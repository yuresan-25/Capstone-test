<?php

namespace Database\Seeders;

use App\Models\EnrollmentPeriod;
use App\Models\EnrollmentRequirement;
use App\Models\Parents;
use App\Models\StudentEnrollment;
use App\Models\TuitionPaymentProof;
use App\Models\TuitionPlan;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Fills a SEPARATE load-test database with realistic volume for performance
 * testing (default 1,000 students). Refuses to run unless the database name
 * contains "loadtest", so it can never pollute the real one.
 *
 *   DB_DATABASE=capstone_loadtest php artisan migrate --force
 *   DB_DATABASE=capstone_loadtest php artisan db:seed --class=AdminSeeder --force
 *   DB_DATABASE=capstone_loadtest LOADTEST_STUDENTS=1000 php artisan db:seed --class=LoadTestSeeder --force
 *
 * Every seeded parent's password is "password".
 */
class LoadTestSeeder extends Seeder
{
    private const GRADES = ['Kinder', 'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6', 'Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'];

    public function run(): void
    {
        $database = DB::connection()->getDatabaseName();
        if (! str_contains($database, 'loadtest')) {
            throw new RuntimeException("Refusing to seed '{$database}': LoadTestSeeder only runs on a database whose name contains 'loadtest'.");
        }

        $target = (int) env('LOADTEST_STUDENTS', 1000);
        mt_srand(42); // same data every run, so before/after numbers are comparable

        EnrollmentPeriod::firstOrCreate(['school_year' => '2026-2027'], [
            'start_date'     => now()->subMonths(2)->startOfMonth(),
            'end_date'       => now()->addMonths(8),
            'is_open'        => true,
            'enrollment_fee' => 15000,
        ]);

        $password = Hash::make('password');
        $created = 0;
        $parentNo = 0;

        while ($created < $target) {
            DB::transaction(function () use (&$created, &$parentNo, $target, $password) {
                // 100 parents per transaction keeps memory flat and inserts fast.
                for ($i = 0; $i < 100 && $created < $target; $i++) {
                    $parentNo++;
                    $parent = Parents::create([
                        'first_name' => fake()->firstName(),
                        'last_name'  => fake()->lastName(),
                        'email'      => "loadtest.parent{$parentNo}@example.test",
                        'password'   => $password,
                        'role'       => 'parent',
                    ]);

                    // Most families have one child here; some have two or three.
                    $children = min($target - $created, [1, 1, 1, 2, 2, 3][mt_rand(0, 5)]);
                    for ($c = 0; $c < $children; $c++) {
                        $this->seedStudent($parent, $created);
                        $created++;
                    }
                }
            });

            $this->command?->info("  {$created} / {$target} students");
        }
    }

    private function seedStudent(Parents $parent, int $n): void
    {
        $grade = self::GRADES[mt_rand(0, count(self::GRADES) - 1)];
        // 25% still pending review, 35% approved, 40% enrolled.
        $roll = mt_rand(1, 100);
        $status = $roll <= 25 ? 'pending' : ($roll <= 60 ? 'approved' : 'enrolled');
        $createdAt = now()->subDays(mt_rand(0, 60))->subMinutes(mt_rand(0, 1440));

        $enrollment = StudentEnrollment::create([
            'user_id'           => $parent->id,
            'first_name'        => fake()->firstName(),
            'middle_name'       => 'N/A',
            'last_name'         => $parent->last_name,
            'suffix'            => 'N/A',
            'lrn'               => (string) (100000000000 + $n),
            'grade_level'       => $grade,
            'student_type'      => mt_rand(0, 1) ? 'old' : 'new',
            'birthday'          => now()->subYears(5 + array_search($grade, self::GRADES, true))->subDays(mt_rand(0, 364))->format('Y-m-d'),
            'birth_place'       => fake()->city(),
            'address'           => fake()->address(),
            'mother_name'       => fake()->name('female'),
            'father_name'       => fake()->name('male'),
            'guardian_name'     => $parent->first_name . ' ' . $parent->last_name,
            'emergency_contact' => '09' . mt_rand(100000000, 999999999),
            'preferred_session' => mt_rand(0, 1) ? 'AM' : 'PM',
            'payment_method'    => ['gcash', 'maya', 'bank_transfer', 'cash'][mt_rand(0, 3)],
            'payment_plan'      => mt_rand(0, 3) ? 'monthly' : 'quarterly',
            'proof_of_payment'  => 'proof_of_payment/loadtest/receipt.jpg',
            'status'            => 'pending',
        ]);
        $enrollment->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        $docStatus = $status === 'pending' ? 'pending' : 'approved';
        foreach (\App\Http\Controllers\EnrollmentController::requiredDocumentTypes($grade, $enrollment->student_type) as $type) {
            EnrollmentRequirement::create([
                'enrollment_id'  => $enrollment->id,
                'document_type'  => $type,
                'document_label' => ucwords(str_replace('_', ' ', $type)),
                'path'           => 'requirements/loadtest/doc.jpg',
                'status'         => $docStatus,
            ]);
        }

        $plan = TuitionPlan::generateForEnrollment($enrollment);
        $enrollment->update(['status' => $status]);

        if ($status === 'pending') {
            return;
        }

        // Approved: enrollment fee verified. Enrolled: also a few months of
        // installments — mostly paid, some partial, some awaiting review.
        $payments = $plan->payments()->orderBy('installment_number')->get();
        $paidThrough = $status === 'enrolled' ? mt_rand(0, min(6, $payments->count() - 1)) : 0;

        foreach ($payments as $payment) {
            if ($payment->installment_number === 0) {
                $payment->proofs()->update(['status' => 'verified', 'verified_at' => $createdAt]);
                $payment->refreshStatus();
                continue;
            }
            if ($payment->installment_number > $paidThrough + 1) {
                break;
            }

            $kind = $payment->installment_number <= $paidThrough ? 'full' : ['partial', 'pending', 'none'][mt_rand(0, 2)];
            if ($kind === 'none') {
                continue;
            }

            TuitionPaymentProof::create([
                'tuition_payment_id' => $payment->id,
                'amount'             => $kind === 'partial' ? round($payment->amount_due / 2, 2) : $payment->amount_due,
                'payment_method'     => ['gcash', 'maya', 'bank_transfer', 'cash'][mt_rand(0, 3)],
                'proof_of_payment'   => 'tuition_payments/loadtest/receipt.jpg',
                'status'             => $kind === 'pending' ? 'pending' : 'verified',
                'source'             => 'manual',
                'submitted_at'       => $createdAt->copy()->addDays(30 * $payment->installment_number),
                'verified_at'        => $kind === 'pending' ? null : $createdAt->copy()->addDays(30 * $payment->installment_number + 1),
            ]);
            $payment->refreshStatus();
        }
    }
}
