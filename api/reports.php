<?php
/**
 * api/reports.php - Financial Reports (PDF + CSV)
 * Types:   financial | dues | donations
 * Formats: pdf | csv
 * Includes member AND admin dues in all calculations.
 * All inline styles removed - uses /assets/css/style.css (.rpt-* classes)
 */

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db.php';

secure_session_start();
require_admin();

$type   = get_param('type',   'financial');
$format = get_param('format', 'pdf');
$year   = int_val(get_param('year',  date('Y')));
$month  = int_val(get_param('month', 0));
$pdo    = DB::get();

$MONTHS = ['','January','February','March','April','May','June',
           'July','August','September','October','November','December'];
$MONTHS_SHORT = ['','Jan','Feb','Mar','Apr','May','Jun',
                 'Jul','Aug','Sep','Oct','Nov','Dec'];

// DATA FUNCTIONS

function getTransactions(PDO $pdo, int $year, int $month): array {
    $sql = "SELECT t.*, m.name AS member_name
            FROM transactions t
            LEFT JOIN members m ON t.member_id = m.id
            WHERE YEAR(t.date) = ?";
    $params = [$year];
    if ($month > 0) { $sql .= " AND MONTH(t.date) = ?"; $params[] = $month; }
    $sql .= " ORDER BY t.date ASC";
    $s = $pdo->prepare($sql); $s->execute($params);
    return $s->fetchAll();
}

function getDonations(PDO $pdo, int $year, int $month): array {
    $sql = "SELECT * FROM donations WHERE YEAR(date) = ?";
    $params = [$year];
    if ($month > 0) { $sql .= " AND MONTH(date) = ?"; $params[] = $month; }
    $sql .= " ORDER BY date ASC";
    $s = $pdo->prepare($sql); $s->execute($params);
    return $s->fetchAll();
}

function getMemberDues(PDO $pdo, int $year): array {
    $s = $pdo->prepare(
        "SELECT d.*, m.name, m.email, m.role
         FROM dues d JOIN members m ON d.member_id = m.id
         WHERE d.year = ? AND d.member_id IS NOT NULL
         ORDER BY m.name, d.month"
    );
    $s->execute([$year]);
    return $s->fetchAll();
}

function getAdminDues(PDO $pdo, int $year): array {
    try {
        $s = $pdo->prepare(
            "SELECT d.*, a.name, a.username, a.email, 'Admin' AS role
             FROM dues d JOIN admin_users a ON d.admin_id = a.id
             WHERE d.year = ? AND d.admin_id IS NOT NULL
             ORDER BY a.name, d.month"
        );
        $s->execute([$year]);
        return $s->fetchAll();
    } catch (Exception $e) { return []; }
}

function getMonthlyBreakdown(PDO $pdo, int $year): array {
    $s = $pdo->prepare(
        "SELECT MONTH(date) AS m,
                SUM(CASE WHEN type='income'  THEN amount ELSE 0 END) AS income,
                SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) AS expenses
         FROM transactions WHERE YEAR(date) = ?
         GROUP BY MONTH(date) ORDER BY m"
    );
    $s->execute([$year]);
    return $s->fetchAll();
}

function getMonthlyDonations(PDO $pdo, int $year): array {
    $s = $pdo->prepare(
        "SELECT MONTH(date) AS m, SUM(amount) AS total
         FROM donations WHERE YEAR(date) = ?
         GROUP BY MONTH(date)"
    );
    $s->execute([$year]);
    $out = [];
    foreach ($s->fetchAll() as $row) $out[(int)$row['m']] = (float)$row['total'];
    return $out;
}

function getSavingsBalance(PDO $pdo): float {
    try {
        return (float)$pdo->query(
            "SELECT COALESCE(SUM(CASE WHEN type='deposit' THEN amount ELSE -amount END),0) FROM savings"
        )->fetchColumn();
    } catch (Exception $e) { return 0.0; }
}

function getDuesRate(PDO $pdo, int $year): float {
    $s = $pdo->prepare("SELECT amount FROM dues_settings WHERE year = ?");
    $s->execute([$year]);
    return (float)($s->fetchColumn() ?: 0);
}

// CSV OUTPUT
if ($format === 'csv') {
    global $MONTHS, $MONTHS_SHORT;
    $filename = "lodge11_{$type}_{$year}" . ($month ? "_{$MONTHS[$month]}" : '') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}\"");
    header('Cache-Control: no-cache, no-store');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    if ($type === 'financial') {
        $txs  = getTransactions($pdo, $year, $month);
        $dons = getDonations($pdo, $year, $month);
        $memDues = getMemberDues($pdo, $year);
        $admDues = getAdminDues($pdo, $year);
        $label = $month ? "{$MONTHS[$month]} $year" : "Annual $year";

        fputcsv($out, ["No. 11 Del Rey David Numero 11 - Financial Report - {$label}"]);
        fputcsv($out, ["Generated:", date('Y-m-d H:i:s')]);
        fputcsv($out, []);
        fputcsv($out, ['Date','Type','Description','Category','Member','Reference','Amount']);

        $totalIncome = $totalExpense = 0;
        foreach ($txs as $t) {
            fputcsv($out, [$t['date'], strtoupper($t['type']), $t['description'],
                $t['category'], $t['member_name'] ?? '', $t['reference'] ?? '',
                number_format($t['amount'],2)]);
            if ($t['type']==='income')  $totalIncome  += $t['amount'];
            if ($t['type']==='expense') $totalExpense += $t['amount'];
        }
        $totalDons = 0;
        foreach ($dons as $d) {
            $donor = $d['anonymous'] ? '[Anonymous]' : ($d['donor_name'] ?? '');
            fputcsv($out, [$d['date'],'DONATION',$donor,$d['category'],'','',number_format($d['amount'],2)]);
            $totalDons += $d['amount'];
        }
        $allDues   = array_merge($memDues, $admDues);
        $totalOwed = array_sum(array_column(array_filter($allDues, fn($d)=>!$d['paid']),'amount'));
        $savings   = getSavingsBalance($pdo);

        fputcsv($out, []);
        fputcsv($out, ['','','','','','Total Income:',     number_format($totalIncome,2)]);
        fputcsv($out, ['','','','','','Total Donations:',  number_format($totalDons,2)]);
        fputcsv($out, ['','','','','','Total Expenses:',   number_format($totalExpense,2)]);
        fputcsv($out, ['','','','','','Net Balance:',      number_format($totalIncome+$totalDons-$totalExpense,2)]);
        fputcsv($out, ['','','','','','Savings Balance:',  number_format($savings,2)]);
        fputcsv($out, ['','','','','','Outstanding Dues:', number_format($totalOwed,2)]);

        fputcsv($out, []);
        fputcsv($out, ['--- Monthly Breakdown ---']);
        fputcsv($out, ['Month','Income','Donations','Expenses','Balance']);
        $breakdown   = getMonthlyBreakdown($pdo, $year);
        $monthlyDons = getMonthlyDonations($pdo, $year);
        foreach ($breakdown as $b) {
            $d   = $monthlyDons[(int)$b['m']] ?? 0;
            $bal = $b['income'] + $d - $b['expenses'];
            fputcsv($out, [$MONTHS[$b['m']],number_format($b['income'],2),
                number_format($d,2),number_format($b['expenses'],2),number_format($bal,2)]);
        }
    }

    if ($type === 'dues') {
        $memDues = getMemberDues($pdo, $year);
        $admDues = getAdminDues($pdo, $year);
        fputcsv($out, ["No. 11 Del Rey David - Dues Report $year"]);
        fputcsv($out, ["Generated:", date('Y-m-d H:i:s')]);
        fputcsv($out, []);
        fputcsv($out, ['Name','Type','Role','Month','Amount','Status','Paid Date']);
        foreach ($memDues as $d) {
            fputcsv($out, [$d['name'],'Member',$d['role'],$MONTHS[$d['month']],
                number_format($d['amount'],2),$d['paid']?'PAID':'UNPAID',$d['paid_date']??'']);
        }
        foreach ($admDues as $d) {
            fputcsv($out, [$d['name']?:$d['username'],'Admin','Administrator',$MONTHS[$d['month']],
                number_format($d['amount'],2),$d['paid']?'PAID':'UNPAID',$d['paid_date']??'']);
        }
    }

    if ($type === 'donations') {
        $dons = getDonations($pdo, $year, $month);
        fputcsv($out, ["No. 11 Del Rey David - Donations Report $year"]);
        fputcsv($out, ["Generated:", date('Y-m-d H:i:s')]);
        fputcsv($out, []);
        fputcsv($out, ['Date','Donor','Email','Amount','Category','Note','Anonymous']);
        $total = 0;
        foreach ($dons as $d) {
            $name = $d['anonymous'] ? '[Anonymous]' : ($d['donor_name'] ?? '');
            fputcsv($out, [$d['date'],$name,$d['donor_email']??'',
                number_format($d['amount'],2),$d['category'],$d['note']??'',$d['anonymous']?'Yes':'No']);
            $total += $d['amount'];
        }
        fputcsv($out, []);
        fputcsv($out, ['','','Total Donated:',number_format($total,2)]);
    }

    fclose($out);
    exit;
}

// PDF (print-ready HTML)
$txs         = getTransactions($pdo, $year, $month);
$dons        = getDonations($pdo, $year, $month);
$memDues     = getMemberDues($pdo, $year);
$admDues     = getAdminDues($pdo, $year);
$breakdown   = getMonthlyBreakdown($pdo, $year);
$monthlyDons = getMonthlyDonations($pdo, $year);
$duesRate    = getDuesRate($pdo, $year);
$savings     = getSavingsBalance($pdo);

$totalIncome  = array_sum(array_column(array_filter($txs,  fn($t)=>$t['type']==='income'),  'amount'));
$totalExpense = array_sum(array_column(array_filter($txs,  fn($t)=>$t['type']==='expense'), 'amount'));
$totalDons    = array_sum(array_column($dons, 'amount'));
$memOwed      = array_sum(array_column(array_filter($memDues, fn($d)=>!$d['paid']), 'amount'));
$admOwed      = array_sum(array_column(array_filter($admDues, fn($d)=>!$d['paid']), 'amount'));
$totalOwed    = $memOwed + $admOwed;
$balance      = $totalIncome + $totalDons - $totalExpense;
$periodLabel  = $month ? "{$MONTHS[$month]} $year" : "Annual $year";

$bmap = [];
foreach ($breakdown as $b) $bmap[(int)$b['m']] = $b;

$memDuesMap = [];
foreach ($memDues as $d) $memDuesMap[$d['member_id']][$d['month']] = $d;
$admDuesMap = [];
foreach ($admDues as $d) $admDuesMap[$d['admin_id']][$d['month']] = $d;

$activeMembers = $pdo->query("SELECT * FROM members WHERE active=1 ORDER BY name")->fetchAll();
$adminUsers    = $pdo->query("SELECT * FROM admin_users WHERE active=1 ORDER BY name")->fetchAll();
$currentMonth  = (int)date('n');
$currentYear   = (int)date('Y');
?>
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reporte <?= e($periodLabel) ?></title>
  <link rel="stylesheet" href="/assets/css/style.css?v=1.12">
</head>
<body>

<div class="rpt-print-bar no-print">
  <strong>Ctrl+P / Cmd+P para guardar como PDF</strong>
  <button class="rpt-print-btn" onclick="window.print()">Imprimir / Guardar PDF</button>
</div>

<div class="rpt-page">

  <div class="rpt-header">
    <span class="rpt-symbol">&#x2B21;</span>
    <h1>No. 11 Del Rey David Numero 11</h1>
    <h2>Reporte Financiero - <?= e($periodLabel) ?></h2>
    <div class="rpt-meta">Generado: <?= date('d F Y') ?> a las <?= date('g:i A') ?> &middot; CONFIDENCIAL</div>
  </div>

  <!-- Summary: income / donations / expenses / balance / savings -->
  <div class="rpt-summary-grid">
    <div class="rpt-box">
      <div class="rpt-box-lbl">Ingresos</div>
      <div class="rpt-box-val rpt-income">$<?= number_format($totalIncome,2) ?></div>
    </div>
    <div class="rpt-box">
      <div class="rpt-box-lbl">Donaciones</div>
      <div class="rpt-box-val rpt-income">$<?= number_format($totalDons,2) ?></div>
    </div>
    <div class="rpt-box">
      <div class="rpt-box-lbl">Egresos</div>
      <div class="rpt-box-val rpt-expense">$<?= number_format($totalExpense,2) ?></div>
    </div>
    <div class="rpt-box">
      <div class="rpt-box-lbl">Balance Neto</div>
      <div class="rpt-box-val <?= $balance>=0?'rpt-income':'rpt-expense' ?>">$<?= number_format($balance,2) ?></div>
    </div>
    <div class="rpt-box">
      <div class="rpt-box-lbl">Ahorros</div>
      <div class="rpt-box-val rpt-savings">$<?= number_format($savings,2) ?></div>
    </div>
  </div>

  <!-- Summary: outstanding dues breakdown -->
  <div class="rpt-summary-grid cols-3">
    <div class="rpt-box">
      <div class="rpt-box-lbl">Cuotas Pendientes (Miembros)</div>
      <div class="rpt-box-val rpt-dues-owed">$<?= number_format($memOwed,2) ?></div>
    </div>
    <div class="rpt-box">
      <div class="rpt-box-lbl">Cuotas Pendientes (Admins)</div>
      <div class="rpt-box-val rpt-dues-owed">$<?= number_format($admOwed,2) ?></div>
    </div>
    <div class="rpt-box">
      <div class="rpt-box-lbl">Total Cuotas Pendientes</div>
      <div class="rpt-box-val rpt-dues-owed">$<?= number_format($totalOwed,2) ?></div>
    </div>
  </div>

  <!-- Monthly Breakdown -->
  <h3 class="rpt-section-title">Movimiento Mensual - <?= $year ?></h3>
  <table class="rpt-table">
    <thead>
      <tr>
        <th>Mes</th>
        <th>Ingresos</th>
        <th>Donaciones</th>
        <th>Total Entradas</th>
        <th>Egresos</th>
        <th>Balance Mes</th>
        <th>Balance Acumulado</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $running = 0;
      $gInc = $gDon = $gExp = 0;
      for ($m=1; $m<=12; $m++):
        $b   = $bmap[$m] ?? ['income'=>0,'expenses'=>0];
        $don = $monthlyDons[$m] ?? 0;
        $inc = (float)$b['income'];
        $exp = (float)$b['expenses'];
        $tot = $inc + $don;
        $mb  = $tot - $exp;
        $running += $mb;
        $gInc += $inc; $gDon += $don; $gExp += $exp;
      ?>
      <tr>
        <td><?= $MONTHS[$m] ?></td>
        <td class="<?= $inc>0?'rpt-income':'rpt-muted' ?>">$<?= number_format($inc,2) ?></td>
        <td class="<?= $don>0?'rpt-income':'rpt-muted' ?>">$<?= number_format($don,2) ?></td>
        <td class="<?= $tot>0?'rpt-income':'rpt-muted' ?>">$<?= number_format($tot,2) ?></td>
        <td class="<?= $exp>0?'rpt-expense':'rpt-muted' ?>">$<?= number_format($exp,2) ?></td>
        <td class="<?= $mb>=0?'rpt-income':'rpt-expense' ?>">$<?= number_format($mb,2) ?></td>
        <td class="<?= $running>=0?'rpt-balance':'rpt-expense' ?>">$<?= number_format($running,2) ?></td>
      </tr>
      <?php endfor; ?>
      <tr class="total-row">
        <td>TOTAL <?= $year ?></td>
        <td class="rpt-income">$<?= number_format($gInc,2) ?></td>
        <td class="rpt-income">$<?= number_format($gDon,2) ?></td>
        <td class="rpt-income">$<?= number_format($gInc+$gDon,2) ?></td>
        <td class="rpt-expense">$<?= number_format($gExp,2) ?></td>
        <td class="<?= ($gInc+$gDon-$gExp)>=0?'rpt-income':'rpt-expense' ?>">$<?= number_format($gInc+$gDon-$gExp,2) ?></td>
        <td></td>
      </tr>
    </tbody>
  </table>

  <!-- Transactions -->
  <h3 class="rpt-section-title">Detalle de Transacciones</h3>
  <?php if (empty($txs)): ?>
    <p class="rpt-muted">No hay transacciones para este periodo.</p>
  <?php else: ?>
  <table class="rpt-table">
    <thead>
      <tr><th>Fecha</th><th>Tipo</th><th>Descripcion</th><th>Categoria</th><th>Miembro</th><th>Monto</th></tr>
    </thead>
    <tbody>
      <?php foreach ($txs as $t): ?>
      <tr>
        <td><?= e($t['date']) ?></td>
        <td><?= $t['type']==='income'?'Ingreso':'Egreso' ?></td>
        <td><?= e($t['description']) ?></td>
        <td><?= e($t['category']) ?></td>
        <td><?= e($t['member_name']??'-') ?></td>
        <td class="<?= $t['type']==='income'?'rpt-income':'rpt-expense' ?>">$<?= number_format($t['amount'],2) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="total-row">
        <td colspan="5">Balance Transacciones</td>
        <td class="<?= ($totalIncome-$totalExpense)>=0?'rpt-income':'rpt-expense' ?>">$<?= number_format($totalIncome-$totalExpense,2) ?></td>
      </tr>
    </tbody>
  </table>
  <?php endif; ?>

  <!-- Donations -->
  <?php if (!empty($dons)): ?>
  <h3 class="rpt-section-title">Donaciones - <?= e($periodLabel) ?></h3>
  <table class="rpt-table">
    <thead>
      <tr><th>Fecha</th><th>Donante</th><th>Categoria</th><th>Monto</th><th>Nota</th></tr>
    </thead>
    <tbody>
      <?php foreach ($dons as $d): ?>
      <tr>
        <td><?= e($d['date']) ?></td>
        <td><?= $d['anonymous'] ? '<em class="rpt-muted">[Anonimo]</em>' : e($d['donor_name']??'-') ?></td>
        <td><?= e($d['category']) ?></td>
        <td class="rpt-income">$<?= number_format($d['amount'],2) ?></td>
        <td class="rpt-muted"><?= e($d['note']??'') ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="total-row">
        <td colspan="3">Total Donaciones</td>
        <td class="rpt-income">$<?= number_format($totalDons,2) ?></td>
        <td></td>
      </tr>
    </tbody>
  </table>
  <?php endif; ?>

  <!-- Member Dues -->
  <h3 class="rpt-section-title">Cuotas - Miembros - <?= $year ?></h3>
  <p class="rpt-section-subtitle">Tarifa mensual: $<?= number_format($duesRate,2) ?></p>
  <?php if (empty($activeMembers)): ?>
    <p class="rpt-muted">No hay miembros activos.</p>
  <?php else: ?>
  <table class="rpt-table">
    <thead>
      <tr>
        <th>Miembro</th><th>Cargo</th>
        <?php for ($m=1;$m<=12;$m++): ?><th class="center"><?= $MONTHS_SHORT[$m] ?></th><?php endfor; ?>
        <th>Pendiente</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $memTotal = 0;
      foreach ($activeMembers as $mem):
        $owed = 0;
        for ($m=1;$m<=12;$m++) {
          $d = $memDuesMap[$mem['id']][$m] ?? null;
          if ($d && !$d['paid']) $owed += $d['amount'];
        }
        $memTotal += $owed;
      ?>
      <tr>
        <td><?= e($mem['name']) ?></td>
        <td><?= e($mem['role']) ?></td>
        <?php for ($m=1;$m<=12;$m++):
          $d      = $memDuesMap[$mem['id']][$m] ?? null;
          $isPast = $m <= $currentMonth || $year < $currentYear;
          $isPaid = $d && $d['paid'];
        ?>
        <td class="center">
          <?php if ($isPaid): ?><span class="rpt-paid">&#10003;</span>
          <?php elseif ($isPast): ?><span class="rpt-unpaid">&#10007;</span>
          <?php else: ?><span class="rpt-muted">&middot;</span>
          <?php endif; ?>
        </td>
        <?php endfor; ?>
        <td class="<?= $owed>0?'rpt-dues-owed':'rpt-paid' ?>">$<?= number_format($owed,2) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="total-row">
        <td colspan="14">Total Pendiente (Miembros)</td>
        <td class="rpt-dues-owed">$<?= number_format($memTotal,2) ?></td>
      </tr>
    </tbody>
  </table>
  <?php endif; ?>

  <!-- Admin Dues -->
  <?php if (!empty($adminUsers)): ?>
  <h3 class="rpt-section-title">Cuotas - Usuarios Administrativos - <?= $year ?></h3>
  <p class="rpt-section-subtitle">Tarifa mensual: $<?= number_format($duesRate,2) ?></p>
  <table class="rpt-table">
    <thead>
      <tr>
        <th>Admin</th><th>Usuario</th>
        <?php for ($m=1;$m<=12;$m++): ?><th class="center"><?= $MONTHS_SHORT[$m] ?></th><?php endfor; ?>
        <th>Pendiente</th>
      </tr>
    </thead>
    <tbody>
      <?php
      $admTotal = 0;
      foreach ($adminUsers as $au):
        $owed = 0;
        for ($m=1;$m<=12;$m++) {
          $d = $admDuesMap[$au['id']][$m] ?? null;
          if ($d && !$d['paid']) $owed += $d['amount'];
        }
        $admTotal += $owed;
      ?>
      <tr>
        <td><?= e($au['name']?:$au['username']) ?> <span class="rpt-admin-badge">Admin</span></td>
        <td class="rpt-muted">@<?= e($au['username']) ?></td>
        <?php for ($m=1;$m<=12;$m++):
          $d      = $admDuesMap[$au['id']][$m] ?? null;
          $isPast = $m <= $currentMonth || $year < $currentYear;
          $isPaid = $d && $d['paid'];
        ?>
        <td class="center">
          <?php if ($isPaid): ?><span class="rpt-paid">&#10003;</span>
          <?php elseif ($isPast): ?><span class="rpt-unpaid">&#10007;</span>
          <?php else: ?><span class="rpt-muted">&middot;</span>
          <?php endif; ?>
        </td>
        <?php endfor; ?>
        <td class="<?= $owed>0?'rpt-dues-owed':'rpt-paid' ?>">$<?= number_format($owed,2) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="total-row">
        <td colspan="14">Total Pendiente (Admins)</td>
        <td class="rpt-dues-owed">$<?= number_format($admTotal,2) ?></td>
      </tr>
    </tbody>
  </table>
  <?php endif; ?>

  <!-- Grand total dues -->
  <table class="rpt-table">
    <tbody>
      <tr class="total-row">
        <td colspan="14"><strong>TOTAL GENERAL CUOTAS PENDIENTES</strong></td>
        <td class="rpt-dues-owed"><strong>$<?= number_format($totalOwed,2) ?></strong></td>
      </tr>
    </tbody>
  </table>

  <div class="rpt-footer">
    No. 11 Del Rey David Numero 11 &middot; Reporte Financiero Confidencial &middot; <?= date('Y') ?>
  </div>

</div>

<script>
  window.addEventListener('load', () => {
    if (window.location.search.includes('autoprint=1')) window.print();
  });
</script>
</body>
</html>