<?php
/**
 * admin/dashboard.php — Full Admin Control Panel
 * ============================================================
 * ============================================================
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/security.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/degrees.php';

secure_session_start();
require_admin();

$pdo         = DB::get();
$activeTab   = get_param('tab', 'dashboard');
$year        = int_val(get_param('year', date('Y')));
$filterMonth = int_val(get_param('month', 0));

// ── STATS — wrap each in try/catch so a missing table never crashes the page ──
try {
    $totalIncome    = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='income'")->fetchColumn();
    $totalExpenses  = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM transactions WHERE type='expense'")->fetchColumn();
    $totalDonations = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM donations")->fetchColumn();
} catch(Exception $e){ $totalIncome = $totalExpenses = $totalDonations = 0; }

try {
    $totalSavings = (float)$pdo->query(
        "SELECT COALESCE(SUM(CASE WHEN type='deposit' THEN amount ELSE -amount END),0) FROM savings"
    )->fetchColumn();
    $savingsRows = $pdo->query("SELECT * FROM savings ORDER BY date DESC")->fetchAll();
} catch(Exception $e){ $totalSavings = 0; $savingsRows = []; }

try {
    $totalDuesOwed = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM dues WHERE paid=0")->fetchColumn();
} catch(Exception $e){ $totalDuesOwed = 0; }

$activeMembers = (int)$pdo->query("SELECT COUNT(*) FROM members WHERE active=1")->fetchColumn();
$totalMembers  = (int)$pdo->query("SELECT COUNT(*) FROM members")->fetchColumn();
$balance       = $totalIncome + $totalDonations - $totalExpenses;

// ── MEMBERS ───────────────────────────────────────────────
$members = $pdo->query("SELECT * FROM members ORDER BY name")->fetchAll();

// ── TRANSACTIONS (year + optional month filter) ───────────
$txSql    = "SELECT t.*, m.name as member_name FROM transactions t LEFT JOIN members m ON t.member_id=m.id WHERE YEAR(t.date)=?";
$txParams = [$year];
if ($filterMonth > 0) { $txSql .= " AND MONTH(t.date)=?"; $txParams[] = $filterMonth; }
$txSql .= " ORDER BY t.date DESC, t.id DESC";
$txStmt = $pdo->prepare($txSql); $txStmt->execute($txParams);
$transactions = $txStmt->fetchAll();

$filteredIncome   = array_sum(array_column(array_filter($transactions, fn($t)=>$t['type']==='income'),  'amount'));
$filteredExpenses = array_sum(array_column(array_filter($transactions, fn($t)=>$t['type']==='expense'), 'amount'));

// ── DUES (members) ────────────────────────────────────────
$duesStmt = $pdo->prepare(
    "SELECT d.*, m.name, m.email, m.role FROM dues d
     JOIN members m ON d.member_id=m.id
     WHERE d.year=? AND d.member_id IS NOT NULL
     ORDER BY m.name, d.month"
);
$duesStmt->execute([$year]);
$allDues = $duesStmt->fetchAll();
// Map: member_id -> month -> dues row
$duesMap = [];
foreach ($allDues as $d) $duesMap[$d['member_id']][$d['month']] = $d;

// ── DUES (admin users) ─────────────────────────────────────
$adminDuesStmt = $pdo->prepare(
    "SELECT d.*, a.name, a.username, a.email FROM dues d
     JOIN admin_users a ON d.admin_id=a.id
     WHERE d.year=? AND d.admin_id IS NOT NULL
     ORDER BY a.name, d.month"
);
$adminDuesStmt->execute([$year]);
$allAdminDues = $adminDuesStmt->fetchAll();
// Map: admin_id -> month -> dues row
$adminDuesMap = [];
foreach ($allAdminDues as $d) $adminDuesMap[$d['admin_id']][$d['month']] = $d;

// Dues rate for selected year
try {
    $duesRateStmt = $pdo->prepare("SELECT amount FROM dues_settings WHERE year=?");
    $duesRateStmt->execute([$year]);
    $monthlyRate = (float)($duesRateStmt->fetchColumn() ?: 0);
} catch(Exception $e){ $monthlyRate = 0; }

// ── DONATIONS ─────────────────────────────────────────────
$donations = $pdo->query(
    "SELECT don.*, m.name as member_name FROM donations don LEFT JOIN members m ON don.member_id=m.id ORDER BY don.date DESC"
)->fetchAll();

// ── NEWS ──────────────────────────────────────────────────
$newsRows = $pdo->query("SELECT * FROM news ORDER BY created_at DESC")->fetchAll();

// ── ADMIN USERS ───────────────────────────────────────────
$adminUsers = $pdo->query(
    "SELECT id, username, name, email, active, last_login FROM admin_users ORDER BY id ASC"
)->fetchAll();

// ── MONTHLY CHART DATA (server-side) ──────────────────────
$chartStmt = $pdo->prepare(
    "SELECT MONTH(date) as m,
     SUM(CASE WHEN type='income' THEN amount ELSE 0 END) as income,
     SUM(CASE WHEN type='expense' THEN amount ELSE 0 END) as expenses
     FROM transactions WHERE YEAR(date)=? GROUP BY MONTH(date)"
);
$chartStmt->execute([$year]);
$chartRaw = $chartStmt->fetchAll();
$chartData = [];
for($i=1;$i<=12;$i++) $chartData[$i] = ['income'=>0,'expenses'=>0];
foreach($chartRaw as $c) $chartData[(int)$c['m']] = ['income'=>(float)$c['income'],'expenses'=>(float)$c['expenses']];

$MONTHS   = ['','Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
$MONTHS_F = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

$adminName = e($_SESSION['admin_name'] ?? 'Administrador');
$myAdminId = (int)$_SESSION['admin_id'];


// ── Header config ──────────────────────────────────────────
$pageTitle   = 'Panel Administrativo';
$pageContext = 'admin';
// Capture inline admin scripts to inject AFTER app.js via footer $extraScripts
ob_start();
?>
<script>
// ── Admin-page helpers (inline — depend on app.js being loaded first) ──

const CSRF = () => document.querySelector('meta[name="csrf-token"]').content;

async function adminPost(endpoint, data) {
  const r = await fetch('/api/' + endpoint, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-Token': CSRF(),
      'X-Requested-With': 'XMLHttpRequest'
    },
    body: JSON.stringify(data)
  });
  const text = await r.text();
  let json;
  try { json = JSON.parse(text); }
  catch(e) {
    // Server returned HTML (likely a PHP error or missing file)
    console.error('Non-JSON response:', text.slice(0,300));
    throw new Error('Server error — check PHP logs');
  }
  if (!json.success) throw new Error(json.error || 'Request failed');
  return json;
}

function showSection(id) { document.getElementById(id).style.display='block'; }
function hideSection(id) { document.getElementById(id).style.display='none'; }

// ── DUES RATE ────────────────────────────────────────────
async function saveDuesRate() {
  const amount = parseFloat(document.getElementById('dues-rate-input').value);
  const year   = parseInt(document.getElementById('dues-rate-year').value);
  if (!amount || amount <= 0) { toast('Ingrese un monto válido', 'error'); return; }
  try {
    await adminPost('dues_settings.php', { amount, year });
    toast('Tarifa actualizada a $' + amount.toFixed(2) + '/mes');
  } catch(err) { toast(err.message, 'error'); }
}

// ── DUES MONTH TOGGLE (members AND admins) ────────────────
function attachDueCellListeners() {
  const MONTH_NAMES = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];

  // Member cells
  document.querySelectorAll('.month-cell[data-member-id]').forEach(cell => {
    cell.addEventListener('click', function() {
      if (this.classList.contains('future')) return; // allow future too — admin may prepay
      const memberId = parseInt(this.dataset.memberId);
      const year     = parseInt(this.dataset.year);
      const month    = parseInt(this.dataset.month);
      const isPaid   = this.classList.contains('paid');
      const mName    = MONTH_NAMES[month];
      if (!confirm(`¿Cambiar ${mName} ${year} a "${isPaid ? 'pendiente' : 'pagado'}"?`)) return;
      adminPost('dues_adjustment.php', { member_id: memberId, year, month, paid: !isPaid })
        .then(() => { toast('Cuota actualizada'); setTimeout(() => location.reload(), 800); })
        .catch(err => toast(err.message, 'error'));
    });
  });

  // Admin user cells
  document.querySelectorAll('.month-cell[data-admin-id]').forEach(cell => {
    cell.addEventListener('click', function() {
      const adminId = parseInt(this.dataset.adminId);
      const year    = parseInt(this.dataset.year);
      const month   = parseInt(this.dataset.month);
      const isPaid  = this.classList.contains('paid');
      const mName   = MONTH_NAMES[month];
      if (!confirm(`¿Cambiar ${mName} ${year} a "${isPaid ? 'pendiente' : 'pagado'}" (Admin)?`)) return;
      adminPost('dues_adjustment.php', { admin_id: adminId, year, month, paid: !isPaid })
        .then(() => { toast('Cuota de admin actualizada'); setTimeout(() => location.reload(), 800); })
        .catch(err => toast(err.message, 'error'));
    });
  });
}
attachDueCellListeners();

// ── DUES MONTH CHECKBOXES (transaction form) ──────────────
function selectAllDuesMonths() {
  document.querySelectorAll('.dues-month-check').forEach(c => c.checked = true);
  updateDuesMonthTotal();
}
function clearDuesMonths() {
  document.querySelectorAll('.dues-month-check').forEach(c => c.checked = false);
  updateDuesMonthTotal();
}
function updateDuesMonthTotal() {
  const rate    = parseFloat(document.getElementById('tx-amount')?.value || 0);
  const checked = document.querySelectorAll('.dues-month-check:checked').length;
  const el = document.getElementById('dues-month-total');
  if (el) el.textContent = checked ? `Total: $${(rate*checked).toFixed(2)} (${checked} mes${checked!==1?'es':''})` : '';
}
document.getElementById('tx-amount')?.addEventListener('input', updateDuesMonthTotal);
document.getElementById('tx-category')?.addEventListener('change', function() {
  const isDues = this.value === 'Dues';
  document.getElementById('dues-month-row').style.display     = isDues ? '' : 'none';
  document.getElementById('tx-payer-type-row').style.display  = isDues ? '' : 'none';
  if (!isDues) {
    // Hide both payer rows when switching away from Dues
    document.getElementById('tx-member-row').style.display = 'none';
    document.getElementById('tx-admin-row').style.display  = 'none';
  } else {
    // Show the currently selected payer type
    const payerType = document.querySelector('input[name="payer_type"]:checked')?.value || 'member';
    updatePayerType(payerType);
  }
});

function updatePayerType(type) {
  document.getElementById('tx-member-row').style.display = type === 'member' ? '' : 'none';
  document.getElementById('tx-admin-row').style.display  = type === 'admin'  ? '' : 'none';
}
document.querySelectorAll('.dues-month-check').forEach(c => c.addEventListener('change', updateDuesMonthTotal));

// ── ADD TRANSACTION ───────────────────────────────────────
document.getElementById('form-add-tx')?.addEventListener('submit', async function(e) {
  e.preventDefault();
  const fd = new FormData(this);
  const months = [...document.querySelectorAll('.dues-month-check:checked')].map(c => +c.value);
  try {
    const payerType = document.querySelector('input[name="payer_type"]:checked')?.value || 'member';
    await adminPost('transactions.php', {
      type: fd.get('type'), amount: fd.get('amount'), date: fd.get('date'),
      description: fd.get('description'), category: fd.get('category'),
      member_id: payerType === 'member' ? (fd.get('member_id') || null) : null,
      admin_id:  payerType === 'admin'  ? (fd.get('admin_id')  || null) : null,
      reference: fd.get('reference') || null,
      dues_months: months, dues_year: fd.get('dues_year') || new Date().getFullYear()
    });
    toast('Transacción guardada');
    setTimeout(() => location.reload(), 900);
  } catch(err) { toast(err.message, 'error'); }
});

// ── INLINE EDIT TRANSACTION ───────────────────────────────
function enableEditRow(id) {
  const row = document.querySelector(`tr[data-id="${id}"]`);
  if (!row) return;
  row.querySelectorAll('[data-edit]').forEach(cell => {
    const val = cell.dataset.val, type = cell.dataset.edit;
    if (type==='number') cell.innerHTML=`<input type="number" class="edit-input" style="width:90px;background:rgba(10,22,40,.8);border:1px solid var(--royal-400,#4a72c4);border-radius:5px;padding:4px 8px;color:#e8f0f8" value="${val}" step="0.01">`;
    else if(type==='date') cell.innerHTML=`<input type="date"  class="edit-input" style="background:rgba(10,22,40,.8);border:1px solid var(--royal-400,#4a72c4);border-radius:5px;padding:4px 8px;color:#e8f0f8" value="${val}">`;
    else cell.innerHTML=`<input type="text" class="edit-input" style="width:140px;background:rgba(10,22,40,.8);border:1px solid var(--royal-400,#4a72c4);border-radius:5px;padding:4px 8px;color:#e8f0f8" value="${val}">`;
  });
  row.querySelector('.edit-btn').style.display   = 'none';
  row.querySelector('.save-btn').style.display   = 'inline-flex';
  row.querySelector('.cancel-btn').style.display = 'inline-flex';
}
async function saveEditRow(id) {
  const row = document.querySelector(`tr[data-id="${id}"]`);
  if (!row) return;
  const data = { id };
  row.querySelectorAll('[data-edit]').forEach(cell => {
    const inp = cell.querySelector('input');
    if (inp) data[cell.dataset.field] = inp.value;
  });
  try { await adminPost('transactions.php?action=update', data); toast('Actualizado'); setTimeout(() => location.reload(), 800); }
  catch(err) { toast(err.message, 'error'); }
}
async function deleteTx(id) {
  if (!confirm('¿Eliminar transacción?')) return;
  try { await adminPost('transactions.php?action=delete', {id}); toast('Eliminado'); document.querySelector(`tr[data-id="${id}"]`)?.remove(); }
  catch(err) { toast(err.message, 'error'); }
}

// ── MEMBERS ───────────────────────────────────────────────
document.getElementById('form-add-member')?.addEventListener('submit', async function(e) {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(this));
  try { await adminPost('members.php', data); toast('Miembro agregado'); setTimeout(() => location.reload(), 900); }
  catch(err) { toast(err.message, 'error'); }
});
async function toggleMember(id) {
  if (!confirm('¿Cambiar estado del miembro?')) return;
  try { await adminPost('members.php?action=toggle', {id}); toast('Estado actualizado'); setTimeout(() => location.reload(), 800); }
  catch(err) { toast(err.message, 'error'); }
}
function editMember(id, data) {
  document.getElementById('edit-m-id').value      = id;
  document.getElementById('edit-m-name').value    = data.name    || '';
  document.getElementById('edit-m-email').value   = data.email   || '';
  document.getElementById('edit-m-role').value    = data.role    || '';
  document.getElementById('edit-m-degree').value  = data.degree  || 1;
  document.getElementById('edit-m-phone').value   = data.phone   || '';
  document.getElementById('edit-m-address').value = data.address || '';
  document.getElementById('edit-m-notes').value   = data.notes   || '';
  openModal('modal-edit-member');
}
document.getElementById('form-edit-member')?.addEventListener('submit', async function(e) {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(this));
  try { await adminPost('members.php?action=update', data); toast('Miembro actualizado'); closeModal('modal-edit-member'); setTimeout(() => location.reload(), 800); }
  catch(err) { toast(err.message, 'error'); }
});

// ── DONATIONS ─────────────────────────────────────────────
function toggleDonorType(type) {
  document.getElementById('don-member-row').style.display = type==='member' ? '' : 'none';
  document.getElementById('don-name-row').style.display   = type==='external' ? '' : 'none';
  document.getElementById('don-email-row').style.display  = type==='external' ? '' : 'none';
}
document.getElementById('form-add-don')?.addEventListener('submit', async function(e) {
  e.preventDefault();
  const fd = new FormData(this);
  const data = Object.fromEntries(fd.entries());
  data.anonymous = fd.get('anonymous') ? 1 : 0;
  if (data.donor_type === 'external') data.member_id = '';
  try { await adminPost('donations.php', data); toast('Donación registrada'); setTimeout(() => location.reload(), 900); }
  catch(err) { toast(err.message, 'error'); }
});
async function deleteDonation(id) {
  if (!confirm('¿Eliminar donación?')) return;
  try { await adminPost('donations.php?action=delete', {id}); toast('Eliminado'); document.querySelector(`tr[data-don-id="${id}"]`)?.remove(); }
  catch(err) { toast(err.message, 'error'); }
}

// ── SAVINGS ───────────────────────────────────────────────
document.getElementById('form-add-saving')?.addEventListener('submit', async function(e) {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(this));
  try { await adminPost('savings.php', data); toast('Ahorro registrado'); setTimeout(() => location.reload(), 900); }
  catch(err) { toast(err.message, 'error'); }
});
async function deleteSaving(id) {
  if (!confirm('¿Eliminar este registro de ahorro?')) return;
  try { await adminPost('savings.php?action=delete', {id}); toast('Eliminado'); document.querySelector(`tr[data-sav-id="${id}"]`)?.remove(); }
  catch(err) { toast(err.message, 'error'); }
}

// ── NEWS ──────────────────────────────────────────────────
document.getElementById('form-add-news')?.addEventListener('submit', async function(e) {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(this));
  try { await adminPost('news.php', data); toast('Publicado'); setTimeout(() => location.reload(), 900); }
  catch(err) { toast(err.message, 'error'); }
});
async function deleteNews(id) {
  if (!confirm('¿Eliminar comunicado?')) return;
  try { await adminPost('news.php?action=delete', {id}); toast('Eliminado'); document.querySelector(`[data-news-id="${id}"]`)?.remove(); }
  catch(err) { toast(err.message, 'error'); }
}
async function toggleNews(id) {
  try { await adminPost('news.php?action=toggle', {id}); toast('Estado cambiado'); setTimeout(() => location.reload(), 800); }
  catch(err) { toast(err.message, 'error'); }
}

// ── ADMIN USERS ───────────────────────────────────────────
document.getElementById('form-add-admin')?.addEventListener('submit', async function(e) {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(this));
  try { await adminPost('admin_users.php', data); toast('Admin creado'); this.reset(); hideSection('add-admin-form'); setTimeout(() => location.reload(), 900); }
  catch(err) { toast(err.message, 'error'); }
});
function openEditAdminModal(id, name, email) {
  document.getElementById('edit-a-id').value    = id;
  document.getElementById('edit-a-name').value  = name;
  document.getElementById('edit-a-email').value = email;
  openModal('modal-edit-admin');
}
document.getElementById('form-edit-admin')?.addEventListener('submit', async function(e) {
  e.preventDefault();
  const data = Object.fromEntries(new FormData(this));
  if (!data.password) delete data.password; // don't send empty password
  try { await adminPost('admin_users.php?action=update', data); toast('Admin actualizado'); closeModal('modal-edit-admin'); setTimeout(() => location.reload(), 800); }
  catch(err) { toast(err.message, 'error'); }
});
async function toggleAdmin(id) {
  if (!confirm('¿Cambiar estado de este admin?')) return;
  try { await adminPost('admin_users.php?action=toggle', {id}); toast('Estado actualizado'); setTimeout(() => location.reload(), 800); }
  catch(err) { toast(err.message, 'error'); }
}
async function deleteAdmin(id) {
  if (!confirm('¿Eliminar esta cuenta admin? Esta acción no se puede deshacer.')) return;
  try { await adminPost('admin_users.php?action=delete', {id}); toast('Admin eliminado'); document.querySelector(`tr[data-au-id="${id}"]`)?.remove(); }
  catch(err) { toast(err.message, 'error'); }
}

// ── REPORTS ───────────────────────────────────────────────
function doExport(type, format) {
  const year  = document.getElementById('rpt-year').value;
  const month = document.getElementById('rpt-month').value;
  const url   = `/api/reports.php?type=${type}&format=${format}&year=${year}&month=${month}`;
  if (format==='csv') { const a=document.createElement('a');a.href=url;a.download='';document.body.appendChild(a);a.click();a.remove(); }
  else window.open(url, '_blank');
}
</script>
</script>
<?php
$extraScripts = ob_get_clean();
require_once __DIR__ . '/../includes/header.php';
?>
<div class="admin-wrap">

<!-- SIDEBAR -->
<aside class="sidebar">
  <div class="sidebar-session">
    <div class="sidebar-session-label">Sesión activa</div>
    <div class="sidebar-session-name"><?= $adminName ?></div>
  </div>
  <div class="sidebar-lbl">Panel</div>
  <?php
  $tabs = [
    'dashboard' => ['<i class="fas fa-star-of-david"></i>', 'Resumen General'],
    'members'   => ['<i class="fa-solid fa-user"></i>','Miembros'],
    'finances'  => ['<i class="fa-solid fa-coins"></i>','Finanzas'],
    'dues'      => ['<i class="fa-regular fa-calendar-days"></i>','Cuotas'],
    'donations' => ['<i class="fa-solid fa-hand-holding-dollar"></i>','Donaciones'],
    'savings'   => ['<i class="fa-solid fa-vault"></i>','Ahorros'],
    'news'      => ['<i class="fa-solid fa-bell"></i>','Comunicados'],
    'admins'    => ['<i class="fa-solid fa-user-tie"></i>','Usuarios Admin'],
    'reports'   => ['<i class="fa-solid fa-file-pdf"></i>','Reportes'],
  ];
  foreach($tabs as $id => [$icon,$label]):
    $cls = $activeTab===$id ? 'active' : '';
  ?>
  <a href="?tab=<?=$id?>&year=<?=$year?>" class="sidebar-link <?=$cls?>">
    <span><?=$icon?></span><?=$label?>
  </a>
  <?php endforeach; ?>
  <div class="sidebar-quick-report">
    <a href="/api/reports.php?type=financial&format=pdf&year=<?=$year?>" target="_blank"
       class="btn btn-gold btn-sm btn-full">🖨 Reporte Rápido</a>
  </div>
</aside>

<!-- MAIN CONTENT -->
<main class="main">

<?php /* ════════════════════ DASHBOARD ════════════════════ */ ?>
<?php if($activeTab==='dashboard'): ?>
<div class="tab-panel active">
  <div class="page-header">
    <div><h1 class="page-title">Resumen General</h1>
      <div class="page-sub">Año fiscal <?=$year?></div></div>
    <form method="GET" class="year-select-form">
      <input type="hidden" name="tab" value="dashboard">
      <select name="year" onchange="this.form.submit()" class="form-control select-sm">
        <?php for($y=date('Y');$y>=2020;$y--): ?>
        <option value="<?=$y?>" <?=$y==$year?'selected':''?>><?=$y?></option>
        <?php endfor; ?>
      </select>
    </form>
  </div>

  <div class="stats-grid">
    <div class="stat-card ">
      <div class="data-head">
      <div class="stat-label">Balance Neto</div>
     <div class="stat-value <?= $balance >= 0 ? 'positive' : 'negative' ?>">
    $<?= number_format($balance, 2) ?>
  </div>
</div>
    <div class="stat-card data"><div class="stat-label">Ingresos + Donaciones</div>
      <div class="stat-value positive">$<?=number_format($totalIncome+$totalDonations,2)?></div></div>
    <div class="stat-card data"><div class="stat-label">Egresos</div>
      <div class="stat-value negative">$<?=number_format($totalExpenses,2)?></div></div>
    <div class="stat-card data "><div class="stat-label">Ahorros</div>
      <div class="stat-value neutral">$<?=number_format($totalSavings,2)?></div></div>
    <div class="stat-card data"><div class="stat-label">Cuotas Pendientes</div>
      <div class="stat-value warning">$<?=number_format($totalDuesOwed,2)?></div></div>
    <div class="stat-card data"><div class="stat-label">Miembros Activos</div>
      <div class="stat-value"><?=$activeMembers?> / <?=$totalMembers?></div></div>
  </div>

  <!-- Monthly Chart (server-rendered PHP — no JS dependency) -->
  <div class="card mt-3">
    <div class="card-subheader">
      <h3 class="text-gold">Movimiento Mensual <?=$year?></h3>
      <div class="text-sm d-flex gap-sm">
        <span class="text-success">■ Ingresos</span>
        <span class="text-danger">■ Egresos</span>
      </div>
    </div>
    <?php
    $maxVal = 1;
    foreach($chartData as $c) $maxVal = max($maxVal, $c['income'], $c['expenses']);
    ?>
    <div class="bar-wrap">
      <?php for($m=1;$m<=12;$m++):
        $ih = $chartData[$m]['income']   > 0 ? max(3, round(($chartData[$m]['income']  /$maxVal)*95)) : 2;
        $eh = $chartData[$m]['expenses'] > 0 ? max(3, round(($chartData[$m]['expenses']/$maxVal)*95)) : 2;
      ?>
      <div class="bar-col">
        <div class="bar-inner-wrap">
          <div class="bar-in" style="height:<?=$ih?>px;width:12px"
               title="Ing <?=$MONTHS[$m]?>: $<?=number_format($chartData[$m]['income'],2)?>"></div>
          <div class="bar-ex" style="height:<?=$eh?>px;width:12px"
               title="Egr <?=$MONTHS[$m]?>: $<?=number_format($chartData[$m]['expenses'],2)?>"></div>
        </div>
        <span class="bar-lbl"><?=$MONTHS[$m]?></span>
      </div>
      <?php endfor; ?>
    </div>
    <?php
    $anyActivity = array_filter($chartData, fn($c)=>$c['income']>0||$c['expenses']>0);
    if(empty($anyActivity)):
    ?>
    <p class="empty-state">
      No hay transacciones en <?=$year?>. Use la pestaña <strong>Finanzas</strong> para agregar movimientos.
    </p>
    <?php endif; ?>
  </div>

  <!-- Recent transactions -->
  <div class="card mt-3">
    <div class="card-subheader">
      <h3 class="text-gold">Últimas Transacciones</h3>
      <a href="?tab=finances&year=<?=$year?>" class="btn btn-outline btn-sm">Ver todas →</a>
    </div>
    <?php if(empty($transactions)): ?>
    <p class="empty-state">No hay transacciones en <?=$year?>.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th>Monto</th></tr></thead>
        <tbody>
          <?php foreach(array_slice($transactions,0,8) as $t): ?>
          <tr>
            <td><?=e($t['date'])?></td>
            <td><span class="badge <?=$t['type']==='income'?'badge-income':'badge-expense'?>"><?=$t['type']==='income'?'Ingreso':'Egreso'?></span></td>
            <td><?=e($t['description'])?></td>
            <td class="<?=$t['type']==='income'?'text-success':'text-danger'?> text-bold">$<?=number_format($t['amount'],2)?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php /* ════════════════════ MEMBERS ════════════════════ */ ?>
<?php elseif($activeTab==='members'): ?>
<div class="tab-panel active">
  <div class="page-header">
    <div><h1 class="page-title">Miembros</h1>
      <div class="page-sub"><?=$totalMembers?> registrados · <?=$activeMembers?> activos</div></div>
    <button class="btn btn-gold" onclick="showSection('add-member-form')">+ Agregar Miembro</button>
  </div>

  <div id="add-member-form" style="display:none" class="card mb-3">
    <h3 class="text-gold mb-2">Nuevo Miembro</h3>
    <form id="form-add-member">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Nombre *</label>
          <input type="text" name="name" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Correo *</label>
          <input type="email" name="email" class="form-control" required></div>
        <div class="form-group"><label class="form-label">PIN (4-8 dígitos) *</label>
          <input type="text" name="pin" class="form-control" maxlength="8" pattern="[0-9]+" placeholder="ej: 1234" required></div>
        <div class="form-group"><label class="form-label">Cargo</label>
          <input type="text" name="role" class="form-control" value="Miembro"></div>
        <div class="form-group"><label class="form-label">Grado (Rito Escocés)</label>
          <select name="degree" class="form-control"><?= degrees_options(3) ?></select></div>
        <div class="form-group"><label class="form-label">Fecha Ingreso</label>
          <input type="date" name="joined_date" class="form-control" value="<?=date('Y-m-d')?>"></div>
        <div class="form-group"><label class="form-label">Teléfono</label>
          <input type="text" name="phone" class="form-control"></div>
        <div class="form-group form-full"><label class="form-label">Dirección</label>
          <input type="text" name="address" class="form-control"></div>
        <div class="form-group form-full"><label class="form-label">Notas</label>
          <textarea name="notes" class="form-control"></textarea></div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-gold">Guardar</button>
        <button type="button" class="btn btn-outline" onclick="hideSection('add-member-form')">Cancelar</button>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Nombre</th><th>Cargo</th><th>Grado</th><th>Estado</th><th>Cuotas (<?=date('Y')?>)</th><th>Ingreso</th><th>Acciones</th></tr></thead>
        <tbody>
          <?php foreach($members as $mem):
            $memOwed = 0;
            for($m=1;$m<=(int)date('n');$m++){
              if(!isset($duesMap[$mem['id']][$m]) || !$duesMap[$mem['id']][$m]['paid']) $memOwed++;
            }
            $owedAmt = $memOwed * $monthlyRate;
          ?>
          <tr>
            <td><strong><?=e($mem['name'])?></strong><br>
              <span class="sidebar-session-label"><?=e($mem['email'])?></span></td>
            <td><?=e($mem['role'])?></td>
            <td class="text-sm"><?=e($mem['degree'])?><?='°'?></td>
            <td><span class="badge <?=$mem['active']?'badge-success':'badge-danger'?>"><?=$mem['active']?'Activo':'Inactivo'?></span></td>
            <td><?php if($owedAmt>0): ?>
              <span class="text-danger"><?=$memOwed?> mes(es) — $<?=number_format($owedAmt,2)?></span>
            <?php else: ?><span class="text-success">Al corriente ✓</span><?php endif; ?></td>
            <td class="text-sm"><?=date('d/m/Y',strtotime($mem['joined_date']))?></td>
            <td>
              <div class="d-flex flex-wrap gap-sm">
                <button class="btn btn-outline btn-sm"
                  onclick="editMember(<?=$mem['id']?>,<?=htmlspecialchars(json_encode($mem),ENT_QUOTES)?>)">Editar</button>
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

<?php /* ════════════════════ FINANCES ════════════════════ */ ?>
<?php elseif($activeTab==='finances'): ?>
<div class="tab-panel active">
  <div class="page-header">
    <div><h1 class="page-title">Finanzas</h1>
      <div class="page-sub">Transacciones</div></div>
    <button class="btn btn-gold" onclick="showSection('add-tx-form')">+ Nueva Transacción</button>
  </div>

  <!-- Filters -->
  <div class="card mb-3">
    <form method="GET" class="filter-row">
      <input type="hidden" name="tab" value="finances">
      <div class="form-group mx-0"><label class="form-label">Año</label>
        <select name="year" class="form-control select-sm">
          <?php for($y=date('Y');$y>=2020;$y--): ?>
          <option value="<?=$y?>" <?=$y==$year?'selected':''?>><?=$y?></option>
          <?php endfor; ?>
        </select></div>
      <div class="form-group mx-0"><label class="form-label">Mes</label>
        <select name="month" class="form-control select-md">
          <option value="0" <?=$filterMonth==0?'selected':''?>>Todos</option>
          <?php for($m=1;$m<=12;$m++): ?>
          <option value="<?=$m?>" <?=$m==$filterMonth?'selected':''?>><?=$MONTHS_F[$m]?></option>
          <?php endfor; ?>
        </select></div>
      <button type="submit" class="btn btn-primary">Filtrar</button>
    </form>
    <div class="filter-summary">
      <div><span class="filter-stat-label">Ingresos</span>
        <div class="filter-stat-value text-success">$<?=number_format($filteredIncome,2)?></div></div>
      <div><span class="filter-stat-label">Egresos</span>
        <div class="filter-stat-value text-danger">$<?=number_format($filteredExpenses,2)?></div></div>
      <div><span class="filter-stat-label">Balance</span>
        <div class="filter-stat-value <?=($filteredIncome-$filteredExpenses)>=0?'text-success':'text-danger'?>">
          $<?=number_format($filteredIncome-$filteredExpenses,2)?></div></div>
    </div>
  </div>

  <!-- Add transaction form -->
  <div id="add-tx-form" style="display:none" class="card mb-3">
    <h3 class="text-gold mb-2">Nueva Transacción</h3>
    <form id="form-add-tx">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Tipo *</label>
          <select name="type" class="form-control"><option value="income">Ingreso</option><option value="expense">Egreso</option></select></div>
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
        <!-- Payer type selector — only visible when category = Dues -->
        <div class="form-group form-full" id="tx-payer-type-row" style="display:none">
          <label class="form-label">Tipo de Pagador</label>
          <div style="display:flex;gap:1.5rem">
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
              <input type="radio" name="payer_type" value="member" checked
                     onchange="updatePayerType('member')" style="accent-color:var(--gold)">
              Miembro
            </label>
            <label style="display:flex;align-items:center;gap:6px;cursor:pointer;font-size:13px">
              <input type="radio" name="payer_type" value="admin"
                     onchange="updatePayerType('admin')" style="accent-color:var(--gold)">
              Usuario Admin
            </label>
          </div>
        </div>
        <!-- Member select -->
        <div class="form-group" id="tx-member-row" style="display:none">
          <label class="form-label">Miembro</label>
          <select name="member_id" class="form-control">
            <option value="">— Seleccionar miembro —</option>
            <?php foreach($members as $m_): ?>
            <option value="<?=$m_['id']?>"><?=e($m_['name'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <!-- Admin user select -->
        <div class="form-group" id="tx-admin-row" style="display:none">
          <label class="form-label">Usuario Admin</label>
          <select name="admin_id" class="form-control">
            <option value="">— Seleccionar admin —</option>
            <?php foreach($adminUsers as $au): ?>
            <option value="<?=$au['id']?>"><?=e($au['name'] ?: $au['username'])?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Referencia</label>
          <input type="text" name="reference" class="form-control" placeholder="Opcional"></div>
        <div class="form-group form-full"><label class="form-label">Descripción *</label>
          <input type="text" name="description" class="form-control" required></div>
      </div>
      <!-- Dues months (shown when category=Dues) -->
      <div id="dues-month-row" class="dues-month-selector" style="display:none">
        <label class="form-label">Meses que cubre este pago de cuota</label>
        <div class="d-flex align-center gap-sm mb-1">
          <select name="dues_year" class="form-control select-sm">
            <?php for($y=date('Y');$y>=2020;$y--): ?><option value="<?=$y?>"><?=$y?></option><?php endfor; ?>
          </select>
          <button type="button" class="btn btn-outline btn-sm" onclick="selectAllDuesMonths()">Selec. todos</button>
          <button type="button" class="btn btn-outline btn-sm" onclick="clearDuesMonths()">Limpiar</button>
        </div>
        <div class="dues-months-grid">
          <?php for($m=1;$m<=12;$m++): ?>
          <label class="dues-month-label">
            <input type="checkbox" class="dues-month-check accent-gold" value="<?=$m?>">
            <?=$MONTHS[$m]?>
          </label>
          <?php endfor; ?>
        </div>
        <div id="dues-month-total" class="text-gold text-13 mt-1"></div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-gold">Guardar Transacción</button>
        <button type="button" class="btn btn-outline" onclick="hideSection('add-tx-form')">Cancelar</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h3 class="text-gold mb-2">
      Transacciones<?=$filterMonth>0?' — '.$MONTHS_F[$filterMonth]:'';?> <?=$year?>
    </h3>
    <?php if(empty($transactions)): ?>
    <p class="empty-state">No hay transacciones para este período.</p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th>Categoría</th><th>Miembro</th><th>Monto</th><th>Acciones</th></tr></thead>
        <tbody>
          <?php foreach($transactions as $t): ?>
          <tr data-id="<?=$t['id']?>">
            <td data-field="date"        data-edit="date"   data-val="<?=e($t['date'])?>"><?=e($t['date'])?></td>
            <td><span class="badge <?=$t['type']==='income'?'badge-income':'badge-expense'?>"><?=$t['type']==='income'?'Ingreso':'Egreso'?></span></td>
            <td data-field="description" data-edit="text"   data-val="<?=e($t['description'])?>"><?=e($t['description'])?></td>
            <td data-field="category"    data-edit="text"   data-val="<?=e($t['category'])?>"><?=e($t['category'])?></td>
            <td class="text-sm text-muted"><?=e($t['member_name']??'—')?></td>
            <td data-field="amount"      data-edit="number" data-val="<?=e($t['amount'])?>"
                class="<?=$t['type']==='income'?'text-success':'text-danger'?> text-bold">
              $<?=number_format($t['amount'],2)?></td>
            <td>
              <div class="d-flex gap-sm">
                <button class="btn btn-outline btn-sm edit-btn"   onclick="enableEditRow(<?=$t['id']?>)">Editar</button>
                <button class="btn btn-gold   btn-sm save-btn"    onclick="saveEditRow(<?=$t['id']?>)"   style="display:none">Guardar</button>
                <button class="btn btn-outline btn-sm cancel-btn" onclick="location.reload()"             style="display:none">✕</button>
                <button class="btn btn-danger  btn-sm"            onclick="deleteTx(<?=$t['id']?>)">✕</button>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php /* ════════════════════ DUES ════════════════════ */ ?>
<?php elseif($activeTab==='dues'): ?>
<div class="tab-panel active">
  <div class="page-header">
    <div><h1 class="page-title">Control de Cuotas</h1>
      <div class="page-sub">Haz clic en un mes para cambiar su estado</div></div>
    <form method="GET" class="year-select-form">
      <input type="hidden" name="tab" value="dues">
      <select name="year" onchange="this.form.submit()" class="form-control select-sm">
        <?php for($y=date('Y');$y>=2020;$y--): ?>
        <option value="<?=$y?>" <?=$y==$year?'selected':''?>><?=$y?></option>
        <?php endfor; ?>
      </select>
    </form>
  </div>

  <!-- Dues rate -->
  <div class="card mb-3">
    <h3 class="text-gold mb-1">Cuota Mensual <?=$year?></h3>
    <div class="dues-rate-row">
      <div class="form-group mx-0">
        <label class="form-label">Monto mensual ($)</label>
        <input type="number" id="dues-rate-input" class="form-control select-lg"
               value="<?=number_format($monthlyRate,2)?>" min="0" step="0.01">
      </div>
      <input type="hidden" id="dues-rate-year" value="<?=$year?>">
      <button class="btn btn-gold" onclick="saveDuesRate()">Actualizar Tarifa</button>
    </div>
    <?php if($monthlyRate>0): ?>
    <p class="text-muted text-sm mt-1">
      Tarifa actual: <strong class="text-gold">$<?=number_format($monthlyRate,2)?>/mes</strong>
    </p>
    <?php endif; ?>
  </div>

  <!-- Per-member dues grids — each month cell has data attributes for JS toggle -->
  <?php $currentMonth = (int)date('n'); ?>
  <?php foreach($members as $mem):
    if(!$mem['active']) continue;
    $paidCnt = 0; $owedCnt = 0;
    for($m=1;$m<=12;$m++){
      $d = $duesMap[$mem['id']][$m] ?? null;
      if($d && $d['paid']) $paidCnt++;
      elseif($m<=$currentMonth || $year<date('Y')) $owedCnt++;
    }
  ?>
  <div class="card mb-2">
    <div class="dues-member-header">
      <div>
        <strong class="text-white"><?=e($mem['name'])?></strong>
        <span class="text-sm text-muted"><?=e($mem['role'])?></span>
      </div>
      <div class="dues-member-stats">
        <span class="text-success">✓ <?=$paidCnt?> pagados</span>
        <?php if($owedCnt>0): ?>
        <span class="text-danger">⚠ <?=$owedCnt?> pendientes
          <?php if($monthlyRate>0): ?>— $<?=number_format($owedCnt * $monthlyRate,2)?><?php endif; ?></span>
        <?php endif; ?>
      </div>
    </div>
    <div class="month-grid">
      <?php for($m=1;$m<=12;$m++):
        $d      = $duesMap[$mem['id']][$m] ?? null;
        $isPast = $m <= $currentMonth || $year < date('Y');
        $isPaid = $d && $d['paid'];
        $cls    = $isPaid ? 'paid' : ($isPast ? 'unpaid' : 'future');
      ?>
      <div class="month-cell <?=$cls?>"
           data-member-id="<?=$mem['id']?>"
           data-year="<?=$year?>"
           data-month="<?=$m?>"
           <?= !$isPast && !$isPaid ? 'title="Mes futuro — click para marcar pagado"' : 'title="Click para cambiar estado"' ?>>
        <div><?=$MONTHS[$m]?></div>
        <?php if($isPaid): ?>
          <div class="text-xs">✓<?=$d['paid_date']?' '.date('d/m',strtotime($d['paid_date'])):''?></div>
        <?php elseif($isPast): ?>
          <div class="text-xs">⚠ Pend.</div>
          <?php if($monthlyRate>0): ?><div class="text-9">$<?=number_format($monthlyRate,2)?></div><?php endif; ?>
        <?php else: ?>
          <div class="text-xs">—</div>
        <?php endif; ?>
      </div>
      <?php endfor; ?>
    </div>
  </div>
  <?php endforeach; ?>

  <!-- ════ ADMIN USERS DUES ════ -->
  <?php if(!empty($adminUsers)): ?>
  <div class="mt-3">
    <h3 class="dues-section-header">
      <i class="fas fa-shield-halved"></i> Cuotas — Usuarios Administrativos
    </h3>
    <?php $currentMonth = (int)date('n'); ?>
    <?php foreach($adminUsers as $au):
      $paidCnt = 0; $owedCnt = 0;
      for($m=1;$m<=12;$m++){
        $d = $adminDuesMap[$au['id']][$m] ?? null;
        if($d && $d['paid']) $paidCnt++;
        elseif($m<=$currentMonth || $year<date('Y')) $owedCnt++;
      }
    ?>
    <div class="card mb-2">
      <div class="dues-member-header">
        <div>
          <strong class="text-white"><?=e($au['name'] ?: $au['username'])?></strong>
          <span class="badge-admin">Admin</span>
          <span class="text-sm text-muted">@<?=e($au['username'])?></span>
        </div>
        <div class="dues-member-stats">
          <span class="text-success">&#10003; <?=$paidCnt?> pagados</span>
          <?php if($owedCnt>0): ?>
          <span class="text-danger">&#9888; <?=$owedCnt?> pendientes
            <?php if($monthlyRate>0): ?>— $<?=number_format($owedCnt*$monthlyRate,2)?><?php endif; ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="month-grid">
        <?php for($m=1;$m<=12;$m++):
          $d      = $adminDuesMap[$au['id']][$m] ?? null;
          $isPast = $m <= $currentMonth || $year < date('Y');
          $isPaid = $d && $d['paid'];
          $cls    = $isPaid ? 'paid' : ($isPast ? 'unpaid' : 'future');
        ?>
        <div class="month-cell <?=$cls?>"
             data-admin-id="<?=$au['id']?>"
             data-year="<?=$year?>"
             data-month="<?=$m?>"
             title="<?=$isPast||$isPaid?'Click para cambiar estado':'Mes futuro — click para marcar pagado'?>">
          <div><?=$MONTHS[$m]?></div>
          <?php if($isPaid): ?>
            <div class="text-xs">&#10003;<?=$d['paid_date']?' '.date('d/m',strtotime($d['paid_date'])):''?></div>
          <?php elseif($isPast): ?>
            <div class="text-xs">&#9888; Pend.</div>
            <?php if($monthlyRate>0): ?><div class="text-9">$<?=number_format($monthlyRate,2)?></div><?php endif; ?>
          <?php else: ?>
            <div class="text-xs">—</div>
          <?php endif; ?>
        </div>
        <?php endfor; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<?php /* ════════════════════ DONATIONS ════════════════════ */ ?>
<?php elseif($activeTab==='donations'): ?>
<div class="tab-panel active">
  <div class="page-header">
    <div><h1 class="page-title">Donaciones</h1>
      <div class="page-sub">Total: $<?=number_format($totalDonations,2)?></div></div>
    <button class="btn btn-gold" onclick="showSection('add-don-form')">+ Registrar</button>
  </div>

  <div id="add-don-form" style="display:none" class="card mb-3">
    <h3 class="text-gold mb-2">Registrar Donación</h3>
    <form id="form-add-don">
      <div class="form-row">
        <div class="form-group form-full">
          <label class="form-label">Tipo de Donante</label>
          <div class="d-flex gap-2">
            <label class="radio-label">
              <input type="radio" name="donor_type" value="member" checked onchange="toggleDonorType('member')" class="accent-gold">
              Miembro de la Logia</label>
            <label class="radio-label">
              <input type="radio" name="donor_type" value="external" onchange="toggleDonorType('external')" class="accent-gold">
              Externo / No Miembro</label>
          </div>
        </div>
        <div class="form-group" id="don-member-row"><label class="form-label">Miembro</label>
          <select name="member_id" class="form-control">
            <option value="">— Seleccionar —</option>
            <?php foreach($members as $m_): ?><option value="<?=$m_['id']?>"><?=e($m_['name'])?></option><?php endforeach; ?>
          </select></div>
        <div class="form-group" id="don-name-row" style="display:none"><label class="form-label">Nombre del Donante</label>
          <input type="text" name="donor_name" class="form-control"></div>
        <div class="form-group" id="don-email-row" style="display:none"><label class="form-label">Correo (opc.)</label>
          <input type="email" name="donor_email" class="form-control"></div>
        <div class="form-group"><label class="form-label">Monto ($) *</label>
          <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required></div>
        <div class="form-group"><label class="form-label">Fecha *</label>
          <input type="date" name="date" class="form-control" value="<?=date('Y-m-d')?>" required></div>
        <div class="form-group"><label class="form-label">Categoría</label>
          <select name="category" class="form-control">
            <option>General</option><option>Charity</option><option>Building</option><option>Education</option><option>Events</option>
          </select></div>
        <div class="form-group form-full"><label class="form-label">Nota</label>
          <input type="text" name="note" class="form-control"></div>
        <div class="form-group">
          <label class="radio-label mt-1">
            <input type="checkbox" name="anonymous" value="1" class="accent-gold"> Donación Anónima</label>
        </div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-gold">Guardar</button>
        <button type="button" class="btn btn-outline" onclick="hideSection('add-don-form')">Cancelar</button>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Fecha</th><th>Donante</th><th>Categoría</th><th>Monto</th><th>Nota</th><th></th></tr></thead>
        <tbody>
          <?php foreach($donations as $d): ?>
          <tr data-don-id="<?=$d['id']?>">
            <td><?=e($d['date'])?></td>
            <td><?php if($d['anonymous']): ?><em class="text-muted">[Anónimo]</em>
              <?php else: ?><strong><?=e($d['member_name']??$d['donor_name']??'—')?></strong>
                <?php if($d['member_id']): ?><span class="badge badge-info ml-1">Miembro</span><?php endif; ?>
            <?php endif; ?></td>
            <td><span class="badge badge-gold"><?=e($d['category'])?></span></td>
            <td class="text-success text-bold">$<?=number_format($d['amount'],2)?></td>
            <td class="text-sm text-muted"><?=e($d['note']??'—')?></td>
            <td><button class="btn btn-danger btn-sm" onclick="deleteDonation(<?=$d['id']?>)">✕</button></td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($donations)): ?>
          <tr><td colspan="6" class="empty-state">No hay donaciones registradas.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php /* ════════════════════ SAVINGS ════════════════════ */ ?>
<?php elseif($activeTab==='savings'): ?>
<div class="tab-panel active">
  <div class="page-header">
    <div><h1 class="page-title">Ahorros</h1>
      <div class="page-sub">Fondo de reserva de la logia</div></div>
    <button class="btn btn-gold" onclick="showSection('add-saving-form')">+ Agregar</button>
  </div>

  <div class="stats-grid mb-3">
    <div class="stat-card"><div class="stat-label">Total Ahorros</div>
      <div class="stat-value neutral">$<?=number_format($totalSavings,2)?></div></div>
    <?php
    $savDeposits    = array_sum(array_column(array_filter($savingsRows,fn($s)=>$s['type']==='deposit'),   'amount'));
    $savWithdrawals = array_sum(array_column(array_filter($savingsRows,fn($s)=>$s['type']==='withdrawal'),'amount'));
    ?>
    <div class="stat-card"><div class="stat-label">Total Depósitos</div>
      <div class="stat-value positive">$<?=number_format($savDeposits,2)?></div></div>
    <div class="stat-card"><div class="stat-label">Total Retiros</div>
      <div class="stat-value negative">$<?=number_format($savWithdrawals,2)?></div></div>
  </div>

  <div id="add-saving-form" style="display:none" class="card mb-3">
    <h3 class="text-gold mb-2">Nuevo Movimiento de Ahorro</h3>
    <form id="form-add-saving">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Tipo *</label>
          <select name="type" class="form-control">
            <option value="deposit">Depósito</option>
            <option value="withdrawal">Retiro</option>
          </select></div>
        <div class="form-group"><label class="form-label">Monto ($) *</label>
          <input type="number" name="amount" class="form-control" min="0.01" step="0.01" required></div>
        <div class="form-group"><label class="form-label">Fecha *</label>
          <input type="date" name="date" class="form-control" value="<?=date('Y-m-d')?>" required></div>
        <div class="form-group form-full"><label class="form-label">Descripción *</label>
          <input type="text" name="description" class="form-control" required></div>
        <div class="form-group form-full"><label class="form-label">Referencia</label>
          <input type="text" name="reference" class="form-control" placeholder="Número de recibo, etc."></div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-gold">Guardar</button>
        <button type="button" class="btn btn-outline" onclick="hideSection('add-saving-form')">Cancelar</button>
      </div>
    </form>
  </div>

  <div class="card">
    <?php if(empty($savingsRows)): ?>
    <p class="empty-state">
      No hay movimientos de ahorro. <br>
      <small>Si acabas de crear la tabla, asegúrate de correr el script <code>database_additions.sql</code> en phpMyAdmin.</small>
    </p>
    <?php else: ?>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Fecha</th><th>Tipo</th><th>Descripción</th><th>Referencia</th><th>Monto</th><th></th></tr></thead>
        <tbody>
          <?php foreach($savingsRows as $s): ?>
          <tr data-sav-id="<?=$s['id']?>">
            <td><?=e($s['date'])?></td>
            <td><span class="badge <?=$s['type']==='deposit'?'badge-income':'badge-expense'?>">
              <?=$s['type']==='deposit'?'Depósito':'Retiro'?></span></td>
            <td><?=e($s['description'])?></td>
            <td class="text-sm text-muted"><?=e($s['reference']??'—')?></td>
            <td class="<?=$s['type']==='deposit'?'text-success':'text-danger'?> text-bold">
              <?=$s['type']==='deposit'?'+':'-'?>$<?=number_format($s['amount'],2)?></td>
            <td><button class="btn btn-danger btn-sm" onclick="deleteSaving(<?=$s['id']?>)">✕</button></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php /* ════════════════════ NEWS ════════════════════ */ ?>
<?php elseif($activeTab==='news'): ?>
<div class="tab-panel active">
  <div class="page-header">
    <div><h1 class="page-title">Comunicados</h1>
      <div class="page-sub">Visibles para todos los miembros</div></div>
    <button class="btn btn-gold" onclick="showSection('add-news-form')">+ Publicar</button>
  </div>
  <div id="add-news-form" style="display:none" class="card mb-3">
    <h3 class="text-gold mb-2">Nuevo Comunicado</h3>
    <form id="form-add-news">
      <div class="form-group"><label class="form-label">Título *</label>
        <input type="text" name="title" class="form-control" required></div>
      <div class="form-group"><label class="form-label">Autor</label>
        <input type="text" name="author" class="form-control" value="<?=$adminName?>"></div>
      <div class="form-group"><label class="form-label">Contenido *</label>
        <textarea name="body" class="form-control" style="min-height:120px" required></textarea></div>
      <div class="form-actions">
        <button type="submit" class="btn btn-gold">Publicar</button>
        <button type="button" class="btn btn-outline" onclick="hideSection('add-news-form')">Cancelar</button>
      </div>
    </form>
  </div>
  <div class="d-flex flex-col gap-2">
    <?php if(empty($newsRows)): ?>
    <p class="empty-state">No hay comunicados aún.</p>
    <?php endif; ?>
    <?php foreach($newsRows as $n): ?>
    <div class="card card-gold" data-news-id="<?=$n['id']?>">
      <div class="news-item-inner">
        <div>
          <div class="news-date"><?=e(date('d M Y',strtotime($n['created_at'])))?> · <?=e($n['author'])?>
            <?php if(!$n['published']): ?><span class="badge badge-danger ml-1">Oculto</span><?php endif; ?>
          </div>
          <h3 class="news-title"><?=e($n['title'])?></h3>
          <p class="news-body"><?=nl2br(e(mb_substr($n['body'],0,300)))?></p>
        </div>
        <div class="d-flex flex-col gap-sm shrink-0">
          <button class="btn btn-outline btn-sm" onclick="toggleNews(<?=$n['id']?>)"><?=$n['published']?'Ocultar':'Publicar'?></button>
          <button class="btn btn-danger btn-sm"  onclick="deleteNews(<?=$n['id']?>)">Eliminar</button>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<?php /* ════════════════════ ADMIN USERS ════════════════════ */ ?>
<?php elseif($activeTab==='admins'): ?>
<div class="tab-panel active">
  <div class="page-header">
    <div><h1 class="page-title">Usuarios Administrativos</h1>
      <div class="page-sub">Gestionar acceso al panel admin</div></div>
    <button class="btn btn-gold" onclick="showSection('add-admin-form')">+ Nuevo Admin</button>
  </div>

  <div id="add-admin-form" style="display:none" class="card mb-3">
    <h3 class="text-gold mb-2">Crear Cuenta Admin</h3>
    <form id="form-add-admin">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Usuario *</label>
          <input type="text" name="username" class="form-control" required placeholder="ej: tesorero"></div>
        <div class="form-group"><label class="form-label">Nombre Completo</label>
          <input type="text" name="name" class="form-control" placeholder="Nombre"></div>
        <div class="form-group"><label class="form-label">Correo *</label>
          <input type="email" name="email" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Contraseña * (mín. 6 caracteres)</label>
          <input type="password" name="password" class="form-control" required minlength="6"></div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-gold">Crear Admin</button>
        <button type="button" class="btn btn-outline" onclick="hideSection('add-admin-form')">Cancelar</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h3 class="text-gold mb-2">Cuentas Admin</h3>
    <div class="table-wrap">
      <table class="data-table">
        <thead><tr><th>Usuario</th><th>Nombre</th><th>Correo</th><th>Estado</th><th>Último Acceso</th><th>Acciones</th></tr></thead>
        <tbody>
          <?php foreach($adminUsers as $au): ?>
          <tr data-au-id="<?=$au['id']?>">
            <td><strong><?=e($au['username'])?></strong>
              <?php if($au['id']==$myAdminId): ?>
              <span class="text-xs text-gold">(tú)</span>
              <?php endif; ?>
            </td>
            <td><?=e($au['name']??'—')?></td>
            <td class="text-sm text-muted"><?=e($au['email']??'—')?></td>
            <td><span class="badge <?=$au['active']?'badge-success':'badge-danger'?>"><?=$au['active']?'Activo':'Inactivo'?></span></td>
            <td class="text-sm text-muted"><?=$au['last_login']?date('d/m/Y',strtotime($au['last_login'])):'Nunca'?></td>
            <td>
              <div class="d-flex gap-sm">
                <button class="btn btn-outline btn-sm"
                  onclick="openEditAdminModal(<?=$au['id']?>, '<?=e($au['name']??'')?>', '<?=e($au['email']??'')?>')">Editar</button>
                <?php if($au['id']!=$myAdminId): ?>
                <button class="btn btn-sm <?=$au['active']?'btn-danger':'btn-success'?>"
                  onclick="toggleAdmin(<?=$au['id']?>)"><?=$au['active']?'Desactivar':'Activar'?></button>
                <button class="btn btn-danger btn-sm"
                  onclick="deleteAdmin(<?=$au['id']?>)">✕</button>
                <?php endif; ?>
              </div>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if(empty($adminUsers)): ?>
          <tr><td colspan="6" class="empty-state">No hay usuarios admin.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php /* ════════════════════ REPORTS ════════════════════ */ ?>
<?php elseif($activeTab==='reports'): ?>
<div class="tab-panel active">
  <div class="page-header">
    <div><h1 class="page-title">Reportes</h1>
      <div class="page-sub">PDF o CSV para Google Sheets / Excel</div></div>
  </div>

  <div class="card mb-3">
    <h3 class="text-gold mb-2">Seleccionar Período</h3>
    <div class="filter-row">
      <div class="form-group mx-0"><label class="form-label">Año</label>
        <select id="rpt-year" class="form-control select-sm">
          <?php for($y=date('Y');$y>=2020;$y--): ?>
          <option value="<?=$y?>" <?=$y==date('Y')?'selected':''?>><?=$y?></option>
          <?php endfor; ?>
        </select></div>
      <div class="form-group mx-0"><label class="form-label">Mes (vacío = anual)</label>
        <select id="rpt-month" class="form-control select-lg">
          <option value="0">Todos los meses</option>
          <?php for($m=1;$m<=12;$m++): ?>
          <option value="<?=$m?>"><?=$MONTHS_F[$m]?></option>
          <?php endfor; ?>
        </select></div>
    </div>
  </div>

  <div class="reports-grid mb-3">
    <div class="stat-card report"><div class="stat-label">Ingresos</div>
      <div class="stat-value positive">$<?=number_format($totalIncome,2)?></div></div>
    <div class="stat-card report"><div class="stat-label">Donaciones</div>
      <div class="stat-value positive">$<?=number_format($totalDonations,2)?></div></div>
    <div class="stat-card report"><div class="stat-label">Egresos</div>
      <div class="stat-value negative">$<?=number_format($totalExpenses,2)?></div></div>
    
</div>
<div class="stat-card report">
  <div class="data-head">
<div class="stat-label">Balance Neto</div>
      <div class="stat-value <?= $balance >= 0 ? 'positive' : 'negative' ?>">
    $<?= number_format($balance, 2) ?>
</div>
</div>
    <div class="stat-card"><div class="stat-label">Ahorros</div>
      <div class="stat-value neutral">$<?=number_format($totalSavings,2)?></div></div>
    <div class="stat-card"><div class="stat-label">Cuotas Pendientes</div>
      <div class="stat-value warning">$<?=number_format($totalDuesOwed,2)?></div></div>
  </div>

  <div class="reports-grid">
    <?php
    $rpts = [
      ['financial','<i class="fa-solid fa-coins"></i> Reporte Financiero Completo','Transacciones, balance mensual, cuotas pendientes, donaciones.'],
      ['dues',     '<i class="fa-regular fa-calendar-days"></i> Estado de Cuotas',           'Todos los miembros con detalle mensual de pagos.'],
      ['donations','<i class="fa-solid fa-hand-holding-dollar"></i> Reporte de Donaciones',      'Historial completo de donaciones de miembros y externos.'],
    ];
    foreach($rpts as [$rtype,$rtitle,$rdesc]):
    ?>
    <div class="card">
      <h3 class="text-gold mb-1"><?=$rtitle?></h3>
      <p class="text-secondary text-sm mb-3"><?=$rdesc?></p>
      <div class="d-flex flex-wrap gap-sm">
        <button class="btn btn-outline btn-sm" onclick="doExport('<?=$rtype?>','csv')">⬇ CSV / Sheets</button>
        <button class="btn btn-gold btn-sm"    onclick="doExport('<?=$rtype?>','pdf')">🖨 Ver PDF</button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
</main>
</div>

<!-- EDIT MEMBER MODAL -->
<div id="modal-edit-member" class="modal-overlay" style="display:none">
  <div class="modal" style="max-width:640px">
    <h2 class="modal-title">Editar Miembro</h2>
    <form id="form-edit-member">
      <input type="hidden" name="id" id="edit-m-id">
      <div class="form-row">
        <div class="form-group"><label class="form-label">Nombre</label><input type="text" name="name" id="edit-m-name" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Correo</label><input type="email" name="email" id="edit-m-email" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Cargo</label><input type="text" name="role" id="edit-m-role" class="form-control"></div>
        <div class="form-group"><label class="form-label">Grado</label><select name="degree" id="edit-m-degree" class="form-control"><?=degrees_options()?></select></div>
        <div class="form-group"><label class="form-label">Teléfono</label><input type="text" name="phone" id="edit-m-phone" class="form-control"></div>
        <div class="form-group"><label class="form-label">Nuevo PIN (vacío = sin cambio)</label><input type="text" name="new_pin" class="form-control" maxlength="8" pattern="[0-9]*" placeholder="Solo si desea cambiar"></div>
        <div class="form-group form-full"><label class="form-label">Dirección</label><input type="text" name="address" id="edit-m-address" class="form-control"></div>
        <div class="form-group form-full"><label class="form-label">Notas</label><textarea name="notes" id="edit-m-notes" class="form-control"></textarea></div>
      </div>
      <div class="form-actions">
        <button type="submit" class="btn btn-gold">Guardar Cambios</button>
        <button type="button" class="btn btn-outline" onclick="closeModal('modal-edit-member')">Cancelar</button>
      </div>
    </form>
  </div>
</div>

<!-- EDIT ADMIN MODAL -->
<div id="modal-edit-admin" class="modal-overlay" style="display:none">
  <div class="modal" style="max-width:440px">
    <h2 class="modal-title">Editar Admin</h2>
    <form id="form-edit-admin">
      <input type="hidden" name="id" id="edit-a-id">
      <div class="form-group"><label class="form-label">Nombre</label><input type="text" name="name" id="edit-a-name" class="form-control"></div>
      <div class="form-group"><label class="form-label">Correo</label><input type="email" name="email" id="edit-a-email" class="form-control"></div>
      <div class="form-group"><label class="form-label">Nueva Contraseña (vacío = sin cambio)</label><input type="password" name="password" class="form-control" placeholder="Mínimo 6 caracteres"></div>
      <div class="form-actions">
        <button type="submit" class="btn btn-gold">Guardar</button>
        <button type="button" class="btn btn-outline" onclick="closeModal('modal-edit-admin')">Cancelar</button>
      </div>
    </form>
  </div>
</div>


<?php
require_once __DIR__ . '/../includes/footer.php';