<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Online payments through PayMongo. A paid checkout becomes an ordinary
     * tuition_payment_proofs row (already 'verified'), so installment totals,
     * partial payments and history keep working unchanged — it just has no
     * uploaded image, since PayMongo's own confirmation is the proof.
     */
    public function up(): void
    {
        Schema::table('tuition_payment_proofs', function (Blueprint $table) {
            $table->string('proof_of_payment')->nullable()->change();
            // manual: parent uploaded a receipt for admin review.
            // paymongo: paid online, confirmed by PayMongo.
            $table->string('source', 20)->default('manual')->after('status');
            $table->string('paymongo_payment_id')->nullable()->unique()->after('source');
            $table->string('paymongo_checkout_id')->nullable()->index()->after('paymongo_payment_id');
            $table->decimal('gateway_fee', 10, 2)->nullable()->after('paymongo_checkout_id');
        });

        // One row per checkout a parent opens — lets the return page and the
        // webhook tie a PayMongo checkout session back to the installment,
        // and records the exact amount the parent chose to pay.
        Schema::create('paymongo_checkouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tuition_payment_id')->constrained('tuition_payments')->cascadeOnDelete();
            $table->string('checkout_session_id')->nullable()->unique();
            $table->decimal('amount', 10, 2);
            // pending: opened, not paid yet. paid: payment recorded.
            $table->string('status', 20)->default('pending');
            $table->foreignId('tuition_payment_proof_id')->nullable()->constrained('tuition_payment_proofs')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('paymongo_checkouts');

        Schema::table('tuition_payment_proofs', function (Blueprint $table) {
            $table->dropUnique(['paymongo_payment_id']);
            $table->dropIndex(['paymongo_checkout_id']);
            $table->dropColumn(['source', 'paymongo_payment_id', 'paymongo_checkout_id', 'gateway_fee']);
        });
    }
};
