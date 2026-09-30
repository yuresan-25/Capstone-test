@extends('layout.app')

@section('content')

<?php
use App\Models\User;
use App\Models\StudentEnrollment;
use App\Models\ActivityLog;
use App\Models\Announcement;
use App\Models\EnrollmentPeriod;
use App\Models\GradeEnrollmentSetting;
use App\Models\GradeTuitionFee;

/* ── Admin accounts (User model, role = admin) ──
   NOTE: assumes `users` table has: name, email, role, status ('active'/'inactive'),
   last_login_at, and a nullable JSON assigned_grades array (null/empty = All Grades). */
$admins            = User::where('role', 'admin')->orderBy('first_name')->orderBy('last_name')->get();
$activeAdminsCount  = $admins->where('is_active', true)->count();

/* ── Enrollment stats ──
   Fetched once and reused below (line ~58) for the grade-level breakdown,
   instead of querying 'enrolled' students twice. */
$enrolledStudents    = StudentEnrollment::where('status', 'enrolled')->get();
$totalStudents       = $enrolledStudents->count();
$pendingApplications = StudentEnrollment::where('status', 'pending')->count();

/* ── System logs today (ActivityLog model) ── */
$systemLogsToday = ActivityLog::whereDate('created_at', today())->count();
$activityLogs    = ActivityLog::latest()->take(50)->get();
$logColors = ['success'=>'#166534','danger'=>'#991b1b','info'=>'#0369a1','purple'=>'#7c3aed','warning'=>'#92400e'];
$logBg     = ['success'=>'#dcfce7','danger'=>'#fee2e2','info'=>'#e0f2fe','purple'=>'#f5f3ff','warning'=>'#fef3c7'];

/* ── Enrollment period (single settings row, EnrollmentPeriod model) ── */
$enrollmentPeriod = EnrollmentPeriod::first();

/* ── Grade-level enrollment toggles — Kinder to Grade 10 ── */
$gradeLevels = ['Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6','Grade 7','Grade 8','Grade 9','Grade 10'];
$gradeSettingsRaw = GradeEnrollmentSetting::pluck('is_open', 'grade_level');
$grades = collect($gradeLevels)->mapWithKeys(fn($g) => [$g => (bool) ($gradeSettingsRaw[$g] ?? false)]);
/* ── Tuition rates per grade level ── */
$tuitionFeesRaw = GradeTuitionFee::pluck('annual_amount', 'grade_level');
$tuitionFees = collect($gradeLevels)->mapWithKeys(fn($g) => [$g => (float) ($tuitionFeesRaw[$g] ?? 0)]);

$gradeColors = [
    'Kinder'=>'#2d6a2d','Grade 1'=>'#d4a900','Grade 2'=>'#b91c1c','Grade 3'=>'#1a2a5e',
    'Grade 4'=>'#2d6a2d','Grade 5'=>'#d4a900','Grade 6'=>'#b91c1c',
    'Grade 7'=>'#1e40af','Grade 8'=>'#166534','Grade 9'=>'#9a3412','Grade 10'=>'#7e22ce',
];

/* ── Announcements ── */
$announcements = Announcement::orderByDesc('created_at')->get();
$announcementsJs = $announcements->map(fn($a) => [
    'id'      => $a->id,
    'title'   => $a->title,
    'message' => $a->message,
    'from'    => optional($a->show_from)->format('Y-m-d'),
    'until'   => optional($a->show_until)->format('Y-m-d'),
    'status'  => $a->status,
    'popup'   => (bool) $a->show_as_popup,
    'by'      => $a->created_by_name ?? (auth()->user()->name ?? 'Super Admin'),
    'date'    => $a->created_at->format('F j, Y'),
])->values();

/* ── Current-SY snapshot for Enrollment History (built from real student data —
   there's no per-school-year archive table yet, so this only ever shows "this SY") ── */
$historyGradeCounts = $enrolledStudents
    ->groupBy('grade_level')
    ->map(fn($g) => $g->count());
$historySnapshot = [
    'sy'                => 'SY ' . ($enrollmentPeriod->school_year ?? 'N/A'),
    'key'               => $enrollmentPeriod->school_year ?? 'current',
    'status'            => ($enrollmentPeriod->is_open ?? false) ? 'active' : 'archived',
    'period'            => $enrollmentPeriod
                              ? \Carbon\Carbon::parse($enrollmentPeriod->start_date)->format('F j, Y') . ' – ' . \Carbon\Carbon::parse($enrollmentPeriod->end_date)->format('F j, Y')
                              : '',
    'totalEnrolled'     => $totalStudents,
    'totalApplications' => StudentEnrollment::count(),
    'grades'            => $gradeLevels,
    'gradeCounts'       => $historyGradeCounts,
];
?>


<style>
/* Comfortable tap target for the row action-menu button (mobile-first) */
.action-dots-btn {
  min-width: 34px;
  min-height: 34px;
}
@media (max-width: 767px) {
  .action-dots-btn { min-width: 40px; min-height: 40px; padding: 8px 12px !important; }
}

/* ===== FULL SIDEBAR LAYOUT ===== */
body { margin:0; background:#f1f5f9; }
.page-wrapper { display:flex; min-height:100vh; }

/* ── LEFT SIDEBAR ── */
.left-sidebar {
  width: 240px; min-width: 240px;
  background: #1a1240;
  display: flex; flex-direction: column;
  position: fixed; top:0; left:0;
  height: 100vh; height: 100dvh; z-index:200;
  overflow-y: auto;
  transition: transform .28s cubic-bezier(.4,0,.2,1);
}
.left-sidebar::-webkit-scrollbar { width:4px; }
.left-sidebar::-webkit-scrollbar-thumb { background:rgba(255,255,255,.1); border-radius:4px; }

.sb-brand {
  display:flex; align-items:center; gap:12px;
  padding:20px 20px 16px;
  border-bottom:1px solid rgba(255,255,255,.08);
}
.sb-brand img { width:40px; height:40px; border-radius:10px; background:#fff; padding:3px; }
.sb-brand-name { font-size:15px; font-weight:800; color:#fff; letter-spacing:.01em; }
.sb-brand-sub  { font-size:10.5px; color:rgba(255,255,255,.4); }

.sb-user {
  display:flex; align-items:center; gap:10px;
  padding:14px 20px;
  border-bottom:1px solid rgba(255,255,255,.08);
}
.sb-avatar {
  width:36px; height:36px; border-radius:50%;
  background:#7c3aed;
  display:flex; align-items:center; justify-content:center;
  font-size:13px; font-weight:800; color:#fff; flex-shrink:0;
  border:2px solid rgba(255,255,255,.2);
  overflow:hidden;
}
.sb-avatar img { width:100%; height:100%; object-fit:cover; border-radius:50%; }
.sb-user-name { font-size:13px; font-weight:700; color:#fff; line-height:1.2; }
.sb-user-role { font-size:10.5px; color:rgba(255,255,255,.4); }

.sb-section-label {
  font-size:10px; font-weight:700;
  text-transform:uppercase; letter-spacing:.1em;
  color:rgba(255,255,255,.3);
  padding:18px 20px 6px;
}

.sb-nav-item {
  display:flex; align-items:center; gap:12px;
  padding:11px 20px;
  color:rgba(255,255,255,.6);
  font-size:13.5px; font-weight:500;
  cursor:pointer;
  transition:background .15s, color .15s;
  border-left:3px solid transparent;
  user-select:none; text-decoration:none;
}
.sb-nav-item i { font-size:16px; width:20px; text-align:center; flex-shrink:0; }
.sb-nav-item:hover { background:rgba(255,255,255,.07); color:#fff; text-decoration:none; }
.sb-nav-item.active {
  background:rgba(124,58,237,.25);
  color:#fff; font-weight:700;
  border-left-color:#a78bfa;
}
.sb-nav-item.active i { color:#a78bfa; }

.sb-bottom {
  margin-top:auto; padding:12px 12px 16px;
  padding-bottom: calc(64px + env(safe-area-inset-bottom));
  border-top:1px solid rgba(255,255,255,.08);
}
.sb-logout {
  display:flex; align-items:center; gap:10px;
  padding:10px 14px; border-radius:10px;
  color:rgba(255,255,255,.6); font-size:13.5px; font-weight:500;
  cursor:pointer; text-decoration:none;
  transition:background .15s, color .15s;
}
.sb-logout:hover { background:rgba(239,68,68,.18); color:#f87171; text-decoration:none; }
.sb-logout i { font-size:16px; }

/* ── RIGHT CONTENT ── */
.main-area {
  margin-left:240px;
  flex:1;
  width: calc(100% - 240px);
  display:flex;
  flex-direction:column;
  min-width:0;
}

.main-topbar {
  background:#fff; border-bottom:1px solid #e2e8f0;
  display:flex; align-items:center; justify-content:space-between;
  padding:10px 28px; position:sticky; top:0; z-index:100;
}
.topbar-toggle {
  display:none; background:none; border:none;
  font-size:20px; color:#475569; cursor:pointer; padding:4px;
}
.topbar-right { display:flex; align-items:center; gap:10px; }
.topbar-icon {
  width:34px; height:34px; border-radius:8px;
  display:flex; align-items:center; justify-content:center;
  font-size:16px; color:#64748b; cursor:pointer;
  background:#f8fafc; border:1px solid #e2e8f0;
  transition:background .15s;
}
.topbar-icon:hover { background:#f1f5f9; color:#1e293b; }

.admin-content { padding:28px; flex:1; width:100%; box-sizing:border-box; }

/* All tab panels and their cards stretch full width */
#sa-tab-admins,
#sa-tab-logs,
#sa-tab-announcements,
#sa-tab-history,
#sa-tab-enrollment {
  width: 100%;
}

#sa-tab-admins > .card,
#sa-tab-logs > .card,
#sa-tab-announcements > .card,
#sa-tab-history > .card {
  width: 100%;
  max-width: 100%;
}

/* Overlay */
.sb-overlay {
  display:none; position:fixed; inset:0;
  background:rgba(0,0,0,.45); z-index:199;
}

/* ── RESPONSIVE ── */
@media (max-width:991px) {
  .left-sidebar { transform:translateX(-100%); }
  .left-sidebar.open { transform:translateX(0); }
  .sb-overlay.open { display:block; }
  .main-area { margin-left:0; width:100%; }
  .topbar-toggle { display:flex; align-items:center; justify-content:center; }
  .admin-content { padding:18px; }
}
@media (max-width:575px) { .admin-content { padding:14px; } }

.av-purple { background:#7c3aed; }
.btn-outline-navy { border-color:var(--navy); color:var(--navy); }
.btn-outline-navy:hover { background:var(--navy); color:#fff; }

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
  <aside class="left-sidebar" id="leftSidebar">

    <div class="sb-brand">
      <img src="{{ asset('photo/logo.png') }}" alt="PHLCI Logo">
      <div>
        <div class="sb-brand-name">PHLCI</div>
        <div class="sb-brand-sub">Super Admin Panel</div>
      </div>
    </div>

    <div class="sb-user">
      <div class="sb-avatar">
        @if(auth()->user()->profile_photo ?? false)
          <img src="{{ asset('storage/' . auth()->user()->profile_photo) }}" alt="Profile">
        @else
          SA
        @endif
      </div>
      <div>
        <div class="sb-user-name">{{ auth()->user()->name ?? 'Super Admin' }}</div>
        <div class="sb-user-role">{{ auth()->user()->email ?? '' }}</div>
      </div>
    </div>

    <div class="sb-section-label">Main Menu</div>

    <div class="sb-nav-item active" onclick="switchSATab('admins',this)" data-tab="admins">
      <i class="bi bi-shield-lock"></i>
      <span>Admin Accounts</span>
    </div>
    <div class="sb-nav-item" onclick="switchSATab('logs',this)" data-tab="logs">
      <i class="bi bi-clock-history"></i>
      <span>Activity Logs</span>
    </div>
    <div class="sb-nav-item" onclick="switchSATab('announcements',this)" data-tab="announcements">
      <i class="bi bi-megaphone-fill"></i>
      <span>Announcements</span>
    </div>
    <div class="sb-nav-item" onclick="switchSATab('history',this)" data-tab="history">
      <i class="bi bi-archive-fill"></i>
      <span>Enrollment History</span>
    </div>
    <div class="sb-nav-item" onclick="switchSATab('profile',this)" data-tab="profile">
      <i class="bi bi-person-circle"></i>
      <span>My Profile</span>
    </div>

    <div class="sb-bottom">
      <a href="{{ route('logout') }}" class="sb-logout">
        <i class="bi bi-box-arrow-left"></i>
        <span>Logout</span>
      </a>
    </div>

  </aside>

  <!-- ── MAIN AREA ── -->
  <div class="main-area">

    <div class="main-topbar">
      <div class="d-flex align-items-center gap-3">
        <button class="topbar-toggle" onclick="openSidebar()">
          <i class="bi bi-list"></i>
        </button>
        <div>
          <div class="fw-bold" style="font-size:15px;color:#1e293b" id="pageTitle">Admin Accounts</div>
          <div class="text-muted" style="font-size:12px">System-wide control and monitoring</div>
        </div>
      </div>
      <div class="topbar-right">
        <div style="width:34px;height:34px;border-radius:50%;background:#7c3aed;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:#fff;overflow:hidden">
          @if(auth()->user()->profile_photo ?? false)
            <img src="{{ asset('storage/' . auth()->user()->profile_photo) }}" alt="Profile" style="width:100%;height:100%;object-fit:cover;border-radius:50%">
          @else
            SA
          @endif
        </div>
      </div>
    </div>

    <!-- ===== MAIN CONTENT ===== -->
    <div class="admin-content">

  <div id="sa-dashboard-header">
  <div class="fw-bold mb-1 fs-5" style="color:#1e293b">Super Admin Dashboard</div>
  <div class="text-muted mb-4" style="font-size:14px">System-wide control: manage admin accounts, enrollment periods, and monitor all activities</div>

  <!-- ENROLLMENT PERIOD BANNER -->
  <div class="card border-0 rounded-3 p-4 mb-4 position-relative overflow-hidden" style="background:linear-gradient(135deg,#7c3aed 0%,#1e3a8a 100%)">
    <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
      <div>
        <div class="d-flex align-items-center gap-2 mb-1">
          <i class="bi bi-calendar-check-fill text-white" style="font-size:18px"></i>
          <span class="fw-bold text-white" style="font-size:16px">Enrollment Period Control</span>
        </div>
        <div class="text-white-50" style="font-size:13px">Currently: <span id="enrollmentStatusText" class="fw-semibold text-white">{{ ($enrollmentPeriod->is_open ?? false) ? 'OPEN' : 'CLOSED' }} – SY {{ $enrollmentPeriod->school_year ?? 'N/A' }}</span></div>
        <div class="text-white-50 mt-1" style="font-size:12px">
          Start: <span id="enrollStart" class="text-white fw-medium">{{ $enrollmentPeriod && $enrollmentPeriod->start_date ? \Carbon\Carbon::parse($enrollmentPeriod->start_date)->format('F j, Y') : '—' }}</span> &nbsp;|&nbsp;
          End: <span id="enrollEnd" class="text-white fw-medium">{{ $enrollmentPeriod && $enrollmentPeriod->end_date ? \Carbon\Carbon::parse($enrollmentPeriod->end_date)->format('F j, Y') : '—' }}</span>
        </div>
      </div>
      <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-light btn-sm fw-semibold px-3" onclick="openEnrollmentModal()">
          <i class="bi bi-pencil-fill me-1"></i>Edit Period
        </button>
        <button class="btn btn-sm fw-semibold px-3" id="toggleEnrollBtn"
          style="background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.4)"
          onclick="toggleEnrollment()" data-open="{{ ($enrollmentPeriod->is_open ?? false) ? '1' : '0' }}" data-period-id="{{ $enrollmentPeriod->id ?? '' }}">
          <i class="bi {{ ($enrollmentPeriod->is_open ?? false) ? 'bi-stop-fill' : 'bi-play-fill' }} me-1"></i>{{ ($enrollmentPeriod->is_open ?? false) ? 'Close Enrollment' : 'Open Enrollment' }}
        </button>
      </div>
    </div>
  </div>

  <!-- STAT CARDS -->
  <div class="row g-3 mb-4">
    <div class="col-md-3 col-6">
      <div class="card border rounded-3 p-3 h-100 position-relative overflow-hidden">
        <div class="stat-icon" style="background:#f5f3ff;color:#7c3aed;width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:18px;margin-bottom:12px"><i class="bi bi-shield-lock-fill"></i></div>
        <div class="fw-bold mb-1" style="font-size:28px;color:#1e293b">{{ $activeAdminsCount }}</div>
        <div class="text-muted" style="font-size:13px">Active Admins</div>
      </div>
    </div>
    <div class="col-md-3 col-6">
      <div class="card border rounded-3 p-3 h-100 position-relative overflow-hidden">
        <div class="stat-icon blue mb-3"><i class="bi bi-people-fill"></i></div>
        <div class="fw-bold mb-1" style="font-size:28px;color:#1e293b">{{ number_format($totalStudents) }}</div>
        <div class="text-muted" style="font-size:13px">Total Students</div>
      </div>
    </div>
    <div class="col-md-3 col-6">
      <div class="card border rounded-3 p-3 h-100 position-relative overflow-hidden">
        <div class="stat-icon orange mb-3"><i class="bi bi-clock-fill"></i></div>
        <div class="fw-bold mb-1" style="font-size:28px;color:#1e293b">{{ $pendingApplications }}</div>
        <div class="text-muted" style="font-size:13px">Pending Applications</div>
      </div>
    </div>
    <div class="col-md-3 col-6">
      <div class="card border rounded-3 p-3 h-100 position-relative overflow-hidden">
        <div class="stat-icon green mb-3"><i class="bi bi-activity"></i></div>
        <div class="fw-bold mb-1" style="font-size:28px;color:#1e293b">{{ $systemLogsToday }}</div>
        <div class="text-muted" style="font-size:13px">System Logs Today</div>
      </div>
    </div>
  </div>
  </div><!-- /sa-dashboard-header -->

  <!-- ===================== TAB: ADMIN ACCOUNTS ===================== -->
  <div id="sa-tab-admins">
    <div class="card border rounded-3 p-3 p-md-4">
      <div class="d-flex align-items-start justify-content-between mb-3 flex-wrap gap-2">
        <div>
          <div class="fw-bold mb-1" style="font-size:15px;color:#1e293b">Admin Account Management</div>
          <div class="text-muted" style="font-size:13px">Manage administrator accounts for the enrollment system</div>
        </div>
        <button class="btn btn-sm fw-semibold px-3" style="background:#7c3aed;color:#fff" onclick="openCreateAdminModal()">
          <i class="bi bi-person-plus-fill me-1"></i>Create Admin Account
        </button>
      </div>

      <div class="position-relative mb-3">
        <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
        <input type="text" class="form-control ps-5" placeholder="Search admins..." oninput="filterTable('adminTable',this.value)">
      </div>

      <div class="table-responsive">
        <table class="table table-hover align-middle" id="adminTable">
          <thead class="table-light">
            <tr>
              <th style="font-size:12.5px;text-transform:uppercase;letter-spacing:.04em;color:#64748b">Name</th>
              <th style="font-size:12.5px;text-transform:uppercase;letter-spacing:.04em;color:#64748b">Email</th>
              <th style="font-size:12.5px;text-transform:uppercase;letter-spacing:.04em;color:#64748b">Scope</th>
              <th style="font-size:12.5px;text-transform:uppercase;letter-spacing:.04em;color:#64748b" title="Whether the account is enabled — see the small dot on their avatar for whether they're online right now">Account</th>
              <th style="font-size:12.5px;text-transform:uppercase;letter-spacing:.04em;color:#64748b">Last Login</th>
              <th style="font-size:12.5px;text-transform:uppercase;letter-spacing:.04em;color:#64748b">Actions</th>
            </tr>
          </thead>
          <tbody style="font-size:13.5px" id="adminTableBody">
            @forelse($admins as $admin)
            @php
              $initials = collect(explode(' ', $admin->name))->map(fn($n) => mb_substr($n, 0, 1))->take(2)->implode('');
              $avColors = ['av-blue','av-green','av-orange','av-purple','av-gold','av-red'];
              $avColor  = $avColors[$admin->id % count($avColors)];
              $isActive = (bool) $admin->is_active;
              $scopeColor = !empty($admin->assigned_grades) ? ['bg' => '#dcfce7', 'text' => '#2d6a2d'] : ['bg' => '#ede9fe', 'text' => '#7c3aed'];
              $scopeLabel = !empty($admin->assigned_grades) ? implode(', ', $admin->assigned_grades) : 'All Grades';
            @endphp
            <tr>
              <td>
                <span class="position-relative d-inline-block me-2">
                  <span class="stu-avatar {{ $avColor }}" style="margin-right:0">{{ $initials }}</span>
                  <span style="position:absolute;bottom:-1px;right:-1px;width:9px;height:9px;border-radius:50%;background:{{ $admin->isOnline() ? '#16a34a' : '#cbd5e1' }};border:2px solid #fff" title="{{ $admin->isOnline() ? 'Online now' : 'Offline' }}"></span>
                </span>{{ $admin->name }}
                @if($admin->isOnline())<span class="badge rounded-pill ms-1" style="background:#dcfce7;color:#166534;font-size:10px;font-weight:600;padding:2px 8px">Online</span>@endif
              </td>
              <td>{{ $admin->email }}</td>
              <td><span class="badge rounded-pill px-3" style="background:{{ $scopeColor['bg'] }};color:{{ $scopeColor['text'] }}" title="{{ $scopeLabel }}">{{ $scopeLabel }}</span></td>
              <td title="Whether this admin's account is enabled — not whether they're online right now">@if($isActive)<span class="badge rounded-pill px-3" style="background:#dcfce7;color:#166534;font-weight:600">Active</span>@else<span class="badge" style="background:#fef9c3;color:#713f12;font-size:12px;padding:4px 10px;border-radius:20px">Inactive</span>@endif</td>
              <td class="text-muted" style="font-size:12px">{{ $admin->last_login_at ? \Carbon\Carbon::parse($admin->last_login_at)->format('M j, Y – g:i A') : 'Never' }}</td>
              <td>
                <div class="action-menu-wrap position-relative">
                  <button class="btn btn-sm btn-light border action-dots-btn" onclick="toggleActionMenu(event,this)"><i class="bi bi-three-dots-vertical"></i></button>
                  <div class="action-dropdown shadow-sm">
                    <button class="action-item" data-admin-id="{{ $admin->id }}" data-admin-name="{{ $admin->name }}" data-admin-email="{{ $admin->email }}" data-admin-grades="{{ json_encode($admin->assigned_grades ?? []) }}" onclick="closeMenuThen(()=>openEditAdminModal(this))"><i class="bi bi-pencil text-navy"></i> Edit</button>
                    <button class="action-item" onclick="closeMenuThen(()=>resetAdminPassword({{ $admin->id }},'{{ $admin->name }}'))"><i class="bi bi-key text-warning"></i> Reset Password</button>
                    @if($isActive)
                    <button class="action-item text-danger" onclick="closeMenuThen(()=>deactivateAdmin({{ $admin->id }},'{{ $admin->name }}'))"><i class="bi bi-slash-circle"></i> Deactivate</button>
                    @else
                    <button class="action-item text-success" onclick="closeMenuThen(()=>activateAdmin({{ $admin->id }},'{{ $admin->name }}'))"><i class="bi bi-check-circle"></i> Activate</button>
                    @endif
                  </div>
                </div>
              </td>
            </tr>
            @empty
            <tr><td colspan="6" class="text-center text-muted py-4">No admin accounts yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ===================== TAB: ENROLLMENT SETTINGS ===================== -->
  <div id="sa-tab-enrollment" class="d-none">
    <div class="row g-3">

      <!-- Enrollment Period Card -->
     
      <!-- Grade-level toggles -->
      <div class="col-lg-6">
        <div class="card border rounded-3 p-4">
          <div class="fw-bold mb-1" style="font-size:15px;color:#1e293b"><i class="bi bi-toggles me-2 text-navy"></i>Grade-Level Enrollment Toggle</div>
          <div class="text-muted mb-4" style="font-size:13px">Enable or disable enrollment per grade level</div>

          <?php foreach($grades as $g => $on): ?>
          <div class="d-flex align-items-center justify-content-between py-3" style="border-bottom:1px solid #f1f5f9">
            <div class="d-flex align-items-center gap-3">
              <div style="width:36px;height:36px;border-radius:8px;background:#f1f5f9;display:flex;align-items:center;justify-content:center">
                <i class="bi bi-mortarboard-fill" style="color:<?= $gradeColors[$g] ?>"></i>
              </div>
              <div>
                <div class="fw-semibold" style="font-size:13.5px;color:#1e293b"><?= $g ?></div>
                <div class="text-muted grade-toggle-status" style="font-size:11.5px"><?= $on ? 'Enrollment Open' : 'Enrollment Closed' ?></div>
              </div>
            </div>
            <div class="form-check form-switch mb-0">
              <input class="form-check-input grade-toggle-input" type="checkbox" data-grade="<?= $g ?>" <?= $on ? 'checked' : '' ?> style="width:2.5em;height:1.3em;cursor:pointer" onchange="this.closest('.d-flex').querySelector('.grade-toggle-status').textContent = this.checked ? 'Enrollment Open' : 'Enrollment Closed'">
            </div>
          </div>
          <?php endforeach; ?>
          <button class="btn btn-outline-navy btn-sm fw-semibold w-100 mt-3" onclick="alert('Grade toggles saved!')">
            <i class="bi bi-save me-1"></i>Save Grade Settings
          </button>
        </div>
      </div>

      <!-- Tuition rates per grade level -->
      <div class="col-lg-6">
        <div class="card border rounded-3 p-4">
          <div class="fw-bold mb-1" style="font-size:15px;color:#1e293b"><i class="bi bi-cash-coin me-2 text-navy"></i>Tuition Rates</div>
          <div class="text-muted mb-4" style="font-size:13px">Set the annual tuition fee per grade level. Used to generate each student's monthly/quarterly installment schedule.</div>

          <div class="d-flex align-items-center justify-content-between py-2 mb-2" style="border-bottom:2px solid #e2e8f0">
            <div class="d-flex align-items-center gap-3">
              <div style="width:36px;height:36px;border-radius:8px;background:#f0fdf4;display:flex;align-items:center;justify-content:center">
                <i class="bi bi-wallet2" style="color:#16a34a"></i>
              </div>
              <div>
                <div class="fw-semibold" style="font-size:13.5px;color:#1e293b">Enrollment Fee (Down Payment)</div>
                <div class="text-muted" style="font-size:11px">Flat fee for all grades, paid at enrollment</div>
              </div>
            </div>
            <div class="input-group input-group-sm" style="width:160px">
              <span class="input-group-text" style="font-size:12px">₱</span>
              <input type="number" min="0" step="0.01" id="enrollmentFeeInput" value="{{ $enrollmentPeriod->enrollment_fee ?? 15000 }}" class="form-control" style="font-size:12.5px">
            </div>
          </div>

          @foreach($tuitionFees as $g => $amount)
          <div class="d-flex align-items-center justify-content-between py-2" style="border-bottom:1px solid #f1f5f9">
            <div class="d-flex align-items-center gap-3">
              <div style="width:36px;height:36px;border-radius:8px;background:#f1f5f9;display:flex;align-items:center;justify-content:center">
                <i class="bi bi-mortarboard-fill" style="color:{{ $gradeColors[$g] }}"></i>
              </div>
              <div class="fw-semibold" style="font-size:13.5px;color:#1e293b">{{ $g }}</div>
            </div>
            <div class="input-group input-group-sm" style="width:160px">
              <span class="input-group-text" style="font-size:12px">₱</span>
              <input type="number" min="0" step="0.01" class="form-control tuition-fee-input" data-grade="{{ $g }}" value="{{ $amount }}" style="font-size:12.5px">
            </div>
          </div>
          @endforeach
          <button class="btn btn-outline-navy btn-sm fw-semibold w-100 mt-3" onclick="saveTuitionRates()">
            <i class="bi bi-save me-1"></i>Save Tuition Rates
          </button>
        </div>
      </div>

    </div>
  </div>

  <!-- ===================== TAB: ACTIVITY LOGS ===================== -->
  <div id="sa-tab-logs" class="d-none">
    <div class="card border rounded-3 p-3 p-md-4">
      <div class="d-flex align-items-start justify-content-between mb-3 flex-wrap gap-2">
        <div>
          <div class="fw-bold mb-1" style="font-size:15px;color:#1e293b">Activity Logs</div>
          <div class="text-muted" style="font-size:13px">All admin and system actions are recorded here</div>
        </div>
        <button class="btn btn-outline-secondary btn-sm"><i class="bi bi-download me-1"></i>Export Logs</button>
      </div>
      <div class="position-relative mb-3">
        <i class="bi bi-search position-absolute top-50 start-0 translate-middle-y ms-3 text-muted"></i>
        <input type="text" class="form-control ps-5" placeholder="Search logs...">
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle">
          <thead class="table-light">
            <tr>
              <th style="font-size:12px;text-transform:uppercase;color:#64748b">Timestamp</th>
              <th style="font-size:12px;text-transform:uppercase;color:#64748b">User</th>
              <th style="font-size:12px;text-transform:uppercase;color:#64748b">Role</th>
              <th style="font-size:12px;text-transform:uppercase;color:#64748b">Action</th>
              <th style="font-size:12px;text-transform:uppercase;color:#64748b">Details</th>
            </tr>
          </thead>
          <tbody style="font-size:13px">
            @forelse($activityLogs as $log)
            <tr>
              <td class="text-muted" style="font-size:12px;white-space:nowrap">{{ $log->created_at->format('M j, Y – g:i A') }}</td>
              <td class="fw-medium">{{ $log->user_name }}</td>
              <td><span class="badge rounded-pill px-2" style="background:{{ $logBg[$log->type] ?? '#f1f5f9' }};color:{{ $logColors[$log->type] ?? '#475569' }};font-size:11px">{{ $log->user_role }}</span></td>
              <td class="fw-semibold" style="color:{{ $logColors[$log->type] ?? '#475569' }}">{{ $log->action }}</td>
              <td class="text-muted" style="font-size:12px">{{ $log->details }}</td>
            </tr>
            @empty
            <tr><td colspan="5" class="text-center text-muted py-4">No activity recorded yet.</td></tr>
            @endforelse
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- ===================== TAB: ANNOUNCEMENTS ===================== -->
  <div id="sa-tab-announcements" class="d-none">
    <div class="card border rounded-3 p-3 p-md-4">
      <div class="d-flex align-items-start justify-content-between mb-3 flex-wrap gap-2">
        <div>
          <div class="fw-bold mb-1" style="font-size:15px;color:#1e293b"><i class="bi bi-megaphone-fill me-2" style="color:#7c3aed"></i>Announcements</div>
          <div class="text-muted" style="font-size:13px">Create and manage popup announcements shown to students on login</div>
        </div>
        <button class="btn btn-sm fw-semibold px-3" style="background:#7c3aed;color:#fff" onclick="openCreateAnnouncementModal()">
          <i class="bi bi-plus-circle me-1"></i>New Announcement
        </button>
      </div>

      <!-- Active announcement banner -->
      <div id="activeAnnouncementBanner" class="alert d-none align-items-start gap-3 mb-3" style="background:#f5f3ff;border:1px solid #c4b5fd;border-radius:12px">
        <i class="bi bi-bell-fill" style="color:#7c3aed;font-size:18px;flex-shrink:0;margin-top:2px"></i>
        <div class="flex-grow-1">
          <div class="fw-bold mb-1" style="font-size:13.5px;color:#5b21b6">Active Announcement</div>
          <div id="activeAnnTitle" class="fw-semibold" style="font-size:14px;color:#1e293b">Welcome to SY 2025–2026 Enrollment!</div>
          <div id="activeAnnMsg" class="text-muted mt-1" style="font-size:13px">Online enrollment for SY 2025–2026 is now open. Please complete all steps and submit the required documents before the deadline.</div>
        </div>
        <span class="badge rounded-pill px-3 py-2" style="background:#dcfce7;color:#166534;font-size:11px;font-weight:700">● Active</span>
      </div>

      <!-- Announcements list -->
      <div id="announcementsList" class="d-flex flex-column gap-3"></div>
    </div>
  </div>

  <!-- ===================== TAB: ENROLLMENT HISTORY ===================== -->
  <div id="sa-tab-history" class="d-none">

    <!-- Summary banner -->
    <div class="card border-0 rounded-3 p-4 mb-4 position-relative overflow-hidden" style="background:linear-gradient(135deg,#7c3aed 0%,#1e3a8a 100%)">
      <div class="d-flex align-items-center justify-content-between flex-wrap gap-3">
        <div>
          <div class="d-flex align-items-center gap-2 mb-1">
            <i class="bi bi-archive-fill text-white" style="font-size:18px"></i>
            <span class="fw-bold text-white" style="font-size:16px">Enrollment History</span>
          </div>
          <div class="text-white-50" style="font-size:13px">Enrollment numbers per grade level, from the system's real records</div>
        </div>
        <div class="dropdown">
          <button class="btn btn-light btn-sm fw-semibold px-3 dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
            <i class="bi bi-download me-1"></i>Export All
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li><a class="dropdown-item" href="{{ route('superadmin.history.export', ['format' => 'pdf']) }}"><i class="bi bi-file-earmark-pdf me-2 text-danger"></i>PDF — printable report</a></li>
            <li><a class="dropdown-item" href="{{ route('superadmin.history.export', ['format' => 'csv']) }}"><i class="bi bi-filetype-csv me-2 text-success"></i>CSV — for Excel / Sheets</a></li>
          </ul>
        </div>
      </div>
    </div>

    <!-- SY history cards -->
    <div id="syHistoryList"></div>

    <div class="d-flex align-items-start gap-2 p-3 rounded-3" style="background:#f8fafc;border:1px dashed #cbd5e1;font-size:12.5px;color:#64748b">
      <i class="bi bi-archive flex-shrink-0 mt-1"></i>
      <span>No archived school years yet. Past school years will be listed here once the system has been used across more than one school year.</span>
    </div>

  </div>

  <!-- ═══ TAB: MY PROFILE ═══ -->
  <div id="sa-tab-profile" class="d-none">
    <div class="fw-bold mb-1" style="font-size:20px;color:#1e293b">My Profile</div>
    <div class="text-muted mb-4" style="font-size:14px">Manage your profile picture and account password</div>

    <!-- Profile Picture -->
    <div class="card border rounded-3 p-4 mb-4">
      <div class="fw-bold mb-3 pb-3" style="font-size:15px;color:#1e293b;border-bottom:1px solid #f1f5f9"><i class="bi bi-image-fill me-2 text-navy"></i>Profile Picture</div>
      <div class="d-flex flex-column align-items-center text-center py-2">
        <div class="pp-avatar-wrap" id="ppAvatarTrigger" onclick="openSAPhotoModal()" role="button" tabindex="0" title="Change profile photo">
          @if(auth()->user()->profile_photo ?? false)
            <img src="{{ asset('storage/'.auth()->user()->profile_photo) }}" alt="Profile photo" class="pp-avatar-img" id="ppCurrentImg">
          @else
            <div class="pp-avatar-fallback" id="ppCurrentFallback"><i class="bi bi-person-fill"></i></div>
          @endif
          <div class="pp-avatar-overlay"><i class="bi bi-camera-fill"></i></div>
          <div class="pp-avatar-badge"><i class="bi bi-camera-fill"></i></div>
        </div>
        <div class="fw-semibold mt-3" style="font-size:14.5px;color:#1e293b">{{ auth()->user()->name ?? 'Super Admin' }}</div>
        <div class="text-muted" style="font-size:13px">{{ auth()->user()->email ?? '' }}</div>
        <button type="button" class="btn btn-outline-navy btn-sm fw-semibold mt-3" onclick="openSAPhotoModal()">
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

      <form id="saChangePasswordForm" onsubmit="submitSAChangePassword(event)">
        <div class="mb-3">
          <label class="form-label fw-medium" style="font-size:13px">Current Password <span class="text-danger">*</span></label>
          <div class="input-group">
            <input type="password" id="sa-cp-current" class="form-control" placeholder="Enter your current password" required>
            <button type="button" class="btn btn-outline-secondary" onclick="togglePw('sa-cp-current',this)"><i class="bi bi-eye"></i></button>
          </div>
        </div>
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-medium" style="font-size:13px">New Password <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="password" id="sa-cp-new" class="form-control" placeholder="Enter new password" minlength="8" required oninput="updatePasswordChecklist(this.value, 'sa-cp-new-req')">
              <button type="button" class="btn btn-outline-secondary" onclick="togglePw('sa-cp-new',this)"><i class="bi bi-eye"></i></button>
            </div>
            @include('partials.password-checklist', ['prefix' => 'sa-cp-new-req'])
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium" style="font-size:13px">Confirm New Password <span class="text-danger">*</span></label>
            <div class="input-group">
              <input type="password" id="sa-cp-confirm" class="form-control" placeholder="Re-enter new password" minlength="8" required>
              <button type="button" class="btn btn-outline-secondary" onclick="togglePw('sa-cp-confirm',this)"><i class="bi bi-eye"></i></button>
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

<!-- ═══ MODAL: SUPER ADMIN MODERN PHOTO UPLOAD ═══ -->
<div class="modal fade" id="saManagePhotoModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content pp-modal">
      <div class="modal-header border-0 pb-0">
        <h5 class="modal-title fw-bold" style="color:#1e293b">Update Profile Photo</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="resetSAPhotoModal()"></button>
      </div>
      <form id="saProfilePhotoForm" onsubmit="submitSAProfilePhoto(event)">
        <div class="modal-body pt-2">

          <div class="pp-dropzone" id="saPpDropzone" onclick="document.getElementById('sa-pp-file').click()">
            <div class="pp-preview-circle" id="saPpPreviewCircle">
              @if(auth()->user()->profile_photo ?? false)
                <img src="{{ asset('storage/'.auth()->user()->profile_photo) }}" alt="" id="saPpPreviewImg">
              @else
                <div class="pp-preview-fallback" id="saPpPreviewFallback"><i class="bi bi-person-fill"></i></div>
                <img src="" alt="" id="saPpPreviewImg" style="display:none">
              @endif
            </div>
            <div class="pp-dropzone-text">
              <div class="fw-semibold" style="font-size:13.5px;color:#1e293b" id="saPpDropzoneTitle">Drag & drop a photo here</div>
              <div class="text-muted" style="font-size:12px">or <span class="text-navy fw-semibold">click to browse</span> &nbsp;•&nbsp; JPG/PNG, up to 2MB</div>
            </div>
            <input type="file" id="sa-pp-file" name="profile_photo" accept="image/png, image/jpeg" class="d-none" onchange="handleSAPhotoSelect(this.files[0])">
          </div>

          <div id="saPpFileMeta" class="d-none mt-3 d-flex align-items-center justify-content-between rounded-3 px-3 py-2" style="background:#f8fafc;border:1px solid var(--border)">
            <div class="d-flex align-items-center gap-2" style="font-size:12.5px;color:#475569">
              <i class="bi bi-file-earmark-image text-navy"></i>
              <span id="saPpFileName" class="text-truncate" style="max-width:220px"></span>
            </div>
            <button type="button" class="btn btn-sm btn-link text-danger p-0" style="font-size:12px" onclick="clearSAPhotoSelect()"><i class="bi bi-x-circle"></i> Remove</button>
          </div>

          <div id="saPpErrorMsg" class="text-danger mt-2 d-none" style="font-size:12.5px"><i class="bi bi-exclamation-circle me-1"></i><span></span></div>
        </div>
        <div class="modal-footer border-0">
          @if(auth()->user()->profile_photo ?? false)
          <button type="button" class="btn btn-outline-danger btn-sm" onclick="removeSAProfilePhoto()"><i class="bi bi-trash me-1"></i>Remove Current Photo</button>
          @endif
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal" onclick="resetSAPhotoModal()">Cancel</button>
          <button type="submit" class="btn btn-navy btn-sm fw-semibold" id="saPpSubmitBtn" disabled><i class="bi bi-check-lg me-1"></i>Save Photo</button>
        </div>
      </form>
    </div>
  </div>
</div>


<!-- ===================== MODAL: CREATE ADMIN ===================== -->
<div class="modal fade" id="createAdminModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered" style="max-width:520px">
    <div class="modal-content border-0 shadow-lg" style="border-radius:18px;overflow:hidden">
      <div style="background:linear-gradient(135deg,#7c3aed,#1e3a8a);padding:24px 28px 20px;position:relative">
        <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" data-bs-dismiss="modal"></button>
        <div class="d-flex align-items-center gap-3">
          <div style="width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff">
            <i class="bi bi-person-plus-fill"></i>
          </div>
          <div>
            <div style="font-size:17px;font-weight:800;color:#fff">Create Admin Account</div>
            <div style="font-size:12px;color:rgba(255,255,255,.7)">Assign to one or more grade levels</div>
          </div>
        </div>
      </div>
      <div class="modal-body p-4">
        <div class="row g-3">
          <div class="col-md-6">
            <label class="form-label fw-medium" style="font-size:13px">First Name *</label>
            <input type="text" class="form-control" placeholder="First Name" id="ca-fname">
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium" style="font-size:13px">Last Name *</label>
            <input type="text" class="form-control" placeholder="Last Name" id="ca-lname">
          </div>
          <div class="col-12">
            <label class="form-label fw-medium" style="font-size:13px">Email Address *</label>
            <input type="email" class="form-control" placeholder="admin@phlci.edu.ph" id="ca-email">
          </div>
          <div class="col-12">
            <label class="form-label fw-medium" style="font-size:13px">Assigned Grade Levels</label>
            <div class="d-flex flex-wrap gap-2" id="ca-grades">
              @foreach($gradeLevels as $g)
              <label class="grade-chip" style="cursor:pointer;font-size:12.5px;padding:6px 12px;border-radius:20px;border:1px solid #e2e8f0;background:#fff;user-select:none">
                <input type="checkbox" class="ca-grade-check d-none" value="{{ $g }}" onchange="this.closest('label').style.background=this.checked?'#7c3aed':'#fff';this.closest('label').style.color=this.checked?'#fff':'';this.closest('label').style.borderColor=this.checked?'#7c3aed':'#e2e8f0'">
                {{ $g }}
              </label>
              @endforeach
            </div>
            <div class="form-text"><i class="bi bi-info-circle me-1"></i>Leave all unchecked for "All Grades" (unrestricted) access.</div>
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium" style="font-size:13px">Temporary Password *</label>
            <input type="password" class="form-control" placeholder="Set password" id="ca-pass" oninput="updatePasswordChecklist(this.value, 'ca-pass-req')">
            @include('partials.password-checklist', ['prefix' => 'ca-pass-req'])
          </div>
          <div class="col-md-6">
            <label class="form-label fw-medium" style="font-size:13px">Confirm Password *</label>
            <input type="password" class="form-control" placeholder="Confirm password" id="ca-pass2">
          </div>
        </div>
        <div class="alert alert-info mt-3 py-2" style="font-size:12.5px">
          <i class="bi bi-shield-lock me-1"></i>The admin will be prompted to change their password upon first login.
        </div>
      </div>
      <div class="modal-footer border-0 pt-0 px-4 pb-4">
        <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm fw-semibold px-4" style="background:#7c3aed;color:#fff" onclick="submitCreateAdmin()">
          <i class="bi bi-person-check me-1"></i>Create Admin
        </button>
      </div>
    </div>
  </div>
</div>


<!-- ===================== MODAL: EDIT ADMIN ===================== -->
<div class="modal fade" id="editAdminModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered" style="max-width:480px">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px">
      <div class="modal-header border-0 pb-0 px-4 pt-4">
        <div>
          <h5 class="modal-title fw-bold" style="color:#1e293b"><i class="bi bi-pencil-fill me-2" style="color:#7c3aed"></i>Edit Admin Account</h5>
          <div class="text-muted" style="font-size:13px">Editing: <strong id="ea-name-label"></strong></div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4">
        <div class="mb-3">
          <label class="form-label fw-medium" style="font-size:13px">Email Address</label>
          <input type="email" class="form-control" id="ea-email">
        </div>
        <div class="mb-3">
          <label class="form-label fw-medium" style="font-size:13px">Assigned Grade Levels</label>
          <div class="d-flex flex-wrap gap-2" id="ea-grades">
            @foreach($gradeLevels as $g)
            <label class="grade-chip" style="cursor:pointer;font-size:12.5px;padding:6px 12px;border-radius:20px;border:1px solid #e2e8f0;background:#fff;user-select:none">
              <input type="checkbox" class="ea-grade-check d-none" value="{{ $g }}" onchange="this.closest('label').style.background=this.checked?'#7c3aed':'#fff';this.closest('label').style.color=this.checked?'#fff':'';this.closest('label').style.borderColor=this.checked?'#7c3aed':'#e2e8f0'">
              {{ $g }}
            </label>
            @endforeach
          </div>
          <div class="form-text"><i class="bi bi-info-circle me-1"></i>Leave all unchecked for "All Grades" (unrestricted) access.</div>
        </div>
      </div>
      <div class="modal-footer border-0 px-4 pb-4">
        <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm fw-semibold px-4" style="background:#7c3aed;color:#fff" onclick="submitEditAdmin()">Save Changes</button>
      </div>
    </div>
  </div>
</div>


<!-- ===================== MODAL: ENROLLMENT PERIOD ===================== -->
<div class="modal fade" id="enrollmentModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered" style="max-width:440px">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px">
      <div class="modal-header border-0 pb-0 px-4 pt-4">
        <h5 class="modal-title fw-bold" style="color:#1e293b"><i class="bi bi-calendar-check-fill me-2" style="color:#7c3aed"></i>Edit Enrollment Period</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body px-4">
        <input type="hidden" id="ep-id" value="{{ $enrollmentPeriod->id ?? '' }}">
        <div class="mb-3">
          <label class="form-label fw-medium" style="font-size:13px">School Year</label>
          <input type="text" class="form-control" value="{{ $enrollmentPeriod->school_year ?? '' }}" id="ep-sy">
        </div>
        <div class="mb-3">
          <label class="form-label fw-medium" style="font-size:13px">Enrollment Start Date</label>
          <input type="text" class="form-control" placeholder="Select date" readonly autocomplete="off" value="{{ $enrollmentPeriod && $enrollmentPeriod->start_date ? \Carbon\Carbon::parse($enrollmentPeriod->start_date)->format('Y-m-d') : '' }}" id="ep-start">
        </div>
        <div class="mb-3">
          <label class="form-label fw-medium" style="font-size:13px">Enrollment End Date</label>
          <input type="text" class="form-control" placeholder="Select date" readonly autocomplete="off" value="{{ $enrollmentPeriod && $enrollmentPeriod->end_date ? \Carbon\Carbon::parse($enrollmentPeriod->end_date)->format('Y-m-d') : '' }}" id="ep-end">
        </div>
      </div>
      <div class="modal-footer border-0 px-4 pb-4">
        <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-navy btn-sm fw-semibold px-4" onclick="saveEnrollmentPeriod()"><i class="bi bi-save me-1"></i>Save</button>
      </div>
    </div>
  </div>
</div>

<style>
  .av-purple { background:#7c3aed; }
  .btn-outline-navy { border-color:var(--navy); color:var(--navy); }
  .btn-outline-navy:hover { background:var(--navy); color:#fff; }
</style>

<script>
// Declared here (script scope, so every function below can use them) but only
// actually initialized once the whole document — including #ann-from/#ann-until,
// which live inside a modal much further down the page than this script tag —
// has finished parsing. Initializing immediately would query the DOM before
// those elements exist, silently returning an empty NodeList instead of a
// real picker instance (no error, just picker.setDate() failing later).
var epStartPicker, epEndPicker, annFromPicker, annUntilPicker;
document.addEventListener('DOMContentLoaded', function () {
  epStartPicker  = flatpickr('#ep-start',  { dateFormat: 'Y-m-d', altInput: true, altFormat: 'F j, Y' });
  epEndPicker    = flatpickr('#ep-end',    { dateFormat: 'Y-m-d', altInput: true, altFormat: 'F j, Y' });
  annFromPicker  = flatpickr('#ann-from',  { dateFormat: 'Y-m-d', altInput: true, altFormat: 'F j, Y' });
  annUntilPicker = flatpickr('#ann-until', { dateFormat: 'Y-m-d', altInput: true, altFormat: 'F j, Y' });
});

/* ── Bootstrap modal helper ── */
function bsModal(id) { return bootstrap.Modal.getOrCreateInstance(document.getElementById(id)); }

/* ── Profile: modern photo upload (drag-drop + live preview) ──
   NOTE: assumes routes super-admin.profile.photo (POST, multipart),
   super-admin.profile.photo.remove (DELETE) exist — see route checklist. */
function openSAPhotoModal() { resetSAPhotoModal(); bsModal('saManagePhotoModal').show(); }

function resetSAPhotoModal() {
  document.getElementById('sa-pp-file').value = '';
  document.getElementById('saPpFileMeta').classList.add('d-none');
  document.getElementById('saPpErrorMsg').classList.add('d-none');
  document.getElementById('saPpSubmitBtn').disabled = true;
  document.getElementById('saPpDropzoneTitle').textContent = 'Drag & drop a photo here';
  const img = document.getElementById('saPpPreviewImg');
  @if(auth()->user()->profile_photo ?? false)
    img.src = '{{ asset("storage/".auth()->user()->profile_photo) }}';
    img.style.display = '';
  @else
    img.style.display = 'none';
    const fb = document.getElementById('saPpPreviewFallback');
    if (fb) fb.style.display = 'flex';
  @endif
}

function showSAPhotoError(msg) {
  const el = document.getElementById('saPpErrorMsg');
  el.classList.remove('d-none');
  el.querySelector('span').textContent = msg;
}

function handleSAPhotoSelect(file) {
  document.getElementById('saPpErrorMsg').classList.add('d-none');
  if (!file) return;
  if (!['image/jpeg','image/png'].includes(file.type)) { showSAPhotoError('Please choose a JPG or PNG image.'); return; }
  if (file.size > 2 * 1024 * 1024) { showSAPhotoError('Image must be 2MB or smaller.'); return; }

  const reader = new FileReader();
  reader.onload = (e) => {
    const img = document.getElementById('saPpPreviewImg');
    img.src = e.target.result;
    img.style.display = '';
    const fb = document.getElementById('saPpPreviewFallback');
    if (fb) fb.style.display = 'none';
  };
  reader.readAsDataURL(file);

  document.getElementById('saPpFileName').textContent = file.name;
  document.getElementById('saPpFileMeta').classList.remove('d-none');
  document.getElementById('saPpDropzoneTitle').textContent = 'Looking good! Click to choose a different photo';
  document.getElementById('saPpSubmitBtn').disabled = false;
}

function clearSAPhotoSelect() {
  document.getElementById('sa-pp-file').value = '';
  resetSAPhotoModal();
}

(function setupSAPhotoDropzone() {
  document.addEventListener('DOMContentLoaded', () => {
    const zone = document.getElementById('saPpDropzone');
    if (!zone) return;
    ['dragenter','dragover'].forEach(evt => zone.addEventListener(evt, (e) => { e.preventDefault(); zone.classList.add('pp-dragover'); }));
    ['dragleave','drop'].forEach(evt => zone.addEventListener(evt, (e) => { e.preventDefault(); zone.classList.remove('pp-dragover'); }));
    zone.addEventListener('drop', (e) => {
      const file = e.dataTransfer.files[0];
      if (!file) return;
      document.getElementById('sa-pp-file').files = e.dataTransfer.files;
      handleSAPhotoSelect(file);
    });
  });
})();

function submitSAProfilePhoto(e) {
  e.preventDefault();
  const fileInput = document.getElementById('sa-pp-file');
  if (!fileInput.files.length) return;
  const fd = new FormData();
  fd.append('profile_photo', fileInput.files[0]);
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  fetch('{{ route('superadmin.profile.photo') }}', { method: 'POST', headers: { 'X-CSRF-TOKEN': token || '', 'Accept': 'application/json' }, body: fd })
    .then(res => { if (!res.ok) throw new Error(); bsModal('saManagePhotoModal').hide(); phlciToast('Profile photo updated!', 'success'); setTimeout(() => location.reload(), 600); })
    .catch(() => showSAPhotoError('Could not upload the photo. Please try again.'));
}
function removeSAProfilePhoto() {
  if (!confirm('Remove your profile photo?')) return;
  apiFetch('{{ route('superadmin.profile.photo.remove') }}', 'DELETE').then(() => {
    bsModal('saManagePhotoModal').hide();
    phlciToast('Profile photo removed.', 'success');
    setTimeout(() => location.reload(), 600);
  }).catch(() => alert('Could not remove the photo.'));
}

/* ── Profile: change password ── */
function submitSAChangePassword(e) {
  e.preventDefault();
  const current = document.getElementById('sa-cp-current').value;
  const next    = document.getElementById('sa-cp-new').value;
  const confirm_ = document.getElementById('sa-cp-confirm').value;
  if (next.length < 8 || !/[A-Z]/.test(next) || !/[0-9]/.test(next) || !/[^A-Za-z0-9]/.test(next)) {
    alert('New password must be at least 8 characters and include an uppercase letter, a number, and a special character.');
    return;
  }
  if (next !== confirm_) { alert('New password and confirmation do not match.'); return; }
  apiFetch('{{ route('superadmin.profile.password') }}', 'PUT', { current_password: current, password: next, password_confirmation: confirm_ })
    .then(() => { phlciToast('Password updated successfully!', 'success'); document.getElementById('saChangePasswordForm').reset(); setTimeout(() => location.reload(), 600); })
    .catch(() => alert('Could not update your password. Please check your current password and try again.'));
}

/* ── Tab switching ── */
const pageTitles = {
  admins:'Admin Accounts', enrollment:'Enrollment Settings',
  logs:'Activity Logs', announcements:'Announcements', history:'Enrollment History',
  profile:'My Profile'
};
function switchSATab(tab, el) {
  sessionStorage.setItem('saTab', tab);
  document.querySelectorAll('.sb-nav-item').forEach(t => t.classList.remove('active'));
  if (el) {
    el.classList.add('active');
  } else {
    document.querySelectorAll('.sb-nav-item[data-tab]').forEach(function(item) {
      if (item.dataset.tab === tab) item.classList.add('active');
    });
  }
  ['admins','logs','announcements','history','profile'].forEach(function(t) {
    document.getElementById('sa-tab-' + t).classList.toggle('d-none', t !== tab);
  });
  document.getElementById('sa-dashboard-header').classList.toggle('d-none', tab === 'profile');
  const titleEl = document.getElementById('pageTitle');
  if (titleEl) titleEl.textContent = pageTitles[tab] || tab;
  if (window.innerWidth < 992) closeSidebar();
}

/* ── Enrollment History data & render ── */
// Current school year only, computed from the database
// (SuperAdminController::enrollmentSummary) — no sample/demo records.
const _saHistoryData = [@json(\App\Http\Controllers\SuperAdminController::enrollmentSummary())];

const _saGradeColors = {
  'Grade 7':  { bg: '#ccfbf1', color: '#0f766e' },
  'Grade 8':  { bg: '#fef3c7', color: '#b45309' },
  'Grade 9':  { bg: '#fce7f3', color: '#be185d' },
  'Grade 10': { bg: '#eff6ff', color: '#1e40af' },
};

function renderSYHistory(filter) {
  var list = document.getElementById('syHistoryList');
  if (!list) return;
  var data = filter === 'all' ? _saHistoryData : _saHistoryData.filter(function(d){ return d.key === filter; });
  list.innerHTML = data.map(function(rec) {
    var isActive = rec.status === 'active';
    var headerBg = isActive ? 'linear-gradient(135deg,#7c3aed,#1e3a8a)' : '#f8fafc';
    var headerColor = isActive ? '#fff' : '#1e293b';
    var subColor = isActive ? 'rgba(255,255,255,.7)' : '#64748b';

    var statCards = [
      { icon: 'bi-file-earmark-text', label: 'Applications',  val: rec.totalApplications, bg: '#fefce8', c: '#713f12' },
      { icon: 'bi-hourglass-split',   label: 'Pending',       val: rec.totalPending,      bg: '#fff7ed', c: '#c2410c' },
      { icon: 'bi-check-circle-fill', label: 'Approved',      val: rec.totalApproved,     bg: '#f0fdf4', c: '#166534' },
      { icon: 'bi-people-fill',       label: 'Enrolled',      val: rec.totalEnrolled,     bg: '#eff6ff', c: '#1e40af' },
    ].map(function(s) {
      return '<div class="col-6 col-md-3">' +
        '<div class="rounded-3 p-3 text-center" style="background:'+s.bg+'">' +
          '<i class="bi '+s.icon+'" style="font-size:18px;color:'+s.c+'"></i>' +
          '<div class="fw-bold mt-1" style="font-size:20px;color:'+s.c+'">'+s.val+'</div>' +
          '<div style="font-size:11.5px;color:'+s.c+';opacity:.8">'+s.label+'</div>' +
        '</div></div>';
    }).join('');

    var gradeRows = rec.grades.map(function(g) {
      var c = _saGradeColors[g.label] || { bg: '#f1f5f9', color: '#475569' };
      var rate = g.applications ? Math.round((g.approved / g.applications) * 100) : 0;
      return '<tr style="font-size:13px">' +
        '<td><span class="badge rounded-pill px-3" style="background:'+c.bg+';color:'+c.color+';font-weight:700">'+g.label+'</span></td>' +
        '<td class="text-center">'+g.sections+'</td>' +
        '<td class="text-center">'+g.applications+'</td>' +
        '<td class="text-center">'+g.approved+'</td>' +
        '<td class="text-center fw-bold" style="color:#166534">'+g.enrolled+'</td>' +
        '<td><div style="display:flex;align-items:center;gap:8px"><div style="flex:1;background:#e2e8f0;border-radius:20px;height:7px;overflow:hidden"><div style="width:'+rate+'%;height:100%;background:'+c.color+';border-radius:20px"></div></div><span style="font-size:11.5px;color:#64748b;white-space:nowrap">'+rate+'%</span></div></td>' +
      '</tr>';
    }).join('');

    return '<div class="card border rounded-3 mb-4 overflow-hidden" data-sy-key="'+rec.key+'">' +
      '<div class="d-flex align-items-center justify-content-between p-3 flex-wrap gap-2" style="background:'+headerBg+';cursor:pointer" onclick="this.nextElementSibling.classList.toggle(\'d-none\')">' +
        '<div class="d-flex align-items-center gap-3">' +
          '<div style="width:42px;height:42px;border-radius:10px;background:'+(isActive?'rgba(255,255,255,.18)':'#e2e8f0')+';display:flex;align-items:center;justify-content:center;font-size:18px;color:'+(isActive?'#fff':'#64748b')+'">' +
            '<i class="bi bi-calendar2-week-fill"></i></div>' +
          '<div>' +
            '<div class="fw-bold" style="font-size:15px;color:'+headerColor+'">'+rec.sy+'</div>' +
            '<div style="font-size:12px;color:'+subColor+'"><i class="bi bi-calendar3 me-1"></i>'+rec.period+'</div>' +
          '</div>' +
        '</div>' +
        '<div class="d-flex align-items-center gap-2">' +
          '<span class="badge rounded-pill px-3" style="background:'+(isActive?'rgba(255,255,255,.2)':'#f1f5f9')+';color:'+(isActive?'#fff':'#64748b')+';font-size:11px">'+(isActive?'&#9679; Current SY':'&#9675; Archived')+'</span>' +
          '<i class="bi bi-chevron-down" style="color:'+headerColor+'"></i>' +
        '</div>' +
      '</div>' +
      '<div class="'+(isActive ? '' : 'd-none')+'" style="background:#fff">' +
        '<div class="p-3"><div class="row g-3">'+statCards+'</div></div>' +
        '<div class="px-3 pb-3">' +
          '<div class="table-responsive">' +
            '<table class="table table-hover align-middle mb-0" style="border-top:1px solid #f1f5f9">' +
              '<thead class="table-light">' +
                '<tr><th style="font-size:12px;text-transform:uppercase;color:#64748b">Grade</th>' +
                '<th class="text-center" style="font-size:12px;text-transform:uppercase;color:#64748b">Sections</th>' +
                '<th class="text-center" style="font-size:12px;text-transform:uppercase;color:#64748b">Applications</th>' +
                '<th class="text-center" style="font-size:12px;text-transform:uppercase;color:#64748b">Approved</th>' +
                '<th class="text-center" style="font-size:12px;text-transform:uppercase;color:#64748b">Enrolled</th>' +
                '<th style="font-size:12px;text-transform:uppercase;color:#64748b;min-width:140px">Approval Rate</th></tr>' +
              '</thead>' +
              '<tbody>'+gradeRows+'</tbody>' +
            '</table>' +
          '</div>' +
          '<div class="d-flex gap-2 mt-3 justify-content-end flex-wrap">' +
            '<a class="btn btn-sm btn-outline-secondary" href="{{ route('superadmin.history.export', ['format' => 'pdf']) }}"><i class="bi bi-file-earmark-pdf me-1"></i>Export PDF</a>' +
            '<a class="btn btn-sm btn-outline-secondary" href="{{ route('superadmin.history.export', ['format' => 'csv']) }}"><i class="bi bi-filetype-csv me-1"></i>Export CSV</a>' +
          '</div>' +
        '</div>' +
      '</div>' +
    '</div>';
  }).join('') || '<div class="text-center text-muted py-5"><i class="bi bi-archive" style="font-size:40px"></i><div class="mt-2">No records for this school year.</div></div>';
}



/* Sidebar mobile toggle */
function openSidebar() {
  document.getElementById('leftSidebar').classList.add('open');
  document.getElementById('sbOverlay').classList.add('open');
}
function closeSidebar() {
  document.getElementById('leftSidebar').classList.remove('open');
  document.getElementById('sbOverlay').classList.remove('open');
}

/* ── Action menu (reuse admin  pattern) ── */
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

/* ── Table filter ── */
function filterTable(tableId, q) {
  document.querySelectorAll('#' + tableId + ' tbody tr').forEach(r => {
    r.style.display = r.textContent.toLowerCase().includes(q.toLowerCase()) ? '' : 'none';
  });
}

/* ── Shared API fetch helper (CSRF-aware) ──
   NOTE: the routes below are assumed REST endpoints — see the migration/route
   checklist provided alongside this file for the controller + routes to add. */
function apiFetch(url, method = 'GET', body = null) {
  const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
  return fetch(url, {
    method,
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'X-CSRF-TOKEN': token || '',
    },
    body: body ? JSON.stringify(body) : null,
  }).then(async res => {
    if (!res.ok) throw new Error(await res.text());
    return res.status === 204 ? null : res.json();
  });
}

/* ── Create Admin ── */
function openCreateAdminModal() { bsModal('createAdminModal').show(); }
function submitCreateAdmin() {
  const fname = document.getElementById('ca-fname').value.trim();
  const lname = document.getElementById('ca-lname').value.trim();
  const email = document.getElementById('ca-email').value.trim();
  const grades = Array.from(document.querySelectorAll('#ca-grades .ca-grade-check:checked')).map(c => c.value);
  const pass  = document.getElementById('ca-pass').value;
  const pass2 = document.getElementById('ca-pass2').value;
  if (!fname || !lname || !email || !pass) { alert('Please fill in all required fields.'); return; }
  if (pass !== pass2) { alert('Passwords do not match.'); return; }
  apiFetch('/superadmin/admins', 'POST', { first_name: fname, last_name: lname, email, assigned_grades: grades, password: pass }).then(() => {
    bootstrap.Modal.getInstance(document.getElementById('createAdminModal')).hide();
    setTimeout(() => { phlciToast(`Admin account created for ${fname} ${lname}!`, 'success'); location.reload(); }, 350);
  }).catch(() => alert('Could not create the admin account. The email may already be in use.'));
}

/* ── Edit Admin ── */
let _editAdminId = null;
function openEditAdminModal(btn) {
  _editAdminId = btn.dataset.adminId;
  document.getElementById('ea-name-label').textContent = btn.dataset.adminName;
  document.getElementById('ea-email').value = btn.dataset.adminEmail;
  const grades = JSON.parse(btn.dataset.adminGrades || '[]');
  document.querySelectorAll('#ea-grades .ea-grade-check').forEach(c => {
    c.checked = grades.includes(c.value);
    c.dispatchEvent(new Event('change'));
  });
  bsModal('editAdminModal').show();
}
function submitEditAdmin() {
  const email = document.getElementById('ea-email').value.trim();
  const grades = Array.from(document.querySelectorAll('#ea-grades .ea-grade-check:checked')).map(c => c.value);
  apiFetch(`/superadmin/admins/${_editAdminId}`, 'PUT', { email, assigned_grades: grades }).then(() => {
    bootstrap.Modal.getInstance(document.getElementById('editAdminModal')).hide();
    setTimeout(() => { phlciToast('Admin account updated successfully.', 'success'); location.reload(); }, 350);
  }).catch(() => alert('Could not update the admin account.'));
}

/* ── Admin actions ── */
function resetAdminPassword(id, name) {
  if (!confirm(`Reset password for ${name}? A temporary password will be generated.`)) return;
  apiFetch(`/superadmin/admins/${id}/reset-password`, 'POST').then(() => phlciToast(`Password reset for ${name}.`, 'success'))
    .catch(() => alert('Could not reset the password.'));
}
function deactivateAdmin(id, name) {
  if (!confirm(`Deactivate admin account for ${name}?`)) return;
  apiFetch(`/superadmin/admins/${id}/toggle-active`, 'POST', { is_active: false }).then(() => { phlciToast(`${name}'s account has been deactivated.`, 'success'); location.reload(); })
    .catch(() => alert('Could not deactivate this account.'));
}
function activateAdmin(id, name) {
  if (!confirm(`Activate admin account for ${name}?`)) return;
  apiFetch(`/superadmin/admins/${id}/toggle-active`, 'POST', { is_active: true }).then(() => { phlciToast(`${name}'s account has been activated.`, 'success'); location.reload(); })
    .catch(() => alert('Could not activate this account.'));
}

/* ── Enrollment toggle ── */
function toggleEnrollment() {
  const btn = document.getElementById('toggleEnrollBtn');
  const periodId = btn.dataset.periodId;
  if (!periodId) { alert('Set up an enrollment period first (Edit Period).'); return; }
  const willOpen = btn.dataset.open !== '1';
  apiFetch(`/superadmin/enrollment-period/${periodId}/toggle`, 'POST', { is_open: willOpen }).then(() => location.reload())
    .catch(() => alert('Could not update enrollment status.'));
}

/* ── Enrollment modal ── */
function openEnrollmentModal() { bsModal('enrollmentModal').show(); }
function saveEnrollmentPeriod() {
  const id    = document.getElementById('ep-id').value;
  const start = document.getElementById('ep-start').value;
  const end   = document.getElementById('ep-end').value;
  const sy    = document.getElementById('ep-sy').value;
  const payload = { start_date: start, end_date: end, school_year: sy };
  if (id) payload.id = id;
  apiFetch('/superadmin/enrollment-period', 'POST', payload).then(() => {
    bootstrap.Modal.getInstance(document.getElementById('enrollmentModal')).hide();
    setTimeout(() => { phlciToast('Enrollment period updated!', 'success'); location.reload(); }, 350);
  }).catch(() => alert('Could not update the enrollment period.'));
}

function saveEnrollmentSettings() {
  const grades = Array.from(document.querySelectorAll('.grade-toggle-input')).map(el => ({ grade: el.dataset.grade, is_open: el.checked }));
  apiFetch('/superadmin/grade-settings', 'PUT', { grades }).then(() => phlciToast('Grade settings saved!', 'success'))
    .catch(() => alert('Could not save grade settings.'));
}

function saveTuitionRates() {
  const fees = Array.from(document.querySelectorAll('.tuition-fee-input')).map(el => ({
    grade_level: el.dataset.grade,
    annual_amount: parseFloat(el.value) || 0,
  }));
  const enrollmentFee = parseFloat(document.getElementById('enrollmentFeeInput').value) || 0;
  apiFetch('{{ route("superadmin.tuition.gradeFees.update") }}', 'PUT', { fees, enrollment_fee: enrollmentFee })
    .then(() => phlciToast('Tuition rates saved!', 'success'))
    .catch(() => phlciToast('Could not save tuition rates. Please try again.', 'error'));
}

/* ═══ ANNOUNCEMENTS ═══ */
let _announcements = @json($announcementsJs);
function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str ?? '';
  return div.innerHTML;
}

function renderAnnouncements() {
  const list = document.getElementById('announcementsList');
  if (!list) return;
  if (!_announcements.length) {
    list.innerHTML = '<div class="text-center text-muted py-4"><i class="bi bi-megaphone" style="font-size:36px"></i><div class="mt-2">No announcements yet</div></div>';
    const banner = document.getElementById('activeAnnouncementBanner');
    if (banner) { banner.classList.add('d-none'); banner.classList.remove('d-flex'); }
    return;
  }
  list.innerHTML = _announcements.map(a => `
    <div class="card border rounded-3 p-3">
      <div class="d-flex align-items-start justify-content-between gap-2 flex-wrap">
        <div class="flex-grow-1">
          <div class="d-flex align-items-center gap-2 mb-1">
            <div class="fw-bold" style="font-size:14.5px;color:#1e293b">${escapeHtml(a.title)}</div>
            <span class="badge rounded-pill px-3" style="background:${a.status==='active'?'#dcfce7':'#f1f5f9'};color:${a.status==='active'?'#166534':'#64748b'};font-size:11px">${a.status==='active'?'● Active':'○ Inactive'}</span>
            ${a.popup ? '<span class="badge rounded-pill px-2" style="background:#ede9fe;color:#7c3aed;font-size:11px"><i class="bi bi-bell-fill"></i> Popup</span>' : ''}
          </div>
          <div class="text-muted mb-2" style="font-size:13px">${escapeHtml(a.message)}</div>
          <div class="text-muted" style="font-size:11.5px">${(a.from || a.until) ? `<i class="bi bi-calendar3 me-1"></i>${a.from || 'Anytime'} – ${a.until || 'Anytime'} &nbsp;&bull;&nbsp; ` : ''}Created by: ${escapeHtml(a.by)} &nbsp;&bull;&nbsp; ${a.date}</div>
        </div>
        <div class="d-flex gap-2 flex-shrink-0">
          <button class="btn btn-sm btn-outline-secondary" onclick="openEditAnnouncementModal(${a.id})" title="Edit"><i class="bi bi-pencil"></i></button>
          <button class="btn btn-sm btn-outline-danger" onclick="deleteAnnouncement(${a.id})" title="Delete"><i class="bi bi-trash"></i></button>
          <button class="btn btn-sm" style="background:${a.status==='active'?'#fee2e2':'#dcfce7'};color:${a.status==='active'?'#991b1b':'#166534'};border:none" onclick="toggleAnnStatus(${a.id})">${a.status==='active'?'Deactivate':'Activate'}</button>
        </div>
      </div>
    </div>`).join('');

  // Update active announcement banner
  const active = _announcements.find(a => a.status === 'active');
  const banner = document.getElementById('activeAnnouncementBanner');
  if (banner) {
    if (active) {
      banner.classList.remove('d-none');
      banner.classList.add('d-flex');
      document.getElementById('activeAnnTitle').textContent = active.title;
      document.getElementById('activeAnnMsg').textContent = active.message;
    } else {
      banner.classList.add('d-none');
      banner.classList.remove('d-flex');
    }
  }
}

function openCreateAnnouncementModal() {
  document.getElementById('ann-edit-id').value = '';
  document.getElementById('ann-title').value = '';
  document.getElementById('ann-message').value = '';
  annFromPicker.setDate('', true);
  annUntilPicker.setDate('', true);
  document.getElementById('ann-status').value = 'active';
  document.getElementById('ann-popup').checked = true;
  document.getElementById('annModalTitle').textContent = 'Create Announcement';
  document.getElementById('annSubmitLabel').textContent = 'Create Announcement';
  bsModal('createAnnouncementModal').show();
}

function openEditAnnouncementModal(id) {
  const a = _announcements.find(x => x.id === id);
  if (!a) return;
  document.getElementById('ann-edit-id').value = id;
  document.getElementById('ann-title').value = a.title;
  document.getElementById('ann-message').value = a.message;
  annFromPicker.setDate(a.from || '', true);
  annUntilPicker.setDate(a.until || '', true);
  document.getElementById('ann-status').value = a.status;
  document.getElementById('ann-popup').checked = a.popup;
  document.getElementById('annModalTitle').textContent = 'Edit Announcement';
  document.getElementById('annSubmitLabel').textContent = 'Save Changes';
  bsModal('createAnnouncementModal').show();
}

function submitAnnouncement() {
  const title   = document.getElementById('ann-title').value.trim();
  const message = document.getElementById('ann-message').value.trim();
  if (!title || !message) { alert('Title and message are required.'); return; }
  const editId = document.getElementById('ann-edit-id').value;
  const payload = {
    title, message,
    show_from:      document.getElementById('ann-from').value || null,
    show_until:     document.getElementById('ann-until').value || null,
    status:         document.getElementById('ann-status').value,
    show_as_popup:  document.getElementById('ann-popup').checked,
  };
  const url    = editId ? `/superadmin/announcements/${editId}` : '/superadmin/announcements';
  const method = editId ? 'PUT' : 'POST';
  apiFetch(url, method, payload).then(data => {
    if (!data) return;
    bootstrap.Modal.getInstance(document.getElementById('createAnnouncementModal')).hide();
    if (editId) {
      const idx = _announcements.findIndex(x => x.id === parseInt(editId));
      if (idx >= 0) _announcements[idx] = data;
    } else {
      _announcements.unshift(data);
    }
    renderAnnouncements();
    phlciToast(`Announcement ${editId ? 'updated' : 'created'} successfully!`, 'success');
  }).catch(() => alert('Could not save the announcement. Please try again.'));
}

function deleteAnnouncement(id) {
  if (!confirm('Delete this announcement?')) return;
  apiFetch(`/superadmin/announcements/${id}`, 'DELETE').then(() => {
    _announcements = _announcements.filter(a => a.id !== id);
    renderAnnouncements();
  }).catch(() => alert('Could not delete the announcement. Please try again.'));
}

function toggleAnnStatus(id) {
  apiFetch(`/superadmin/announcements/${id}/toggle-status`, 'POST').then(data => {
    if (!data) return;
    const a = _announcements.find(x => x.id === id);
    if (a) a.status = data.status;
    renderAnnouncements();
  }).catch(() => alert('Could not update the announcement status.'));
}

document.addEventListener('DOMContentLoaded', function() {
  renderAnnouncements();
  renderSYHistory('all');
  var savedTab = sessionStorage.getItem('saTab') || 'admins';
  switchSATab(savedTab, null);
});
</script>


<!-- ===================== MODAL: CREATE / EDIT ANNOUNCEMENT ===================== -->
<div class="modal fade" id="createAnnouncementModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered" style="max-width:540px">
    <div class="modal-content border-0 shadow-lg" style="border-radius:18px;overflow:hidden">
      <div style="background:linear-gradient(135deg,#7c3aed,#1e3a8a);padding:24px 28px 20px;position:relative">
        <button type="button" class="btn-close btn-close-white position-absolute top-0 end-0 m-3" data-bs-dismiss="modal"></button>
        <div class="d-flex align-items-center gap-3">
          <div style="width:44px;height:44px;border-radius:12px;background:rgba(255,255,255,.18);display:flex;align-items:center;justify-content:center;font-size:22px;color:#fff">
            <i class="bi bi-megaphone-fill"></i>
          </div>
          <div>
            <div style="font-size:17px;font-weight:800;color:#fff" id="annModalTitle">Create Announcement</div>
            <div style="font-size:12px;color:rgba(255,255,255,.7)">This will be shown as a popup to students on login</div>
          </div>
        </div>
      </div>
      <div class="modal-body p-4">
        <input type="hidden" id="ann-edit-id" value="">
        <div class="mb-3">
          <label class="form-label fw-medium" style="font-size:13px">Announcement Title *</label>
          <input type="text" class="form-control" placeholder="e.g., Enrollment is Now Open!" id="ann-title">
        </div>
        <div class="mb-3">
          <label class="form-label fw-medium" style="font-size:13px">Message *</label>
          <textarea class="form-control" rows="4" placeholder="Write the full announcement message here..." id="ann-message"></textarea>
        </div>
        <div class="row g-3 mb-3">
          <div class="col-6">
            <label class="form-label fw-medium" style="font-size:13px">Show From</label>
            <input type="text" class="form-control" placeholder="Select date" readonly autocomplete="off" id="ann-from">
          </div>
          <div class="col-6">
            <label class="form-label fw-medium" style="font-size:13px">Show Until</label>
            <input type="text" class="form-control" placeholder="Select date" readonly autocomplete="off" id="ann-until">
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label fw-medium" style="font-size:13px">Status</label>
          <select class="form-select" id="ann-status">
            <option value="active">Active (show to students)</option>
            <option value="inactive">Inactive (hidden)</option>
          </select>
        </div>
        <div class="form-check">
          <input class="form-check-input" type="checkbox" id="ann-popup" checked>
          <label class="form-check-label" for="ann-popup" style="font-size:13px">
            Show as popup when student logs in or creates account
          </label>
        </div>
      </div>
      <div class="modal-footer border-0 pt-0 px-4 pb-4">
        <button class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
        <button class="btn btn-sm fw-semibold px-4" style="background:#7c3aed;color:#fff" onclick="submitAnnouncement()">
          <i class="bi bi-check-circle me-1"></i><span id="annSubmitLabel">Create Announcement</span>
        </button>
      </div>
    </div>
  </div>
</div>


@endsection   