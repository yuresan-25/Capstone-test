<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Models\EnrollmentPeriod;
use App\Models\GradeEnrollmentSetting;
use App\Models\Parents;
use App\Models\User;
use App\Notifications\NewAnnouncementPosted;
use App\Support\SafeNotify;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SuperAdminController extends Controller
{
    /* ──────────────────────────────────────────────
       ENROLLMENT HISTORY (current school year, real data)
    ────────────────────────────────────────────── */

    private const GRADES = ['Kinder', 'Grade 1', 'Grade 2', 'Grade 3', 'Grade 4', 'Grade 5', 'Grade 6', 'Grade 7', 'Grade 8', 'Grade 9', 'Grade 10'];

    /**
     * Per-grade enrollment numbers for the current school year, straight
     * from the database. Enrollments aren't stored per school year yet, so
     * this is the current year only — archived years will appear once the
     * system has been used across more than one school year.
     */
    public static function enrollmentSummary(): array
    {
        $period = EnrollmentPeriod::current();

        $counts = \App\Models\StudentEnrollment::where('status', '!=', 'draft')
            ->selectRaw('grade_level, status, COUNT(*) as total')
            ->groupBy('grade_level', 'status')
            ->get()
            ->groupBy('grade_level');

        $sections = \App\Models\Section::selectRaw('grade_level, COUNT(*) as total')
            ->groupBy('grade_level')
            ->pluck('total', 'grade_level');

        $grades = [];
        foreach (self::GRADES as $grade) {
            $byStatus = ($counts[$grade] ?? collect())->pluck('total', 'status');
            $pending  = (int) ($byStatus['pending'] ?? 0);
            $approved = (int) ($byStatus['approved'] ?? 0);
            $enrolled = (int) ($byStatus['enrolled'] ?? 0);
            $applications = $pending + $approved + $enrolled + (int) ($byStatus['rejected'] ?? 0);

            $grades[] = [
                'label'        => $grade,
                'sections'     => (int) ($sections[$grade] ?? 0),
                'applications' => $applications,
                'pending'      => $pending,
                // Approved = cleared admin review, whether or not sectioned yet.
                'approved'     => $approved + $enrolled,
                'enrolled'     => $enrolled,
            ];
        }

        $schoolYear = $period?->school_year;

        return [
            'sy'                => $schoolYear ? 'SY ' . str_replace('-', '–', $schoolYear) : 'Current School Year',
            'key'               => $schoolYear ?: 'current',
            'status'            => 'active',
            'period'            => $period && $period->start_date && $period->end_date
                ? $period->start_date->format('F j, Y') . ' – ' . $period->end_date->format('F j, Y')
                : 'Enrollment period not set',
            'totalApplications' => array_sum(array_column($grades, 'applications')),
            'totalPending'      => array_sum(array_column($grades, 'pending')),
            'totalApproved'     => array_sum(array_column($grades, 'approved')),
            'totalEnrolled'     => array_sum(array_column($grades, 'enrolled')),
            'totalSections'     => array_sum(array_column($grades, 'sections')),
            'grades'            => $grades,
        ];
    }

    /**
     * GET /superadmin/history/export?format=pdf|csv
     * Downloads the enrollment summary shown on the Enrollment History tab.
     */
    public function exportHistory(Request $request)
    {
        $request->validate(['format' => 'nullable|in:pdf,csv']);

        $summary  = self::enrollmentSummary();
        $filename = 'enrollment-summary_' . str_replace(['SY ', '–', ' '], ['', '-', '_'], $summary['sy']) . '_' . now()->format('Y-m-d');

        \App\Models\ActivityLog::record($request->user(), 'Exported Enrollment Summary', $summary['sy'] . ' (' . strtoupper($request->input('format', 'pdf')) . ')', 'info');

        if ($request->input('format') === 'csv') {
            return response()->streamDownload(function () use ($summary) {
                $out = fopen('php://output', 'w');
                fwrite($out, "\xEF\xBB\xBF"); // UTF-8 marker so Excel shows "–" / "—" correctly
                fputcsv($out, [$summary['sy'], $summary['period']]);
                fputcsv($out, ['Grade Level', 'Sections', 'Applications', 'Pending', 'Approved', 'Enrolled (sectioned)', 'Approval Rate']);
                foreach ($summary['grades'] as $g) {
                    fputcsv($out, [$g['label'], $g['sections'], $g['applications'], $g['pending'], $g['approved'], $g['enrolled'],
                        $g['applications'] ? round($g['approved'] / $g['applications'] * 100) . '%' : '—']);
                }
                fputcsv($out, ['Total', $summary['totalSections'], $summary['totalApplications'], $summary['totalPending'], $summary['totalApproved'], $summary['totalEnrolled'],
                    $summary['totalApplications'] ? round($summary['totalApproved'] / $summary['totalApplications'] * 100) . '%' : '—']);
                fclose($out);
            }, $filename . '.csv', ['Content-Type' => 'text/csv']);
        }

        $logoPath = public_path('photo/logo.png');

        return \Barryvdh\DomPDF\Facade\Pdf::loadView('superadmin.exports.enrollment-summary-pdf', [
            'summary'     => $summary,
            'logoData'    => is_file($logoPath) ? 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath)) : null,
            'generatedAt' => now()->format('M d, Y g:i A'),
            'generatedBy' => trim(($request->user()->first_name ?? '') . ' ' . ($request->user()->last_name ?? '')) ?: 'Super Admin',
        ])->setPaper('a4', 'portrait')->download($filename . '.pdf');
    }

    /* ──────────────────────────────────────────────
       ADMIN ACCOUNTS
    ────────────────────────────────────────────── */

    public function storeAdmin(Request $request)
    {
        $data = $request->validate([
            'first_name'        => 'required|string|max:100',
            'last_name'         => 'required|string|max:100',
            'email'             => 'required|email|unique:users,email',
            'assigned_grades'   => 'nullable|array',
            'assigned_grades.*' => 'string|max:20',
            'password'          => ['required', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);

        $assignedGrades = !empty($data['assigned_grades']) ? array_values($data['assigned_grades']) : null;

        $admin = User::create([
            'first_name'      => $data['first_name'],
            'last_name'       => $data['last_name'],
            'email'           => $data['email'],
            'password'        => Hash::make($data['password']),
            'role'            => 'admin',
            'assigned_grades' => $assignedGrades,
            'is_active'       => true,
        ]);

        \App\Models\ActivityLog::record(
            $request->user(),
            'Created Admin Account',
            "New admin: {$admin->first_name} {$admin->last_name}" . ($assignedGrades ? ' assigned to ' . implode(', ', $assignedGrades) : ' (All Grades)'),
            'purple'
        );

        return response()->json(['success' => true, 'admin' => $admin]);
    }

    public function updateAdmin(Request $request, User $admin)
    {
        $data = $request->validate([
            'email'             => ['required', 'email', Rule::unique('users', 'email')->ignore($admin->id)],
            'assigned_grades'   => 'nullable|array',
            'assigned_grades.*' => 'string|max:20',
        ]);

        $admin->update([
            'email'           => $data['email'],
            'assigned_grades' => !empty($data['assigned_grades']) ? array_values($data['assigned_grades']) : null,
        ]);

        \App\Models\ActivityLog::record($request->user(), 'Updated Admin Account', "Admin: {$admin->first_name} {$admin->last_name}", 'info');

        return response()->json(['success' => true]);
    }

    public function resetAdminPassword(Request $request, User $admin)
    {
        $tempPassword = \Illuminate\Support\Str::random(10);
        $admin->update(['password' => Hash::make($tempPassword)]);

        // TODO: wire up actual email notification with $tempPassword
        \App\Models\ActivityLog::record($request->user(), 'Reset Admin Password', "Admin: {$admin->first_name} {$admin->last_name}", 'warning');

        return response()->json(['success' => true]);
    }

    public function toggleAdminActive(Request $request, User $admin)
    {
        $data = $request->validate(['is_active' => 'required|boolean']);
        $admin->update(['is_active' => $data['is_active']]);

        \App\Models\ActivityLog::record(
            $request->user(),
            $data['is_active'] ? 'Activated Admin Account' : 'Deactivated Admin Account',
            "Admin: {$admin->first_name} {$admin->last_name}",
            $data['is_active'] ? 'success' : 'danger'
        );

        return response()->json(['success' => true]);
    }

    /* ──────────────────────────────────────────────
       ENROLLMENT PERIOD
    ────────────────────────────────────────────── */

    public function saveEnrollmentPeriod(Request $request)
    {
        $data = $request->validate([
            'id'          => 'nullable|exists:enrollment_periods,id',
            'school_year' => 'required|string|max:20',
            'start_date'  => 'required|date',
            'end_date'    => 'required|date|after_or_equal:start_date',
        ]);

        if (!empty($data['id'])) {
            $period = EnrollmentPeriod::findOrFail($data['id']);
            $period->update([
                'school_year' => $data['school_year'],
                'start_date'  => $data['start_date'],
                'end_date'    => $data['end_date'],
            ]);
        } else {
            // Close any previously open period before creating a new current one
            EnrollmentPeriod::where('is_open', true)->update(['is_open' => false]);
            $period = EnrollmentPeriod::create([
                'school_year' => $data['school_year'],
                'start_date'  => $data['start_date'],
                'end_date'    => $data['end_date'],
                'is_open'     => true,
            ]);
        }

        \App\Models\ActivityLog::record(
            $request->user(),
            'Enrollment Period Updated',
            "SY {$period->school_year}: {$data['start_date']} – {$data['end_date']}",
            'purple'
        );

        return response()->json(['success' => true, 'period' => $period]);
    }

    public function toggleEnrollmentPeriod(Request $request, EnrollmentPeriod $period)
    {
        $data = $request->validate(['is_open' => 'required|boolean']);
        $period->update(['is_open' => $data['is_open']]);

        \App\Models\ActivityLog::record(
            $request->user(),
            $data['is_open'] ? 'Opened Enrollment' : 'Closed Enrollment',
            "SY {$period->school_year}",
            'purple'
        );

        return response()->json(['success' => true]);
    }

    /**
     * PUT /superadmin/grade-settings
     * Sets which grade levels currently accept enrollment applications.
     */
    public function updateGradeSettings(Request $request)
    {
        $data = $request->validate([
            'grades'             => 'required|array',
            'grades.*.grade'     => 'required|string',
            'grades.*.is_open'   => 'required|boolean',
        ]);

        foreach ($data['grades'] as $g) {
            GradeEnrollmentSetting::updateOrCreate(
                ['grade_level' => $g['grade']],
                ['is_open' => $g['is_open']]
            );
        }

        \App\Models\ActivityLog::record($request->user(), 'Updated Grade-Level Enrollment Settings', null, 'purple');

        return response()->json(['success' => true]);
    }

    /* ──────────────────────────────────────────────
       ANNOUNCEMENTS
    ────────────────────────────────────────────── */

    public function storeAnnouncement(Request $request)
    {
        $data = $request->validate([
            'title'         => 'required|string|max:255',
            'message'       => 'required|string',
            'show_from'     => 'nullable|date',
            'show_until'    => 'nullable|date',
            'status'        => 'required|in:active,inactive',
            'show_as_popup' => 'boolean',
        ]);

        $announcement = Announcement::create([
            ...$data,
            'created_by'      => $request->user()->id,
            'created_by_name' => trim($request->user()->first_name . ' ' . $request->user()->last_name),
        ]);

        \App\Models\ActivityLog::record($request->user(), 'Created Announcement', $announcement->title, 'purple');

        // Only email/push parents for announcements that are actually live —
        // an "inactive" (draft) announcement shouldn't notify anyone yet.
        if ($announcement->status === 'active') {
            SafeNotify::to(Parents::all(), new NewAnnouncementPosted($announcement));
        }

        return response()->json($this->announcementPayload($announcement));
    }

    public function updateAnnouncement(Request $request, Announcement $announcement)
    {
        $data = $request->validate([
            'title'         => 'required|string|max:255',
            'message'       => 'required|string',
            'show_from'     => 'nullable|date',
            'show_until'    => 'nullable|date',
            'status'        => 'required|in:active,inactive',
            'show_as_popup' => 'boolean',
        ]);

        $announcement->update($data);

        \App\Models\ActivityLog::record($request->user(), 'Updated Announcement', $announcement->title, 'info');

        return response()->json($this->announcementPayload($announcement));
    }

    public function deleteAnnouncement(Request $request, Announcement $announcement)
    {
        $title = $announcement->title;
        $announcement->delete();

        \App\Models\ActivityLog::record($request->user(), 'Deleted Announcement', $title, 'danger');

        return response()->json(['success' => true]);
    }

    public function toggleAnnouncementStatus(Request $request, Announcement $announcement)
    {
        $wasInactive = $announcement->status !== 'active';

        $announcement->update(['status' => $announcement->status === 'active' ? 'inactive' : 'active']);

        \App\Models\ActivityLog::record($request->user(), 'Toggled Announcement Status', $announcement->title, 'info');

        // Notify parents the moment a draft announcement first goes live,
        // same as if it had been created active from the start.
        if ($wasInactive && $announcement->status === 'active') {
            SafeNotify::to(Parents::all(), new NewAnnouncementPosted($announcement));
        }

        return response()->json(['success' => true, 'status' => $announcement->status]);
    }

    /**
     * Normalize an Announcement into the shape the super-admin dashboard's
     * front-end announcement list/edit-form expects (matches $announcementsJs).
     */
    private function announcementPayload(Announcement $announcement): array
    {
        return [
            'id'      => $announcement->id,
            'title'   => $announcement->title,
            'message' => $announcement->message,
            'from'    => optional($announcement->show_from)->format('Y-m-d'),
            'until'   => optional($announcement->show_until)->format('Y-m-d'),
            'status'  => $announcement->status,
            'popup'   => (bool) $announcement->show_as_popup,
            'by'      => $announcement->created_by_name,
            'date'    => $announcement->created_at->format('F j, Y'),
        ];
    }
}