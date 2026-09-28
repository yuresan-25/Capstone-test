<?php

namespace App\Support;

use App\Models\ActivityLog;
use App\Models\PaymongoCheckout;
use App\Models\TuitionPaymentProof;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper around the PayMongo REST API (https://docs.paymongo.com),
 * plus the single place a paid checkout is turned into a verified tuition
 * proof — shared by the return page and the webhook, so whichever arrives
 * first records the payment and the other finds it already done.
 */
class PayMongo
{
    private const API = 'https://api.paymongo.com/v1';

    /** PayMongo source/payment-method type → our payment_method value. */
    private const METHOD_MAP = [
        'gcash'   => 'gcash',
        'paymaya' => 'maya',
        'card'    => 'card',
        'dob'     => 'online_banking',
        'dob_ubp' => 'online_banking',
        'brankas_bdo'       => 'online_banking',
        'brankas_landbank'  => 'online_banking',
        'brankas_metrobank' => 'online_banking',
    ];

    public static function enabled(): bool
    {
        return filled(config('services.paymongo.secret_key'));
    }

    private static function client(): PendingRequest
    {
        return Http::withBasicAuth((string) config('services.paymongo.secret_key'), '')
            ->acceptJson()
            ->asJson()
            ->timeout(20)
            ->baseUrl(self::API);
    }

    /** @return array The checkout_session resource (id, attributes.checkout_url, ...). */
    public static function createCheckoutSession(array $attributes): array
    {
        return self::client()
            ->post('/checkout_sessions', ['data' => ['attributes' => $attributes]])
            ->throw()
            ->json('data');
    }

    public static function retrieveCheckoutSession(string $id): array
    {
        return self::client()->get('/checkout_sessions/' . $id)->throw()->json('data');
    }

    public static function createWebhook(string $url, array $events): array
    {
        return self::client()
            ->post('/webhooks', ['data' => ['attributes' => ['url' => $url, 'events' => $events]]])
            ->throw()
            ->json('data');
    }

    public static function updateWebhook(string $id, string $url, array $events): array
    {
        return self::client()
            ->put('/webhooks/' . $id, ['data' => ['attributes' => ['url' => $url, 'events' => $events]]])
            ->throw()
            ->json('data');
    }

    /**
     * Paymongo-Signature: t=<timestamp>,te=<test sig>,li=<live sig>, where
     * each signature is HMAC-SHA256("<timestamp>.<raw body>", webhook secret).
     * Test-mode events are signed in te, live-mode events in li.
     */
    public static function verifySignature(?string $header, string $rawBody, bool $livemode, int $toleranceSeconds = 300): bool
    {
        $secret = (string) config('services.paymongo.webhook_secret');
        if ($secret === '' || ! $header) {
            return false;
        }

        $parts = [];
        foreach (explode(',', $header) as $piece) {
            [$key, $value] = array_pad(explode('=', trim($piece), 2), 2, '');
            $parts[$key] = $value;
        }

        $timestamp = $parts['t'] ?? '';
        $signature = $livemode ? ($parts['li'] ?? '') : ($parts['te'] ?? '');

        if ($timestamp === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        // Replay protection: reject events signed long ago.
        if (abs(time() - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * Records a paid checkout as a verified proof. Safe to call any number of
     * times for the same checkout (return page + webhook, webhook retries):
     * the checkout row is locked and the PayMongo payment id is unique.
     *
     * @param  array  $session  checkout_session resource from PayMongo
     * @return TuitionPaymentProof|null  the proof, or null if not paid yet
     */
    public static function recordPaidCheckout(PaymongoCheckout $checkout, array $session): ?TuitionPaymentProof
    {
        $paid = collect(data_get($session, 'attributes.payments', []))
            ->first(fn ($payment) => data_get($payment, 'attributes.status') === 'paid');

        if (! $paid) {
            return null;
        }

        return DB::transaction(function () use ($checkout, $paid) {
            $locked = PaymongoCheckout::whereKey($checkout->id)->lockForUpdate()->first();

            if ($locked->status === 'paid') {
                return $locked->proof;
            }

            $paymentId = data_get($paid, 'id');
            $existing = TuitionPaymentProof::where('paymongo_payment_id', $paymentId)->first();
            if ($existing) {
                $locked->update(['status' => 'paid', 'tuition_payment_proof_id' => $existing->id, 'paid_at' => $existing->verified_at]);
                return $existing;
            }

            $attrs = data_get($paid, 'attributes', []);
            // Carbon 3 reads unix timestamps as UTC unless told otherwise.
            $paidAt = isset($attrs['paid_at']) ? \Carbon\Carbon::createFromTimestamp($attrs['paid_at'], config('app.timezone')) : now();
            $sourceType = data_get($attrs, 'source.type');

            // What PayMongo actually charged is what counts — normally the
            // same as the amount the parent chose. If it pushes the
            // installment past what's due (two checkouts paid in two tabs),
            // the excess shows as advance credit, like any overpayment.
            $proof = TuitionPaymentProof::create([
                'tuition_payment_id'   => $locked->tuition_payment_id,
                'amount'               => round(((int) $attrs['amount']) / 100, 2),
                'payment_method'       => self::METHOD_MAP[$sourceType] ?? ($sourceType ?: 'online'),
                'proof_of_payment'     => null,
                'status'               => 'verified',
                'source'               => 'paymongo',
                'paymongo_payment_id'  => $paymentId,
                'paymongo_checkout_id' => $locked->checkout_session_id,
                'gateway_fee'          => isset($attrs['fee']) ? round(((int) $attrs['fee']) / 100, 2) : null,
                'submitted_at'         => $paidAt,
                'verified_at'          => $paidAt,
                'verified_by'          => null,
            ]);

            $locked->update(['status' => 'paid', 'tuition_payment_proof_id' => $proof->id, 'paid_at' => $paidAt]);

            $payment = $locked->payment;
            $payment->refreshStatus();

            $enrollment = $payment->plan->enrollment;
            ActivityLog::record(
                null,
                'Online Tuition Payment',
                trim($enrollment->first_name . ' ' . $enrollment->last_name)
                    . ' — ' . ($payment->installment_number === 0 ? 'Down Payment' : 'Installment ' . $payment->installment_number)
                    . ' (₱' . number_format((float) $proof->amount, 2) . ' via PayMongo, ' . $paymentId . ')',
                'success'
            );

            return $proof;
        });
    }
}
