<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TuitionPayment extends Model
{
    protected $fillable = [
        'tuition_plan_id',
        'installment_number',
        'amount_due',
        'due_date',
        'status',
        'proof_of_payment',
        'payment_method',
        'submitted_at',
        'paid_at',
        'verified_by',
        'feedback',
        'reminder_sent_at',
    ];

    protected $casts = [
        'amount_due'       => 'decimal:2',
        'due_date'         => 'date',
        'submitted_at'     => 'datetime',
        'paid_at'          => 'datetime',
        'reminder_sent_at' => 'datetime',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(TuitionPlan::class, 'tuition_plan_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    /**
     * Every proof ever submitted against this installment — supports
     * partial payments (e.g. ₱950 now, ₱500 later, ₱500 after that).
     */
    public function proofs(): HasMany
    {
        return $this->hasMany(TuitionPaymentProof::class, 'tuition_payment_id');
    }

    /**
     * Sum of admin-verified proofs' credited amounts (see
     * TuitionPaymentProof::creditedAmount() — this is the parent's claimed
     * amount unless an admin corrected it at verify-time). Rejected and
     * still-pending proofs don't count until an admin verifies them.
     */
    public function verifiedAmount(): float
    {
        // Uses already-loaded proofs when the caller eager-loaded them
        // (with('payments.proofs')) — lists of hundreds of installments
        // otherwise run one query per installment, per call.
        $verified = $this->relationLoaded('proofs')
            ? $this->proofs->where('status', 'verified')
            : $this->proofs()->where('status', 'verified')->get();

        // Rounded to the centavo — summing floats can otherwise leave
        // ₱1,949.9999999 and an installment that never reads as paid.
        return round((float) $verified->sum(fn (TuitionPaymentProof $proof) => $proof->creditedAmount()), 2);
    }

    public function remainingBalance(): float
    {
        return max(0, round((float) $this->amount_due - $this->verifiedAmount(), 2));
    }

    public function hasPendingProof(): bool
    {
        return $this->relationLoaded('proofs')
            ? $this->proofs->contains('status', 'pending')
            : $this->proofs()->where('status', 'pending')->exists();
    }

    /**
     * Recomputes this installment's overall status from its proofs.
     * Call after any proof is verified or rejected — never set 'paid'/
     * 'partial' directly on the payment anymore, since a single field
     * can no longer capture "some verified, some still pending".
     */
    public function refreshStatus(): void
    {
        // Always recompute from the database — a proof was just added or
        // changed, so any eager-loaded copy may be stale.
        $this->unsetRelation('proofs');
        $verified = $this->verifiedAmount();

        if ($verified >= round((float) $this->amount_due, 2)) {
            $lastVerified = $this->proofs()->where('status', 'verified')->latest('verified_at')->first();

            $this->update([
                'status'   => 'paid',
                'paid_at'  => $lastVerified?->verified_at ?? now(),
                'feedback' => null,
            ]);
        } elseif ($verified > 0) {
            $this->update(['status' => 'partial', 'paid_at' => null]);
        } else {
            $this->update(['status' => 'unpaid', 'paid_at' => null]);
        }
    }
}