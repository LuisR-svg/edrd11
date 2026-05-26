<?php
/**
 * api/reports.php — Financial Reports Export
 * ============================================================
 * FIXED: Now shows outstanding dues, correct net balance with donations
 * Generates CSV (for Google Sheets/Excel) or print-ready HTML
 *
 * Types: financial, dues, donations, annual, monthly
 * Formats: csv, pdf
 * ============================================================
 */

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db.php';

secure_session_start();
require_admin();

$type   = get_param('type',   'financial');
$format = get_param('format', 'pdf');
$year   = int_val(get_param('year', date('Y')));
$month  = int_val(get_param('month', 0));
$pdo    = DB::get();

// ── FETCH DATA ───────────────────────────────────────────
function getTransactions($pdo, $year, $month) {
    $sql    = "SELECT t.*, m.name as member_name FROM transactions t LEFT JOIN members m ON t.member_id=m.id WHERE YEAR(t.date)=?";
    $params = [$year];
    if ($month > 0) { $sql .= " AND MONTH(t.date)=?"; $params[] = $month; }
    $sql .= " ORDER BY t.date ASC";
    $s = $pdo->prepare($sql); $s->execute($params);
    return $s->fetchAll();
}

function getDues($pdo, $year) {
    $s = $pdo->prepare("SELECT d.*, m.name, m.email, m.role FROM dues d JOIN members m ON d.member_id=m.id WHERE d.year=? ORDER BY m.name, d.month");
    $s->execute([$year]); return $s->fetchAll();
}

function getDonations($pdo, $year, $month) {
    $sql    = "SELECT * FROM donations WHERE YEAR(date)=?";
    $params = [$year];
    if ($month > 0) { $sql .= " AND MONTH(date)=?"; $params[] = $month; }
    $sql .= " ORDER BY date ASC";
    $s = $pdo->prepare($sql); $s->execute($params);
    return $s->fetchAll();
}

function getMonthlyBreakdown($pdo, $year) {
    $s = $pdo->prepare(
        "SELECT MONTH(date) as m,
                SUM(CASE WHEN type='income' THEN amount ELSE 0 END) as income,
                SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) as expenses
         FROM transactions WHERE YEAR(date)=? GROUP BY MONTH(date) ORDER BY m"
    );
    $s->execute([$year]); return $s->fetchAll();
}

$months = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

// ── CSV OUTPUT ───────────────────────────────────────────
if ($format === 'csv') {
    $filename = "lodge11_{$type}_{$year}" . ($month ? "_{$months[$month]}" : '') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header('Cache-Control: no-cache, no-store');

    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    if ($type === 'financial' || $type === 'annual' || $type === 'monthly') {
        $txs = getTransactions($pdo, $year, $month);
        $dons = getDonations($pdo, $year, $month);
        $title = $month ? "{$months[$month]} $year Financial Report" : "$year Annual Financial Report";
        
        fputcsv($out, ["Estrella Del Rey David No. 11 Numero 11 — $title"]);
        fputcsv($out, ["Generated:", date('Y-m-d H:i:s')]);
        fputcsv($out, []);
        fputcsv($out, ['Date','Type','Description','Category','Member','Reference','Amount']);
        
        $totalIncome = 0; $totalExpense = 0; $totalDons = 0;
        
        foreach ($txs as $t) {
            fputcsv($out, [$t['date'], strtoupper($t['type']), $t['description'], $t['category'], $t['member_name'] ?? '', $t['reference'] ?? '', number_format($t['amount'],2)]);
            if ($t['type']==='income')  $totalIncome  += $t['amount'];
            if ($t['type']==='expense') $totalExpense += $t['amount'];
        }
        
        foreach ($dons as $d) {
            $donor = $d['anonymous'] ? '[Anonymous]' : ($d['donor_name'] ?? '');
            fputcsv($out, [$d['date'], 'DONATION', $donor, $d['category'], '', '', number_format($d['amount'],2)]);
            $totalDons += $d['amount'];
        }

        fputcsv($out, []);
        fputcsv($out, ['','','','','','Total Income:',  number_format($totalIncome,2)]);
        fputcsv($out, ['','','','','','Total Donations:', number_format($totalDons,2)]);
        fputcsv($out, ['','','','','','Total Expenses:', number_format($totalExpense,2)]);
        fputcsv($out, ['','','','','','Net Balance:',   number_format($totalIncome + $totalDons - $totalExpense,2)]);

        // Monthly breakdown
        $dues = getDues($pdo, $year);
        $totalOwed = array_sum(array_column(array_filter($dues, fn($d)=>!$d['paid']), 'amount'));
        fputcsv($out, ['','','','','','Outstanding Dues:', number_format($totalOwed,2)]);

        fputcsv($out, []);
        fputcsv($out, ['--- Monthly Breakdown ---']);
        fputcsv($out, ['Month','Income','Expenses','Balance']);
        $breakdown = getMonthlyBreakdown($pdo, $year);
        foreach ($breakdown as $b) {
            fputcsv($out, [$months[$b['m']], number_format($b['income'],2), number_format($b['expenses'],2), number_format($b['income']-$b['expenses'],2)]);
        }
    }

    if ($type === 'dues') {
        $dues = getDues($pdo, $year);
        fputcsv($out, ["Estrella Del Rey David No. 11 — Dues Report $year"]);
        fputcsv($out, ["Generated:", date('Y-m-d H:i:s')]);
        fputcsv($out, []);
        fputcsv($out, ['Member','Email','Role','Month','Amount','Status','Paid Date']);
        foreach ($dues as $d) {
            fputcsv($out, [$d['name'],$d['email'],$d['role'],$months[$d['month']],number_format($d['amount'],2),$d['paid']?'PAID':'UNPAID',$d['paid_date']??'']);
        }
    }

    if ($type === 'donations') {
        $dons = getDonations($pdo, $year, $month);
        fputcsv($out, ["Estrella Del Rey David No. 11 — Donations Report $year"]);
        fputcsv($out, ["Generated:", date('Y-m-d H:i:s')]);
        fputcsv($out, []);
        fputcsv($out, ['Date','Donor','Email','Amount','Category','Note','Anonymous']);
        $total = 0;
        foreach ($dons as $d) {
            $name = $d['anonymous'] ? '[Anonymous]' : ($d['donor_name'] ?? '');
            fputcsv($out, [$d['date'],$name,$d['donor_email']??'',number_format($d['amount'],2),$d['category'],$d['note']??'',$d['anonymous']?'Yes':'No']);
            $total += $d['amount'];
        }
        fputcsv($out, []);
        fputcsv($out, ['','','Total Donated:',number_format($total,2)]);
    }

    fclose($out);
    exit;
}

// ── PDF (Print-ready HTML) ───────────────────────────────
$txs  = getTransactions($pdo, $year, $month);
$dons = getDonations($pdo, $year, $month);
$dues = getDues($pdo, $year);
$breakdown = getMonthlyBreakdown($pdo, $year);

$totalIncome   = array_sum(array_column(array_filter($txs, fn($t)=>$t['type']==='income'),  'amount'));
$totalExpense  = array_sum(array_column(array_filter($txs, fn($t)=>$t['type']==='expense'), 'amount'));
$totalDons     = array_sum(array_column($dons, 'amount'));
$totalOwed     = array_sum(array_column(array_filter($dues, fn($d)=>!$d['paid']), 'amount'));
$balance       = $totalIncome + $totalDons - $totalExpense;

$periodLabel = $month ? "{$months[$month]} $year" : "Annual $year";

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Lodge Report — <?= e($periodLabel) ?></title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
  <link rel="icon" type="image/x-icon" href="/assets/img/star-ico.ico">
<link rel="stylesheet" href="/assets/css/style.css?v=1.13">
</head>
<body class="reports">
<div class="no-print" style="padding:12px;background:#1a3a6b;color:#fff;text-align:center">
  <strong>Click File → Print (or Ctrl+P) to save as PDF</strong>
  <button onclick="window.print()" style="margin-left:16px;padding:6px 16px;background:#c9a84c;color:#0d1f3c;border:none;border-radius:4px;cursor:pointer;font-weight:bold">🖨 Print / Save PDF</button>
</div>

<div style="padding:24px 32px">
  <div class="header">
    <div class="symbol"><i class="fas fa-star-of-david"></i></div>
    <h1>Estrella Del Rey David No. 11</h1>
    <h2>Financial Report — <?= e($periodLabel) ?></h2>
    <div class="meta">Generated: <?= date('F j, Y \a\t g:i A') ?> · CONFIDENTIAL</div>
  </div>

  <!-- Summary Boxes — FIXED to include outstanding dues -->
  <div class="summary-grid">
    <div class="summary-box"><div class="lbl">Total Income</div><div class="val income-val">$<?= number_format($totalIncome, 2) ?></div></div>
    <div class="summary-box"><div class="lbl">Total Donations</div><div class="val income-val">$<?= number_format($totalDons, 2) ?></div></div>
    <div class="summary-box"><div class="lbl">Total Expenses</div><div class="val expense-val">$<?= number_format($totalExpense, 2) ?></div></div>
    <div class="summary-box"><div class="lbl">Net Balance</div><div class="val balance-val">$<?= number_format($balance, 2) ?></div></div>
    <div class="summary-box"><div class="lbl">Outstanding Dues</div><div class="val dues-val">$<?= number_format($totalOwed, 2) ?></div></div>
  </div>

  <!-- Monthly Breakdown -->
  <h3>Monthly Breakdown — <?= $year ?></h3>
  <table>
    <thead><tr><th>Month</th><th>Income</th><th>Expenses</th><th>Balance</th></tr></thead>
    <tbody>
      <?php
      $bmap = [];
      foreach ($breakdown as $b) $bmap[$b['m']] = $b;
      for ($m = 1; $m <= 12; $m++):
        $b = $bmap[$m] ?? ['income'=>0,'expenses'=>0];
        $bal = $b['income'] - $b['expenses'];
      ?>
      <tr>
        <td><?= $months[$m] ?></td>
        <td class="income-td">$<?= number_format($b['income'],2) ?></td>
        <td class="expense-td">$<?= number_format($b['expenses'],2) ?></td>
        <td style="color:<?= $bal>=0?'#1a7a4a':'#9b2335' ?>;font-weight:bold">$<?= number_format($bal,2) ?></td>
      </tr>
      <?php endfor; ?>
      <tr class="total-row">
        <td>TOTAL</td>
        <td class="income-td">$<?= number_format($totalIncome,2) ?></td>
        <td class="expense-td">$<?= number_format($totalExpense,2) ?></td>
        <td>$<?= number_format($totalIncome-$totalExpense,2) ?></td>
      </tr>
    </tbody>
  </table>

  <!-- All Transactions -->
  <h3>Transaction Detail</h3>
  <table>
    <thead><tr><th>Date</th><th>Type</th><th>Description</th><th>Category</th><th>Member</th><th>Amount</th></tr></thead>
    <tbody>
      <?php foreach ($txs as $t): ?>
      <tr>
        <td><?= e($t['date']) ?></td>
        <td><?= e(strtoupper($t['type'])) ?></td>
        <td><?= e($t['description']) ?></td>
        <td><?= e($t['category']) ?></td>
        <td><?= e($t['member_name'] ?? '—') ?></td>
        <td class="<?= $t['type']==='income'?'income-td':'expense-td' ?>">$<?= number_format($t['amount'],2) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="total-row">
        <td colspan="5">Subtotal Transactions</td>
        <td>$<?= number_format($totalIncome-$totalExpense,2) ?></td>
      </tr>
    </tbody>
  </table>

  <!-- Donations -->
  <?php if (!empty($dons)): ?>
  <h3>Donations — <?= e($periodLabel) ?></h3>
  <table>
    <thead><tr><th>Date</th><th>Donor</th><th>Amount</th><th>Category</th><th>Note</th></tr></thead>
    <tbody>
      <?php foreach ($dons as $d): ?>
      <tr>
        <td><?= e($d['date']) ?></td>
        <td><?= $d['anonymous'] ? '[Anonymous]' : e($d['donor_name'] ?? '—') ?></td>
        <td class="income-td">$<?= number_format($d['amount'],2) ?></td>
        <td><?= e($d['category']) ?></td>
        <td><?= e($d['note'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="total-row"><td colspan="2">Total Donations</td><td class="income-td">$<?= number_format($totalDons,2) ?></td><td colspan="2"></td></tr>
    </tbody>
  </table>
  <?php endif; ?>

  <!-- Dues Summary -->
  <h3>Dues Status — <?= $year ?></h3>
  <table>
    <thead><tr><th>Member</th><th>Role</th><th>Jan</th><th>Feb</th><th>Mar</th><th>Apr</th><th>May</th><th>Jun</th><th>Jul</th><th>Aug</th><th>Sep</th><th>Oct</th><th>Nov</th><th>Dec</th><th>Outstanding</th></tr></thead>
    <tbody>
      <?php
      $duesMap = [];
      foreach ($dues as $d) $duesMap[$d['member_id']][$d['month']] = $d;
      $members = $pdo->query("SELECT id,name,role FROM members WHERE active=1 ORDER BY name")->fetchAll();
      foreach ($members as $mem):
        $owed = 0;
      ?>
      <tr>
        <td><?= e($mem['name']) ?></td>
        <td><?= e($mem['role']) ?></td>
        <?php for ($m=1; $m<=12; $m++):
          $d = $duesMap[$mem['id']][$m] ?? null;
          if ($d && !$d['paid']) $owed += $d['amount'];
        ?>
        <td style="text-align:center;font-size:11px">
          <?php if (!$d): ?><span style="color:#aaa">—</span>
          <?php elseif ($d['paid']): ?><span class="paid">✓</span>
          <?php else: ?><span class="unpaid">✗</span>
          <?php endif; ?>
        </td>
        <?php endfor; ?>
        <td class="<?= $owed>0?'unpaid':'paid' ?>">$<?= number_format($owed,2) ?></td>
      </tr>
      <?php endforeach; ?>
      <tr class="total-row">
        <td colspan="14">Total Outstanding Dues</td>
        <td class="dues-val">$<?= number_format($totalOwed,2) ?></td>
      </tr>
    </tbody>
  </table>

  <div class="footer">
    Estrella Del Rey David No. 11 Numero 11 · Confidential Financial Report · <?= date('Y') ?>
  </div>
</div>
<script>
  window.addEventListener('load', () => {
    if (window.location.search.includes('autoprint=1')) window.print();
  });
</script>
</body>
</html>