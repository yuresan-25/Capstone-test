<?php

namespace App\Notifications;

use App\Models\TuitionPayment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class PaymentProofSubmitted extends Notification
{
    use Queueable;

    public function __construct(private TuitionPayment $payment)
    {
    }

    /**
     * Database-only — this powers the admin notification bell. Unlike
     * DocumentNeedsResubmit (parent-facing, mail + web push), this is an
     * internal admin alert, so no mail/push channel is needed here.
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $enrollment = $this->payment->plan->enrollment;
        $studentName = trim($enrollment->first_name . ' ' . $enrollment->last_name);
        $label = $this->payment->installment_number === 0
            ? 'Down Payment'
            : 'Installment ' . $this->payment->installment_number;

        return [
            'type'                => 'payment_proof_submitted',
            'payment_id'          => $this->payment->id,
            'enrollment_id'       => $enrollment->id,
            'student_name'        => $studentName,
            'label'               => $label,
            'installment_number'  => $this->payment->installment_number,
            'amount_due'          => (float) $this->payment->amount_due,
            'payment_method'      => $this->payment->payment_method,
            // Included so the admin notification modal can show the actual
            // uploaded proof image directly, without a second fetch.
            'proof_of_payment'    => $this->payment->proof_of_payment
                ? asset('storage/' . $this->payment->proof_of_payment)
                : null,
            'submitted_at'        => $this->payment->submitted_at?->format('M j, Y g:i A'),
            'message'             => $studentName . ' submitted proof of payment for ' . $label . '.',
        ];
    }
}