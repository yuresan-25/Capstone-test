<?php
    
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Authcontroller;
use App\Http\Controllers\RequirementsController;
use App\Http\Controllers\EnrollmentController;
use App\Http\Controllers\Parent\ParentProfileController;
use App\Http\Controllers\SuperAdminController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\TuitionController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaymongoController;
// ── PUBLIC ROUTES ─────────────────────────────────────────────────────────────
Route::get('/',               [Authcontroller::class, 'landingpage'])->name('landingpage');
Route::get('/logout',         [Authcontroller::class, 'logout'])->name('logout');
Route::get('/terms',   [App\Http\Controllers\LegalController::class, 'terms'])->name('terms.show');
Route::get('/privacy', [App\Http\Controllers\LegalController::class, 'privacy'])->name('privacy.show');
Route::get('/clear-view', function() {
    Artisan::call('view:clear');
    return 'Blade view cache cleared successfully!';
});


// Guest-only: an already-logged-in user is redirected to their dashboard
// instead of seeing these — they must log out first to reach them again.
Route::middleware(['guest.only'])->group(function () {
    Route::get('/auth/login',     [Authcontroller::class, 'login'])->name('login');
    Route::get('/auth/admission', [Authcontroller::class, 'admission'])->name('admission');
    Route::get('/register',       [Authcontroller::class, 'Showregister'])->name('register');
    Route::get('/forgot-password',        [Authcontroller::class, 'showForgotPasswordForm'])->name('password.request');
    Route::get('/reset-password/{token}', [Authcontroller::class, 'showResetPasswordForm'])->name('password.reset');
});

Route::post('/auth/login',    [Authcontroller::class, 'loginUser'])->name('login.store')->middleware('throttle:6,1');
Route::post('/register',      [Authcontroller::class, 'register'])->name('register.store')->middleware('throttle:6,1');

// Forgot / Reset password
Route::post('/forgot-password',       [Authcontroller::class, 'sendResetLinkEmail'])->name('password.email')->middleware('throttle:6,1');
Route::post('/reset-password',        [Authcontroller::class, 'resetPassword'])->name('password.update')->middleware('throttle:6,1');

// PayMongo server-to-server notifications — no session or CSRF token; only
// trusted after PayMongo::verifySignature() (CSRF exemption in bootstrap/app.php).
Route::post('/webhooks/paymongo', [PaymongoController::class, 'webhook'])->name('webhooks.paymongo')->middleware('throttle:120,1');

// Where PayMongo sends the parent back. Deliberately NOT behind parent auth:
// the checkout can take longer than the login session lasts, and the check
// here doesn't depend on who is logged in. The signed URL (made when the
// checkout was created) stops anyone guessing or editing the address.
Route::get('/tuition/paymongo/return/{checkout}', [PaymongoController::class, 'return'])
    ->name('tuition.paymongo.return')
    ->middleware(['signed', 'throttle:30,1']);

// ── PARENT ROUTES — protected by parent guard ──────────────────────────────────
Route::middleware(['auth:parent'])->group(function () {
    Route::get('/parent', [Authcontroller::class, 'studentportal'])->name('parent.dashboard')->middleware('no.cache');

    // Enrollment AJAX routes (Step 1 — application form)
    Route::get('/enrollment/{enrollment}',         [EnrollmentController::class, 'show'])->name('enrollment.show');
    Route::post('/enrollment',                    [EnrollmentController::class, 'store'])->name('enrollment.store');
    Route::put('/enrollment/{enrollment}',         [EnrollmentController::class, 'update'])->name('enrollment.update');
    Route::post('/enrollment/{enrollment}/finalize', [EnrollmentController::class, 'finalize'])->name('enrollment.finalize');
    Route::delete('/enrollment/{enrollment}',      [EnrollmentController::class, 'destroy'])->name('enrollment.destroy');
    Route::post('/children/{enrollment}/photo',    [EnrollmentController::class, 'uploadPhoto'])->name('children.photo.upload');
    Route::delete('/children/{enrollment}/photo',  [EnrollmentController::class, 'removePhoto'])->name('children.photo.remove');

    // Requirements AJAX routes (used in parent.blade.php)
    Route::get('/requirements',         [RequirementsController::class, 'index'])->name('requirements.index');
    Route::post('/requirements/upload', [RequirementsController::class, 'upload'])->name('requirements.upload');

    // Tuition & Payments (used in panel-tuition-payments.blade.php)
    Route::get('/tuition', [TuitionController::class, 'show'])->name('tuition.show');
    Route::get('/tuition/history', [TuitionController::class, 'history'])->name('tuition.history');
    Route::post('/tuition/payments/{payment}/upload-proof', [TuitionController::class, 'uploadProof'])->name('tuition.uploadProof');
    Route::get('/tuition/proofs/{proof}/receipt', [TuitionController::class, 'parentReceipt'])->name('tuition.receipt');

    // Online payment (PayMongo): start a checkout, and where PayMongo sends the parent back.
    Route::post('/tuition/payments/{payment}/paymongo-checkout', [PaymongoController::class, 'checkout'])->name('tuition.paymongo.checkout')->middleware('throttle:10,1');

 Route::post('/profile/upload-pic',      [Authcontroller::class, 'uploadProfilePic'])->name('parent.profile.uploadPic');
Route::post('/profile/remove-pic',      [Authcontroller::class, 'removeProfilePic'])->name('parent.profile.removePic');
Route::post('/profile/update-password', [Authcontroller::class, 'updatePassword'])->name('parent.profile.updatePassword');

    // Web push subscription (enable/disable browser notifications)
    Route::post('/push/subscribe',   [PushSubscriptionController::class, 'subscribe'])->name('push.subscribe');
    Route::post('/push/unsubscribe', [PushSubscriptionController::class, 'unsubscribe'])->name('push.unsubscribe');
});

// ── ADMIN ROUTES — protected by web guard ─────────────────────────────────────
// GET /admin is shared: adminportal() branches by role and renders either the
// admin or super_admin view, so both roles must be allowed here.
Route::middleware(['auth:web', 'role:admin,superadmin'])->group(function () {
    Route::get('/admin', [Authcontroller::class, 'adminportal'])->name('admin.dashboard')->middleware('no.cache');

    // Notification bell — both admin and superadmin can receive payment-proof alerts.
    Route::get('/admin/notifications', [NotificationController::class, 'index'])->name('admin.notifications.index');
    Route::post('/admin/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('admin.notifications.read');
    Route::post('/admin/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('admin.notifications.readAll');
});

// The rest of /admin/* is only ever called from the admin dashboard's own JS
// (superadmin has its own separate /superadmin/profile/* routes below), so
// these are restricted to role=admin specifically.
Route::middleware(['auth:web', 'role:admin'])->group(function () {
    Route::post('/admin/profile/photo', [ProfileController::class, 'uploadPhoto'])->name('admin.profile.photo');
    Route::delete('/admin/profile/photo', [ProfileController::class, 'removePhoto'])->name('admin.profile.photo.remove');
    Route::put('/admin/profile/password', [ProfileController::class, 'updatePassword'])->name('admin.profile.password');
    Route::patch('/admin/requirements/{requirement}/verify', [RequirementsController::class, 'verify'])->name('admin.requirements.verify');
    Route::patch('/admin/requirements/{requirement}/flag-resubmit', [RequirementsController::class, 'flagResubmit'])->name('admin.requirements.flagResubmit');

    // Applications
    Route::patch('/admin/applications/{enrollment}/approve', [EnrollmentController::class, 'approve'])->name('admin.applications.approve');
    Route::post('/admin/applications/bulk-approve', [EnrollmentController::class, 'bulkApprove'])->name('admin.applications.bulkApprove');

    // Sections
    Route::get('/admin/sections', [SectionController::class, 'index'])->name('admin.sections.index');
    Route::post('/admin/sections/generate', [SectionController::class, 'generate'])->name('admin.sections.generate');
    Route::patch('/admin/students/{enrollment}/transfer', [SectionController::class, 'transfer'])->name('admin.students.transfer');
    Route::get('/admin/sections/{section}/master-list', [SectionController::class, 'masterList'])
    ->name('admin.sections.masterList');

    // Students
    Route::get('/admin/students/export', [EnrollmentController::class, 'export'])->name('admin.students.export');
    Route::post('/admin/students', [EnrollmentController::class, 'adminStore'])->name('admin.students.store');

    // Tuition verification — acts on a specific proof submission, since one
    // installment can now hold several partial proofs.
    Route::post('/admin/tuition/proofs/{proof}/verify', [TuitionController::class, 'verifyProof'])->name('admin.tuition.verify');
    Route::post('/admin/tuition/proofs/{proof}/reject', [TuitionController::class, 'rejectProof'])->name('admin.tuition.reject');
    Route::get('/admin/tuition/proofs/{proof}/receipt', [TuitionController::class, 'adminReceipt'])->name('admin.tuition.receipt');

    // Direct balance override — corrects an installment's billed amount
    // itself, independent of any proof (data-entry fix, discount, waiver).
    Route::patch('/admin/tuition/payments/{payment}/adjust-amount', [TuitionController::class, 'adjustAmount'])->name('admin.tuition.adjustAmount');

    // Plan-level override — corrects the overall tuition total for a
    // student (e.g. wrong grade fee at enrollment, a scholarship granted
    // after the fact). Redistributes the change across installments that
    // don't yet have any payment submitted against them.
    Route::patch('/admin/tuition/plans/{plan}/adjust-total', [TuitionController::class, 'adjustPlanTotal'])->name('admin.tuition.adjustTotal');
});

// ── SUPERADMIN ROUTES — protected by web guard, superadmin role only ─────────
Route::middleware(['auth:web', 'role:superadmin'])->prefix('superadmin')->name('superadmin.')->group(function () {
 
    // Admin Accounts
    Route::post('/admins', [SuperAdminController::class, 'storeAdmin'])->name('admins.store');
    Route::put('/admins/{admin}', [SuperAdminController::class, 'updateAdmin'])->name('admins.update');
    Route::post('/admins/{admin}/reset-password', [SuperAdminController::class, 'resetAdminPassword'])->name('admins.reset-password');
    Route::post('/admins/{admin}/toggle-active', [SuperAdminController::class, 'toggleAdminActive'])->name('admins.toggle-active');
 
    // Enrollment Period
    Route::post('/enrollment-period', [SuperAdminController::class, 'saveEnrollmentPeriod'])->name('enrollment-period.save');
    Route::post('/enrollment-period/{period}/toggle', [SuperAdminController::class, 'toggleEnrollmentPeriod'])->name('enrollment-period.toggle');

    // Grade-level enrollment settings
    Route::put('/grade-settings', [SuperAdminController::class, 'updateGradeSettings'])->name('grade-settings.update');

    // Announcements
    Route::post('/announcements', [SuperAdminController::class, 'storeAnnouncement'])->name('announcements.store');
    Route::put('/announcements/{announcement}', [SuperAdminController::class, 'updateAnnouncement'])->name('announcements.update');
    Route::delete('/announcements/{announcement}', [SuperAdminController::class, 'deleteAnnouncement'])->name('announcements.delete');
    Route::post('/announcements/{announcement}/toggle-status', [SuperAdminController::class, 'toggleAnnouncementStatus'])->name('announcements.toggle-status');
    Route::post('/profile/photo', [ProfileController::class, 'uploadPhoto'])->name('profile.photo');
    Route::delete('/profile/photo', [ProfileController::class, 'removePhoto'])->name('profile.photo.remove');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    // Tuition rate configuration
    Route::put('/tuition/grade-fees', [TuitionController::class, 'updateGradeFees'])->name('tuition.gradeFees.update');
    //terns of use
   
});