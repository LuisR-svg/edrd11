/**
 * assets/js/app.js — Estrella Del Rey David Numero 11
 * ============================================================
 * Handles: tabs, modals, toasts, API calls, charts,
 * report exports, hamburger menu, dues management.
 * ============================================================
 */

"use strict";

// ─────────────────────────────────────────────────────────
// UTILITIES
// ─────────────────────────────────────────────────────────

/** Format number as currency string */
const fmt = (n) =>
  "$" +
  parseFloat(n || 0)
    .toFixed(2)
    .replace(/\B(?=(\d{3})+(?!\d))/g, ",");

/** Format ISO date string to readable */
const fmtDate = (d) =>
  d
    ? new Date(d + "T00:00:00").toLocaleDateString("en-US", {
        year: "numeric",
        month: "short",
        day: "numeric",
      })
    : "—";

/** Month name arrays */
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

// ─────────────────────────────────────────────────────────
// TOAST NOTIFICATIONS
// ─────────────────────────────────────────────────────────
function toast(msg, type = "success") {
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

// ─────────────────────────────────────────────────────────
// API FETCH WRAPPER
// ─────────────────────────────────────────────────────────
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

// ─────────────────────────────────────────────────────────
// TABS SYSTEM
// ─────────────────────────────────────────────────────────
function initTabs() {
  document.querySelectorAll("[data-tabs]").forEach((container) => {
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
    document.body.style.overflow = "hidden";
  }
}

function closeModal(id) {
  const m = document.getElementById(id);
  if (m) {
    m.style.display = "none";
    document.body.style.overflow = "";
  }
}

// Close on backdrop click
document.addEventListener("click", (e) => {
  if (e.target.classList.contains("modal-overlay")) {
    closeModal(e.target.id);
  }
});

// ─────────────────────────────────────────────────────────
// HAMBURGER MENU (mobile)
// ─────────────────────────────────────────────────────────
function initHamburger() {
  const hamburger = document.getElementById("hamburger");
  const navLinks = document.querySelector(".navbar-links");
  if (!hamburger || !navLinks) return;

  let menuOpen = false;

  hamburger.addEventListener("click", (e) => {
    e.stopPropagation();
    menuOpen = !menuOpen;

    if (menuOpen) {
      navLinks.style.display = "flex";
      navLinks.style.flexDirection = "column";
      navLinks.style.position = "fixed";
      navLinks.style.top = "64px";
      navLinks.style.left = "0";
      navLinks.style.right = "0";
      navLinks.style.width = "100%";
      navLinks.style.background = "var(--royal-950, #050f1e)";
      navLinks.style.zIndex = "9998";
      navLinks.style.padding = "1rem 1.5rem";
      navLinks.style.gap = "0.5rem";
      navLinks.style.borderBottom =
        "1px solid var(--border, rgba(74,114,196,0.3))";
      navLinks.style.boxShadow = "0 8px 24px rgba(0,0,0,0.4)";
      hamburger.textContent = "✕";
    } else {
      closeHamburger();
    }
  });

  function closeHamburger() {
    menuOpen = false;
    navLinks.style.display = "";
    navLinks.style.position = "";
    // Reset all styles applied by open
    [
      "flexDirection",
      "top",
      "left",
      "right",
      "width",
      "background",
      "zIndex",
      "padding",
      "gap",
      "borderBottom",
      "boxShadow",
    ].forEach((p) => (navLinks.style[p] = ""));
    hamburger.textContent = "☰";
  }

  // Close when a nav link or button is clicked
  navLinks.querySelectorAll("a, button").forEach((el) => {
    el.addEventListener("click", () => {
      if (menuOpen) closeHamburger();
    });
  });

  // Close when clicking outside the navbar
  document.addEventListener("click", (e) => {
    if (menuOpen && !e.target.closest(".navbar")) {
      closeHamburger();
    }
  });

  // Close on Escape key
  document.addEventListener("keydown", (e) => {
    if (e.key === "Escape" && menuOpen) closeHamburger();
  });
}

// ─────────────────────────────────────────────────────────
// MONTHLY BAR CHART (admin dashboard)
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

  const legend = document.getElementById("chart-legend");
  if (legend)
    legend.innerHTML = `
    <span style="color:var(--success);font-size:12px">■ Ingresos</span>
    <span style="color:var(--danger);font-size:12px;margin-left:12px">■ Egresos</span>`;
}

// ─────────────────────────────────────────────────────────
// TRANSACTION FORM (dues month selector)
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
// SUBMIT TRANSACTION
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
    dues_months: months,
    dues_year: fd.get("dues_year") || new Date().getFullYear(),
  };
  try {
    await api("transactions.php", data);
    toast("Transacción guardada!");
    form.reset();
    initTransactionForm();
    setTimeout(() => location.reload(), 1200);
  } catch {}
}

// ─────────────────────────────────────────────────────────
// INLINE EDIT TRANSACTION ROW
// ─────────────────────────────────────────────────────────
function enableEditRow(id) {
  const row = document.querySelector(`tr[data-id="${id}"]`);
  if (!row) return;
  row.querySelectorAll("[data-edit]").forEach((cell) => {
    const val = cell.dataset.val;
    const type = cell.dataset.edit;
    if (type === "select-type") {
      cell.innerHTML = `<select class="form-control" style="padding:4px 8px;width:110px">
        <option value="income"  ${val === "income" ? "selected" : ""}>Ingreso</option>
        <option value="expense" ${val === "expense" ? "selected" : ""}>Egreso</option>
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
    toast("Transacción actualizada!");
    setTimeout(() => location.reload(), 1000);
  } catch {}
}

// ─────────────────────────────────────────────────────────
// DELETE HELPERS
// ─────────────────────────────────────────────────────────
async function deleteTransaction(id) {
  if (!confirm("¿Eliminar esta transacción? Esta acción no se puede deshacer."))
    return;
  try {
    await api("transactions.php?action=delete", { id });
    toast("Transacción eliminada");
    document.querySelector(`tr[data-id="${id}"]`)?.remove();
  } catch {}
}

async function deleteMember(id) {
  if (
    !confirm(
      "¿Desactivar este miembro? Sus registros financieros se conservarán.",
    )
  )
    return;
  try {
    await api("members.php?action=toggle", { id });
    toast("Estado del miembro actualizado");
    setTimeout(() => location.reload(), 1000);
  } catch {}
}

async function deleteDonation(id) {
  if (!confirm("¿Eliminar este registro de donación?")) return;
  try {
    await api("donations.php?action=delete", { id });
    toast("Donación eliminada");
    document.querySelector(`tr[data-don-id="${id}"]`)?.remove();
  } catch {}
}

async function deleteNews(id) {
  if (!confirm("¿Eliminar este comunicado?")) return;
  try {
    await api("news.php?action=delete", { id });
    toast("Comunicado eliminado");
    document.querySelector(`[data-news-id="${id}"]`)?.remove();
  } catch {}
}

// ─────────────────────────────────────────────────────────
// DUES MONTH TOGGLE (admin — click month cell to adjust)
// ─────────────────────────────────────────────────────────
async function toggleDueMonth(memberId, year, month, currentPaid) {
  const monthName = MONTHS_FULL[month - 1] || `Mes ${month}`;
  const newState = currentPaid ? "pendiente" : "pagada";
  if (!confirm(`¿Cambiar cuota de ${monthName} ${year} a "${newState}"?`))
    return;
  try {
    await api("dues_adjustment.php", {
      member_id: memberId,
      year,
      month,
      paid: !currentPaid,
    });
    toast(`Cuota de ${monthName} marcada como ${newState}`);
    setTimeout(() => location.reload(), 900);
  } catch {}
}

// Make dues month cells clickable (call after page load)
function initDuesCells() {
  document.querySelectorAll(".month-cell[data-member-id]").forEach((cell) => {
    cell.style.cursor = "pointer";
    cell.title = "Click para cambiar estado";
    cell.addEventListener("click", function () {
      const memberId = parseInt(this.dataset.memberId);
      const year = parseInt(this.dataset.year);
      const month = parseInt(this.dataset.month);
      const isPaid = this.classList.contains("paid");
      toggleDueMonth(memberId, year, month, isPaid);
    });
  });
}

// ─────────────────────────────────────────────────────────
// REPORT EXPORTS
// ─────────────────────────────────────────────────────────
async function exportCSV(type) {
  try {
    const year =
      document.getElementById("rpt-year")?.value || new Date().getFullYear();
    const month = document.getElementById("rpt-month")?.value || 0;
    const res = await fetch(
      `/api/reports.php?type=${type}&format=csv&year=${year}&month=${month}`,
      {
        headers: {
          "X-Requested-With": "XMLHttpRequest",
          "X-CSRF-Token": csrfToken(),
        },
      },
    );
    const blob = await res.blob();
    const url = URL.createObjectURL(blob);
    const a = document.createElement("a");
    a.href = url;
    a.download = `lodge11_${type}_${new Date().toISOString().slice(0, 10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);
    toast("CSV exportado!");
  } catch {
    toast("Error al exportar", "error");
  }
}

function exportPDF(type) {
  const year =
    document.getElementById("rpt-year")?.value || new Date().getFullYear();
  const month = document.getElementById("rpt-month")?.value || 0;
  window.open(
    `/api/reports.php?type=${type}&format=pdf&year=${year}&month=${month}`,
    "_blank",
  );
}

// Alias used in admin dashboard report tab
function doExport(type, format) {
  if (format === "csv") exportCSV(type);
  else exportPDF(type);
}

// ─────────────────────────────────────────────────────────
// TRANSACTION PERIOD FILTER (finances tab)
// ─────────────────────────────────────────────────────────
function filterByPeriod() {
  const periodType = document.getElementById("filter-period")?.value;
  const month = document.getElementById("filter-month")?.value;
  const year = document.getElementById("filter-year")?.value;

  document.querySelectorAll(".tx-row").forEach((row) => {
    const [y, m] = (row.dataset.date || "").split("-");
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
// SCROLL ANIMATIONS (Intersection Observer)
// ─────────────────────────────────────────────────────────
function initScrollAnimations() {
  const observer = new IntersectionObserver(
    (entries) => {
      entries.forEach((entry) => {
        if (entry.isIntersecting) {
          entry.target.style.opacity = "1";
          entry.target.style.transform = "translateY(0)";
          observer.unobserve(entry.target);
        }
      });
    },
    { threshold: 0.1, rootMargin: "0px 0px -60px 0px" },
  );

  document
    .querySelectorAll(".card, .stat-card, .pillar, .animate-fadeUp")
    .forEach((el) => {
      // Skip elements already visible (e.g. above the fold)
      el.style.opacity = "0";
      el.style.transform = "translateY(20px)";
      el.style.transition = "opacity 0.5s ease, transform 0.5s ease";
      observer.observe(el);
    });
}

// ─────────────────────────────────────────────────────────
// DOM READY — INIT EVERYTHING
// ─────────────────────────────────────────────────────────
document.addEventListener("DOMContentLoaded", () => {
  // Core UI
  initTabs();
  initHamburger();
  initScrollAnimations();

  // Transaction form (admin finances tab)
  initTransactionForm();

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

  // Period filter change listeners
  ["filter-period", "filter-month", "filter-year"].forEach((id) => {
    document.getElementById(id)?.addEventListener("change", filterByPeriod);
  });

  // Dues month cells (admin dues tab — clickable to toggle)
  initDuesCells();

  // Auto-dismiss alert messages
  document.querySelectorAll(".auto-dismiss").forEach((el) => {
    setTimeout(() => {
      el.style.opacity = "0";
      el.style.transition = "opacity 0.4s";
      setTimeout(() => el.remove(), 400);
    }, 4000);
  });
});
