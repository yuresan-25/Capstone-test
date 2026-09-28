<?php

namespace App\Notifications;

use App\Models\StudentEnrollment;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Admin bell: a parent clicked "Enroll Now" — a new application is waiting
 * for review. Sent from EnrollmentController::finalize(). (EnrollmentSubmitted
 * is the parent-facing email for the same moment.)
 */
class NewEnrollmentSubmitted extends Notification
{
    use Queueable;

    public function __construct(private StudentEnrollment $enrollment)
    {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $e = $this->enrollment;
        $studentName = trim($e->first_name . ' ' . $e->last_name);
        $parentName  = trim(($e->user->first_name ?? '') . ' ' . ($e->user->last_name ?? ''));

        $feeNote = match (true) {
            $e->payment_method === 'online' => 'enrollment fee to be paid online',
            (bool) $e->proof_of_payment     => 'enrollment fee receipt to verify',
            default                         => 'awaiting review',
        };

        return [
            'type'          => 'enrollment_submitted',
            'message'       => ($parentName ?: 'A parent') . ' enrolled ' . $studentName
                . ' (' . $e->grade_level . ') — ' . $feeNote,
            'enrollment_id' => $e->id,
            'student_name'  => $studentName,
            'parent_name'   => $parentName ?: null,
            'grade_level'   => $e->grade_level,
            'from'          => 'applications',
        ];
    }
}
