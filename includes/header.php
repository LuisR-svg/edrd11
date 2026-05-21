<?php
/**
 * includes/header.php — Global Page Header
 * ============================================================
 * Include this at the TOP of every page to get:
 *   - Full <!DOCTYPE html> ... <body> open tag
 *   - Correct <head> with fonts, CSS, CSRF meta tag
 *   - Context-aware navbar (public / member / admin)
 *   - Login modals (public pages only)
 *
 * HOW TO USE — set variables BEFORE including this file:
 * --------------------------------------------------------
 *   $pageTitle    = 'My Page Title';          // required
 *   $pageContext  = 'public';                  // 'public' | 'member' | 'admin'
 *   $activeNav    = 'about';                   // optional: 'about'|'history'|'news'|'contact'
 *   $bodyClass    = '';                        // optional extra class on <body>
 *
 * EXAMPLE (public page):
 *   $pageTitle   = 'Inicio';
 *   $pageContext = 'public';
 *   $activeNav   = '';
 *   require_once __DIR__ . '/includes/header.php';
 *
 * EXAMPLE (member dashboard):
 *   $pageTitle   = 'Portal de Miembros';
 *   $pageContext = 'member';
 *   require_once __DIR__ . '/../includes/header.php';
 *
 * EXAMPLE (admin panel):
 *   $pageTitle   = 'Panel Admin';
 *   $pageContext = 'admin';
 *   require_once __DIR__ . '/../../includes/header.php';
 * ============================================================
 */

// ── Defaults ─────────────────────────────────────────────
if (!isset($pageTitle))   $pageTitle   = APP_NAME;
if (!isset($pageContext)) $pageContext = 'public';   // public | member | admin
if (!isset($activeNav))   $activeNav   = '';
if (!isset($bodyClass))   $bodyClass   = '';

// ── Resolve logged-in user display name ──────────────────
$_headerMemberName = '';
$_headerAdminName  = '';
if ($pageContext === 'member' && isset($_SESSION['member_name'])) {
    $_headerMemberName = e(explode(' ', $_SESSION['member_name'])[0]);
}
if ($pageContext === 'admin' && isset($_SESSION['admin_name'])) {
    $_headerAdminName = e($_SESSION['admin_name']);
}

// ── Helper: is this nav item active? ─────────────────────
function _navActive(string $item, string $active): string {
    return $item === $active ? ' style="color:var(--gold)"' : '';
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= csrf_token() ?>">
  <meta http-equiv="X-Content-Type-Options" content="nosniff">
  <meta name="description" content="Estrella Del Rey David Numero 11 — Fraternidad, Caridad y Verdad.">
  <title><?= e($pageTitle) ?> — <?= APP_NAME ?></title>

  <!-- Google Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">

  <!-- Global stylesheet -->
  <link rel="stylesheet" href="/assets/css/style.css">

  <?php if (isset($extraHead)) echo $extraHead; // page-specific <style> or <link> tags ?>
</head>
<body class="<?= e($bodyClass) ?>">

<?php /* ══════════════════════════════════════════════
         NAVBAR — adapts to context
         ══════════════════════════════════════════════ */ ?>

<!-- ═══════════ PUBLIC NAVBAR ═══════════ -->
<?php if ($pageContext === 'public'): ?>
<nav class="navbar" role="navigation" aria-label="Navegación principal">
  <div class="navbar-inner">
    <a href="/" class="navbar-brand" style="text-decoration:none" aria-label="Inicio">
      <span class="symbol" aria-hidden="true">⬡</span>
      <div class="brand-text">
        <div class="brand-name">Estrella Del Rey David</div>
        <div class="brand-sub">Numero 11 — Est. 1952</div>
      </div>
    </a>
    <div class="navbar-links" id="navbar-links">
      <a href="/#about"   class="nav-link"<?= _navActive('about',   $activeNav) ?>>Acerca de</a>
      <a href="/#history" class="nav-link"<?= _navActive('history', $activeNav) ?>>Historia</a>
      <a href="/#news"    class="nav-link"<?= _navActive('news',    $activeNav) ?>>Comunicados</a>
      <a href="/#contact" class="nav-link"<?= _navActive('contact', $activeNav) ?>>Contacto</a>
      <button class="nav-link" onclick="openModal('modal-member-login')">Acceso Miembros</button>
      <button class="nav-link" style="color:var(--gold)" onclick="openModal('modal-admin-login')">Admin</button>
    </div>
    <button class="hamburger" id="hamburger" aria-label="Abrir menú" aria-expanded="false">☰</button>
  </div>
</nav>

<!-- ═══════════ MEMBER NAVBAR ═══════════ -->
<?php elseif ($pageContext === 'member'): ?>
<nav class="navbar" role="navigation" aria-label="Portal de Miembros">
  <div class="navbar-inner">
    <a href="/" class="navbar-brand" style="text-decoration:none" aria-label="Inicio">
      <span class="symbol" aria-hidden="true">⬡</span>
      <div class="brand-text">
        <div class="brand-name">Estrella Del Rey David</div>
        <div class="brand-sub">Portal de Miembros</div>
      </div>
    </a>
    <div class="navbar-links" id="navbar-links">
      <span style="color:var(--gold);font-size:13px;margin-right:8px">
        Bienvenido, <?= $_headerMemberName ?>
      </span>
      <a href="/member/dashboard.php" class="nav-link"<?= _navActive('dashboard', $activeNav) ?>>Mi Panel</a>
      <a href="/"                     class="nav-link">Sitio Público</a>
      <a href="/api/auth.php?logout=1" class="nav-link">Cerrar Sesión</a>
    </div>
    <button class="hamburger" id="hamburger" aria-label="Abrir menú" aria-expanded="false">☰</button>
  </div>
</nav>

<!-- ═══════════ ADMIN NAVBAR ═══════════ -->
<?php elseif ($pageContext === 'admin'): ?>
<nav class="navbar" role="navigation" aria-label="Panel Administrativo">
  <div class="navbar-inner">
    <a href="/admin/dashboard.php" class="navbar-brand" style="text-decoration:none" aria-label="Admin">
      <span class="symbol" aria-hidden="true">⬡</span>
      <div class="brand-text">
        <div class="brand-name">Estrella Del Rey David</div>
        <div class="brand-sub">Panel Administrativo</div>
      </div>
    </a>
    <div class="navbar-links" id="navbar-links">
      <span style="color:var(--gold);font-size:13px;margin-right:8px">⬡ <?= $_headerAdminName ?></span>
      <a href="/"                      class="nav-link">Sitio Público</a>
      <a href="/member/dashboard.php"  class="nav-link">Ver Portal</a>
      <a href="/api/auth.php?logout=1" class="nav-link">Cerrar Sesión</a>
    </div>
    <button class="hamburger" id="hamburger" aria-label="Abrir menú" aria-expanded="false">☰</button>
  </div>
</nav>
<?php endif; ?>
<!-- ══ LOGIN MODALS ══════════════════════════════════════ -->

<?php /* ══════════════════════════════════════════════
         LOGIN MODALS — only rendered on public pages.
         Members and admins are already logged in.
         ══════════════════════════════════════════════ */ ?>
<?php if ($pageContext === 'public'): ?>

Member Login Modal
<!-- <div id="modal-member-login" class="modal-overlay" style="display:none"
     role="dialog" aria-modal="true" aria-labelledby="member-login-title">
  <div class="modal">
    <span class="login-symbol" aria-hidden="true">⬡</span>
    <h2 class="modal-title" id="member-login-title">Acceso de Miembros</h2>
    <p class="login-sub">Estrella Del Rey David No. 11</p>
    <?php if (!empty($_SESSION['login_error_member'])): ?>
      <div class="form-error auto-dismiss"><?= e($_SESSION['login_error_member']) ?></div>
      <?php unset($_SESSION['login_error_member']); ?>
    <?php endif; ?>
    <form method="POST" action="/api/auth.php" autocomplete="on">
      <?= csrf_field() ?>
      <input type="hidden" name="type" value="member">
      <div class="form-group">
        <label class="form-label" for="member-email">Correo Electrónico</label>
        <input type="email" id="member-email" name="email" class="form-control"
               placeholder="tu@correo.com" required autocomplete="email">
      </div>
      <div class="form-group">
        <label class="form-label" for="member-pin">PIN de Acceso</label>
        <input type="password" id="member-pin" name="pin" class="form-control"
               placeholder="••••" maxlength="8" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-gold btn-full" style="margin-top:1rem">
        Entrar a la Logia
      </button>
    </form>
    <button class="btn btn-outline btn-full" style="margin-top:.75rem"
            onclick="closeModal('modal-member-login')">Cancelar</button>
  </div>
</div> -->

<!-- Admin Login Modal -->
<!-- <div id="modal-admin-login" class="modal-overlay" style="display:none"
     role="dialog" aria-modal="true" aria-labelledby="admin-login-title">
  <div class="modal">
    <span class="login-symbol" aria-hidden="true">⬡</span>
    <h2 class="modal-title" id="admin-login-title">Acceso Administrativo</h2>
    <p class="login-sub">Solo personal autorizado</p>
    <?php if (!empty($_SESSION['login_error_admin'])): ?>
      <div class="form-error auto-dismiss"><?= e($_SESSION['login_error_admin']) ?></div>
      <?php unset($_SESSION['login_error_admin']); ?>
    <?php endif; ?>
    <form method="POST" action="/api/auth.php" autocomplete="on">
      <?= csrf_field() ?>
      <input type="hidden" name="type" value="admin">
      <div class="form-group">
        <label class="form-label" for="admin-username">Usuario</label>
        <input type="text" id="admin-username" name="username" class="form-control"
               placeholder="admin" required autocomplete="username">
      </div>
      <div class="form-group">
        <label class="form-label" for="admin-password">Contraseña</label>
        <input type="password" id="admin-password" name="password" class="form-control"
               placeholder="••••••••" required autocomplete="current-password">
      </div>
      <button type="submit" class="btn btn-gold btn-full" style="margin-top:1rem">
        Ingresar
      </button>
    </form>
    <button class="btn btn-outline btn-full" style="margin-top:.75rem"
            onclick="closeModal('modal-admin-login')">Cancelar</button>
  </div>
</div> -->

<?php /* Auto-open a modal if URL has ?login=member or ?login=admin */ ?>
<?php
$_autoLogin = trim(strip_tags($_GET['login'] ?? ''));
if ($_autoLogin === 'member'): ?>
<script>document.addEventListener('DOMContentLoaded', () => openModal('modal-member-login'));</script>
<?php elseif ($_autoLogin === 'admin'): ?>
<script>document.addEventListener('DOMContentLoaded', () => openModal('modal-admin-login'));</script>
<?php endif; ?>

<?php endif; // end public-only modals ?>