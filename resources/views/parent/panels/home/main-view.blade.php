{{-- Default view of the Home panel: empty state, draft banner, or student card row --}}
      <div id="home-main-view">

        {{-- ── CONTINUE ENROLLMENT BANNER ──
             Shown for any in-progress draft (Step 1 saved, "Enroll Now" not
             yet clicked). Drafts are intentionally excluded from
             $enrolledChildren, so they never show as a regular card. --}}
        @if(isset($draftChildren) && $draftChildren->isNotEmpty())
        <div class="d-flex flex-column gap-2 mb-4">
          @foreach($draftChildren as $draft)
          <div class="d-flex align-items-center justify-content-between p-3 rounded-3 flex-wrap gap-2" style="background:#fffbeb;border:1.5px solid #fcd34d">
            <div class="d-flex align-items-center gap-3">
              <i class="bi bi-pencil-square" style="font-size:22px;color:#92400e"></i>
              <div>
                <div class="fw-bold" style="font-size:14px;color:#92400e">Continue Enrollment — {{ $draft->first_name }} {{ $draft->last_name }}</div>
                <div class="text-muted" style="font-size:12px">Step 1 saved. Finish uploading requirements to complete enrollment.</div>
              </div>
            </div>
            <div class="d-flex gap-2">
              <button class="btn btn-sm fw-semibold" style="background:transparent;color:#92400e;border:1px solid #fcd34d;font-size:12.5px"
                onclick="deleteEnrollment({{ $draft->id }}, '{{ addslashes($draft->first_name . ' ' . $draft->last_name) }}')">
                <i class="bi bi-trash3 me-1"></i>Discard
              </button>
              <button class="btn btn-sm fw-semibold" style="background:#92400e;color:#fff;font-size:12.5px"
                onclick="resumeDraftEnrollment({{ $draft->id }})">
                <i class="bi bi-arrow-right-circle me-1"></i>Continue
              </button>
            </div>
          </div>
          @endforeach
        </div>
        @endif

        @if($enrolledChildren->isEmpty())
        {{-- ── EMPTY STATE ── --}}
        <div class="d-flex flex-column align-items-center justify-content-center text-center py-5" style="min-height:60vh">
          <div class="mb-4" style="width:110px;height:110px;border-radius:28px;background:#e8edf8;display:flex;align-items:center;justify-content:center">
            <i class="bi bi-person-plus-fill" style="font-size:52px;color:#1a2a5e;opacity:.8"></i>
          </div>
          <div class="fw-bold mb-2" style="font-size:22px;color:#1e293b">No students enrolled yet</div>
          <div class="text-muted mb-4" style="font-size:14px;max-width:400px;line-height:1.7">
            Start the enrollment process for SY {{ $enrollmentPeriod->school_year ?? '2026–2027' }} at Premiere Heights Learning Center, Inc.
          </div>
          @if($isEnrollmentOpen)
          <button class="btn fw-bold px-5 py-3" style="background:#1a2a5e;color:#fff;font-size:15px;border-radius:12px;box-shadow:0 4px 16px rgba(26,42,94,.25)" onclick="resetPHLCIForm(); showHomeSubPanel('enrollment-form')">
            <i class="bi bi-person-plus-fill me-2"></i>Enroll Your First Child
          </button>
          <div class="mt-4 d-flex align-items-center gap-2 px-3 py-2 rounded-3" style="background:#f0f9ff;border:1px solid #bae6fd;font-size:12.5px;color:#0369a1">
            <i class="bi bi-calendar-check-fill flex-shrink-0"></i>
            <span><strong>Enrollment Period:</strong> {{ $enrollmentPeriod && $enrollmentPeriod->start_date ? $enrollmentPeriod->start_date->format('F j') : '—' }} – {{ $enrollmentPeriod && $enrollmentPeriod->end_date ? $enrollmentPeriod->end_date->format('F j, Y') : '—' }}</span>
          </div>
          @else
          <button class="btn fw-bold px-5 py-3" style="background:#e2e8f0;color:#64748b;font-size:15px;border-radius:12px;cursor:not-allowed" disabled>
            <i class="bi bi-lock-fill me-2"></i>Enrollment Closed
          </button>
          <div class="mt-4 d-flex align-items-center gap-2 px-3 py-2 rounded-3" style="background:#fef2f2;border:1px solid #fecaca;font-size:12.5px;color:#991b1b">
            <i class="bi bi-exclamation-circle-fill flex-shrink-0"></i>
            <span>Enrollment is currently closed. Please check back once the next enrollment period opens.</span>
          </div>
          @endif
        </div>

        @else
        {{-- ── HAS STUDENTS: card row ── --}}
        <div class="fw-bold mb-1" style="font-size:22px;color:#1e293b">Home</div>
        <div class="text-muted mb-4" style="font-size:14px">SY 2026–2027 &nbsp;|&nbsp; Your children's enrollment status</div>

        <div class="d-flex flex-wrap gap-3 mb-4 align-items-stretch" id="studentCardRow">
          @foreach($enrolledChildren as $child)
          @php
            $ci = strtoupper(substr($child->first_name,0,1)).strtoupper(substr($child->last_name,0,1));
            $stMap = [
              'approved' => ['label'=>'Approved',      'bg'=>'#dcfce7','color'=>'#166534','icon'=>'bi-check-circle-fill','border'=>'#16a34a'],
              'pending'  => ['label'=>'Pending Review','bg'=>'#fef3c7','color'=>'#b45309','icon'=>'bi-clock-fill',       'border'=>'#f59e0b'],
              'rejected' => ['label'=>'Rejected',      'bg'=>'#fee2e2','color'=>'#991b1b','icon'=>'bi-x-circle-fill',   'border'=>'#dc2626'],
            ];
            $st = $stMap[$child->status ?? 'pending'] ?? ['label'=>ucfirst($child->status),'bg'=>'#f1f5f9','color'=>'#475569','icon'=>'bi-question-circle','border'=>'#cbd5e1'];
          @endphp
          {{-- Read-only card: click anywhere to view full profile + documents
               in "My Children" (once unlocked). Only action available here
               is Delete, and only while still pending. No Edit, no Upload
               Documents — those only happen during the active enroll flow,
               before this card ever exists. --}}
          <div class="student-card" onclick="openChildProfile({{ $child->id }})"
               style="background:#fff;border:1.5px solid #e2e8f0;border-top:4px solid {{ $st['border'] }};border-radius:14px;padding:20px;min-width:200px;max-width:220px;cursor:pointer;transition:box-shadow .2s,transform .15s"
               onmouseover="this.style.boxShadow='0 6px 20px rgba(0,0,0,.1)';this.style.transform='translateY(-2px)'"
               onmouseout="this.style.boxShadow='none';this.style.transform='none'">
            <div class="d-flex flex-column align-items-center text-center gap-2">
              <div style="width:52px;height:52px;border-radius:50%;background:#1a2a5e;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:17px">{{ $ci }}</div>
              <div class="fw-bold" style="font-size:14px;color:#1e293b;line-height:1.2">{{ $child->first_name }} {{ $child->last_name }}</div>
              <div class="text-muted" style="font-size:12px">{{ $child->grade_level ?? '—' }}</div>
              <span class="badge px-3 py-1 rounded-pill fw-semibold mt-1" style="font-size:11px;background:{{ $st['bg'] }};color:{{ $st['color'] }}">
                <i class="bi {{ $st['icon'] }} me-1"></i>{{ $st['label'] }}
              </span>
              @if($child->preferred_session)
              <div class="text-muted" style="font-size:11px"><i class="bi bi-sun me-1"></i>{{ $child->preferred_session }} Session</div>
              @endif
              @php
                // Chose "pay online" but closed PayMongo before paying.
                $feePayment = $child->status === 'pending' && \App\Http\Controllers\EnrollmentController::awaitingOnlineFee($child)
                  ? $child->tuitionPlan->payments()->where('installment_number', 0)->first()
                  : null;
              @endphp
              @if($feePayment)
              <div class="w-100 mt-1 px-2 py-1 rounded-2" style="font-size:11px;background:#fffbeb;color:#b45309;border:1px solid #fde68a">
                <i class="bi bi-exclamation-circle-fill me-1"></i>Enrollment fee not paid yet
              </div>
              <button class="btn btn-sm fw-semibold w-100" style="font-size:11.5px;background:#1a2a5e;color:#fff"
                onclick="event.stopPropagation(); startEnrollmentFeePayment({{ $feePayment->id }}, {{ $feePayment->remainingBalance() }}, this)">
                <i class="bi bi-lightning-charge-fill me-1"></i>Pay ₱{{ number_format($feePayment->remainingBalance(), 2) }} online
              </button>
              @endif
              @if($child->status === 'pending')
              <button class="btn btn-sm fw-semibold w-100 mt-2" style="font-size:11.5px;background:#fee2e2;color:#dc2626"
                onclick="event.stopPropagation(); deleteEnrollment({{ $child->id }}, '{{ addslashes($child->first_name . ' ' . $child->last_name) }}')">
                <i class="bi bi-trash3 me-1"></i>Delete
              </button>
              @endif
            </div>
          </div>
          @endforeach

          {{-- Plus card to enroll another --}}
          @if($isEnrollmentOpen)
          <div onclick="resetPHLCIForm(); showHomeSubPanel('enrollment-form')"
               style="background:#fff;border:2px dashed #1a2a5e;border-radius:14px;padding:20px;min-width:200px;max-width:220px;cursor:pointer;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;transition:background .15s;color:#1a2a5e"
               onmouseover="this.style.background='#f0f4ff'" onmouseout="this.style.background='#fff'">
            <div style="width:52px;height:52px;border-radius:50%;background:#e8edf8;display:flex;align-items:center;justify-content:center">
              <i class="bi bi-plus-lg" style="font-size:24px;color:#1a2a5e"></i>
            </div>
            <div class="fw-semibold text-center" style="font-size:13px">Enroll Another Child</div>
          </div>
          @else
          <div style="background:#f8fafc;border:2px dashed #cbd5e1;border-radius:14px;padding:20px;min-width:200px;max-width:220px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:10px;color:#94a3b8">
            <div style="width:52px;height:52px;border-radius:50%;background:#f1f5f9;display:flex;align-items:center;justify-content:center">
              <i class="bi bi-lock-fill" style="font-size:20px;color:#94a3b8"></i>
            </div>
            <div class="fw-semibold text-center" style="font-size:13px">Enrollment Closed</div>
          </div>
          @endif
        </div>

        @if($isEnrollmentOpen)
        <div class="mt-2 d-flex align-items-center gap-2 px-3 py-2 rounded-3" style="max-width:560px;background:#f0f9ff;border:1px solid #bae6fd;font-size:12.5px;color:#0369a1">
          <i class="bi bi-calendar-check-fill flex-shrink-0"></i>
          <span><strong>Enrollment Period:</strong> {{ $enrollmentPeriod && $enrollmentPeriod->start_date ? $enrollmentPeriod->start_date->format('F j') : '—' }} – {{ $enrollmentPeriod && $enrollmentPeriod->end_date ? $enrollmentPeriod->end_date->format('F j, Y') : '—' }}. Complete all steps before the deadline.</span>
        </div>
        @else
        <div class="mt-2 d-flex align-items-center gap-2 px-3 py-2 rounded-3" style="max-width:560px;background:#fef2f2;border:1px solid #fecaca;font-size:12.5px;color:#991b1b">
          <i class="bi bi-exclamation-circle-fill flex-shrink-0"></i>
          <span>Enrollment is currently closed. Existing applications can still be viewed under My Children.</span>
        </div>
        @endif
        @endif

      </div>{{-- /home-main-view --}}