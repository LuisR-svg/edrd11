<?php
/**
 * admin/dashboard.php — Full Admin Control Panel
 * ============================================================
 * Requires admin login. All financial management lives here.
 * Tabs: Dashboard · Members · Finances · Dues · Donations · News · Reports
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/degrees.php';

secure_session_start();
require_admin();   // redirects to /?login=admin if not logged in

$pdo         = DB::get();
$activeTab   = get_param('tab', 'dashboard');
$year        = int_val(get_param('year', date('Y')));
$filterMonth = int_val(get_param('month', 0));

// ── AGGREGATE STATS ──────────────────────────────────────
$totalIncome   = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='income'")->fetchColumn();
$totalExpenses = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='expense'")->fetchColumn();
$totalDonations= (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM donations")->fetchColumn();
$totalSavings  = (float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN type='deposit' THEN amount ELSE -amount END),0) FROM savings")->fetchColumn();
$totalDuesOwed = (float)$pdo->query("SELECT COALESCE(SUM(d.amount),0) FROM dues d WHERE d.paid=0")->fetchColumn();
$activeMembers = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE active=1")->fetchColumn();
$totalMembers  = (int)$pdo->query("SELECT COUNT(*) FROM members")->fetchColumn();
$balance       = $totalIncome + $totalDonations - $totalExpenses;

// ── MEMBERS ───────────────────────────────────────────────
$members = $pdo->query("SELECT * FROM members ORDER BY name")->fetchAll();

// ── TRANSACTIONS (filtered) ───────────────────────────────
$txSql    = "SELECT t.*, m.name as member_name FROM transactions t LEFT JOIN members m ON t.member_id=m.id WHERE YEAR(t.date)=?";
$txParams = [$year];
if ($filterMonth > 0) { $txSql .= " AND MONTH(t.date)=?"; $txParams[] = $filterMonth; }
$txSql .= " ORDER BY t.date DESC";
$txStmt = $pdo->prepare($txSql); $txStmt->execute($txParams);
$transactions = $txStmt->fetchAll();

// Filtered totals
$filteredIncome   = array_sum(array_column(array_filter($transactions, fn($t)=>$t['type']==='income'),  'amount'));
$filteredExpenses = array_sum(array_column(array_filter($transactions, fn($t)=>$t['type']==='expense'), 'amount'));

// ── DUES (all members × 12 months for selected year) ──────
$duesRows = $pdo->prepare(
    "SELECT d.*, m.name, m.email, m.role FROM dues d JOIN members m ON d.member_id=m.id WHERE d.year=? ORDER BY m.name, d.month"
);
$duesRows->execute([$year]);
$allDues = $duesRows->fetchAll();
// Map: member_id → month → dues row
$duesMap = [];
foreach ($allDues as $d) $duesMap[$d['member_id']][$d['month']] = $d;

// Dues settings for year
$duesSetting = $pdo->prepare("SELECT amount FROM dues_settings WHERE year=?");
$duesSetting->execute([$year]);
$monthlyRate = (float)($duesSetting->fetchColumn() ?: 0);

// ── DONATIONS ─────────────────────────────────────────────
$donations = $pdo->query(
    "SELECT don.*, m.name as member_name FROM donations don LEFT JOIN members m ON don.member_id=m.id ORDER BY don.date DESC"
)->fetchAll();

// ── SAVINGS ───────────────────────────────────────────────
$savingsRows = $pdo->query("SELECT * FROM savings ORDER BY date DESC")->fetchAll();

// ── NEWS ──────────────────────────────────────────────────
$newsRows = $pdo->query("SELECT * FROM news ORDER BY created_at DESC")->fetchAll();

// ── MONTHLY CHART DATA ────────────────────────────────────
$chartStmt = $pdo->prepare(
    "SELECT MONTH(date) as m,
     SUM(CASE WHEN type='income' THEN amount ELSE 0 END) as income,
     SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) as expenses
     FROM transactions WHERE YEAR(date)=? GROUP BY MONTH(date)"
);
$chartStmt->execute([$year]);
$chartRaw = $chartStmt->fetchAll();
$chartData = array_fill(1, 12, ['income'=>0,'expenses'=>0]);
foreach ($chartRaw as $c) $chartData[(int)$c['m']] = ['income'=>(float)$c['income'],'expenses'=>(float)$c['expenses']];

$MONTHS     = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$MONTHS_F   = ['','January','February','March','April','May','June','July','August','September','October','November','December'];
$adminName  = e($_SESSION['admin_name'] ?? 'Administrator');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <meta name="csrf-token" content="<?= csrf_token() ?>">
  <title>Admin Panel — <?= APP_NAME ?></title>
  <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@400;700&family=Inter:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/7.0.1/css/all.min.css"/>
  <link rel="stylesheet" href="/assets/css/style.css?v=1.7">
</head>
<body>

<!-- ── NAVBAR ── -->
<nav class="navbar">
  <div class="navbar-inner">
    <a href="/" class="navbar-brand" style="text-decoration:none">
      <span class="symbol"><i class="fa-solid fa-star-of-david"></i></span>
      <div class="brand-text">
        <div class="brand-name">Estrella Del Rey David</div>
        <div class="brand-sub">Panel Administrativo</div>
      </div>
    </a>
    <div class="navbar-links">
      <span style="color:var(--gold);font-size:13px;margin-right:8px"><i class="fa-solid fa-star-of-david"></i> <?= $adminName ?></span>
      <a href="/" class="nav-link">Sitio Público</a>
      <a href="/api/auth.php?logout=1" class="nav-link">Cerrar Sesión</a>
    </div>
  </div>
</nav>

<div class="admin-wrap">

  <!-- ── SIDEBAR ── -->
  <aside class="sidebar">
    <div style="padding:0 1.2rem 1rem;border-bottom:1px solid var(--border);margin-bottom:1rem">
      <div style="font-size:11px;color:var(--text-muted)">Sesión activa</div>
      <div style="font-size:13px;color:var(--gold);margin-top:2px"><?= $adminName ?></div>
    </div>
    <div class="sidebar-lbl">Panel</div>
    <?php
    $tabs = [
      'dashboard'  => ['<i class="fa-solid fa-star-of-david"></i>','Resumen General'],
      'members'    => ['👤','Miembros'],
      'finances'   => ['💰','Finanzas'],
      'dues'       => ['📋','Cuotas'],
      'donations'  => ['🎁','Donaciones'],
      'savings'    => ['🏦','Ahorros'],
      'news'       => ['📢','Comunicados'],
      'reports'    => ['📊','Reportes'],
    ];
    foreach ($tabs as $id => [$icon, $label]):
      $cls = $activeTab === $id ? 'active' : '';
    ?>
    <a href="?tab=<?= $id ?>&year=<?= $year ?>" class="sidebar-link <?= $cls ?>">
      <span><?= $icon ?></span><?= $label ?>
    </a>
    <?php endforeach; ?>
    <div style="padding:1rem 1.2rem;margin-top:1rem;border-top:1px solid var(--border)">
      <a href="/api/reports.php?type=financial&format=pdf&year=<?= $year ?>" target="_blank"
         class="btn btn-gold btn-sm btn-full">🖨 Reporte Rápido</a>
    </div>
  </aside>

  <!-- ── MAIN ── -->
  <main class="main">

    <!-- ══════════════════════════════════════════════════ -->
    <!-- TAB: DASHBOARD                                      -->
    <!-- ══════════════════════════════════════════════════ -->
    <?php if ($activeTab === 'dashboard'): ?>
    <div class="tab-panel active animate-fadeUp">
      <div class="page-header">
        <div><h1 class="page-title">Resumen General</h1>
          <div class="page-sub">Año fiscal <?= $year ?></div></div>
        <div style="display:flex;gap:8px;align-items:center">
          <form method="GET" style="display:flex;gap:6px;align-items:center">
            <input type="hidden" name="tab" value="dashboard">
            <select name="year" onchange="this.form.submit()" class="form-control" style="width:90px;padding:6px 8px">
              <?php for($y=date('Y');$y>=2020;$y--): ?>
              <option value="<?=$y?>" <?=$y==$year?'selected':''?>><?=$y?></option>
              <?php endfor; ?>
            </select>
          </form>
        </div>
      </div>

      <!-- Stats grid -->
      <div class="stats-grid">
        <div class="stat-card"><div class="stat-label">Balance General</div>
          <div class="stat-value" style="color:<?=$balance>=0?'var(--success)':'var(--danger)'?>">$<?=number_format($balance,2)?></div></div>
        <div class="stat-card"><div class="stat-label">Ingresos Totales</div>
          <div class="stat-value" style="color:var(--success)">$<?=number_format($totalIncome+$totalDonations,2)?></div></div>
        <div class="stat-card"><div class="stat-label">Egresos Totales</div>
          <div class="stat-value" style="color:var(--danger)">$<?=number_format($totalExpenses,2)?></div></div>
        <div class="stat-card"><div class="stat-label">Ahorros</div>
          <div class="stat-value" style="color:var(--accent)">$<?=number_format($totalSavings,2)?></div></div>
        <div class="stat-card"><div class="stat-label">Cuotas Pendientes</div>
          <div class="stat-value" style="color:var(--warning)">$<?=number_format($totalDuesOwed,2)?></div></div>
        <div class="stat-card"><div class="stat-label">Miembros Activos</div>
          <div class="stat-value"><?=$activeMembers?> / <?=$totalMembers?></div></div>
      </div>

      <!-- Monthly chart -->
      <div class="card" style="margin-top:1.5rem">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
          <h3 style="color:var(--gold)">Movimiento Mensual <?= $year ?></h3>
          <div style="font-size:12px;display:flex;gap:12px">
            <span style="color:var(--success)">■ Ingresos</span>
            <span style="color:var(--danger)">■ Egresos</span>
          </div>
        </div>
        <?php
        $maxVal = max(array_map(fn($c)=>max($c['income'],$c['expenses']), $chartData), 1);
        ?>
        <div class="bar-wrap">
          <?php for($m=1;$m<=12;$m++):
            $ih = round(($chartData[$m]['income']  / $maxVal)*95);
            $eh = round(($chartData[$m]['expenses']/ $maxVal)*95);
          ?>
          <div class="bar-col">
            <div style="display:flex;align-items:flex-end;gap:2px;height:95px">
              <div class="bar-in" style="height:<?=$ih?>px;width:12px" title="Ing: $<?=number_format($chartData[$m]['income'],2)?>"></div>
              <div class="bar-ex" style="height:<?=$eh?>px;width:12px" title="Egr: $<?=number_format($chartData[$m]['expenses'],2)?>"></div>
            </div>
            <span class="bar-lbl"><?=$MONTHS[$m]?></span>
          </div>
          <?php endfor; ?>
        </div>
      </div>

      <!-- Recent transactions -->
      <div class="card" style="margin-top:1.5rem">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:1rem">
          <h3 style="color:var(--gold)">Últimas Transacciones</h3>
          <a href="?tab=finances&year=<?=$year?>" class="btn btn-outline btn-sm">Ver todas →</a>
        </div>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th>Categoría</th><th>Monto</th></tr></thead>
            <tbody>
              <?php foreach(array_slice($transactions,0,8) as $t): ?>
              <tr>
                <td><?=e($t['date'])?></td>
                <td><span class="badge <?=$t['type']==='income'?'badge-income':'badge-expense'?>"><?=$t['type']==='income'?'Ingreso':'Egreso'?></span></td>
                <td><?=e($t['description'])?></td>
                <td><span class="badge badge-gold"><?=e($t['category'])?></span></td>
                <td style="color:<?=$t['type']==='income'?'var(--success)':'var(--danger)'?>;font-weight:bold">$<?=number_format($t['amount'],2)?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════ -->
    <!-- TAB: MEMBERS                                        -->
    <!-- ══════════════════════════════════════════════════ -->
    <?php elseif($activeTab==='members'): ?>
    <div class="tab-panel active animate-fadeUp">
      <div class="page-header">
        <div><h1 class="page-title">Miembros</h1>
          <div class="page-sub"><?=$totalMembers?> registrados · <?=$activeMembers?> activos</div></div>
        <button class="btn btn-gold" onclick="toggleSection('add-member-form')">+ Agregar Miembro</button>
      </div>

      <!-- Add member form (hidden by default) -->
      <div id="add-member-form" style="display:none" class="card" style="margin-bottom:1.5rem">
        <h3 style="color:var(--gold);margin-bottom:1rem">Agregar Nuevo Miembro</h3>
        <form id="form-add-member">
          <?= csrf_field() ?>
          <div class="form-row">
            <div class="form-group"><label class="form-label">Nombre Completo *</label>
              <input type="text" name="name" class="form-control" required></div>
            <div class="form-group"><label class="form-label">Correo *</label>
              <input type="email" name="email" class="form-control" required></div>
            <div class="form-group"><label class="form-label">PIN de Acceso (4-8 dígitos) *</label>
              <input type="text" name="pin" class="form-control" maxlength="8" pattern="[0-9]+" placeholder="e.g. 1234" required></div>
            <div class="form-group"><label class="form-label">Cargo / Rol</label>
              <input type="text" name="role" class="form-control" value="Member"></div>
            <div class="form-group"><label class="form-label">Grado (Rito Escocés)</label>
              <select name="degree" class="form-control"><?= degrees_options(3) ?></select></div>
            <div class="form-group"><label class="form-label">Fecha de Ingreso</label>
              <input type="date" name="joined_date" class="form-control" value="<?=date('Y-m-d')?>"></div>
            <div class="form-group"><label class="form-label">Teléfono</label>
              <input type="text" name="phone" class="form-control"></div>
            <div class="form-group form-full"><label class="form-label">Dirección</label>
              <input type="text" name="address" class="form-control"></div>
            <div class="form-group form-full"><label class="form-label">Notas</label>
              <textarea name="notes" class="form-control"></textarea></div>
          </div>
          <div style="display:flex;gap:10px;margin-top:.5rem">
            <button type="submit" class="btn btn-gold">Guardar Miembro</button>
            <button type="button" class="btn btn-outline" onclick="toggleSection('add-member-form')">Cancelar</button>
          </div>
        </form>
      </div>

      <!-- Members table -->
      <div class="card">
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Nombre</th><th>Cargo</th><th>Grado</th><th>Estado</th><th>Cuotas Pendientes</th><th>Ingreso</th><th>Acciones</th></tr></thead>
            <tbody>
              <?php foreach($members as $mem):
                // Calculate owed dues for this member in current year
                $memOwed = 0;
                for($m=1;$m<=(int)date('n');$m++){
                  if(!isset($duesMap[$mem['id']][$m]) || !$duesMap[$mem['id']][$m]['paid']) $memOwed++;
                }
                $owedAmt = $memOwed * $monthlyRate;
              ?>
              <tr data-member-id="<?=$mem['id']?>">
                <td>
                  <strong><?=e($mem['name'])?></strong><br>
                  <span style="font-size:11px;color:var(--text-muted)"><?=e($mem['email'])?></span>
                </td>
                <td><?=e($mem['role'])?></td>
                <td><?=e($mem['degree'])?><?=e('° — ')?><?=e($mem['degree_name'])?></td>
                <td><span class="badge <?=$mem['active']?'badge-success':'badge-danger'?>"><?=$mem['active']?'Activo':'Inactivo'?></span></td>
                <td>
                  <?php if($owedAmt > 0): ?>
                    <span style="color:var(--danger);font-weight:bold"><?=$memOwed?> mes(es) — $<?=number_format($owedAmt,2)?></span>
                  <?php else: ?>
                    <span style="color:var(--success)">Al corriente ✓</span>
                  <?php endif; ?>
                </td>
                <td style="font-size:12px"><?=date('d/m/Y',strtotime($mem['joined_date']))?></td>
                <td>
                  <div style="display:flex;gap:6px;flex-wrap:wrap">
                    <button class="btn btn-outline btn-sm" onclick="editMember(<?=$mem['id']?>,<?=htmlspecialchars(json_encode($mem),ENT_QUOTES)?>)">Editar</button>
                    <button class="btn btn-sm <?=$mem['active']?'btn-danger':'btn-success'?>"
                      onclick="toggleMember(<?=$mem['id']?>)"><?=$mem['active']?'Desactivar':'Activar'?></button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════ -->
    <!-- TAB: FINANCES                                       -->
    <!-- ══════════════════════════════════════════════════ -->
    <?php elseif($activeTab==='finances'): ?>
    <div class="tab-panel active animate-fadeUp">
      <div class="page-header">
        <div><h1 class="page-title">Finanzas</h1>
          <div class="page-sub">Todas las transacciones</div></div>
        <button class="btn btn-gold" onclick="toggleSection('add-tx-form')">+ Nueva Transacción</button>
      </div>

      <!-- Period filter -->
      <div class="card" style="margin-bottom:1.5rem">
        <form method="GET" style="display:flex;gap:1rem;align-items:flex-end;flex-wrap:wrap">
          <input type="hidden" name="tab" value="finances">
          <div class="form-group" style="margin:0">
            <label class="form-label">Año</label>
            <select name="year" class="form-control" style="width:90px">
              <?php for($y=date('Y');$y>=2020;$y--): ?>
              <option value="<?=$y?>" <?=$y==$year?'selected':''?>><?=$y?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">Mes (opcional)</label>
            <select name="month" class="form-control" style="width:120px">
              <option value="0" <?=$filterMonth==0?'selected':''?>>Todos</option>
              <?php for($m=1;$m<=12;$m++): ?>
              <option value="<?=$m?>" <?=$m==$filterMonth?'selected':''?>><?=$MONTHS_F[$m]?></option>
              <?php endfor; ?>
            </select>
          </div>
          <button type="submit" class="btn btn-primary">Filtrar</button>
        </form>
        <!-- Filtered summary -->
        <div style="display:flex;gap:2rem;margin-top:1rem;flex-wrap:wrap">
          <div><span style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px">Ingresos</span>
            <div style="color:var(--success);font-weight:bold;font-size:18px">$<?=number_format($filteredIncome,2)?></div></div>
          <div><span style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px">Egresos</span>
            <div style="color:var(--danger);font-weight:bold;font-size:18px">$<?=number_format($filteredExpenses,2)?></div></div>
          <div><span style="font-size:11px;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px">Balance</span>
            <div style="color:<?=($filteredIncome-$filteredExpenses)>=0?'var(--success)':'var(--danger)'?>;font-weight:bold;font-size:18px">$<?=number_format($filteredIncome-$filteredExpenses,2)?></div></div>
        </div>
      </div>

      <!-- Add transaction form -->
      <div id="add-tx-form" style="display:none" class="card" style="margin-bottom:1.5rem">
        <h3 style="color:var(--gold);margin-bottom:1rem">Nueva Transacción</h3>
        <form id="form-add-tx">
          <?= csrf_field() ?>
          <div class="form-row">
            <div class="form-group"><label class="form-label">Tipo *</label>
              <select name="type" class="form-control" id="tx-type">
                <option value="income">Ingreso</option>
                <option value="expense">Egreso</option>
              </select></div>
            <div class="form-group"><label class="form-label">Monto ($) *</label>
              <input type="number" name="amount" id="tx-amount" class="form-control" min="0.01" step="0.01" required></div>
            <div class="form-group"><label class="form-label">Fecha *</label>
              <input type="date" name="date" class="form-control" value="<?=date('Y-m-d')?>" required></div>
            <div class="form-group"><label class="form-label">Categoría *</label>
              <select name="category" id="tx-category" class="form-control">
                <option>General</option><option>Dues</option><option>Donations</option>
                <option>Events</option><option>Maintenance</option><option>Administrative</option>
                <option>Operations</option><option>Charity</option><option>Education</option><option>Other</option>
              </select></div>
            <div class="form-group" id="tx-member-row"><label class="form-label">Miembro (opcional)</label>
              <select name="member_id" class="form-control">
                <option value="">— Sin miembro —</option>
                <?php foreach($members as $mem): ?>
                <option value="<?=$mem['id']?>"><?=e($mem['name'])?></option>
                <?php endforeach; ?>
              </select></div>
            <div class="form-group"><label class="form-label">Referencia / Recibo</label>
              <input type="text" name="reference" class="form-control" placeholder="Opcional"></div>
            <div class="form-group form-full"><label class="form-label">Descripción *</label>
              <input type="text" name="description" class="form-control" required></div>
          </div>
          <!-- Dues month selector (shown only when category=Dues) -->
          <div id="dues-month-row" style="display:none;margin-top:.5rem">
            <label class="form-label">Meses que Cubre este Pago</label>
            <div style="margin-bottom:.5rem">
              <div class="form-group" style="display:inline-block;margin-right:1rem">
                <label class="form-label" style="display:inline">Año:</label>
                <select name="dues_year" class="form-control" style="width:90px;display:inline-block;margin-left:6px">
                  <?php for($y=date('Y');$y>=2020;$y--): ?><option value="<?=$y?>"><?=$y?></option><?php endfor; ?>
                </select>
              </div>
              <button type="button" class="btn btn-outline btn-sm" onclick="selectAllMonths()">Seleccionar Todos</button>
            </div>
            <div style="display:grid;grid-template-columns:repeat(6,1fr);gap:.4rem">
              <?php for($m=1;$m<=12;$m++): ?>
              <label style="display:flex;align-items:center;gap:5px;font-size:12px;cursor:pointer;padding:5px 6px;border:1px solid var(--border);border-radius:5px">
                <input type="checkbox" class="dues-month-check" value="<?=$m?>" style="accent-color:var(--gold)"> <?=$MONTHS[$m]?>
              </label>
              <?php endfor; ?>
            </div>
            <div id="dues-month-total" style="margin-top:.5rem;font-size:13px;color:var(--gold)"></div>
          </div>
          <div style="display:flex;gap:10px;margin-top:1rem">
            <button type="submit" class="btn btn-gold">Guardar Transacción</button>
            <button type="button" class="btn btn-outline" onclick="toggleSection('add-tx-form')">Cancelar</button>
          </div>
        </form>
      </div>

      <!-- Transactions table -->
      <div class="card">
        <h3 style="color:var(--gold);margin-bottom:1rem">Transacciones <?=$filterMonth>0?$MONTHS_F[$filterMonth].' ':''?><?=$year?></h3>
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th>Categoría</th><th>Miembro</th><th>Monto</th><th>Acciones</th></tr></thead>
            <tbody>
              <?php foreach($transactions as $t): ?>
              <tr data-id="<?=$t['id']?>" data-type="<?=e($t['type'])?>" data-date="<?=e($t['date'])?>" data-amount="<?=e($t['amount'])?>">
                <td data-field="date" data-edit="date" data-val="<?=e($t['date'])?>"><?=e($t['date'])?></td>
                <td><span class="badge <?=$t['type']==='income'?'badge-income':'badge-expense'?>"><?=$t['type']==='income'?'Ingreso':'Egreso'?></span></td>
                <td data-field="description" data-edit="text" data-val="<?=e($t['description'])?>"><?=e($t['description'])?></td>
                <td data-field="category" data-edit="text" data-val="<?=e($t['category'])?>"><?=e($t['category'])?></td>
                <td style="font-size:12px;color:var(--text-muted)"><?=e($t['member_name']??'—')?></td>
                <td data-field="amount" data-edit="number" data-val="<?=e($t['amount'])?>"
                    style="color:<?=$t['type']==='income'?'var(--success)':'var(--danger)'?>;font-weight:bold">
                    $<?=number_format($t['amount'],2)?></td>
                <td>
                  <div style="display:flex;gap:4px">
                    <button class="btn btn-outline btn-sm edit-btn"   onclick="enableEditRow(<?=$t['id']?>)">Editar</button>
                    <button class="btn btn-gold btn-sm save-btn"      onclick="saveEditRow(<?=$t['id']?>)" style="display:none">Guardar</button>
                    <button class="btn btn-outline btn-sm cancel-btn" onclick="location.reload()" style="display:none">✕</button>
                    <button class="btn btn-danger btn-sm"             onclick="deleteTx(<?=$t['id']?>)">✕</button>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════ -->
    <!-- TAB: DUES                                           -->
    <!-- ══════════════════════════════════════════════════ -->
    <?php elseif($activeTab==='dues'): ?>
    <div class="tab-panel active animate-fadeUp">
      <div class="page-header">
        <div><h1 class="page-title">Control de Cuotas</h1>
          <div class="page-sub">Seguimiento mensual por hermano</div></div>
        <form method="GET" style="display:flex;gap:8px;align-items:center">
          <input type="hidden" name="tab" value="dues">
          <select name="year" onchange="this.form.submit()" class="form-control" style="width:90px">
            <?php for($y=date('Y');$y>=2020;$y--): ?>
            <option value="<?=$y?>" <?=$y==$year?'selected':''?>><?=$y?></option>
            <?php endfor; ?>
          </select>
        </form>
      </div>

      <!-- Dues rate setting -->
      <div class="card" style="margin-bottom:1.5rem">
        <h3 style="color:var(--gold);margin-bottom:.75rem">Cuota Mensual <?=$year?></h3>
        <form id="form-dues-rate" style="display:flex;gap:10px;align-items:flex-end">
          <?= csrf_field() ?>
          <div class="form-group" style="margin:0">
            <label class="form-label">Monto mensual ($)</label>
            <input type="number" name="amount" id="dues-rate" class="form-control" style="width:120px"
                   value="<?=number_format($monthlyRate,2)?>" min="0" step="0.01">
          </div>
          <input type="hidden" name="year_setting" value="<?=$year?>">
          <button type="submit" class="btn btn-gold" onclick="saveDuesRate(event)">Actualizar Tarifa</button>
        </form>
      </div>

      <!-- Dues grid per member -->
      <?php foreach($members as $mem):
        if(!$mem['active']) continue;
        $owed = 0; $paid = 0;
        for($m=1;$m<=12;$m++){
          $d = $duesMap[$mem['id']][$m] ?? null;
          if($d && $d['paid']) $paid++;
          elseif($m <= (int)date('n') || $year < date('Y')) $owed++;
        }
      ?>
      <div class="card" style="margin-bottom:1rem">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.75rem;flex-wrap:wrap;gap:.5rem">
          <div>
            <strong style="color:#fff"><?=e($mem['name'])?></strong>
            <span style="color:var(--text-muted);font-size:12px;margin-left:8px"><?=e($mem['role'])?></span>
          </div>
          <div style="display:flex;gap:1rem;font-size:12px">
            <span style="color:var(--success)">✓ <?=$paid?> pagados</span>
            <?php if($owed>0): ?><span style="color:var(--danger)">⚠ <?=$owed?> pendientes — $<?=number_format($owed*$monthlyRate,2)?></span><?php endif; ?>
          </div>
        </div>
        <div class="month-grid">
          <?php for($m=1;$m<=12;$m++):
            $d = $duesMap[$mem['id']][$m] ?? null;
            $isPast = $m <= (int)date('n') || $year < date('Y');
            $isPaid = $d && $d['paid'];
            $cls = $isPaid ? 'paid' : ($isPast ? 'unpaid' : 'future');
          ?>
          <div class="month-cell <?=$cls?>" title="<?=$MONTHS_F[$m]?> <?=$year?>">
            <div><?=$MONTHS[$m]?></div>
            <?php if($isPaid): ?>
              <div style="font-size:10px">✓<?=$d['paid_date']?' '.date('d/m',strtotime($d['paid_date'])):''?></div>
            <?php elseif($isPast): ?>
              <div style="font-size:10px">⚠ Pendiente</div>
              <?php if($monthlyRate>0): ?><div style="font-size:9px">$<?=number_format($monthlyRate,2)?></div><?php endif; ?>
            <?php else: ?>
              <div style="font-size:10px">—</div>
            <?php endif; ?>
          </div>
          <?php endfor; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- ══════════════════════════════════════════════════ -->
    <!-- TAB: DONATIONS                                      -->
    <!-- ══════════════════════════════════════════════════ -->
    <?php elseif($activeTab==='donations'): ?>
    <div class="tab-panel active animate-fadeUp">
      <div class="page-header">
        <div><h1 class="page-title">Donaciones</h1>
          <div class="page-sub">Total: $<?=number_format($totalDonations,2)?></div></div>
        <button class="btn btn-gold" onclick="toggleSection('add-don-form')">+ Registrar Donación</button>
      </div>

      <!-- Add donation form -->
      <div id="add-don-form" style="display:none" class="card" style="margin-bottom:1.5rem">
        <h3 style="color:var(--gold);margin-bottom:1rem">Registrar Donación</h3>
        <form id="form-add-don">
          <?= csrf_field() ?>
          <div class="form-row">
            <!-- Donor type toggle -->
            <div class="form-group form-full">
              <label class="form-label">Tipo de Donante</label>
              <div style="display:flex;gap:10px">
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
                  <input type="radio" name="donor_type" value="member" checked onchange="toggleDonorType('member')" style="accent-color:var(--gold)">
                  Miembro de la Logia
                </label>
                <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
                  <input type="radio" name="donor_type" value="external" onchange="toggleDonorType('external')" style="accent-color:var(--gold)">
                  Externo / No Miembro
                </label>
              </div>
            </div>
            <!-- Member select -->
            <div class="form-group" id="don-member-row">
              <label class="form-label">Miembro</label>
              <select name="member_id" class="form-control">
                <option value="">— Seleccionar —</option>
                <?php foreach($members as $mem): ?>
                <option value="<?=$mem['id']?>"><?=e($mem['name'])?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <!-- External donor fields -->
            <div class="form-group" id="don-name-row" style="display:none">
              <label class="form-label">Nombre del Donante</label>
              <input type="text" name="donor_name" class="form-control" placeholder="Nombre completo">
            </div>
            <div class="form-group" id="don-email-row" style="display:none">
              <label class="form-label">Correo (opcional)</label>
              <input type="email" name="donor_email" class="form-control" placeholder="correo@ejemplo.com">
            </div>
            <!-- Common fields -->
            <div class="form-group"><label class="form-label">Monto ($) *</label>
              <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required></div>
            <div class="form-group"><label class="form-label">Fecha *</label>
              <input type="date" name="date" class="form-control" value="<?=date('Y-m-d')?>" required></div>
            <div class="form-group"><label class="form-label">Categoría</label>
              <select name="category" class="form-control">
                <option>General</option><option>Charity</option><option>Building</option>
                <option>Education</option><option>Events</option><option>Other</option>
              </select></div>
            <div class="form-group form-full"><label class="form-label">Nota / Motivo</label>
              <input type="text" name="note" class="form-control" placeholder="Descripción de la donación"></div>
            <div class="form-group">
              <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;margin-top:.5rem">
                <input type="checkbox" name="anonymous" value="1" style="accent-color:var(--gold)"> Donación Anónima
              </label>
            </div>
          </div>
          <div style="display:flex;gap:10px;margin-top:.5rem">
            <button type="submit" class="btn btn-gold">Guardar Donación</button>
            <button type="button" class="btn btn-outline" onclick="toggleSection('add-don-form')">Cancelar</button>
          </div>
        </form>
      </div>

      <!-- Donations table -->
      <div class="card">
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Fecha</th><th>Donante</th><th>Categoría</th><th>Monto</th><th>Nota</th><th></th></tr></thead>
            <tbody>
              <?php foreach($donations as $d): ?>
              <tr data-don-id="<?=$d['id']?>">
                <td><?=e($d['date'])?></td>
                <td>
                  <?php if($d['anonymous']): ?>
                    <span style="color:var(--text-muted);font-style:italic">[Anónimo]</span>
                  <?php else: ?>
                    <strong><?=e($d['member_name'] ?? $d['donor_name'] ?? '—')?></strong>
                    <?php if($d['member_id']): ?><span class="badge badge-info" style="margin-left:4px">Miembro</span><?php endif; ?>
                  <?php endif; ?>
                </td>
                <td><span class="badge badge-gold"><?=e($d['category'])?></span></td>
                <td style="color:var(--success);font-weight:bold">$<?=number_format($d['amount'],2)?></td>
                <td style="font-size:12px;color:var(--text-muted)"><?=e($d['note']??'—')?></td>
                <td><button class="btn btn-danger btn-sm" onclick="deleteDonation(<?=$d['id']?>)">✕</button></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════ -->
    <!-- TAB: SAVINGS                                        -->
    <!-- ══════════════════════════════════════════════════ -->
    <?php elseif($activeTab==='savings'): ?>
    <div class="tab-panel active animate-fadeUp">
      <div class="page-header">
        <div><h1 class="page-title">Ahorros de la Logia</h1>
          <div class="page-sub">Fondo de reserva</div></div>
        <button class="btn btn-gold" onclick="toggleSection('add-saving-form')">+ Registrar Movimiento</button>
      </div>
      <div class="stats-grid" style="margin-bottom:1.5rem">
        <div class="stat-card"><div class="stat-label">Total Ahorros</div>
          <div class="stat-value" style="color:var(--accent)">$<?=number_format($totalSavings,2)?></div></div>
      </div>
      <div id="add-saving-form" style="display:none" class="card" style="margin-bottom:1.5rem">
        <h3 style="color:var(--gold);margin-bottom:1rem">Nuevo Movimiento de Ahorro</h3>
        <form id="form-add-saving">
          <?= csrf_field() ?>
          <div class="form-row">
            <div class="form-group"><label class="form-label">Tipo</label>
              <select name="type" class="form-control"><option value="deposit">Depósito</option><option value="withdrawal">Retiro</option></select></div>
            <div class="form-group"><label class="form-label">Monto ($)</label>
              <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required></div>
            <div class="form-group"><label class="form-label">Fecha</label>
              <input type="date" name="date" class="form-control" value="<?=date('Y-m-d')?>"></div>
            <div class="form-group form-full"><label class="form-label">Descripción</label>
              <input type="text" name="description" class="form-control" required></div>
          </div>
          <button type="submit" class="btn btn-gold" style="margin-top:.5rem">Guardar</button>
        </form>
      </div>
      <div class="card">
        <div class="table-wrap">
          <table class="data-table">
            <thead><tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th>Monto</th></tr></thead>
            <tbody>
              <?php foreach($savingsRows as $s): ?>
              <tr>
                <td><?=e($s['date'])?></td>
                <td><span class="badge <?=$s['type']==='deposit'?'badge-income':'badge-expense'?>"><?=$s['type']==='deposit'?'Depósito':'Retiro'?></span></td>
                <td><?=e($s['description'])?></td>
                <td style="color:<?=$s['type']==='deposit'?'var(--success)':'var(--danger)'?>;font-weight:bold">
                  <?=$s['type']==='deposit'?'+':'-'?>$<?=number_format($s['amount'],2)?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════ -->
    <!-- TAB: NEWS                                           -->
    <!-- ══════════════════════════════════════════════════ -->
    <?php elseif($activeTab==='news'): ?>
    <div class="tab-panel active animate-fadeUp">
      <div class="page-header">
        <div><h1 class="page-title">Comunicados</h1>
          <div class="page-sub">Visibles para todos los miembros al iniciar sesión</div></div>
        <button class="btn btn-gold" onclick="toggleSection('add-news-form')">+ Nuevo Comunicado</button>
      </div>
      <div id="add-news-form" style="display:none" class="card" style="margin-bottom:1.5rem">
        <h3 style="color:var(--gold);margin-bottom:1rem">Publicar Comunicado</h3>
        <form id="form-add-news">
          <?= csrf_field() ?>
          <div class="form-group"><label class="form-label">Título *</label>
            <input type="text" name="title" class="form-control" required></div>
          <div class="form-group"><label class="form-label">Autor</label>
            <input type="text" name="author" class="form-control" value="<?=$adminName?>"></div>
          <div class="form-group"><label class="form-label">Mensaje *</label>
            <textarea name="body" class="form-control" style="min-height:100px" required></textarea></div>
          <div style="display:flex;gap:10px;margin-top:.5rem">
            <button type="submit" class="btn btn-gold">Publicar</button>
            <button type="button" class="btn btn-outline" onclick="toggleSection('add-news-form')">Cancelar</button>
          </div>
        </form>
      </div>
      <div style="display:flex;flex-direction:column;gap:1rem">
        <?php foreach($newsRows as $n): ?>
        <div class="card card-gold" data-news-id="<?=$n['id']?>">
          <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:1rem">
            <div>
              <div class="news-date"><?=e(date('d M Y',strtotime($n['created_at'])))?> · <?=e($n['author'])?>
                <?php if(!$n['published']): ?><span class="badge badge-danger" style="margin-left:6px">Oculto</span><?php endif; ?>
              </div>
              <h3 class="news-title"><?=e($n['title'])?></h3>
              <p class="news-body"><?=nl2br(e($n['body']))?></p>
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0">
              <button class="btn btn-outline btn-sm" onclick="toggleNews(<?=$n['id']?>)"><?=$n['published']?'Ocultar':'Publicar'?></button>
              <button class="btn btn-danger btn-sm" onclick="deleteNews(<?=$n['id']?>)">Eliminar</button>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ══════════════════════════════════════════════════ -->
    <!-- TAB: REPORTS                                        -->
    <!-- ══════════════════════════════════════════════════ -->
    <?php elseif($activeTab==='reports'): ?>
    <div class="tab-panel active animate-fadeUp">
      <div class="page-header">
        <div><h1 class="page-title">Reportes Financieros</h1>
          <div class="page-sub">Exportar en PDF o CSV (para Excel / Google Sheets)</div></div>
      </div>

      <!-- Period selector -->
      <div class="card" style="margin-bottom:1.5rem">
        <h3 style="color:var(--gold);margin-bottom:1rem">Seleccionar Período</h3>
        <div style="display:flex;gap:1rem;flex-wrap:wrap;align-items:flex-end">
          <div class="form-group" style="margin:0">
            <label class="form-label">Año</label>
            <select id="rpt-year" class="form-control" style="width:90px">
              <?php for($y=date('Y');$y>=2020;$y--): ?>
              <option value="<?=$y?>" <?=$y==date('Y')?'selected':''?>><?=$y?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="form-group" style="margin:0">
            <label class="form-label">Mes (vacío = anual)</label>
            <select id="rpt-month" class="form-control" style="width:130px">
              <option value="0">Todos los meses</option>
              <?php for($m=1;$m<=12;$m++): ?><option value="<?=$m?>"><?=$MONTHS_F[$m]?></option><?php endfor; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="grid-3">
        <?php
        $rpts = [
          ['financial','Reporte Financiero Completo','Todas las transacciones, balance mensual y anual, resumen ejecutivo.'],
          ['dues',     'Estado de Cuotas',           'Todos los miembros con detalle mensual de pagos pendientes y realizados.'],
          ['donations','Reporte de Donaciones',      'Historial completo de donaciones de miembros y externos.'],
        ];
        foreach($rpts as [$rtype,$rtitle,$rdesc]):
        ?>
        <div class="card">
          <h3 style="color:var(--gold);margin-bottom:.5rem"><?=$rtitle?></h3>
          <p style="color:var(--text-secondary);font-size:13px;margin-bottom:1.5rem"><?=$rdesc?></p>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <button class="btn btn-primary btn-sm" onclick="doExport('<?=$rtype?>','csv')">⬇ CSV / Sheets</button>
            <button class="btn btn-gold btn-sm"    onclick="doExport('<?=$rtype?>','pdf')">🖨 PDF</button>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Summary snapshot -->
      <div class="card" style="margin-top:1.5rem">
        <h3 style="color:var(--gold);margin-bottom:1rem">Resumen Actual</h3>
        <div class="stats-grid">
          <?php
          $snaps = [
            ['Ingresos + Donaciones', $totalIncome+$totalDonations, 'var(--success)'],
            ['Egresos',               $totalExpenses,               'var(--danger)'],
            ['Balance Neto',          $balance,                     $balance>=0?'var(--success)':'var(--danger)'],
            ['Ahorros',               $totalSavings,                'var(--accent)'],
            ['Cuotas Pendientes',     $totalDuesOwed,               'var(--warning)'],
          ];
          foreach($snaps as [$lbl,$val,$clr]):
          ?>
          <div class="stat-card">
            <div class="stat-label"><?=$lbl?></div>
            <div class="stat-value" style="color:<?=$clr?>">$<?=number_format($val,2)?></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>

    <?php endif; ?>
  </main>
</div>

<!-- ── EDIT MEMBER MODAL ── -->
<div id="modal-edit-member" class="modal-overlay" style="display:none">
  <div class="modal" style="max-width:600px">
    <h2 class="modal-title">Editar Miembro</h2>
    <form id="form-edit-member">
      <?= csrf_field() ?>
      <input type="hidden" name="id" id="edit-member-id">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Nombre</label>
          <input type="text" name="name" id="edit-member-name" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Correo</label>
          <input type="email" name="email" id="edit-member-email" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Cargo</label>
          <input type="text" name="role" id="edit-member-role" class="form-control"></div>
        <div class="form-group"><label class="form-label">Grado</label>
          <select name="degree" id="edit-member-degree" class="form-control"><?= degrees_options() ?></select></div>
        <div class="form-group"><label class="form-label">Teléfono</label>
          <input type="text" name="phone" id="edit-member-phone" class="form-control"></div>
        <div class="form-group"><label class="form-label">Nuevo PIN (dejar vacío = no cambiar)</label>
          <input type="text" name="new_pin" class="form-control" maxlength="8" pattern="[0-9]*" placeholder="Solo si desea cambiar"></div>
        <div class="form-group form-full"><label class="form-label">Dirección</label>
          <input type="text" name="address" id="edit-member-address" class="form-control"></div>
        <div class="form-group form-full"><label class="form-label">Notas</label>
          <textarea name="notes" id="edit-member-notes" class="form-control"></textarea></div>
      </div>
      <div style="display:flex;gap:10px;margin-top:.5rem">
        <button type="submit" class="btn btn-gold">Guardar Cambios</button>
        <button type="button" class="btn btn-outline" onclick="closeModal('modal-edit-member')">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<footer style="margin-top:0">
  <span class="footer-symbol"><i class="fa-solid fa-star-of-david"></i></span>
  <div class="footer-name">Estrella Del Rey David Numero 11</div>
  <p class="footer-copy">© <?=date('Y')?> · Panel Administrativo · Confidencial</p>
</footer>

<script src="/assets/js/app.js"></script>
<script>
// ── Admin page helpers ──────────────────────────────────
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

function toggleSection(id) {
  const el = document.getElementById(id);
  el.style.display = el.style.display === 'none' ? 'block' : 'none';
}

async function adminApi(endpoint, data) {
  const r = await fetch('/api/' + endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF, 'X-Requested-With': 'XMLHttpRequest' },
    body: JSON.stringify(data)
  });
  const j = await r.json();
  if (!j.success) throw new Error(j.error || 'Error');
  return j;
}

function showToast(msg, type='success') {
  document.querySelectorAll('.toast').forEach(t=>t.remove());
  const el = document.createElement('div');
  el.className = `toast toast-${type}`; el.textContent = msg;
  document.body.appendChild(el);
  setTimeout(()=>{ el.style.opacity='0'; el.style.transition='opacity .4s'; setTimeout(()=>el.remove(),400); }, 3000);
}

// ── Add Transaction ──────────────────────────────────────
document.getElementById('form-add-tx')?.addEventListener('submit', async e => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const months = [...document.querySelectorAll('.dues-month-check:checked')].map(c=>+c.value);
  try {
    await adminApi('transactions.php', {
      type: fd.get('type'), amount: fd.get('amount'), date: fd.get('date'),
      description: fd.get('description'), category: fd.get('category'),
      member_id: fd.get('member_id') || null, reference: fd.get('reference') || null,
      dues_months: months, dues_year: fd.get('dues_year') || <?=date('Y')?>
    });
    showToast('Transacción guardada');
    setTimeout(()=>location.reload(), 900);
  } catch(err) { showToast(err.message, 'error'); }
});

// Show/hide dues month picker
document.getElementById('tx-category')?.addEventListener('change', function() {
  const show = this.value === 'Dues';
  document.getElementById('dues-month-row').style.display  = show ? 'block' : 'none';
  document.getElementById('tx-member-row').style.display   = show ? 'block' : 'none';
});
function selectAllMonths() {
  document.querySelectorAll('.dues-month-check').forEach(c=>c.checked=true);
  updateDuesTotal();
}
function updateDuesTotal() {
  const rate = parseFloat(document.getElementById('tx-amount')?.value || 0);
  const n    = document.querySelectorAll('.dues-month-check:checked').length;
  const el   = document.getElementById('dues-month-total');
  if(el) el.textContent = `Total: $${(rate*n).toFixed(2)} (${n} mes${n!==1?'es':''})`;
}
document.getElementById('tx-amount')?.addEventListener('input', updateDuesTotal);
document.querySelectorAll('.dues-month-check')?.forEach(c=>c.addEventListener('change',updateDuesTotal));

// ── Inline edit transaction ──────────────────────────────
function enableEditRow(id) {
  const row = document.querySelector(`tr[data-id="${id}"]`);
  row.querySelectorAll('[data-edit]').forEach(cell => {
    const val = cell.dataset.val, type = cell.dataset.edit;
    if (type==='number') cell.innerHTML=`<input type="number" class="edit-input" style="width:90px" value="${val}" step="0.01">`;
    else if(type==='date') cell.innerHTML=`<input type="date" class="edit-input" value="${val}">`;
    else cell.innerHTML=`<input type="text" class="edit-input" style="width:140px" value="${val}">`;
  });
  row.querySelector('.edit-btn').style.display   = 'none';
  row.querySelector('.save-btn').style.display   = 'inline-flex';
  row.querySelector('.cancel-btn').style.display = 'inline-flex';
}
async function saveEditRow(id) {
  const row = document.querySelector(`tr[data-id="${id}"]`);
  const data = { id };
  row.querySelectorAll('[data-field]').forEach(cell => {
    const inp = cell.querySelector('input');
    if(inp) data[cell.dataset.field] = inp.value;
  });
  try { await adminApi('transactions.php?action=update', data); showToast('Actualizado'); setTimeout(()=>location.reload(),900); }
  catch(err) { showToast(err.message,'error'); }
}
async function deleteTx(id) {
  if(!confirm('¿Eliminar esta transacción?')) return;
  try { await adminApi('transactions.php?action=delete',{id}); showToast('Eliminado'); document.querySelector(`tr[data-id="${id}"]`)?.remove(); }
  catch(err) { showToast(err.message,'error'); }
}

// ── Members ──────────────────────────────────────────────
document.getElementById('form-add-member')?.addEventListener('submit', async e => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const data = Object.fromEntries(fd.entries());
  try { await adminApi('members.php', data); showToast('Miembro agregado'); setTimeout(()=>location.reload(),900); }
  catch(err) { showToast(err.message,'error'); }
});
async function toggleMember(id) {
  if(!confirm('¿Cambiar estado de este miembro?')) return;
  try { await adminApi('members.php?action=toggle',{id}); showToast('Estado actualizado'); setTimeout(()=>location.reload(),900); }
  catch(err) { showToast(err.message,'error'); }
}
function editMember(id, data) {
  document.getElementById('edit-member-id').value      = id;
  document.getElementById('edit-member-name').value    = data.name;
  document.getElementById('edit-member-email').value   = data.email;
  document.getElementById('edit-member-role').value    = data.role;
  document.getElementById('edit-member-degree').value  = data.degree;
  document.getElementById('edit-member-phone').value   = data.phone || '';
  document.getElementById('edit-member-address').value = data.address || '';
  document.getElementById('edit-member-notes').value   = data.notes || '';
  openModal('modal-edit-member');
}
document.getElementById('form-edit-member')?.addEventListener('submit', async e => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const data = Object.fromEntries(fd.entries());
  try { await adminApi('members.php?action=update', data); showToast('Miembro actualizado'); closeModal('modal-edit-member'); setTimeout(()=>location.reload(),900); }
  catch(err) { showToast(err.message,'error'); }
});

// ── Donations ────────────────────────────────────────────
function toggleDonorType(type) {
  document.getElementById('don-member-row').style.display = type==='member' ? '' : 'none';
  document.getElementById('don-name-row').style.display   = type==='external' ? '' : 'none';
  document.getElementById('don-email-row').style.display  = type==='external' ? '' : 'none';
}
document.getElementById('form-add-don')?.addEventListener('submit', async e => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const data = Object.fromEntries(fd.entries());
  data.anonymous = fd.get('anonymous') ? 1 : 0;
  if(data.donor_type === 'external') data.member_id = '';
  try { await adminApi('donations.php', data); showToast('Donación registrada'); setTimeout(()=>location.reload(),900); }
  catch(err) { showToast(err.message,'error'); }
});
async function deleteDonation(id) {
  if(!confirm('¿Eliminar esta donación?')) return;
  try { await adminApi('donations.php?action=delete',{id}); showToast('Eliminado'); document.querySelector(`tr[data-don-id="${id}"]`)?.remove(); }
  catch(err) { showToast(err.message,'error'); }
}

// ── Savings ──────────────────────────────────────────────
document.getElementById('form-add-saving')?.addEventListener('submit', async e => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const data = Object.fromEntries(fd.entries());
  try { await adminApi('savings.php', data); showToast('Ahorro registrado'); setTimeout(()=>location.reload(),900); }
  catch(err) { showToast(err.message,'error'); }
});

// ── News ─────────────────────────────────────────────────
document.getElementById('form-add-news')?.addEventListener('submit', async e => {
  e.preventDefault();
  const fd = new FormData(e.target);
  const data = Object.fromEntries(fd.entries());
  try { await adminApi('news.php', data); showToast('Comunicado publicado'); setTimeout(()=>location.reload(),900); }
  catch(err) { showToast(err.message,'error'); }
});
async function deleteNews(id) {
  if(!confirm('¿Eliminar este comunicado?')) return;
  try { await adminApi('news.php?action=delete',{id}); showToast('Eliminado'); document.querySelector(`[data-news-id="${id}"]`)?.remove(); }
  catch(err) { showToast(err.message,'error'); }
}
async function toggleNews(id) {
  try { await adminApi('news.php?action=toggle',{id}); showToast('Estado cambiado'); setTimeout(()=>location.reload(),900); }
  catch(err) { showToast(err.message,'error'); }
}

// ── Dues rate ────────────────────────────────────────────
async function saveDuesRate(e) {
  e.preventDefault();
  const amount = document.getElementById('dues-rate').value;
  const year   = <?= $year ?>;
  try { await adminApi('dues_settings.php', { amount, year }); showToast('Tarifa actualizada'); }
  catch(err) { showToast(err.message,'error'); }
}

// ── Reports ──────────────────────────────────────────────
function doExport(type, format) {
  const year  = document.getElementById('rpt-year').value;
  const month = document.getElementById('rpt-month').value;
  const url   = `/api/reports.php?type=${type}&format=${format}&year=${year}&month=${month}`;
  if(format==='csv') { const a=document.createElement('a');a.href=url;a.download='';a.click(); }
  else window.open(url,'_blank');
}
</script>
</body>
</html>