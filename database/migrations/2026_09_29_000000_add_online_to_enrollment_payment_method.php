<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 'online' = the parent chose to pay the enrollment fee through PayMongo
     * right after "Enroll Now", instead of uploading a receipt in Step 1.
     */
    public function up(): void
    {
        Schema::table('student_enrollment', function (Blueprint $table) {
            $table->enum('payment_method', ['gcash', 'maya', 'bank_transfer', 'cash', 'online'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('student_enrollment', function (Blueprint $table) {
            $table->enum('payment_method', ['gcash', 'maya', 'bank_transfer', 'cash'])->change();
        });
    }
};
