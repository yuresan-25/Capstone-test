{{-- Lightweight toast notification helper, used across the dashboard --}}
<script>
// ── Toast ──────────────────────────────────────────────────────────────────
function showToast(type, message) {
  var container = document.getElementById('toastContainer');
  if (!container) {
    container = document.createElement('div');
    container.id = 'toastContainer';
    // Top-right, just under the sticky topbar — bottom-right covered the
    // fixed "Enroll Now" button while document-upload toasts were showing.
    container.style.cssText = 'position:fixed;top:80px;right:16px;z-index:9999;display:flex;flex-direction:column;gap:8px;max-width:calc(100vw - 32px)';
    document.body.appendChild(container);
  }
  var colors = { success:'#16a34a', danger:'#dc2626', info:'#1d4ed8', warning:'#d97706' };
  var icons  = { success:'bi-check-circle-fill', danger:'bi-x-circle-fill', info:'bi-info-circle-fill', warning:'bi-exclamation-triangle-fill' };
  var toast  = document.createElement('div');
  toast.style.cssText = 'background:#fff;border:1px solid #e2e8f0;border-left:4px solid ' + (colors[type]||'#64748b') + ';border-radius:8px;padding:12px 16px;min-width:min(260px, 100%);max-width:340px;box-shadow:0 4px 12px rgba(0,0,0,.12);display:flex;align-items:flex-start;gap:8px;font-size:13px;color:#1e293b;animation:fadeIn .2s ease';
  toast.innerHTML = '<i class="bi ' + (icons[type]||'bi-bell') + '" style="color:' + (colors[type]||'#64748b') + ';margin-top:1px;flex-shrink:0"></i><span></span>';
  toast.querySelector('span').innerHTML = message;
  container.appendChild(toast);
  setTimeout(() => { toast.style.opacity='0'; toast.style.transition='opacity .4s'; setTimeout(()=>{ toast.remove(); }, 400); }, 4000);
}
</script>
