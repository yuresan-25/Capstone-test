<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymongoCheckout extends Model
{
    protected $fillable = [
        'tuition_payment_id',
        'checkout_session_id',
        'amount',
        'status',
        'tuition_payment_proof_id',
        'paid_at',
    ];

    protected $casts = [
        'amount'  => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    public function payment(): BelongsTo
    {
        return $this->belongsTo(TuitionPayment::class, 'tuition_payment_id');
    }

    public function proof(): BelongsTo
    {
        return $this->belongsTo(TuitionPaymentProof::class, 'tuition_payment_proof_id');
    }
}
