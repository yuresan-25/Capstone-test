<?php

namespace App\Notifications;

use App\Models\TuitionPaymentProof;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Fires when a parent submits proof of payment for a tuition installment
 * (see TuitionController::uploadProof()). Admin-facing — read entirely from
 * the database notifications table by NotificationController and rendered
 * in the bell dropdown / notifDetailModal in admin.blade.php.
 *
 * Takes the TuitionPaymentProof itself (not the parent TuitionPayment) since
 * every admin action from the notification modal — Verify, Reject — acts on
 * one specific proof: an installment can now hold several partial proofs,
 * so "payment_id" here is really the proof's id, matching the
 * /admin/tuition/proofs/{proof}/verify|reject routes the JS calls.
 *
 * 'database' only for now: admins don't have a push subscription flow yet
 * (only parents do, via PushSubscriptionController) and this doesn't need
 * an email — it's meant to be checked from the dashboard bell.
 */
class TuitionProofSubmitted extends Notification
{
    use Queueable;

    public function __construct(private TuitionPaymentProof $proof)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $payment     = $this->proof->payment;
        $enrollment  = $payment->plan->enrollment;
        $studentName = trim($enrollment->first_name . ' ' . $enrollment->last_name);
        $parentName  = trim(($enrollment->user->first_name ?? '') . ' ' . ($enrollment->user->last_name ?? ''));

        $label = $payment->installment_number === 0
            ? 'Upon Enrollment (Down Payment)'
            : 'Installment ' . $payment->installment_number;

        return [
            // Drives the bell icon (bi-cash-coin) and modal routing in the
            // existing admin JS — must stay exactly 'payment_proof_submitted'.
            'type'             => 'payment_proof_submitted',
            'message'          => ($parentName ?: 'A parent') . ' submitted proof of payment for '
                . $studentName . ' — ' . $label,
            // Named payment_id for compatibility with the existing JS
            // (_notifModalPaymentId), but this is the proof's id — the
            // Verify/Reject buttons in the modal act on the proof, not the
            // installment as a whole.
            'payment_id'       => $this->proof->id,
            'enrollment_id'    => $enrollment->id,
            'student_name'     => $studentName,
            'parent_name'      => $parentName ?: null,
            'label'            => $label,
            // The parent's claimed amount for THIS proof — what the admin
            // sees and can correct at verify-time, not the installment's
            // total billed amount.
            'amount_due'       => (float) $this->proof->amount,
            'payment_method'   => $this->proof->payment_method,
            'submitted_at'     => $this->proof->submitted_at?->format('M j, Y g:i A'),
            'proof_of_payment' => $this->proof->proof_of_payment
                ? asset('storage/' . $this->proof->proof_of_payment)
                : null,
        ];
    }
}