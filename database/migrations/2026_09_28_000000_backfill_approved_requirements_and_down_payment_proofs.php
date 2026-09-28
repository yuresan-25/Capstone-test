<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Two data fixes for rows created before the current approval flow:
     *
     * 1. Applications approved before approve() started auto-approving
     *    documents still show their requirements as "Pending Review" even
     *    though the student is approved/enrolled.
     * 2. Down payments (installment 0) finalized after the proofs table was
     *    introduced never got a proof row for the Step 1 receipt, so the
     *    admin had nothing to Verify / Resubmit against.
     */
    public function up(): void
    {
        $approvedIds = DB::table('student_enrollment')
            ->whereIn('status', ['approved', 'enrolled'])
            ->pluck('id');

        DB::table('enrollment_requirements')
            ->whereIn('enrollment_id', $approvedIds)
            ->where('status', 'pending')
            ->update(['status' => 'approved', 'reviewed_at' => now(), 'updated_at' => now()]);

        $downPayments = DB::table('tuition_payments')
            ->where('installment_number', 0)
            ->whereNotNull('proof_of_payment')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('tuition_payment_proofs')
                    ->whereColumn('tuition_payment_proofs.tuition_payment_id', 'tuition_payments.id');
            })
            ->get();

        foreach ($downPayments as $payment) {
            $isPaid = $payment->status === 'paid';

            DB::table('tuition_payment_proofs')->insert([
                'tuition_payment_id' => $payment->id,
                'amount'             => $payment->amount_due,
                'payment_method'     => $payment->payment_method ?? 'cash',
                'proof_of_payment'   => $payment->proof_of_payment,
                'status'             => $isPaid ? 'verified' : 'pending',
                'submitted_at'       => $payment->submitted_at ?? now(),
                'verified_at'        => $isPaid ? ($payment->paid_at ?? now()) : null,
                'verified_by'        => $isPaid ? $payment->verified_by : null,
                'created_at'         => now(),
                'updated_at'         => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Data-only backfill; the previous "stuck pending" state isn't worth
        // restoring.
    }
};
