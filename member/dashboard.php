<?php
/**
 * member/dashboard.php — Member Portal
 * ============================================================
 * Requires member to be logged in.
 * Shows: profile, monthly dues calendar, my donations, news.
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/degrees.php';

secure_session_start();
require_member();

$pdo       = DB::get();
$member_id = (int) $_SESSION['member_id'];
$year      = int_val($_GET['year'] ?? date('Y'));

// Fetch member data
$stmt = $pdo->prepare("SELECT * FROM members WHERE id=? AND active=1");
$stmt->execute([$member_id]);
$member = $stmt->fetch();
if (!$member) { session_destroy(); header('Location: /'); exit; }

// Fetch dues for this member by month for selected year
$stmt = $pdo->prepare("SELECT * FROM dues WHERE member_id=? AND year=? ORDER BY month");
$stmt->execute([$member_id, $year]);
$duesRows = $stmt->fetchAll();

// Build month map
$duesMap = [];
foreach ($duesRows as $d) $duesMap[$d['month']] = $d;

// Dues settings (monthly rate)
$duesSettingStmt = $pdo->prepare("SELECT amount FROM dues_settings WHERE year=?");
$duesSettingStmt->execute([$year]);
$duesSetting = $duesSettingStmt->fetch();
$monthlyRate = $duesSetting ? $duesSetting['amount'] : 0;

// Count outstanding months
$owed = 0;
for ($m = 1; $m <= date('n'); $m++) {
    if (!isset($duesMap[$m]) || !$duesMap[$m]['paid']) $owed++;
}
$owedAmount = $owed * $monthlyRate;

// Fetch member's donations
$stmt = $pdo->prepare("SELECT * FROM donations WHERE member_id=? ORDER BY date DESC LIMIT 20");
$stmt->execute([$member_id]);
$myDonations = $stmt->fetchAll();
$totalDonated = array_sum(array_column($myDonations, 'amount'));

// Fetch recent news
$news = $pdo->query("SELECT * FROM news WHERE published=1 ORDER BY created_at DESC LIMIT 10")->fetchAll();

$initials = implode('', array_map(fn($w) => $w[0], array_slice(explode(' ', $member['name']), 0, 2)));
$months   = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
$monthsAbbr = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$currentMonth = (int) date('n');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= csrf_token() ?>">
  <title>Member Portal — <?= APP_NAME ?></title>
   <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/css/style.css?v=1.8">
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
  <div class="navbar-inner">
    <a href="/" class="navbar-brand" style="text-decoration:none">
      <span class="symbol" aria-hidden="true"><i class="fa-solid fa-star-of-david"></i></span>
      <div class="brand-text">
        <div class="brand-name">Estrella Del Rey David</div>
        <div class="brand-sub">Portal de Miembros</div>
      </div>
    </a>
    <div class="navbar-links">
      <span style="color:var(--gold);font-size:13px;margin-right:8px">
        Bienvenido, <?= e(explode(' ', $member['name'])[0]) ?>
      </span>
      <a href="/api/auth.php?logout=1" class="nav-link">Cerrar Sesión</a>
    </div>
  </div>
</nav>

<!-- MAIN CONTENT -->
<div style="max-width:1100px;margin:0 auto;padding:2rem">

  <!-- Page Header -->
  <div class="page-header animate-fadeUp">
    <div style="display:flex;align-items:center;gap:16px">
      <div class="avatar"><?= e(strtoupper($initials)) ?></div>
      <div>
        <h1 class="page-title"><?= e($member['name']) ?></h1>
        <div class="page-sub"><?= e($member['role']) ?> · <?= e($member['degree']) ?>° — <?= e($member['degree_name']) ?></div>
      </div>
    </div>
    <span class="badge <?= $member['active'] ? 'badge-success' : 'badge-danger' ?>" style="font-size:13px;padding:6px 14px">
      <?= $member['active'] ? '● Activo' : '● Inactivo' ?>
    </span>
  </div>

  <!-- Quick Stats -->
  <div class="stats-grid animate-fadeUp delay-1">
    <div class="stat-card">
      <div class="stat-label">Meses Pendientes <?= $year ?></div>
      <div class="stat-value" style="color:<?= $owed > 0 ? 'var(--danger)' : 'var(--success)' ?>"><?= $owed ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Cuota Pendiente</div>
      <div class="stat-value" style="color:<?= $owedAmount > 0 ? 'var(--danger)' : 'var(--success)' ?>">$<?= number_format($owedAmount,2) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Total Donado</div>
      <div class="stat-value">$<?= number_format($totalDonated,2) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Miembro Desde</div>
      <div class="stat-value" style="font-size:1.3rem"><?= date('Y', strtotime($member['joined_date'])) ?></div>
    </div>
  </div>

  <!-- Tabs -->
  <div data-tabs class="animate-fadeUp delay-2">
    <div class="tabs">
      <button class="tab-btn active" data-tab="dues">Cuotas Mensuales</button>
      <button class="tab-btn" data-tab="profile">Mi Perfil</button>
      <button class="tab-btn" data-tab="donations">Mis Donaciones</button>
      <button class="tab-btn" data-tab="news">Comunicados</button>
    </div>

    <!-- TAB: DUES CALENDAR -->
    <div data-tab-content="dues" class="tab-content active">
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem;flex-wrap:wrap;gap:1rem">
          <h3 style="color:var(--gold)">Estado de Cuotas — <?= $year ?></h3>
          <!-- Year selector -->
          <form method="GET" style="display:flex;align-items:center;gap:8px">
            <label style="font-size:12px;color:var(--text-muted)">Año:</label>
            <select name="year" onchange="this.form.submit()" class="form-control" style="width:100px;padding:6px 10px">
              <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </form>
        </div>

        <!-- Monthly Rate -->
        <?php if ($monthlyRate > 0): ?>
        <p style="color:var(--text-muted);font-size:13px;margin-bottom:1.25rem">
          Cuota mensual: <strong style="color:var(--gold)">$<?= number_format($monthlyRate,2) ?></strong>
        </p>
        <?php endif; ?>

        <!-- Calendar Grid -->
        <div class="dues-calendar">
          <?php for ($m = 1; $m <= 12; $m++):
            $d = $duesMap[$m] ?? null;
            $isPast   = $m <= $currentMonth || $year < date('Y');
            $isPaid   = $d && $d['paid'];
            $isOwed   = $isPast && !$isPaid;
            $isFuture = $m > $currentMonth && $year == date('Y');
            $cls = $isPaid ? 'paid' : ($isOwed ? 'owed' : 'future');
          ?>
          <div class="dues-month <?= $cls ?>" title="<?= $months[$m] ?> <?= $year ?>">
            <span class="dues-month-name"><?= $monthsAbbr[$m] ?></span>
            <?php if ($isPaid): ?>
              <span class="dues-month-amt">✓ Pagado</span>
              <?php if ($d['paid_date']): ?>
              <span style="font-size:10px;display:block;margin-top:2px"><?= date('d/m', strtotime($d['paid_date'])) ?></span>
              <?php endif; ?>
            <?php elseif ($isOwed): ?>
              <span class="dues-month-amt">⚠ Pendiente</span>
              <?php if ($monthlyRate > 0): ?>
              <span style="font-size:10px;display:block;margin-top:2px">$<?= number_format($monthlyRate,2) ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="dues-month-amt" style="font-size:10px">—</span>
            <?php endif; ?>
          </div>
          <?php endfor; ?>
        </div>

        <!-- Legend -->
        <div style="display:flex;gap:1.5rem;margin-top:1.25rem;font-size:12px;flex-wrap:wrap">
          <span><span style="color:var(--success)">■</span> Pagado</span>
          <span><span style="color:var(--danger)">■</span> Pendiente</span>
          <span><span style="color:var(--text-muted)">■</span> Próximo</span>
        </div>

        <?php if ($owed > 0): ?>
        <div class="form-error" style="margin-top:1.5rem">
          ⚠ Tienes <strong><?= $owed ?> mes(es)</strong> de cuota pendiente por un total de
          <strong>$<?= number_format($owedAmount,2) ?></strong>. Por favor contáctate con el Tesorero.
        </div>
        <?php else: ?>
        <div class="form-success" style="margin-top:1.5rem">
          ✓ Tus cuotas están al corriente. ¡Gracias, Hermano!
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- TAB: PROFILE -->
    <div data-tab-content="profile" class="tab-content">
      <div class="grid-2">
        <div class="card">
          <h3 style="color:var(--gold);margin-bottom:1rem">Información Personal</h3>
          <?php
          $fields = [
            'Nombre Completo' => $member['name'],
            'Correo' => $member['email'],
            'Teléfono' => $member['phone'] ?: '—',
            'Dirección' => $member['address'] ?: '—',
          ];
          foreach ($fields as $label => $val): ?>
          <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid rgba(74,114,196,0.15)">
            <span style="color:var(--text-muted);font-size:13px"><?= e($label) ?></span>
            <span style="color:#fff;font-size:13px"><?= e($val) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="card">
          <h3 style="color:var(--gold);margin-bottom:1rem">Información Masónica</h3>
          <?php
          $mfields = [
            'Cargo' => $member['role'],
            'Grado' => $member['degree'] . '° — ' . $member['degree_name'],
            'Estado' => $member['active'] ? 'Activo' : 'Inactivo',
            'Fecha de Ingreso' => date('d F Y', strtotime($member['joined_date'])),
            'Años en la Logia' => (date('Y') - date('Y', strtotime($member['joined_date']))) . ' años',
          ];
          foreach ($mfields as $label => $val): ?>
          <div style="display:flex;justify-content:space-between;padding:10px 0;border-bottom:1px solid rgba(74,114,196,0.15)">
            <span style="color:var(--text-muted);font-size:13px"><?= e($label) ?></span>
            <span style="color:#fff;font-size:13px"><?= e($val) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- TAB: DONATIONS -->
    <div data-tab-content="donations" class="tab-content">
      <div class="card">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1.5rem">
          <h3 style="color:var(--gold)">Historial de Donaciones</h3>
          <span style="color:var(--gold);font-weight:bold">Total: $<?= number_format($totalDonated,2) ?></span>
        </div>
        <?php if (empty($myDonations)): ?>
          <p style="color:var(--text-muted);text-align:center;padding:2rem">No hay donaciones registradas aún.</p>
        <?php else: ?>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Fecha</th><th>Categoría</th><th>Monto</th><th>Nota</th></tr></thead>
            <tbody>
              <?php foreach ($myDonations as $d): ?>
              <tr>
                <td><?= e(date('d M Y', strtotime($d['date']))) ?></td>
                <td><span class="badge badge-gold"><?= e($d['category']) ?></span></td>
                <td style="color:var(--success);font-weight:bold">$<?= number_format($d['amount'],2) ?></td>
                <td><?= e($d['note'] ?? '—') ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- TAB: NEWS -->
    <div data-tab-content="news" class="tab-content">
      <div style="display:flex;flex-direction:column;gap:1rem">
        <?php if (empty($news)): ?>
          <p style="color:var(--text-muted);text-align:center;padding:2rem">No hay comunicados disponibles.</p>
        <?php else: ?>
          <?php foreach ($news as $n): ?>
          <article class="card card-gold">
            <div class="news-date">
              <?= e(date('d F Y', strtotime($n['created_at']))) ?> · <?= e($n['author']) ?>
            </div>
            <h3 class="news-title"><?= e($n['title']) ?></h3>
            <p class="news-body" style="white-space:pre-wrap"><?= nl2br(e($n['body'])) ?></p>
          </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /tabs -->
</div>

<footer>
  <span class="footer-symbol" aria-hidden="true"><i class="fa-solid fa-star-of-david"></i></span>
  <div class="footer-name">Estrella Del Rey David Numero 11</div>
  <p class="footer-copy">© <?= date('Y') ?> · Todos los derechos reservados</p>
</footer>

<script src="/assets/js/app.js"></script>
</body>
</html>