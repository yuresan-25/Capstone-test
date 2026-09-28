{{-- Panel & sub-panel navigation: showPanel, home sub-panels, enroll flow, child/tuition tab switching --}}
<script>
// ── CSRF helper ────────────────────────────────────────────────────────────
// Shared by every script that makes a fetch() POST (enrollment form,
// requirements upload, finalize). Defined here since navigation.blade.php
// is the foundational script every other enroll-related script depends on.
// Assumes the standard Laravel Blade layout has:
//   <meta name="csrf-token" content="{{ csrf_token() }}">
// in <head>. Update the selector below if your layout names it differently.
function getCsrfToken() {
  var meta = document.querySelector('meta[name="csrf-token"]');
  return meta ? meta.getAttribute('content') : '';
}

// ── Panel navigation ───────────────────────────────────────────────────────
function showPanel(panelId) {
  document.querySelectorAll('.panel-section').forEach(p => p.classList.add('d-none'));
  document.getElementById('panel-' + panelId).classList.remove('d-none');
  if (panelId !== 'home') { hideHomeSubPanel(); }
  document.querySelectorAll('.sidebar-nav-btn').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.panel === panelId);
  });
  const oc = document.getElementById('studentSidebar');
  if (oc && bootstrap && bootstrap.Offcanvas.getInstance(oc)) {
    bootstrap.Offcanvas.getInstance(oc).hide();
  }
  if (panelId === 'tuition-payments') {
    var visiblePane = document.querySelector('.child-tuition-pane:not(.d-none)');
    // Refetch on every visit so a payment verified by the admin meanwhile shows up.
    if (visiblePane) loadTuitionPane(visiblePane.id.replace('tuition-pane-', ''), visiblePane.dataset.enrollmentId);
    loadTuitionHistory();
  }
}

// ── Home sub-panel (enrollment flow) ──────────────────────────────────────
function showHomeSubPanel(subId) {
  document.getElementById('home-main-view').classList.add('d-none');
  document.querySelectorAll('.home-sub-panel').forEach(p => p.classList.add('d-none'));
  var el = document.getElementById('home-sub-' + subId);
  if (el) el.classList.remove('d-none');
}

function hideHomeSubPanel() {
  document.querySelectorAll('.home-sub-panel').forEach(p => p.classList.add('d-none'));
  document.getElementById('home-main-view').classList.remove('d-none');
}

// ── Cancel out of the enroll flow entirely ─────────────────────────────────
// Used by the "Back"/"Cancel" buttons. Just navigates back to Home — does
// NOT delete the draft (if one exists), so the parent can resume later via
// the "Continue Enrollment" banner.
function cancelEnrollment() {
  hideHomeSubPanel();
}

// ── Step 1 ⇄ completed-banner toggle ───────────────────────────────────────
// After Step 1 is saved, the form is replaced by a green "complete" banner.
// Clicking "Review / Edit" on that banner calls this with show=true to swap
// back to the editable form (still the same draft row — PUT, not a new one).
function toggleStep1Form(showForm) {
  var formWrapper = document.getElementById('step1-form-wrapper');
  var banner      = document.getElementById('step1-complete-banner');
  if (!formWrapper || !banner) return;
  if (showForm) {
    formWrapper.classList.remove('d-none');
    banner.classList.add('d-none');
  } else {
    formWrapper.classList.add('d-none');
    banner.classList.remove('d-none');
  }
}

// ── Open child profile from dashboard card ─────────────────────────────────
// Switches to the "My Children" panel and selects the pane for this specific
// child, looked up by their real student_enrollment.id (not array position).
function openChildProfile(childId) {
  showPanel('my-children');
  switchChildProfileTab(childId);
}

// ── Resume an unfinished draft ──────────────────────────────────────────────
// Called from the "Continue Enrollment" banner on Home (see main-view.blade.php).
// Opens the enroll flow and loads that draft's saved Step 1 data + reveals
// Step 2, picking up right where the parent left off.
function resumeDraftEnrollment(enrollmentId) {
  showHomeSubPanel('enrollment-form');
  loadDraftIntoForm(enrollmentId); // defined in enrollment-application-form-script.blade.php
}

// ── Delete an enrollment (draft or pending) ─────────────────────────────────
// Only shown/allowed while status is 'draft' or 'pending' (enforced
// server-side too — the button itself is hidden in the blade once status
// changes, but the backend re-checks in case of stale page state).
function deleteEnrollment(childId, childName) {
  if (!confirm('Delete the enrollment for "' + childName + '"? This cannot be undone — all uploaded documents and proof of payment will be permanently removed.')) {
    return;
  }

  fetch('/enrollment/' + childId, {
    method: 'DELETE',
    headers: { 'X-CSRF-TOKEN': getCsrfToken(), 'Accept': 'application/json' },
  })
  .then(res => res.json().then(data => ({ ok: res.ok, data })))
  .then(({ ok, data }) => {
    if (ok) {
      showToast('success', data.message || 'Enrollment deleted.');
      setTimeout(function() { window.location.reload(); }, 700);
    } else {
      showToast('danger', data.message || 'Unable to delete this enrollment.');
    }
  })
  .catch(() => showToast('danger', 'Something went wrong. Please try again.'));
}

// ── Child profile tab switcher ─────────────────────────────────────────────
// Panes and tab buttons are rendered with data-child-id (see
// panel-my-children.blade.php). We look up by that attribute instead of
// array index so this works correctly no matter how many children exist
// or what order they're rendered in.
function switchChildProfileTab(childId) {
  // Toggle active class on tab buttons
  document.querySelectorAll('.child-profile-tab').forEach(btn => {
    btn.classList.toggle('active', btn.dataset.childId == childId);
  });

  // Show the matching pane, hide the rest
  document.querySelectorAll('.child-profile-pane').forEach(pane => {
    pane.classList.toggle('d-none', pane.dataset.childId != childId);
  });
}

function showLockedToast() {
  showToast('info', 'This section unlocks once your child\'s enrollment has been approved by the administrator.');
}
</script>