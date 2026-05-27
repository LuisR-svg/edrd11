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


// ── Header config ──────────────────────────────────────────
$pageTitle   = 'Portal de Miembros';
$pageContext = 'member';
require_once __DIR__ . '/../includes/header.php';
?>

<!-- MAIN CONTENT -->
<div class="page-content">
  <!-- Page Header -->
  <div class="page-header animate-fadeUp">s
    <div class="d-flex align-center gap-2">
      <div class="avatar"><?= e(strtoupper($initials)) ?></div>
      <div>
        <h1 class="page-title"><?= e($member['name']) ?></h1>
        <div class="page-sub"><?= e($member['role']) ?> · <?= e($member['degree']) ?>° — <?= e($member['degree_name']) ?></div>
      </div>
    </div>
    <span class="badge <?= $member['active'] ? 'badge-success' : 'badge-danger' ?> badge-lg">
      <?= $member['active'] ? '● Activo' : '● Inactivo' ?>
    </span>
  </div>

  <!-- Quick Stats -->
  <div class="stats-grid animate-fadeUp delay-1">
    <div class="stat-card">
      <div class="stat-label">Meses Pendientes <?= $year ?></div>
      <div class="stat-value" data-owing="<?= $owed ?>"><?= $owed ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Cuota Pendiente</div>
      <div class="stat-value <?= $owedAmount > 0 ? 'text-danger' : 'text-success' ?>">$<?= number_format($owedAmount,2) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Total Donado</div>
      <div class="stat-value">$<?= number_format($totalDonated,2) ?></div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Miembro Desde</div>
      <div class="stat-value sm"><?= date('Y', strtotime($member['joined_date'])) ?></div>
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
        <div class="card-header">
          <h3 class="text-gold">Estado de Cuotas — <?= $year ?></h3>
          <!-- Year selector -->
          <form method="GET" class="year-select-form">
            <label class="form-label text-sm">Año:</label>
            <select name="year" onchange="this.form.submit()" class="form-control select-sm">
              <?php for ($y = date('Y'); $y >= 2020; $y--): ?>
                <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
              <?php endfor; ?>
            </select>
          </form>
        </div>

        <!-- Monthly Rate -->
        <?php if ($monthlyRate > 0): ?>
        <p class="dues-rate-note">
          Cuota mensual: <strong class="text-gold">$<?= number_format($monthlyRate,2) ?></strong>
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
              <span class="text-xs"><?= date('d/m', strtotime($d['paid_date'])) ?></span>
              <?php endif; ?>
            <?php elseif ($isOwed): ?>
              <span class="dues-month-amt">⚠ Pendiente</span>
              <?php if ($monthlyRate > 0): ?>
              <span class="text-xs">$<?= number_format($monthlyRate,2) ?></span>
              <?php endif; ?>
            <?php else: ?>
              <span class="dues-month-amt text-xs">—</span>
            <?php endif; ?>
          </div>
          <?php endfor; ?>
        </div>

        <!-- Legend -->
        <div class="dues-legend">
          <span><span class="text-success">■</span> Pagado</span>
          <span><span class="text-danger">■</span> Pendiente</span>
          <span><span class="text-muted">■</span> Próximo</span>
        </div>

        <?php if ($owed > 0): ?>
        <div class="form-error" class="mt-3">
          ⚠ Tienes <strong><?= $owed ?> mes(es)</strong> de cuota pendiente por un total de
          <strong>$<?= number_format($owedAmount,2) ?></strong>. Por favor contáctate con el Tesorero.
        </div>
        <?php else: ?>
        <div class="form-success" class="mt-3">
          ✓ Tus cuotas están al corriente. ¡Gracias, Hermano!
        </div>
        <?php endif; ?>
      </div>
    </div>

    <!-- TAB: PROFILE -->
    <div data-tab-content="profile" class="tab-content">
      <div class="grid-2">
        <div class="card">
          <h3 class="text-gold mb-2">Información Personal</h3>
          <?php
          $fields = [
            'Nombre Completo' => $member['name'],
            'Correo' => $member['email'],
            'Teléfono' => $member['phone'] ?: '—',
            'Dirección' => $member['address'] ?: '—',
          ];
          foreach ($fields as $label => $val): ?>
          <div class="profile-row">
            <span class="profile-label"><?= e($label) ?></span>
            <span class="profile-value"><?= e($val) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="card">
          <h3 class="text-gold mb-2">Información Masónica</h3>
          <?php
          $mfields = [
            'Cargo' => $member['role'],
            'Grado' => $member['degree'] . '° — ' . $member['degree_name'],
            'Estado' => $member['active'] ? 'Activo' : 'Inactivo',
            'Fecha de Ingreso' => date('d F Y', strtotime($member['joined_date'])),
            'Años en la Logia' => (date('Y') - date('Y', strtotime($member['joined_date']))) . ' años',
          ];
          foreach ($mfields as $label => $val): ?>
          <div class="profile-row">
            <span class="profile-label"><?= e($label) ?></span>
            <span class="profile-value"><?= e($val) ?></span>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <!-- TAB: DONATIONS -->
    <div data-tab-content="donations" class="tab-content">
      <div class="card">
        <div class="card-subheader">
          <h3 class="text-gold">Historial de Donaciones</h3>
          <span class="text-gold text-bold">Total: $<?= number_format($totalDonated,2) ?></span>
        </div>
        <?php if (empty($myDonations)): ?>
          <p class="empty-state">No hay donaciones registradas aún.</p>
        <?php else: ?>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Fecha</th><th>Categoría</th><th>Monto</th><th>Nota</th></tr></thead>
            <tbody>
              <?php foreach ($myDonations as $d): ?>
              <tr>
                <td><?= e(date('d M Y', strtotime($d['date']))) ?></td>
                <td><span class="badge badge-gold"><?= e($d['category']) ?></span></td>
                <td class="text-success text-bold">$<?= number_format($d['amount'],2) ?></td>
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
      <div class="d-flex flex-col gap-2">
        <?php if (empty($news)): ?>
          <p class="empty-state">No hay comunicados disponibles.</p>
        <?php else: ?>
          <?php foreach ($news as $n): ?>
          <article class="card card-gold">
            <div class="news-date">
              <?= e(date('d F Y', strtotime($n['created_at']))) ?> · <?= e($n['author']) ?>
            </div>
            <h3 class="news-title"><?= e($n['title']) ?></h3>
            <p class="news-body" class="pre-wrap"><?= nl2br(e($n['body'])) ?></p>
          </article>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

  </div><!-- /tabs -->
</div>

<?php
require_once __DIR__ . '/../includes/footer.php';