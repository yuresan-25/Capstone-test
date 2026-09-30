{{-- Styles specific to the parent dashboard. Loaded once from dashboard.blade.php. --}}
<style>
  .sidebar-nav-btn {
    display:flex;align-items:center;padding:9px 14px;border-radius:8px;font-size:13.5px;
    font-weight:500;color:#374151;text-decoration:none;border:none;background:transparent;
    width:100%;text-align:left;transition:background .15s,color .15s;cursor:pointer;
  }
  .sidebar-nav-btn:hover{background:#f1f5f9;color:#1e293b;}
  .sidebar-nav-btn.active{background:#1a2a5e;color:#fff;}
  .sidebar-nav-btn.active i{color:#fff;}
  .sidebar-nav-btn i{font-size:15px;color:#64748b;}
  .locked-nav-btn{opacity:.55;cursor:not-allowed!important;}
  .locked-nav-btn:hover{background:transparent!important;color:#374151!important;}
  .form-control.is-invalid,.form-select.is-invalid{border-color:#dc2626!important;background-image:none;}
  .form-control.is-invalid:focus,.form-select.is-invalid:focus{box-shadow:0 0 0 3px rgba(220,38,38,.15);}
  .stu-profile-avatar-lg{width:90px;height:90px;border-radius:50%;background:#1a2a5e;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:28px;overflow:hidden;}
  .stu-profile-avatar-lg img{width:100%;height:100%;object-fit:cover;border-radius:50%;}
  /* flex-shrink:0 keeps it a circle — beside a long name/badge on a phone the
     row used to squeeze its width into an oval. */
  .stu-profile-avatar{width:58px;height:58px;border-radius:50%;background:#1a2a5e;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:18px;cursor:pointer;flex-shrink:0;overflow:hidden;}
  .step-num{width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,.25);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;flex-shrink:0;}
  .step-card{transition:box-shadow .2s;}
  .step-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.1);}
  .panel-section{animation:fadeIn .2s ease;}
  .home-sub-panel{animation:fadeIn .2s ease;}
  @keyframes fadeIn{from{opacity:0;transform:translateY(6px);}to{opacity:1;transform:translateY(0);}}
  .session-card-enter{animation:sessionCardIn .3s cubic-bezier(.34,1.56,.64,1) both;}
  @keyframes sessionCardIn{from{opacity:0;transform:translateY(-10px) scale(.97);}to{opacity:1;transform:translateY(0) scale(1);}}
  .student-card{transition:box-shadow .2s,transform .15s;}

  /* ── Modern Profile Photo: clickable avatar (matches admin/super-admin) ── */
  .pp-avatar-wrap { position:relative; width:110px; height:110px; cursor:pointer; }
  .pp-avatar-img, .pp-avatar-fallback {
    width:110px; height:110px; border-radius:50%; object-fit:cover;
    border:3px solid #e2e8f0; display:flex; align-items:center; justify-content:center;
    background:#f1f5f9; color:#94a3b8; font-size:40px; transition:filter .15s;
  }
  .pp-avatar-overlay {
    position:absolute; inset:0; border-radius:50%;
    background:rgba(26,42,94,.55); color:#fff; font-size:22px;
    display:flex; align-items:center; justify-content:center;
    opacity:0; transition:opacity .18s;
  }
  .pp-avatar-wrap:hover .pp-avatar-overlay,
  .pp-avatar-wrap:focus .pp-avatar-overlay { opacity:1; }
  .pp-avatar-badge {
    position:absolute; bottom:2px; right:2px;
    width:32px; height:32px; border-radius:50%;
    background:#1a2a5e; color:#F5C800;
    display:flex; align-items:center; justify-content:center;
    font-size:13px; border:3px solid #fff;
  }

  /* ── Modern Profile Photo: upload modal (matches admin/super-admin) ── */
  .pp-modal .modal-content { border-radius:16px; border:none; }
  .pp-dropzone {
    border:2px dashed #e2e8f0; border-radius:14px;
    padding:28px 20px; display:flex; flex-direction:column; align-items:center; gap:14px;
    cursor:pointer; transition:border-color .15s, background .15s; text-align:center;
  }
  .pp-dropzone:hover, .pp-dropzone.pp-dragover {
    border-color:#F5C800; background:#fef9e0;
  }
  .pp-preview-circle {
    width:96px; height:96px; border-radius:50%; overflow:hidden;
    background:#f1f5f9; display:flex; align-items:center; justify-content:center;
    border:2px solid #e2e8f0; flex-shrink:0;
  }
  .pp-preview-circle img { width:100%; height:100%; object-fit:cover; }
  .pp-preview-fallback { color:#94a3b8; font-size:36px; }
  .pp-dropzone-text { max-width:280px; }

  /* ── Sidebar logout (pinned to bottom, matches admin/super-admin placement) ── */
  .sb-logout {
    display:flex; align-items:center; gap:10px;
    padding:10px 14px; border-radius:10px;
    font-size:13.5px; font-weight:600; color:#dc2626;
    text-decoration:none; transition:background .15s, color .15s;
  }
  .sb-logout:hover { background:#fee2e2; color:#b91c1c; text-decoration:none; }
  .sb-logout i { font-size:16px; }
</style>
