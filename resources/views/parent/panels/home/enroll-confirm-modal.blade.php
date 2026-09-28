{{--
  Enroll confirmation modal — shown when "Enroll Now" is clicked.
  Displays a compact summary (name, grade, session, payment method,
  documents uploaded) so the parent can double-check before this becomes
  a real, admin-visible enrollment. Confirming calls finalizeEnrollment(),
  which flips status from 'draft' to 'pending'.
--}}
<div class="modal fade" id="enrollConfirmModal" tabindex="-1" aria-labelledby="enrollConfirmModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content" style="border-radius:16px;border:none">
      <div class="modal-header px-4 py-3" style="border-bottom:1px solid #e2e8f0">
        <h6 class="modal-title fw-bold mb-0" id="enrollConfirmModalLabel" style="color:#1e293b;font-size:15px">
          <i class="bi bi-clipboard-check-fill me-2" style="color:#1a2a5e"></i>Double-Check Before Enrolling
        </h6>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body px-4 py-4">
        <p class="text-muted mb-3" style="font-size:13px">Please review the information below. Once confirmed, this will be sent for admin review and can no longer be edited.</p>
        <div class="d-flex flex-column gap-2" id="enrollConfirmSummary" style="font-size:13.5px">
          {{-- Filled in by JS via showEnrollConfirmModal() --}}
        </div>
      </div>
      <div class="modal-footer px-4 py-3" style="border-top:1px solid #e2e8f0">
        <button type="button" class="btn btn-outline-secondary fw-semibold" data-bs-dismiss="modal">
          <i class="bi bi-arrow-left me-1"></i>Go Back
        </button>
        <button type="button" class="btn fw-semibold" id="enrollConfirmBtn" style="background:#16a34a;color:#fff" onclick="confirmFinalizeEnrollment()">
          <i class="bi bi-check-circle-fill me-1"></i>Confirm & Enroll
        </button>
      </div>
    </div>
  </div>
</div>