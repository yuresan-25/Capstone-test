{{-- Modal: preview an uploaded requirement/proof-of-payment image without leaving the page --}}
<div class="modal fade" id="documentViewModal" tabindex="-1">
  <div class="modal-dialog modal-dialog-centered modal-lg">
    <div class="modal-content border-0 shadow-lg" style="border-radius:16px;overflow:hidden">
      <div class="d-flex justify-content-between align-items-center px-4 py-3 border-bottom">
        <span class="fw-semibold" id="documentViewModalLabel" style="font-size:14px;color:#1e293b">Document Preview</span>
        <button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="d-flex align-items-center justify-content-center p-3" style="background:#f8fafc;min-height:300px">
        <img id="documentViewModalImg" src="" alt="" style="max-width:100%;max-height:70vh;object-fit:contain;border-radius:8px"
             onerror="if (this.getAttribute('src')) { this.classList.add('d-none'); document.getElementById('documentViewModalError').classList.remove('d-none'); }">
        {{-- Shown instead of a blank box if the file can't be displayed. --}}
        <div id="documentViewModalError" class="d-none text-center text-muted" style="font-size:13px">
          <i class="bi bi-file-earmark-x" style="font-size:36px;color:#94a3b8"></i>
          <div class="mt-2">This file can't be previewed here.</div>
          <a id="documentViewModalOpen" href="#" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary mt-3"><i class="bi bi-box-arrow-up-right me-1"></i>Open in a new tab</a>
        </div>
      </div>
    </div>
  </div>
</div>
