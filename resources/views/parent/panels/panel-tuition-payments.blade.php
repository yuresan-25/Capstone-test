{{-- ===== PANEL: TUITION & PAYMENTS ===== --}}
    <div id="panel-tuition-payments" class="panel-section d-none p-3 p-md-4">
      <div class="fw-bold mb-1" style="font-size:22px;color:#1e293b">Tuition &amp; Payments</div>
      <div class="text-muted mb-4" style="font-size:14px">Monitor billing statements, balances, and submit proofs of payment</div>

      @if(!$hasEnrolledChildren)
        <div class="d-flex flex-column align-items-center justify-content-center py-5 text-center" style="max-width:480px;margin:0 auto">
          <div style="width:72px;height:72px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center;margin-bottom:20px">
            <i class="bi bi-lock-fill" style="font-size:28px;color:#94a3b8"></i>
          </div>
          <div class="fw-bold mb-2" style="font-size:18px;color:#1e293b">Access Restricted</div>
          <div class="text-muted mb-4" style="font-size:13.5px;line-height:1.7">
            Tuition &amp; Payments becomes available once your child's enrollment has been approved by the school administrator.
          </div>
          <button class="btn fw-semibold px-4" style="background:#1a2a5e;color:#fff;font-size:13px" onclick="showPanel('home'); showHomeSubPanel('enrollment-form')">
            <i class="bi bi-pencil-fill me-2"></i>Go to Enrollment
          </button>
        </div>
      @else
        <div style="max-width:980px">
          @if($approvedChildren->count() > 1)
          <style>
            .child-switch-btn {
              display:flex; align-items:center; gap:12px;
              padding:12px 18px; border-radius:14px;
              border:2px solid #e2e8f0; background:#fff; cursor:pointer;
              transition:all .15s ease; text-align:left; min-width:220px;
            }
            .child-switch-btn:hover { border-color:#94a3b8; box-shadow:0 2px 10px rgba(15,23,42,.08); transform:translateY(-1px); }
            .child-switch-btn.active { border-color:#1a2a5e; background:#eef1fb; box-shadow:0 2px 12px rgba(26,42,94,.15); }
            .child-switch-avatar {
              width:42px; height:42px; border-radius:50%; flex-shrink:0;
              background:#1a2a5e; color:#fff; display:flex; align-items:center; justify-content:center;
              font-size:15px; font-weight:700;
            }
            .child-switch-btn.active .child-switch-avatar { background:#fbbf24; color:#1a2a5e; }
            .child-switch-text { display:flex; flex-direction:column; flex:1; min-width:0; }
            .child-switch-name { font-size:14.5px; font-weight:700; color:#1e293b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
            .child-switch-grade { font-size:12px; color:#64748b; margin-top:1px; }
            .child-switch-check { font-size:20px; color:#1a2a5e; flex-shrink:0; visibility:hidden; }
            .child-switch-btn.active .child-switch-check { visibility:visible; }
          </style>
          <div class="mb-4">
            <div class="fw-semibold mb-2" style="font-size:12px;color:#64748b;text-transform:uppercase;letter-spacing:.04em">
              <i class="bi bi-people-fill me-1"></i>Viewing tuition for — tap to switch child
            </div>
            <div class="d-flex flex-wrap gap-2" id="tuitionChildSwitcher">
              @foreach($approvedChildren as $i => $child)
              @php $initials = strtoupper(substr($child->first_name, 0, 1) . substr($child->last_name, 0, 1)); @endphp
              <button type="button" class="child-switch-btn {{ $i === 0 ? 'active' : '' }}" data-index="{{ $i }}" onclick="selectTuitionChild(this, {{ $i }})">
                <span class="child-switch-avatar">{{ $initials }}</span>
                <span class="child-switch-text">
                  <span class="child-switch-name">{{ $child->first_name }} {{ $child->last_name }}</span>
                  <span class="child-switch-grade">{{ $child->grade_level }}</span>
                </span>
                <i class="bi bi-check-circle-fill child-switch-check"></i>
              </button>
              @endforeach
            </div>
          </div>
          <script>
            // Keeps the existing switchTuitionChild(index) contract (it still
            // loads/shows the right pane) — this just swaps which button in
            // the row looks "pressed" so it's obvious which child is active.
            function selectTuitionChild(btn, index) {
              document.querySelectorAll('#tuitionChildSwitcher .child-switch-btn').forEach(function (b) {
                b.classList.remove('active');
              });
              btn.classList.add('active');
              switchTuitionChild(index);
            }
          </script>
          @endif

          @foreach($approvedChildren as $i => $child)
          <div class="child-tuition-pane {{ $i !== 0 ? 'd-none' : '' }}" id="tuition-pane-{{ $i }}" data-enrollment-id="{{ $child->id }}">
            <div id="tuition-loading-{{ $i }}" class="text-center py-4 text-muted" style="font-size:13px">
              <div class="spinner-border spinner-border-sm me-2"></div> Loading billing information…
            </div>
            <div id="tuition-content-{{ $i }}" class="d-none"></div>
          </div>
          @endforeach

          <!-- Payment History (combined across all children) -->
          <div class="tp-card mt-4">
            <div class="tp-card-head">
              <div>
                <div class="tp-card-title"><i class="bi bi-clock-history me-2 text-navy"></i>Payment History &amp; Submissions</div>
                <div class="tp-card-sub">Every payment you've submitted, across all your children</div>
              </div>
              <div class="tp-tabs d-none" id="tuitionHistoryTabs" role="tablist"></div>
            </div>
            <div id="tuitionHistoryLoading" class="text-center py-4 text-muted" style="font-size:13px">
              <div class="spinner-border spinner-border-sm me-2"></div> Loading payment history…
            </div>
            <div id="tuitionHistoryContent" class="d-none"></div>
          </div>
        </div>
      @endif
    </div>

    <style>
      /* ── Tuition & Payments (parent) ── */
      .tp-card { background:#fff; border:1px solid var(--border); border-radius:16px; overflow:hidden; }
      .tp-card-head { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; padding:18px 20px; border-bottom:1px solid #eef2f7; }
      .tp-card-title { font-size:15px; font-weight:700; color:var(--text-dark); }
      .tp-card-sub { font-size:12px; color:var(--text-muted); margin-top:2px; }
      .tp-pill { display:inline-flex; align-items:center; gap:4px; font-size:11px; font-weight:600; padding:3px 10px; border-radius:999px; white-space:nowrap; }
      .tp-pill-plan    { background:var(--navy-light); color:var(--navy); letter-spacing:.06em; text-transform:uppercase; font-size:10.5px; }
      .tp-pill-paid    { background:#dcfce7; color:#166534; }
      .tp-pill-partial { background:#e0f2fe; color:#0369a1; }
      .tp-pill-pending { background:#fef3c7; color:#b45309; }
      .tp-pill-unpaid  { background:#f1f5f9; color:#64748b; }
      .tp-pill-overdue { background:#fee2e2; color:#b91c1c; }
      .tp-pill-rejected{ background:#fee2e2; color:#b91c1c; }
      .tp-pill-soft    { background:#f8fafc; color:#475569; border:1px solid var(--border); font-weight:500; }

      /* Summary */
      .tp-summary { padding:22px; }
      .tp-plan-title { font-size:20px; font-weight:800; color:var(--text-dark); line-height:1.25; margin-top:8px; }
      .tp-plan-sub { font-size:12.5px; color:var(--text-muted); margin-top:4px; }
      .tp-balance { background:var(--navy); color:#fff; border-radius:14px; padding:16px 18px; display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; min-width:min(100%, 340px); }
      .tp-balance-label { font-size:10.5px; letter-spacing:.08em; text-transform:uppercase; color:rgba(255,255,255,.65); }
      .tp-balance-amount { font-size:26px; font-weight:800; line-height:1.15; font-variant-numeric:tabular-nums; }
      .tp-btn-gold { background:var(--gold); color:var(--navy); border:none; font-weight:700; font-size:12.5px; padding:8px 14px; border-radius:10px; white-space:nowrap; }
      .tp-btn-gold:hover { background:var(--gold-dark); color:var(--navy); }
      .tp-stats { display:grid; grid-template-columns:repeat(3, 1fr); border-top:1px solid #eef2f7; margin-top:20px; }
      .tp-stat { padding:16px 18px 2px 0; }
      .tp-stat + .tp-stat { padding-left:18px; border-left:1px solid #eef2f7; }
      .tp-stat-label { font-size:12px; color:var(--text-muted); display:flex; align-items:center; justify-content:space-between; gap:8px; }
      .tp-stat-value { font-size:20px; font-weight:700; color:var(--text-dark); margin-top:4px; font-variant-numeric:tabular-nums; }
      .tp-stat-note { font-size:11.5px; color:#94a3b8; margin-top:2px; }
      .tp-progress { height:6px; background:#eef2f7; border-radius:999px; overflow:hidden; margin-top:10px; }
      .tp-progress > span { display:block; height:100%; background:#16a34a; border-radius:999px; }
      @media (max-width: 767.98px) {
        .tp-stats { grid-template-columns:1fr; }
        .tp-stat, .tp-stat + .tp-stat { padding:14px 0 0; border-left:none; }
        .tp-stat + .tp-stat { border-top:1px solid #eef2f7; margin-top:14px; }
      }

      /* Installments */
      .tp-inst { padding:18px 20px; border-top:1px solid #eef2f7; }
      .tp-inst.is-partial { background:#fffbeb; }
      .tp-inst-title { font-size:14.5px; font-weight:700; color:var(--text-dark); }
      .tp-inst-meta { font-size:12px; color:var(--text-muted); margin-top:3px; }
      .tp-inst-meta .warn { color:#c2410c; font-weight:600; }
      .tp-inst-total-label { font-size:11px; color:var(--text-muted); text-align:right; }
      .tp-inst-total { font-size:16px; font-weight:700; color:var(--text-dark); text-align:right; font-variant-numeric:tabular-nums; }
      .tp-proof { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px; margin-top:10px; padding:10px 12px; background:#f8fafc; border:1px solid var(--border); border-radius:12px; }
      .tp-proof.is-pending  { background:#fffbeb; border-color:#fde68a; }
      .tp-proof.is-rejected { background:#fef2f2; border-color:#fecaca; }
      .tp-proof-amount { font-size:13px; font-weight:700; color:var(--text-dark); font-variant-numeric:tabular-nums; }
      .tp-proof-meta { font-size:11.5px; color:#94a3b8; }
      .tp-proof-state { font-size:12px; font-weight:600; white-space:nowrap; }
      .tp-proof-feedback { flex-basis:100%; font-size:12px; color:#991b1b; }
      .tp-method { font-size:11px; font-weight:600; padding:3px 8px; border-radius:6px; white-space:nowrap; }
      .tp-method-gcash { background:#dbeafe; color:#1d4ed8; }
      .tp-method-maya  { background:#dcfce7; color:#15803d; }
      .tp-method-bank  { background:#ede9fe; color:#6d28d9; }
      .tp-method-cash  { background:#f1f5f9; color:#475569; }
      .tp-method-card  { background:var(--navy-light); color:var(--navy); }
      .tp-row { display:flex; align-items:center; gap:14px; padding:14px 20px; border-top:1px solid #eef2f7; }
      .tp-row-num { width:30px; height:30px; border-radius:50%; background:#f1f5f9; color:#64748b; font-size:12px; font-weight:600; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
      .tp-row-amount { font-size:14px; font-weight:700; color:var(--text-dark); font-variant-numeric:tabular-nums; }
      .tp-btn-dark { background:var(--navy); color:#fff; border:none; font-size:12px; font-weight:600; padding:7px 14px; border-radius:9px; white-space:nowrap; }
      .tp-btn-dark:hover { background:var(--navy-dark); color:#fff; }
      .tp-btn-outline { background:#fff; color:var(--text-dark); border:1px solid var(--border); font-size:12px; font-weight:600; padding:7px 14px; border-radius:9px; white-space:nowrap; }
      .tp-btn-outline:hover { background:#f8fafc; }
      @media (max-width: 575.98px) {
        .tp-row { flex-wrap:wrap; }
        .tp-row-right { width:100%; justify-content:space-between; padding-left:44px; }
      }

      /* History */
      .tp-tabs { display:inline-flex; background:#f1f5f9; border-radius:10px; padding:3px; gap:2px; flex-wrap:wrap; }
      .tp-tab { border:none; background:transparent; font-size:12px; font-weight:600; color:#64748b; padding:6px 12px; border-radius:8px; }
      .tp-tab.active { background:#fff; color:var(--text-dark); box-shadow:0 1px 3px rgba(15,23,42,.08); }
      .tp-history th { font-size:11px; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:.04em; background:#f8fafc; padding:12px 16px; white-space:nowrap; }
      .tp-history td { font-size:13px; padding:14px 16px; vertical-align:middle; }
      .tp-history td.num { font-weight:700; font-variant-numeric:tabular-nums; white-space:nowrap; }
      .tp-history td.date { font-size:12px; color:var(--text-muted); white-space:nowrap; }
      .tp-history .btn { white-space:nowrap; }
    </style>

    <!-- ===== MODAL: SUBMIT PAYMENT ===== -->
    <div class="modal fade" id="submitPaymentModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered" style="max-width:440px">
        <div class="modal-content border-0 shadow-lg" style="border-radius:16px">
          <div class="modal-header border-0 pb-0 px-4 pt-4">
            <h5 class="modal-title fw-bold" style="color:#1e293b;font-size:16px">Submit Payment</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body px-4">
            <div class="text-muted mb-1" style="font-size:12.5px" id="submitPaymentInstallmentLabel"></div>
            <div class="text-muted mb-3" style="font-size:11.5px" id="submitPaymentRemainingLabel"></div>

            @if(\App\Support\PayMongo::enabled())
            <div class="tp-tabs w-100 mb-3" role="tablist" id="submitPaymentModeTabs">
              <button type="button" class="tp-tab flex-fill active" data-mode="online" onclick="setSubmitPaymentMode('online')"><i class="bi bi-lightning-charge-fill me-1"></i>Pay Online</button>
              <button type="button" class="tp-tab flex-fill" data-mode="manual" onclick="setSubmitPaymentMode('manual')"><i class="bi bi-upload me-1"></i>Upload Receipt</button>
            </div>
            @endif

            <div class="mb-3">
              <label class="form-label fw-medium" style="font-size:12.5px">Amount You're Paying <span class="text-danger">*</span></label>
              <div class="input-group input-group-sm">
                <span class="input-group-text">₱</span>
                <input type="number" class="form-control" id="submitPaymentAmount" min="1" step="0.01" placeholder="0.00">
              </div>
              <div class="text-muted mt-1" style="font-size:11px">You can pay the full remaining amount, or send a partial payment and cover the rest later.</div>
            </div>

            <div id="submitPaymentOnlineFields" class="d-none mb-2">
              <div class="d-flex align-items-start gap-2 p-3 rounded-3" style="background:#f0fdf4;border:1px solid #bbf7d0;font-size:12.5px;color:#166534">
                <i class="bi bi-shield-lock-fill flex-shrink-0 mt-1"></i>
                <span>You'll be taken to <strong>PayMongo's secure checkout</strong> to pay with GCash, Maya, card or online banking. Your payment is confirmed automatically, so there's no receipt to upload.</span>
              </div>
            </div>

            <div id="submitPaymentManualFields">
            <div class="mb-3">
              <label class="form-label fw-medium" style="font-size:12.5px">Mode of Payment <span class="text-danger">*</span></label>
              <div class="d-flex flex-wrap gap-2" id="submitPaymentMethodCards">
                <label class="pay-method-card d-flex align-items-center gap-2 px-3 py-2 rounded-3 border" style="cursor:pointer;background:#fff;min-width:100px" onclick="selectSubmitPaymentMethod(this,'gcash')">
                  <input type="radio" name="submitPaymentMethod" value="gcash" style="display:none">
                  <span style="font-size:13px;font-weight:700;color:#1e293b">GCash</span>
                </label>
                <label class="pay-method-card d-flex align-items-center gap-2 px-3 py-2 rounded-3 border" style="cursor:pointer;background:#fff;min-width:100px" onclick="selectSubmitPaymentMethod(this,'maya')">
                  <input type="radio" name="submitPaymentMethod" value="maya" style="display:none">
                  <span style="font-size:13px;font-weight:700;color:#1e293b">Maya</span>
                </label>
                <label class="pay-method-card d-flex align-items-center gap-2 px-3 py-2 rounded-3 border" style="cursor:pointer;background:#fff;min-width:120px" onclick="selectSubmitPaymentMethod(this,'bank_transfer')">
                  <input type="radio" name="submitPaymentMethod" value="bank_transfer" style="display:none">
                  <span style="font-size:13px;font-weight:700;color:#1e293b">Bank Transfer</span>
                </label>
                <label class="pay-method-card d-flex align-items-center gap-2 px-3 py-2 rounded-3 border" style="cursor:pointer;background:#fff;min-width:90px" onclick="selectSubmitPaymentMethod(this,'cash')">
                  <input type="radio" name="submitPaymentMethod" value="cash" style="display:none">
                  <span style="font-size:13px;font-weight:700;color:#1e293b">Cash</span>
                </label>
              </div>
            </div>

            <div class="mb-2">
              <label class="form-label fw-medium" style="font-size:12.5px">Proof of Payment <span class="text-danger">*</span></label>
              <label class="d-flex align-items-center gap-2 p-3 rounded-3 border" style="cursor:pointer;background:#f8fafc;border-style:dashed!important">
                <i class="bi bi-cloud-arrow-up" style="font-size:20px;color:#94a3b8;flex-shrink:0"></i>
                <div>
                  <div style="font-size:12.5px;font-weight:600;color:#374151">Click to upload screenshot or receipt</div>
                  <div style="font-size:11px;color:#94a3b8">JPG, PNG or PDF · max 5MB</div>
                </div>
                <input type="file" id="submitPaymentFile" accept="image/*,.pdf" style="display:none" onchange="handleSubmitPaymentFileChange(this)">
              </label>
              <span id="submitPaymentFileName" class="d-block mt-2 text-muted" style="font-size:12px"></span>
            </div>
            </div>
            <div id="submitPaymentError" class="text-danger d-none" style="font-size:12px"></div>
          </div>
          <div class="modal-footer border-0 px-4 pb-4">
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
            <button type="button" class="btn btn-navy btn-sm fw-semibold px-4" id="submitPaymentBtn" onclick="confirmSubmitPayment()">
              <i class="bi bi-send me-1"></i>Submit
            </button>
          </div>
        </div>
      </div>
    </div>