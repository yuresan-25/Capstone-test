{{-- Enrollment application form + Step 2 document upload + Enroll Now confirmation flow --}}
{{-- This is the SINGLE real script file for the entire enroll flow (resources/views/parent/scripts/enrollment-form.blade.php) --}}
<script>
// ── Student type toggle ────────────────────────────────────────────────────
var PHLCIStudentType = 'old';
// The draft (student_enrollment row with status='draft') currently being
// worked on in this session. Set the moment Step 1 first saves, or when
// resuming an existing draft via loadDraftIntoForm(). Step 2's upload calls
// (enrollment-requirements-script.blade.php) read this same variable.
var currentDraftId = null;

// Enrollment fee: 'online' (pay via PayMongo right after Enroll Now) or
// 'manual' (upload a receipt now). Online is only offered when PayMongo is set up.
var FEE_PAY_ONLINE_ENABLED = @json(\App\Support\PayMongo::enabled());
var feeMode = FEE_PAY_ONLINE_ENABLED ? 'online' : 'manual';
// Whether the saved draft already has a receipt on file (so re-saving
// without choosing a new file keeps it).
var currentDraftHasProof = false;

// Branded calendar picker for Date of Birth (replaces the browser's native
// <input type="date">, which renders inconsistently — plain system UI in
// Chrome, a different widget in Firefox/Safari — with one consistent look.
// Deferred to DOMContentLoaded so it's not order-dependent on where #f_birthday
// happens to sit relative to this script tag (bit us in the superadmin
// announcement pickers — same mistake, not repeating it here).
var birthdayPicker;
document.addEventListener('DOMContentLoaded', function () {
  // Youngest accepted grade is Kinder — a child must be at least 4 years old
  // by today to enroll anywhere in the school, so the picker can't even
  // select a birthdate that young. Mirrored server-side in
  // EnrollmentController (the actual enforcement point — this is just UX).
  var earliestAllowedBirthdate = new Date();
  earliestAllowedBirthdate.setFullYear(earliestAllowedBirthdate.getFullYear() - 4);

  birthdayPicker = flatpickr('#f_birthday', {
    dateFormat: 'Y-m-d',
    altInput: true,
    altFormat: 'F j, Y',
    maxDate: earliestAllowedBirthdate,
    minDate: '1990-01-01',
    disableMobile: true,
  });
});

// ── Local autosave (survives connectivity loss / accidental navigation) ────
// The server-side draft (student_enrollment status='draft') only exists once
// Step 1 is first saved — the real gap is everything typed BEFORE that first
// save. This mirrors it into localStorage on every change and restores it
// when starting a new enrollment. Stops once a server draft exists
// (currentDraftId set) since the server copy is authoritative from then on.
var PHLCI_AUTOSAVE_KEY = 'phlci_enroll_autosave_v1';
var PHLCI_AUTOSAVE_FIELDS = ['f_first_name','f_middle_name','f_last_name','f_suffix','f_lrn',
  'f_grade_level','f_birthday','f_birth_place','f_address','f_last_school','f_mother_name',
  'f_father_name','f_guardian_name','f_emergency_contact'];
var phlciAutosaveTimer = null;

function collectAutosaveData() {
  var data = { studentType: PHLCIStudentType, fields: {}, session: '', payMethod: '', paymentPlan: '' };
  PHLCI_AUTOSAVE_FIELDS.forEach(function (id) {
    var el = document.getElementById(id);
    if (el) data.fields[id] = el.value;
  });
  var session = document.querySelector('input[name="classSession"]:checked');
  if (session) data.session = session.value;
  data.payMethod = currentPayMethod();
  var plan = document.querySelector('input[name="paymentPlan"]:checked');
  if (plan) data.paymentPlan = plan.value;
  return data;
}

function isAutosaveDataBlank(data) {
  var hasField = Object.keys(data.fields).some(function (k) { return (data.fields[k] || '').trim() !== ''; });
  return !hasField && !data.session && !data.payMethod && !data.paymentPlan;
}

function scheduleAutosave() {
  if (currentDraftId) return; // a server draft already exists — that's authoritative now
  clearTimeout(phlciAutosaveTimer);
  phlciAutosaveTimer = setTimeout(function () {
    var data = collectAutosaveData();
    if (isAutosaveDataBlank(data)) { localStorage.removeItem(PHLCI_AUTOSAVE_KEY); return; }
    try { localStorage.setItem(PHLCI_AUTOSAVE_KEY, JSON.stringify(data)); }
    catch (e) { /* storage full/unavailable — this is a UX nicety, fail silently */ }
  }, 500);
}

function clearAutosavedDraft() {
  localStorage.removeItem(PHLCI_AUTOSAVE_KEY);
}

// Restores unsaved progress typed before the connection/tab was lost. Only
// called when starting a brand-new enrollment (resetPHLCIForm) — resuming an
// existing server-side draft (loadDraftIntoForm) always wins over this.
function restoreAutosavedDraft() {
  var raw;
  try { raw = localStorage.getItem(PHLCI_AUTOSAVE_KEY); } catch (e) { return; }
  if (!raw) return;
  var data;
  try { data = JSON.parse(raw); } catch (e) { localStorage.removeItem(PHLCI_AUTOSAVE_KEY); return; }
  if (isAutosaveDataBlank(data)) { localStorage.removeItem(PHLCI_AUTOSAVE_KEY); return; }

  switchStudentType(data.studentType || 'old');
  PHLCI_AUTOSAVE_FIELDS.forEach(function (id) {
    var el = document.getElementById(id);
    if (el && data.fields[id] !== undefined) el.value = data.fields[id];
  });
  if (data.fields.f_birthday && birthdayPicker) birthdayPicker.setDate(data.fields.f_birthday, true);
  if (data.session) {
    document.querySelectorAll('input[name="classSession"]').forEach(function (r) { r.checked = (r.value === data.session); });
  }
  if (data.payMethod) applyPayMethod(data.payMethod);
  if (data.paymentPlan) {
    document.querySelectorAll('#paymentPlanCards .pay-method-card').forEach(function (card) {
      var radio = card.querySelector('input[name="paymentPlan"]');
      if (radio && radio.value === data.paymentPlan) selectPaymentPlan(card, data.paymentPlan);
    });
  }

  showToast('success', 'We restored your unsaved progress from last time. Note: proof of payment can\'t be auto-saved — please re-attach it.');
}

// Delegated so every field is covered without individual listeners,
// including ones toggled visible/hidden (Old vs New student).
document.addEventListener('DOMContentLoaded', function () {
  var wrapper = document.getElementById('step1-form-wrapper');
  if (!wrapper) return;
  wrapper.addEventListener('input', scheduleAutosave);
  wrapper.addEventListener('change', scheduleAutosave);
});

function switchStudentType(type) {
  PHLCIStudentType = type;
  var newOnlyEls = document.querySelectorAll('.PHLCI-new-only');
  var btnOld = document.getElementById('btnOldStudent');
  var btnNew = document.getElementById('btnNewStudent');
  var label  = document.getElementById('formTypeLabel');
  if (type === 'new') {
    newOnlyEls.forEach(el => el.classList.remove('d-none'));
    btnNew.style.cssText = 'font-size:13px;background:#fff;color:#1a2a5e;border:2px solid #fff;border-radius:30px';
    btnOld.style.cssText = 'font-size:13px;background:transparent;color:#fff;border:2px solid rgba(255,255,255,.5);border-radius:30px';
    if (label) label.textContent = 'NEW STUDENT REGISTRATION FORM';
  } else {
    newOnlyEls.forEach(el => el.classList.add('d-none'));
    btnOld.style.cssText = 'font-size:13px;background:#fff;color:#b91c1c;border:2px solid #fff;border-radius:30px';
    btnNew.style.cssText = 'font-size:13px;background:transparent;color:#fff;border:2px solid rgba(255,255,255,.5);border-radius:30px';
    if (label) label.textContent = 'OLD STUDENT REGISTRATION FORM';
  }
}

// ── Enrollment fee: pay online vs. upload a receipt ────────────────────────
function selectFeeOption(mode) {
  if (mode === 'online' && !FEE_PAY_ONLINE_ENABLED) mode = 'manual';
  feeMode = mode;
  document.querySelectorAll('#feeOptionCards .fee-option').forEach(function (opt) {
    var active = opt.dataset.mode === mode;
    opt.classList.toggle('active', active);
    opt.querySelector('input').checked = active;
  });
  var online = document.getElementById('feeOnlineBlock');
  var manual = document.getElementById('feeManualBlock');
  if (online) online.classList.toggle('d-none', mode !== 'online');
  if (manual) manual.classList.toggle('d-none', mode !== 'manual');
}

// The payMethod value sent to the server: 'Online', or the chosen chip.
function currentPayMethod() {
  if (feeMode === 'online') return 'Online';
  var method = document.querySelector('#payMethodCards input[name="payMethod"]:checked');
  return method ? method.value : '';
}

// Restores a saved payMethod ('Online' or a chip value) into the form.
function applyPayMethod(value) {
  if (value === 'Online') { selectFeeOption('online'); return; }
  selectFeeOption('manual');
  document.querySelectorAll('#payMethodCards .pay-method-card').forEach(function (card) {
    var radio = card.querySelector('input[name="payMethod"]');
    if (radio && radio.value === value) selectPayMethod(card, value);
  });
}

// ── Payment method (receipt upload) ────────────────────────────────────────
// Scoped to #payMethodCards — the plan cards share the .pay-method-card
// class, and resetting every card here used to clear the plan highlight.
function selectPayMethod(card, method) {
  document.querySelectorAll('#payMethodCards .pay-method-card').forEach(c => { c.style.background='#fff'; c.style.borderColor='#e2e8f0'; c.style.boxShadow='none'; });
  card.style.background='#dbeafe'; card.style.borderColor='#2563eb'; card.style.boxShadow='inset 0 0 0 1px #2563eb';
  card.querySelector('input[name="payMethod"]').checked = true;
  var uploadStep = document.getElementById('proofUploadLabel');
  if (uploadStep) uploadStep.textContent = method === 'Cash' ? 'Upload the official receipt (or a photo of the OR number)' : 'Upload your receipt';
}

function selectPaymentPlan(card, plan) {
  document.querySelectorAll('#paymentPlanCards .pay-method-card').forEach(c => { c.style.background='#fff'; c.style.borderColor='#e2e8f0'; c.style.boxShadow='none'; });
  card.style.background='#dbeafe'; card.style.borderColor='#2563eb'; card.style.boxShadow='inset 0 0 0 1px #2563eb';
  card.querySelector('input[name="paymentPlan"]').checked = true;
}

function showPaymentFileName(input) {
  var el = document.getElementById('paymentFileName');
  if (el && input.files.length) {
    el.textContent = '\u2714 ' + input.files[0].name;
    var file = input.files[0];
    var preview = document.getElementById('payProofPreview');
    var img = document.getElementById('payProofImg');
    if (preview && img && file.type.startsWith('image/')) {
      var reader = new FileReader();
      reader.onload = e => { img.src = e.target.result; preview.style.display = 'block'; };
      reader.readAsDataURL(file);
    }
  }
}

// Images preview in-page via documentViewModal; PDFs still open in a new tab
// since a plain <img> can't render one.
function viewDocument(url, label) {
  if (/\.pdf($|\?)/i.test(url)) {
    window.open(url, '_blank', 'noopener');
    return;
  }
  document.getElementById('documentViewModalImg').src = url;
  document.getElementById('documentViewModalLabel').textContent = label || 'Document Preview';
  bootstrap.Modal.getOrCreateInstance(document.getElementById('documentViewModal')).show();
}

function removePaymentProof() {
  var input = document.querySelector('#proofUploadBlock input[type="file"]');
  if (input) input.value = '';

  var fn = document.getElementById('paymentFileName');
  if (fn) fn.textContent = '';

  var preview = document.getElementById('payProofPreview');
  var img = document.getElementById('payProofImg');
  if (preview) preview.style.display = 'none';
  if (img) img.src = '';
}

// NOTE: getCsrfToken() lives in navigation.blade.php (shared helper).

// ── Load an existing draft into the form ────────────────────────────────────
// Used both by resumeDraftEnrollment() (parent comes back later via the
// "Continue Enrollment" banner) and by the "Review / Edit" button on the
// Step 1 completed-banner (parent wants to double-check what they entered
// in the current session). Either way: fetch saved data, pre-fill, reveal
// Step 2 if it isn't already, mark currentDraftId.
function loadDraftIntoForm(enrollmentId) {
  fetch('/enrollment/' + enrollmentId, {
    headers: { 'Accept': 'application/json' },
  })
  .then(res => {
    if (!res.ok) { throw new Error('Failed to load enrollment data.'); }
    return res.json();
  })
  .then(data => {
    var e = data.enrollment;
    currentDraftId = e.id;

    switchStudentType(e.student_type || 'old');

    document.getElementById('f_first_name').value         = e.first_name || '';
    document.getElementById('f_middle_name').value        = e.middle_name || '';
    document.getElementById('f_last_name').value          = e.last_name || '';
    document.getElementById('f_suffix').value             = e.suffix || '';
    document.getElementById('f_lrn').value                = e.lrn || '';
    document.getElementById('f_grade_level').value        = e.grade_level || '';
    birthdayPicker.setDate(e.birthday || '', true);
    document.getElementById('f_birth_place').value        = e.birth_place || '';
    document.getElementById('f_address').value            = e.address || '';
    var lastSchoolEl = document.getElementById('f_last_school');
    if (lastSchoolEl) lastSchoolEl.value = e.last_school || '';
    document.getElementById('f_mother_name').value        = e.mother_name || '';
    document.getElementById('f_father_name').value        = e.father_name || '';
    document.getElementById('f_guardian_name').value      = e.guardian_name || '';
    document.getElementById('f_emergency_contact').value  = e.emergency_contact || '';

    document.querySelectorAll('input[name="classSession"]').forEach(r => {
      r.checked = (r.value === e.classSession);
    });

    currentDraftHasProof = !!e.hasProof;
    applyPayMethod(e.payMethod);

    document.querySelectorAll('#paymentPlanCards .pay-method-card').forEach(card => {
      var radio = card.querySelector('input[name="paymentPlan"]');
      if (radio && radio.value === e.paymentPlan) {
        selectPaymentPlan(card, e.paymentPlan);
      }
    });

    // Proof of payment file input is intentionally left empty — browsers
    // can't pre-fill file inputs. The parent must reselect a file to save
    // any change to Step 1; this is made clear via the hint text below.
    var proofInput = document.querySelector('#proofUploadBlock input[type="file"]');
    if (proofInput) proofInput.value = '';
    var fn = document.getElementById('paymentFileName');
    if (fn) fn.textContent = e.hasProof ? 'Your receipt is saved. Upload a new one only if you want to replace it.' : '';
    var preview = document.getElementById('payProofPreview');
    if (preview) preview.style.display = 'none';

    // Show the collapsed "Step 1 Complete" summary, same as right after a
    // fresh submit — resuming a draft should look like "this part is done",
    // not reopen a blank-looking fill-out form. The parent can still get to
    // the editable form via the banner's "Review / Edit" button, which is
    // now pre-filled with the values set above.
    var summaryEl = document.getElementById('step1-complete-summary');
    if (summaryEl) {
      summaryEl.textContent = (e.first_name + ' ' + e.last_name).trim() + ' · ' + e.grade_level + ' · ' + e.classSession + ' Session';
    }
    toggleStep1Form(false);

    // Reveal Step 2 and load its uploaded documents for this draft.
    revealStep2(currentDraftId, (e.first_name + ' ' + e.last_name).trim(), e.grade_level, e.student_type);
  })
  .catch(err => {
    console.error('Failed to load draft enrollment:', err);
    showToast('danger', 'Could not load this enrollment. Please try again.');
  });
}

// ── Submit (save) Step 1 ────────────────────────────────────────────────────
// Always saves as a draft. First save = POST (creates the draft row).
// Every save after that (e.g. clicking "Submit Registration" again after
// "Review / Edit") = PUT against the same draft row — never creates a
// second record for the same in-progress child.
function submitPHLCIForm() {
  var required = [
    {id:'f_first_name',label:'First Name'},{id:'f_last_name',label:'Last Name'},
    {id:'f_grade_level',label:'Incoming Grade Level'},{id:'f_birthday',label:'Date of Birth'},
    {id:'f_birth_place',label:'Birth Place'},{id:'f_address',label:'Complete Address'},
    {id:'f_mother_name',label:'Name of Mother'},{id:'f_father_name',label:'Name of Father'},
    {id:'f_guardian_name',label:'Guardian Name'},{id:'f_emergency_contact',label:'Emergency Contact'},
  ];
  var errors = [], firstErrorEl = null;
  required.forEach(f => {
    var el = document.getElementById(f.id);
    if (!el) return;
    if (!el.value.trim()) { el.classList.add('is-invalid'); errors.push(f.label); if (!firstErrorEl) firstErrorEl = el; }
    else { el.classList.remove('is-invalid'); }
  });
  var session = document.querySelector('input[name="classSession"]:checked');
  if (!session) { errors.push('Preferred Class Session'); document.querySelectorAll('input[name="classSession"]').forEach(r => r.closest('label').style.color = '#dc2626'); }
  else { document.querySelectorAll('input[name="classSession"]').forEach(r => r.closest('label').style.color = ''); }
  var lastSchoolEl = document.getElementById('f_last_school');
  if (lastSchoolEl && !lastSchoolEl.closest('.PHLCI-new-only').classList.contains('d-none') && !lastSchoolEl.value.trim()) {
    lastSchoolEl.classList.add('is-invalid'); errors.push('Last School Attended'); if (!firstErrorEl) firstErrorEl = lastSchoolEl;
  } else if (lastSchoolEl) { lastSchoolEl.classList.remove('is-invalid'); }
  var lrnEl = document.getElementById('f_lrn');
  if (lrnEl && lrnEl.value.trim() && !/^\d+$/.test(lrnEl.value.trim())) {
    lrnEl.classList.add('is-invalid'); errors.push('LRN (numbers only)'); if (!firstErrorEl) firstErrorEl = lrnEl;
  } else if (lrnEl) { lrnEl.classList.remove('is-invalid'); }
  var contactEl = document.getElementById('f_emergency_contact');
  if (contactEl && contactEl.value.trim() && !/^(09|\+639)\d{9}$/.test(contactEl.value.trim())) {
    contactEl.classList.add('is-invalid'); errors.push('Emergency Contact (must be e.g. 09171234567)'); if (!firstErrorEl) firstErrorEl = contactEl;
  }
  var plan = document.querySelector('input[name="paymentPlan"]:checked');
  if (!plan) { errors.push('Tuition Payment Plan'); }
  var payMethod = currentPayMethod();
  var proofInput = document.querySelector('#proofUploadBlock input[type="file"]');
  if (feeMode === 'manual') {
    if (!payMethod) { errors.push('How you paid the enrollment fee'); }
    // A receipt is needed unless this draft already has one saved.
    if (!currentDraftHasProof && (!proofInput || !proofInput.files.length)) { errors.push('Enrollment fee receipt'); }
  }

  if (errors.length > 0) {
    if (firstErrorEl) {
      firstErrorEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
      firstErrorEl.focus();
    }
    showToast('danger', 'Please fill in: <strong>' + errors[0] + '</strong>' + (errors.length > 1 ? ' and ' + (errors.length - 1) + ' other field(s).' : '.'));
    return;
  }

  ['f_middle_name','f_suffix','f_lrn'].forEach(id => { var el = document.getElementById(id); if (el && !el.value.trim()) el.value = 'N/A'; });

  var firstName  = document.getElementById('f_first_name').value.trim();
  var lastName   = document.getElementById('f_last_name').value.trim();
  var grade      = document.getElementById('f_grade_level').value;
  var sessionVal = session ? session.value : '';

  var formData = new FormData();
  formData.append('first_name', firstName);
  formData.append('middle_name', document.getElementById('f_middle_name').value.trim());
  formData.append('last_name', lastName);
  formData.append('suffix', document.getElementById('f_suffix').value.trim());
  formData.append('lrn', document.getElementById('f_lrn').value.trim());
  formData.append('grade_level', grade);
  formData.append('student_type', PHLCIStudentType);
  formData.append('birthday', document.getElementById('f_birthday').value);
  formData.append('birth_place', document.getElementById('f_birth_place').value.trim());
  formData.append('address', document.getElementById('f_address').value.trim());
  if (lastSchoolEl && !lastSchoolEl.closest('.PHLCI-new-only').classList.contains('d-none')) {
    formData.append('last_school', lastSchoolEl.value.trim());
  }
  formData.append('mother_name', document.getElementById('f_mother_name').value.trim());
  formData.append('father_name', document.getElementById('f_father_name').value.trim());
  formData.append('guardian_name', document.getElementById('f_guardian_name').value.trim());
  formData.append('emergency_contact', document.getElementById('f_emergency_contact').value.trim());
  formData.append('classSession', sessionVal);
  formData.append('payMethod', payMethod);
  formData.append('paymentPlan', plan ? plan.value : '');
  if (feeMode === 'manual' && proofInput && proofInput.files.length) {
    formData.append('proof_of_payment', proofInput.files[0]);
  }

  var submitBtn = document.querySelector('[onclick="submitPHLCIForm()"]');
  var isUpdating = !!currentDraftId;
  if (submitBtn) { submitBtn.disabled = true; submitBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving…'; }

  var url = isUpdating ? ('/enrollment/' + currentDraftId) : '{{ route("enrollment.store") }}';
  // Always POST — file uploads (multipart/form-data) aren't reliably sent via
  // a real PUT request in all server/proxy setups. For updates, POST with a
  // _method=PUT field instead; Laravel's middleware treats this as a real
  // PUT (standard Laravel "method spoofing" pattern, not a workaround).
  if (isUpdating) { formData.append('_method', 'PUT'); }

  fetch(url, {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': getCsrfToken(), 'Accept': 'application/json' },
    body: formData,
  })
  .then(res => {
    if (!res.ok) { return res.json().then(data => { throw data; }); }
    return res.json();
  })
  .then(data => {
    var saved = data.enrollment;
    currentDraftId = saved.id;
    currentDraftHasProof = feeMode === 'manual' && (currentDraftHasProof || !!(proofInput && proofInput.files.length));
    clearAutosavedDraft(); // now safely on the server — local copy no longer needed

    // Show the green "Step 1 complete" banner with a short summary, hide the form.
    var summaryEl = document.getElementById('step1-complete-summary');
    if (summaryEl) {
      summaryEl.textContent = saved.name + ' · ' + saved.grade + ' · ' + saved.session + ' Session';
    }
    toggleStep1Form(false);

    showToast('success', isUpdating
      ? 'Application updated.'
      : '<strong>' + saved.name + '</strong> — Step 1 saved! Now upload the required documents below.');

    revealStep2(currentDraftId, saved.name, saved.grade, PHLCIStudentType);
  })
  .catch(err => {
    console.error('Enrollment submit failed:', err);
    var msg = (err && err.message) ? err.message : 'Something went wrong while submitting. Please try again.';
    if (err && err.errors) {
      var firstField = Object.keys(err.errors)[0];
      msg = err.errors[firstField][0];
    }
    showToast('danger', msg);
  })
  .finally(() => {
    if (submitBtn) { submitBtn.disabled = false; submitBtn.innerHTML = '<i class="bi bi-send-fill me-1"></i> Submit Registration'; }
  });
}

// ── Reveal Step 2 + the fixed Enroll Now button ────────────────────────────
// Called after Step 1 first saves, after loading an existing draft, or when
// resuming. Hands off to setActiveEnrollment()/loadRequirementsPanel() in
// enrollment-requirements-script.blade.php for the actual document list.
function revealStep2(enrollmentId, name, grade, studentType) {
  var step2 = document.getElementById('enroll-step-requirements');
  if (step2) step2.classList.remove('d-none');
  var fixedBtn = document.getElementById('enrollNowFixedBtn');
  if (fixedBtn) fixedBtn.classList.remove('d-none');
  setActiveEnrollment(enrollmentId, name, grade, studentType);
  loadRequirementsPanel();
}

function resetPHLCIForm() {
  currentDraftId = null;
  ['f_first_name','f_middle_name','f_last_name','f_suffix','f_lrn','f_grade_level','f_birthday',
   'f_birth_place','f_address','f_last_school','f_mother_name','f_father_name','f_guardian_name','f_emergency_contact']
    .forEach(id => { var el = document.getElementById(id); if (el) { el.value=''; el.classList.remove('is-invalid'); } });
  document.querySelectorAll('input[name="classSession"]').forEach(r => r.checked = false);
  currentDraftHasProof = false;
  document.querySelectorAll('.pay-method-card').forEach(c => { c.style.background='#fff'; c.style.borderColor='#e2e8f0'; c.style.boxShadow='none'; });
  document.querySelectorAll('input[name="payMethod"], input[name="paymentPlan"]').forEach(r => r.checked = false);
  selectFeeOption(FEE_PAY_ONLINE_ENABLED ? 'online' : 'manual');
  var proofInput = document.querySelector('#proofUploadBlock input[type="file"]');
  if (proofInput) proofInput.value = '';
  var fn = document.getElementById('paymentFileName');
  if (fn) fn.textContent = '';
  var preview = document.getElementById('payProofPreview');
  if (preview) preview.style.display = 'none';
  var submitBtn = document.querySelector('[onclick="submitPHLCIForm()"]');
  if (submitBtn) submitBtn.innerHTML = '<i class="bi bi-send-fill me-1"></i> Submit Registration';
  switchStudentType('old');

  // Hide Step 2 + the fixed button, and show the editable Step 1 form again
  // (not the completed banner) — this is a fresh, brand-new child.
  toggleStep1Form(true);
  var step2 = document.getElementById('enroll-step-requirements');
  if (step2) step2.classList.add('d-none');
  var fixedBtn = document.getElementById('enrollNowFixedBtn');
  if (fixedBtn) fixedBtn.classList.add('d-none');

  // Pick up any unsaved progress left over from a dropped connection/closed
  // tab on this same child (blank fields above are overwritten if found).
  restoreAutosavedDraft();
}

// ============================================================
// Step 2: document upload (AJAX) + Enroll Now confirmation flow
// ============================================================

// ── Active enrollment context ──────────────────────────────────────────────
// Set by revealStep2() (enrollment-application-form-script.blade.php) right
// after Step 1 saves, or by loadDraftIntoForm() when resuming a draft.
var activeEnrollmentId = null;
var activeEnrollmentName = '';
var activeEnrollmentGrade = '';
var activeEnrollmentStudentType = 'new';

function setActiveEnrollment(id, name, grade, studentType) {
  activeEnrollmentId = id;
  activeEnrollmentName = name || '';
  activeEnrollmentGrade = grade || '';
  activeEnrollmentStudentType = studentType || 'new';
}

var ALL_DOC_TYPES = ['report_card', 'birth_certificate', 'good_moral', 'medical_certificate'];

// Mirrors EnrollmentController::requiredDocumentTypes() on the backend:
//   - Kinder: PSA Birth Certificate + Medical Certificate (no Report Card —
//     there's no prior school record for an incoming Kinder student)
//   - Grade 1+ (old or new student): Report Card + PSA Birth Certificate
function getRequiredDocTypes() {
  if (activeEnrollmentGrade === 'Kinder') return ['birth_certificate', 'medical_certificate'];
  return ['report_card', 'birth_certificate'];
}
function getOptionalDocTypes() {
  var required = getRequiredDocTypes();
  return ALL_DOC_TYPES.filter(t => !required.includes(t));
}

var DOC_LABELS = {
  birth_certificate:   'PSA Birth Certificate',
  report_card:         'Form 138 (Report Card)',
  good_moral:          'Good Moral Certificate',
  medical_certificate: 'Medical Certificate / Doctor\'s Assessment',
};

function buildDocRow(docType, accentColor, uploadedInfo) {
  var label    = DOC_LABELS[docType] || docType;
  if (docType !== 'birth_certificate' && getOptionalDocTypes().includes(docType)) label += ' (optional)';
  var uploaded = uploadedInfo && uploadedInfo[docType];
  var rowId    = 'doc-row-' + docType;
  var needsResubmit = uploaded && uploaded.status === 'needs_resubmit';
  var subInfo  = '<span class="text-muted" style="font-size:11px">PDF, JPG, PNG – Max 5 MB</span>';
  var feedbackHtml = '';
  var actionBtn;

  var viewBtn = '';

  if (needsResubmit) {
    subInfo = '<span class="fw-medium" style="font-size:11px;color:#991b1b"><i class="bi bi-exclamation-circle-fill me-1"></i>Needs Resubmit</span><span class="text-muted ms-2" style="font-size:11px">' + uploaded.uploaded_at + '</span>';
    if (uploaded.feedback) {
      feedbackHtml = '<div class="mt-2 p-2 rounded-2" style="background:#fef2f2;border:1px solid #fecaca;font-size:11.5px;color:#991b1b"><i class="bi bi-chat-left-text-fill me-1"></i>' + uploaded.feedback + '</div>';
    }
    actionBtn = '<label class="btn btn-sm fw-semibold me-1" style="background:#dc2626;color:#fff;font-size:12px;cursor:pointer" title="Resubmit file"><i class="bi bi-arrow-repeat me-1"></i>Resubmit<input type="file" accept=".pdf,.jpg,.jpeg,.png" style="display:none" onchange="handleDocUpload(this,\'' + docType + '\')"></label>';
  } else if (uploaded) {
    subInfo = '<span class="text-success fw-medium" style="font-size:11px"><i class="bi bi-check-lg me-1"></i>Uploaded</span><span class="text-muted ms-2" style="font-size:11px">' + uploaded.uploaded_at + '</span>';
    actionBtn = '<label class="btn btn-sm fw-semibold me-1" style="background:' + accentColor + ';color:#fff;font-size:12px;cursor:pointer" title="Replace file"><i class="bi bi-arrow-repeat me-1"></i>Replace<input type="file" accept=".pdf,.jpg,.jpeg,.png" style="display:none" onchange="handleDocUpload(this,\'' + docType + '\')"></label>';
  } else {
    actionBtn = '<label class="btn btn-sm fw-semibold" style="background:' + accentColor + ';color:#fff;font-size:12px;cursor:pointer"><i class="bi bi-upload me-1"></i>Upload<input type="file" accept=".pdf,.jpg,.jpeg,.png" style="display:none" onchange="handleDocUpload(this,\'' + docType + '\')"></label>';
  }

  if (uploaded && uploaded.url) {
    viewBtn = '<button type="button" onclick="viewDocument(\'' + uploaded.url + '\',\'' + label.replace(/'/g, "") + '\')" class="btn btn-sm btn-outline-secondary fw-semibold me-1" style="font-size:12px" title="View uploaded file"><i class="bi bi-eye me-1"></i>View</button>';
  }

  return '<div class="d-flex align-items-center justify-content-between p-3 rounded-2" id="' + rowId + '" style="background:#f8fafc;border:1px solid ' + (needsResubmit ? '#fecaca' : '#e2e8f0') + '"><div style="flex:1;min-width:0"><div class="fw-medium" style="font-size:13.5px;color:#1e293b">' + label + '</div><div class="mt-1">' + subInfo + '</div>' + feedbackHtml + '</div><div class="ms-2 flex-shrink-0 d-flex align-items-center"><div id="spinner-' + docType + '" class="spinner-border spinner-border-sm text-secondary d-none me-1" style="width:16px;height:16px"></div>' + viewBtn + actionBtn + '</div></div>';
}

// Keeps the latest fetched requirements in memory so the confirm modal can
// build its summary without an extra round-trip.
var lastFetchedRequirements = {};

function loadRequirementsPanel() {
  if (!activeEnrollmentId) {
    console.error('loadRequirementsPanel called with no activeEnrollmentId set.');
    return;
  }
  var listEl = document.getElementById('req-doc-list');
  var loadEl = document.getElementById('req-loading');
  listEl.innerHTML = '';
  loadEl.classList.remove('d-none');

  fetch('{{ route("requirements.index") }}?enrollment_id=' + encodeURIComponent(activeEnrollmentId), {
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
  })
  .then(r => r.json())
  .then(data => {
    loadEl.classList.add('d-none');
    var uploaded = data.requirements || {};
    lastFetchedRequirements = uploaded;
    getRequiredDocTypes().concat(getOptionalDocTypes()).forEach(doc => {
      listEl.innerHTML += buildDocRow(doc, '#1a2a5e', uploaded);
    });
    updateEnrollNowButton(uploaded);
  })
  .catch(() => {
    loadEl.classList.add('d-none');
    getRequiredDocTypes().concat(getOptionalDocTypes()).forEach(doc => { listEl.innerHTML += buildDocRow(doc, '#1a2a5e', {}); });
  });
}

function handleDocUpload(input, docType) {
  if (!input.files || !input.files[0]) return;
  if (!activeEnrollmentId) {
    showToast('danger', 'No active enrollment selected. Please save Step 1 first.');
    return;
  }
  var file    = input.files[0];
  var spinner = document.getElementById('spinner-' + docType);
  if (spinner) spinner.classList.remove('d-none');

  var formData = new FormData();
  formData.append('document_type', docType);
  formData.append('enrollment_id', activeEnrollmentId);
  formData.append('file', file);

  fetch('{{ route("requirements.upload") }}', {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    body: formData,
  })
  .then(r => r.json())
  .then(data => {
    if (spinner) spinner.classList.add('d-none');
    if (data.record) {
      loadRequirementsPanel();
      if (docType === 'medical_certificate' && document.getElementById('sn-doc-row')) loadSpecialNeedsPanel();
      showToast('success', data.record.document_label + ' uploaded successfully.');
    } else {
      showToast('danger', data.message || 'Upload failed.');
    }
  })
  .catch(() => { if (spinner) spinner.classList.add('d-none'); showToast('danger', 'An error occurred. Please try again.'); });
}

// ── Child photo upload (My Children panel avatar) ───────────────────────────
function handleChildPhotoUpload(input, enrollmentId) {
  if (!input.files || !input.files[0]) return;
  var avatar = input.closest('.stu-profile-avatar');

  var formData = new FormData();
  formData.append('photo', input.files[0]);

  fetch('/children/' + enrollmentId + '/photo', {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    body: formData,
  })
  .then(r => r.json())
  .then(data => {
    if (data.success) {
      if (avatar) {
        var img = avatar.querySelector('img');
        if (!img) {
          img = document.createElement('img');
          avatar.insertBefore(img, avatar.firstChild);
          avatar.childNodes.forEach(n => { if (n.nodeType === 3 && n.textContent.trim()) n.textContent = ''; });
        }
        img.src = data.url;
      }
      showToast('success', data.message || 'Photo updated.');
    } else {
      showToast('danger', data.message || 'Upload failed.');
    }
    input.value = '';
  })
  .catch(() => { showToast('danger', 'An error occurred. Please try again.'); input.value = ''; });
}

// ── Enroll Now button state ─────────────────────────────────────────────────
// Enables/disables the FIXED button (lives outside the steps — see
// enrollment-form.blade.php) based on whether both required docs are present.
function updateEnrollNowButton(uploaded) {
  var btn  = document.getElementById('enrollNowBtn');
  var hint = document.getElementById('enrollNowHint');
  if (!btn) return;

  var missing = getRequiredDocTypes().filter(t => !uploaded[t]);

  if (missing.length === 0) {
    btn.disabled = false;
    btn.style.opacity = '1';
    if (hint) hint.textContent = '';
  } else {
    btn.disabled = true;
    btn.style.opacity = '.5';
    if (hint) {
      var missingLabels = missing.map(t => DOC_LABELS[t] || t).join(', ');
      hint.textContent = 'Missing: ' + missingLabels;
    }
  }
}

// ── Enroll Now → confirmation modal ─────────────────────────────────────────
// Builds the compact review summary (name, grade, session, payment method,
// documents uploaded) and shows it in #enrollConfirmModal before finalizing.
function showEnrollConfirmModal() {
  if (!activeEnrollmentId) return;

  fetch('/enrollment/' + activeEnrollmentId, { headers: { 'Accept': 'application/json' } })
    .then(res => res.json())
    .then(data => {
      var e = data.enrollment;
      var uploadedTypes = getRequiredDocTypes().concat(getOptionalDocTypes()).filter(t => lastFetchedRequirements[t]);
      var docList = uploadedTypes.length
        ? uploadedTypes.map(t => DOC_LABELS[t] || t).join(', ')
        : 'None';

      var summaryEl = document.getElementById('enrollConfirmSummary');
      if (summaryEl) {
        summaryEl.innerHTML =
          '<div class="d-flex justify-content-between"><span class="text-muted">Student</span><span class="fw-semibold">' + e.name + '</span></div>' +
          '<div class="d-flex justify-content-between"><span class="text-muted">Grade Level</span><span class="fw-semibold">' + e.grade_level + '</span></div>' +
          '<div class="d-flex justify-content-between"><span class="text-muted">Session</span><span class="fw-semibold">' + e.classSession + '</span></div>' +
          '<div class="d-flex justify-content-between"><span class="text-muted">Enrollment Fee</span><span class="fw-semibold text-end">' +
            (e.payMethod === 'Online'
              ? 'Pay online next' + (e.enrollmentFee ? ' — ₱' + Number(e.enrollmentFee).toLocaleString('en-US', {minimumFractionDigits: 2}) : '')
              : 'Paid via ' + e.payMethod + ' (receipt uploaded)') +
          '</span></div>' +
          '<div class="d-flex justify-content-between"><span class="text-muted">Documents</span><span class="fw-semibold text-end" style="max-width:60%">' + docList + '</span></div>';
      }

      var confirmBtn = document.getElementById('enrollConfirmBtn');
      if (confirmBtn) {
        confirmBtn.innerHTML = e.payMethod === 'Online'
          ? '<i class="bi bi-lock-fill me-1"></i>Confirm &amp; Pay Online'
          : '<i class="bi bi-check-circle-fill me-1"></i>Confirm &amp; Enroll';
      }

      var modalEl  = document.getElementById('enrollConfirmModal');
      var instance = bootstrap.Modal.getInstance(modalEl);
      if (!instance) instance = new bootstrap.Modal(modalEl);
      instance.show();
    })
    .catch(() => showToast('danger', 'Could not load enrollment summary. Please try again.'));
}

function confirmFinalizeEnrollment() {
  if (!activeEnrollmentId) return;

  var confirmBtn = document.getElementById('enrollConfirmBtn');
  if (confirmBtn) { confirmBtn.disabled = true; confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Enrolling…'; }

  fetch('/enrollment/' + activeEnrollmentId + '/finalize', {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': getCsrfToken(), 'Accept': 'application/json' },
  })
  .then(res => res.json().then(data => ({ ok: res.ok, data })))
  .then(({ ok, data }) => {
    if (ok && data.pay_online) {
      // Chose "pay online" — go straight to PayMongo for the enrollment fee.
      if (confirmBtn) confirmBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Opening payment…';
      startEnrollmentFeePayment(data.pay_online.payment_id, data.pay_online.amount);
      return;
    }
    if (ok) {
      var modalEl  = document.getElementById('enrollConfirmModal');
      var instance = bootstrap.Modal.getInstance(modalEl);
      if (instance) instance.hide();

      showToast('success', data.message || 'Enrollment complete! Waiting for admin review.');
      setTimeout(function() { window.location.reload(); }, 900); // reload so the new card appears on Home
    } else {
      showToast('danger', data.message || 'Something went wrong.');
      if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Confirm & Enroll'; }
    }
  })
  .catch(() => {
    showToast('danger', 'Something went wrong. Please try again.');
    if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i>Confirm & Enroll'; }
  });
}

// Opens PayMongo checkout for the full enrollment fee. Used right after
// "Enroll Now" and by the "Pay enrollment fee" button on a Home card (for a
// parent who closed the checkout without paying). If it can't open, the
// application is still saved — reload so the Home card offers a retry.
function startEnrollmentFeePayment(paymentId, amount, btn) {
  if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Opening…'; }

  fetch('/tuition/payments/' + paymentId + '/paymongo-checkout', {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': getCsrfToken(), 'Accept': 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify({ amount: amount }),
  })
  .then(res => res.json().then(data => ({ ok: res.ok, data })))
  .then(({ ok, data }) => {
    if (ok && data.checkout_url) { window.location.href = data.checkout_url; return; }
    throw new Error(data.message || '');
  })
  .catch(err => {
    showToast('danger', (err && err.message) || 'Could not open the payment page. You can pay the enrollment fee from your child\'s card on Home.');
    setTimeout(function () { window.location.reload(); }, 2500);
  });
}

document.addEventListener('DOMContentLoaded', function () {
  selectFeeOption(feeMode);
});
</script>
