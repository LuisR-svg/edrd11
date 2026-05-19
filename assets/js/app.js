/**
 * assets/js/app.js — Estrella Del Rey David Numero 11
 * Main frontend JavaScript
 * ============================================================
 * Handles: tabs, modals, toast notifications, API calls,
 * financial charts, report exports, form validation.
 * ============================================================
 */

"use strict";

// ─────────────────────────────────────────────────────────
// UTILITY FUNCTIONS
// ─────────────────────────────────────────────────────────

/** Format currency */
const fmt = (n) =>
  "$" +
  parseFloat(n || 0)
    .toFixed(2)
    .replace(/\B(?=(\d{3})+(?!\d))/g, ",");

/** Format date to readable */
const fmtDate = (d) =>
  d
    ? new Date(d + "T00:00:00").toLocaleDateString("en-US", {
        year: "numeric",
        month: "short",
        day: "numeric",
      })
    : "—";

/** Month names */
const MONTHS = [
  "Jan",
  "Feb",
  "Mar",
  "Apr",
  "May",
  "Jun",
  "Jul",
  "Aug",
  "Sep",
  "Oct",
  "Nov",
  "Dec",
];
const MONTHS_FULL = [
  "January",
  "February",
  "March",
  "April",
  "May",
  "June",
  "July",
  "August",
  "September",
  "October",
  "November",
  "December",
];

/** Get CSRF token from meta tag */
const csrfToken = () =>
  document.querySelector('meta[name="csrf-token"]')?.content || "";

/** Show toast notification */
function toast(msg, type = "success") {
  // Remove existing
  document.querySelectorAll(".toast").forEach((t) => t.remove());
  const el = document.createElement("div");
  el.className = `toast toast-${type}`;
  el.textContent = msg;
  document.body.appendChild(el);
  setTimeout(() => {
    el.style.opacity = "0";
    el.style.transition = "opacity 0.4s";
    setTimeout(() => el.remove(), 400);
  }, 3000);
}

/** Make authenticated API call */
async function api(endpoint, data = null, method = "POST") {
  try {
    const opts = {
      method,
      headers: {
        "Content-Type": "application/json",
        "X-CSRF-Token": csrfToken(),
        "X-Requested-With": "XMLHttpRequest",
      },
    };
    if (data) opts.body = JSON.stringify(data);
    const res = await fetch("/api/" + endpoint, opts);
    const json = await res.json();
    if (!res.ok || !json.success)
      throw new Error(json.error || "Request failed");
    return json;
  } catch (err) {
    toast(err.message, "error");
    throw err;
  }
}

/** Show confirmation dialog */
function confirm_dialog(msg, onConfirm) {
  if (window.confirm(msg)) onConfirm();
}

// ─────────────────────────────────────────────────────────
// TABS SYSTEM
// ─────────────────────────────────────────────────────────
function initTabs(containerSelector) {
  const containers = document.querySelectorAll(
    containerSelector || "[data-tabs]",
  );
  containers.forEach((container) => {
    container.querySelectorAll(".tab-btn").forEach((btn) => {
      btn.addEventListener("click", () => {
        const target = btn.dataset.tab;
        container
          .querySelectorAll(".tab-btn")
          .forEach((b) => b.classList.remove("active"));
        container
          .querySelectorAll(".tab-content")
          .forEach((c) => c.classList.remove("active"));
        btn.classList.add("active");
        const content = container.querySelector(
          `[data-tab-content="${target}"]`,
        );
        if (content) content.classList.add("active");
      });
    });
  });
}

// ─────────────────────────────────────────────────────────
// MODAL SYSTEM
// ─────────────────────────────────────────────────────────
function openModal(id) {
  const m = document.getElementById(id);
  if (m) {
    m.style.display = "flex";
    m.classList.add("animate-fadeIn");
  }
}
function closeModal(id) {
  const m = document.getElementById(id);
  if (m) m.style.display = "none";
}
// Close on backdrop click
document.addEventListener("click", (e) => {
  if (e.target.classList.contains("modal-overlay")) closeModal(e.target.id);
});

// ─────────────────────────────────────────────────────────
// ADMIN: FINANCIAL CHART (monthly bar chart)
// ─────────────────────────────────────────────────────────
function renderMonthlyChart(monthlyData) {
  const wrap = document.getElementById("monthly-chart");
  if (!wrap || !monthlyData) return;
  const maxVal = Math.max(
    ...monthlyData.map((m) => Math.max(m.income, m.expenses)),
    1,
  );
  const h = 110;

  wrap.innerHTML = monthlyData
    .map((m) => {
      const iH = Math.round((m.income / maxVal) * h);
      const eH = Math.round((m.expenses / maxVal) * h);
      return `
      <div class="bar-col">
        <div style="display:flex;align-items:flex-end;gap:2px;height:${h}px">
          <div class="bar-income"  style="height:${iH}px;width:14px" title="Income: ${fmt(m.income)}"></div>
          <div class="bar-expense" style="height:${eH}px;width:14px" title="Expenses: ${fmt(m.expenses)}"></div>
        </div>
        <span class="bar-label">${m.month}</span>
      </div>`;
    })
    .join("");

  // Legend
  const legend = document.getElementById("chart-legend");
  if (legend)
    legend.innerHTML = `
    <span style="color:var(--success);font-size:12px">■ Income</span>
    <span style="color:var(--danger);font-size:12px;margin-left:12px">■ Expenses</span>`;
}

// ─────────────────────────────────────────────────────────
// ADMIN: ADD TRANSACTION — month selector for dues payments
// ─────────────────────────────────────────────────────────
function initTransactionForm() {
  const categoryEl = document.getElementById("tx-category");
  const duesRow = document.getElementById("dues-month-row");
  const memberRow = document.getElementById("tx-member-row");
  if (!categoryEl) return;

  function toggleDuesUI() {
    const isDues = categoryEl.value === "Dues";
    if (duesRow) duesRow.style.display = isDues ? "" : "none";
    if (memberRow) memberRow.style.display = isDues ? "" : "none";
  }
  categoryEl.addEventListener("change", toggleDuesUI);
  toggleDuesUI();

  // Month checkboxes: select/deselect all
  const selectAll = document.getElementById("dues-select-all");
  if (selectAll) {
    selectAll.addEventListener("click", () => {
      document
        .querySelectorAll(".dues-month-check")
        .forEach((cb) => (cb.checked = true));
      updateDuesTotal();
    });
  }
  document
    .querySelectorAll(".dues-month-check")
    .forEach((cb) => cb.addEventListener("change", updateDuesTotal));
}

function updateDuesTotal() {
  const rate = parseFloat(document.getElementById("tx-amount")?.value || 0);
  const checked = document.querySelectorAll(".dues-month-check:checked").length;
  const totalEl = document.getElementById("dues-month-total");
  if (totalEl)
    totalEl.textContent = `Total: ${fmt(rate * checked)} (${checked} month${checked !== 1 ? "s" : ""})`;
}

// ─────────────────────────────────────────────────────────
// ADMIN: SUBMIT ADD TRANSACTION
// ─────────────────────────────────────────────────────────
async function submitTransaction(form) {
  const fd = new FormData(form);
  const months = [...form.querySelectorAll(".dues-month-check:checked")].map(
    (cb) => parseInt(cb.value),
  );
  const data = {
    type: fd.get("type"),
    amount: fd.get("amount"),
    date: fd.get("date"),
    description: fd.get("description"),
    category: fd.get("category"),
    member_id: fd.get("member_id") || null,
    reference: fd.get("reference") || null,
    dues_months: months, // array of month numbers being paid
    dues_year: fd.get("dues_year") || new Date().getFullYear(),
  };
  try {
    await api("transactions.php", data);
    toast("Transaction recorded!");
    form.reset();
    initTransactionForm();
    setTimeout(() => location.reload(), 1200);
  } catch {}
}

// ─────────────────────────────────────────────────────────
// ADMIN: INLINE EDIT TRANSACTION
// ─────────────────────────────────────────────────────────
function enableEditRow(id) {
  const row = document.querySelector(`tr[data-id="${id}"]`);
  if (!row) return;
  row.querySelectorAll("[data-edit]").forEach((cell) => {
    const val = cell.dataset.val;
    const type = cell.dataset.edit;
    if (type === "select-type") {
      cell.innerHTML = `<select class="form-control" style="padding:4px 8px;width:110px">
        <option value="income" ${val === "income" ? "selected" : ""}>Income</option>
        <option value="expense" ${val === "expense" ? "selected" : ""}>Expense</option>
      </select>`;
    } else if (type === "number") {
      cell.innerHTML = `<input type="number" class="form-control" style="padding:4px 8px;width:100px" value="${val}" step="0.01" min="0">`;
    } else if (type === "date") {
      cell.innerHTML = `<input type="date" class="form-control" style="padding:4px 8px" value="${val}">`;
    } else {
      cell.innerHTML = `<input type="text" class="form-control" style="padding:4px 8px" value="${val}">`;
    }
  });
  row.querySelector(".edit-btn").style.display = "none";
  row.querySelector(".save-btn").style.display = "inline-flex";
  row.querySelector(".cancel-btn").style.display = "inline-flex";
}

async function saveEditRow(id) {
  const row = document.querySelector(`tr[data-id="${id}"]`);
  if (!row) return;
  const data = { id };
  row.querySelectorAll("[data-edit]").forEach((cell) => {
    const field = cell.dataset.field;
    const input = cell.querySelector("input, select");
    if (input && field) data[field] = input.value;
  });
  try {
    await api("transactions.php?action=update", data);
    toast("Transaction updated!");
    setTimeout(() => location.reload(), 1000);
  } catch {}
}

async function deleteTransaction(id) {
  confirm_dialog(
    "Delete this transaction? This cannot be undone.",
    async () => {
      try {
        await api("transactions.php?action=delete", { id });
        toast("Transaction deleted");
        document.querySelector(`tr[data-id="${id}"]`)?.remove();
      } catch {}
    },
  );
}

async function deleteMember(id) {
  confirm_dialog(
    "Deactivate this member? Their financial records will be kept.",
    async () => {
      try {
        await api("members.php?action=toggle", { id });
        toast("Member status updated");
        setTimeout(() => location.reload(), 1000);
      } catch {}
    },
  );
}

async function deleteDonation(id) {
  confirm_dialog("Delete this donation record?", async () => {
    try {
      await api("donations.php?action=delete", { id });
      toast("Donation deleted");
      document.querySelector(`tr[data-don-id="${id}"]`)?.remove();
    } catch {}
  });
}

async function deleteNews(id) {
  confirm_dialog("Delete this notice?", async () => {
    try {
      await api("news.php?action=delete", { id });
      toast("Notice deleted");
      document.querySelector(`[data-news-id="${id}"]`)?.remove();
    } catch {}
  });
}

// ─────────────────────────────────────────────────────────
// REPORT EXPORT — CSV
// ─────────────────────────────────────────────────────────
async function exportCSV(type) {
  try {
    const res = await fetch(`/api/reports.php?type=${type}&format=csv`, {
      headers: {
        "X-Requested-With": "XMLHttpRequest",
        "X-CSRF-Token": csrfToken(),
      },
    });
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `lodge11_${type}_${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    toast("CSV exported!");
  } catch {
    toast("Export failed", "error");
  }
}

// ─────────────────────────────────────────────────────────
// REPORT EXPORT — Print-ready PDF (opens new window)
// ─────────────────────────────────────────────────────────
function exportPDF(type) {
  window.open(`/api/reports.php?type=${type}&format=pdf`, "_blank");
}

// ─────────────────────────────────────────────────────────
// FILTER TRANSACTIONS BY PERIOD
// ─────────────────────────────────────────────────────────
function filterByPeriod() {
  const periodType = document.getElementById("filter-period")?.value;
  const month = document.getElementById("filter-month")?.value;
  const year = document.getElementById("filter-year")?.value;
  const rows = document.querySelectorAll(".tx-row");

  rows.forEach((row) => {
    const date = row.dataset.date || "";
    const [y, m] = date.split("-");
    let show = true;
    if (periodType === "monthly" && month && year)
      show = m === month.padStart(2, "0") && y === year;
    if (periodType === "yearly" && year) show = y === year;
    row.style.display = show ? "" : "none";
  });
  updateFilteredTotals();
}

function updateFilteredTotals() {
  let income = 0,
    expenses = 0;
  document.querySelectorAll('.tx-row:not([style*="none"])').forEach((row) => {
    const amt = parseFloat(row.dataset.amount || 0);
    if (row.dataset.type === "income") income += amt;
    if (row.dataset.type === "expense") expenses += amt;
  });
  const incEl = document.getElementById("filtered-income");
  const expEl = document.getElementById("filtered-expenses");
  const balEl = document.getElementById("filtered-balance");
  if (incEl) incEl.textContent = fmt(income);
  if (expEl) expEl.textContent = fmt(expenses);
  if (balEl) {
    balEl.textContent = fmt(income - expenses);
    balEl.style.color = income >= expenses ? "var(--success)" : "var(--danger)";
  }
}

// ─────────────────────────────────────────────────────────
// INIT ON PAGE LOAD
// ─────────────────────────────────────────────────────────
document.addEventListener("DOMContentLoaded", () => {
  // Init tabs everywhere
  initTabs("[data-tabs]");

  // Transaction form (admin finances tab)
  initTransactionForm();

  // Amount field updates dues total
  const amtField = document.getElementById("tx-amount");
  if (amtField) amtField.addEventListener("input", updateDuesTotal);

  // Transaction form submit
  const txForm = document.getElementById("tx-form");
  if (txForm) {
    txForm.addEventListener("submit", (e) => {
      e.preventDefault();
      submitTransaction(txForm);
    });
  }

  // Period filter change handlers
  ["filter-period", "filter-month", "filter-year"].forEach((id) => {
    document.getElementById(id)?.addEventListener("change", filterByPeriod);
  });

  // Animate elements on scroll (Intersection Observer)
  const observer = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.style.opacity = "1";
          entry.target.style.transform = "translateY(0)";
        }
      });
    },
    { threshold: 0.1 },
  );

  document.querySelectorAll(".card, .stat-card, .pillar").forEach((el) => {
    el.style.opacity = "0";
    el.style.transform = "translateY(20px)";
    el.style.transition = "opacity 0.5s ease, transform 0.5s ease";
    observer.observe(el);
  });

  // Hamburger menu (mobile)
  const hamburger = document.getElementById("hamburger");
  const mobileMenu = document.getElementById("mobile-menu");
  if (hamburger && mobileMenu) {
    hamburger.addEventListener("click", () => {
      mobileMenu.classList.toggle("open");
    });
  }

  // Auto-dismiss alerts
  document.querySelectorAll(".auto-dismiss").forEach((el) => {
    setTimeout(() => {
      el.style.opacity = "0";
      setTimeout(() => el.remove(), 300);
    }, 4000);
  });
});
