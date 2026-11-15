/**
 * admin-analytics.js
 * Barangay Inosluban — Admin Analytics Dashboard v2
 * Initialises all Chart.js charts from #adashData JSON
 */

/* ---- Center-text plugin for donut charts -------------------- */
const centerTextPlugin = {
  id: 'centerText',
  beforeDraw(chart) {
    const opt = chart.config.options.centerText;
    if (!opt) return;
    const { ctx, chartArea: { left, right, top, bottom } } = chart;
    const cx = (left + right) / 2;
    const cy = (top + bottom) / 2;
    ctx.save();
    ctx.font = 'bold 20px Inter, Segoe UI, sans-serif';
    ctx.fillStyle = '#252333';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(String(opt.value ?? ''), cx, cy - 9);
    ctx.font = '11px Inter, Segoe UI, sans-serif';
    ctx.fillStyle = '#666677';
    ctx.fillText(String(opt.label ?? ''), cx, cy + 10);
    ctx.restore();
  },
};
Chart.register(centerTextPlugin);

/* ---- Colour palette --------------------------------------- */
const C = {
  primary:  '#8B0015',
  pink:     '#F8E7EA',
  pinkLine: '#e07a90',
  orange:   '#d35400',
  amber:    '#c77700',
  green:    '#1a8c5a',
  blue:     '#1565c0',
  red:      '#c0392b',
  gray:     '#aaaabc',
  muted:    '#666677',
  border:   '#EEEEF2',
};

/* ---- Shared Chart.js defaults ----------------------------- */
Chart.defaults.font.family = 'Inter, Segoe UI, system-ui, sans-serif';
Chart.defaults.font.size   = 11;
Chart.defaults.color       = C.muted;
Chart.defaults.plugins.legend.display = false;

const tooltipDefaults = {
  backgroundColor: '#fff',
  titleColor: '#252333',
  bodyColor: '#252333',
  borderColor: C.border,
  borderWidth: 1,
  cornerRadius: 8,
  padding: 10,
};

/* ---- Helper: load data ------------------------------------ */
function loadData() {
  const el = document.getElementById('adashData');
  if (!el) return {};
  try { return JSON.parse(el.textContent); } catch { return {}; }
}

/* ---- Helper: pluck arrays --------------------------------- */
function pluck(arr, key) {
  return (arr || []).map(r => r[key]);
}

/* ===========================================================
   INIT
   =========================================================== */
document.addEventListener('DOMContentLoaded', () => {
  const data = loadData();

  initPurokBarChart(data.households_by_purok || []);
  initAgeGroupDonut(data.resident_age_groups || []);
  initHouseholdSizeChart(data.household_size_groups || []);
  initMonthlyTrendsChart(data.monthly_activity || []);
  initDocStatusDonut(data.document_statuses || []);
  initBlotterStatusDonut(data.blotter_status || []);
  initStaffHoursChart(data.staff_worktime || []);
  initPaymentTrendChart(data.monthly_activity || [], data.revenue || {});
  initNotificationDonut(data.notification_status || []);
  initWeatherTrendChart(data.weather_history || []);

  initSidebar();
  initDateRange();
  initTabs();
  initCollapsibles();
});

/* ===========================================================
   1. Purok bar chart (horizontal) — households_by_purok
   =========================================================== */
function initPurokBarChart(rows) {
  const el = document.getElementById('purokBarChart');
  if (!el) return;
  new Chart(el, {
    type: 'bar',
    data: {
      labels: pluck(rows, 'label'),
      datasets: [
        {
          label: 'Households',
          data: pluck(rows, 'households'),
          backgroundColor: C.primary,
          borderRadius: 5,
          borderSkipped: false,
        },
        {
          label: 'Members',
          data: pluck(rows, 'members'),
          backgroundColor: C.pinkLine,
          borderRadius: 5,
          borderSkipped: false,
        },
      ],
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        tooltip: { ...tooltipDefaults, mode: 'index', intersect: false },
        legend: {
          display: true,
          position: 'top',
          labels: { boxWidth: 10, boxHeight: 10, borderRadius: 3, usePointStyle: true, pointStyle: 'circle' },
        },
      },
      scales: {
        x: {
          grid: { color: C.border },
          ticks: { color: C.muted },
          border: { dash: [4, 4] },
        },
        y: {
          grid: { display: false },
          ticks: { color: C.muted },
        },
      },
    },
  });
}

/* ===========================================================
   2. Age group donut — resident_age_groups
   =========================================================== */
function initAgeGroupDonut(rows) {
  const el = document.getElementById('ageGroupDonut');
  if (!el) return;
  const total = rows.reduce((s, r) => s + (r.count || 0), 0);
  new Chart(el, {
    type: 'doughnut',
    data: {
      labels: pluck(rows, 'label'),
      datasets: [{
        data: pluck(rows, 'count'),
        backgroundColor: [C.primary, C.pinkLine, C.amber, C.green],
        borderWidth: 2,
        borderColor: '#fff',
        hoverOffset: 6,
      }],
    },
    options: {
      cutout: '65%',
      responsive: true,
      maintainAspectRatio: false,
      centerText: { value: total.toLocaleString(), label: 'People' },
      plugins: {
        tooltip: {
          ...tooltipDefaults,
          callbacks: {
            label: ctx => {
              const pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
              return ` ${ctx.label}: ${ctx.parsed.toLocaleString()} (${pct}%)`;
            },
          },
        },
        legend: {
          display: true,
          position: 'bottom',
          labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle', padding: 12 },
        },
      },
    },
  });
}

/* ===========================================================
   3. Household size horizontal bar — household_size_groups
   =========================================================== */
function initHouseholdSizeChart(rows) {
  const el = document.getElementById('householdSizeChart');
  if (!el) return;
  const total = rows.reduce((s, r) => s + (r.count || 0), 0);
  new Chart(el, {
    type: 'bar',
    data: {
      labels: pluck(rows, 'label'),
      datasets: [{
        label: 'Households',
        data: pluck(rows, 'count'),
        backgroundColor: [C.primary, C.pinkLine, C.amber, C.green],
        borderRadius: 5,
        borderSkipped: false,
      }],
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        tooltip: {
          ...tooltipDefaults,
          callbacks: {
            label: ctx => {
              const pct = total > 0 ? ((ctx.parsed.x / total) * 100).toFixed(1) : 0;
              return ` ${ctx.parsed.x.toLocaleString()} households (${pct}%)`;
            },
          },
        },
      },
      scales: {
        x: {
          grid: { color: C.border },
          ticks: { color: C.muted },
        },
        y: {
          grid: { display: false },
          ticks: { color: C.muted, font: { size: 10 } },
        },
      },
    },
  });
}

/* ===========================================================
   4. Monthly trends line chart — monthly_activity
   =========================================================== */
function initMonthlyTrendsChart(rows) {
  const el = document.getElementById('monthlyTrendsChart');
  if (!el) return;
  const labels = pluck(rows, 'label');
  new Chart(el, {
    type: 'line',
    data: {
      labels,
      datasets: [
        {
          label: 'Documents',
          data: pluck(rows, 'count'),
          borderColor: C.primary,
          backgroundColor: 'rgba(139,0,21,.08)',
          fill: true,
          tension: .4,
          pointRadius: 4,
          pointHoverRadius: 6,
          pointBackgroundColor: C.primary,
          borderWidth: 2.5,
        },
        {
          label: 'Appointments',
          data: pluck(rows, 'appointment_count'),
          borderColor: C.pinkLine,
          backgroundColor: 'rgba(224,122,144,.06)',
          fill: true,
          tension: .4,
          pointRadius: 4,
          pointHoverRadius: 6,
          pointBackgroundColor: C.pinkLine,
          borderWidth: 2.5,
        },
        {
          label: 'Blotter',
          data: pluck(rows, 'blotter_count'),
          borderColor: C.orange,
          backgroundColor: 'rgba(211,84,0,.06)',
          fill: true,
          tension: .4,
          pointRadius: 4,
          pointHoverRadius: 6,
          pointBackgroundColor: C.orange,
          borderWidth: 2.5,
        },
      ],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        tooltip: { ...tooltipDefaults },
        legend: {
          display: true,
          position: 'top',
          align: 'end',
          labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle', padding: 16 },
        },
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: { color: C.muted },
        },
        y: {
          grid: { color: C.border },
          ticks: { color: C.muted, stepSize: 1 },
          border: { dash: [4, 4] },
          beginAtZero: true,
        },
      },
    },
  });
}

/* ===========================================================
   5. Document status donut — document_statuses
   =========================================================== */
function initDocStatusDonut(rows) {
  const el = document.getElementById('docStatusDonut');
  if (!el) return;
  const total = rows.reduce((s, r) => s + (r.count || 0), 0);

  const colorMap = {
    'Approved': C.primary,
    'Pending':  C.pinkLine,
    'Rejected': C.gray,
    'Ready for Pickup': C.green,
    'Released': C.blue,
  };
  const bgColors = rows.map(r => colorMap[r.label] || C.gray);

  new Chart(el, {
    type: 'doughnut',
    data: {
      labels: pluck(rows, 'label'),
      datasets: [{
        data: pluck(rows, 'count'),
        backgroundColor: bgColors,
        borderWidth: 2,
        borderColor: '#fff',
        hoverOffset: 6,
      }],
    },
    options: {
      cutout: '65%',
      responsive: true,
      maintainAspectRatio: false,
      centerText: { value: total.toLocaleString(), label: 'Requests' },
      plugins: {
        tooltip: {
          ...tooltipDefaults,
          callbacks: {
            label: ctx => {
              const pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
              return ` ${ctx.label}: ${ctx.parsed.toLocaleString()} (${pct}%)`;
            },
          },
        },
        legend: {
          display: true,
          position: 'bottom',
          labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle', padding: 10 },
        },
      },
    },
  });
}

/* ===========================================================
   6. Blotter status donut — blotter_status
   =========================================================== */
function initBlotterStatusDonut(rows) {
  const el = document.getElementById('blotterStatusDonut');
  if (!el) return;
  const total = rows.reduce((s, r) => s + (r.count || 0), 0);
  const colorMap = {
    'Open':        C.primary,
    'In Progress': C.pinkLine,
    'Resolved':    C.green,
    'Closed':      C.gray,
  };
  const bgColors = rows.map(r => colorMap[r.label] || C.gray);
  new Chart(el, {
    type: 'doughnut',
    data: {
      labels: pluck(rows, 'label'),
      datasets: [{
        data: pluck(rows, 'count'),
        backgroundColor: bgColors,
        borderWidth: 2,
        borderColor: '#fff',
        hoverOffset: 6,
      }],
    },
    options: {
      cutout: '65%',
      responsive: true,
      maintainAspectRatio: false,
      centerText: { value: total.toLocaleString(), label: 'Cases' },
      plugins: {
        tooltip: {
          ...tooltipDefaults,
          callbacks: {
            label: ctx => {
              const pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
              return ` ${ctx.label}: ${ctx.parsed.toLocaleString()} (${pct}%)`;
            },
          },
        },
        legend: {
          display: true,
          position: 'bottom',
          labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle', padding: 10 },
        },
      },
    },
  });
}

/* ===========================================================
   7. Staff hours bar (grouped by position) — staff_worktime
   =========================================================== */
function initStaffHoursChart(rows) {
  const el = document.getElementById('staffHoursChart');
  if (!el) return;
  // Group by position
  const groups = {};
  rows.forEach(r => {
    if (!groups[r.position]) groups[r.position] = 0;
    groups[r.position] += (parseFloat(r.hours) || 0);
  });
  const labels = Object.keys(groups);
  const values = Object.values(groups);
  new Chart(el, {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Hours',
        data: values,
        backgroundColor: labels.map((_, i) => i % 2 === 0 ? C.primary : C.pinkLine),
        borderRadius: 5,
        borderSkipped: false,
      }],
    },
    options: {
      indexAxis: 'y',
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        tooltip: {
          ...tooltipDefaults,
          callbacks: {
            label: ctx => ` ${ctx.parsed.x.toFixed(1)} hours`,
          },
        },
      },
      scales: {
        x: {
          grid: { color: C.border },
          ticks: { color: C.muted },
          border: { dash: [4, 4] },
          beginAtZero: true,
        },
        y: {
          grid: { display: false },
          ticks: { color: C.muted, font: { size: 10 } },
        },
      },
    },
  });
}

/* ===========================================================
   8. Payment trend line chart (use monthly_activity doc counts as proxy)
   =========================================================== */
function initPaymentTrendChart(rows, revenue) {
  const el = document.getElementById('paymentTrendChart');
  if (!el) return;
  // Use last 6 months of data as a simple trend
  const slice = rows.slice(-6);
  new Chart(el, {
    type: 'line',
    data: {
      labels: pluck(slice, 'label'),
      datasets: [{
        label: 'Paid Requests',
        data: pluck(slice, 'count'),
        borderColor: C.primary,
        backgroundColor: 'rgba(139,0,21,.1)',
        fill: true,
        tension: .4,
        pointRadius: 3,
        pointBackgroundColor: C.primary,
        borderWidth: 2,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { tooltip: { ...tooltipDefaults } },
      scales: {
        x: {
          grid: { display: false },
          ticks: { color: C.muted, font: { size: 9 } },
        },
        y: {
          grid: { color: C.border },
          ticks: { color: C.muted, stepSize: 1 },
          border: { dash: [4, 4] },
          beginAtZero: true,
        },
      },
    },
  });
}

/* ===========================================================
   9. Notification delivery donut — notification_status
   =========================================================== */
function initNotificationDonut(rows) {
  const el = document.getElementById('notificationDonut');
  if (!el) return;
  // Aggregate: Delivered = Sent + Delivered, Failed = Failed, Queued = Queued
  const totals = { Delivered: 0, Failed: 0, Queued: 0 };
  rows.forEach(r => {
    if (r.status === 'Sent' || r.status === 'Delivered') totals.Delivered += (r.count || 0);
    else if (r.status === 'Failed') totals.Failed += (r.count || 0);
    else totals.Queued += (r.count || 0);
  });
  const labels = Object.keys(totals).filter(k => totals[k] > 0);
  const values = labels.map(k => totals[k]);
  const total  = values.reduce((a, b) => a + b, 0);
  const deliveredPct = total > 0 ? Math.round((totals.Delivered / total) * 100) : 0;
  const bgColors = { Delivered: C.primary, Failed: C.orange, Queued: C.gray };

  new Chart(el, {
    type: 'doughnut',
    data: {
      labels,
      datasets: [{
        data: values,
        backgroundColor: labels.map(l => bgColors[l] || C.gray),
        borderWidth: 2,
        borderColor: '#fff',
        hoverOffset: 6,
      }],
    },
    options: {
      cutout: '65%',
      responsive: true,
      maintainAspectRatio: false,
      centerText: { value: deliveredPct + '%', label: 'Delivered' },
      plugins: {
        tooltip: {
          ...tooltipDefaults,
          callbacks: {
            label: ctx => {
              const pct = total > 0 ? ((ctx.parsed / total) * 100).toFixed(1) : 0;
              return ` ${ctx.label}: ${ctx.parsed.toLocaleString()} (${pct}%)`;
            },
          },
        },
        legend: {
          display: true,
          position: 'bottom',
          labels: { boxWidth: 10, boxHeight: 10, usePointStyle: true, pointStyle: 'circle', padding: 10 },
        },
      },
    },
  });
}

/* ===========================================================
   10. Weather trend line — weather_history
   =========================================================== */
function initWeatherTrendChart(rows) {
  const el = document.getElementById('weatherTrendChart');
  if (!el) return;
  const slice = rows.slice(-14); // last 14 days
  const labels = slice.map(r => {
    if (!r.date) return '';
    const d = new Date(r.date);
    return (d.getMonth() + 1) + '/' + d.getDate();
  });
  new Chart(el, {
    type: 'line',
    data: {
      labels,
      datasets: [{
        label: 'Avg Temp (°C)',
        data: slice.map(r => parseFloat(r.average_temperature) || null),
        borderColor: C.primary,
        backgroundColor: 'rgba(139,0,21,.08)',
        fill: true,
        tension: .4,
        pointRadius: 3,
        pointBackgroundColor: C.primary,
        borderWidth: 2,
        spanGaps: true,
      }],
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        tooltip: {
          ...tooltipDefaults,
          callbacks: {
            label: ctx => ctx.raw !== null ? ` ${ctx.raw.toFixed(1)}°C` : ' N/A',
          },
        },
      },
      scales: {
        x: {
          grid: { display: false },
          ticks: { color: C.muted, maxRotation: 45, font: { size: 9 } },
        },
        y: {
          grid: { color: C.border },
          ticks: { color: C.muted, callback: v => v + '°C' },
          border: { dash: [4, 4] },
        },
      },
    },
  });
}

/* ===========================================================
   UI helpers
   =========================================================== */

/** Sidebar mobile toggle */
function initSidebar() {
  const sidebar  = document.querySelector('.adash-sidebar');
  const overlay  = document.querySelector('.adash-overlay');
  const toggleBtn = document.querySelector('.adash-sidebar-toggle');
  if (!sidebar) return;

  function open() {
    sidebar.classList.add('is-open');
    overlay && overlay.classList.add('is-visible');
  }
  function close() {
    sidebar.classList.remove('is-open');
    overlay && overlay.classList.remove('is-visible');
  }

  toggleBtn && toggleBtn.addEventListener('click', () => {
    sidebar.classList.contains('is-open') ? close() : open();
  });
  overlay && overlay.addEventListener('click', close);
}

/** Date range → localStorage */
function initDateRange() {
  const from = document.getElementById('adashDateFrom');
  const to   = document.getElementById('adashDateTo');
  if (!from || !to) return;
  // Restore
  const stored = JSON.parse(localStorage.getItem('adashDateRange') || '{}');
  if (stored.from) from.value = stored.from;
  if (stored.to)   to.value   = stored.to;
  // Save on change
  [from, to].forEach(el => {
    el.addEventListener('change', () => {
      localStorage.setItem('adashDateRange', JSON.stringify({ from: from.value, to: to.value }));
    });
  });
}

/** Simple tab switcher */
function initTabs() {
  document.querySelectorAll('[data-adash-tab]').forEach(btn => {
    btn.addEventListener('click', () => {
      const group = btn.closest('[data-adash-tabs]');
      if (!group) return;
      group.querySelectorAll('[data-adash-tab]').forEach(b => b.classList.remove('is-active'));
      group.querySelectorAll('.adash-tab-panel').forEach(p => p.classList.remove('is-active'));
      btn.classList.add('is-active');
      const panelId = btn.getAttribute('data-adash-tab');
      const panel = group.querySelector('#' + panelId);
      if (panel) panel.classList.add('is-active');
    });
  });
}

/** Collapsible sections */
function initCollapsibles() {
  document.querySelectorAll('[data-adash-collapse]').forEach(btn => {
    const targetId = btn.getAttribute('data-adash-collapse');
    const body = document.getElementById(targetId);
    if (!body) return;
    // start collapsed
    btn.setAttribute('aria-expanded', 'false');
    body.style.maxHeight = '0px';

    btn.addEventListener('click', () => {
      const expanded = btn.getAttribute('aria-expanded') === 'true';
      if (expanded) {
        body.style.maxHeight = '0px';
        btn.setAttribute('aria-expanded', 'false');
      } else {
        body.style.maxHeight = body.scrollHeight + 'px';
        btn.setAttribute('aria-expanded', 'true');
      }
    });
  });
}
