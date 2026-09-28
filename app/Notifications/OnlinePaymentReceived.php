<?php

namespace App\Notifications;

use App\Models\TuitionPaymentProof;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Admin bell: PayMongo confirmed an online payment (enrollment fee or a
 * tuition installment). Already verified — informational, no action needed.
 * Sent from PayMongo::recordPaidCheckout(), once per payment.
 */
class OnlinePaymentReceived extends Notification
{
    use Queueable;

    private const METHOD_LABELS = [
        'gcash' => 'GCash', 'maya' => 'Maya', 'card' => 'Card', 'online_banking' => 'Online Banking',
    ];

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
        $label       = $payment->installment_number === 0 ? 'Enrollment Fee' : 'Installment ' . $payment->installment_number;
        $method      = self::METHOD_LABELS[$this->proof->payment_method] ?? $this->proof->payment_method;

        return [
            'type'           => 'online_payment_received',
            'message'        => ($parentName ?: 'A parent') . ' paid ₱' . number_format((float) $this->proof->amount, 2)
                . ' online (' . $method . ') for ' . $studentName . ' — ' . $label,
            'enrollment_id'  => $enrollment->id,
            'proof_id'       => $this->proof->id,
            'student_name'   => $studentName,
            'parent_name'    => $parentName ?: null,
            'label'          => $label,
            'amount'         => (float) $this->proof->amount,
            'payment_method' => $this->proof->payment_method,
            'reference'      => $this->proof->paymongo_payment_id,
            // Pending applications live on the Applications tab.
            'from'           => $enrollment->status === 'pending' ? 'applications' : 'students',
        ];
    }
}
