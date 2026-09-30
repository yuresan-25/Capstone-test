{{-- ===== PANEL: MY CHILDREN (PROFILES) ===== --}}
{{-- $unlockedChildren / $hasUnlockedChildProfile / $enrollmentPeriod computed
     once in dashboard.blade.php and shared with sidebar-nav-links.blade.php,
     so the nav lock and this panel's content can never disagree. --}}
    <div id="panel-my-children" class="panel-section d-none p-3 p-md-4">
      <div class="fw-bold mb-1 text-center" style="font-size:22px;color:#1e293b">My Children (Profiles)</div>
      <div class="text-muted mb-4 text-center" style="font-size:14px">Personal, demographic, and academic placement records of your enrolled children</div>

      @if($unlockedChildren->isNotEmpty())
      {{-- Child selector tabs if multiple --}}
      @if($unlockedChildren->count() > 1)
      <div class="d-flex flex-wrap gap-4 mb-4 border-bottom child-profile-tabs" style="max-width:780px;margin:0 auto">
        @foreach($unlockedChildren as $i => $child)
        <button id="child-profile-tab-{{ $child->id }}" data-child-id="{{ $child->id }}"
          onclick="switchChildProfileTab({{ $child->id }})"
          class="btn btn-sm fw-semibold px-1 pb-2 child-profile-tab {{ $i===0 ? 'active' : '' }}">
          {{ $child->first_name }} {{ $child->last_name }}
        </button>
        @endforeach
      </div>

      <style>
        .child-profile-tabs { gap: 1.5rem; }
        .child-profile-tab {
          border: none;
          border-radius: 0;
          background: transparent;
          color: #64748b;
          border-bottom: 3px solid transparent;
          font-size: 14px;
          transition: color .15s ease, border-color .15s ease;
        }
        .child-profile-tab:hover { color: #1a2a5e; }
        .child-profile-tab.active {
          color: #1a2a5e;
          border-bottom-color: #1a2a5e;
        }
      </style>
      @endif

      @foreach($unlockedChildren as $i => $child)
      @php
        $ci2 = strtoupper(substr($child->first_name,0,1)).strtoupper(substr($child->last_name,0,1));

        // Pull this child's uploaded requirements, keyed by document_type.
        // $child->requirements is already eager-loaded in dashboard.blade.php
        // (via $enrolledChildren->with('requirements')), so this doesn't
        // issue a fresh query per child.
        $childDocs = $child->requirements->keyBy('document_type');

        $docLabels = [
          'birth_certificate'   => 'PSA Birth Certificate',
          'report_card'         => 'Form 138 (Report Card)',
          'good_moral'          => 'Good Moral Certificate',
          'medical_certificate' => 'Medical Certificate / Doctor\'s Assessment',
        ];
        $requiredDocs = ['report_card', 'birth_certificate'];
        $optionalDocs = ['good_moral', 'medical_certificate'];
      @endphp
      <div id="child-profile-pane-{{ $child->id }}" data-child-id="{{ $child->id }}"
           class="child-profile-pane {{ $i !== 0 ? 'd-none' : '' }}" style="max-width:780px;margin:0 auto">
        <div class="card border rounded-3 p-4 mb-4">
          <div class="d-flex align-items-center gap-3 gap-sm-4">
            <label class="stu-profile-avatar" data-child-photo="{{ $child->id }}" data-initials="{{ $ci2 }}" style="width:72px;height:72px;font-size:26px" title="Click to upload photo">
              @if($child->photo_url)
                {{-- If the image still fails to load, fall back to initials. --}}
                <img src="{{ $child->photo_url }}" alt="{{ $child->first_name }}"
                     onerror="this.replaceWith(document.createTextNode(this.parentElement.dataset.initials))">
              @else
                {{ $ci2 }}
              @endif
              <input type="file" accept="image/jpeg,image/png,image/webp" style="display:none" onchange="handleChildPhotoUpload(this, {{ $child->id }})">
            </label>
            {{-- min-width:0 lets this column shrink beside the photo; the
                 status badge below may wrap instead of overflowing the card. --}}
            <div style="min-width:0">
              <div class="fw-bold" style="font-size:20px;color:#1e293b;overflow-wrap:anywhere">{{ $child->first_name }} {{ $child->last_name }}</div>
              <div class="text-muted" style="font-size:13px">LRN: <span class="fw-semibold text-dark">{{ $child->lrn ?? 'N/A' }}</span></div>
              <div class="text-muted" style="font-size:13px">Grade Level: <span class="fw-semibold text-dark">{{ $child->grade_level ?? '—' }}</span></div>
              <div class="mt-1">
                @if($child->status === 'enrolled')
                  <span class="badge bg-success-subtle text-success px-3 py-1 rounded-pill">Enrolled</span>
                @elseif($child->status === 'approved')
                  <span class="badge bg-info-subtle text-info px-3 py-1 rounded-pill" style="white-space:normal;text-align:left;line-height:1.35">Approved – Awaiting Sectioning</span>
                @else
                  <span class="badge bg-warning-subtle text-warning px-3 py-1 rounded-pill">Pending Review</span>
                @endif
              </div>
            </div>
          </div>
        </div>

        <div class="card border rounded-3 p-4 mb-4">
          <h5 class="fw-bold mb-3 pb-2 border-bottom" style="color:#1e293b">Personal Information</h5>
          <div class="row g-3">
            <div class="col-md-6"><div class="text-muted" style="font-size:12px">First Name</div><div class="fw-semibold" style="font-size:14px;color:#1e293b">{{ $child->first_name }}</div></div>
            <div class="col-md-6"><div class="text-muted" style="font-size:12px">Middle Name</div><div class="fw-semibold" style="font-size:14px;color:#1e293b">{{ $child->middle_name ?? 'N/A' }}</div></div>
            <div class="col-md-6"><div class="text-muted" style="font-size:12px">Last Name</div><div class="fw-semibold" style="font-size:14px;color:#1e293b">{{ $child->last_name }}</div></div>
            <div class="col-md-6"><div class="text-muted" style="font-size:12px">Suffix</div><div class="fw-semibold" style="font-size:14px;color:#1e293b">{{ $child->suffix ?? 'N/A' }}</div></div>
            <div class="col-md-6"><div class="text-muted" style="font-size:12px">Date of Birth</div><div class="fw-semibold" style="font-size:14px;color:#1e293b">{{ $child->birthday ? \Carbon\Carbon::parse($child->birthday)->format('F d, Y') : '—' }}</div></div>
            <div class="col-md-6"><div class="text-muted" style="font-size:12px">Birth Place</div><div class="fw-semibold" style="font-size:14px;color:#1e293b">{{ $child->birth_place ?? '—' }}</div></div>
            <div class="col-md-6"><div class="text-muted" style="font-size:12px">Preferred Session</div><div class="fw-semibold" style="font-size:14px;color:#1e293b">{{ $child->preferred_session ? $child->preferred_session.' Session' : '—' }}</div></div>
            <div class="col-md-6"><div class="text-muted" style="font-size:12px">Student Type</div><div class="fw-semibold" style="font-size:14px;color:#1e293b">{{ $child->student_type === 'new' ? 'New Student' : 'Old Student' }}</div></div>
          </div>
        </div>

        <div class="card border rounded-3 p-4 mb-4">
          <h5 class="fw-bold mb-3 pb-2 border-bottom" style="color:#1e293b">Academic Placement</h5>
          <div class="row g-3">
            <div class="col-md-4"><div class="text-muted" style="font-size:12px">School Year</div><div class="fw-semibold" style="font-size:14px;color:#1e293b">{{ $enrollmentPeriod->school_year ?? '—' }}</div></div>
            <div class="col-md-4"><div class="text-muted" style="font-size:12px">Grade Level</div><div class="fw-semibold" style="font-size:14px;color:#1e293b">{{ $child->grade_level ?? '—' }}</div></div>
            <div class="col-md-4">
              <div class="text-muted" style="font-size:12px">Section</div>
              @if($child->section)
                <div class="fw-semibold" style="font-size:14px;color:#1e293b">{{ $child->section->name }}</div>
              @else
                <div class="fw-semibold" style="font-size:14px;color:#94a3b8">Not yet sectioned</div>
              @endif
            </div>
          </div>
          @if(!$child->section)
          <div class="d-flex align-items-start gap-2 mt-3 p-2 rounded-2" style="background:#fffbeb;border:1px solid #fcd34d;font-size:12px;color:#92400e">
            <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
            <span>Section assignment happens after admin review — check back once your child's status changes to "Enrolled".</span>
          </div>
          @endif
        </div>

        <div class="card border rounded-3 p-4 mb-4">
          <h5 class="fw-bold mb-3 pb-2 border-bottom" style="color:#1e293b">Address</h5>
          <div class="fw-semibold" style="font-size:14px;color:#1e293b">{{ $child->address ?? '—' }}</div>
        </div>

        <div class="card border rounded-3 p-4 mb-4">
          <h5 class="fw-bold mb-3 pb-2 border-bottom" style="color:#1e293b">Parent / Guardian Information</h5>
          <div class="row g-3">
            <div class="col-md-6"><div class="text-muted" style="font-size:12px">Mother's Name</div><div class="fw-semibold" style="font-size:14px;color:#1e293b">{{ $child->mother_name ?? '—' }}</div></div>
            <div class="col-md-6"><div class="text-muted" style="font-size:12px">Father's Name</div><div class="fw-semibold" style="font-size:14px;color:#1e293b">{{ $child->father_name ?? '—' }}</div></div>
            <div class="col-md-6"><div class="text-muted" style="font-size:12px">Guardian</div><div class="fw-semibold" style="font-size:14px;color:#1e293b">{{ $child->guardian_name ?? '—' }}</div></div>
            <div class="col-md-6"><div class="text-muted" style="font-size:12px">Emergency Contact</div><div class="fw-semibold" style="font-size:14px;color:#1e293b">{{ $child->emergency_contact ?? '—' }}</div></div>
          </div>
        </div>

        {{-- Submitted documents — read-only. Uploading/replacing happens in the
             enroll-step modal (see "Upload Documents" button on the Home card),
             not here. --}}
        <div class="card border rounded-3 p-4 mb-4">
          <h5 class="fw-bold mb-3 pb-2 border-bottom" style="color:#1e293b">Submitted Documents</h5>
          <div class="d-flex flex-column gap-2">
            @foreach(array_merge($requiredDocs, $optionalDocs) as $docType)
              @php $doc = $childDocs->get($docType); @endphp
              <div class="d-flex align-items-center justify-content-between p-3 rounded-2" style="background:#f8fafc;border:1px solid #e2e8f0">
                <div>
                  <div class="fw-medium" style="font-size:13.5px;color:#1e293b">
                    {{ $docLabels[$docType] }}
                    @if(in_array($docType, $optionalDocs))
                      <span class="text-muted fw-normal" style="font-size:11px">(optional)</span>
                    @endif
                  </div>
                  @if($doc)
                    <div class="text-success" style="font-size:11.5px"><i class="bi bi-check-lg me-1"></i>Uploaded {{ $doc->updated_at->format('M d, Y') }}</div>
                  @else
                    <div class="text-muted" style="font-size:11.5px">Not yet submitted</div>
                  @endif
                </div>
                @if($doc)
                  <button type="button" onclick="viewDocument('{{ $doc->url }}', '{{ addslashes($docLabels[$docType]) }}')" class="btn btn-sm btn-outline-secondary" style="font-size:12px">
                    <i class="bi bi-eye me-1"></i>View
                  </button>
                @else
                  <span class="badge bg-secondary-subtle text-secondary" style="font-size:11px">Pending</span>
                @endif
              </div>
            @endforeach
          </div>
        </div>
      </div>
      @endforeach

      @else
      <div class="d-flex flex-column align-items-center justify-content-center py-5 text-center" style="max-width:420px;margin:0 auto">
        @if($enrolledChildren->isEmpty())
        <i class="bi bi-people" style="font-size:52px;color:#cbd5e1;margin-bottom:16px"></i>
        <div class="fw-bold mb-2" style="font-size:18px;color:#1e293b">No children enrolled yet</div>
        <div class="text-muted mb-4" style="font-size:13.5px">Profiles will appear here once you enroll a child.</div>
        <button class="btn fw-semibold px-4" style="background:#1a2a5e;color:#fff;font-size:13px" onclick="showPanel('home'); showHomeSubPanel('enrollment-form')">
          <i class="bi bi-person-plus-fill me-2"></i>Enroll a Child
        </button>
        @else
        <i class="bi bi-lock-fill" style="font-size:52px;color:#cbd5e1;margin-bottom:16px"></i>
        <div class="fw-bold mb-2" style="font-size:18px;color:#1e293b">Profile not unlocked yet</div>
        <div class="text-muted mb-4" style="font-size:13.5px">Finish uploading the required documents (Report Card and PSA Birth Certificate) on your child's card in the Home tab to unlock their profile here.</div>
        <button class="btn fw-semibold px-4" style="background:#1a2a5e;color:#fff;font-size:13px" onclick="showPanel('home')">
          <i class="bi bi-house-fill me-2"></i>Go to Home
        </button>
        @endif
      </div>
      @endif
    </div>