<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TuitionPlan extends Model
{
    /** Number of installments per plan type. */
    public const INSTALLMENT_COUNTS = [
        'monthly'   => 10,
        'quarterly' => 4,
    ];

    protected $fillable = [
        'student_enrollment_id',
        'plan_type',
        'total_amount',
        'down_payment',
    ];

    protected $casts = [
        'total_amount' => 'decimal:2',
        'down_payment' => 'decimal:2',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(StudentEnrollment::class, 'student_enrollment_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(TuitionPayment::class);
    }

    /**
     * Creates the tuition plan + its full installment schedule for a
     * newly-finalized enrollment. total_amount is the grade's full annual
     * fee (tuition + misc + books); down_payment is the flat "Upon
     * Enrollment" fee already captured via the Step 1 proof-of-payment
     * upload, so only the remaining balance is split into installments.
     * Due dates are spread evenly across the current enrollment period
     * (falls back to 1 month per installment from today if none is set).
     */
    public static function generateForEnrollment(StudentEnrollment $enrollment): self
    {
        $fee = GradeTuitionFee::where('grade_level', $enrollment->grade_level)->first();
        $totalAmount = $fee->annual_amount ?? 0;

        $period = EnrollmentPeriod::current();
        $downPayment = $period?->enrollment_fee ?? 0;
        $downPayment = min($downPayment, $totalAmount); // never negative balance

        $plan = self::create([
            'student_enrollment_id' => $enrollment->id,
            'plan_type'             => $enrollment->payment_plan,
            'total_amount'          => $totalAmount,
            'down_payment'          => $downPayment,
        ]);

        // The down payment is installment_number 0 — a REAL, admin-verified
        // tuition_payments row (not just a number echoed back as "paid").
        // The parent already uploaded proof of payment during Step 1, so it
        // starts 'pending' (awaiting admin review), exactly like every other
        // installment, instead of being auto-trusted.
        if ($downPayment > 0) {
            $downPaymentRow = $plan->payments()->create([
                'installment_number' => 0,
                'amount_due'         => $downPayment,
                'due_date'           => now(),
                // No Step 1 receipt = the parent chose to pay online right
                // after this, so there's nothing to review yet.
                'status'             => $enrollment->proof_of_payment ? 'pending' : 'unpaid',
                'proof_of_payment'   => $enrollment->proof_of_payment,
                'payment_method'     => $enrollment->payment_method,
                'submitted_at'       => now(),
            ]);

            // The Step 1 receipt is also this installment's first proof, so
            // the admin can Verify / Resubmit it through the same per-proof
            // flow as every other installment.
            if ($enrollment->proof_of_payment) {
                $downPaymentRow->proofs()->create([
                    'amount'           => $downPayment,
                    'payment_method'   => $enrollment->payment_method ?? 'cash',
                    'proof_of_payment' => $enrollment->proof_of_payment,
                    'status'           => 'pending',
                    'submitted_at'     => now(),
                ]);
            }
        }

        $count   = self::INSTALLMENT_COUNTS[$enrollment->payment_plan] ?? 4;
        $balance = $totalAmount - $downPayment;

        $start = $period && $period->start_date ? \Carbon\Carbon::parse($period->start_date) : now();
        $end   = $period && $period->end_date ? \Carbon\Carbon::parse($period->end_date) : now()->addMonths($count);

        // Evenly spaced due dates between start and end (inclusive of end).
        // Cast to int explicitly — diffInDays() can return a float with tiny
        // floating-point drift (e.g. 123.0000000000926) when either date
        // carries a sub-day time component, which intdiv() rejects.
        $totalDays = max((int) round($start->diffInDays($end)), 1);

        // Split the remaining balance into equal installments, adjusting the
        // last one so the sum matches exactly (avoids rounding drift).
        $baseAmount = round($balance / $count, 2);
        $runningSum = 0;
        $previousOffsetDays = -1;

        for ($i = 1; $i <= $count; $i++) {
            $amount = $i === $count ? round($balance - $runningSum, 2) : $baseAmount;
            $runningSum += $amount;

            // Offset computed per-installment (not via a shared intdiv'd
            // step), and forced strictly increasing via the max() below —
            // proportional rounding alone can still round two adjacent
            // installments to the same day when the period is shorter than
            // $count (e.g. a 3-day period split 4 ways), so each offset is
            // clamped to at least one day past the previous installment's.
            $dueOffsetDays = max((int) round($totalDays * $i / $count), $previousOffsetDays + 1);
            $previousOffsetDays = $dueOffsetDays;

            $plan->payments()->create([
                'installment_number' => $i,
                'amount_due'         => $amount,
                'due_date'           => $start->copy()->addDays($dueOffsetDays),
                'status'             => 'unpaid',
            ]);
        }

        return $plan;
    }
}
