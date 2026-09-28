<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The admin dashboard's paginated lists: approved/enrolled students
     * sorted by name, and pending applications newest-first. Lets MySQL read
     * one page straight from the index instead of sorting the whole table.
     * Plus the PayMongo reconcile job's "pending checkouts" scan.
     */
    public function up(): void
    {
        Schema::table('student_enrollment', function (Blueprint $table) {
            $table->index(['status', 'last_name', 'first_name'], 'student_enrollment_status_name_index');
            $table->index(['status', 'created_at'], 'student_enrollment_status_created_index');
        });

        Schema::table('paymongo_checkouts', function (Blueprint $table) {
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::table('student_enrollment', function (Blueprint $table) {
            $table->dropIndex('student_enrollment_status_name_index');
            $table->dropIndex('student_enrollment_status_created_index');
        });

        Schema::table('paymongo_checkouts', function (Blueprint $table) {
            $table->dropIndex(['status', 'created_at']);
        });
    }
};
