{{-- Tuition & Payments panel logic: loads each child's installment schedule
     (down payment + monthly/quarterly installments), lets the parent submit
     proof of payment for any unpaid/needs-resubmit installment — including
     ahead of its due date — and renders the combined payment history table.
     Shares getCsrfToken()/showToast() with the rest of the parent scripts. --}}
<script>
var tuitionPaymentBeingSubmitted = null; // payment id currently open in the modal
var tuitionPaneIndexBeingSubmitted = null; // which child pane to refresh after submit
var tuitionRemainingBeingSubmitted = 0; // remaining balance on that installment, caps the amount input

// Online payments through PayMongo — off (upload-only) until the secret key is set.
var PAYMONGO_ENABLED = @json(\App\Support\PayMongo::enabled());
var PAYMONGO_MIN_AMOUNT = @json((float) config('services.paymongo.min_amount'));
var submitPaymentMode = PAYMONGO_ENABLED ? 'online' : 'manual';

function setSubmitPaymentMode(mode) {
  submitPaymentMode = mode;
  document.querySelectorAll('#submitPaymentModeTabs .tp-tab').forEach(function (tab) {
    var active = tab.dataset.mode === mode;
    tab.classList.toggle('active', active);
    tab.setAttribute('aria-selected', active);
  });
  document.getElementById('submitPaymentOnlineFields').classList.toggle('d-none', mode !== 'online');
  document.getElementById('submitPaymentManualFields').classList.toggle('d-none', mode !== 'manual');
  document.getElementById('submitPaymentBtn').innerHTML = mode === 'online'
    ? '<i class="bi bi-lock-fill me-1"></i>Continue to Payment'
    : '<i class="bi bi-send me-1"></i>Submit';
  document.getElementById('submitPaymentError').classList.add('d-none');
}

// Status of one individual proof submission.
var PROOF_STATUS_BADGE = {
  pending:  '<span class="tp-pill tp-pill-pending">Pending Verification</span>',
  verified: '<span class="tp-pill tp-pill-paid">Verified</span>',
  rejected: '<span class="tp-pill tp-pill-rejected">Needs Resubmit</span>',
};

function installmentLabel(p) {
  return p.installment_number === 0 ? 'Upon Enrollment (Down Payment)' : 'Installment ' + p.installment_number;
}

function peso(n) {
  return '₱' + Number(n || 0).toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// Admin feedback, names, etc. go into innerHTML below — escape them.
function esc(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
  });
}

// For values placed inside a single-quoted JS string in an onclick="" attribute.
function jsArg(s) {
  return esc(String(s).replace(/\\/g, '\\\\').replace(/'/g, "\\'"));
}

function methodTag(label) {
  if (!label) return '';
  var key = /gcash/i.test(label) ? 'gcash' : /maya/i.test(label) ? 'maya' : /bank/i.test(label) ? 'bank' : /card/i.test(label) ? 'card' : 'cash';
  return '<span class="tp-method tp-method-' + key + '">' + esc(label) + '</span>';
}

// The installment's headline status. A submitted-but-unreviewed proof
// outranks unpaid/partial, so the parent sees their payment is in review.
function installmentPill(p) {
  if (p.status === 'paid') return '<span class="tp-pill tp-pill-paid">Paid</span>';
  if (p.pending_amount > 0) return '<span class="tp-pill tp-pill-pending">Pending Verification</span>';
  if (p.status === 'partial') return '<span class="tp-pill tp-pill-partial">Partially Paid</span>';
  if (p.is_overdue) return '<span class="tp-pill tp-pill-overdue">Overdue</span>';
  return '<span class="tp-pill tp-pill-unpaid">Unpaid</span>';
}

// What's still payable once pending submissions are accounted for.
function openBalance(p) {
  return Math.max(0, Math.round((p.remaining_balance - (p.pending_amount || 0)) * 100) / 100);
}

function payButton(p, index, cls, text) {
  return '<button type="button" class="' + cls + '" onclick="openSubmitPaymentModal(' + p.id + ', \'' + jsArg(installmentLabel(p)) + '\', ' + index + ', ' + openBalance(p) + ')">' + text + '</button>';
}

function viewButton(url, title) {
  return '<button type="button" class="btn btn-outline-secondary btn-sm" style="font-size:12px" onclick="viewDocument(\'' + jsArg(url) + '\', \'' + jsArg(title) + '\')"><i class="bi bi-eye me-1"></i>View</button>';
}

function receiptButton(url) {
  return '<a class="btn btn-outline-secondary btn-sm" style="font-size:12px" href="' + esc(url) + '" target="_blank" rel="noopener"><i class="bi bi-receipt me-1"></i>Receipt</a>';
}

// View (uploaded image, if any) + Receipt (once verified). Online payments
// have no image — PayMongo's confirmation is the proof.
function proofActions(url, receiptUrl, title) {
  return (url ? viewButton(url, title) : '') + (receiptUrl ? receiptButton(receiptUrl) : '');
}

function monthYear(dateStr) {
  var d = new Date(dateStr);
  return isNaN(d) ? '' : d.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
}

function loadTuitionPane(index, enrollmentId) {
  var loadingEl = document.getElementById('tuition-loading-' + index);
  var contentEl = document.getElementById('tuition-content-' + index);

  loadingEl.classList.remove('d-none');
  contentEl.classList.add('d-none');

  fetch('{{ route("tuition.show") }}?enrollment_id=' + encodeURIComponent(enrollmentId), {
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
  })
  .then(function (r) { return r.json(); })
  .then(function (data) {
    contentEl.innerHTML = renderTuitionPane(data, index);
    loadingEl.classList.add('d-none');
    contentEl.classList.remove('d-none');
  })
  .catch(function (err) {
    console.error('Failed to load tuition plan:', err);
    loadingEl.classList.add('d-none');
    contentEl.classList.remove('d-none');
    contentEl.innerHTML = '<div class="text-danger text-center py-4" style="font-size:13px">Could not load billing information. Please refresh the page.</div>';
  });
}

function renderTuitionPane(data, index) {
  if (!data.plan) {
    return '<div class="text-muted text-center py-4" style="font-size:13px">No tuition plan has been set up for this child yet.</div>';
  }

  var plan = data.plan;
  var payments = data.payments || [];
  var installments = payments.filter(function (p) { return p.installment_number > 0; });
  var downPayment = payments.find(function (p) { return p.installment_number === 0; });
  var isQuarterly = plan.plan_type === 'quarterly';
  var cycle = isQuarterly ? 'quarter' : 'month';

  var planTitle = (isQuarterly ? 'Quarterly' : 'Monthly') + ' Installment Plan (' + installments.length + ' ' + cycle + (installments.length === 1 ? '' : 's') + ')';
  var planSub = 'Tuition balance split into ' + installments.length + ' ' + cycle + 'ly payments';
  if (installments.length) {
    planSub += ', from ' + monthYear(installments[0].due_date) + ' to ' + monthYear(installments[installments.length - 1].due_date);
  }

  var percent = plan.total_amount > 0 ? Math.min(100, Math.round(plan.total_paid / plan.total_amount * 100)) : 0;
  // "Pay Next Due" = the earliest installment that still has an open balance.
  var nextDue = payments.find(function (p) { return openBalance(p) > 0; });

  var html = '';

  // ── Summary ──
  html += '<div class="tp-card tp-summary mb-4">';
  html += '  <div class="d-flex justify-content-between align-items-start flex-wrap gap-3">';
  html += '    <div style="min-width:0;flex:1 1 280px">';
  html += '      <div class="d-flex align-items-center gap-2 flex-wrap"><span class="tp-pill tp-pill-plan">Enrolled Plan</span>' + (plan.school_year ? '<span style="font-size:12px;color:#94a3b8">SY ' + esc(plan.school_year) + '</span>' : '') + '</div>';
  html += '      <div class="tp-plan-title">' + planTitle + '</div>';
  html += '      <div class="tp-plan-sub">' + planSub + '</div>';
  html += '    </div>';
  html += '    <div class="tp-balance">';
  html += '      <div><div class="tp-balance-label">Remaining Balance</div><div class="tp-balance-amount">' + peso(plan.remaining_balance) + '</div></div>';
  if (nextDue) {
    html +=      payButton(nextDue, index, 'tp-btn-gold', 'Pay Next Due <i class="bi bi-chevron-right ms-1"></i>');
  } else if (plan.remaining_balance <= 0) {
    html += '      <span class="tp-pill tp-pill-paid"><i class="bi bi-check-circle-fill"></i>Fully Paid</span>';
  }
  html += '    </div>';
  html += '  </div>';

  html += '  <div class="tp-stats">';
  html += '    <div class="tp-stat"><div class="tp-stat-label">Total Tuition</div><div class="tp-stat-value">' + peso(plan.total_amount) + '</div><div class="tp-stat-note">Full school-year fee</div></div>';

  var dpVerified = downPayment && downPayment.status === 'paid';
  var dpVerifiedProof = downPayment && (downPayment.proofs || []).find(function (pr) { return pr.status === 'verified'; });
  html += '    <div class="tp-stat"><div class="tp-stat-label">Down Payment' + (downPayment ? ' ' + (dpVerified ? '<span class="tp-pill tp-pill-paid"><i class="bi bi-check-lg"></i>Verified</span>' : installmentPill(downPayment)) : '') + '</div>';
  html += '      <div class="tp-stat-value">' + peso(plan.down_payment) + '</div>';
  html += '      <div class="tp-stat-note">' + (dpVerifiedProof && dpVerifiedProof.verified_at ? 'Cleared ' + esc(dpVerifiedProof.verified_at.replace(/\s+\d{1,2}:\d{2}\s*[AP]M$/, '')) : 'Paid upon enrollment') + '</div></div>';

  html += '    <div class="tp-stat"><div class="tp-stat-label">Paid to Date (Verified) <span class="tp-pill tp-pill-paid">' + percent + '% Completed</span></div>';
  html += '      <div class="tp-stat-value" style="color:#16a34a">' + peso(plan.total_paid) + '</div>';
  html += '      <div class="tp-progress" role="progressbar" aria-valuenow="' + percent + '" aria-valuemin="0" aria-valuemax="100"><span style="width:' + percent + '%"></span></div></div>';
  html += '  </div>';
  html += '</div>';

  // ── Installment breakdown ──
  html += '<div class="tp-card">';
  html += '  <div class="tp-card-head">';
  html += '    <div><div class="tp-card-title">Installment Breakdown &amp; Dues</div><div class="tp-card-sub">Track verified payments, partial credits, and upcoming due dates</div></div>';
  if (installments.length) {
    html += '  <span class="tp-pill tp-pill-soft">Standard Installment: <strong class="ms-1" style="color:var(--text-dark)">' + peso(installments[0].amount_due) + '</strong>&nbsp;/ ' + cycle + '</span>';
  }
  html += '  </div>';

  payments.forEach(function (p) {
    html += (p.proofs && p.proofs.length) ? renderInstallmentDetail(p, index) : renderInstallmentRow(p, index);
  });

  html += '</div>';

  html += '<div class="d-flex align-items-start gap-2 mt-3 p-3 rounded-3" style="background:#eff6ff;border:1px solid #bfdbfe;font-size:12.5px;color:#1e40af">' +
    '<i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>' +
    '<span>Every proof of payment you upload is checked by the school before it counts toward your balance.</span></div>';

  return html;
}

// An installment the parent has already submitted something for — shown
// expanded, with each submitted proof listed underneath.
function renderInstallmentDetail(p, index) {
  var open = openBalance(p);
  var isPartial = p.status === 'partial' && !(p.pending_amount > 0);

  var meta = 'Due ' + esc(p.due_date) + ' &bull; Amount due: ' + peso(p.amount_due);
  if (p.status !== 'paid' && p.verified_amount > 0) {
    meta += ' &bull; <span class="warn">' + peso(p.remaining_balance) + ' remaining</span>';
  } else if (p.status !== 'paid' && p.pending_amount > 0) {
    meta += ' &bull; ' + peso(p.pending_amount) + ' submitted for review';
  }
  if (p.verified_amount > p.amount_due) {
    meta += ' &bull; includes ' + peso(p.verified_amount - p.amount_due) + ' advance credit';
  }

  var html = '<div class="tp-inst' + (isPartial ? ' is-partial' : '') + '">';
  html += '<div class="d-flex justify-content-between align-items-start flex-wrap gap-2">';
  html += '  <div style="min-width:0">';
  html += '    <div class="d-flex align-items-center gap-2 flex-wrap"><span class="tp-inst-title">' + installmentLabel(p) + '</span>' + installmentPill(p) + '</div>';
  html += '    <div class="tp-inst-meta">' + meta + '</div>';
  html += '  </div>';
  html += '  <div class="d-flex align-items-center gap-3">';
  if (p.verified_amount > 0) {
    html += '  <div><div class="tp-inst-total-label">Total Verified</div><div class="tp-inst-total">' + peso(p.verified_amount) + '</div></div>';
  }
  if (open > 0) {
    html += p.verified_amount > 0 || p.pending_amount > 0
      ? payButton(p, index, isPartial ? 'tp-btn-dark' : 'tp-btn-outline', (isPartial ? '<i class="bi bi-wallet2 me-1"></i>Pay Balance (' : '<i class="bi bi-plus-lg me-1"></i>Pay Remaining (') + peso(open) + ')')
      : payButton(p, index, 'tp-btn-dark', 'Pay Now');
  }
  html += '  </div>';
  html += '</div>';

  p.proofs.forEach(function (proof) {
    var state = proof.status === 'verified'
      ? '<span class="tp-proof-state" style="color:#16a34a"><i class="bi bi-check-lg me-1"></i>Verified</span>'
      : proof.status === 'pending'
        ? '<span class="tp-proof-state" style="color:#b45309"><i class="bi bi-circle-fill me-1" style="font-size:7px;vertical-align:middle"></i>Under Review</span>'
        : '<span class="tp-proof-state" style="color:#b91c1c"><i class="bi bi-arrow-repeat me-1"></i>Needs Resubmit</span>';

    html += '<div class="tp-proof' + (proof.status === 'pending' ? ' is-pending' : proof.status === 'rejected' ? ' is-rejected' : '') + '">';
    html += '  <div class="d-flex align-items-center gap-2 flex-wrap" style="min-width:0">';
    html +=      methodTag(proof.payment_method);
    html += '    <span class="tp-proof-amount">' + peso(proof.amount) + '</span>';
    html += proof.source === 'paymongo'
      ? '    <span class="tp-proof-meta">&bull; Paid online ' + esc(proof.submitted_at) + ' &bull; PayMongo ref ' + esc(proof.reference) + '</span>'
      : '    <span class="tp-proof-meta">&bull; Submitted ' + esc(proof.submitted_at) + (proof.verified_at ? ' &bull; Verified ' + esc(proof.verified_at) : '') + '</span>';
    html += '  </div>';
    html += '  <div class="d-flex align-items-center gap-2">' + state + proofActions(proof.proof_of_payment, proof.receipt_url, installmentLabel(p)) + '</div>';
    if (proof.status === 'rejected' && proof.feedback) {
      html += '<div class="tp-proof-feedback"><i class="bi bi-exclamation-circle-fill me-1"></i>School note: ' + esc(proof.feedback) + '</div>';
    }
    html += '</div>';
  });

  html += '</div>';
  return html;
}

// An installment with nothing submitted yet — a compact single row.
function renderInstallmentRow(p, index) {
  var html = '<div class="tp-row">';
  html += '  <span class="tp-row-num">' + (p.installment_number === 0 ? '<i class="bi bi-star-fill" style="font-size:11px"></i>' : p.installment_number) + '</span>';
  html += '  <div style="min-width:0;flex:1">';
  html += '    <div style="font-size:13.5px;font-weight:600;color:var(--text-dark)">' + installmentLabel(p) + '</div>';
  html += '    <div style="font-size:11.5px;color:' + (p.is_overdue ? '#b91c1c' : '#94a3b8') + '">Due ' + esc(p.due_date) + '</div>';
  html += '  </div>';
  html += '  <div class="tp-row-right d-flex align-items-center gap-3">';
  html += '    <span class="tp-row-amount">' + peso(p.amount_due) + '</span>';
  html += '    <span class="d-flex align-items-center gap-2">' + installmentPill(p) + (openBalance(p) > 0 ? payButton(p, index, 'tp-btn-dark', 'Pay Now') : '') + '</span>';
  html += '  </div>';
  html += '</div>';
  return html;
}

function switchTuitionChild(index) {
  document.querySelectorAll('.child-tuition-pane').forEach(function (pane, i) {
    pane.classList.toggle('d-none', String(i) !== String(index));
  });

  var pane = document.getElementById('tuition-pane-' + index);
  var contentEl = document.getElementById('tuition-content-' + index);
  // Only fetch the first time this pane is switched to — avoid refetching
  // every time the parent flips back and forth between children.
  if (pane && contentEl && contentEl.innerHTML.trim() === '') {
    loadTuitionPane(index, pane.dataset.enrollmentId);
  }
}

function openSubmitPaymentModal(paymentId, label, paneIndex, remainingBalance) {
  tuitionPaymentBeingSubmitted = paymentId;
  tuitionPaneIndexBeingSubmitted = paneIndex;
  tuitionRemainingBeingSubmitted = remainingBalance;

  document.getElementById('submitPaymentInstallmentLabel').textContent = 'Submitting payment for: ' + label;
  document.getElementById('submitPaymentRemainingLabel').textContent =
    '₱' + Number(remainingBalance).toLocaleString(undefined, {minimumFractionDigits:2}) + ' remaining on this installment — you can pay all of it now or send a partial amount.';

  // Reset modal state
  document.querySelectorAll('#submitPaymentMethodCards .pay-method-card').forEach(function (card) {
    card.style.borderColor = '';
    card.style.background = '#fff';
    card.querySelector('input').checked = false;
  });
  var amountInput = document.getElementById('submitPaymentAmount');
  amountInput.value = remainingBalance;
  amountInput.max = remainingBalance;
  document.getElementById('submitPaymentFile').value = '';
  document.getElementById('submitPaymentFileName').textContent = '';
  document.getElementById('submitPaymentError').classList.add('d-none');
  document.getElementById('submitPaymentError').textContent = '';
  setSubmitPaymentMode(PAYMONGO_ENABLED ? 'online' : 'manual');

  var modalEl = document.getElementById('submitPaymentModal');
  var modal = bootstrap.Modal.getOrCreateInstance(modalEl);
  modal.show();
}

function selectSubmitPaymentMethod(el, value) {
  document.querySelectorAll('#submitPaymentMethodCards .pay-method-card').forEach(function (card) {
    card.style.borderColor = '';
    card.style.background = '#fff';
  });
  el.style.borderColor = '#1a2a5e';
  el.style.background = '#f1f5f9';
  el.querySelector('input').checked = true;
}

function handleSubmitPaymentFileChange(input) {
  var nameEl = document.getElementById('submitPaymentFileName');
  nameEl.textContent = input.files.length ? input.files[0].name : '';
}

function confirmSubmitPayment() {
  var errorEl = document.getElementById('submitPaymentError');
  errorEl.classList.add('d-none');
  errorEl.textContent = '';

  var methodInput = document.querySelector('input[name="submitPaymentMethod"]:checked');
  var fileInput = document.getElementById('submitPaymentFile');
  var amountInput = document.getElementById('submitPaymentAmount');
  var amount = parseFloat(amountInput.value);

  if (submitPaymentMode === 'online') {
    startOnlinePayment(amount, errorEl);
    return;
  }

  if (!methodInput) {
    errorEl.textContent = 'Please select a mode of payment.';
    errorEl.classList.remove('d-none');
    return;
  }
  if (!amount || amount <= 0) {
    errorEl.textContent = 'Please enter how much you\'re paying.';
    errorEl.classList.remove('d-none');
    return;
  }
  if (amount > tuitionRemainingBeingSubmitted + 0.01) {
    errorEl.textContent = 'That\'s more than the ₱' + Number(tuitionRemainingBeingSubmitted).toLocaleString(undefined, {minimumFractionDigits:2}) + ' remaining on this installment.';
    errorEl.classList.remove('d-none');
    return;
  }
  if (!fileInput.files.length) {
    errorEl.textContent = 'Please upload proof of payment.';
    errorEl.classList.remove('d-none');
    return;
  }
  if (!tuitionPaymentBeingSubmitted) {
    errorEl.textContent = 'Something went wrong — please close this and try again.';
    errorEl.classList.remove('d-none');
    return;
  }

  var btn = document.getElementById('submitPaymentBtn');
  var originalHtml = btn.innerHTML;
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Submitting…';

  var formData = new FormData();
  formData.append('payment_method', methodInput.value);
  formData.append('amount', amount);
  formData.append('file', fileInput.files[0]);

  fetch('{{ url("/tuition/payments") }}/' + tuitionPaymentBeingSubmitted + '/upload-proof', {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
    body: formData,
  })
  .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
  .then(function (res) {
    btn.disabled = false;
    btn.innerHTML = originalHtml;

    if (!res.ok) {
      errorEl.textContent = res.data.message || 'Could not submit payment. Please try again.';
      errorEl.classList.remove('d-none');
      return;
    }

    var modalEl = document.getElementById('submitPaymentModal');
    bootstrap.Modal.getOrCreateInstance(modalEl).hide();
    showToast('success', res.data.message || 'Proof of payment submitted.');

    // Refresh the pane that triggered this, plus the combined history table
    var paneIndex = tuitionPaneIndexBeingSubmitted;
    var contentEl = document.getElementById('tuition-content-' + paneIndex);
    if (contentEl) contentEl.innerHTML = ''; // force refetch
    var pane = document.getElementById('tuition-pane-' + paneIndex);
    if (pane) loadTuitionPane(paneIndex, pane.dataset.enrollmentId);
    loadTuitionHistory();

    tuitionPaymentBeingSubmitted = null;
    tuitionPaneIndexBeingSubmitted = null;
    tuitionRemainingBeingSubmitted = 0;
  })
  .catch(function (err) {
    console.error('Payment submission failed:', err);
    btn.disabled = false;
    btn.innerHTML = originalHtml;
    errorEl.textContent = 'Something went wrong. Please try again.';
    errorEl.classList.remove('d-none');
  });
}

// "Pay Online": asks the server for a PayMongo checkout for this amount and
// sends the parent there. PayMongo brings them back to the return page,
// which records the payment and lands them on this panel with ?payment=...
function startOnlinePayment(amount, errorEl) {
  var showError = function (msg) { errorEl.textContent = msg; errorEl.classList.remove('d-none'); };
  var minAmount = Math.min(PAYMONGO_MIN_AMOUNT, tuitionRemainingBeingSubmitted);

  if (!amount || amount <= 0) return showError('Please enter how much you\'re paying.');
  if (amount < minAmount) return showError('The minimum online payment is ' + peso(minAmount) + '.');
  if (amount > tuitionRemainingBeingSubmitted + 0.01) return showError('That\'s more than the ' + peso(tuitionRemainingBeingSubmitted) + ' remaining on this installment.');

  var btn = document.getElementById('submitPaymentBtn');
  btn.disabled = true;
  btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span>Opening checkout…';

  fetch('{{ url("/tuition/payments") }}/' + tuitionPaymentBeingSubmitted + '/paymongo-checkout', {
    method: 'POST',
    headers: { 'X-CSRF-TOKEN': getCsrfToken(), 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'Content-Type': 'application/json' },
    body: JSON.stringify({ amount: amount }),
  })
  .then(function (r) { return r.json().then(function (data) { return { ok: r.ok, data: data }; }); })
  .then(function (res) {
    if (res.ok && res.data.checkout_url) {
      window.location.href = res.data.checkout_url;
      return;
    }
    btn.disabled = false;
    setSubmitPaymentMode('online');
    showError(res.data.message || 'Could not start the online payment. Please try again.');
  })
  .catch(function () {
    btn.disabled = false;
    setSubmitPaymentMode('online');
    showError('Something went wrong. Please try again.');
  });
}

// Back from PayMongo: /parent?panel=tuition-payments&payment=success|processing|cancelled
function handlePaymentReturn() {
  var params = new URLSearchParams(window.location.search);
  var result = params.get('payment');
  if (params.get('panel') !== 'tuition-payments' || !result) return;

  showPanel('tuition-payments');

  if (result === 'success') {
    showToast('success', 'Payment received! It has been recorded on your account.');
  } else if (result === 'cancelled') {
    showToast('info', 'Payment cancelled. Nothing was charged.');
  } else {
    showToast('warning', 'Your payment is being confirmed. It will appear here in a few moments.');
    // Give the webhook time to arrive, then refresh what's on screen.
    [5000, 15000].forEach(function (delay) {
      setTimeout(function () {
        var pane = document.querySelector('.child-tuition-pane:not(.d-none)');
        if (pane) loadTuitionPane(pane.id.replace('tuition-pane-', ''), pane.dataset.enrollmentId);
        loadTuitionHistory();
      }, delay);
    });
  }

  // Don't repeat the message on refresh.
  window.history.replaceState({}, '', window.location.pathname);
}

function loadTuitionHistory() {
  var loadingEl = document.getElementById('tuitionHistoryLoading');
  var contentEl = document.getElementById('tuitionHistoryContent');

  loadingEl.classList.remove('d-none');
  contentEl.classList.add('d-none');

  fetch('{{ route("tuition.history") }}', {
    headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' }
  })
  .then(function (r) { return r.json(); })
  .then(function (data) {
    tuitionHistoryRows = data.history || [];
    renderTuitionHistoryTabs();
    contentEl.innerHTML = renderTuitionHistory(filteredTuitionHistory());
    loadingEl.classList.add('d-none');
    contentEl.classList.remove('d-none');
  })
  .catch(function (err) {
    console.error('Failed to load payment history:', err);
    loadingEl.classList.add('d-none');
    contentEl.classList.remove('d-none');
    contentEl.innerHTML = '<div class="text-danger text-center py-4" style="font-size:13px">Could not load payment history.</div>';
  });
}

var tuitionHistoryRows = [];
var tuitionHistoryFilter = 'all';

function filteredTuitionHistory() {
  return tuitionHistoryFilter === 'all'
    ? tuitionHistoryRows
    : tuitionHistoryRows.filter(function (r) { return r.status === tuitionHistoryFilter; });
}

function renderTuitionHistoryTabs() {
  var tabsEl = document.getElementById('tuitionHistoryTabs');
  if (!tabsEl) return;

  var count = function (status) { return tuitionHistoryRows.filter(function (r) { return r.status === status; }).length; };
  var tabs = [['all', 'All'], ['verified', 'Verified (' + count('verified') + ')'], ['pending', 'Pending (' + count('pending') + ')']];
  if (count('rejected')) tabs.push(['rejected', 'Needs Resubmit (' + count('rejected') + ')']);

  tabsEl.innerHTML = tabs.map(function (t) {
    var active = t[0] === tuitionHistoryFilter;
    return '<button type="button" class="tp-tab' + (active ? ' active' : '') + '" role="tab" aria-selected="' + active + '" onclick="setTuitionHistoryFilter(\'' + t[0] + '\')">' + t[1] + '</button>';
  }).join('');
  tabsEl.classList.toggle('d-none', tuitionHistoryRows.length === 0);
}

function setTuitionHistoryFilter(status) {
  tuitionHistoryFilter = status;
  renderTuitionHistoryTabs();
  document.getElementById('tuitionHistoryContent').innerHTML = renderTuitionHistory(filteredTuitionHistory());
}

function renderTuitionHistory(rows) {
  if (!rows.length) {
    return '<div class="text-muted text-center py-4" style="font-size:13px">' +
      (tuitionHistoryRows.length ? 'No payments in this view.' : 'No payments submitted yet.') + '</div>';
  }

  var html = '<div class="table-responsive"><table class="table tp-history mb-0">';
  html += '<thead><tr><th>Child</th><th>Payment For</th><th>Amount</th><th>Mode</th><th>Submitted</th><th>Verified</th><th>Status</th><th class="text-end">Action</th></tr></thead><tbody>';

  rows.forEach(function (row) {
    html += '<tr>';
    html += '<td class="fw-semibold">' + esc(row.child) + '</td>';
    html += '<td>' + esc(row.label) + '</td>';
    html += '<td class="num">' + peso(row.amount) + '</td>';
    html += '<td>' + (methodTag(row.payment_method) || '—') + (row.source === 'paymongo' ? '<div style="font-size:10.5px;color:#94a3b8;margin-top:3px">Paid online</div>' : '') + '</td>';
    html += '<td class="date">' + esc(row.submitted_at) + '</td>';
    html += '<td class="date">' + esc(row.verified_at || '—') + '</td>';
    html += '<td>' + (PROOF_STATUS_BADGE[row.status] || esc(row.status)) + '</td>';
    html += '<td class="text-end"><span class="d-inline-flex gap-1">' + proofActions(row.proof_url, row.receipt_url, row.label) + '</span></td>';
    html += '</tr>';
  });

  html += '</tbody></table></div>';
  return html;
}

document.addEventListener('DOMContentLoaded', function () {
  var firstPane = document.querySelector('.child-tuition-pane');
  if (firstPane) {
    loadTuitionPane(0, firstPane.dataset.enrollmentId);
  }
  if (document.getElementById('tuitionHistoryContent')) {
    loadTuitionHistory();
  }
  handlePaymentReturn();
});
</script>