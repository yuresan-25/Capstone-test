<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover"/>
<meta name="csrf-token" content="{{ csrf_token() }}">
<meta name="vapid-public-key" content="{{ config('webpush.vapid.public_key') }}">
<title>@yield('title', 'Premiere Heights Learning Center, Inc. – Online Enrollment System')</title>
  <link href="{{ asset('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet"/>
  <link href="{{ asset('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet"/>
  <link href="{{ asset('vendor/flatpickr/flatpickr.min.css') }}" rel="stylesheet"/>
  {{-- Favicons generated from public/photo/favicon.jpg (the school seal):
       .ico (16/32/48) for browser tabs, PNG for modern browsers, and a
       180px icon for phones' "Add to Home Screen". --}}
  <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any"/>
  <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('photo/favicon-32.png') }}"/>
  <link rel="apple-touch-icon" href="{{ asset('photo/apple-touch-icon.png') }}"/>
  {{-- Loaded here (before @yield('content')) because dashboard/admin/superadmin
       scripts call flatpickr(...) immediately at the top level, not inside a
       click handler — unlike Bootstrap, which those pages only ever touch
       from inside onclick handlers that fire well after the whole page,
       including scripts loaded near </body>, has finished loading. --}}
  <script src="{{ asset('vendor/flatpickr/flatpickr.min.js') }}"></script>
  {{-- Single recaptcha load — the render= variant is for v3; the plain one is for v2 checkbox. Keep only ONE. --}}
  <script src="https://www.google.com/recaptcha/api.js" async defer></script>
  @stack('style')

<style>
/* ============================================================
   style.css — PHLCI Online Enrollment System
   Brand palette derived from Premiere Heights Learning Center
   logo: deep navy, gold, crimson red, forest green
   ============================================================ */


/* ============================================================
   1. CSS VARIABLES
   ============================================================ */
:root {

  /* ── PHLCI Brand Colors ── */
  --navy:        #1a2a5e;   /* deep navy from logo ring         */
  --navy-dark:   #111d42;   /* darker navy for hovers/footer    */
  --navy-light:  #e8ecf7;   /* tinted navy for backgrounds      */
  --gold:        #F5C800;   /* primary gold/yellow from logo     */
  --gold-dark:   #d4a900;   /* gold hover state                 */
  --gold-light:  #fef9e0;   /* pale gold tint for backgrounds   */
  --red:         #b91c1c;   /* crimson from logo book/torch     */
  --red-light:   #fee2e2;   /* pale red tint                    */
  --green:       #2d6a2d;   /* forest green from laurel wreaths */
  --green-light: #dcfce7;   /* pale green tint                  */

  /* ── Aliases used across existing components ── */
  --cyan:        #1a2a5e;   /* remapped → navy (removes teal)   */
  --teal:        #1a2a5e;
  --teal-light:  #e8ecf7;
  --orange:      #d97706;   /* kept for grade-level chips       */

  /* ── Grade level palette ── */
  --g7-color:    #2d6a2d;   /* forest green  */
  --g7-light:    #dcfce7;
  --g8-color:    #d4a900;   /* gold          */
  --g8-light:    #fef9e0;
  --g9-color:    #b91c1c;   /* crimson       */
  --g9-light:    #fee2e2;
  --g10-color:   #1a2a5e;   /* navy          */
  --g10-light:   #e8ecf7;

  /* ── Layout & UI ── */
  --bg:          #f5f6fa;
  --card-bg:     #ffffff;
  --text-dark:   #1e293b;
  --text-muted:  #64748b;
  --border:      #e2e8f0;
  --radius:      14px;
}


/* ============================================================
   2. RESET
   ============================================================ */
*, *::before, *::after { box-sizing: border-box; }


/* ============================================================
   3. COLOR UTILITIES
   ============================================================ */
.text-navy   { color: var(--navy)  !important; }
.text-gold   { color: var(--gold)  !important; }
.text-cyan   { color: var(--navy)  !important; } /* alias */
.text-red    { color: var(--red)   !important; }
.text-green  { color: var(--green) !important; }
.text-orange { color: var(--orange)!important; }

.bg-navy     { background-color: var(--navy)  !important; }
.bg-gold     { background-color: var(--gold)  !important; }
.bg-cyan     { background-color: var(--navy)  !important; } /* alias */
.bg-red      { background-color: var(--red)   !important; }
.bg-orange   { background-color: var(--orange)!important; }


/* ============================================================
   4. BRAND LOGO
   ============================================================ */
.brand-logo {
  width: 40px; height: 40px;
  border-radius: 50%;
  background: linear-gradient(135deg, var(--navy), var(--gold));
  display: flex; align-items: center; justify-content: center;
  font-weight: 800; color: #fff; font-size: 13px; flex-shrink: 0;
}
img.brand-logo {
  object-fit: cover;
  background: #fff;
  border: 2px solid var(--gold);
  padding: 2px;
}


/* ============================================================
   5. BUTTONS
   ============================================================ */

/* Primary navy button */
.btn-navy,
.btn.btn-navy {
  background-color: var(--navy) !important;
  border-color:     var(--navy) !important;
  color: #fff !important;
  transition: background .18s, border-color .18s, transform .12s;
}
.btn-navy:hover,
.btn.btn-navy:hover {
  background-color: var(--navy-dark) !important;
  border-color:     var(--navy-dark) !important;
  color: #fff !important;
}
.btn.btn-navy:focus {
  box-shadow: 0 0 0 .25rem rgba(26,42,94,.35);
}

/* Gold / CTA button */
.btn-gold,
.btn.btn-gold {
  background-color: var(--gold) !important;
  border-color:     var(--gold) !important;
  color: var(--navy-dark) !important;
  font-weight: 700 !important;
  transition: background .18s, transform .12s;
}
.btn-gold:hover,
.btn.btn-gold:hover {
  background-color: var(--gold-dark) !important;
  border-color:     var(--gold-dark) !important;
  color: var(--navy-dark) !important;
  transform: translateY(-1px);
}

/* Hero outline button */
.btn-hero-outline {
  border: 2px solid rgba(255,255,255,.7);
  color: #fff; background: transparent;
  padding: 10px 28px; border-radius: 8px;
  font-size: 14px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 8px;
  text-decoration: none; transition: background .2s;
}
.btn-hero-outline:hover { background: rgba(255,255,255,.12); color: #fff; }

.btn-hero-filled {
  border: 2px solid rgba(255,255,255,.9);
  color: var(--navy); background: #fff;
  padding: 10px 28px; border-radius: 8px;
  font-size: 14px; font-weight: 600;
  display: inline-flex; align-items: center; gap: 8px;
  text-decoration: none; transition: background .2s;
}
.btn-hero-filled:hover { background: #e8ecf7; color: var(--navy); }


/* ============================================================
   6. TOPBAR / NAV
   Shared by login.php, admission.php, forgotpass.php
   ============================================================ */
.phlci-topbar {
  background: var(--navy);
  border-bottom: 3px solid var(--gold);
  padding: 10px 0;
}
.phlci-topbar .brand-school-name {
  font-size: 15px; font-weight: 700; color: #fff; line-height: 1.2;
}
.phlci-topbar .brand-school-sub {
  font-size: 10.5px; color: var(--gold);
}
.phlci-topbar .back-link {
  font-size: 13.5px; font-weight: 600; color: rgba(255,255,255,.8);
  text-decoration: none; display: flex; align-items: center; gap: 5px;
  transition: color .15s;
}
.phlci-topbar .back-link:hover { color: var(--gold); }


/* ============================================================
   7. PAGE BACKGROUND (login / auth pages)
   ============================================================ */
.login-page-bg {
  background:
    radial-gradient(ellipse 900px 600px at 15% 30%, rgba(245,200,0,.07) 0%, transparent 70%),
    radial-gradient(ellipse 600px 500px at 85% 70%, rgba(26,42,94,.06) 0%, transparent 65%),
    linear-gradient(160deg, #f0f3fb 0%, #faf8ee 50%, #f5f6fa 100%);
}


/* ============================================================
   8. AUTH CARD (login, admission, forgotpass)
   ============================================================ */
.auth-card {
  background: #fff;
  border-radius: 16px;
  border: 1px solid var(--border);
  box-shadow: 0 4px 32px rgba(26,42,94,.09);
  padding: 36px 40px;
  width: 100%;
  max-width: 460px;
  margin: 40px auto;
}
@media (max-width: 575px) {
  .auth-card { padding: 28px 20px; }
}

/* Gold top accent bar */
.auth-card::before {
  content: '';
  display: block;
  height: 4px;
  background: linear-gradient(90deg, var(--navy) 0%, var(--gold) 100%);
  border-radius: 16px 16px 0 0;
  margin: -36px -40px 28px;
}
@media (max-width: 575px) {
  .auth-card::before { margin: -28px -20px 24px; }
}


/* ============================================================
   9. LOGIN TABS
   ============================================================ */
.login-tab-btn {
  color: #64748b !important; background: transparent !important;
  border: none !important; transition: all .2s;
}
.login-tab-btn.active {
  background: #fff !important; color: var(--navy) !important;
  box-shadow: 0 1px 4px rgba(0,0,0,.1) !important;
  font-weight: 700 !important;
}
.login-tab-btn:hover:not(.active) { color: var(--navy) !important; }


/* ============================================================
   10. FORM CONTROLS (auth pages)
   ============================================================ */
.form-control:focus {
  border-color: var(--gold) !important;
  box-shadow: 0 0 0 3px rgba(245,200,0,.18) !important;
}


/* ============================================================
   11. TOPBAR ICON (admin/student dashboards)
   ============================================================ */
.topbar-icon {
  font-size: 20px; color: #64748b; cursor: pointer;
  padding: 6px; border-radius: 8px; transition: background .15s;
}
.topbar-icon:hover { background: #f1f5f9; }


/* ============================================================
   12. TOAST ANIMATION
   ============================================================ */
@keyframes slideIn {
  from { transform: translateX(60px); opacity: 0; }
  to   { transform: translateX(0);    opacity: 1; }
}


/* ============================================================
   13. STAT CARDS (admin dashboard)
   ============================================================ */
.stat-icon {
  width: 44px; height: 44px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 20px;
}
.stat-icon.blue   { background: var(--navy);  color: #fff; }
.stat-icon.gold   { background: var(--gold);  color: var(--navy-dark); }
.stat-icon.orange { background: var(--orange);color: #fff; }
.stat-icon.teal   { background: var(--navy);  color: #fff; } /* remapped */
.stat-icon.green  { background: var(--green); color: #fff; }
.stat-icon.red    { background: var(--red);   color: #fff; }


/* ============================================================
   14. ADMIN TABS
   ============================================================ */
.admin-tab {
  padding: 10px 20px; font-size: 14px; font-weight: 500; color: #64748b;
  cursor: pointer; border-bottom: 2px solid transparent; margin-bottom: -1px;
  transition: all .15s; white-space: nowrap;
}
.admin-tab.active { color: var(--navy); border-bottom-color: var(--gold); font-weight: 600; }
.admin-tab:hover:not(.active) { color: var(--navy); }


/* ============================================================
   15. ADMIN CONTENT WRAPPER
   ============================================================ */
.admin-content { padding: 32px 24px; max-width: 1280px; margin: 0 auto; }
@media (max-width: 575.98px) { .admin-content { padding: 16px 12px; } }


/* ============================================================
   16. STATUS BADGES
   ============================================================ */
.badge-pending  { background: var(--gold-light); color: #7a5800; font-size: 12px; font-weight: 600; padding: 4px 12px; border-radius: 20px; }
.badge-approved { background: var(--green-light);color: #166534; font-size: 12px; font-weight: 600; padding: 4px 12px; border-radius: 20px; }
.badge-enrolled { background: var(--navy-light); color: var(--navy);font-size: 12px; font-weight: 600; padding: 4px 12px; border-radius: 20px; }
.badge-resubmit { background: var(--red-light);  color: #991b1b; font-size: 12px; font-weight: 600; padding: 4px 12px; border-radius: 20px; }


/* ============================================================
   17. STUDENT AVATARS
   ============================================================ */
.stu-avatar {
  width: 28px; height: 28px; border-radius: 50%;
  font-size: 11px; font-weight: 700; color: #fff;
  display: inline-flex; align-items: center; justify-content: center;
  margin-right: 8px; flex-shrink: 0;
}
.av-blue   { background: var(--navy);  }
.av-teal   { background: var(--navy);  } /* remapped */
.av-gold   { background: var(--gold);  color: var(--navy-dark); }
.av-orange { background: var(--orange);}
.av-green  { background: var(--green); }
.av-red    { background: var(--red);   }
.av-purple { background: #7c3aed;      }


/* ============================================================
   18. THREE-DOT ACTION DROPDOWN
   ============================================================ */
/* Bootstrap's .table-responsive sets overflow-x: auto, and browsers then
   force overflow-y to non-visible too — so an absolutely-positioned menu in
   the last rows gets clipped by the table's edge. The menu is therefore
   position: fixed, placed next to its button by positionActionMenu() in the
   page JS, which lets it escape any scrolling/overflow container. */
.action-dropdown {
  display: none;
  position: fixed;
  z-index: 9999;
  background: #fff; border: 1px solid var(--border);
  border-radius: 10px; min-width: 170px;
  padding: 6px 0; overflow: hidden;
}
.action-menu-wrap.open .action-dropdown { display: block; }

.action-item {
  display: flex; align-items: center; gap: 9px;
  width: 100%; padding: 9px 14px;
  font-size: 13px; font-weight: 500; color: var(--text-dark);
  background: none; border: none; text-align: left;
  cursor: pointer; transition: background .12s;
}
.action-item:hover { background: var(--navy-light); }
.action-item i { font-size: 14px; width: 16px; text-align: center; }


/* ============================================================
   19. GRADE / SECTION ACCORDION
   ============================================================ */
.grade-card {
  background: var(--card-bg);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  margin-bottom: 12px; overflow: hidden;
  transition: box-shadow .2s;
}
.grade-card:hover { box-shadow: 0 2px 12px rgba(26,42,94,.08); }
.grade-card:last-child { margin-bottom: 0; }

.grade-header {
  display: flex; align-items: center; flex-wrap: nowrap;
  gap: 14px; padding: 16px 20px;
  cursor: pointer; user-select: none; overflow: hidden;
  transition: background .15s;
}
.grade-header:hover { background: #fafbff; }

.grade-icon {
  width: 40px; height: 40px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; flex-shrink: 0;
}
.grade-icon.navy  { background: var(--navy-light); color: var(--navy); }
.grade-icon.teal  { background: var(--navy-light); color: var(--navy); } /* remapped */
.grade-icon.amber { background: var(--gold-light); color: #7a5800; }
.grade-icon.rose  { background: var(--red-light);  color: var(--red); }
.grade-icon.green { background: var(--green-light);color: var(--green); }

.grade-info  { flex: 1 1 0; min-width: 0; overflow: hidden; }
.grade-title {
  font-size: 15px; font-weight: 700; color: var(--text-dark);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
/* Wraps instead of truncating — on phones the "N awaiting sectioning"
   count at the end used to be cut off. */
.grade-meta {
  font-size: 12.5px; color: var(--text-muted); margin-top: 2px;
  white-space: normal; overflow-wrap: anywhere;
}

/* Admin notification dropdown: on phones the 340px panel anchored to the
   bell ran off the left edge — span the screen with a margin instead.
   (!important beats the element's inline desktop sizing.) */
@media (max-width: 575.98px) {
  .notif-dropdown { position: fixed !important; left: 12px !important; right: 12px !important; top: 64px !important; width: auto !important; max-height: 70vh !important; }
}

.grade-bar-wrap { flex: 0 1 120px; min-width: 40px; display: flex; align-items: center; gap: 10px; margin-left: auto; }
.grade-pill-bar { width: 120px; height: 8px; background: #e2e8f0; border-radius: 99px; overflow: hidden; }
.grade-pill-bar .fill { height: 100%; border-radius: 99px; transition: width .5s ease; }

@media (max-width: 400px) { .grade-bar-wrap { display: none; } }

.chevron {
  font-size: 14px; color: var(--text-muted);
  margin-left: 10px; transition: transform .25s;
}
.grade-card.open .chevron { transform: rotate(180deg); }

.sections-wrap {
  max-height: 0; overflow: hidden;
  transition: max-height .35s ease;
  padding: 0 16px;
}
.grade-card.open .sections-wrap { max-height: 800px; padding-bottom: 16px; }

.section-row {
  background: #f8fafc; border: 1px solid var(--border);
  border-radius: 10px; padding: 14px 16px; margin-bottom: 10px;
  transition: background .15s;
}
.section-row:last-child { margin-bottom: 0; }
.section-row:hover { background: #f0f4fa; }

.section-top { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
.section-icon {
  width: 34px; height: 34px; border-radius: 8px;
  display: flex; align-items: center; justify-content: center;
  font-size: 15px; flex-shrink: 0;
}
.section-title   { font-size: 14px; font-weight: 600; color: var(--text-dark); }
.section-teacher { font-size: 12px; color: var(--text-muted); margin-top: 1px; }
.section-menu {
  margin-left: auto; background: none; border: none;
  font-size: 18px; color: var(--text-muted);
  cursor: pointer; padding: 2px 6px; border-radius: 6px; line-height: 1;
}
.section-menu:hover { background: var(--navy-light); }

.cap-row { display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px; }
.cap-label { font-size: 12.5px; color: var(--text-muted); }
.cap-value  { font-size: 12.5px; font-weight: 600; color: var(--text-dark); }

.cap-bar { width: 100%; height: 7px; background: #e2e8f0; border-radius: 99px; overflow: hidden; }
.cap-bar .fill { height: 100%; border-radius: 99px; transition: width .5s ease; }

/* fill colors aligned to brand palette */
.fill-navy  { background: var(--navy);  }
.fill-teal  { background: var(--navy);  } /* remapped */
.fill-gold  { background: var(--gold);  }
.fill-amber { background: var(--gold);  } /* remapped */
.fill-rose  { background: var(--red);   }
.fill-green { background: var(--green); }


/* ============================================================
   20. GRADE PICKER BUTTONS (auto-section modal)
   ============================================================ */
.grade-pick-btn {
  background: #fff; border: 2px solid var(--border);
  border-radius: 14px; padding: 20px 12px;
  cursor: pointer; text-align: center; transition: all .18s ease;
}
.grade-pick-btn:hover {
  border-color: var(--gold);
  box-shadow: 0 4px 18px rgba(26,42,94,.12);
  transform: translateY(-2px);
}
.grade-pick-btn:active { transform: translateY(0); }

.grade-pick-icon {
  width: 48px; height: 48px; border-radius: 12px;
  display: flex; align-items: center; justify-content: center;
  font-size: 22px; margin: 0 auto 10px;
}
.grade-pick-name { font-weight: 800; font-size: 16px; color: #1e293b; }
.grade-pick-meta { font-size: 11px; color: #64748b; margin-top: 3px; }
.grade-pick-badge {
  display: inline-block; margin-top: 10px;
  font-size: 11px; font-weight: 700;
  padding: 3px 10px; border-radius: 20px;
}


/* ============================================================
   21. EMPTY SECTION STATE
   ============================================================ */
.empty-section-state {
  display: flex; flex-direction: column; align-items: center;
  justify-content: center; padding: 36px 20px;
  text-align: center; gap: 10px;
}
.empty-section-icon {
  width: 56px; height: 56px; border-radius: 16px;
  display: flex; align-items: center; justify-content: center;
  font-size: 26px; margin-bottom: 4px;
}
.empty-section-title { font-size: 14px; font-weight: 700; color: #1e293b; }
.empty-section-sub   { font-size: 12.5px; color: #94a3b8; max-width: 300px; line-height: 1.6; }


/* ============================================================
   22. STUDENT PORTAL
   ============================================================ */
.stu-profile-avatar {
  width: 72px; height: 72px; border-radius: 50%;
  background: var(--navy-light); display: flex; align-items: center; justify-content: center;
  font-size: 22px; font-weight: 700; color: var(--navy);
  cursor: pointer; border: 2px dashed var(--gold); position: relative;
  overflow: hidden; flex-shrink: 0;
}
.stu-profile-avatar img { width: 100%; height: 100%; object-fit: cover; border-radius: 50%; }

.sched-icon {
  width: 42px; height: 42px; border-radius: 10px;
  background: var(--navy); color: var(--gold);
  display: flex; align-items: center; justify-content: center;
  font-size: 18px; flex-shrink: 0;
}


/* ============================================================
   23. FOOTER
   ============================================================ */
.site-footer {
  background: linear-gradient(160deg, var(--navy) 0%, var(--navy-dark) 60%, #0d1530 100%);
  color: #cbd5e1;
  padding: 56px 0 0;
  margin-top: auto;
  border-top: 4px solid var(--gold);
}
.border-footer { border-color: rgba(255,255,255,.1) !important; }

.footer-logo {
  width: 56px; height: 56px; border-radius: 50%;
  object-fit: cover; border: 2px solid var(--gold); flex-shrink: 0;
}
.footer-brand-name { font-size: 14px; font-weight: 700; color: #fff; line-height: 1.3; }
.footer-brand-sub  { font-size: 11px; color: var(--gold); }
.footer-desc       { font-size: 13px; color: #94a3b8; line-height: 1.7; margin: 0; }

.footer-social {
  width: 36px; height: 36px; border-radius: 50%;
  background: rgba(245,200,0,.12); color: #cbd5e1;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 15px; text-decoration: none;
  border: 1px solid rgba(245,200,0,.25); transition: all .2s;
}
.footer-social:hover { background: var(--gold); border-color: var(--gold); color: var(--navy-dark); }

.footer-heading {
  font-size: 12px; font-weight: 700; text-transform: uppercase;
  letter-spacing: .08em; color: var(--gold); margin-bottom: 16px;
}

.footer-links { list-style: none; padding: 0; margin: 0; }
.footer-links li { margin-bottom: 10px; }
.footer-links a {
  font-size: 13px; color: #94a3b8; text-decoration: none;
  display: flex; align-items: center; gap: 5px; transition: color .2s;
}
.footer-links a i  { font-size: 11px; color: var(--gold); }
.footer-links a:hover { color: #fff; }

.footer-contact {
  list-style: none; padding: 0; margin: 0;
  display: flex; flex-direction: column; gap: 12px;
}
.footer-contact li {
  display: flex; align-items: flex-start; gap: 10px;
  font-size: 13px; color: #94a3b8; line-height: 1.5;
}
.footer-contact-icon { color: var(--gold); font-size: 14px; margin-top: 2px; flex-shrink: 0; }

.footer-bottom {
  font-size: 12.5px; color: #64748b; padding-bottom: 20px;
}
.footer-bottom-links a { color: #64748b; text-decoration: none; transition: color .2s; }
.footer-bottom-links a:hover { color: #cbd5e1; }

/* ============================================================
   24. GRADE LEVEL PALETTE — Kinder to Grade 6
   Added so the elementary grades added to the admin dashboard
   (Section Management / Create Section / Add Student) render
   with the same brand-consistent treatment as Grade 7-10.
   ============================================================ */
:root {
  --gk-color:  #2d6a2d;  --gk-light:  #dcfce7; /* Kinder  → forest green */
  --g1-color:  #d4a900;  --g1-light:  #fef9e0; /* Grade 1 → gold         */
  --g2-color:  #b91c1c;  --g2-light:  #fee2e2; /* Grade 2 → crimson      */
  --g3-color:  #1a2a5e;  --g3-light:  #e8ecf7; /* Grade 3 → navy         */
  --g4-color:  #2d6a2d;  --g4-light:  #dcfce7; /* Grade 4 → forest green */
  --g5-color:  #d4a900;  --g5-light:  #fef9e0; /* Grade 5 → gold         */
  --g6-color:  #b91c1c;  --g6-light:  #fee2e2; /* Grade 6 → crimson      */
}

/* ============================================================
   25. TOAST NOTIFICATIONS — used by phlciToast() (see script below)
   ============================================================ */
.phlc-toast {
  display: flex; align-items: center; gap: 10px;
  min-width: 280px; max-width: 380px;
  margin-top: 10px;
  padding: 12px 14px;
  border-radius: 10px;
  background: #fff;
  box-shadow: 0 8px 24px rgba(0,0,0,.14);
  border-left: 4px solid var(--navy);
  font-size: 13.5px; color: #1e293b;
  animation: phlcToastIn .25s ease-out;
}
.phlc-toast.hiding { animation: phlcToastOut .3s ease-in forwards; }
@keyframes phlcToastIn {
  from { opacity: 0; transform: translateX(24px); }
  to   { opacity: 1; transform: translateX(0); }
}
@keyframes phlcToastOut {
  from { opacity: 1; transform: translateX(0); }
  to   { opacity: 0; transform: translateX(24px); }
}
.phlc-toast-icon { font-size: 18px; flex-shrink: 0; }
.phlc-toast-body { flex: 1; line-height: 1.4; }
.phlc-toast-close {
  background: none; border: none; padding: 0; margin: 0;
  color: #94a3b8; font-size: 16px; line-height: 1; cursor: pointer; flex-shrink: 0;
}
.phlc-toast-close:hover { color: #475569; }
.phlc-toast-success { border-left-color: #16a34a; }
.phlc-toast-success .phlc-toast-icon { color: #16a34a; }
.phlc-toast-error   { border-left-color: #dc2626; }
.phlc-toast-error   .phlc-toast-icon { color: #dc2626; }
.phlc-toast-warning { border-left-color: #d97706; }
.phlc-toast-warning .phlc-toast-icon { color: #d97706; }
.phlc-toast-info    { border-left-color: #0369a1; }
.phlc-toast-info    .phlc-toast-icon { color: #0369a1; }

/* Password fields pair a show/hide eye button with Bootstrap's built-in
   invalid-state icon in the same right-side padding — both render on top
   of each other. The red border + message below already communicate the
   error, so drop Bootstrap's icon here and let the eye button own that spot. */
.form-control.pe-5.is-invalid {
  background-image: none;
}

/* ============================================================
   26. FLATPICKR — brand-matched date picker (Date of Birth, etc.)
   ============================================================ */
.flatpickr-calendar { box-shadow: 0 8px 24px rgba(0,0,0,.12); border-radius: 10px; }
.flatpickr-months .flatpickr-month,
.flatpickr-current-month .flatpickr-monthDropdown-months { color: var(--navy); fill: var(--navy); }
.flatpickr-current-month input.cur-year { color: var(--navy); }
.flatpickr-weekdays { background: var(--navy-light); }
span.flatpickr-weekday { color: var(--navy); font-weight: 600; }
.flatpickr-day.selected,
.flatpickr-day.selected:hover {
  background: var(--navy); border-color: var(--navy);
}
.flatpickr-day.today { border-color: var(--gold); }
.flatpickr-day:hover { background: var(--navy-light); }
.flatpickr-day.selected.today { background: var(--navy); }

</style>
</head>

<body>

{{-- ══════════════════════════════════════════════════════════
     TOAST CONTAINER  –  toasts are injected here by JS
     ══════════════════════════════════════════════════════════ --}}
<div id="phlci-toast-container"
     style="position:fixed;top:20px;right:20px;z-index:99999;display:flex;flex-direction:column;align-items:flex-end;pointer-events:none;">
</div>

@yield('content')

@unless(request()->routeIs('parent.dashboard', 'admin.dashboard'))
<footer class="site-footer">
  <div class="container">
    <div class="row g-4 pb-2 border-bottom border-footer">

      <!-- Brand -->
      <div class="col-lg-4 col-md-6">
        <div class="d-flex align-items-center gap-3 mb-3">
          <img src="{{ asset('photo/logo.png') }}" class="footer-logo" alt="PHLC Logo">
          <div>
            <div class="footer-brand-name">Premiere Heights Learning Center, Inc.</div>
            <div class="footer-brand-sub">Online Enrollment System</div>
          </div>
        </div>
        <p class="footer-desc">Shaping futures and nurturing excellence — empowering students and administrators with a seamless, modern enrollment experience.</p>
        <div class="d-flex gap-3 mt-3">
          <a href="#" class="footer-social"><i class="bi bi-facebook"></i></a>
          <a href="#" class="footer-social"><i class="bi bi-envelope-fill"></i></a>
          <a href="#" class="footer-social"><i class="bi bi-telephone-fill"></i></a>
        </div>
      </div>

      <!-- Quick Links -->
      <div class="col-lg-2 col-md-6 col-6">
        <div class="footer-heading">Quick Links</div>
        <ul class="footer-links">
          <li><a href="{{ route('landingpage') }}"><i class="bi bi-chevron-right"></i> Home</a></li>
          <li><a href="{{ route('register') }}"><i class="bi bi-chevron-right"></i> Apply Now</a></li>
          <li><a href="{{ route('login') }}"><i class="bi bi-chevron-right"></i> Parent Login</a></li>
          <li><a href="{{ route('login') }}?tab=admin"><i class="bi bi-chevron-right"></i> Admin Login</a></li>
        </ul>
      </div>

      <!-- Resources -->
      <div class="col-lg-2 col-md-6 col-6">
        <div class="footer-heading">Resources</div>
        <ul class="footer-links">
          <li><a href="#"><i class="bi bi-chevron-right"></i> DepEd Portal</a></li>
          <li><a href="#"><i class="bi bi-chevron-right"></i> LRN Lookup</a></li>
          <li><a href="#"><i class="bi bi-chevron-right"></i> School Calendar</a></li>
          <li><a href="#"><i class="bi bi-chevron-right"></i> FAQs</a></li>
        </ul>
      </div>

    </div>

    <!-- Bottom Bar -->
    <div class="footer-bottom d-flex flex-column flex-md-row align-items-center justify-content-between gap-2 pt-3">
      <span>&copy; {{ date('Y') }} Premiere Heights Learning Center, Inc.. All rights reserved.</span>
      <span class="footer-bottom-links">
        <a href="#">Privacy Policy</a>
        <span class="mx-2 opacity-50">|</span>
        <a href="#">Terms of Use</a>
        <span class="mx-2 opacity-50">|</span>
        <a href="#">Sitemap</a>
      </span>
    </div>
  </div>
</footer>
@endunless
{{-- Parent portal toasts (parent/scripts/toast.blade.php). Top-right under
     the sticky topbar — bottom-right covered the fixed "Enroll Now" button. --}}
<div id="toastContainer" style="position:fixed;top:80px;right:16px;z-index:9999;display:flex;flex-direction:column;gap:8px;max-width:calc(100vw - 32px)"></div>
<script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
<script>
/* ═══════════════════════════════════════════════════════════════
   TOAST SYSTEM
   Usage anywhere:  phlciToast('Message here', 'success')
   Types:           'success' | 'error' | 'warning' | 'info'
   ═══════════════════════════════════════════════════════════════ */
function phlciToast(msg, type = 'success', duration = 4000) {
  const icons = {
    success: 'bi-check-circle-fill',
    error:   'bi-x-circle-fill',
    warning: 'bi-exclamation-triangle-fill',
    info:    'bi-info-circle-fill',
  };

  const container = document.getElementById('phlci-toast-container');
  const el = document.createElement('div');
  el.className = `phlc-toast phlc-toast-${type}`;
  el.style.pointerEvents = 'auto';
  // Built via textContent (not innerHTML) for the message so user-controlled
  // text (names, announcement content, etc.) can never inject markup/scripts.
  el.innerHTML = `
    <i class="bi ${icons[type] ?? icons.info} phlc-toast-icon"></i>
    <span class="phlc-toast-body"></span>
    <button class="phlc-toast-close" onclick="phlciDismissToast(this.parentElement)" aria-label="Close">
      <i class="bi bi-x"></i>
    </button>`;
  el.querySelector('.phlc-toast-body').textContent = msg;

  container.appendChild(el);

  // Auto-dismiss
  setTimeout(() => phlciDismissToast(el), duration);
}

function phlciDismissToast(el) {
  if (!el || el.classList.contains('hiding')) return;
  el.classList.add('hiding');
  setTimeout(() => el.remove(), 300);
}

/* ── Fire Laravel flash messages on page load ───────────────── */
document.addEventListener('DOMContentLoaded', function () {
  @if(session('success'))
    phlciToast(@json(session('success')), 'success');
  @endif

  @if(session('error'))
    phlciToast(@json(session('error')), 'error');
  @endif

  @if(session('warning'))
    phlciToast(@json(session('warning')), 'warning');
  @endif

  @if(session('info'))
    phlciToast(@json(session('info')), 'info');
  @endif

  @if($errors->any())
    @foreach($errors->all() as $err)
      phlciToast(@json($err), 'error');
    @endforeach
  @endif
});

/* ── Shared password toggle (available on all pages) ────────── */
function togglePw(id, btn) {
  const inp = document.getElementById(id);
  if (inp.type === 'password') {
    inp.type = 'text';
    btn.innerHTML = '<i class="bi bi-eye-slash"></i>';
  } else {
    inp.type = 'password';
    btn.innerHTML = '<i class="bi bi-eye"></i>';
  }
}

/* ── Shared live password requirements checklist (available on all pages) ──
   Usage: call updatePasswordChecklist(value, 'prefix') on a password field's
   oninput, with matching elements id="prefix-length/upper/number/symbol"
   rendered via the @include('partials.password-checklist', ['prefix' => '...']) partial. */
function updatePasswordChecklist(value, prefix) {
  const checks = {
    length: value.length >= 8,
    upper:  /[A-Z]/.test(value),
    number: /[0-9]/.test(value),
    symbol: /[^A-Za-z0-9]/.test(value),
  };
  Object.keys(checks).forEach(key => {
    const el = document.getElementById(prefix + '-' + key);
    if (!el) return;
    const icon = el.querySelector('i');
    if (checks[key]) {
      icon.className = 'bi bi-check-circle-fill';
      el.style.color = '#16a34a';
      el.style.fontWeight = '600';
    } else {
      icon.className = 'bi bi-circle';
      el.style.color = '#94a3b8';
      el.style.fontWeight = '400';
    }
  });
}
</script>
    
@stack('script')
</body>
</html>