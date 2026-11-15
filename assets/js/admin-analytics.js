(() => {
  const dataElement = document.querySelector("#adminAnalyticsData");
  if (!dataElement || !window.Chart) {
    return;
  }

  let data;
  try {
    data = JSON.parse(dataElement.textContent || "{}");
  } catch (error) {
    console.error("Analytics chart data could not be read.", error);
    return;
  }

  Chart.defaults.font.family =
    'Inter, ui-sans-serif, system-ui, -apple-system, "Segoe UI", sans-serif';
  Chart.defaults.color = "#786f72";
  Chart.defaults.borderColor = "rgba(120, 111, 114, 0.13)";

  const colors = {
    red: "#97111c",
    redSoft: "#d95763",
    gold: "#e9ad25",
    orange: "#ee9d37",
    green: "#4ca77c",
    gray: "#c5bdbe",
  };

  const getCanvas = (id) => document.getElementById(id);
  const labels = (items) => (items || []).map((item) => item.label);
  const counts = (items) => (items || []).map((item) => item.count);
  const doughnutOptions = {
    responsive: true,
    maintainAspectRatio: false,
    cutout: "68%",
    plugins: {
      legend: {
        position: "bottom",
        labels: { usePointStyle: true, padding: 16, boxWidth: 8 },
      },
    },
  };

  const monthlyCanvas = getCanvas("analyticsMonthlyChart");
  if (monthlyCanvas) {
    new Chart(monthlyCanvas, {
      type: "line",
      data: {
        labels: (data.monthly_activity || []).map((item) => item.label),
        datasets: [
          {
            label: "Documents",
            data: (data.monthly_activity || []).map((item) => item.count),
            borderColor: colors.red,
            backgroundColor: "rgba(151, 17, 28, .08)",
            fill: true,
            tension: 0.35,
            pointRadius: 3,
            pointHoverRadius: 5,
          },
          {
            label: "Appointments",
            data: (data.monthly_activity || []).map(
              (item) => item.appointment_count,
            ),
            borderColor: colors.redSoft,
            backgroundColor: "transparent",
            tension: 0.35,
            pointRadius: 3,
          },
          {
            label: "Incidents",
            data: (data.monthly_activity || []).map(
              (item) => item.blotter_count,
            ),
            borderColor: colors.orange,
            backgroundColor: "transparent",
            tension: 0.35,
            pointRadius: 3,
          },
        ],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { intersect: false, mode: "index" },
        plugins: {
          legend: {
            position: "top",
            align: "start",
            labels: { usePointStyle: true, padding: 18, boxWidth: 8 },
          },
        },
        scales: {
          y: { beginAtZero: true, grid: { drawBorder: false } },
          x: { grid: { display: false } },
        },
      },
    });
  }

  const createDoughnut = (id, items, palette) => {
    const canvas = getCanvas(id);
    if (!canvas || !items?.length) {
      return;
    }
    new Chart(canvas, {
      type: "doughnut",
      data: {
        labels: labels(items),
        datasets: [
          {
            data: counts(items),
            backgroundColor: palette,
            borderWidth: 3,
            borderColor: "#fff",
          },
        ],
      },
      options: doughnutOptions,
    });
  };

  createDoughnut("analyticsResidentChart", data.resident_statuses, [
    colors.red,
    colors.redSoft,
    colors.gold,
    colors.gray,
  ]);
  createDoughnut("analyticsRequestChart", data.document_statuses, [
    colors.red,
    colors.gold,
    colors.green,
    colors.gray,
    colors.orange,
  ]);

  const notificationItems = Object.values(
    (data.notification_status || []).reduce((result, item) => {
      result[item.status] = (result[item.status] || 0) + item.count;
      return result;
    }, {}),
  ).map((count, index, values) => ({
    label: Object.keys(
      (data.notification_status || []).reduce((result, item) => {
        result[item.status] = true;
        return result;
      }, {}),
    )[index],
    count,
  }));
  createDoughnut("analyticsNotificationChart", notificationItems, [
    colors.green,
    colors.red,
    colors.gold,
    colors.gray,
  ]);
})();
