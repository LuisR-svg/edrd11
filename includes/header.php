<?php
/**
 * includes/header.php — Global Page Header
 * ============================================================
 * Set these variables BEFORE including this file:
 *
 *   $pageTitle   = 'Page Title';           // required
 *   $pageContext = 'public';               // 'public' | 'member' | 'admin'
 *   $activeNav   = '';                     // optional: 'about'|'history'|'news'|'contact'
 *   $extraHead   = '<style>...</style>';   // optional: page-specific CSS
 *
 * Paths (from each folder):
 *   root:    require_once __DIR__ . '/includes/header.php';
 *   /member/ or /admin/:  require_once __DIR__ . '/../includes/header.php';
 * ============================================================
 */

if (!isset($pageTitle))   $pageTitle   = APP_NAME;
if (!isset($pageContext)) $pageContext = 'public';
if (!isset($activeNav))   $activeNav   = '';
if (!isset($extraHead))   $extraHead   = '';

// Logged-in display names
$_hMemberName = '';
$_hAdminName  = '';
if ($pageContext === 'member' && isset($_SESSION['member_name'])) {
    $_hMemberName = e(explode(' ', $_SESSION['member_name'])[0]);
}
if ($pageContext === 'admin' && isset($_SESSION['admin_name'])) {
    $_hAdminName = e($_SESSION['admin_name']);
}

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
  <!-- Favicon -->
  <link rel="icon" type="image/x-icon" href="/assets/img/star-ico.ico">
  <title><?= e($pageTitle) ?> | <?= APP_NAME ?></title>
  <!-- Fonts -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <!-- Font Awesome -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <!-- Global CSS -->
  <link rel="stylesheet" href="/assets/css/style.css?v=1.36">
  <?php if ($extraHead) echo $extraHead; ?>
</head>

<body class="main-layout">

<?php /* ══════ NAVBAR — switches by context ══════ */ ?>

<?php if ($pageContext === 'public'): ?>
<!-- PUBLIC NAVBAR -->
<nav class="navbar" role="navigation" aria-label="Navegación principal">
  <div class="navbar-inner">
    <a href="/" class="navbar-brand" style="text-decoration:none">
      <span class="symbol"><i class="fas fa-star-of-david"></i></span>
      <div class="brand-text">
        <div class="brand-name">Estrella Del Rey David</div>
        <div class="brand-sub">No. 11</div>
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
    <button class="hamburger" id="hamburger" aria-label="Abrir menú">☰</button>
  </div>
  <div class="mobile-menu" id="mobile-menu">
    <a href="/#about"   class="nav-link">Acerca de</a>
    <a href="/#history" class="nav-link">Historia</a>
    <a href="/#news"    class="nav-link">Comunicados</a>
    <a href="/#contact" class="nav-link">Contacto</a>
    <button class="nav-link" onclick="openModal('modal-member-login')">Acceso Miembros</button>
    <button class="nav-link" style="color:var(--gold)" onclick="openModal('modal-admin-login')">Admin</button>
  </div>
</nav>

<?php elseif ($pageContext === 'member'): ?>
<!-- MEMBER NAVBAR -->
<nav class="navbar" role="navigation" aria-label="Portal de Miembros">
  <div class="navbar-inner">
    <a href="/" class="navbar-brand" style="text-decoration:none">
      <span class="symbol"><i class="fas fa-star-of-david"></i></span>
      <div class="brand-text">
        <div class="brand-name">Estrella Del Rey David</div>
        <div class="brand-sub">Portal de Miembros</div>
      </div>
    </a>
    <div class="navbar-links" id="navbar-links">
      <span style="color:var(--gold);font-size:13px;margin-right:8px">Bienvenido, <?= $_hMemberName ?></span>
      <a href="/member/dashboard.php" class="nav-link"<?= _navActive('dashboard', $activeNav) ?>>Mi Panel</a>
      <a href="/" class="nav-link">Sitio Público</a>
      <a href="/api/auth.php?logout=1" class="nav-link">Cerrar Sesión</a>
    </div>
    <button class="hamburger" id="hamburger" aria-label="Abrir menú">☰</button>
  </div>
  <div class="mobile-menu" id="mobile-menu">
    <span style="color:var(--gold);font-size:13px;padding:.5rem 1rem;display:block">Bienvenido, <?= $_hMemberName ?></span>
    <a href="/member/dashboard.php" class="nav-link">Mi Panel</a>
    <a href="/" class="nav-link">Sitio Público</a>
    <a href="/api/auth.php?logout=1" class="nav-link">Cerrar Sesión</a>
  </div>
</nav>

<?php elseif ($pageContext === 'admin'): ?>
<!-- ADMIN NAVBAR -->
<nav class="navbar" role="navigation" aria-label="Panel Administrativo">
  <div class="navbar-inner">
    <a href="/admin/dashboard.php" class="navbar-brand" style="text-decoration:none">
      <span class="symbol"><i class="fas fa-star-of-david"></i></span>
      <div class="brand-text">
        <div class="brand-name">Estrella Del Rey David</div>
        <div class="brand-sub">Panel Administrativo</div>
      </div>
    </a>
    <div class="navbar-links" id="navbar-links">
      <span class="admin-user"><i class="fas fa-star-of-david"></i> <?= $_hAdminName ?></span>
      <a href="/" class="nav-link">Sitio Público</a>
      <a href="/api/auth.php?logout=1" class="nav-link">Cerrar Sesión</a>
    </div>
    <button class="hamburger" id="hamburger" aria-label="Abrir menú">☰</button>
  </div>
  <div class="mobile-menu" id="mobile-menu">
    <span class="admin-user mobile-user"><i class="fas fa-star-of-david"></i> <?= $_hAdminName ?></span>
    <a href="/" class="nav-link">Sitio Público</a>
    <a href="/api/auth.php?logout=1" class="nav-link">Cerrar Sesión</a>
  </div>
</nav>
<?php endif; ?>

<?php /* ══════ LOGIN MODALS — public pages only ══════ */ ?>
<?php if ($pageContext === 'public'): ?>
<?php require_once __DIR__ . '/modals.php'; ?>
<?php endif; ?>