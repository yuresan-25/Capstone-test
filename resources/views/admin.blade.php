@extends('layout.app')
@section('content')


<?php
use App\Models\StudentEnrollment;
use App\Models\Section;

/* ── URL params ── */
$appId   = request('app_id',  '');
$modal   = request('modal',   '');
$stuId   = request('stu_id',  '');
$stuName = request('stu_name','');

$transferStudent = ($modal === 'transfer' && $stuId) ? StudentEnrollment::find($stuId) : null;

// An admin scoped to specific grades (assigned_grades) only ever sees data
// for those grades — an unrestricted admin (null/empty) sees everything,
// same "All Grades" convention used throughout. Filters, not just a UI
// nicety: the write endpoints these pickers feed into (approve, sections
// generate/transfer, tuition verify/reject) enforce the same scope
// server-side via User::canManageGrade(), so this keeps what's shown from
// promising actions the admin isn't actually allowed to take.
$currentAdmin  = \Illuminate\Support\Facades\Auth::guard('web')->user();
$scopedGrades  = $currentAdmin->assigned_grades ?: null;
$allGradeLevelOptions = ['Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6','Grade 7','Grade 8','Grade 9','Grade 10'];
$gradeLevelOptions    = $scopedGrades ? array_values(array_intersect($allGradeLevelOptions, $scopedGrades)) : $allGradeLevelOptions;

/* Current school year label, e.g. "SY 2026–2027" (was hand-typed). */
$currentSchoolYear = ($sy = \App\Models\EnrollmentPeriod::current()?->school_year) ? 'SY ' . str_replace('-', '–', $sy) : 'Current School Year';

/* ── Real DB data ──
   Both tables are paginated (25 per page) with a server-side search, and
   every installment's proofs are loaded up front — previously the page
   loaded every student and ran ~11 queries per student (5,500+ queries and
   ~2s at 1,000 students). Statistics below use COUNT queries instead of
   the full lists, so they still cover every student, not just one page. */
$perPage   = 25;
$appSearch = trim((string) request('app_q', ''));
$stuSearch = trim((string) request('stu_q', ''));

// Name / LRN search: "dela cruz", "Juan", "Cruz, Juan" or an LRN.
$nameSearch = function ($q, string $term) {
    $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $term) . '%';
    $q->where(fn ($w) => $w->where('first_name', 'like', $like)
        ->orWhere('last_name', 'like', $like)
        ->orWhere('lrn', 'like', $like)
        ->orWhereRaw("CONCAT(first_name, ' ', last_name) LIKE ?", [$like])
        ->orWhereRaw("CONCAT(last_name, ', ', first_name) LIKE ?", [$like]));
};

$pendingBase  = StudentEnrollment::where('status', 'pending')
    ->when($scopedGrades, fn ($q) => $q->whereIn('grade_level', $scopedGrades));
$studentsBase = StudentEnrollment::whereIn('status', ['approved', 'enrolled'])
    ->when($scopedGrades, fn ($q) => $q->whereIn('grade_level', $scopedGrades));

$applications = (clone $pendingBase)
    ->when($appSearch !== '', fn ($q) => $nameSearch($q, $appSearch))
    ->with('user')
    ->orderByDesc('created_at')
    ->paginate($perPage, ['*'], 'app_page')
    ->appends(['tab' => 'applications', 'app_q' => $appSearch ?: null]);

$students = (clone $studentsBase)
    ->when($stuSearch !== '', fn ($q) => $nameSearch($q, $stuSearch))
    ->with(['user', 'tuitionPlan.payments.proofs'])
    ->orderBy('last_name')
    ->orderBy('first_name')
    ->paginate($perPage, ['*'], 'stu_page')
    ->appends(['tab' => 'students', 'stu_q' => $stuSearch ?: null]);

/* ── Stats (whole school, not just the current page or search) ── */
$totalStudents = (clone $studentsBase)->count();
$pendingCount  = (clone $pendingBase)->count();

/* ──────────────────────────────────────────────────────
   SECTIONS — real, persisted (see SectionController::generate).
   AM/PM is a per-student schedule preference only; it does NOT split
   sections — grouping is by grade_level alone. Ideal size: 15, max: 30
   (handled server-side when sections are generated).
────────────────────────────────────────────────────── */
$sectionsByGrade = Section::withCount('students')
    ->when($scopedGrades, fn ($q) => $q->whereIn('grade_level', $scopedGrades))
    ->orderBy('name')
    ->get()
    ->groupBy('grade_level');

$unsectionedByGrade = StudentEnrollment::where('status', 'approved')
    ->whereNull('section_id')
    ->when($scopedGrades, fn ($q) => $q->whereIn('grade_level', $scopedGrades))
    ->selectRaw('grade_level, COUNT(*) as total')
    ->groupBy('grade_level')
    ->pluck('total', 'grade_level');

/* ── Grade display config ── */
$gradeConfig = [
    'Kinder'   => ['id' => 'kinder', 'icon' => 'teal',  'fill' => 'fill-teal',  'color' => '#0f766e', 'bg' => '#ccfbf1'],
    'Grade 1'  => ['id' => 'g1',     'icon' => 'amber', 'fill' => 'fill-amber', 'color' => '#b45309', 'bg' => '#fef3c7'],
    'Grade 2'  => ['id' => 'g2',     'icon' => 'rose',  'fill' => 'fill-rose',  'color' => '#be185d', 'bg' => '#fce7f3'],
    'Grade 3'  => ['id' => 'g3',     'icon' => 'navy',  'fill' => 'fill-navy',  'color' => '#1e3a8a', 'bg' => '#eff6ff'],
    'Grade 4'  => ['id' => 'g4',     'icon' => 'teal',  'fill' => 'fill-teal',  'color' => '#0f766e', 'bg' => '#ccfbf1'],
    'Grade 5'  => ['id' => 'g5',     'icon' => 'amber', 'fill' => 'fill-amber', 'color' => '#b45309', 'bg' => '#fef3c7'],
    'Grade 6'  => ['id' => 'g6',     'icon' => 'rose',  'fill' => 'fill-rose',  'color' => '#be185d', 'bg' => '#fce7f3'],
    'Grade 7'  => ['id' => 'g7',  'icon' => 'teal',  'fill' => 'fill-teal',  'color' => 'var(--g7-color)',  'bg' => 'var(--g7-light)'],
    'Grade 8'  => ['id' => 'g8',  'icon' => 'amber', 'fill' => 'fill-amber', 'color' => '#b45309',          'bg' => '#fef3c7'],
    'Grade 9'  => ['id' => 'g9',  'icon' => 'rose',  'fill' => 'fill-rose',  'color' => '#be185d',          'bg' => '#fce7f3'],
    'Grade 10' => ['id' => 'g10', 'icon' => 'navy',  'fill' => 'fill-navy',  'color' => 'var(--navy)',      'bg' => 'var(--navy-light)'],
];

/* ──────────────────────────────────────────────────────
   LIVE CHART DATA — computed from real enrollment records
   so the Statistics tab always reflects current students.
────────────────────────────────────────────────────── */

/* Enrollment by Grade (approved/enrolled students, grouped by grade_level) */
$gradeCountsRaw   = (clone $studentsBase)->selectRaw('grade_level, COUNT(*) as total')->groupBy('grade_level')->pluck('total', 'grade_level');
$chartGradeLabels = array_keys($gradeConfig);
$chartGradeData   = array_map(fn($g) => $gradeCountsRaw[$g] ?? 0, $chartGradeLabels);

/* Gender distribution — student_enrollment has no `sex` column yet, so
   these stay 0 until one is added (same result as before, without loading
   every student to find that out). */
$maleCount   = 0;
$femaleCount = 0;
$genderTotal = max($maleCount + $femaleCount, 1);
$malePct     = round($maleCount / $genderTotal * 100);
$femalePct   = round($femaleCount / $genderTotal * 100);

/* Application Status Breakdown — reuses the real counts already computed above */
$statusApproved = $totalStudents;
$statusPending  = $pendingCount;

/* Enrollment Growth (cumulative, by month, current school year) — built from
   real created_at timestamps of approved/enrolled students. Replaces the old
   hardcoded "last 3 SY" demo chart since there's no stored multi-year archive yet. */
$trendLabels = [];
$trendData   = [];
$running     = 0;
$monthlyNew = (clone $studentsBase)
    ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, COUNT(*) as total")
    ->groupBy('ym')
    ->orderBy('ym')
    ->pluck('total', 'ym');
foreach ($monthlyNew as $ym => $count) {
    $running    += $count;
    $trendLabels[] = \Carbon\Carbon::createFromFormat('Y-m', $ym)->format('M Y');
    $trendData[]    = $running;
}
if (empty($trendLabels)) {
    $trendLabels = ['No enrollments yet'];
    $trendData   = [0];
}

/* ── Profile modal data ── */
$profileEnrollment = null;
if ($modal === 'profile' && $appId) {
    $profileEnrollment = StudentEnrollment::with(['user', 'requirements', 'tuitionPlan.payments.proofs.verifier'])->find($appId);
}
?>


<style>
/* ===== FULL SIDEBAR LAYOUT ===== */
body { margin:0; background:#f1f5f9; }

/* Wrapper */
.page-wrapper {
  display: flex;
  min-height: 100vh;
}

/* ── LEFT SIDEBAR ── */
.left-sidebar {
  width: 240px;
  min-width: 240px;
  background: #1a2744;
  display: flex;
  flex-direction: column;
  position: fixed;
  top: 0; left: 0;
  height: 100vh;
  height: 100dvh;
  z-index: 200;
  overflow-y: auto;
  transition: transform .28s cubic-bezier(.4,0,.2,1);
}
.left-sidebar::-webkit-scrollbar { width: 4px; }
.left-sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,.1); border-radius:4px; }

/* Brand */
.sb-brand {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 20px 20px 16px;
  border-bottom: 1px solid rgba(255,255,255,.08);
}
.sb-brand img { width:40px; height:40px; border-radius:10px; background:#fff; padding:3px; }
.sb-brand-text { line-height: 1.2; }
.sb-brand-name { font-size:15px; font-weight:800; color:#fff; letter-spacing:.01em; }
.sb-brand-sub  { font-size:10.5px; color:rgba(255,255,255,.45); }

/* User card */
.sb-user {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 20px;
  border-bottom: 1px solid rgba(255,255,255,.08);
}
.sb-avatar {
  width: 36px; height: 36px; border-radius: 50%;
  background: var(--navy, #1e3a8a);
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 800; color: #fff; flex-shrink: 0;
  border: 2px solid rgba(255,255,255,.2);
  overflow: hidden;
}
.sb-avatar img { width:100%; height:100%; object-fit:cover; border-radius:50%; }
.sb-user-name { font-size:13px; font-weight:700; color:#fff; line-height:1.2; }
.sb-user-role { font-size:10.5px; color:rgba(255,255,255,.45); }

/* Nav section label */
.sb-section-label {
  font-size: 10px;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .1em;
  color: rgba(255,255,255,.35);
  padding: 18px 20px 6px;
}

/* Nav item */
.sb-nav-item {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 11px 20px;
  color: rgba(255,255,255,.65);
  font-size: 13.5px;
  font-weight: 500;
  cursor: pointer;
  transition: background .15s, color .15s;
  border-left: 3px solid transparent;
  user-select: none;
  text-decoration: none;
}
.sb-nav-item i { font-size:16px; width:20px; text-align:center; flex-shrink:0; }
.sb-nav-item:hover {
  background: rgba(255,255,255,.07);
  color: #fff;
  text-decoration: none;
}
.sb-nav-item.active {
  background: rgba(255,255,255,.12);
  color: #fff;
  font-weight: 700;
  border-left-color: #60a5fa;
}
.sb-nav-item.active i { color: #60a5fa; }

/* Badge on nav item */
.sb-badge {
  margin-left: auto;
  background: #3b82f6;
  color: #fff;
  font-size: 10px;
  font-weight: 700;
  border-radius: 20px;
  padding: 1px 7px;
  line-height: 1.7;
}

/* Bottom of sidebar */
.sb-bottom {
  margin-top: auto;
  padding: 12px 12px 16px;
  padding-bottom: calc(64px + env(safe-area-inset-bottom));
  border-top: 1px solid rgba(255,255,255,.08);
}
.sb-logout {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  border-radius: 10px;
  color: rgba(255,255,255,.6);
  font-size: 13.5px;
  font-weight: 500;
  cursor: pointer;
  text-decoration: none;
  transition: background .15s, color .15s;
}
.sb-logout:hover { background: rgba(239,68,68,.18); color: #f87171; text-decoration:none; }
.sb-logout i { font-size:16px; }

/* ── RIGHT CONTENT AREA ── */
.main-area {
  margin-left: 240px;
  flex: 1;
  width: calc(100% - 240px);
  display: flex;
  flex-direction: column;
  min-width: 0;
}

/* Top bar (right side) */
.main-topbar {
  background: #fff;
  border-bottom: 1px solid #e2e8f0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 28px;
  position: sticky;
  top: 0;
  z-index: 100;
}
.topbar-toggle {
  display: none;
  background: none;
  border: none;
  font-size: 20px;
  color: #475569;
  cursor: pointer;
  padding: 4px;
}
.topbar-right { display:flex; align-items:center; gap:10px; }
.topbar-icon {
  width:34px; height:34px; border-radius:8px;
  display:flex; align-items:center; justify-content:center;
  font-size:16px; color:#64748b; cursor:pointer;
  background: #f8fafc; border: 1px solid #e2e8f0;
  transition: background .15s;
}
.topbar-icon:hover { background:#f1f5f9; color:#1e293b; }

/* Page content */
.admin-content {
  padding: 28px;
  flex: 1;
  width: 100%;
  min-width: 0;
  box-sizing: border-box;
}

/* Tab panels & their direct cards fill full width */
#admin-tab-applications,
#admin-tab-students,
#admin-tab-sections,
#admin-tab-statistics {
  width: 100%;
}

#admin-tab-applications > .card,
#admin-tab-students > .card,
#admin-tab-sections > .card {
  width: 100%;
  max-width: 100%;
}

/* Mobile sidebar overlay */
.sb-overlay {
  display: none;
  position: fixed;
  inset: 0;
  background: rgba(0,0,0,.45);
  z-index: 199;
}

/* Clickable section row hover */
.section-row[title] {
  transition: background .15s, box-shadow .15s;
}
.section-row[title]:hover {
  background: #f0f9ff;
  box-shadow: 0 0 0 2px #3b82f6 inset;
  border-radius: 10px;
}

/* ── RESPONSIVE ── */
@media (max-width: 991px) {
  .left-sidebar { transform: translateX(-100%); }
  .left-sidebar.open { transform: translateX(0); }
  .sb-overlay.open { display: block; }
  .main-area { margin-left: 0; width: 100%; }
  .topbar-toggle { display: flex; align-items:center; justify-content:center; }
  .admin-content { padding: 18px; }
}
@media (max-width: 575px) {
  .admin-content { padding: 14px; }
}

/* ── TABLE ROW SIZING ── */
#appTable thead th,
#stuTable thead th {
  padding: 10px 12px;
  font-size: 11.5px;
  white-space: nowrap;
}

#appTable tbody td,
#stuTable tbody td {
  padding: 10px 12px;
  font-size: 13px;
  vertical-align: middle;
  line-height: 1.4;
}

/* Keep avatar/badge vertically centered and not too large */
.stu-avatar {
  width: 30px !important;
  height: 30px !important;
  min-width: 30px !important;
  font-size: 11px !important;
  margin-right: 8px !important;
}

/* Compact action button */
.action-dots-btn {
  padding: 6px 10px !important;
  font-size: 13px !important;
  line-height: 1.2 !important;
  min-width: 34px;
  min-height: 34px;
}
@media (max-width: 767px) {
  .action-dots-btn { min-width: 40px; min-height: 40px; padding: 8px 12px !important; }
}

/* Tighter badge sizing */
.badge-enrolled,
.badge-pending,
.badge-resubmit,
.badge-approved {
  font-size: 11px !important;
  padding: 3px 10px !important;
  border-radius: 20px !important;
  white-space: nowrap;
}

/* ── Modern Profile Photo: clickable avatar ── */
.pp-avatar-wrap {
  position: relative; width: 110px; height: 110px; cursor: pointer;
}
.pp-avatar-img, .pp-avatar-fallback {
  width: 110px; height: 110px; border-radius: 50%; object-fit: cover;
  border: 3px solid var(--border); display: flex; align-items: center; justify-content: center;
  background: #f1f5f9; color: #94a3b8; font-size: 40px; transition: filter .15s;
}
.pp-avatar-overlay {
  position: absolute; inset: 0; border-radius: 50%;
  background: rgba(26,42,94,.55); color: #fff; font-size: 22px;
  display: flex; align-items: center; justify-content: center;
  opacity: 0; transition: opacity .18s;
}
.pp-avatar-wrap:hover .pp-avatar-overlay,
.pp-avatar-wrap:focus .pp-avatar-overlay { opacity: 1; }
.pp-avatar-badge {
  position: absolute; bottom: 2px; right: 2px;
  width: 32px; height: 32px; border-radius: 50%;
  background: var(--navy); color: var(--gold);
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; border: 3px solid #fff;
}

/* ── Modern Profile Photo: upload modal ── */
.pp-modal .modal-content { border-radius: 16px; border: none; }
.pp-dropzone {
  border: 2px dashed var(--border); border-radius: 14px;
  padding: 28px 20px; display: flex; flex-direction: column; align-items: center; gap: 14px;
  cursor: pointer; transition: border-color .15s, background .15s; text-align: center;
}
.pp-dropzone:hover, .pp-dropzone.pp-dragover {
  border-color: var(--gold); background: var(--gold-light);
}
.pp-preview-circle {
  width: 96px; height: 96px; border-radius: 50%; overflow: hidden;
  background: #f1f5f9; display: flex; align-items: center; justify-content: center;
  border: 2px solid var(--border); flex-shrink: 0;
}
.pp-preview-circle img { width: 100%; height: 100%; object-fit: cover; }
.pp-preview-fallback { color: #94a3b8; font-size: 36px; }
.pp-dropzone-text { max-width: 280px; }
</style>


<!-- ===== SIDEBAR OVERLAY (mobile) ===== -->
<div class="sb-overlay" id="sbOverlay" onclick="closeSidebar()"></div>

<!-- ===== PAGE WRAPPER ===== -->
<div class="page-wrapper">

  <!-- ── LEFT SIDEBAR ── -->
  <aside class="left-sidebar sb-admin" id="leftSidebar">
    <div class="sb-brand">
      <img src="{{ asset('photo/logo.png') }}" alt="PHLCI Logo">
      <div class="sb-brand-text">
        <div class="sb-brand-name">PHLCI</div>
        <div class="sb-brand-sub">Admin Dashboard</div>
      </div>
    </div>

    <div class="sb-user">
      <div class="sb-avatar av-navy">
        @if($user->profile_photo)
          <img src="{{ asset('storage/' . $user->profile_photo) }}" alt="Profile">
        @else
          {{ $initials }}
        @endif
      </div>
      <div>
        <div class="sb-user-name">{{ $fullName }}</div>
        <div class="sb-user-role">{{ ucfirst($user->role) }}</div>
      </div>
    </div>

    <div class="sb-section-label">Main Menu</div>

 <div class="sb-nav-item active" onclick="switchAdminTab('statistics',this)" data-tab="statistics">
      <i class="bi bi-bar-chart-fill"></i>
      <span>Statistics</span>
    </div>

    <div class="sb-nav-item" onclick="switchAdminTab('applications',this)" data-tab="applications">
      <i class="bi bi-file-earmark-text"></i>
      <span>Applications</span>
      <span class="sb-badge">{{ $pendingCount }}</span>
    </div>
    <div class="sb-nav-item" onclick="switchAdminTab('students',this)" data-tab="students">
      <i class="bi bi-people"></i><span>Students</span>
    </div>
    <div class="sb-nav-item" onclick="switchAdminTab('sections',this)" data-tab="sections">
      <i class="bi bi-layout-text-sidebar-reverse"></i><span>Sections</span>
    </div>
    <div class="sb-nav-item" onclick="switchAdminTab('profile',this)" data-tab="profile">
      <i class="bi bi-person-circle"></i><span>My Profile</span>
    </div>
   

    <div class="sb-bottom">
      <a href="{{ route('logout') }}" class="sb-logout">
        <i class="bi bi-box-arrow-left"></i><span>Logout</span>
      </a>
    </div>
  </aside>

  <!-- ── MAIN AREA ── -->
  <div class="main-area">
    <div class="main-topbar">
      <div class="d-flex align-items-center gap-3">
        <button class="topbar-toggle" onclick="openSidebar()"><i class="bi bi-list"></i></button>
        <div>
          <div class="fw-bold" style="font-size:15px;color:#1e293b" id="pageTitle">Applications</div>
          <div class="text-muted" style="font-size:12px">Manage enrollment, students, and sections</div>
        </div>
      </div>
      <div class="topbar-right">
        <div class="position-relative">
          <div class="topbar-icon" style="position:relative" onclick="toggleNotifDropdown(event)" id="notifBellBtn">
            <i class="bi bi-bell-fill"></i>
            <span id="notifBadge" class="d-none" style="position:absolute;top:-4px;right:-4px;background:#dc2626;color:#fff;border-radius:999px;font-size:10px;line-height:1;min-width:16px;height:16px;display:flex;align-items:center;justify-content:center;padding:2px;font-weight:700"></span>
          </div>
          <div id="notifDropdown" class="d-none notif-dropdown" style="position:absolute;right:0;top:44px;width:340px;max-height:420px;overflow-y:auto;background:#fff;border:1px solid #e2e8f0;border-radius:12px;box-shadow:0 12px 32px rgba(0,0,0,.12);z-index:200">
            <div style="padding:12px 14px;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:between;gap:8px">
              <span style="font-size:13px;font-weight:700;color:#1e293b">Notifications</span>
              <button type="button" class="btn btn-link btn-sm ms-auto p-0" style="font-size:11.5px;text-decoration:none" onclick="markAllNotifsRead(event)">Mark all read</button>
            </div>
            <div id="notifList"></div>
          </div>
        </div>
        <div class="brand-logo" style="background:var(--navy);width:34px;height:34px;font-size:13px;overflow:hidden">
          @if($user->profile_photo)
            <img src="{{ asset('storage/' . $user->profile_photo) }}" alt="Profile" style="width:100%;height:100%;object-fit:cover;border-radius:50%">
          @else
            {{ $initials }}
          @endif
        </div>
      </div>
    </div>

    <!-- ===== MAIN CONTENT ===== -->
    <div class="admin-content">

      <!-- ══════════════════════════════════════
           TAB: STATISTICS
      ══════════════════════════════════════ -->
      <div id="admin-tab-statistics" class="d-none">
      <!-- STAT CARDS -->
      <div class="row g-3 mb-4">
        <div class="col-md-3 col-6">
          <div class="card border rounded-3 p-3 h-100 position-relative overflow-hidden">
            <div class="stat-icon blue"><i class="bi bi-people-fill"></i></div>
            <div class="fw-bold mb-1" style="font-size:28px;color:#1e293b">{{ $totalStudents }}</div>
            <div class="text-muted" style="font-size:13px">Total Students</div>
          </div>
        </div>
        <div class="col-md-3 col-6">
          <div class="card border rounded-3 p-3 h-100 position-relative overflow-hidden">
            <div class="stat-icon orange"><i class="bi bi-clock-fill"></i></div>
            <div class="fw-bold mb-1" style="font-size:28px;color:#1e293b">{{ $pendingCount }}</div>
            <div class="text-muted" style="font-size:13px">Pending Applications</div>
          </div>
        </div>
        <div class="col-md-3 col-6">
          <div class="card border rounded-3 p-3 h-100 position-relative overflow-hidden">
            <div class="stat-icon teal"><i class="bi bi-book-fill"></i></div>
            <div class="fw-bold mb-1" style="font-size:28px;color:#1e293b">
              {{ $sectionsByGrade->flatten()->count() }}
            </div>
            <div class="text-muted" style="font-size:13px">Active Sections</div>
          </div>
        </div>
        <div class="col-md-3 col-6">
          <div class="card border rounded-3 p-3 h-100 position-relative overflow-hidden">
            <div class="stat-icon green"><i class="bi bi-graph-up-arrow"></i></div>
            <div class="fw-bold mb-1" style="font-size:28px;color:#1e293b">
              @php
                $totalApps = $totalStudents + $pendingCount;
                $rate = $totalApps > 0 ? round($totalStudents / $totalApps * 100) : 0;
              @endphp
              {{ $rate }}%
            </div>
            <div class="text-muted" style="font-size:13px">Enrollment Rate</div>
          </div>
        </div>
      </div>

      <!-- CHARTS -->
      <div class="row g-3 mb-4">
        <div class="col-md-7">
          <div class="card border rounded-3 p-3 h-100">
            <div class="fw-bold mb-1" style="font-size:15px;color:#1e293b">Enrollment by Grade</div>
            <div class="text-muted mb-3" style="font-size:13px">Number of students per grade level</div>
            <div style="position:relative;width:100%;height:220px">
              <canvas id="barChart"></canvas>
            </div>
          </div>
        </div>
        <div class="col-md-5">
          <div class="card border rounded-3 p-3 h-100">
            <div class="fw-bold mb-1" style="font-size:15px;color:#1e293b">Student Gender Distribution</div>
            <div class="text-muted mb-3" style="font-size:13px">Male vs Female student ratio</div>
            <div class="d-flex align-items-center justify-content-center gap-4 h-100 pb-3 flex-column flex-sm-row">
              <div style="position:relative;width:160px;height:160px;flex-shrink:0">
                <canvas id="pieChart"></canvas>
              </div>
              <div class="d-flex flex-column gap-2">
                <div class="d-flex align-items-center gap-2" style="font-size:13px"><span class="rounded-circle d-inline-block" style="width:12px;height:12px;background:#1e3a8a"></span> Male: {{ $maleCount }} ({{ $malePct }}%)</div>
                <div class="d-flex align-items-center gap-2" style="font-size:13px;color:#0d9488"><span class="rounded-circle d-inline-block" style="width:12px;height:12px;background:#0d9488"></span> Female: {{ $femaleCount }} ({{ $femalePct }}%)</div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- APPLICATION STATUS BREAKDOWN -->
      <div class="row g-3 mb-4">
        <div class="col-md-6">
          <div class="card border rounded-3 p-3 h-100">
            <div class="fw-bold mb-1" style="font-size:15px;color:#1e293b">Application Status Breakdown</div>
            <div class="text-muted mb-3" style="font-size:13px">Current SY application outcomes</div>
            <div style="position:relative;width:100%;height:200px">
              <canvas id="appStatusChart"></canvas>
            </div>
          </div>
        </div>
        <div class="col-md-6">
          <div class="card border rounded-3 p-3 h-100">
            <div class="fw-bold mb-1" style="font-size:15px;color:#1e293b">Enrollment Growth (This School Year)</div>
            <div class="text-muted mb-3" style="font-size:13px">Cumulative enrolled students by month</div>
            <div style="position:relative;width:100%;height:200px">
              <canvas id="trendChart"></canvas>
            </div>
          </div>
        </div>
      </div>
      </div><!-- /admin-tab-statistics -->

      <!-- ══════════════════════════════════════
           TAB: APPLICATIONS
      ══════════════════════════════════════ -->
      <div id="admin-tab-applications">
        <div class="card border rounded-3 p-3 p-md-4">
          <div class="d-flex align-items-start justify-content-between mb-3 flex-wrap gap-2">
            <div>
              <div class="fw-bold mb-1" style="font-size:15px;color:#1e293b">Recent Applications</div>
              <div class="text-muted" style="font-size:13px">Review and manage admission applications</div>
            </div>
            <div class="d-flex gap-2 flex-wrap align-items-center">
              <button class="btn btn-success btn-sm fw-semibold d-none" id="bulkApproveBtn" onclick="bulkApproveSelected()" disabled>
                <i class="bi bi-check2-all me-1"></i>Approve Selected (<span id="bulkApproveCount">0</span>)
              </button>
              <button class="btn-icon-sm" title="Filter"><i class="bi bi-funnel"></i></button>
            </div>
          </div>

          <form method="GET" class="position-relative mb-3" role="search">
            <input type="hidden" name="tab" value="applications">
            <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
            <input type="search" name="app_q" value="{{ $appSearch }}" class="form-control ps-5" placeholder="Search by name or LRN, then press Enter" aria-label="Search applications">
          </form>
          @if($appSearch !== '')
          <div class="mb-2" style="font-size:12.5px">Showing results for "<strong>{{ $appSearch }}</strong>" &middot; <a href="?tab=applications">Clear search</a></div>
          @endif

          <div class="table-responsive">
            <table class="table table-hover align-middle" id="appTable">
              <thead class="table-light">
                <tr>
                  <th style="width:36px"><input type="checkbox" class="form-check-input" id="appSelectAll" onchange="toggleSelectAllApplications(this)"></th>
                  @foreach(['#','Name','Grade','Session','Date Applied','Status','Actions'] as $h)
                  <th style="text-transform:uppercase;letter-spacing:.04em;color:#64748b">{{ $h }}</th>
                  @endforeach
                </tr>
              </thead>
              <tbody>
                @forelse($applications as $i => $app)
                @php
                  $appFullName = $app->last_name . ', ' . $app->first_name . ($app->middle_name && $app->middle_name !== 'N/A' ? ' ' . substr($app->middle_name,0,1).'.' : '');
                  $appInitials = strtoupper(substr($app->first_name,0,1) . substr($app->last_name,0,1));
                @endphp
                <tr>
                  <td><input type="checkbox" class="form-check-input app-row-check" value="{{ $app->id }}" onchange="updateBulkApproveButton()"></td>
                  <td>{{ $applications->firstItem() + $loop->index }}</td>
                  <td><span class="stu-avatar av-blue">{{ $appInitials }}</span> {{ $appFullName }}</td>
                  <td>{{ $app->grade_level }}</td>
                  <td>
                    <span class="badge rounded-pill px-2" style="background:{{ $app->preferred_session === 'AM' ? '#dbeafe' : '#fce7f3' }};color:{{ $app->preferred_session === 'AM' ? '#1e40af' : '#be185d' }};font-size:11px">
                      {{ $app->preferred_session }}
                    </span>
                  </td>
                  <td>{{ $app->created_at->format('M d, Y') }}</td>
                  <td><span class="badge-pending">Pending</span></td>
                  <td>
                    <div class="action-menu-wrap position-relative">
                      <button class="btn btn-sm btn-light border action-dots-btn" onclick="toggleActionMenu(event,this)">
                        <i class="bi bi-three-dots-vertical"></i>
                      </button>
                      <div class="action-dropdown shadow-sm">
                        <a class="action-item" href="?modal=profile&app_id={{ $app->id }}&from=applications">
                          <i class="bi bi-eye text-navy"></i> View Profile
                        </a>
                        <button type="button" class="action-item text-success" onclick="closeMenuThen(()=>approveApplication({{ $app->id }}, '{{ addslashes($appFullName) }}'))">
                          <i class="bi bi-check-circle"></i> Approve
                        </button>
                      </div>
                    </div>
                  </td>
                </tr>
                @empty
                <tr>
                  <td colspan="8" class="text-center text-muted py-4">
                    <i class="bi bi-inbox" style="font-size:32px"></i>
                    <div class="mt-2">{{ $appSearch !== '' ? 'No applications match your search' : 'No pending applications' }}</div>
                  </td>
                </tr>
                @endforelse
              </tbody>
            </table>
          </div>
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3">
            <div class="text-muted" style="font-size:13px">
              @if($applications->total() > 0)
                Showing {{ $applications->firstItem() }}–{{ $applications->lastItem() }} of {{ $applications->total() }} pending application(s)
              @else
                Total: 0 pending application(s)
              @endif
            </div>
            {{ $applications->onEachSide(1)->links('pagination::bootstrap-5') }}
          </div>
        </div>
      </div>

      <!-- ══════════════════════════════════════
           TAB: STUDENTS
      ══════════════════════════════════════ -->
      <div id="admin-tab-students" class="d-none">
        <div class="card border rounded-3 p-3 p-md-4">
          <div class="d-flex align-items-start justify-content-between mb-3 flex-wrap gap-2">
            <div>
              <div class="fw-bold mb-1" style="font-size:15px;color:#1e293b">Student Management</div>
              <div class="text-muted" style="font-size:13px">View and manage enrolled students</div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
              <button class="btn-icon-sm" title="Filter"><i class="bi bi-funnel"></i></button>
              <a href="?modal=export" class="btn btn-navy btn-sm fw-semibold">
                <i class="bi bi-download me-1"></i>Export
              </a>
              <a href="?modal=addStudent" class="btn btn-navy btn-sm fw-semibold">
                <i class="bi bi-person-plus me-1"></i>Add Student
              </a>
            </div>
          </div>

          <form method="GET" class="position-relative mb-3" role="search">
            <input type="hidden" name="tab" value="students">
            <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
            <input type="search" name="stu_q" value="{{ $stuSearch }}" class="form-control ps-5" placeholder="Search by name or LRN, then press Enter" aria-label="Search students">
          </form>
          @if($stuSearch !== '')
          <div class="mb-2" style="font-size:12.5px">Showing results for "<strong>{{ $stuSearch }}</strong>" &middot; <a href="?tab=students">Clear search</a></div>
          @endif

          <div class="table-responsive">
            <table class="table table-hover align-middle" id="stuTable">
              <thead class="table-light">
                <tr>
                  @foreach(['#','Name','Grade','Session','Status','Payment','Actions'] as $h)
                  <th style="text-transform:uppercase;letter-spacing:.04em;color:#64748b">{{ $h }}</th>
                  @endforeach
                </tr>
              </thead>
              <tbody>
                @forelse($students as $i => $stu)
                @php
                  $stuFullName = $stu->last_name . ', ' . $stu->first_name . ($stu->middle_name && $stu->middle_name !== 'N/A' ? ' ' . substr($stu->middle_name,0,1).'.' : '');
                  $stuInitials = strtoupper(substr($stu->first_name,0,1) . substr($stu->last_name,0,1));
                  $avatarColors = ['av-blue','av-teal','av-orange','av-green','av-purple'];
                  $avatarColor  = $avatarColors[$loop->index % count($avatarColors)];
                @endphp
                @php
                  $payments = $stu->tuitionPlan?->payments ?? collect();
                  $downPayment = $payments->firstWhere('installment_number', 0);

                  if ($stu->status === 'enrolled') {
                    $watchPayment = $payments->where('installment_number', '>', 0)
                      ->whereIn('status', ['unpaid', 'partial'])
                      ->sortBy('due_date')
                      ->first();
                  } else {
                    $watchPayment = $downPayment;
                  }

                  // A proof can be pending review on an installment
                  // regardless of its overall unpaid/partial/paid status
                  // (e.g. ₱950 already verified, a further ₱500 pending).
                  $hasAnyPendingProof = $payments->contains(fn ($pay) => $pay->hasPendingProof());
                @endphp
                <tr>
                  <td>{{ $students->firstItem() + $loop->index }}</td>
                  <td>
                    <span class="stu-avatar {{ $avatarColor }}">{{ $stuInitials }}</span> {{ $stuFullName }}
                    @if($hasAnyPendingProof)
                      <span title="Payment proof awaiting review" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:#dc2626;margin-left:5px;vertical-align:middle"></span>
                    @endif
                  </td>
                  <td>{{ $stu->grade_level }}</td>
                  <td>
                    <span class="badge rounded-pill px-2" style="background:{{ $stu->preferred_session === 'AM' ? '#dbeafe' : '#fce7f3' }};color:{{ $stu->preferred_session === 'AM' ? '#1e40af' : '#be185d' }};font-size:11px">
                      {{ $stu->preferred_session }}
                    </span>
                  </td>
                  <td><span class="badge-{{ $stu->status === 'enrolled' ? 'enrolled' : 'approved' }}">{{ ucfirst($stu->status) }}</span></td>
                  <td>
                    {{-- $watchPayment already computed above the <tr> so the
                         Name column's red dot can use it too. --}}
                    @if($payments->isEmpty())
                      <span class="text-muted" style="font-size:11px">—</span>
                    @elseif(!$watchPayment)
                      {{-- enrolled student with every installment already paid --}}
                      <span class="badge rounded-pill px-2" style="background:#dcfce7;color:#166534;font-size:11px">Paid</span>
                    @elseif($watchPayment->status === 'partial')
                      <span class="badge rounded-pill px-2" style="background:#e0f2fe;color:#0369a1;font-size:11px">Partially Paid</span>
                    @elseif($watchPayment->status === 'unpaid')
                      <span class="badge rounded-pill px-2" style="background:#fef3c7;color:#b45309;font-size:11px">
                        {{ $watchPayment->due_date->isPast() ? 'Overdue' : 'Unpaid' }}
                      </span>
                    @else
                      <span class="badge rounded-pill px-2" style="background:#dcfce7;color:#166534;font-size:11px">Paid</span>
                    @endif
                  </td>
                  <td>
                    <div class="action-menu-wrap position-relative">
                      <button class="btn btn-sm btn-light border action-dots-btn" onclick="toggleActionMenu(event,this)">
                        <i class="bi bi-three-dots-vertical"></i>
                      </button>
                      <div class="action-dropdown shadow-sm">
                        <a class="action-item" href="?modal=profile&app_id={{ $stu->id }}&from=students">
                          <i class="bi bi-eye text-navy"></i> View Profile
                        </a>
                        <a class="action-item" href="?modal=transfer&stu_id={{ $stu->id }}&stu_name={{ urlencode(trim($stu->first_name.' '.$stu->last_name)) }}">
                          <i class="bi bi-arrow-left-right text-navy"></i> Transfer Section
                        </a>
                      </div>
                    </div>
                  </td>
                </tr>
                @empty
                <tr>
                  <td colspan="7" class="text-center text-muted py-4">
                    <i class="bi bi-people" style="font-size:32px"></i>
                    <div class="mt-2">{{ $stuSearch !== '' ? 'No students match your search' : 'No approved or enrolled students yet' }}</div>
                  </td>
                </tr>
                @endforelse
              </tbody>
            </table>
          </div>
          <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 mt-3">
            <div class="text-muted" style="font-size:13px">
              @if($students->total() > 0)
                Showing {{ $students->firstItem() }}–{{ $students->lastItem() }} of {{ $students->total() }} student(s)
              @else
                Total: 0 student(s)
              @endif
            </div>
            {{ $students->onEachSide(1)->links('pagination::bootstrap-5') }}
          </div>
        </div>
      </div>

      <!-- ══════════════════════════════════════
           TAB: SECTIONS
      ══════════════════════════════════════ -->
      <div id="admin-tab-sections" class="d-none">
        <div class="card border rounded-3 p-3 p-md-4">
          <div class="d-flex align-items-start justify-content-between mb-3 flex-wrap gap-2">
            <div>
              <div class="fw-bold mb-1" style="font-size:15px;color:#1e293b">Section Management</div>
              <div class="text-muted" style="font-size:13px">Click a grade level to view its sections</div>
            </div>
            <div class="d-flex gap-2 flex-wrap">
              <a href="?modal=createSection" class="btn btn-navy btn-sm fw-semibold">
                <i class="bi bi-plus-circle me-1"></i>Generate Sections
              </a>
            </div>
          </div>

          <!-- Current SY pill -->
          <div class="d-flex align-items-center gap-2 mb-4 p-2 rounded-2" style="background:#eff6ff;border:1px solid #bfdbfe;font-size:13px">
            <i class="bi bi-calendar2-week-fill" style="color:#1e40af"></i>
            <span class="fw-semibold" style="color:#1e40af">Currently Viewing: {{ $currentSchoolYear }}</span>
            <span class="badge rounded-pill ms-1" style="background:#1e3a8a;color:#fff;font-size:11px">Active</span>
          </div>

          {{-- All grades with real enrollment counts from DB --}}
          @foreach($gradeConfig as $gradeLabel => $gc)
          @php
            $gradeSections    = $sectionsByGrade[$gradeLabel] ?? collect();
            $totalSectioned   = $gradeSections->sum('students_count');
            $unsectionedCount = $unsectionedByGrade[$gradeLabel] ?? 0;
            $gid              = $gc['id'];
            $gcColor          = $gc['color'];
            $gcBg             = $gc['bg'];
          @endphp
          <div class="grade-card" id="{{ $gid }}">
            <div class="grade-header" onclick="toggleGrade('{{ $gid }}')">
              <div class="grade-icon {{ $gc['icon'] }}"><i class="bi bi-mortarboard-fill"></i></div>
              <div class="grade-info">
                <div class="grade-title">{{ $gradeLabel }}</div>
                <div class="grade-meta" id="{{ $gid }}-meta">
                  @if($gradeSections->count() > 0)
                    {{ $gradeSections->count() }} Section(s) &bull; {{ $totalSectioned }} Students
                  @else
                    No sections yet
                  @endif
                  @if($unsectionedCount > 0)
                    &bull; <span style="color:#b45309;white-space:nowrap">{{ $unsectionedCount }} awaiting sectioning</span>
                  @endif
                </div>
              </div>
              <div class="grade-bar-wrap">
                <div class="grade-pill-bar">
                  <div class="fill {{ $gc['fill'] }}" style="width:{{ $gradeSections->count() > 0 ? min(100, round($totalSectioned / $gradeSections->count() / 30 * 100)) : 0 }}%"></div>
                </div>
              </div>
              <i class="bi bi-chevron-down chevron"></i>
            </div>
            <div class="sections-wrap" id="{{ $gid }}-sections">
              @if($gradeSections->count() > 0)
                @foreach($gradeSections as $sec)
                @php $pct = round($sec->students_count / \App\Http\Controllers\SectionController::MAX_SIZE * 100); @endphp
               <div class="section-row" style="cursor:pointer" title="Click to view Master List"
                     onclick="openMasterList({{ $sec->id }}, '{{ addslashes($gradeLabel) }}', '{{ addslashes($sec->name) }}')">
                  <div class="section-top">
                    <div class="section-icon" style="background:{{ $gcBg }};color:{{ $gcColor }}">
                      <i class="bi bi-book-fill"></i>
                    </div>
                    <div>
                      <div class="section-title">{{ $sec->name }}</div>
                    </div>
                    <div style="margin-left:auto;display:flex;align-items:center;gap:8px">
                      <span style="font-size:12px;font-weight:600;color:{{ $gcColor }};background:{{ $gcBg }};padding:2px 10px;border-radius:20px">
                        {{ $sec->students_count }}/{{ \App\Http\Controllers\SectionController::MAX_SIZE }}
                      </span>
                    </div>
                  </div>
                  <div class="cap-row">
                    <span class="cap-label">Capacity</span>
                    <span class="cap-value">{{ $sec->students_count }} / {{ \App\Http\Controllers\SectionController::MAX_SIZE }} students</span>
                  </div>
                  <div class="cap-bar">
                    <div class="fill {{ $gc['fill'] }}" style="width:{{ $pct }}%"></div>
                  </div>
                </div>
                @endforeach
              @else
                <div class="empty-section-state">
                  <div class="empty-section-icon" style="background:{{ $gcBg }};color:{{ $gcColor }}">
                    <i class="bi bi-layout-text-sidebar-reverse"></i>
                  </div>
                  <div class="empty-section-title">No Sections Yet</div>
                  <div class="empty-section-sub">
                    @if($unsectionedCount > 0)
                      {{ $unsectionedCount }} approved student(s) in <strong>{{ $gradeLabel }}</strong> are waiting — click "Generate Sections" above to assign them.
                    @else
                      Once students for <strong>{{ $gradeLabel }}</strong> are approved, sections can be generated here.
                    @endif
                  </div>
                </div>
              @endif
            </div>
          </div>
          @endforeach
        </div>
      </div>

      <!-- ═══ TAB: MY PROFILE ═══ -->
      <div id="admin-tab-profile" class="d-none">
        <div class="fw-bold mb-1" style="font-size:20px;color:#1e293b">My Profile</div>
        <div class="text-muted mb-4" style="font-size:14px">Manage your profile picture and account password</div>

        <!-- Profile Picture -->
        <div class="card border rounded-3 p-4 mb-4">
          <div class="fw-bold mb-3 pb-3" style="font-size:15px;color:#1e293b;border-bottom:1px solid #f1f5f9"><i class="bi bi-image-fill me-2 text-navy"></i>Profile Picture</div>
          <div class="d-flex flex-column align-items-center text-center py-2">
            <div class="pp-avatar-wrap" id="ppAvatarTrigger" onclick="openPhotoModal()" role="button" tabindex="0" title="Change profile photo">
              @if($user->profile_photo ?? false)
                <img src="{{ asset('storage/'.$user->profile_photo) }}" alt="Profile photo" class="pp-avatar-img" id="ppCurrentImg">
              @else
                <div class="pp-avatar-fallback" id="ppCurrentFallback"><i class="bi bi-person-fill"></i></div>
              @endif
              <div class="pp-avatar-overlay"><i class="bi bi-camera-fill"></i></div>
              <div class="pp-avatar-badge"><i class="bi bi-camera-fill"></i></div>
            </div>
            <div class="fw-semibold mt-3" style="font-size:14.5px;color:#1e293b">{{ $fullName }}</div>
            <div class="text-muted" style="font-size:13px">{{ $user->email }}</div>
            <button type="button" class="btn btn-outline-navy btn-sm fw-semibold mt-3" onclick="openPhotoModal()">
              <i class="bi bi-pencil-fill me-1"></i>Change Photo
            </button>
          </div>
        </div>

        <!-- Change Password -->
        <div class="card border rounded-3 p-4">
          <div class="fw-bold mb-3 pb-3" style="font-size:15px;color:#1e293b;border-bottom:1px solid #f1f5f9"><i class="bi bi-shield-lock-fill me-2 text-navy"></i>Change Password</div>

          <div class="alert d-flex align-items-start gap-2 mb-4" style="background:var(--navy-light);border:1px solid #c7d2ea;border-radius:10px">
            <i class="bi bi-info-circle-fill" style="color:var(--navy);margin-top:2px"></i>
            <div style="font-size:13px;color:var(--navy)">For your security, choose a strong password with at least 8 characters including uppercase, lowercase, numbers, and symbols.</div>
          </div>

          <form id="changePasswordForm" onsubmit="submitChangePassword(event)">
            <div class="mb-3">
              <label class="form-label fw-medium" style="font-size:13px">Current Password <span class="text-danger">*</span></label>
              <div class="input-group">
                <input type="password" id="cp-current" class="form-control" placeholder="Enter your current password" required>
                <button type="button" class="btn btn-outline-secondary" onclick="togglePw('cp-current',this)"><i class="bi bi-eye"></i></button>
              </div>
            </div>
            <div class="row g-3">
              <div class="col-md-6">
                <label class="form-label fw-medium" style="font-size:13px">New Password <span class="text-danger">*</span></label>
                <div class="input-group">
                  <input type="password" id="cp-new" class="form-control" placeholder="Enter new password" minlength="8" required oninput="updatePasswordChecklist(this.value, 'cp-new-req')">
                  <button type="button" class="btn btn-outline-secondary" onclick="togglePw('cp-new',this)"><i class="bi bi-eye"></i></button>
                </div>
                @include('partials.password-checklist', ['prefix' => 'cp-new-req'])
              </div>
              <div class="col-md-6">
                <label class="form-label fw-medium" style="font-size:13px">Confirm New Password <span class="text-danger">*</span></label>
                <div class="input-group">
                  <input type="password" id="cp-confirm" class="form-control" placeholder="Re-enter new password" minlength="8" required>
                  <button type="button" class="btn btn-outline-secondary" onclick="togglePw('cp-confirm',this)"><i class="bi bi-eye"></i></button>
                </div>
              </div>
            </div>
            <button type="submit" class="btn btn-navy fw-semibold mt-4">
              <i class="bi bi-shield-check me-1"></i>Update Password
            </button>
          </form>
        </div>
      </div>

    </div><!-- /admin-content -->
  </div><!-- /main-area -->
</div><!-- /page-wrapper -->

<!-- ═══ MODAL: FLAG DOCUMENT FOR RESUBMIT ═══ -->
<div class="modal fade" id="resubmitFeedbackModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px;overflow:hidden">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold" style="color:#991b1b"><i class="bi bi-arrow-repeat me-2"></i>Request Resubmit</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <p style="font-size:14px">Let the parent know what's wrong with <strong id="resubmitDocLabel"></strong> so they can re-upload it — e.g. the photo is blurry, cropped, or the wrong document.</p>
        <label class="form-label fw-medium" style="font-size:13px">Note to Parent <span class="text-danger">*</span></label>
        <textarea class="form-control" id="resubmitFeedbackText" rows="3" placeholder="e.g. The photo is too blurry to read — please retake it in good lighting."></textarea>
        <div id="resubmitFeedbackError" class="text-danger mt-2 d-none" style="font-size:12.5px"></div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-danger btn-sm fw-semibold" id="resubmitFeedbackSubmitBtn" onclick="submitResubmitFlag()">
          <i class="bi bi-send me-1"></i>Notify Parent
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ MODAL: NOTIFICATION DETAIL (payment proof submitted) ═══ -->
<div class="modal fade" id="notifDetailModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px;overflow:hidden">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold" style="color:#1e293b"><i class="bi bi-cash-coin me-2" style="color:#0d9488"></i>Payment Proof Submitted</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2" style="font-size:13px">
          <div class="col-6">
            <div class="text-muted" style="font-size:11px">Student</div>
            <div class="fw-semibold" id="notifModalStudent">—</div>
          </div>
          <div class="col-6">
            <div class="text-muted" style="font-size:11px">Parent</div>
            <div class="fw-semibold" id="notifModalParent">—</div>
          </div>
          <div class="col-6">
            <div class="text-muted" style="font-size:11px">Installment</div>
            <div class="fw-semibold" id="notifModalLabel">—</div>
          </div>
          <div class="col-6">
            <div class="text-muted" style="font-size:11px">Amount</div>
            <div class="fw-semibold" id="notifModalAmount">—</div>
          </div>
          <div class="col-6">
            <div class="text-muted" style="font-size:11px">Method</div>
            <div class="fw-semibold" id="notifModalMethod">—</div>
          </div>
          <div class="col-12">
            <div class="text-muted" style="font-size:11px">Submitted</div>
            <div class="fw-semibold" id="notifModalSubmitted">—</div>
          </div>
        </div>

        <div class="mt-3">
          <div class="text-muted mb-1" style="font-size:11px">Proof of Payment</div>
          <div id="notifModalProofWrap" class="text-center"></div>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Close</button>
        <button type="button" class="btn btn-danger btn-sm fw-semibold" onclick="rejectFromNotifModal()">
          <i class="bi bi-arrow-repeat me-1"></i>Reject
        </button>
        <button type="button" class="btn btn-success btn-sm fw-semibold" onclick="verifyFromNotifModal()">
          <i class="bi bi-check-lg me-1"></i>Verify
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ MODAL: VERIFY PAYMENT (amount confirmation) ═══ -->
<div class="modal fade" id="verifyProofModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold" style="color:#1e293b"><i class="bi bi-check-circle me-2" style="color:#16a34a"></i>Verify Payment</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="text-muted mb-3" style="font-size:12.5px" id="verifyProofLabel"></div>
        <div class="mb-1">
          <label class="form-label fw-medium" style="font-size:12.5px">Amount to credit</label>
          <div class="input-group">
            <span class="input-group-text">₱</span>
            <input type="number" step="0.01" min="0.01" class="form-control" id="verifyProofAmount">
          </div>
          <div class="text-muted mt-1" style="font-size:11px">
            Pre-filled with what the parent claimed. Since there's no payment gateway, correct this if the receipt shows a different amount.
          </div>
        </div>
        <div id="verifyProofError" class="text-danger d-none mt-2" style="font-size:12px"></div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success btn-sm fw-semibold" id="verifyProofSubmitBtn" onclick="confirmVerifyProof()">
          <i class="bi bi-check-lg me-1"></i>Verify
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ MODAL: ADJUST INSTALLMENT AMOUNT (direct balance override) ═══ -->
<div class="modal fade" id="adjustAmountModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered" style="max-width:420px">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold" style="color:#1e293b"><i class="bi bi-pencil-square me-2" style="color:#0369a1"></i>Adjust Installment Amount</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <div class="text-muted mb-3" style="font-size:12.5px" id="adjustAmountLabel"></div>
        <div class="mb-2">
          <label class="form-label fw-medium" style="font-size:12.5px">Billed amount</label>
          <div class="input-group">
            <span class="input-group-text">₱</span>
            <input type="number" step="0.01" min="0" class="form-control" id="adjustAmountValue">
          </div>
        </div>
        <div class="mb-1">
          <label class="form-label fw-medium" style="font-size:12.5px">Reason <span class="text-muted fw-normal">(optional, kept in the activity log)</span></label>
          <input type="text" class="form-control" id="adjustAmountReason" placeholder="e.g. Sibling discount applied, data-entry correction">
        </div>
        <div class="text-muted mt-2" style="font-size:11px">
          This directly changes what's owed on this installment — independent of any submitted proof. Use it for corrections, discounts, or waivers.
        </div>
        <div id="adjustAmountError" class="text-danger d-none mt-2" style="font-size:12px"></div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-navy btn-sm fw-semibold" id="adjustAmountSubmitBtn" onclick="confirmAdjustAmount()">
          <i class="bi bi-check-lg me-1"></i>Save
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ MODAL: IMAGE PREVIEW (requirements / payment proofs) ═══ -->
<div class="modal fade" id="imagePreviewModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px;overflow:hidden">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold" style="color:#1e293b" id="imagePreviewTitle">Preview</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body text-center pt-2">
        <img id="imagePreviewImg" src="" alt="Preview" style="max-width:100%;max-height:70vh;border-radius:10px;border:1px solid #e2e8f0;object-fit:contain"
             onerror="if (this.getAttribute('src')) { this.classList.add('d-none'); document.getElementById('imagePreviewError').classList.remove('d-none'); }">
        {{-- PDFs (allowed for documents and payment receipts) can't render in an <img>. --}}
        <iframe id="imagePreviewFrame" class="d-none" title="Document preview" style="width:100%;height:70vh;border:1px solid #e2e8f0;border-radius:10px"></iframe>
        <div id="imagePreviewError" class="d-none text-muted py-5" style="font-size:13px">
          <i class="bi bi-file-earmark-x" style="font-size:36px;color:#94a3b8"></i>
          <div class="mt-2">This file can't be previewed here. Try "Open Full Size".</div>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <a id="imagePreviewOpenNew" href="#" target="_blank" class="btn btn-outline-secondary btn-sm">
          <i class="bi bi-box-arrow-up-right me-1"></i>Open Full Size
        </a>
        <button type="button" class="btn btn-light btn-sm border" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ═══ MODAL: MODERN PHOTO UPLOAD ═══ -->
<div class="modal fade" id="managePhotoModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content pp-modal">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold" style="color:#1e293b">Update Profile Photo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="resetPhotoModal()"></button>
      </div>
      <form id="profilePhotoForm" onsubmit="submitProfilePhoto(event)">
        <div class="modal-body pt-2">

          <div class="pp-dropzone" id="ppDropzone" onclick="document.getElementById('pp-file').click()">
            <div class="pp-preview-circle" id="ppPreviewCircle">
              @if($user->profile_photo ?? false)
                <img src="{{ asset('storage/'.$user->profile_photo) }}" alt="" id="ppPreviewImg">
              @else
                <div class="pp-preview-fallback" id="ppPreviewFallback"><i class="bi bi-person-fill"></i></div>
                <img src="" alt="" id="ppPreviewImg" style="display:none">
              @endif
            </div>
            <div class="pp-dropzone-text">
              <div class="fw-semibold" style="font-size:13.5px;color:#1e293b" id="ppDropzoneTitle">Drag & drop a photo here</div>
              <div class="text-muted" style="font-size:12px">or <span class="text-navy fw-semibold">click to browse</span> &nbsp;•&nbsp; JPG/PNG, up to 2MB</div>
            </div>
            <input type="file" id="pp-file" name="profile_photo" accept="image/png, image/jpeg" class="d-none" onchange="handlePhotoSelect(this.files[0])">
          </div>

          <div id="ppFileMeta" class="d-none mt-3 d-flex align-items-center justify-content-between rounded-3 px-3 py-2" style="background:#f8fafc;border:1px solid var(--border)">
            <div class="d-flex align-items-center gap-2" style="font-size:12.5px;color:#475569">
              <i class="bi bi-file-earmark-image text-navy"></i>
              <span id="ppFileName" class="text-truncate" style="max-width:220px"></span>
            </div>
            <button type="button" class="btn btn-sm btn-link text-danger p-0" style="font-size:12px" onclick="clearPhotoSelect()"><i class="bi bi-x-circle"></i> Remove</button>
          </div>

          <div id="ppErrorMsg" class="text-danger mt-2 d-none" style="font-size:12.5px"><i class="bi bi-exclamation-circle me-1"></i><span></span></div>
        </div>
        <div class="modal-footer border-0">
          @if($user->profile_photo ?? false)
          <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeProfilePhoto()"><i class="bi bi-trash me-1"></i>Remove Current Photo</button>
          @endif
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal" onclick="resetPhotoModal()">Cancel</button>
          <button type="submit" class="btn btn-navy btn-sm fw-semibold" id="ppSubmitBtn" disabled><i class="bi bi-check-lg me-1"></i>Save Photo</button>
        </div>
      </form>
    </div>
  </div>
</div>


<!-- ═══════════════════════════════════════════
     PHP-DRIVEN MODALS (opened via ?modal=xxx)
═══════════════════════════════════════════ -->

<?php if($modal === 'profile'): ?>
<!-- MODAL: VIEW PROFILE -->
@php
  $p = $profileEnrollment;
  // Same issue as the tab-restore JS: send Close back to whichever tab the
  // profile was actually opened from, using the "from" param set on the
  // View Profile links, instead of always assuming Applications.
  $closeHref = request('from') === 'students' ? '?tab=students' : '?tab=applications';
@endphp
<div class="modal fade" id="phpModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px;overflow:hidden">
      @if($p)
      @php
        $pInitials = strtoupper(substr($p->first_name,0,1) . substr($p->last_name,0,1));
        $pFullName = $p->first_name . ' ' . ($p->middle_name && $p->middle_name !== 'N/A' ? $p->middle_name . ' ' : '') . $p->last_name . ($p->suffix && $p->suffix !== 'N/A' ? ', ' . $p->suffix : '');
        $pAge = $p->birthday ? \Carbon\Carbon::parse($p->birthday)->age : '—';
        $pRequirements = $p->requirements ?? collect();

        // Approve should be blocked until every REQUIRED document (not the
        // optional ones like Good Moral / Medical Cert for non-Kinder) has
        // actually been individually verified — otherwise admin can approve
        // an application while a required document still sits unreviewed.
        $pRequiredDocTypes = \App\Http\Controllers\EnrollmentController::requiredDocumentTypes($p->grade_level, $p->student_type);
        $pAllRequiredApproved = collect($pRequiredDocTypes)->every(function ($type) use ($pRequirements) {
            $doc = $pRequirements->firstWhere('document_type', $type);
            return $doc && $doc->status === 'approved';
        });
      @endphp
      <div style="background:linear-gradient(135deg,#1e3a8a 0%,#0d9488 100%);padding:28px 28px 20px;position:relative">
        <a href="{{ $closeHref }}" class="btn-close btn-close-white position-absolute top-0 end-0 m-3"></a>
        <div class="d-flex align-items-center gap-3 flex-wrap">
          <div style="width:72px;height:72px;border-radius:50%;background:rgba(255,255,255,.2);border:3px solid rgba(255,255,255,.5);display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:800;color:#fff">
            {{ $pInitials }}
          </div>
          <div>
            <div style="font-size:11px;text-transform:uppercase;letter-spacing:.08em;color:rgba(255,255,255,.65);margin-bottom:3px">
              {{ in_array($p->status, ['enrolled']) ? 'Enrolled Student – Full Profile' : 'Admission Application – Full Details' }}
            </div>
            <div style="font-size:22px;font-weight:800;color:#fff">{{ $pFullName }}</div>
            <div class="d-flex align-items-center gap-2 mt-2 flex-wrap">
              <span style="background:rgba(255,255,255,.15);color:#fff;font-size:12px;font-weight:600;padding:3px 10px;border-radius:20px">{{ $p->grade_level }}</span>
              <span style="background:rgba(255,255,255,.15);color:#fff;font-size:12px;font-weight:600;padding:3px 10px;border-radius:20px">{{ $currentSchoolYear }}</span>
              <span style="background:rgba(255,255,255,.15);color:#fff;font-size:12px;font-weight:600;padding:3px 10px;border-radius:20px">{{ $p->preferred_session }} Class</span>
              @if($p->status === 'enrolled')
              <span style="background:#dcfce7;color:#166534;font-size:12px;font-weight:700;padding:3px 10px;border-radius:20px">● Enrolled</span>
              @elseif($p->status === 'approved')
              <span style="background:#dbeafe;color:#1e40af;font-size:12px;font-weight:700;padding:3px 10px;border-radius:20px">● Approved</span>
              @else
              <span style="background:#fef3c7;color:#92400e;font-size:12px;font-weight:700;padding:3px 10px;border-radius:20px">● Pending Review</span>
              @endif
            </div>
          </div>
        </div>
        <div class="d-flex gap-4 mt-3 flex-wrap" style="font-size:12px;color:rgba(255,255,255,.75)">
          <div><i class="bi bi-credit-card-2-front me-1"></i>LRN: {{ $p->lrn !== 'N/A' ? $p->lrn : 'Not provided' }}</div>
          <div><i class="bi bi-geo-alt me-1"></i>{{ $p->birth_place }}</div>
          <div><i class="bi bi-calendar3 me-1"></i>{{ $p->birthday ? \Carbon\Carbon::parse($p->birthday)->format('M d, Y') : '—' }}</div>
        </div>
      </div>
      <div class="modal-body p-0" style="background:#f8fafc">
        <style>
          .detail-label { font-size:11px; color:#94a3b8; text-transform:uppercase; letter-spacing:.03em; margin-bottom:3px; }
          .detail-value { font-size:13.5px; font-weight:600; color:#1e293b; line-height:1.4; }
        </style>
        <div style="padding:20px 24px;display:flex;flex-direction:column;gap:16px">

          {{-- Learner Info --}}
          <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
            <div style="padding:12px 16px;background:#f1f5f9;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:8px">
              <div style="width:28px;height:28px;border-radius:8px;background:#1e3a8a;display:flex;align-items:center;justify-content:center;font-size:13px;color:#fff"><i class="bi bi-person-fill"></i></div>
              <span style="font-size:13px;font-weight:700;color:#1e293b">Learner Information</span>
            </div>
            <div style="padding:18px 16px">
              <div class="row g-4">
                <div class="col-12">
                  <div class="detail-label">Full Name</div>
                  <div class="detail-value" style="font-size:15px;font-weight:700">{{ $p->last_name }}, {{ $p->first_name }} {{ $p->middle_name !== 'N/A' ? $p->middle_name : '' }}</div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="detail-label">LRN</div>
                  <div class="detail-value" style="font-family:monospace">{{ $p->lrn !== 'N/A' ? $p->lrn : '—' }}</div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="detail-label">Date of Birth</div>
                  <div class="detail-value">{{ $p->birthday ? \Carbon\Carbon::parse($p->birthday)->format('M d, Y') : '—' }}</div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="detail-label">Age</div>
                  <div class="detail-value">{{ $pAge }}</div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="detail-label">Preferred Session</div>
                  <div class="detail-value">{{ $p->preferred_session }}</div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="detail-label">Payment Method</div>
                  <div class="detail-value">{{ $p->payment_method === 'online' ? 'Online (PayMongo)' : ucfirst(str_replace('_',' ',$p->payment_method)) }}</div>
                </div>
                <div class="col-6 col-md-3">
                  <div class="detail-label">Last School Attended</div>
                  <div class="detail-value">{{ $p->last_school ?? '—' }}</div>
                </div>
                <div class="col-12 col-md-6">
                  <div class="detail-label">Place of Birth</div>
                  <div class="detail-value">{{ $p->birth_place }}</div>
                </div>
              </div>
            </div>
          </div>

          {{-- Address --}}
          <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
            <div style="padding:12px 16px;background:#f1f5f9;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:8px">
              <div style="width:28px;height:28px;border-radius:8px;background:#0d9488;display:flex;align-items:center;justify-content:center;font-size:13px;color:#fff"><i class="bi bi-geo-alt-fill"></i></div>
              <span style="font-size:13px;font-weight:700;color:#1e293b">Current Address</span>
            </div>
            <div style="padding:14px 16px;font-size:14px;font-weight:500;color:#374151;line-height:1.6">{{ $p->address }}</div>
          </div>

          {{-- Parents / Guardian --}}
          <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
            <div style="padding:12px 16px;background:#f1f5f9;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:8px">
              <div style="width:28px;height:28px;border-radius:8px;background:#d97706;display:flex;align-items:center;justify-content:center;font-size:13px;color:#fff"><i class="bi bi-people-fill"></i></div>
              <span style="font-size:13px;font-weight:700;color:#1e293b">Parent / Guardian Information</span>
            </div>
            <div style="padding:16px">
              @foreach([['role'=>'Father','name'=>$p->father_name,'contact'=>$p->emergency_contact,'bg'=>'#dbeafe','color'=>'#1e40af'],['role'=>'Mother','name'=>$p->mother_name,'contact'=>'—','bg'=>'#fce7f3','color'=>'#be185d'],['role'=>'Guardian','name'=>$p->guardian_name,'contact'=>'—','bg'=>'#dcfce7','color'=>'#166534']] as $g)
              <div class="d-flex align-items-start gap-3 mb-3 p-3 rounded-3" style="background:#f8fafc;border:1px solid #e2e8f0">
                <div style="width:34px;height:34px;border-radius:50%;background:{{ $g['bg'] }};display:flex;align-items:center;justify-content:center;font-size:14px;color:{{ $g['color'] }};flex-shrink:0"><i class="bi bi-person"></i></div>
                <div class="row g-0 w-100">
                  <div class="col-12 mb-1"><span style="font-size:11px;font-weight:700;text-transform:uppercase;color:#94a3b8">{{ $g['role'] }}</span></div>
                  <div class="col-md-7"><div style="font-size:11px;color:#94a3b8">Name</div><div style="font-size:13.5px;font-weight:600;color:#1e293b">{{ $g['name'] }}</div></div>
                  <div class="col-md-5"><div style="font-size:11px;color:#94a3b8">Contact</div><div style="font-size:13.5px;font-weight:600;color:#1e293b">{{ $g['contact'] }}</div></div>
                </div>
              </div>
              @endforeach
            </div>
          </div>

          {{-- Submitted Requirements --}}
          @if($pRequirements->isNotEmpty())
          <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
            <div style="padding:12px 16px;background:#f1f5f9;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:8px">
              <div style="width:28px;height:28px;border-radius:8px;background:#c0392b;display:flex;align-items:center;justify-content:center;font-size:13px;color:#fff"><i class="bi bi-file-earmark-check-fill"></i></div>
              <span style="font-size:13px;font-weight:700;color:#1e293b">Submitted Requirements</span>
            </div>
            <div style="padding:14px 16px;display:flex;flex-direction:column;gap:10px">
              @foreach($pRequirements as $doc)
              <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 p-3 rounded-3" id="req-doc-{{ $doc->id }}" style="background:#f8fafc;border:1px solid #e2e8f0">
                <div style="min-width:0;flex:1">
                  <div style="font-size:13px;font-weight:600;color:#1e293b">{{ $doc->document_label }}</div>
                  <div class="mt-1" id="req-doc-status-{{ $doc->id }}">
                    @if($doc->status === 'needs_resubmit')
                      <span class="badge-resubmit">Needs Resubmit</span>
                    @elseif($doc->status === 'approved')
                      <span class="badge-approved">Approved</span>
                    @else
                      <span class="badge-pending">Pending Review</span>
                    @endif
                  </div>
                  @if($doc->status === 'needs_resubmit' && $doc->feedback)
                  <div class="mt-2" style="font-size:12px;color:#991b1b;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:8px 10px">
                    <i class="bi bi-exclamation-circle-fill me-1"></i>{{ $doc->feedback }}
                  </div>
                  @endif
                </div>
                <div class="d-flex align-items-center gap-2 flex-shrink-0" id="req-doc-actions-{{ $doc->id }}">
                  <button type="button" class="btn btn-outline-secondary btn-sm" style="font-size:12px" onclick="showImagePreview('{{ asset('storage/' . $doc->path) }}', '{{ addslashes($doc->document_label) }}')"><i class="bi bi-eye me-1"></i>View</button>
                  @if($doc->status === 'pending' && !in_array($p->status, ['approved', 'enrolled']))
                  <button type="button" class="btn btn-success btn-sm" style="font-size:12px" onclick="verifyRequirementDoc({{ $doc->id }})">
                    <i class="bi bi-check-lg me-1"></i>Verify
                  </button>
                  @endif
                  @if($doc->status !== 'needs_resubmit' && !in_array($p->status, ['approved', 'enrolled']))
                  <button type="button" class="btn btn-outline-danger btn-sm" style="font-size:12px" onclick="openResubmitModal({{ $doc->id }}, '{{ addslashes($doc->document_label) }}')">
                    <i class="bi bi-arrow-repeat me-1"></i>Resubmit
                  </button>
                  @endif
                </div>
              </div>
              @endforeach
            </div>
          </div>
          @endif

          {{-- Tuition Payments --}}
          @if($p->tuitionPlan)
          <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
            <div style="padding:12px 16px;background:#f1f5f9;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:8px">
              <div style="width:28px;height:28px;border-radius:8px;background:#0369a1;display:flex;align-items:center;justify-content:center;font-size:13px;color:#fff"><i class="bi bi-cash-coin"></i></div>
              <span style="font-size:13px;font-weight:700;color:#1e293b">Tuition Payments</span>
              <span class="text-muted" style="font-size:12px;margin-left:auto">{{ ucfirst($p->tuitionPlan->plan_type) }} Plan &bull; ₱{{ number_format($p->tuitionPlan->total_amount, 2) }} total</span>
            </div>
            <div style="padding:14px 16px;display:flex;flex-direction:column;gap:10px">
              @foreach($p->tuitionPlan->payments->sortBy('installment_number') as $pay)
              @php
                $payLabel = $pay->installment_number === 0 ? 'Upon Enrollment (Down Payment)' : 'Installment ' . $pay->installment_number;
                $payMethodLabels = ['gcash'=>'GCash','maya'=>'Maya','bank_transfer'=>'Bank Transfer','cash'=>'Cash','card'=>'Card','online_banking'=>'Online Banking'];
                $verifiedAmount = $pay->verifiedAmount();
                $remaining = $pay->remainingBalance();
                $proofsSorted = $pay->proofs->sortByDesc('submitted_at');
              @endphp
              <div class="d-flex flex-column gap-2 p-3 rounded-3" id="tuition-pay-{{ $pay->id }}" style="background:#f8fafc;border:1px solid #e2e8f0">
                <div style="min-width:0;flex:1">
                  <div class="d-flex align-items-center gap-2">
                    <div style="font-size:13px;font-weight:600;color:#1e293b">{{ $payLabel }} — ₱{{ number_format($pay->amount_due, 2) }}</div>
                    <button type="button" class="btn btn-link btn-sm p-0" style="font-size:11px" onclick="openAdjustAmountModal({{ $pay->id }}, '{{ addslashes($payLabel) }}', {{ $pay->amount_due }})" title="Correct the billed amount">
                      <i class="bi bi-pencil-square"></i> Adjust
                    </button>
                  </div>
                  @if($pay->installment_number > 0)
                  <div class="text-muted mt-1" style="font-size:11.5px">Due {{ $pay->due_date->format('M j, Y') }}</div>
                  @endif
                  <div class="mt-1">
                    @if($pay->status === 'paid')
                      <span class="badge-approved">Paid</span>
                    @elseif($pay->status === 'partial')
                      <span class="badge rounded-pill px-2" style="background:#e0f2fe;color:#0369a1;font-size:11px">Partially Paid</span>
                    @else
                      <span class="badge rounded-pill px-2" style="background:#fef3c7;color:#b45309;font-size:11px">Unpaid</span>
                    @endif
                    @if($proofsSorted->where('status', 'pending')->isNotEmpty())
                      <span class="badge-pending ms-1">{{ $proofsSorted->where('status', 'pending')->count() }} Pending Review</span>
                    @endif
                  </div>
                  @if($verifiedAmount > 0)
                  <div class="text-muted mt-1" style="font-size:11.5px">₱{{ number_format($verifiedAmount, 2) }} verified &bull; ₱{{ number_format($remaining, 2) }} remaining</div>
                  @endif
                </div>

                @if($proofsSorted->isNotEmpty())
                <div class="d-flex flex-column gap-2 mt-1">
                  @foreach($proofsSorted as $proof)
                  <div class="d-flex align-items-center justify-content-between flex-wrap gap-2 p-2 rounded-3" style="background:#fff;border:1px solid #e2e8f0">
                    <div style="min-width:0;flex:1">
                      <div style="font-size:12.5px;font-weight:600;color:#1e293b">₱{{ number_format($proof->amount, 2) }}</div>
                      <div class="text-muted mt-1" style="font-size:11px">
                        <i class="bi bi-credit-card me-1"></i>{{ $payMethodLabels[$proof->payment_method] ?? $proof->payment_method }}
                        @if($proof->isOnline())
                        &bull; Paid online {{ $proof->submitted_at->format('M j, Y g:i A') }}
                        &bull; PayMongo ref {{ $proof->paymongo_payment_id }}
                        @if($proof->gateway_fee !== null) &bull; Fee ₱{{ number_format($proof->gateway_fee, 2) }} @endif
                        @else
                        &bull; Submitted {{ $proof->submitted_at->format('M j, Y g:i A') }}
                        @if($proof->verified_at) &bull; Verified {{ $proof->verified_at->format('M j, Y g:i A') }} @endif
                        @endif
                      </div>
                      @if($proof->status === 'rejected' && $proof->feedback)
                      <div class="mt-2" style="font-size:11.5px;color:#991b1b;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:6px 8px">
                        <i class="bi bi-exclamation-circle-fill me-1"></i>{{ $proof->feedback }}
                      </div>
                      @endif
                    </div>
                    <div class="d-flex align-items-center gap-2 flex-shrink-0">
                      @if($proof->isOnline())
                        <span class="badge rounded-pill px-2" style="background:#e8ecf7;color:#1a2a5e;font-size:11px"><i class="bi bi-lightning-charge-fill me-1"></i>PayMongo</span>
                      @endif
                      @if($proof->status === 'verified')
                        <span class="badge-approved">Verified</span>
                      @elseif($proof->status === 'rejected')
                        <span class="badge rounded-pill px-2" style="background:#fee2e2;color:#991b1b;font-size:11px">Rejected</span>
                      @endif
                      @if($proof->proof_of_payment)
                      <button type="button" class="btn btn-outline-secondary btn-sm" style="font-size:12px" onclick="showImagePreview('{{ asset('storage/' . $proof->proof_of_payment) }}', '{{ addslashes($payLabel) }} — ₱{{ number_format($proof->amount, 2) }}')"><i class="bi bi-eye me-1"></i>View</button>
                      @endif
                      @if($proof->status === 'verified')
                      <a class="btn btn-outline-secondary btn-sm" style="font-size:12px" href="{{ route('admin.tuition.receipt', $proof) }}" target="_blank" rel="noopener"><i class="bi bi-receipt me-1"></i>Receipt</a>
                      @endif
                      @if($proof->status === 'pending')
                      <button type="button" class="btn btn-success btn-sm" style="font-size:12px" onclick="openVerifyProofModal({{ $proof->id }}, '{{ addslashes($payLabel) }}', {{ $proof->amount }})">
                        <i class="bi bi-check-lg me-1"></i>Verify
                      </button>
                      <button type="button" class="btn btn-outline-danger btn-sm" style="font-size:12px" onclick="openResubmitModal({{ $proof->id }}, '{{ $payLabel }} (₱{{ number_format($proof->amount, 2) }})', true)">
                        <i class="bi bi-arrow-repeat me-1"></i>Resubmit
                      </button>
                      @endif
                    </div>
                  </div>
                  @endforeach
                </div>
                @endif
              </div>
              @endforeach
            </div>
          </div>
          @endif

          {{-- Proof of Payment — the enrollment fee (installment 0). Either the
               Step 1 receipt (its first proof row, so Verify / Resubmit act on
               it through the tuition proof endpoints), or — when the parent
               chose "pay online" — PayMongo's confirmation. --}}
          @if($p->proof_of_payment || $p->payment_method === 'online')
          @php
            $pDownPayment = $p->tuitionPlan?->payments->firstWhere('installment_number', 0);
            $pDownProof   = $pDownPayment?->proofs->sortByDesc('submitted_at')->first();
            $pPaidOnline  = $pDownProof?->isOnline() && $pDownProof->status === 'verified';
          @endphp
          <div style="background:#fff;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden">
            <div style="padding:12px 16px;background:#f1f5f9;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;gap:8px">
              <div style="width:28px;height:28px;border-radius:8px;background:#7c3aed;display:flex;align-items:center;justify-content:center;font-size:13px;color:#fff"><i class="bi bi-receipt"></i></div>
              <span style="font-size:13px;font-weight:700;color:#1e293b">Proof of Payment</span>
            </div>
            <div class="d-flex align-items-center justify-content-between flex-wrap gap-2" style="padding:14px 16px">
              <div style="min-width:0;flex:1">
                <div style="font-size:13px;font-weight:600;color:#1e293b">Enrollment Fee (Down Payment)@if($pDownPayment) — ₱{{ number_format($pDownPayment->amount_due, 2) }}@endif</div>
                <div class="mt-1">
                  @if($pPaidOnline)
                    <span class="badge-approved">Verified</span>
                    <span class="badge rounded-pill px-2 ms-1" style="background:#e8ecf7;color:#1a2a5e;font-size:11px"><i class="bi bi-lightning-charge-fill me-1"></i>Paid online via PayMongo</span>
                  @elseif($p->payment_method === 'online' && ! $p->proof_of_payment)
                    <span class="badge-pending">Awaiting online payment</span>
                  @elseif($pDownProof?->status === 'verified')
                    <span class="badge-approved">Verified</span>
                  @elseif($pDownProof?->status === 'rejected')
                    <span class="badge-resubmit">Needs Resubmit</span>
                  @else
                    <span class="badge-pending">Pending Review</span>
                  @endif
                </div>
                @if($pPaidOnline)
                <div class="text-muted mt-1" style="font-size:11.5px">Paid {{ $pDownProof->submitted_at->format('M j, Y g:i A') }} &bull; PayMongo ref {{ $pDownProof->paymongo_payment_id }}</div>
                @elseif($p->payment_method === 'online' && ! $p->proof_of_payment)
                <div class="text-muted mt-1" style="font-size:11.5px">The parent chose to pay online. It's confirmed automatically once they pay.</div>
                @endif
                @if($pDownProof?->status === 'rejected' && $pDownProof->feedback)
                <div class="mt-2" style="font-size:12px;color:#991b1b;background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:8px 10px">
                  <i class="bi bi-exclamation-circle-fill me-1"></i>{{ $pDownProof->feedback }}
                </div>
                @endif
              </div>
              <div class="d-flex align-items-center gap-2 flex-shrink-0">
                @if($p->proof_of_payment)
                <button type="button" class="btn btn-outline-secondary btn-sm" style="font-size:12px" onclick="showImagePreview('{{ asset('storage/' . $p->proof_of_payment) }}', 'Proof of Payment')"><i class="bi bi-eye me-1"></i>View</button>
                @endif
                @if($pDownProof?->status === 'verified')
                <a class="btn btn-outline-secondary btn-sm" style="font-size:12px" href="{{ route('admin.tuition.receipt', $pDownProof) }}" target="_blank" rel="noopener"><i class="bi bi-receipt me-1"></i>Receipt</a>
                @endif
                @if($pDownProof?->status === 'pending' && ! $pDownProof->isOnline())
                <button type="button" class="btn btn-success btn-sm" style="font-size:12px" onclick="openVerifyProofModal({{ $pDownProof->id }}, 'Enrollment Fee (Down Payment)', {{ $pDownProof->amount }})">
                  <i class="bi bi-check-lg me-1"></i>Verify
                </button>
                <button type="button" class="btn btn-outline-danger btn-sm" style="font-size:12px" onclick="openResubmitModal({{ $pDownProof->id }}, 'the enrollment fee proof of payment', true)">
                  <i class="bi bi-arrow-repeat me-1"></i>Resubmit
                </button>
                @endif
              </div>
            </div>
          </div>
          @endif

        </div>
      </div>

      <div class="modal-footer border-0" style="background:#f8fafc;padding:14px 24px">
        @if($p->status === 'pending')
        @if(\App\Http\Controllers\EnrollmentController::awaitingOnlineFee($p))
        <button type="button" class="btn btn-success btn-sm fw-semibold px-3" disabled title="The enrollment fee hasn't been paid online yet.">
          <i class="bi bi-check-circle me-1"></i>Approve
        </button>
        <span class="text-muted align-self-center" style="font-size:11.5px">Waiting for the enrollment fee to be paid online</span>
        @elseif($pAllRequiredApproved)
        <button type="button" class="btn btn-success btn-sm fw-semibold px-3" onclick="approveApplication({{ $p->id }}, '{{ addslashes($p->first_name . ' ' . $p->last_name) }}')">
          <i class="bi bi-check-circle me-1"></i>Approve
        </button>
        @else
        <button type="button" class="btn btn-success btn-sm fw-semibold px-3" disabled title="All required documents must be verified before approving.">
          <i class="bi bi-check-circle me-1"></i>Approve
        </button>
        <span class="text-muted align-self-center" style="font-size:11.5px">Verify all required documents first</span>
        @endif
        @endif
        <a href="{{ $closeHref }}" class="btn btn-light btn-sm border px-4 fw-medium"><i class="bi bi-x me-1"></i>Close</a>
      </div>
      @else
      <div class="modal-body text-center py-5">
        <i class="bi bi-person-x" style="font-size:40px;color:#94a3b8"></i>
        <div class="mt-2 text-muted">Profile not found.</div>
      </div>
      <div class="modal-footer border-0">
        <a href="{{ $closeHref }}" class="btn btn-outline-secondary btn-sm">Close</a>
      </div>
      @endif
    </div>
  </div>
</div>

<?php elseif($modal === 'addStudent'): ?>
<!-- MODAL: ADD STUDENT -->
<div class="modal fade" id="phpModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <div>
          <h5 class="modal-title fw-bold" style="color:#1e293b"><i class="bi bi-person-plus-fill me-2 text-navy"></i>Add Student – Late Enrollment</h5>
          <div class="text-muted" style="font-size:13px">Admin bypass for late-enrolled students. Complete all fields below.</div>
        </div>
        <a href="{{ route('admin.dashboard') }}" class="btn-close" aria-label="Close"></a>
      </div>
      <div class="modal-body">
        <div class="alert alert-warning py-2 mb-3" style="font-size:12.5px"><i class="bi bi-info-circle me-1"></i>The parent must already have an account under the given email — this only creates the student's enrollment record, not a parent account.</div>
        <div class="fw-semibold mb-2 mt-1" style="font-size:13.5px;color:#1e293b;border-left:3px solid var(--navy);padding-left:10px">School Information</div>
        <div class="row g-3 mb-3">
          <div class="col-md-6"><label class="form-label fw-medium" style="font-size:13px">Grade Level *</label><select class="form-select" id="addStudentGrade"><option value="">Select grade level</option>@foreach($gradeLevelOptions as $g)<option>{{ $g }}</option>@endforeach</select></div>
          <div class="col-md-6"><label class="form-label fw-medium" style="font-size:13px">Parent's Email *</label><input type="email" class="form-control" id="addStudentEmail" placeholder="parent@email.com"></div>
        </div>
        <div class="fw-semibold mb-2" style="font-size:13.5px;color:#1e293b;border-left:3px solid var(--navy);padding-left:10px">Learner Information</div>
        <div class="row g-3 mb-3">
          <div class="col-md-4"><label class="form-label fw-medium" style="font-size:13px">Last Name *</label><input type="text" class="form-control" id="addStudentLast" placeholder="Last Name"></div>
          <div class="col-md-4"><label class="form-label fw-medium" style="font-size:13px">First Name *</label><input type="text" class="form-control" id="addStudentFirst" placeholder="First Name"></div>
          <div class="col-md-4"><label class="form-label fw-medium" style="font-size:13px">Middle Name</label><input type="text" class="form-control" id="addStudentMiddle" placeholder="Middle Name"></div>
          <div class="col-md-4"><label class="form-label fw-medium" style="font-size:13px">Date of Birth *</label><input type="text" class="form-control" id="addStudentDob" placeholder="Select date" readonly autocomplete="off"></div>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <a href="{{ route('admin.dashboard') }}" class="btn btn-outline-secondary btn-sm">Cancel</a>
        <button class="btn btn-navy btn-sm fw-semibold" id="addStudentSubmitBtn" onclick="submitAddStudent(this)">
          <i class="bi bi-person-check me-1"></i>Enroll Student
        </button>
      </div>
    </div>
  </div>
</div>

<?php elseif($modal === 'export'): ?>
<!-- MODAL: EXPORT -->
<div class="modal fade" id="phpModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold" style="color:#1e293b"><i class="bi bi-download me-2 text-navy"></i>Export Student Data</h5>
        <a href="{{ route('admin.dashboard') }}" class="btn-close" aria-label="Close"></a>
      </div>
      <div class="modal-body">
        <div class="mb-3"><label class="form-label fw-medium" style="font-size:13px">Grade Level</label><select class="form-select" id="exportGrade"><option value="">All Grades</option>@foreach($gradeLevelOptions as $g)<option>{{ $g }}</option>@endforeach</select></div>
        <div class="mb-3">
          <label class="form-label fw-medium" style="font-size:13px">Format</label>
          <select class="form-select" id="exportFormat" onchange="updateExportHint()">
            <option value="pdf">PDF — printable document</option>
            <option value="csv">CSV — for Excel / Sheets</option>
          </select>
        </div>
        <div class="form-text" style="font-size:12px" id="exportHint">Downloads a printable PDF of every approved/enrolled student matching this filter.</div>
      </div>
      <div class="modal-footer border-0 pt-0">
        <a href="{{ route('admin.dashboard') }}" class="btn btn-outline-secondary btn-sm">Cancel</a>
        <a class="btn btn-navy btn-sm fw-semibold" id="exportNowBtn" href="#" onclick="startExport(this);return false;"><i class="bi bi-download me-1"></i>Export Now</a>
      </div>
    </div>
  </div>
</div>

<?php elseif($modal === 'transfer'): ?>
<!-- MODAL: TRANSFER SECTION -->
<div class="modal fade" id="phpModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered" style="max-width:460px">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px;overflow:hidden">
      <div style="background:linear-gradient(135deg,#1e3a8a,#0d9488);padding:22px 28px 18px;position:relative">
        <a href="{{ route('admin.dashboard') }}" class="btn-close btn-close-white position-absolute top-0 end-0 m-3"></a>
        <div class="d-flex align-items-center gap-3">
          <div style="width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:20px;color:#fff"><i class="bi bi-arrow-left-right"></i></div>
          <div>
            <div style="font-size:17px;font-weight:800;color:#fff">Transfer Section</div>
            <div style="font-size:12px;color:rgba(255,255,255,.7)">Student ID: {{ $stuId }}</div>
          </div>
        </div>
      </div>
      <div class="modal-body p-4">
        @if(!$transferStudent)
        <div class="alert alert-danger py-2 mb-0" style="font-size:13px"><i class="bi bi-exclamation-triangle me-1"></i>Student not found.</div>
        @else
        <div class="alert alert-info py-2 mb-3" style="font-size:13px">
          <i class="bi bi-info-circle me-1"></i>Transferring: <strong>{{ $stuName }}</strong> &nbsp;•&nbsp; Currently: <strong>{{ $transferStudent->grade_level }}{{ $transferStudent->section ? ' – '.$transferStudent->section->name : ' (unsectioned)' }}</strong>
        </div>
        <div class="mb-3"><label class="form-label fw-medium" style="font-size:13px">New Grade Level *</label>
          <select class="form-select" id="transferGrade">
            @foreach($gradeLevelOptions as $g)
              <option @selected($g === $transferStudent->grade_level)>{{ $g }}</option>
            @endforeach
          </select>
        </div>
        <div class="mb-3"><label class="form-label fw-medium" style="font-size:13px">New Section</label>
          <input type="text" class="form-control" id="transferSection" placeholder="e.g., Section B — leave blank to await Generate Sections">
        </div>
        <div class="mb-3"><label class="form-label fw-medium" style="font-size:13px">Reason</label>
          <textarea class="form-control" id="transferReason" rows="2" placeholder="Optional reason..."></textarea>
        </div>
        @endif
      </div>
      <div class="modal-footer border-0 pt-0 px-4 pb-4">
        <a href="{{ route('admin.dashboard') }}" class="btn btn-outline-secondary btn-sm">Cancel</a>
        @if($transferStudent)
        <button class="btn btn-navy btn-sm fw-semibold px-4" id="transferSubmitBtn" onclick="submitTransfer(this, {{ $transferStudent->id }})">
          <i class="bi bi-check-circle me-1"></i>Confirm Transfer
        </button>
        @endif
      </div>
    </div>
  </div>
</div>

<?php elseif($modal === 'createSection'): ?>
<!-- MODAL: CREATE SECTION -->
<div class="modal fade" id="phpModal" tabindex="-1" data-bs-backdrop="static">
  <div class="modal-dialog modal-dialog-centered" style="max-width:520px">
    <div class="modal-content border-0 shadow-lg" style="border-radius:18px;overflow:hidden">
      <div style="background:linear-gradient(135deg,#1e3a8a,#0d9488);padding:24px 28px 20px;position:relative">
        <a href="{{ route('admin.dashboard') }}" class="btn-close btn-close-white position-absolute top-0 end-0 m-3"></a>
        <div class="d-flex align-items-center gap-3">
          <div style="width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff"><i class="bi bi-layout-text-sidebar-reverse"></i></div>
          <div>
            <div style="font-size:17px;font-weight:800;color:#fff">Generate Sections</div>
            <div style="font-size:12px;color:rgba(255,255,255,.7)">Pick a grade level to auto-section its approved students</div>
          </div>
        </div>
      </div>
      <div class="modal-body p-4" style="background:#f8fafc">
        <div style="font-size:13px;color:#64748b;margin-bottom:18px;text-align:center">
          Every approved, unsectioned student in the grade gets grouped into sections of up to 30 (e.g. 31 students &rarr; two sections of 16 and 15). AM/PM is a schedule preference only and does not split sections.
        </div>
        <div class="row g-3">
          @foreach($gradeConfig as $gradeLabel => $gc)
          @php
            $gradeUnsectioned = $unsectionedByGrade[$gradeLabel] ?? 0;
          @endphp
          <div class="col-6">
            <button type="button" class="grade-pick-btn text-center p-3 w-100 border-0" style="border:2px solid #e2e8f0;border-radius:14px;background:#fff;cursor:{{ $gradeUnsectioned > 0 ? 'pointer' : 'not-allowed' }}" {{ $gradeUnsectioned > 0 ? '' : 'disabled' }} onclick="generateSections('{{ $gradeLabel }}', this)">
              <div class="grade-pick-icon mx-auto mb-2" style="width:48px;height:48px;border-radius:12px;background:{{ $gc['bg'] }};color:{{ $gc['color'] }};display:flex;align-items:center;justify-content:center;font-size:22px">
                <i class="bi bi-mortarboard-fill"></i>
              </div>
              <div class="fw-bold" style="font-size:14px;color:#1e293b">{{ $gradeLabel }}</div>
              @if($gradeUnsectioned > 0)
                <div style="font-size:11px;margin-top:4px">
                  <span style="background:#dcfce7;color:#166534;padding:2px 8px;border-radius:20px;font-weight:600">
                    {{ $gradeUnsectioned }} awaiting &rarr; {{ (int) ceil($gradeUnsectioned / \App\Http\Controllers\SectionController::MAX_SIZE) }} section(s)
                  </span>
                </div>
              @else
                <div style="font-size:11px;color:#94a3b8;margin-top:4px">No approved students waiting</div>
              @endif
            </button>
          </div>
          @endforeach
        </div>
      </div>
      <div class="modal-footer border-0" style="background:#f8fafc;padding:12px 24px">
        <a href="{{ route('admin.dashboard') }}" class="btn btn-outline-secondary btn-sm px-4">Cancel</a>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<!-- Bootstrap JS already loaded once, globally, by layout/app.blade.php -->
<script src="{{ asset('vendor/chartjs/chart.umd.js') }}"></script>
<script>

document.addEventListener('DOMContentLoaded', function () {
  // Youngest accepted grade is Kinder — a child must be at least 4 years old
  // by today to enroll anywhere in the school (enforced server-side too, in
  // EnrollmentController::adminStore()).
  var earliestAllowedBirthdate = new Date();
  earliestAllowedBirthdate.setFullYear(earliestAllowedBirthdate.getFullYear() - 4);
  flatpickr('#addStudentDob', { dateFormat: 'Y-m-d', altInput: true, altFormat: 'F j, Y', maxDate: earliestAllowedBirthdate, minDate: '1990-01-01', disableMobile: true });
});

/* ── Live chart data from the server (real enrollment records) ── */
const _chartData = {
  gradeLabels: @json($chartGradeLabels),
  gradeData:   @json($chartGradeData),
  male:        {{ $maleCount }},
  female:      {{ $femaleCount }},
  statusApproved: {{ $statusApproved }},
  statusPending:  {{ $statusPending }},
  trendLabels: @json($trendLabels),
  trendData:   @json($trendData),
};

/* ── Sidebar ── */
function openSidebar()  { document.getElementById('leftSidebar').classList.add('open'); document.getElementById('sbOverlay').classList.add('open'); }
function closeSidebar() { document.getElementById('leftSidebar').classList.remove('open'); document.getElementById('sbOverlay').classList.remove('open'); }

/* ── Bootstrap modal helper ── */
function bsModal(id) { return bootstrap.Modal.getOrCreateInstance(document.getElementById(id)); }

/* ── Shared CSRF-aware fetch helper ──
   NOTE: assumes routes admin.profile.photo (POST, multipart), admin.profile.photo.remove (DELETE),
   and admin.profile.password (PUT) exist — see route checklist. */
function apiFetch(url, method = 'GET', body = null, isFormData = false) {
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  const headers = { 'Accept': 'application/json', 'X-CSRF-TOKEN': token || '' };
  if (!isFormData) headers['Content-Type'] = 'application/json';
  return fetch(url, { method, headers, body: isFormData ? body : (body ? JSON.stringify(body) : null) })
    .then(async res => {
      if (!res.ok) throw new Error(await res.text());
      return res.status === 204 ? null : res.json();
    });
}

/* ── Notification bell ── */
function renderNotifIcon(type) {
  return {
    payment_proof_submitted: 'bi-cash-coin',
    online_payment_received: 'bi-lightning-charge-fill',
    enrollment_submitted:    'bi-person-plus-fill',
  }[type] || 'bi-bell';
}

// Notification text includes parent-typed names — never insert it as HTML.
function escapeHtml(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

// Cache of the last-loaded notifications, keyed by id, so a click can pull
// the full data object (student name, amount, proof image, etc.) without a
// second round trip — and without stuffing untrusted strings into onclick.
let _notifCache = {};

function loadNotifications() {
  apiFetch('{{ route("admin.notifications.index") }}')
    .then(data => {
      const badge = document.getElementById('notifBadge');
      if (data.unread_count > 0) {
        badge.textContent = data.unread_count > 9 ? '9+' : data.unread_count;
        badge.classList.remove('d-none');
      } else {
        badge.classList.add('d-none');
      }

      _notifCache = {};
      data.notifications.forEach(n => { _notifCache[n.id] = n; });

      const list = document.getElementById('notifList');
      if (!data.notifications.length) {
        list.innerHTML = '<div class="text-muted text-center" style="font-size:12.5px;padding:24px 12px">No notifications yet.</div>';
        return;
      }

      list.innerHTML = data.notifications.map(n => `
        <div class="d-flex align-items-start gap-2" style="padding:11px 14px;border-bottom:1px solid #f1f5f9;cursor:pointer;${n.read ? '' : 'background:#f0f9ff'}"
             onclick="openNotification('${n.id}')">
          <i class="bi ${renderNotifIcon(n.data.type)}" style="color:#0d9488;font-size:14px;margin-top:2px"></i>
          <div style="min-width:0;flex:1">
            <div style="font-size:12.5px;color:#1e293b;line-height:1.4">${escapeHtml(n.data.message)}</div>
            <div style="font-size:11px;color:#94a3b8;margin-top:2px">${escapeHtml(n.created_at)}</div>
          </div>
          ${n.read ? '' : '<span style="width:7px;height:7px;border-radius:50%;background:#dc2626;flex-shrink:0;margin-top:5px"></span>'}
        </div>
      `).join('');
    })
    .catch(() => {});
}

function toggleNotifDropdown(e) {
  e.stopPropagation();
  const dd = document.getElementById('notifDropdown');
  const willOpen = dd.classList.contains('d-none');
  dd.classList.toggle('d-none');
  if (willOpen) loadNotifications();
}

document.addEventListener('click', function (e) {
  const dd = document.getElementById('notifDropdown');
  const btn = document.getElementById('notifBellBtn');
  if (dd && !dd.classList.contains('d-none') && !dd.contains(e.target) && e.target !== btn && !btn.contains(e.target)) {
    dd.classList.add('d-none');
  }
});

/* Generic in-page viewer for requirement docs / payment proofs.
   Keeps the admin on the current page (and current modal underneath)
   instead of navigating to a new tab. */
function isPdfUrl(url) { return /\.pdf($|[?#])/i.test(String(url || '')); }

function showImagePreview(url, title) {
  const img   = document.getElementById('imagePreviewImg');
  const frame = document.getElementById('imagePreviewFrame');
  const pdf   = isPdfUrl(url);

  document.getElementById('imagePreviewTitle').textContent = title || 'Preview';
  document.getElementById('imagePreviewError').classList.add('d-none');
  img.classList.toggle('d-none', pdf);
  frame.classList.toggle('d-none', !pdf);
  if (pdf) { img.removeAttribute('src'); frame.src = url; }
  else     { frame.removeAttribute('src'); img.src = url; }
  document.getElementById('imagePreviewOpenNew').href = url;
  new bootstrap.Modal(document.getElementById('imagePreviewModal')).show();
}

// The "View" buttons above live inside the profile modal, so this preview
// opens as a second, stacked modal. Bootstrap 5 doesn't stack modals by
// default (the new backdrop covers the first modal and closing this one
// drops the "modal-open" body class even though the profile modal is still
// showing) — these two listeners fix both issues.
(function () {
  const previewEl = document.getElementById('imagePreviewModal');
  if (!previewEl) return;
  previewEl.addEventListener('shown.bs.modal', function () {
    const backdrops = document.querySelectorAll('.modal-backdrop');
    if (backdrops.length > 1) {
      backdrops[backdrops.length - 1].style.zIndex = 1070;
      previewEl.style.zIndex = 1080;
    }
  });
  previewEl.addEventListener('hidden.bs.modal', function () {
    if (document.querySelectorAll('.modal.show').length) {
      document.body.classList.add('modal-open');
    }
  });
})();

// Same stacking fix as imagePreviewModal above — verifyProofModal,
// adjustAmountModal and resubmitFeedbackModal are all opened from buttons
// inside the profile modal, so without this they render behind the profile
// modal's backdrop: visually just a darkened screen with nothing clickable
// on top.
(function () {
  ['verifyProofModal', 'adjustAmountModal', 'resubmitFeedbackModal'].forEach(function (id) {
    const el = document.getElementById(id);
    if (!el) return;
    el.addEventListener('shown.bs.modal', function () {
      const backdrops = document.querySelectorAll('.modal-backdrop');
      if (backdrops.length > 1) {
        backdrops[backdrops.length - 1].style.zIndex = 1070;
        el.style.zIndex = 1080;
      }
    });
    el.addEventListener('hidden.bs.modal', function () {
      if (document.querySelectorAll('.modal.show').length) {
        document.body.classList.add('modal-open');
      }
    });
  });
})();

const NOTIF_METHOD_LABELS = { gcash: 'GCash', maya: 'Maya', bank_transfer: 'Bank Transfer', cash: 'Cash', card: 'Card', online_banking: 'Online Banking' };

function openNotification(id) {
  const n = _notifCache[id];

  // Mark read in the background regardless — dropdown badge/list stay accurate.
  apiFetch(`/admin/notifications/${id}/read`, 'POST').then(loadNotifications).catch(() => {});

  if (!n) return; // fell out of the cached top-20; nothing more to show

  if (n.data.type === 'payment_proof_submitted') {
    showPaymentNotifModal(n.data);
  } else if (n.data.enrollment_id) {
    // New enrollments and online payments open the student's full profile
    // (online payments are already verified — nothing to approve there).
    const from = n.data.from === 'applications' ? 'applications' : 'students';
    window.location = `?modal=profile&app_id=${encodeURIComponent(n.data.enrollment_id)}&from=${from}`;
  }
}

let _notifModalPaymentId = null;
let _notifModalAmountDue = 0;

function showPaymentNotifModal(data) {
  _notifModalPaymentId = data.payment_id;
  _notifModalAmountDue = Number(data.amount_due || 0);

  document.getElementById('notifModalStudent').textContent = data.student_name || '—';
  document.getElementById('notifModalParent').textContent = data.parent_name || '—';
  document.getElementById('notifModalLabel').textContent = data.label || '—';
  document.getElementById('notifModalAmount').textContent = '₱' + _notifModalAmountDue.toLocaleString('en-PH', { minimumFractionDigits: 2 });
  document.getElementById('notifModalMethod').textContent = NOTIF_METHOD_LABELS[data.payment_method] || data.payment_method || '—';
  document.getElementById('notifModalSubmitted').textContent = data.submitted_at || '—';

  const proofWrap = document.getElementById('notifModalProofWrap');
  const proofUrl = data.proof_of_payment ? encodeURI(String(data.proof_of_payment)) : '';
  if (proofUrl && isPdfUrl(proofUrl)) {
    proofWrap.innerHTML = `<button type="button" class="btn btn-outline-secondary btn-sm" onclick="showImagePreview('${escapeHtml(proofUrl)}', 'Proof of Payment')"><i class="bi bi-file-earmark-pdf me-1"></i>View PDF receipt</button>`;
  } else if (proofUrl) {
    proofWrap.innerHTML = `<a href="${escapeHtml(proofUrl)}" target="_blank" rel="noopener">
      <img src="${escapeHtml(proofUrl)}" alt="Proof of payment" style="max-width:100%;max-height:260px;border-radius:10px;border:1px solid #e2e8f0;object-fit:contain"
           onerror="this.closest('a').outerHTML='<div class=&quot;text-muted&quot; style=&quot;font-size:12.5px&quot;>The receipt image could not be loaded.</div>'">
    </a>`;
  } else {
    proofWrap.innerHTML = '<div class="text-muted" style="font-size:12.5px">No proof image attached.</div>';
  }

  // Close the notif dropdown so it doesn't sit behind the modal backdrop.
  document.getElementById('notifDropdown').classList.add('d-none');

  bsModal('notifDetailModal').show();
}

function verifyFromNotifModal() {
  if (!_notifModalPaymentId) return;
  bsModal('notifDetailModal').hide();
  const label = document.getElementById('notifModalLabel').textContent;
  openVerifyProofModal(_notifModalPaymentId, label, _notifModalAmountDue);
}

function rejectFromNotifModal() {
  if (!_notifModalPaymentId) return;
  const label = document.getElementById('notifModalLabel').textContent;
  bsModal('notifDetailModal').hide();
  openResubmitModal(_notifModalPaymentId, label, true);
}

function markAllNotifsRead(e) {
  e.stopPropagation();
  apiFetch('{{ route("admin.notifications.readAll") }}', 'POST')
    .then(() => loadNotifications())
    .catch(() => {});
}

// Initial load + light polling so the badge updates without a full refresh
document.addEventListener('DOMContentLoaded', function () {
  loadNotifications();
  setInterval(loadNotifications, 30000);
});

/* ── Generate sections for a grade level ── */
function generateSections(gradeLevel, btn) {
  if (btn) btn.disabled = true;
  apiFetch('{{ route("admin.sections.generate") }}', 'POST', { grade_level: gradeLevel })
    .then(data => {
      phlciToast(data.message || 'Sections generated.', 'success');
      setTimeout(() => { window.location = '{{ route("admin.dashboard") }}?tab=sections'; }, 700);
    })
    .catch(() => {
      phlciToast('Could not generate sections. Please try again.', 'error');
      if (btn) btn.disabled = false;
    });
}

/* ── Add Student (late enrollment) ── */
function submitAddStudent(btn) {
  const grade  = document.getElementById('addStudentGrade').value;
  const email  = document.getElementById('addStudentEmail').value.trim();
  const last   = document.getElementById('addStudentLast').value.trim();
  const first  = document.getElementById('addStudentFirst').value.trim();
  const middle = document.getElementById('addStudentMiddle').value.trim();
  const dob    = document.getElementById('addStudentDob').value;

  if (!grade || !email || !last || !first || !dob) {
    phlciToast('Please fill in all required fields.', 'error');
    return;
  }

  btn.disabled = true;
  apiFetch('{{ route("admin.students.store") }}', 'POST', {
    grade_level: grade, email, last_name: last, first_name: first, middle_name: middle, birthday: dob,
  })
    .then(data => {
      phlciToast(data.message || 'Student enrolled successfully!', 'success');
      setTimeout(() => { window.location = '{{ route("admin.dashboard") }}?tab=students'; }, 1200);
    })
    .catch(async err => {
      let message = 'Could not add student. Please try again.';
      try { message = JSON.parse(err.message).message || message; } catch (e) {}
      phlciToast(message, 'error');
      btn.disabled = false;
    });
}

/* ── Export students (PDF or CSV) ── */
function updateExportHint() {
  const fmt = document.getElementById('exportFormat').value;
  document.getElementById('exportHint').textContent = fmt === 'pdf'
    ? 'Downloads a printable PDF of every approved/enrolled student matching this filter.'
    : 'Downloads a CSV of every approved/enrolled student matching this filter.';
}

function startExport(link) {
  const grade  = document.getElementById('exportGrade').value;
  const format = document.getElementById('exportFormat').value;

  const params = new URLSearchParams();
  if (grade) params.set('grade_level', grade);
  params.set('format', format);

  window.location = '{{ route("admin.students.export") }}?' + params.toString();
  phlciToast('Export started — your download will begin shortly.', 'success');
  setTimeout(() => { window.location = '{{ route("admin.dashboard") }}'; }, 1200);
}

/* ── Transfer Section ── */
function submitTransfer(btn, enrollmentId) {
  const grade   = document.getElementById('transferGrade').value;
  const section = document.getElementById('transferSection').value.trim();
  const reason  = document.getElementById('transferReason').value.trim();

  btn.disabled = true;
  apiFetch(`/admin/students/${enrollmentId}/transfer`, 'PATCH', {
    grade_level: grade, section_name: section, reason,
  })
    .then(data => {
      phlciToast(data.message || 'Transferred successfully!', 'success');
      setTimeout(() => { window.location = '{{ route("admin.dashboard") }}?tab=students'; }, 1200);
    })
    .catch(async err => {
      let message = 'Could not transfer student. Please try again.';
      try { message = JSON.parse(err.message).message || message; } catch (e) {}
      phlciToast(message, 'error');
      btn.disabled = false;
    });
}

/* ── Flag requirement document (or tuition payment) for resubmit ── */
let _resubmitDocId = null;
let _resubmitIsTuition = false;
function openResubmitModal(docId, docLabel, isTuition) {
  _resubmitDocId = docId;
  _resubmitIsTuition = !!isTuition;
  document.getElementById('resubmitDocLabel').textContent = docLabel;
  document.getElementById('resubmitFeedbackText').value = '';
  document.getElementById('resubmitFeedbackError').classList.add('d-none');
  bsModal('resubmitFeedbackModal').show();
}
function submitResubmitFlag() {
  const feedback = document.getElementById('resubmitFeedbackText').value.trim();
  const errorEl  = document.getElementById('resubmitFeedbackError');
  if (!feedback) {
    errorEl.textContent = 'Please describe what needs to be fixed.';
    errorEl.classList.remove('d-none');
    return;
  }
  const btn = document.getElementById('resubmitFeedbackSubmitBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Sending…';

  const url = _resubmitIsTuition
    ? `/admin/tuition/proofs/${_resubmitDocId}/reject`
    : `/admin/requirements/${_resubmitDocId}/flag-resubmit`;
  const method = _resubmitIsTuition ? 'POST' : 'PATCH';

  apiFetch(url, method, { feedback })
    .then(() => {
      bsModal('resubmitFeedbackModal').hide();
      phlciToast('Parent has been notified to resubmit.', 'success');
      setTimeout(() => location.reload(), 800);
    })
    .catch(() => {
      errorEl.textContent = 'Could not send this request. Please try again.';
      errorEl.classList.remove('d-none');
    })
    .finally(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-send me-1"></i>Notify Parent';
    });
}

function verifyRequirementDoc(requirementId) {
  apiFetch(`/admin/requirements/${requirementId}/verify`, 'PATCH')
    .then(() => {
      phlciToast('Document verified.', 'success');
      setTimeout(() => location.reload(), 600);
    })
    .catch(() => phlciToast('Could not verify this document. Please try again.', 'error'));
}

function approveApplication(enrollmentId, name) {
  apiFetch(`/admin/applications/${enrollmentId}/approve`, 'PATCH')
    .then(data => {
      phlciToast(data.message || `${name} has been approved.`, 'success');
      setTimeout(() => location.reload(), 700);
    })
    .catch(err => {
      let message = 'Could not approve this application. Please try again.';
      try { message = JSON.parse(err.message).message || message; } catch (e) {}
      phlciToast(message, 'error');
    });
}

/* ── Bulk approve (Applications tab) ─────────────────────────────────────── */
function toggleSelectAllApplications(checkbox) {
  document.querySelectorAll('#appTable .app-row-check').forEach(c => { c.checked = checkbox.checked; });
  updateBulkApproveButton();
}
function updateBulkApproveButton() {
  const checked = document.querySelectorAll('#appTable .app-row-check:checked').length;
  const btn = document.getElementById('bulkApproveBtn');
  const countEl = document.getElementById('bulkApproveCount');
  if (!btn || !countEl) return;
  countEl.textContent = checked;
  btn.classList.toggle('d-none', checked === 0);
  btn.disabled = checked === 0;

  const total = document.querySelectorAll('#appTable .app-row-check').length;
  const selectAll = document.getElementById('appSelectAll');
  if (selectAll) selectAll.checked = total > 0 && checked === total;
}
function bulkApproveSelected() {
  const ids = Array.from(document.querySelectorAll('#appTable .app-row-check:checked')).map(c => parseInt(c.value, 10));
  if (ids.length === 0) return;
  if (!confirm(`Approve ${ids.length} selected application(s)?`)) return;

  const btn = document.getElementById('bulkApproveBtn');
  if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Approving…'; }

  apiFetch('/admin/applications/bulk-approve', 'POST', { ids })
    .then(data => {
      phlciToast(data.message || `${data.approved.length} application(s) approved.`, 'success');
      if (data.skipped && data.skipped.length) {
        console.warn('Skipped:', data.skipped);
      }
      setTimeout(() => location.reload(), 900);
    })
    .catch(() => {
      phlciToast('Could not approve the selected applications. Please try again.', 'error');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check2-all me-1"></i>Approve Selected (' + ids.length + ')'; }
    });
}

let _verifyProofId = null;

function openVerifyProofModal(proofId, label, claimedAmount) {
  _verifyProofId = proofId;
  document.getElementById('verifyProofLabel').textContent = label;
  document.getElementById('verifyProofAmount').value = Number(claimedAmount).toFixed(2);
  document.getElementById('verifyProofError').classList.add('d-none');
  bsModal('verifyProofModal').show();
}

function confirmVerifyProof() {
  const errorEl = document.getElementById('verifyProofError');
  const amount = parseFloat(document.getElementById('verifyProofAmount').value);

  if (!amount || amount <= 0) {
    errorEl.textContent = 'Please enter a valid amount.';
    errorEl.classList.remove('d-none');
    return;
  }

  const btn = document.getElementById('verifyProofSubmitBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Verifying…';

  apiFetch(`/admin/tuition/proofs/${_verifyProofId}/verify`, 'POST', { amount })
    .then(() => {
      bsModal('verifyProofModal').hide();
      phlciToast('Payment marked as paid.', 'success');
      setTimeout(() => location.reload(), 600);
    })
    .catch(() => {
      errorEl.textContent = 'Could not verify this payment. Please check the amount and try again.';
      errorEl.classList.remove('d-none');
    })
    .finally(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Verify';
    });
}

let _adjustAmountPaymentId = null;

function openAdjustAmountModal(paymentId, label, currentAmount) {
  _adjustAmountPaymentId = paymentId;
  document.getElementById('adjustAmountLabel').textContent = label;
  document.getElementById('adjustAmountValue').value = Number(currentAmount).toFixed(2);
  document.getElementById('adjustAmountReason').value = '';
  document.getElementById('adjustAmountError').classList.add('d-none');
  bsModal('adjustAmountModal').show();
}

function confirmAdjustAmount() {
  const errorEl = document.getElementById('adjustAmountError');
  const amount_due = parseFloat(document.getElementById('adjustAmountValue').value);
  const reason = document.getElementById('adjustAmountReason').value.trim();

  if (isNaN(amount_due) || amount_due < 0) {
    errorEl.textContent = 'Please enter a valid amount.';
    errorEl.classList.remove('d-none');
    return;
  }

  const btn = document.getElementById('adjustAmountSubmitBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Saving…';

  apiFetch(`/admin/tuition/payments/${_adjustAmountPaymentId}/adjust-amount`, 'PATCH', { amount_due, reason })
    .then(() => {
      bsModal('adjustAmountModal').hide();
      phlciToast('Installment amount updated.', 'success');
      setTimeout(() => location.reload(), 600);
    })
    .catch(() => {
      errorEl.textContent = 'Could not update this amount. Please try again.';
      errorEl.classList.remove('d-none');
    })
    .finally(() => {
      btn.disabled = false;
      btn.innerHTML = '<i class="bi bi-check-lg me-1"></i>Save';
    });
}

/* ── Profile: modern photo upload (drag-drop + live preview) ── */
function openPhotoModal() { resetPhotoModal(); bsModal('managePhotoModal').show(); }

function resetPhotoModal() {
  const fileInput = document.getElementById('pp-file');
  fileInput.value = '';
  document.getElementById('ppFileMeta').classList.add('d-none');
  document.getElementById('ppErrorMsg').classList.add('d-none');
  document.getElementById('ppSubmitBtn').disabled = true;
  document.getElementById('ppDropzoneTitle').textContent = 'Drag & drop a photo here';
  // restore preview to current saved photo (or fallback)
  const img = document.getElementById('ppPreviewImg');
  @if($user->profile_photo ?? false)
    img.src = '{{ asset("storage/".$user->profile_photo) }}';
    img.style.display = '';
  @else
    img.style.display = 'none';
    const fb = document.getElementById('ppPreviewFallback');
    if (fb) fb.style.display = 'flex';
  @endif
}

function showPhotoError(msg) {
  const el = document.getElementById('ppErrorMsg');
  el.classList.remove('d-none');
  el.querySelector('span').textContent = msg;
}

function handlePhotoSelect(file) {
  document.getElementById('ppErrorMsg').classList.add('d-none');
  if (!file) return;
  if (!['image/jpeg','image/png'].includes(file.type)) { showPhotoError('Please choose a JPG or PNG image.'); return; }
  if (file.size > 2 * 1024 * 1024) { showPhotoError('Image must be 2MB or smaller.'); return; }

  const reader = new FileReader();
  reader.onload = (e) => {
    const img = document.getElementById('ppPreviewImg');
    img.src = e.target.result;
    img.style.display = '';
    const fb = document.getElementById('ppPreviewFallback');
    if (fb) fb.style.display = 'none';
  };
  reader.readAsDataURL(file);

  document.getElementById('ppFileName').textContent = file.name;
  document.getElementById('ppFileMeta').classList.remove('d-none');
  document.getElementById('ppDropzoneTitle').textContent = 'Looking good! Click to choose a different photo';
  document.getElementById('ppSubmitBtn').disabled = false;
}

function clearPhotoSelect() {
  document.getElementById('pp-file').value = '';
  resetPhotoModal();
}

(function setupPhotoDropzone() {
  document.addEventListener('DOMContentLoaded', () => {
    const zone = document.getElementById('ppDropzone');
    if (!zone) return;
    ['dragenter','dragover'].forEach(evt => zone.addEventListener(evt, (e) => { e.preventDefault(); zone.classList.add('pp-dragover'); }));
    ['dragleave','drop'].forEach(evt => zone.addEventListener(evt, (e) => { e.preventDefault(); zone.classList.remove('pp-dragover'); }));
    zone.addEventListener('drop', (e) => {
      const file = e.dataTransfer.files[0];
      if (!file) return;
      document.getElementById('pp-file').files = e.dataTransfer.files;
      handlePhotoSelect(file);
    });
  });
})();

function submitProfilePhoto(e) {
  e.preventDefault();
  const fileInput = document.getElementById('pp-file');
  if (!fileInput.files.length) return;
  const fd = new FormData();
  fd.append('profile_photo', fileInput.files[0]);
  fd.append('_method', 'POST');
  apiFetch('{{ route("admin.profile.photo") }}', 'POST', fd, true).then(() => {
    bsModal('managePhotoModal').hide();
    phlciToast('Profile photo updated!', 'success');
    setTimeout(() => location.reload(), 600);
  }).catch(() => showPhotoError('Could not upload the photo. Please try again.'));
}
function removeProfilePhoto() {
  if (!confirm('Remove your profile photo?')) return;
  apiFetch('{{ route("admin.profile.photo.remove") }}', 'DELETE').then(() => {
    bsModal('managePhotoModal').hide();
    phlciToast('Profile photo removed.', 'success');
    setTimeout(() => location.reload(), 600);
  }).catch(() => alert('Could not remove the photo.'));
}

/* ── Profile: change password ── */
function submitChangePassword(e) {
  e.preventDefault();
  const current = document.getElementById('cp-current').value;
  const next    = document.getElementById('cp-new').value;
  const confirm_ = document.getElementById('cp-confirm').value;
  if (next.length < 8 || !/[A-Z]/.test(next) || !/[0-9]/.test(next) || !/[^A-Za-z0-9]/.test(next)) {
    alert('New password must be at least 8 characters and include an uppercase letter, a number, and a special character.');
    return;
  }
  if (next !== confirm_) { alert('New password and confirmation do not match.'); return; }
  apiFetch('{{ route("admin.profile.password") }}', 'PUT', { current_password: current, password: next, password_confirmation: confirm_ })
    .then(() => { phlciToast('Password updated successfully!', 'success'); document.getElementById('changePasswordForm').reset(); setTimeout(() => location.reload(), 600); })
    .catch(() => alert('Could not update your password. Please check your current password and try again.'));
}

/* ── Tab switching ── */
function switchAdminTab(tab, el) {
  sessionStorage.setItem('adminTab', tab);
  document.querySelectorAll('.sb-nav-item').forEach(t => t.classList.remove('active'));
  if (el) {
    el.classList.add('active');
  } else {
    // find and activate the matching nav item
    document.querySelectorAll('.sb-nav-item[data-tab]').forEach(function(item) {
      if (item.dataset.tab === tab) item.classList.add('active');
    });
  }
  ['statistics','applications','students','sections','profile'].forEach(function(t) {
    document.getElementById('admin-tab-'+t).classList.toggle('d-none', t !== tab);
  });
  const titles = { applications:'Applications', students:'Students', sections:'Sections', statistics:'Statistics & Analytics', profile:'My Profile' };
  document.getElementById('pageTitle').textContent = titles[tab] || tab;
  if (window.innerWidth < 992) closeSidebar();
  if (tab === 'statistics') initAdminCharts();
}

/* Restore tab on page load handled inside DOMContentLoaded below */

/* ── Action dropdown ── */
let _openMenuWrap = null;
function toggleActionMenu(e, btn) {
  e.stopPropagation();
  const wrap = btn.closest('.action-menu-wrap');
  if (_openMenuWrap && _openMenuWrap !== wrap) _openMenuWrap.classList.remove('open');
  wrap.classList.toggle('open');
  _openMenuWrap = wrap.classList.contains('open') ? wrap : null;
  if (_openMenuWrap) positionActionMenu(btn, wrap.querySelector('.action-dropdown'));
}
// The dropdown is position: fixed (see layout CSS) so the table's overflow
// can't clip it — place it under the button, right-aligned, or above the
// button when there isn't room below.
function positionActionMenu(btn, dropdown) {
  const r = btn.getBoundingClientRect();
  const below = r.bottom + 4;
  const fitsBelow = below + dropdown.offsetHeight <= window.innerHeight - 8;
  dropdown.style.top  = (fitsBelow ? below : Math.max(8, r.top - 4 - dropdown.offsetHeight)) + 'px';
  dropdown.style.left = Math.max(8, r.right - dropdown.offsetWidth) + 'px';
}
function closeActionMenu() { if (_openMenuWrap) { _openMenuWrap.classList.remove('open'); _openMenuWrap = null; } }
document.addEventListener('click', closeActionMenu);
// A fixed menu wouldn't follow its row when the page or table scrolls.
window.addEventListener('scroll', closeActionMenu, true);
window.addEventListener('resize', closeActionMenu);
function closeMenuThen(fn) { if (_openMenuWrap) { _openMenuWrap.classList.remove('open'); _openMenuWrap = null; } fn(); }

/* ── Table search ── */
function filterTable(tid, q) {
  document.querySelectorAll('#'+tid+' tbody tr').forEach(r => {
    r.style.display = r.textContent.toLowerCase().includes(q.toLowerCase()) ? '' : 'none';
  });
}

/* ── Grade accordion ── */
function toggleGrade(id) { document.getElementById(id).classList.toggle('open'); }

/* ── Toast helper ── */
function showToast(msg) {
  const t = document.createElement('div');
  t.className = 'phlci-toast';
  t.innerHTML = `<i class="bi bi-check-circle-fill" style="color:#4ade80;font-size:18px"></i>${msg}`;
  document.body.appendChild(t);
  setTimeout(() => t.remove(), 3500);
}

/* ── Auto section (section tab, grade picker) ── */
const enrolledCounts = { g7:64, g8:58, g9:82, g10:67 };
const LETTERS = ['A','B','C','D','E','F'];
const CAP = 45;
const gradeColors = {
  g7:  { fill:'fill-teal',  bg:'var(--g7-light)', color:'var(--g7-color)' },
  g8:  { fill:'fill-amber', bg:'var(--g8-light)', color:'var(--g8-color)' },
  g9:  { fill:'fill-rose',  bg:'var(--g9-light)', color:'var(--g9-color)' },
  g10: { fill:'fill-navy',  bg:'var(--g10-light)',color:'var(--g10-color)'},
};

function triggerAutoSection(gradeId, gradeLabel) {
  const total = enrolledCounts[gradeId];
  const n = Math.ceil(total / CAP);
  const base = Math.floor(total / n);
  const rem  = total % n;
  const plan = Array.from({length:n},(_,i)=>({ name:'Section '+LETTERS[i], students: base+(i<rem?1:0) }));
  const c = gradeColors[gradeId];
  const wrap = document.getElementById(gradeId+'-sections');
  wrap.innerHTML = '';
  plan.forEach(sec => {
    const pct = Math.round((sec.students/CAP)*100);
    const row = document.createElement('div');
    row.className = 'section-row';
    row.style.cursor = 'pointer';
    row.title = 'Click to view Master List';
    row.innerHTML = `
      <div class="section-top">
        <div class="section-icon" style="background:${c.bg};color:${c.color}"><i class="bi bi-book-fill"></i></div>
        <div><div class="section-title">${sec.name}</div></div>
        <div style="margin-left:auto;display:flex;align-items:center;gap:8px">
          <span style="font-size:11px;color:${c.color};background:${c.bg};padding:2px 8px;border-radius:20px"><i class="bi bi-list-ul me-1"></i>Master List</span>
          <span style="font-size:12px;font-weight:600;color:${c.color};background:${c.bg};padding:2px 10px;border-radius:20px">${sec.students}/${CAP}</span>
        </div>
      </div>
      <div class="cap-row"><span class="cap-label">Capacity</span><span class="cap-value">${sec.students} / ${CAP} students</span></div>
      <div class="cap-bar"><div class="fill ${c.fill}" style="width:${pct}%"></div></div>`;
    row.addEventListener('click', function() {
      openMasterList(gradeLabel, sec.name, sec.students, gradeId);
    });
    wrap.appendChild(row);
  });
  document.getElementById(gradeId+'-meta').textContent = `${n} Sections • ${total} / ${n*CAP} Students`;
  document.querySelector(`#${gradeId} .grade-pill-bar .fill`).style.width = Math.round((total/(n*CAP))*100)+'%';
  document.getElementById(gradeId).classList.add('open');
  // Close the create-section modal
  const phpM = document.getElementById('phpModal');
  const bsM = phpM ? bootstrap.Modal.getInstance(phpM) : null;
  if (bsM) bsM.hide();
  setTimeout(() => {
    showToast(`${n} section(s) created for ${gradeLabel}! Click a section to view Master List.`);
  }, 300);
}

/* ── Charts ── */
let _adminChartsInit = false;
function initAdminCharts() {
  if (_adminChartsInit) return;
  _adminChartsInit = true;
  new Chart(document.getElementById('barChart'), {
    type:'bar',
    data:{ labels:_chartData.gradeLabels, datasets:[{ label:'Students', data:_chartData.gradeData, backgroundColor:'#3b82f6', borderRadius:4, borderSkipped:false }] },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{ y:{beginAtZero:true,ticks:{color:'#94a3b8',font:{size:11}},grid:{color:'rgba(148,163,184,0.15)'}}, x:{ticks:{color:'#64748b',font:{size:11}},grid:{display:false}} } }
  });
  new Chart(document.getElementById('pieChart'), {
    type:'doughnut',
    data:{ labels:['Male','Female'], datasets:[{data:[_chartData.male,_chartData.female],backgroundColor:['#1e3a8a','#0d9488'],borderWidth:2,borderColor:'#ffffff'}] },
    options:{ responsive:true, maintainAspectRatio:false, cutout:'60%', plugins:{legend:{display:false}} }
  });
  new Chart(document.getElementById('appStatusChart'), {
    type:'doughnut',
    data:{ labels:['Approved','Pending'], datasets:[{data:[_chartData.statusApproved,_chartData.statusPending],backgroundColor:['#22c55e','#f59e0b'],borderWidth:2,borderColor:'#fff'}] },
    options:{ responsive:true, maintainAspectRatio:false, cutout:'55%', plugins:{legend:{position:'bottom',labels:{font:{size:12},padding:14}}} }
  });
  new Chart(document.getElementById('trendChart'), {
    type:'line',
    data:{ labels:_chartData.trendLabels, datasets:[{ label:'Enrolled', data:_chartData.trendData, borderColor:'#7c3aed', backgroundColor:'rgba(124,58,237,.1)', fill:true, tension:.35, pointBackgroundColor:'#7c3aed', pointRadius:5 }] },
    options:{ responsive:true, maintainAspectRatio:false, plugins:{legend:{display:false}}, scales:{ y:{beginAtZero:true,ticks:{color:'#94a3b8',font:{size:11}},grid:{color:'rgba(148,163,184,.15)'}}, x:{ticks:{color:'#64748b',font:{size:11}},grid:{display:false}} } }
  });
}

/* ── Master List (real data) ── */
function openMasterList(sectionId, gradeLabel, sectionName) {
  fetch(`/admin/sections/${sectionId}/master-list`)
    .then(res => res.json())
    .then(data => renderMasterList(data.students, gradeLabel, sectionName))
    .catch(() => showToast('Could not load master list. Please try again.'));
}

function renderMasterList(students, gradeLabel, sectionName) {
  const gradeColorMap = {
    'Kinder':   {bg:'#ccfbf1',color:'#0f766e',gradient:'#0d9488,#065f46'},
    'Grade 1':  {bg:'#fef3c7',color:'#b45309',gradient:'#d97706,#92400e'},
    'Grade 2':  {bg:'#fce7f3',color:'#be185d',gradient:'#db2777,#9d174d'},
    'Grade 3':  {bg:'#eff6ff',color:'#1e40af',gradient:'#1e3a8a,#1e40af'},
    'Grade 4':  {bg:'#ccfbf1',color:'#0f766e',gradient:'#0d9488,#065f46'},
    'Grade 5':  {bg:'#fef3c7',color:'#b45309',gradient:'#d97706,#92400e'},
    'Grade 6':  {bg:'#fce7f3',color:'#be185d',gradient:'#db2777,#9d174d'},
    'Grade 7':  {bg:'#ccfbf1',color:'#0f766e',gradient:'#0d9488,#065f46'},
    'Grade 8':  {bg:'#fef3c7',color:'#b45309',gradient:'#d97706,#92400e'},
    'Grade 9':  {bg:'#fce7f3',color:'#be185d',gradient:'#db2777,#9d174d'},
    'Grade 10': {bg:'#eff6ff',color:'#1e40af',gradient:'#1e3a8a,#1e40af'},
  };
  const gc = gradeColorMap[gradeLabel] || {bg:'#f1f5f9',color:'#475569',gradient:'#475569,#1e293b'};

  const rows = students.map(s =>
    `<tr>
      <td style="text-align:center;color:#64748b">${s.no}</td>
      <td style="font-family:monospace;font-size:11.5px;color:#64748b">${s.lrn ?? '—'}</td>
      <td style="font-weight:600;color:#1e293b">${s.name}</td>
      <td style="text-align:center">${s.sex}</td>
      <td style="font-size:12px;color:#475569">${s.dob ?? '—'}</td>
      <td style="text-align:center">${s.age ?? '—'}</td>
      <td style="font-size:12px;color:#475569">${s.address ?? '—'}</td>
    </tr>`
  ).join('');

  document.getElementById('masterListContent').innerHTML = `
    <div id="masterListPrintArea">
      <div class="print-only" style="text-align:center;margin-bottom:18px">
        <div style="font-size:13px;font-weight:700;text-transform:uppercase">Premiere Heights Learning Center, Inc.</div>
        <div style="font-size:11px;color:#475569">Carmona, Cavite</div>
        <div style="font-size:14px;font-weight:800;margin-top:8px;text-transform:uppercase;letter-spacing:.05em">Class Master List</div>
        <div style="font-size:12px">${gradeLabel} – ${sectionName} &nbsp;|&nbsp; {{ $currentSchoolYear }}</div>
        <div style="border-bottom:2px solid #1e293b;margin:10px 0"></div>
      </div>
      <div class="no-print d-flex align-items-center gap-3 mb-3 p-3 rounded-3" style="background:linear-gradient(135deg,${gc.gradient});color:#fff">
        <div style="width:48px;height:48px;border-radius:12px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:22px"><i class="bi bi-list-columns-reverse"></i></div>
        <div>
          <div style="font-size:17px;font-weight:800">${gradeLabel} – ${sectionName}</div>
          <div style="font-size:12px;opacity:.8">{{ $currentSchoolYear }} &nbsp;|&nbsp; ${students.length} students enrolled</div>
        </div>
      </div>
      <div class="table-responsive">
        <table style="width:100%;border-collapse:collapse;font-size:13px" class="master-table">
          <thead>
            <tr style="background:#1e3a8a;color:#fff">
              <th style="padding:9px 10px;text-align:center;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;width:42px">#</th>
              <th style="padding:9px 10px;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap">LRN</th>
              <th style="padding:9px 10px;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em">Student Name</th>
              <th style="padding:9px 10px;text-align:center;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;width:50px">Sex</th>
              <th style="padding:9px 10px;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;white-space:nowrap">Date of Birth</th>
              <th style="padding:9px 10px;text-align:center;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em;width:50px">Age</th>
              <th style="padding:9px 10px;font-size:11.5px;text-transform:uppercase;letter-spacing:.05em">Address</th>
            </tr>
          </thead>
          <tbody>${rows || '<tr><td colspan="7" class="text-center text-muted py-3">No students in this section yet.</td></tr>'}</tbody>
        </table>
      </div>
      <div class="print-only" style="margin-top:32px;display:flex;justify-content:space-between;font-size:11px">
        <div>Prepared by: _______________________<br>Class Adviser<br>Date: _______________</div>
        <div style="text-align:right">Noted by: _______________________<br>School Principal<br>Date: _______________</div>
      </div>
    </div>`;

  if (!document.getElementById('masterPrintStyle')) {
    const s = document.createElement('style');
    s.id = 'masterPrintStyle';
    s.textContent = `
      @media print {
        body > *:not(#masterListModal) { display:none !important; }
        #masterListModal { position:static !important; display:block !important; }
        .modal-dialog { max-width:100% !important; margin:0 !important; }
        .modal-content { box-shadow:none !important; border:none !important; }
        .no-print, .modal-header, .modal-footer { display:none !important; }
        .print-only { display:block !important; }
        .master-table tbody tr:nth-child(even) { background:#f8fafc; }
        .master-table td, .master-table th { border:1px solid #e2e8f0; }
      }
      .print-only { display:none; }
      .master-table tbody tr:nth-child(even) { background:#f8fafc; }
      .master-table td { padding:8px 10px; border-bottom:1px solid #f1f5f9; }
    `;
    document.head.appendChild(s);
  }

  new bootstrap.Modal(document.getElementById('masterListModal')).show();
}

function generateSampleStudents(count, gradeLabel, sectionName) {
  const lastNames = ['Reyes','Santos','Cruz','Bautista','Garcia','Torres','Flores','Ramos','Lopez','Hernandez','Dela Cruz','Mendoza','Villanueva','Castillo','Aquino'];
  const firstNames = ['Juan','Maria','Jose','Ana','Carlos','Rosa','Miguel','Liza','Pedro','Elena','Rico','Clara','Diego','Sophia','Marco'];
  const arr = [];
  for (let i=0;i<count;i++) {
    const ln = lastNames[i % lastNames.length];
    const fn = firstNames[(i+3) % firstNames.length];
    const mi = String.fromCharCode(65+(i%26));
    const yr = 2013 - Math.floor(i/15);
    const mo = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'][i%12];
    const day = (i%28)+1;
    arr.push({no:i+1, lrn:'20260'+gradeLabel.slice(-1)+'0'+String(i+1).padStart(5,'0'), name:`${ln}, ${fn} ${mi}.`, sex:i %2===0?'M':'F', dob:`${mo} ${day}, ${yr}`, age:2026-yr, address:`Brgy. ${lastNames[(i+5)%lastNames.length]}, Minalabac`});
  }
  return arr;
}

document.addEventListener('DOMContentLoaded', () => {
  const urlParams = new URLSearchParams(window.location.search);
  const modalParam = urlParams.get('modal');
  if (modalParam === 'addStudent' || modalParam === 'export' || modalParam === 'transfer') {
    sessionStorage.setItem('adminTab', 'students');
  } else if (modalParam === 'profile') {
    // Stay on applications if opened from an application, students if opened from a student record.
    // Both links pass the row's id as app_id, so we can't tell them apart by that param alone —
    // the explicit "from" param (set by the link that was actually clicked) is the source of truth.
    const profileFrom = urlParams.get('from');
    if (profileFrom === 'students') {
      sessionStorage.setItem('adminTab', 'students');
    } else if (profileFrom === 'applications') {
      sessionStorage.setItem('adminTab', 'applications');
    } else if (urlParams.get('app_id')) {
      // Fallback for older links without "from" (e.g. the notification redirect below)
      sessionStorage.setItem('adminTab', 'applications');
    } else {
      sessionStorage.setItem('adminTab', 'students');
    }
  } else if (modalParam === 'createSection') {
    sessionStorage.setItem('adminTab', 'sections');
  }
  if (urlParams.get('app_page')) sessionStorage.setItem('adminTab', 'applications');
  if (urlParams.get('stu_page')) sessionStorage.setItem('adminTab', 'students');
  if (urlParams.get('tab')) sessionStorage.setItem('adminTab', urlParams.get('tab'));

  // Restore saved tab
  var savedTab = sessionStorage.getItem('adminTab') || 'statistics';
  switchAdminTab(savedTab, null);

  // Auto-open modal if ?modal= is set
  const m = document.getElementById('phpModal');
  if (m) { new bootstrap.Modal(m).show(); }
});
</script>

<!-- ===================== MODAL: MASTER LIST ===================== -->
<div class="modal fade" id="masterListModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable modal-dialog-centered">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px;overflow:hidden">
      <div class="modal-header border-0 px-4 pt-4 pb-2 no-print">
        <div class="d-flex align-items-center gap-3">
          <div style="width:40px;height:40px;border-radius:10px;background:#1e3a8a;display:flex;align-items:center;justify-content:center;font-size:18px;color:#fff">
            <i class="bi bi-list-columns-reverse"></i>
          </div>
          <div>
            <h5 class="modal-title fw-bold mb-0" style="color:#1e293b">Class Master List</h5>
            <div class="text-muted" style="font-size:12px">{{ $currentSchoolYear }} &nbsp;|&nbsp; PHLCI</div>
          </div>
        </div>
        <div class="d-flex gap-2 align-items-center ms-auto">
          <button class="btn btn-sm fw-semibold px-3" style="background:#1e3a8a;color:#fff" onclick="window.print()">
            <i class="bi bi-printer-fill me-1"></i>Print Master List
          </button>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
      </div>
      <div class="modal-body px-4 pb-4" id="masterListContent">
        <!-- Populated by JS -->
      </div>
    </div>
  </div>
</div>

@endsection