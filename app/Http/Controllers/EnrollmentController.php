<?php

namespace App\Http\Controllers;

use App\Models\EnrollmentRequirement;
use App\Models\StudentEnrollment;
use App\Notifications\EnrollmentApproved;
use App\Notifications\EnrollmentSubmitted;
use App\Support\ImageUploadStorer;
use App\Support\SafeNotify;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class EnrollmentController extends Controller
{
    /**
     * Map the form's payMethod values to the DB enum values.
     */
    private const PAY_METHOD_MAP = [
        'GCash'         => 'gcash',
        'Maya'          => 'maya',
        'Bank Transfer' => 'bank_transfer',
        'Cash'          => 'cash',
    ];

    /**
     * Documents required before "Enroll Now" can be clicked, depending on
     * grade level:
     *   - Kinder: PSA Birth Certificate + Medical Certificate (no Report
     *     Card — there's no prior school record for an incoming Kinder
     *     student)
     *   - Grade 1+ (old or new student): Report Card + PSA Birth Certificate
     */
    public static function requiredDocumentTypes(string $gradeLevel, string $studentType): array
    {
        if ($gradeLevel === 'Kinder') {
            return ['birth_certificate', 'medical_certificate'];
        }

        return ['report_card', 'birth_certificate'];
    }

    /**
     * Shared validation rules for store/update.
     */
    private function rules(): array
    {
        return [
            'first_name'         => 'required|string|max:100',
            'middle_name'        => 'nullable|string|max:100',
            'last_name'          => 'required|string|max:100',
            'suffix'             => 'nullable|string|max:20',
            'lrn'                => 'nullable|string|max:20',
            'grade_level'        => 'required|string|max:20',
            'student_type'       => 'required|in:new,old',
            // Youngest accepted grade is Kinder — a child must be at least 4
            // years old by today's date to be enrolled anywhere in the school.
            'birthday'           => ['required', 'date', 'before_or_equal:' . now()->subYears(4)->format('Y-m-d')],
            'birth_place'        => 'required|string|max:150',
            'last_school'        => 'nullable|string|max:150',
            'address'            => 'required|string',
            'mother_name'        => 'required|string|max:150',
            'father_name'        => 'required|string|max:150',
            'guardian_name'      => 'required|string|max:150',
            'emergency_contact'  => 'required|string|max:20',
            'emergency_contact_2' => 'nullable|string|max:20',
            'classSession'       => 'required|in:AM,PM',
            'payMethod'          => 'required|in:GCash,Maya,Bank Transfer,Cash',
            'paymentPlan'        => 'required|in:monthly,quarterly',
            'proof_of_payment'   => 'required|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ];
    }

    private function messages(): array
    {
        return [
            'birthday.before_or_equal' => 'The student must be at least 4 years old to enroll.',
        ];
    }

    /**
     * Reverse map: DB enum value -> the label used in the form's radio inputs.
     */
    private const PAY_METHOD_REVERSE_MAP = [
        'gcash'         => 'GCash',
        'maya'          => 'Maya',
        'bank_transfer' => 'Bank Transfer',
        'cash'          => 'Cash',
    ];

    /**
     * GET /enrollment/{enrollment}
     * Returns this enrollment's full Step 1 data so the application-form
     * modal can pre-fill itself when the parent clicks "Edit" on their
     * child's card. Field names match the form's input `name` attributes
     * directly so the frontend can just loop and assign.
     */
    public function show(Request $request, StudentEnrollment $enrollment)
    {
        $parent = Auth::guard('parent')->user();

        if ($enrollment->user_id !== $parent->id) {
            abort(403, 'You do not have permission to view this enrollment.');
        }

        return response()->json([
            'enrollment' => [
                'id'                => $enrollment->id,
                'first_name'        => $enrollment->first_name,
                'middle_name'       => $enrollment->middle_name === 'N/A' ? '' : $enrollment->middle_name,
                'last_name'         => $enrollment->last_name,
                'suffix'            => $enrollment->suffix === 'N/A' ? '' : $enrollment->suffix,
                'lrn'               => $enrollment->lrn === 'N/A' ? '' : $enrollment->lrn,
                'grade_level'       => $enrollment->grade_level,
                'student_type'      => $enrollment->student_type,
                'birthday'          => $enrollment->birthday
                    ? \Carbon\Carbon::parse($enrollment->birthday)->format('Y-m-d')
                    : '',
                'birth_place'       => $enrollment->birth_place,
                'last_school'       => $enrollment->last_school,
                'address'           => $enrollment->address,
                'mother_name'       => $enrollment->mother_name,
                'father_name'       => $enrollment->father_name,
                'guardian_name'     => $enrollment->guardian_name,
                'emergency_contact' => $enrollment->emergency_contact,
                'classSession'      => $enrollment->preferred_session,
                'payMethod'         => self::PAY_METHOD_REVERSE_MAP[$enrollment->payment_method] ?? '',
                'paymentPlan'       => $enrollment->payment_plan,
                'status'            => $enrollment->status,
            ],
        ]);
    }

    /**
     * POST /enrollment
     * Step 1 save — creates a new student_enrollment record.
     */
    public function store(Request $request)
    {
        $validated = $request->validate($this->rules(), $this->messages());

        $period = \App\Models\EnrollmentPeriod::current();
        if (! $period || ! $period->is_open) {
            return response()->json([
                'message' => 'Enrollment is currently closed. Please check back once the next enrollment period opens.',
            ], 403);
        }

        $gradeSetting = \App\Models\GradeEnrollmentSetting::where('grade_level', $validated['grade_level'])->first();
        if ($gradeSetting && ! $gradeSetting->is_open) {
            return response()->json([
                'message' => 'Enrollment for ' . $validated['grade_level'] . ' is currently closed.',
            ], 403);
        }

        $parent = Auth::guard('parent')->user();

        $path = ImageUploadStorer::store(
            $request->file('proof_of_payment'),
            'proof_of_payment/' . $parent->id,
            'public'
        );

        $enrollment = StudentEnrollment::create([
            'user_id'            => $parent->id,
            'first_name'         => $validated['first_name'],
            'middle_name'        => $validated['middle_name'] ?: 'N/A',
            'last_name'          => $validated['last_name'],
            'suffix'             => $validated['suffix'] ?: 'N/A',
            'lrn'                => $validated['lrn'] ?: 'N/A',
            'grade_level'        => $validated['grade_level'],
            'student_type'       => $validated['student_type'],
            'birthday'           => $validated['birthday'],
            'birth_place'        => $validated['birth_place'],
            'last_school'        => $validated['last_school'] ?? null,
            'address'            => $validated['address'],
            'mother_name'        => $validated['mother_name'],
            'father_name'        => $validated['father_name'],
            'guardian_name'      => $validated['guardian_name'],
            'emergency_contact'  => $validated['emergency_contact'],
            'preferred_session'  => $validated['classSession'],
            'payment_method'     => self::PAY_METHOD_MAP[$validated['payMethod']],
            'payment_plan'       => $validated['paymentPlan'],
            'proof_of_payment'   => $path,
            'status'             => 'draft', // Step 2 not done yet — not a Home tab card until finalize()
        ]);

        return response()->json([
            'message'    => 'Step 1 saved. You can now upload requirements below.',
            'enrollment' => [
                'id'         => $enrollment->id,
                'name'       => trim($enrollment->first_name . ' ' . $enrollment->last_name),
                'grade'      => $enrollment->grade_level,
                'session'    => $enrollment->preferred_session,
                'pay_method' => $validated['payMethod'], // original label for display
                'status'     => $enrollment->status,
            ],
        ], 201);
    }

    /**
     * PUT/PATCH /enrollment/{enrollment}
     * Step 1 edit — updates an existing record (e.g. before admin approval).
     */
    public function update(Request $request, StudentEnrollment $enrollment)
    {
        $parent = Auth::guard('parent')->user();

        if ($enrollment->user_id !== $parent->id) {
            abort(403, 'You do not have permission to edit this enrollment.');
        }

        $rules = $this->rules();
        // proof_of_payment is already required via $this->rules() — the parent
        // must reselect a file every time they edit, since browsers can't
        // pre-fill file inputs for security reasons.

        $validated = $request->validate($rules, $this->messages());

        $data = [
            'first_name'         => $validated['first_name'],
            'middle_name'        => $validated['middle_name'] ?: 'N/A',
            'last_name'          => $validated['last_name'],
            'suffix'             => $validated['suffix'] ?: 'N/A',
            'lrn'                => $validated['lrn'] ?: 'N/A',
            'grade_level'        => $validated['grade_level'],
            'student_type'       => $validated['student_type'],
            'birthday'           => $validated['birthday'],
            'birth_place'        => $validated['birth_place'],
            'last_school'        => $validated['last_school'] ?? null,
            'address'            => $validated['address'],
            'mother_name'        => $validated['mother_name'],
            'father_name'        => $validated['father_name'],
            'guardian_name'      => $validated['guardian_name'],
            'emergency_contact'  => $validated['emergency_contact'],
            'preferred_session'  => $validated['classSession'],
            'payment_method'     => self::PAY_METHOD_MAP[$validated['payMethod']],
            'payment_plan'       => $validated['paymentPlan'],
        ];

        // Always replace the proof-of-payment file, since it's required on
        // every edit (old file becomes orphaned — acceptable tradeoff for
        // keeping the data model simple; not deleted here to avoid risk of
        // removing a file the admin may still be reviewing mid-edit).
        $data['proof_of_payment'] = ImageUploadStorer::store(
            $request->file('proof_of_payment'),
            'proof_of_payment/' . $parent->id,
            'public'
        );

        $enrollment->update($data);

        return response()->json([
            'message'    => 'Enrollment updated successfully.',
            'enrollment' => [
                'id'         => $enrollment->id,
                'name'       => trim($enrollment->first_name . ' ' . $enrollment->last_name),
                'grade'      => $enrollment->grade_level,
                'session'    => $enrollment->preferred_session,
                'pay_method' => $validated['payMethod'],
                'status'     => $enrollment->status,
            ],
        ]);
    }

    /**
     * Returns the list of required document types still missing for this enrollment.
     * Empty array means all required documents are uploaded.
     */
    public static function missingDocumentTypes(StudentEnrollment $enrollment): array
    {
        $uploadedTypes = EnrollmentRequirement::where('enrollment_id', $enrollment->id)
            ->pluck('document_type')
            ->toArray();

        $required = self::requiredDocumentTypes($enrollment->grade_level, $enrollment->student_type);

        return array_values(array_diff($required, $uploadedTypes));
    }

    /**
     * POST /enrollment/{enrollment}/finalize
     * "Enroll Now" — parent confirms Step 2 is complete and wants to finish
     * enrolling this child. This is the moment status flips from 'draft' to
     * 'pending', which is also the moment the child's card appears on the
     * Home tab (Home only queries non-draft enrollments).
     */
    public function finalize(Request $request, StudentEnrollment $enrollment)
    {
        $parent = Auth::guard('parent')->user();

        if ($enrollment->user_id !== $parent->id) {
            abort(403, 'You do not have permission to finalize this enrollment.');
        }

        $missing = self::missingDocumentTypes($enrollment);

        if (!empty($missing)) {
            return response()->json([
                'message' => 'Please upload all required documents before enrolling.',
                'missing' => $missing,
            ], 422);
        }

        // Re-fetch with a row lock inside a transaction so a double-click
        // (or a slow-network retry) can't have both requests pass the
        // "still a draft" check before either commits — the second one
        // blocks until the first finishes, then correctly sees it's no
        // longer a draft instead of generating a second tuition plan and
        // throwing an uncaught unique-constraint exception.
        return DB::transaction(function () use ($enrollment) {
            $locked = StudentEnrollment::whereKey($enrollment->id)->lockForUpdate()->first();

            if ($locked->status !== 'draft') {
                return response()->json([
                    'message' => 'This enrollment has already been finalized.',
                ], 422);
            }

            $locked->update(['status' => 'pending']);

            // Generate the tuition installment schedule now that the plan
            // (monthly/quarterly, chosen in Step 1) and grade level are final.
            \App\Models\TuitionPlan::generateForEnrollment($locked);

            SafeNotify::to($locked->user, new EnrollmentSubmitted($locked));

            return response()->json([
                'message'    => 'Enrollment complete! Your child has been added to your Home tab and is awaiting admin review.',
                'enrollment' => [
                    'id'     => $locked->id,
                    'status' => $locked->status,
                ],
            ]);
        });
    }

    /**
     * DELETE /enrollment/{enrollment}
     * Parent decided not to enroll this child after all. Only allowed while
     * status is still 'pending' — once the admin has approved/enrolled the
     * child, the parent can no longer delete it themselves.
     * Hard delete: removes the student_enrollment row, its
     * enrollment_requirements rows (via DB cascade), AND the actual files
     * on disk (proof of payment + every uploaded document), so nothing is
     * left orphaned in storage.
     */
    public function destroy(Request $request, StudentEnrollment $enrollment)
    {
        $parent = Auth::guard('parent')->user();

        if ($enrollment->user_id !== $parent->id) {
            abort(403, 'You do not have permission to delete this enrollment.');
        }

        if (!in_array($enrollment->status, ['draft', 'pending'])) {
            return response()->json([
                'message' => 'This enrollment can no longer be deleted because it has already been reviewed by the admin.',
            ], 422);
        }

        // Delete every uploaded requirement file from disk before the DB
        // cascade removes the rows (cascade only cleans up the rows, not
        // the actual files in storage).
        $requirements = EnrollmentRequirement::where('enrollment_id', $enrollment->id)->get();
        foreach ($requirements as $requirement) {
            Storage::disk('public')->delete($requirement->path);
        }

        // Delete the proof-of-payment file too.
        if ($enrollment->proof_of_payment) {
            Storage::disk('public')->delete($enrollment->proof_of_payment);
        }

        $enrollment->delete(); // cascades to enrollment_requirements rows in the DB

        return response()->json([
            'message' => 'Enrollment deleted.',
        ]);
    }

    /**
     * GET /admin/students/export
     * Streams a CSV of every approved/enrolled student, optionally filtered
     * by grade level and school year (school year is accepted for the
     * filter UI but there's no per-enrollment school-year column yet, so it
     * currently has no effect on the result set).
     */
    public function export(Request $request)
    {
        $request->validate([
            'format' => 'nullable|in:csv,pdf',
        ]);

        $query = StudentEnrollment::with('section')
            ->whereIn('status', ['approved', 'enrolled'])
            ->when($request->filled('grade_level'), fn ($q) => $q->where('grade_level', $request->input('grade_level')))
            ->orderBy('last_name');

        if ($request->input('format') === 'pdf') {
            return $this->exportPdf($request, $query);
        }

        $filename = 'students_' . now()->format('Y-m-d_His') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Last Name', 'First Name', 'Middle Name', 'Grade Level', 'Section', 'Session', 'Status', 'LRN', 'Birthday']);

            $query->chunk(200, function ($chunk) use ($out) {
                foreach ($chunk as $s) {
                    fputcsv($out, [
                        $s->last_name,
                        $s->first_name,
                        $s->middle_name,
                        $s->grade_level,
                        $s->section->name ?? '—',
                        $s->preferred_session,
                        ucfirst($s->status),
                        $s->lrn,
                        optional($s->birthday)->format('Y-m-d'),
                    ]);
                }
            });

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * PDF branch of export(). Renders admin/exports/students-pdf.blade.php
     * through dompdf so the output is a consistent, paginated document with
     * the school header — unlike a browser print, which varies per machine.
     *
     * Not chunked like the CSV path: dompdf builds the whole document in
     * memory anyway, so the collection has to be fully loaded regardless.
     */
    private function exportPdf(Request $request, $query)
    {
        $students = $query->get();

        $gradeLevel = $request->input('grade_level');

        // The logo is embedded as a data URI — dompdf resolves relative
        // asset() URLs inconsistently depending on how the app is served,
        // and a missing image would silently render as a broken box.
        $logoPath = public_path('photo/logo.png');
        $logoData = null;
        if (is_file($logoPath)) {
            $logoData = 'data:image/png;base64,' . base64_encode(file_get_contents($logoPath));
        }

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('admin.exports.students-pdf', [
            'students'    => $students,
            'title'       => $gradeLevel ? $gradeLevel . ' — Student List' : 'Master Student List',
            'gradeFilter' => $gradeLevel ?: 'All Grade Levels',
            'generatedAt' => now()->format('M d, Y g:i A'),
            'generatedBy' => trim(($request->user()->first_name ?? '') . ' ' . ($request->user()->last_name ?? '')) ?: 'Administrator',
            'logoData'    => $logoData,
        ])->setPaper('a4', 'landscape');

        // Page numbers go on after layout, once the total page count is
        // known; download() below reuses this render instead of redoing it.
        $pdf->render();
        $dompdf = $pdf->getDomPDF();
        $canvas = $dompdf->getCanvas();
        $canvas->page_text(
            $canvas->get_width() - 80,
            $canvas->get_height() - 38,
            '{PAGE_NUM} of {PAGE_COUNT}',
            $dompdf->getFontMetrics()->getFont('DejaVu Sans'),
            8.5,
            [0.58, 0.64, 0.72]
        );

        $filename = 'students_' . now()->format('Y-m-d_His') . '.pdf';

        return $pdf->download($filename);
    }

    /**
     * POST /admin/students
     * "Add Student – Late Enrollment": admin directly creates an already-
     * approved enrollment for a student whose parent already has an
     * account (skips the parent-facing Step 1/2 form and document review).
     * Fields the modal doesn't collect (address, parent names, emergency
     * contact, session, payment method) get placeholder defaults, same
     * convention as the 'N/A' placeholders used in store()/update() above —
     * the parent or admin can fill in real values later via the normal
     * edit flow.
     */
    public function adminStore(Request $request)
    {
        $validated = $request->validate([
            'grade_level' => 'required|string|max:20',
            'last_name'   => 'required|string|max:100',
            'first_name'  => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'birthday'    => ['required', 'date', 'before_or_equal:' . now()->subYears(4)->format('Y-m-d')],
            'email'       => 'required|email',
        ], $this->messages());

        if (! $request->user()->canManageGrade($validated['grade_level'])) {
            abort(403, 'You are not assigned to manage ' . $validated['grade_level'] . '.');
        }

        $parent = \App\Models\Parents::where('email', $validated['email'])->first();

        if (! $parent) {
            return response()->json([
                'message' => 'No parent account found with that email. The parent needs to register an account before a student can be added under it.',
            ], 422);
        }

        $enrollment = StudentEnrollment::create([
            'user_id'           => $parent->id,
            'first_name'        => $validated['first_name'],
            'middle_name'       => ($validated['middle_name'] ?? null) ?: 'N/A',
            'last_name'         => $validated['last_name'],
            'suffix'            => 'N/A',
            'lrn'               => 'N/A',
            'grade_level'       => $validated['grade_level'],
            'student_type'      => 'new',
            'birthday'          => $validated['birthday'],
            'birth_place'       => 'N/A',
            'address'           => 'N/A',
            'mother_name'       => 'N/A',
            'father_name'       => 'N/A',
            'guardian_name'     => 'N/A',
            'emergency_contact' => 'N/A',
            'preferred_session' => 'AM',
            'payment_method'    => 'cash',
            'status'            => 'approved',
        ]);

        \App\Models\ActivityLog::record(
            $request->user(),
            'Added Student (Late Enrollment)',
            trim($enrollment->first_name . ' ' . $enrollment->last_name) . ' (' . $enrollment->grade_level . ')',
            'success'
        );

        return response()->json([
            'success' => true,
            'message' => trim($enrollment->first_name . ' ' . $enrollment->last_name) . ' has been enrolled.',
        ], 201);
    }

    /**
     * PATCH /admin/applications/{enrollment}/approve
     * Admin approves a pending application. This is the transition that
     * makes the student eligible for sectioning (SectionController::generate
     * only picks up status='approved' students).
     */
    public function approve(Request $request, StudentEnrollment $enrollment)
    {
        if (! $request->user()->canManageGrade($enrollment->grade_level)) {
            abort(403, 'You are not assigned to manage ' . $enrollment->grade_level . '.');
        }

        if ($enrollment->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending applications can be approved.',
            ], 422);
        }

        $enrollment->update(['status' => 'approved']);
        $this->settleDownPayment($enrollment, $request->user());

        // Approving the application implies the submitted documents were
        // reviewed too — otherwise every requirement stays stuck on
        // "Pending Review" forever, since nothing else ever sets a
        // requirement to 'approved'. Leaves 'needs_resubmit' docs alone
        // rather than silently clearing a flag the admin deliberately set.
        $enrollment->requirements()
            ->whereNotIn('status', ['approved', 'needs_resubmit'])
            ->update(['status' => 'approved', 'reviewed_at' => now()]);

        \App\Models\ActivityLog::record(
            $request->user(),
            'Approved Application',
            'Applicant: ' . trim($enrollment->first_name . ' ' . $enrollment->last_name) . ' (' . $enrollment->grade_level . ')',
            'success'
        );

        SafeNotify::to($enrollment->user, new EnrollmentApproved($enrollment));

        return response()->json([
            'success' => true,
            'message' => trim($enrollment->first_name . ' ' . $enrollment->last_name) . ' has been approved.',
        ]);
    }

    /**
     * Approving an application implies its Step 1 enrollment-fee receipt
     * was accepted too, so any still-pending down payment proof is verified
     * and the installment's status recomputed from its proofs. A proof the
     * admin already sent back for resubmission is left rejected — that
     * money is still owed.
     */
    private function settleDownPayment(StudentEnrollment $enrollment, $admin): void
    {
        $downPayment = $enrollment->tuitionPlan?->payments()->where('installment_number', 0)->first();

        if (! $downPayment || ! $downPayment->proofs()->exists()) {
            return;
        }

        $downPayment->proofs()->where('status', 'pending')->update([
            'status'      => 'verified',
            'verified_at' => now(),
            'verified_by' => $admin->id,
        ]);

        $downPayment->refreshStatus();
    }

    /**
     * POST /admin/applications/bulk-approve
     * Approves several pending applications in one action. Rather than
     * failing the whole batch over one bad id, each one is checked
     * individually and skipped (with a reason) if it's outside the admin's
     * assigned grade scope or no longer pending — so a stale selection
     * (someone else already actioned one mid-batch) can't silently corrupt
     * the rest.
     */
    public function bulkApprove(Request $request)
    {
        $validated = $request->validate([
            'ids'   => 'required|array|min:1',
            'ids.*' => 'integer',
        ]);

        $approvedNames = [];
        $skipped = [];

        DB::transaction(function () use ($validated, $request, &$approvedNames, &$skipped) {
            $enrollments = StudentEnrollment::whereIn('id', $validated['ids'])->lockForUpdate()->get();

            foreach ($enrollments as $enrollment) {
                $name = trim($enrollment->first_name . ' ' . $enrollment->last_name);

                if (! $request->user()->canManageGrade($enrollment->grade_level)) {
                    $skipped[] = "{$name} (not in your assigned grades)";
                    continue;
                }

                if ($enrollment->status !== 'pending') {
                    $skipped[] = "{$name} (already reviewed)";
                    continue;
                }

                $enrollment->update(['status' => 'approved']);
                $this->settleDownPayment($enrollment, $request->user());

                // Same auto-approval as the single approve() path — a
                // bulk-approved batch shouldn't leave its documents stuck
                // on "Pending Review" either.
                $enrollment->requirements()
                    ->whereNotIn('status', ['approved', 'needs_resubmit'])
                    ->update(['status' => 'approved', 'reviewed_at' => now()]);

                SafeNotify::to($enrollment->user, new EnrollmentApproved($enrollment));
                    $approvedNames[] = $name;
            }
        });

        if (!empty($approvedNames)) {
            \App\Models\ActivityLog::record(
                $request->user(),
                'Bulk Approved Applications',
                count($approvedNames) . ' application(s): ' . implode(', ', $approvedNames),
                'success'
            );
        }

        return response()->json([
            'success'  => true,
            'approved' => $approvedNames,
            'skipped'  => $skipped,
            'message'  => count($approvedNames) . ' application(s) approved' . (count($skipped) ? ', ' . count($skipped) . ' skipped.' : '.'),
        ]);
    }

    /**
     * POST /children/{enrollment}/photo
     * Uploads (or replaces) the child's profile photo, shown in the My Children panel.
     */
    public function uploadPhoto(Request $request, StudentEnrollment $enrollment)
    {
        $parent = Auth::guard('parent')->user();

        if ($enrollment->user_id !== $parent->id) {
            abort(403, 'You do not have permission to update this child\'s photo.');
        }

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        if ($enrollment->photo && Storage::disk('public')->exists($enrollment->photo)) {
            Storage::disk('public')->delete($enrollment->photo);
        }

        $path = ImageUploadStorer::store($request->file('photo'), 'child-photos', 'public', maxWidth: 500);

        $enrollment->photo = $path;
        $enrollment->save();

        return response()->json([
            'success' => true,
            'message' => 'Photo updated.',
            'url'     => $enrollment->photo_url,
        ]);
    }

    /**
     * DELETE /children/{enrollment}/photo
     */
    public function removePhoto(Request $request, StudentEnrollment $enrollment)
    {
        $parent = Auth::guard('parent')->user();

        if ($enrollment->user_id !== $parent->id) {
            abort(403, 'You do not have permission to update this child\'s photo.');
        }

        if ($enrollment->photo && Storage::disk('public')->exists($enrollment->photo)) {
            Storage::disk('public')->delete($enrollment->photo);
        }

        $enrollment->photo = null;
        $enrollment->save();

        return response()->json([
            'success' => true,
            'message' => 'Photo removed.',
        ]);
    }
}