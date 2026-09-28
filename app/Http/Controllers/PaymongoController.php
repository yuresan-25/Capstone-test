<?php

namespace App\Http\Controllers;

use App\Models\PaymongoCheckout;
use App\Models\TuitionPayment;
use App\Support\PayMongo;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;

class PaymongoController extends Controller
{
    /**
     * POST /tuition/payments/{payment}/paymongo-checkout
     * Parent chose "Pay Online": opens a PayMongo Checkout Session for the
     * amount they want to pay now (full or partial) and returns its URL.
     */
    public function checkout(Request $request, TuitionPayment $payment)
    {
        $parent = Auth::guard('parent')->user();
        $enrollment = $payment->plan->enrollment;

        if ($enrollment->user_id !== $parent->id) {
            abort(403, 'You do not have permission to pay for this installment.');
        }

        // The enrollment fee (installment 0) is paid right after "Enroll Now",
        // while the application is still pending; installments unlock once
        // the enrollment is approved.
        $allowed = $payment->installment_number === 0
            ? ['pending', 'approved', 'enrolled']
            : ['approved', 'enrolled'];

        if (! in_array($enrollment->status, $allowed, true)) {
            return response()->json(['message' => 'Online payment unlocks once this enrollment is approved.'], 403);
        }

        if (! PayMongo::enabled()) {
            return response()->json(['message' => 'Online payment is not available right now. Please upload a proof of payment instead.'], 503);
        }

        // What's still payable: the remaining balance minus anything already
        // submitted and awaiting review, so a parent can't pay twice for it.
        $pending = (float) $payment->proofs()->where('status', 'pending')->sum('amount');
        $open = round(max(0, $payment->remainingBalance() - $pending), 2);
        $min = (float) config('services.paymongo.min_amount');

        if ($open <= 0) {
            return response()->json(['message' => 'Nothing is left to pay on this installment.'], 422);
        }

        $request->validate([
            'amount' => 'required|numeric|min:' . min($min, $open) . '|max:' . $open,
        ], [
            'amount.min' => 'The minimum online payment is ₱' . number_format(min($min, $open), 2) . '.',
            'amount.max' => 'You can pay at most ₱' . number_format($open, 2) . ' on this installment right now.',
        ]);

        $amount = round((float) $request->input('amount'), 2);

        // One checkout at a time per installment: a double click, a second
        // tab or a Back-and-retry gets the same PayMongo page instead of a
        // second one the parent could also pay.
        $lock = Cache::lock('paymongo-checkout-' . $payment->id, 15);
        if (! $lock->get()) {
            return response()->json(['message' => 'Your payment page is already opening. Please wait a moment.'], 429);
        }

        try {
            return $this->openCheckout($payment, $enrollment, $parent, $amount);
        } finally {
            $lock->release();
        }
    }

    private function openCheckout(TuitionPayment $payment, $enrollment, $parent, float $amount)
    {
        $recent = PaymongoCheckout::where('tuition_payment_id', $payment->id)
            ->where('status', 'pending')
            ->whereNotNull('checkout_session_id')
            ->where('amount', $amount)
            ->where('created_at', '>=', now()->subMinutes(30))
            ->latest('id')
            ->first();

        if ($recent) {
            try {
                $session = PayMongo::syncCheckout($recent);
                $recent->refresh();

                if ($recent->status === 'paid') {
                    return response()->json(['message' => 'This payment was already received. Refresh the page to see it.'], 409);
                }
                if ($recent->status === 'pending' && data_get($session, 'attributes.checkout_url')) {
                    return response()->json(['checkout_url' => data_get($session, 'attributes.checkout_url')]);
                }
            } catch (\Throwable $e) {
                report($e); // fall through and open a fresh checkout
            }
        }

        $label = $payment->installment_number === 0 ? 'Enrollment Fee' : 'Installment ' . $payment->installment_number;
        $childName = trim($enrollment->first_name . ' ' . $enrollment->last_name);

        $checkout = PaymongoCheckout::create([
            'tuition_payment_id' => $payment->id,
            'amount'             => $amount,
            'status'             => 'pending',
        ]);

        try {
            $session = PayMongo::createCheckoutSession([
                'line_items' => [[
                    'name'     => $label . ' — ' . $childName,
                    'amount'   => (int) round($amount * 100), // centavos
                    'currency' => 'PHP',
                    'quantity' => 1,
                ]],
                'payment_method_types' => array_values(config('services.paymongo.methods')),
                'description'          => 'PHLCI tuition — ' . $label . ' for ' . $childName . ' (' . $enrollment->grade_level . ')',
                'reference_number'     => 'PHLCI-' . $checkout->id,
                'send_email_receipt'   => true,
                'show_description'     => true,
                'show_line_items'      => true,
                'billing'              => array_filter([
                    'name'  => trim(($parent->first_name ?? '') . ' ' . ($parent->last_name ?? '')) ?: null,
                    'email' => $parent->email,
                ]),
                // Signed: the return page works without a login session.
                'success_url' => URL::signedRoute('tuition.paymongo.return', ['checkout' => $checkout]),
                'cancel_url'  => URL::signedRoute('tuition.paymongo.return', ['checkout' => $checkout, 'cancelled' => 1]),
                'metadata'    => [
                    'paymongo_checkout_id' => (string) $checkout->id,
                    'tuition_payment_id'   => (string) $payment->id,
                ],
            ]);
        } catch (RequestException $e) {
            $checkout->delete();
            Log::error('PayMongo checkout creation failed: ' . $e->response?->body());

            return response()->json(['message' => 'Could not start the online payment. Please try again in a moment.'], 502);
        }

        $checkout->update(['checkout_session_id' => $session['id']]);

        return response()->json(['checkout_url' => data_get($session, 'attributes.checkout_url')]);
    }

    /**
     * GET /tuition/paymongo/return/{checkout}
     * Where PayMongo sends the parent back after checkout. Asks PayMongo
     * directly whether it was paid, so the payment is recorded even when the
     * webhook can't reach this machine (tunnel down / address changed).
     */
    public function return(Request $request, PaymongoCheckout $checkout)
    {
        // No ownership check against the logged-in parent here on purpose —
        // the signed URL proves PayMongo (via our own checkout) sent them,
        // and the parent's session may have expired during a long checkout.

        // The enrollment fee is paid before approval, when Tuition & Payments
        // is still locked — send those parents back to Home instead.
        $panel = $checkout->payment->installment_number === 0 && $checkout->payment->plan->enrollment->status === 'pending'
            ? 'home'
            : 'tuition-payments';

        $to = fn (string $result) => redirect()->route('parent.dashboard', ['panel' => $panel, 'payment' => $result]);

        if ($request->boolean('cancelled')) {
            return $to('cancelled');
        }

        if ($checkout->status === 'paid') {
            return $to('success');
        }

        try {
            PayMongo::syncCheckout($checkout);
            if ($checkout->refresh()->status === 'paid') {
                return $to('success');
            }
        } catch (\Throwable $e) {
            report($e);
        }

        // Paid on PayMongo's side but not confirmed yet — the webhook (or the
        // next visit) will record it.
        return $to('processing');
    }

    /**
     * POST /webhooks/paymongo
     * PayMongo's server-to-server notification. Only a correctly signed
     * request is trusted; every other event type is acknowledged and ignored.
     */
    public function webhook(Request $request)
    {
        $raw = $request->getContent();
        $event = json_decode($raw, true);
        $livemode = (bool) data_get($event, 'data.attributes.livemode', false);

        if (! PayMongo::verifySignature($request->header('Paymongo-Signature'), $raw, $livemode)) {
            Log::warning('PayMongo webhook rejected: invalid signature.');
            return response()->json(['message' => 'Invalid signature.'], 400);
        }

        // A test-mode payment must never count on a live system (or the
        // reverse). Acknowledged so PayMongo stops retrying, but ignored.
        if ($livemode !== PayMongo::liveMode()) {
            Log::warning('PayMongo webhook ignored: ' . ($livemode ? 'live' : 'test') . ' event but the app uses ' . (PayMongo::liveMode() ? 'live' : 'test') . ' keys.');
            return response()->json(['received' => true, 'ignored' => 'mode mismatch']);
        }

        if (data_get($event, 'data.attributes.type') === 'checkout_session.payment.paid') {
            $session = data_get($event, 'data.attributes.data', []);

            $checkout = PaymongoCheckout::where('checkout_session_id', data_get($session, 'id'))->first()
                ?? PaymongoCheckout::find(data_get($session, 'attributes.metadata.paymongo_checkout_id'));

            if ($checkout) {
                PayMongo::recordPaidCheckout($checkout, $session);
            } else {
                Log::warning('PayMongo webhook for unknown checkout session ' . data_get($session, 'id'));
            }
        }

        return response()->json(['received' => true]);
    }
}
