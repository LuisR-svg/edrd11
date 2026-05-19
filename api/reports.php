<?php
/**
 * api/reports.php — Financial Reports Export
 * ============================================================
 * Generates CSV (for Google Sheets/Excel) or print-ready HTML
 * that the browser can save as PDF via Ctrl+P / Print dialog.
 *
 * Types: financial, dues, donations, annual, monthly
 * Formats: csv, pdf
 * ============================================================
 */

require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db.php';

secure_session_start();
require_admin();

$type   = get_param('type',   'financial'); // financial|dues|donations|annual|monthly
$format = get_param('format', 'pdf');        // csv|pdf
$year   = int_val(get_param('year', date('Y')));
$month  = int_val(get_param('month', 0));    // 0 = all months
$pdo    = DB::get();

// ── FETCH DATA ───────────────────────────────────────────

// All transactions (optionally filtered)
function getTransactions($pdo, $year, $month) {
    $sql    = "SELECT t.*, m.name as member_name FROM transactions t LEFT JOIN members m ON t.member_id=m.id WHERE YEAR(t.date)=?";
    $params = [$year];
    if ($month > 0) { $sql .= " AND MONTH(t.date)=?"; $params[] = $month; }
    $sql .= " ORDER BY t.date ASC";
    $s = $pdo->prepare($sql); $s->execute($params);
    return $s->fetchAll();
}

// All dues (optionally filtered by year)
function getDues($pdo, $year) {
    $s = $pdo->prepare("SELECT d.*, m.name, m.email, m.role FROM dues d JOIN members m ON d.member_id=m.id WHERE d.year=? ORDER BY m.name, d.month");
    $s->execute([$year]); return $s->fetchAll();
}

// All donations (optionally filtered)
function getDonations($pdo, $year, $month) {
    $sql    = "SELECT * FROM donations WHERE YEAR(date)=?";
    $params = [$year];
    if ($month > 0) { $sql .= " AND MONTH(date)=?"; $params[] = $month; }
    $sql .= " ORDER BY date ASC";
    $s = $pdo->prepare($sql); $s->execute($params);
    return $s->fetchAll();
}

// Monthly breakdown
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
    // BOM for Excel UTF-8 compatibility
    fputs($out, "\xEF\xBB\xBF");

    if ($type === 'financial' || $type === 'annual' || $type === 'monthly') {
        $txs = getTransactions($pdo, $year, $month);
        $title = $month ? "{$months[$month]} $year Financial Report" : "$year Annual Financial Report";
        fputcsv($out, ["Estrella Del Rey David Numero 11 — $title"]);
        fputcsv($out, ["Generated:", date('Y-m-d H:i:s')]);
        fputcsv($out, []);
        fputcsv($out, ['Date','Type','Description','Category','Member','Reference','Amount']);
        $totalIncome = 0; $totalExpense = 0;
        foreach ($txs as $t) {
            fputcsv($out, [$t['date'], strtoupper($t['type']), $t['description'], $t['category'], $t['member_name'] ?? '', $t['reference'] ?? '', number_format($t['amount'],2)]);
            if ($t['type']==='income')  $totalIncome  += $t['amount'];
            if ($t['type']==='expense') $totalExpense += $t['amount'];
        }
        fputcsv($out, []);
        fputcsv($out, ['','','','','','Total Income:',  number_format($totalIncome,2)]);
        fputcsv($out, ['','','','','','Total Expenses:', number_format($totalExpense,2)]);
        fputcsv($out, ['','','','','','Net Balance:',   number_format($totalIncome-$totalExpense,2)]);

        // Monthly breakdown
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
        fputcsv($out, ["Estrella Del Rey David — Dues Report $year"]);
        fputcsv($out, ["Generated:", date('Y-m-d H:i:s')]);
        fputcsv($out, []);
        fputcsv($out, ['Member','Email','Role','Month','Amount','Status','Paid Date']);
        foreach ($dues as $d) {
            fputcsv($out, [$d['name'],$d['email'],$d['role'],$months[$d['month']],number_format($d['amount'],2),$d['paid']?'PAID':'UNPAID',$d['paid_date']??'']);
        }
    }

    if ($type === 'donations') {
        $dons = getDonations($pdo, $year, $month);
        fputcsv($out, ["Estrella Del Rey David — Donations Report $year"]);
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

$totalIncome  = array_sum(array_column(array_filter($txs, fn($t)=>$t['type']==='income'),  'amount'));
$totalExpense = array_sum(array_column(array_filter($txs, fn($t)=>$t['type']==='expense'), 'amount'));
$totalDons    = array_sum(array_column($dons, 'amount'));
$totalDues    = array_sum(array_column(array_filter($dues, fn($d)=>!$d['paid']), 'amount'));
$balance      = $totalIncome + $totalDons - $totalExpense;

$periodLabel = $month ? "{$months[$month]} $year" : "Annual $year";

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Lodge Report — <?= e($periodLabel) ?></title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }
  body { font-family: Georgia, serif; color: #0d1f3c; background: #fff; font-size: 13px; }
  .header { text-align: center; border-bottom: 3px solid #c9a84c; padding-bottom: 16px; margin-bottom: 24px; }
  .header h1 { font-size: 22px; color: #1a3a6b; }
  .header h2 { font-size: 16px; color: #2952a3; margin-top: 4px; }
  .header .meta { font-size: 11px; color: #666; margin-top: 6px; }
  .symbol { font-size: 36px; color: #c9a84c; }
  .summary-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 24px; }
  .summary-box { border: 1px solid #b8cfe8; border-radius: 8px; padding: 12px; text-align: center; }
  .summary-box .lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 1px; color: #2952a3; margin-bottom: 4px; }
  .summary-box .val { font-size: 20px; font-weight: bold; }
  .income-val  { color: #1a7a4a; }
  .expense-val { color: #9b2335; }
  .balance-val { color: #1a3a6b; }
  .dues-val    { color: #e65100; }
  h3 { color: #1a3a6b; border-bottom: 2px solid #c9a84c; padding-bottom: 6px; margin: 24px 0 12px; font-size: 14px; }
  table { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 16px; }
  th { background: #1a3a6b; color: #fff; padding: 8px 10px; text-align: left; font-size: 10px; letter-spacing: 1px; text-transform: uppercase; }
  td { padding: 7px 10px; border-bottom: 1px solid #e8f0f8; }
  tr:nth-child(even) td { background: #f5f8fc; }
  .income-td  { color: #1a7a4a; font-weight: bold; }
  .expense-td { color: #9b2335; font-weight: bold; }
  .total-row td { font-weight: bold; background: #e8f0f8 !important; border-top: 2px solid #1a3a6b; }
  .paid    { color: #1a7a4a; font-weight: bold; }
  .unpaid  { color: #9b2335; font-weight: bold; }
  .footer { text-align: center; margin-top: 32px; padding-top: 12px; border-top: 1px solid #b8cfe8; font-size: 11px; color: #999; }
  @media print {
    body { font-size: 11px; }
    .no-print { display: none; }
    @page { margin: 1cm; }
  }
</style>
</head>
<body>
<div class="no-print" style="padding:12px;background:#1a3a6b;color:#fff;text-align:center">
  <strong>Click File → Print (or Ctrl+P) to save as PDF</strong>
  <button onclick="window.print()" style="margin-left:16px;padding:6px 16px;background:#c9a84c;color:#0d1f3c;border:none;border-radius:4px;cursor:pointer;font-weight:bold">🖨 Print / Save PDF</button>
</div>

<div style="padding:24px 32px">
  <div class="header">
    <div class="symbol">⬡</div>
    <h1>Estrella Del Rey David Numero 11</h1>
    <h2>Financial Report — <?= e($periodLabel) ?></h2>
    <div class="meta">Generated: <?= date('F j, Y \a\t g:i A') ?> · CONFIDENTIAL</div>
  </div>

  <!-- Summary Boxes -->
  <div class="summary-grid">
    <div class="summary-box"><div class="lbl">Total Income</div><div class="val income-val">$<?= number_format($totalIncome + $totalDons, 2) ?></div></div>
    <div class="summary-box"><div class="lbl">Total Expenses</div><div class="val expense-val">$<?= number_format($totalExpense, 2) ?></div></div>
    <div class="summary-box"><div class="lbl">Net Balance</div><div class="val balance-val">$<?= number_format($balance, 2) ?></div></div>
    <div class="summary-box"><div class="lbl">Outstanding Dues</div><div class="val dues-val">$<?= number_format($totalDues, 2) ?></div></div>
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
        <td colspan="5">Net Balance</td>
        <td>$<?= number_format($totalIncome-$totalExpense,2) ?></td>
      </tr>
    </tbody>
  </table>

  <!-- Dues Summary -->
  <h3>Dues Status — <?= $year ?></h3>
  <table>
    <thead><tr><th>Member</th><th>Role</th><th>Jan</th><th>Feb</th><th>Mar</th><th>Apr</th><th>May</th><th>Jun</th><th>Jul</th><th>Aug</th><th>Sep</th><th>Oct</th><th>Nov</th><th>Dec</th><th>Outstanding</th></tr></thead>
    <tbody>
      <?php
      // Group dues by member
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
    </tbody>
  </table>

  <!-- Donations -->
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

  <div class="footer">
    Estrella Del Rey David Numero 11 · Confidential Financial Report · <?= date('Y') ?>
  </div>
</div>
<script>
  // Auto-open print dialog
  window.addEventListener('load', () => {
    if (window.location.search.includes('autoprint=1')) window.print();
  });
</script>
</body>
</html>