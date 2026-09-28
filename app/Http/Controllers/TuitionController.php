<?php

namespace App\Http\Controllers;

use App\Models\GradeTuitionFee;
use App\Models\StudentEnrollment;
use App\Models\TuitionPayment;
use App\Models\TuitionPaymentProof;
use App\Models\TuitionPlan;
use App\Support\ImageUploadStorer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TuitionController extends Controller
{
    /**
     * GET /tuition?enrollment_id=xxx
     * Parent-facing: returns the tuition plan + full installment schedule
     * for one of their own children, including how much has been verified
     * as paid so far and what's still owed.
     */
    public function show(Request $request)
    {
        $request->validate(['enrollment_id' => 'required|integer']);

        $parent = Auth::guard('parent')->user();

        $enrollment = StudentEnrollment::where('id', $request->query('enrollment_id'))
            ->where('user_id', $parent->id)
            ->firstOrFail();

        if (! in_array($enrollment->status, ['approved', 'enrolled'], true)) {
            return response()->json([
                'message' => 'Tuition & Payments unlocks once this child\'s enrollment is approved.',
            ], 403);
        }

        $plan = $enrollment->tuitionPlan()->with('payments.proofs')->first();

        if (! $plan) {
            return response()->json(['plan' => null, 'payments' => []]);
        }

        $payments = $plan->payments->sortBy('installment_number')->values();

        // Only verified proofs count against the total — a 'pending' proof
        // upload isn't confirmed money yet, so it shouldn't shrink the
        // remaining balance until an admin verifies it.
        $totalPaid = (float) $payments->sum(fn ($p) => $p->verifiedAmount());
        $totalAmount = (float) $plan->total_amount;
        $remainingBalance = max(0, $totalAmount - $totalPaid);

        return response()->json([
            'plan'     => [
                'school_year'       => \App\Models\EnrollmentPeriod::current()?->school_year,
                'plan_type'         => $plan->plan_type,
                'total_amount'      => $totalAmount,
                'down_payment'      => (float) $plan->down_payment,
                'total_paid'        => $totalPaid,
                'remaining_balance' => $remainingBalance,
            ],
            // installment_number 0 is the down payment — a real row like
            // every other installment (see TuitionPlan::generateForEnrollment).
            //
            // Every installment is returned so the frontend can let a
            // parent submit proof for any future installment ahead of its
            // due date, and can submit another partial proof against the
            // same installment as long as a balance remains.
            'payments' => $payments->map(function ($p) {
                $verifiedAmount = $p->verifiedAmount();
                $remaining = $p->remainingBalance();

                return [
                    'id'                 => $p->id,
                    'installment_number' => $p->installment_number,
                    'amount_due'         => (float) $p->amount_due,
                    'due_date'           => $p->due_date->format('M j, Y'),
                    'status'             => $p->status, // unpaid | partial | paid
                    'verified_amount'    => $verifiedAmount,
                    'remaining_balance'  => $remaining,
                    // Submitted but not yet reviewed — lets the UI say
                    // "under review" instead of asking the parent to pay again.
                    'pending_amount'     => (float) $p->proofs->where('status', 'pending')->sum('amount'),
                    'is_overdue'         => $remaining > 0 && $p->installment_number > 0 && $p->due_date->isPast(),
                    'feedback'           => $p->feedback,
                    // A parent can submit proof for any amount up to what's
                    // left, any number of times, until the balance hits 0.
                    'can_submit_proof'   => $remaining > 0,
                    // Every proof ever submitted for this installment, most
                    // recent first — lets the parent see "₱950 verified,
                    // ₱500 pending review" instead of just one flat status.
                    'proofs' => $p->proofs->sortByDesc('submitted_at')->values()->map(fn ($proof) => [
                        'id'               => $proof->id,
                        'amount'           => (float) $proof->amount,
                        'status'           => $proof->status, // pending | verified | rejected
                        'payment_method'   => self::methodLabel($proof->payment_method),
                        // null for online (PayMongo) payments — no uploaded image.
                        'proof_of_payment' => $proof->proof_of_payment ? asset('storage/' . $proof->proof_of_payment) : null,
                        'source'           => $proof->source,
                        'reference'        => $proof->paymongo_payment_id,
                        'receipt_url'      => $proof->status === 'verified' ? route('tuition.receipt', $proof) : null,
                        'submitted_at'     => $proof->submitted_at->format('M j, Y g:i A'),
                        'verified_at'      => $proof->verified_at?->format('M j, Y g:i A'),
                        'feedback'         => $proof->feedback,
                    ]),
                ];
            }),
        ]);
    }

    /**
     * GET /tuition/history
     * Parent-facing: a single combined payment history across ALL of this
     * parent's children — every proof ever submitted, most recent first.
     */
    public function history(Request $request)
    {
        $parent = Auth::guard('parent')->user();

        $enrollments = StudentEnrollment::where('user_id', $parent->id)
            ->whereIn('status', ['approved', 'enrolled'])
            ->with('tuitionPlan.payments.proofs')
            ->get();

        $rows = collect();

        foreach ($enrollments as $enrollment) {
            $childName = trim($enrollment->first_name . ' ' . $enrollment->last_name);
            $plan = $enrollment->tuitionPlan;

            if (! $plan) {
                continue;
            }

            foreach ($plan->payments as $p) {
                $label = $p->installment_number === 0 ? 'Upon Enrollment (Down Payment)' : 'Installment ' . $p->installment_number;

                // Every proof is its own history row now, since one
                // installment can have several partial submissions.
                foreach ($p->proofs as $proof) {
                    $rows->push([
                        'child'          => $childName,
                        'label'          => $label,
                        'amount'         => (float) $proof->amount,
                        'payment_method' => self::methodLabel($proof->payment_method),
                        'submitted_at'   => $proof->submitted_at->format('M j, Y g:i A'),
                        'verified_at'    => $proof->verified_at?->format('M j, Y g:i A'),
                        'status'         => $proof->status,
                        'proof_url'      => $proof->proof_of_payment ? asset('storage/' . $proof->proof_of_payment) : null,
                        'source'         => $proof->source,
                        'receipt_url'    => $proof->status === 'verified' ? route('tuition.receipt', $proof) : null,
                        'sort_key'       => $proof->submitted_at->timestamp,
                    ]);
                }
            }
        }

        return response()->json([
            'history' => $rows->sortByDesc('sort_key')->values(),
        ]);
    }

    private static function methodLabel(?string $method): ?string
    {
        return match ($method) {
            'gcash'         => 'GCash',
            'maya'          => 'Maya',
            'bank_transfer' => 'Bank Transfer',
            'cash'          => 'Cash',
            'card'          => 'Card',
            'online_banking' => 'Online Banking',
            default         => $method,
        };
    }

    /**
     * GET /tuition/proofs/{proof}/receipt
     * Parent downloads the acknowledgment receipt for one of their own
     * verified payments (online or admin-verified upload).
     */
    public function parentReceipt(TuitionPaymentProof $proof)
    {
        $parent = Auth::guard('parent')->user();

        if ($proof->payment->plan->enrollment->user_id !== $parent->id) {
            abort(403, 'You do not have permission to view this receipt.');
        }

        return $this->receiptPdf($proof);
    }

    /** GET /admin/tuition/proofs/{proof}/receipt */
    public function adminReceipt(Request $request, TuitionPaymentProof $proof)
    {
        if (! $request->user()->canManageGrade($proof->payment->plan->enrollment->grade_level)) {
            abort(403, 'You are not assigned to manage this student\'s grade level.');
        }

        return $this->receiptPdf($proof);
    }

    /**
     * Acknowledgment receipt PDF — confirms the school received and verified
     * this payment. Not a BIR official receipt; the footer says so.
     */
    private function receiptPdf(TuitionPaymentProof $proof)
    {
        abort_unless($proof->status === 'verified', 404, 'A receipt is only available once the payment is verified.');

        $payment = $proof->payment;
        $enrollment = $payment->plan->enrollment;
        $parent = $enrollment->user;

        $logoPath = public_path('photo/logo.png');
        $logoData = is_file($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : null;

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('receipts.payment-acknowledgment', [
            'proof'       => $proof,
            'payment'     => $payment,
            'enrollment'  => $enrollment,
            'parentName'  => trim(($parent->first_name ?? '') . ' ' . ($parent->last_name ?? '')),
            'label'       => $payment->installment_number === 0 ? 'Upon Enrollment (Down Payment)' : 'Installment ' . $payment->installment_number,
            'method'      => self::methodLabel($proof->payment_method),
            'schoolYear'  => \App\Models\EnrollmentPeriod::current()?->school_year,
            'remaining'   => $payment->remainingBalance(),
            'verifier'    => $proof->verifier ? trim(($proof->verifier->first_name ?? '') . ' ' . ($proof->verifier->last_name ?? '')) : null,
            'logoData'    => $logoData,
            'generatedAt' => now()->format('M d, Y g:i A'),
        ])->setPaper('a5', 'portrait');

        return $pdf->stream($proof->receiptNumber() . '.pdf');
    }

    /**
     * POST /tuition/payments/{payment}/upload-proof
     * Parent submits proof of payment for an installment — for any amount
     * up to what's still owed on it. Multiple proofs can stack against the
     * same installment (e.g. ₱950 now, ₱500 later, ₱500 after that); each
     * is reviewed by an admin independently, so a pending proof never
     * blocks another submission.
     */
    public function uploadProof(Request $request, TuitionPayment $payment)
    {
        $parent = Auth::guard('parent')->user();

        if ($payment->plan->enrollment->user_id !== $parent->id) {
            abort(403, 'You do not have permission to update this payment.');
        }

        $remaining = $payment->remainingBalance();

        if ($remaining <= 0) {
            return response()->json([
                'message' => 'This installment has already been fully paid.',
            ], 422);
        }

        $request->validate([
            'file'           => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
            'payment_method' => 'required|in:gcash,maya,bank_transfer,cash',
            // Capped at what's left so a parent can't submit proof for more
            // than the installment actually owes.
            'amount'         => 'required|numeric|min:1|max:' . $remaining,
        ]);

        $path = ImageUploadStorer::store(
            $request->file('file'),
            'tuition_payments/' . $parent->id . '/' . $payment->tuition_plan_id,
            'public'
        );

        $proof = TuitionPaymentProof::create([
            'tuition_payment_id' => $payment->id,
            'amount'             => $request->input('amount'),
            'payment_method'     => $request->input('payment_method'),
            'proof_of_payment'   => $path,
            'status'             => 'pending',
            'submitted_at'       => now(),
        ]);

        // Notification failures must never turn an already-saved upload
        // into a 500 for the parent — the proof row above is committed
        // by this point regardless of what happens here.
        try {
            $this->notifyAdminsOfProofSubmission($proof);
        } catch (\Throwable $e) {
            report($e);
        }

        return response()->json([
            'success' => true,
            'message' => 'Proof of payment submitted. Awaiting admin verification.',
        ]);
    }

    /**
     * Notifies every admin scoped to manage this student's grade level
     * (same canManageGrade() rule enforced on verifyProof()/rejectProof()),
     * plus every superadmin, who oversee all grades.
     */
    private function notifyAdminsOfProofSubmission(TuitionPaymentProof $proof): void
    {
        $grade = $proof->payment->plan->enrollment->grade_level;

        $admins = \App\Models\User::whereIn('role', ['admin', 'superadmin'])
            ->get()
            ->filter(fn ($admin) => $admin->isSuperAdmin() || $admin->canManageGrade($grade));

        \Illuminate\Support\Facades\Notification::send(
            $admins,
            new \App\Notifications\TuitionProofSubmitted($proof)
        );
    }

    /**
     * POST /admin/tuition/proofs/{proof}/verify
     * Admin confirms one submitted proof as received. Since there's no
     * payment gateway, the system only knows what the parent typed as the
     * amount — not what actually arrived — so the admin can optionally
     * correct it here (pre-filled with the parent's claim) based on what
     * the receipt actually shows. The installment's overall status
     * (unpaid/partial/paid) is recalculated from the sum of all verified
     * proofs' credited amounts, not just this one.
     */
    public function verifyProof(Request $request, TuitionPaymentProof $proof)
    {
        $payment = $proof->payment;

        if (! $request->user()->canManageGrade($payment->plan->enrollment->grade_level)) {
            abort(403, 'You are not assigned to manage this student\'s grade level.');
        }

        if ($proof->status !== 'pending') {
            return response()->json([
                'message' => 'Only a submitted (pending) proof can be verified.',
            ], 422);
        }

        // The corrected amount can't exceed what's actually left owing on
        // the installment (remainingBalance() already excludes this proof,
        // since it isn't verified yet).
        $request->validate([
            'amount' => 'nullable|numeric|min:0.01|max:' . $payment->remainingBalance(),
        ]);

        $correctedAmount = $request->filled('amount') ? round((float) $request->input('amount'), 2) : null;

        $proof->update([
            'status'          => 'verified',
            // Only stored when it actually differs from what the parent
            // claimed — leaving it null means "trust the parent's figure",
            // matching creditedAmount()'s fallback.
            'verified_amount' => ($correctedAmount !== null && $correctedAmount != (float) $proof->amount) ? $correctedAmount : null,
            'verified_at'     => now(),
            'verified_by'     => $request->user()->id,
            'feedback'        => null,
        ]);

        $payment->refreshStatus();

        \App\Models\ActivityLog::record(
            $request->user(),
            'Verified Tuition Payment',
            trim($payment->plan->enrollment->first_name . ' ' . $payment->plan->enrollment->last_name)
                . ' — ' . ($payment->installment_number === 0 ? 'Down Payment' : 'Installment ' . $payment->installment_number)
                . ' (₱' . number_format($proof->creditedAmount(), 2) . ')',
            'success'
        );

        return response()->json([
            'success'             => true,
            'message'             => 'Payment marked as paid.',
            'installment_status'  => $payment->status,
            'verified_amount'     => $payment->verifiedAmount(),
            'remaining_balance'   => $payment->remainingBalance(),
        ]);
    }

    /**
     * POST /admin/tuition/proofs/{proof}/reject
     * Admin flags one submitted proof as wrong/unclear — sends it back to
     * the parent with a note. Doesn't touch any other proof already
     * verified against the same installment.
     */
    public function rejectProof(Request $request, TuitionPaymentProof $proof)
    {
        $request->validate(['feedback' => 'required|string|max:500']);

        $payment = $proof->payment;

        if (! $request->user()->canManageGrade($payment->plan->enrollment->grade_level)) {
            abort(403, 'You are not assigned to manage this student\'s grade level.');
        }

        if ($proof->status !== 'pending') {
            return response()->json([
                'message' => 'Only a submitted (pending) proof can be rejected.',
            ], 422);
        }

        $proof->update([
            'status'      => 'rejected',
            'feedback'    => $request->input('feedback'),
            'verified_at' => null,
            'verified_by' => null,
        ]);

        // Moves a down payment out of its initial 'pending' state (other
        // installments are already derived from their proofs).
        $payment->refreshStatus();

        \App\Models\ActivityLog::record(
            $request->user(),
            'Rejected Tuition Payment',
            trim($payment->plan->enrollment->first_name . ' ' . $payment->plan->enrollment->last_name)
                . ' — ' . ($payment->installment_number === 0 ? 'Down Payment' : 'Installment ' . $payment->installment_number)
                . ' (₱' . number_format((float) $proof->amount, 2) . ')',
            'warning'
        );

        return response()->json([
            'success' => true,
            'message' => 'Parent has been notified to resubmit this payment.',
        ]);
    }

    /**
     * PATCH /admin/tuition/payments/{payment}/adjust-amount
     * Direct balance override — admin can correct an installment's billed
     * amount at any time (e.g. a data-entry mistake, applying a discount or
     * waiver), independent of any proof. Since there's no payment gateway,
     * this is the only way to fix the amount actually owed if it was wrong
     * to begin with, separately from correcting what a specific proof paid.
     */
    public function adjustAmount(Request $request, TuitionPayment $payment)
    {
        if (! $request->user()->canManageGrade($payment->plan->enrollment->grade_level)) {
            abort(403, 'You are not assigned to manage this student\'s grade level.');
        }

        $request->validate([
            'amount_due' => 'required|numeric|min:0',
            'reason'     => 'nullable|string|max:255',
        ]);

        $oldAmount = (float) $payment->amount_due;
        $newAmount = round((float) $request->input('amount_due'), 2);

        $payment->update(['amount_due' => $newAmount]);

        // Billed amount changed — the installment may now be fully covered
        // by what's already verified (or no longer be), so recompute.
        $payment->refreshStatus();

        $label = $payment->installment_number === 0 ? 'Down Payment' : 'Installment ' . $payment->installment_number;
        $studentName = trim($payment->plan->enrollment->first_name . ' ' . $payment->plan->enrollment->last_name);
        $reasonSuffix = $request->filled('reason') ? ' — ' . $request->input('reason') : '';

        \App\Models\ActivityLog::record(
            $request->user(),
            'Adjusted Tuition Amount',
            $studentName . ' — ' . $label . ' (₱' . number_format($oldAmount, 2) . ' → ₱' . number_format($newAmount, 2) . ')' . $reasonSuffix,
            'warning'
        );

        return response()->json([
            'success'            => true,
            'message'            => 'Installment amount updated.',
            'amount_due'         => $newAmount,
            'installment_status' => $payment->fresh()->status,
            'verified_amount'    => $payment->verifiedAmount(),
            'remaining_balance'  => $payment->remainingBalance(),
        ]);
    }

    /**
     * PATCH /admin/tuition/plans/{plan}/adjust-total
     * Corrects the overall tuition total for this plan (e.g. the grade fee
     * was wrong at enrollment time, or a scholarship/discount is granted
     * partway through the year) and cascades that change across the
     * installment schedule — same idea as adjustAmount() above, but for
     * the plan as a whole instead of one installment at a time.
     *
     * The down payment and any installment that already has a proof
     * submitted against it (status 'partial' or 'paid') are left exactly
     * as they are — those represent money already claimed or verified, and
     * changing them is what adjustAmount()/verifyProof() are for. Only
     * installments still fully 'unpaid' absorb the new total, split evenly
     * across them the same way the original schedule was generated (see
     * TuitionPlan::generateForEnrollment), with the last one absorbing any
     * rounding remainder so the numbers always add up exactly.
     */
    public function adjustPlanTotal(Request $request, TuitionPlan $plan)
    {
        if (! $request->user()->canManageGrade($plan->enrollment->grade_level)) {
            abort(403, 'You are not assigned to manage this student\'s grade level.');
        }

        $request->validate([
            'total_amount' => 'required|numeric|min:0',
            'reason'       => 'nullable|string|max:255',
        ]);

        $oldTotal = (float) $plan->total_amount;
        $newTotal = round((float) $request->input('total_amount'), 2);
        $downPayment = (float) $plan->down_payment;

        $installments = $plan->payments()->where('installment_number', '>', 0)->orderBy('installment_number')->get();
        $lockedInstallments = $installments->where('status', '!=', 'unpaid');
        $unpaidInstallments = $installments->where('status', 'unpaid')->values();

        $lockedSum = (float) $lockedInstallments->sum(fn ($p) => (float) $p->amount_due);
        // What's left to spread across the still-untouched installments,
        // once the down payment and any already-in-progress installment
        // are accounted for.
        $pool = round($newTotal - $downPayment - $lockedSum, 2);

        if ($unpaidInstallments->isEmpty()) {
            // Nothing left to redistribute into — every installment already
            // has a payment submitted or verified, so the new total can
            // only be honored if it already matches what's billed.
            if (abs($pool) > 0.01) {
                return response()->json([
                    'message' => 'Every installment already has a payment submitted or verified against it, so the total can\'t be redistributed automatically. Adjust the individual installments instead.',
                ], 422);
            }
        } elseif ($pool < 0) {
            return response()->json([
                'message' => 'That total is lower than what\'s already billed on the down payment and installments with a payment submitted (₱'
                    . number_format($downPayment + $lockedSum, 2) . '). Adjust those installments individually first if this is meant to be a discount.',
            ], 422);
        } else {
            $count = $unpaidInstallments->count();
            $base = round($pool / $count, 2);
            $running = 0;

            foreach ($unpaidInstallments as $i => $installment) {
                $isLast = $i === $count - 1;
                $amount = $isLast ? round($pool - $running, 2) : $base;
                $running += $amount;
                $installment->update(['amount_due' => $amount]);
            }
        }

        $plan->update(['total_amount' => $newTotal]);

        // An installment's amount_due may have just moved past or below
        // what's already verified against it, so recheck every installment's
        // paid/partial/unpaid status against the new numbers.
        foreach ($installments as $installment) {
            $installment->refreshStatus();
        }

        $studentName = trim($plan->enrollment->first_name . ' ' . $plan->enrollment->last_name);
        $reasonSuffix = $request->filled('reason') ? ' — ' . $request->input('reason') : '';

        \App\Models\ActivityLog::record(
            $request->user(),
            'Adjusted Tuition Total',
            $studentName . ' (₱' . number_format($oldTotal, 2) . ' → ₱' . number_format($newTotal, 2) . ')' . $reasonSuffix,
            'warning'
        );

        return response()->json([
            'success'      => true,
            'message'      => 'Tuition total updated.',
            'total_amount' => $newTotal,
        ]);
    }

    /**
     * PUT /superadmin/tuition/grade-fees
     * Superadmin sets the annual tuition rate per grade level, plus the
     * flat enrollment_fee (down payment) that applies to every grade.
     */
    public function updateGradeFees(Request $request)
    {
        $request->validate([
            'fees'                 => 'required|array',
            'fees.*.grade_level'   => 'required|string',
            'fees.*.annual_amount' => 'required|numeric|min:0',
            'enrollment_fee'       => 'nullable|numeric|min:0',
        ]);

        if ($request->filled('enrollment_fee')) {
            \App\Models\EnrollmentPeriod::current()?->update([
                'enrollment_fee' => $request->input('enrollment_fee'),
            ]);
        }

        foreach ($request->input('fees') as $fee) {
            GradeTuitionFee::updateOrCreate(
                ['grade_level' => $fee['grade_level']],
                ['annual_amount' => $fee['annual_amount']]
            );
        }

        return response()->json(['success' => true, 'message' => 'Tuition rates updated.']);
    }
}