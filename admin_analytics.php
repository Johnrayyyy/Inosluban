<?php
declare(strict_types=1);

require_once __DIR__ . "/includes/bootstrap.php";
require_once __DIR__ . "/includes/staff.php";
require_once __DIR__ . "/includes/admin.php";
require_once __DIR__ . "/includes/analytics.php";
require_once __DIR__ . "/includes/households.php";
require_once __DIR__ . "/includes/document_payments.php";
require_once __DIR__ . "/includes/gemini.php";
require_once __DIR__ . "/includes/notification_email.php";

require_admin();
ensure_admin_schema();
ensure_staff_tables();
ensure_household_schema();
ensure_document_payment_schema();
$refreshWeatherOnLogin = !empty($_SESSION["weather_refresh_on_login"]);
unset($_SESSION["weather_refresh_on_login"]);

$aiPrediction = "";
$aiError      = "";
$actionMessage = "";
$actionError   = "";
$actionWarning = "";
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $action = (string) ($_POST["action"] ?? "");
    try {
        verify_csrf_token($_POST["csrf_token"] ?? "");
        if ($action === "predict") {
            $aiPrediction = ask_gemini(
                admin_analytics_ai_prompt(admin_analytics_data()),
            );
        } elseif ($action === "create_disaster_alert") {
            $alertType    = trim((string) ($_POST["alert_type"]    ?? ""));
            $severity     = (string) ($_POST["severity"]     ?? "");
            $affectedArea = trim((string) ($_POST["affected_area"] ?? ""));
            $alertMessage = trim((string) ($_POST["alert_message"] ?? ""));
            $expiresAt    = trim((string) ($_POST["expires_at"]    ?? ""));
            $allowedSeverities = [
                "Information", "Advisory", "Watch", "Warning", "Emergency",
            ];
            if (
                $alertType === "" ||
                strlen($alertType) > 100 ||
                !in_array($severity, $allowedSeverities, true) ||
                strlen($affectedArea) > 255 ||
                $alertMessage === "" ||
                strlen($alertMessage) > 10000
            ) {
                throw new RuntimeException(
                    "Enter an alert type, valid severity, and a message. The affected area must be 255 characters or fewer.",
                );
            }
            if ($expiresAt !== "") {
                $expiry = new DateTimeImmutable(
                    $expiresAt,
                    new DateTimeZone(APP_TIMEZONE),
                );
                if (
                    $expiry <=
                    new DateTimeImmutable("now", new DateTimeZone(APP_TIMEZONE))
                ) {
                    throw new RuntimeException(
                        "The alert expiry must be in the future.",
                    );
                }
                $expiresAt = $expiry->format(DATE_ATOM);
            }
            $insert = db()->prepare(
                "INSERT INTO disaster_preparedness
                    (source, alert_type, severity, affected_area, alert_message, expires_at)
                 VALUES
                    ('Admin dashboard', :alert_type, :severity, NULLIF(:affected_area, ''),
                     :alert_message, NULLIF(:expires_at, '')::timestamptz)
                 RETURNING disaster_id",
            );
            $insert->execute([
                "alert_type"    => $alertType,
                "severity"      => $severity,
                "affected_area" => $affectedArea,
                "alert_message" => $alertMessage,
                "expires_at"    => $expiresAt,
            ]);
            $alertId = (int) $insert->fetchColumn();
            staff_audit(
                "DISASTER_ALERT_CREATED",
                "disaster_preparedness",
                $alertId,
                [
                    "alert_type" => $alertType,
                    "severity"   => $severity,
                ],
            );
            $actionMessage = "Disaster alert published to the admin dashboard.";
        } elseif ($action === "send_disaster_alert") {
            $alertId = filter_var(
                $_POST["disaster_id"] ?? "",
                FILTER_VALIDATE_INT,
            );
            if (!$alertId) {
                throw new RuntimeException(
                    "Select a valid disaster alert to send.",
                );
            }
            $alertQuery = db()->prepare(
                'SELECT alert_type, severity, affected_area, alert_message
                 FROM disaster_preparedness
                 WHERE disaster_id = :disaster_id
                   AND (expires_at IS NULL OR expires_at >= CURRENT_TIMESTAMP)',
            );
            $alertQuery->execute(["disaster_id" => $alertId]);
            $alert = $alertQuery->fetch();
            if (!$alert) {
                throw new RuntimeException(
                    "This alert has expired or no longer exists.",
                );
            }
            $delivery = send_disaster_alert_email((int) $alertId, $alert);
            staff_audit(
                "DISASTER_ALERT_EMAIL_SENT",
                "disaster_preparedness",
                (int) $alertId,
                [
                    "sent_count"   => $delivery["sent_count"],
                    "failed_count" => $delivery["failed_count"],
                ],
            );
            $actionMessage = $delivery["recipient_count"] === 0
                ? "No active residents have an email address on file; the alert was not emailed."
                : sprintf(
                    "Alert email delivery finished: %d sent, %d failed.",
                    $delivery["sent_count"],
                    $delivery["failed_count"],
                );
            if ($delivery["failed_count"] > 0) {
                $actionWarning =
                    $actionMessage .
                    " Review the delivery log and email configuration.";
            }
        } elseif ($action === "resolve_disaster_alert") {
            $alertId = filter_var(
                $_POST["disaster_id"] ?? "",
                FILTER_VALIDATE_INT,
            );
            if (!$alertId) {
                throw new RuntimeException(
                    "Select a valid disaster alert to resolve.",
                );
            }
            $resolve = db()->prepare(
                'UPDATE disaster_preparedness
                 SET expires_at = CURRENT_TIMESTAMP
                 WHERE disaster_id = :disaster_id
                   AND (expires_at IS NULL OR expires_at >= CURRENT_TIMESTAMP)',
            );
            $resolve->execute(["disaster_id" => $alertId]);
            if ($resolve->rowCount() !== 1) {
                throw new RuntimeException(
                    "This alert is already expired or no longer exists.",
                );
            }
            staff_audit(
                "DISASTER_ALERT_RESOLVED",
                "disaster_preparedness",
                (int) $alertId,
            );
            $actionMessage = "Disaster alert marked as resolved.";
        } else {
            throw new RuntimeException("Invalid analytics action.");
        }
    } catch (Throwable $exception) {
        error_log("Admin analytics action error: " . $exception->getMessage());
        if ($action === "predict") {
            $aiError = $exception->getMessage();
        } else {
            $actionError = $exception->getMessage();
        }
    }
}

/* ---- Analytics data --------------------------------------- */
$analytics = admin_analytics_data();
$overview  = $analytics["overview"];

/* ---- Weather ---------------------------------------------- */
$snapshot        = latest_weather_snapshot();
$latestWeather   = $snapshot["weather"] ?? null;
$currentTemp     = null;
$currentCondition = "N/A";
$currentHumidity = null;
$currentWind     = null;
if (is_array($latestWeather) && isset($latestWeather["current"])) {
    $currentTemp      = $latestWeather["current"]["temperature"] ?? null;
    $currentCondition = $latestWeather["summary"] ?? "Unknown";
    $currentHumidity  = $latestWeather["current"]["humidity"] ?? null;
    $currentWind      = $latestWeather["current"]["wind_speed"] ?? null;
}
$weatherIconClass = "";
if (is_array($latestWeather) && isset($latestWeather["current"]["weather_code"])) {
    $weatherIconClass = weather_icon_for((int) $latestWeather["current"]["weather_code"]);
}

/* ---- Quick insights --------------------------------------- */
$insights = [];
$resChange = $analytics["kpi_changes"]["residents"]["percent"] ?? null;
if ($resChange !== null && $resChange != 0) {
    $dir = $resChange > 0 ? "increased" : "decreased";
    $insights[] = [
        "type" => $resChange >= 0 ? "positive" : "warning",
        "text" => "Total residents {$dir} by " . abs((float)$resChange) . "% compared to last month.",
    ];
}
$docChange = $analytics["kpi_changes"]["documents"]["percent"] ?? null;
if ($docChange !== null && $docChange != 0) {
    $insights[] = [
        "type" => "info",
        "text" => "Document requests " . ($docChange > 0 ? "up" : "down") . " by " . abs((float)$docChange) . "% this month.",
    ];
}
if ($overview["active_disaster_alerts"] == 0) {
    $insights[] = ["type" => "positive", "text" => "No critical disaster alerts at the moment."];
} else {
    $insights[] = [
        "type" => "warning",
        "text" => number_format($overview["active_disaster_alerts"]) . " active disaster alert(s) require attention.",
    ];
}
if ($overview["open_blotter"] > 0) {
    $insights[] = [
        "type" => "info",
        "text" => number_format($overview["open_blotter"]) . " blotter case(s) are currently open.",
    ];
}
if ($overview["failed_notifications"] > 0) {
    $insights[] = [
        "type" => "warning",
        "text" => number_format($overview["failed_notifications"]) . " failed email notification(s) recorded.",
    ];
}
if (count($insights) === 0) {
    $insights[] = ["type" => "info", "text" => "All systems are operating normally."];
}

/* ---- Insight type → icon map ------------------------------ */
$insightIconMap = [
    "positive" => "bi-check-circle-fill",
    "warning"  => "bi-exclamation-triangle-fill",
    "info"     => "bi-info-circle-fill",
];

/* ---- KPI change badge helper ------------------------------ */
$kpiChangeBadge = static function (string $metric) use ($analytics): string {
    $change = $analytics["kpi_changes"][$metric] ?? null;
    if ($change === null) {
        return '<span class="adash-kpi-badge is-neutral"><i class="bi bi-dash"></i> —</span>';
    }
    $pct = $change["percent"];
    if ($pct === null) {
        $cur = $change["current"] ?? 0;
        if ($cur > 0) {
            return '<span class="adash-kpi-badge is-new"><i class="bi bi-stars"></i> New</span>';
        }
        return '<span class="adash-kpi-badge is-neutral"><i class="bi bi-dash"></i> —</span>';
    }
    if ($pct > 0) {
        return '<span class="adash-kpi-badge is-up"><i class="bi bi-arrow-up-right"></i> +' . number_format((float)$pct, 1) . '%</span>';
    }
    if ($pct < 0) {
        return '<span class="adash-kpi-badge is-down"><i class="bi bi-arrow-down-right"></i> ' . number_format((float)$pct, 1) . '%</span>';
    }
    return '<span class="adash-kpi-badge is-neutral"><i class="bi bi-dash"></i> 0%</span>';
};

/* ---- Revenue ---------------------------------------------- */
$revenue = $analytics["revenue"];

/* ---- Delivery totals -------------------------------------- */
$deliveryTotals = ["Sent" => 0, "Delivered" => 0, "Failed" => 0, "Queued" => 0];
foreach ($analytics["notification_status"] as $delivery) {
    $deliveryTotals[$delivery["status"]] =
        ($deliveryTotals[$delivery["status"]] ?? 0) + $delivery["count"];
}
$successfulDeliveries = $deliveryTotals["Sent"] + $deliveryTotals["Delivered"];
$totalDeliveries      = array_sum($deliveryTotals);

/* ---- Active alerts list ----------------------------------- */
$activeAlertsList = $analytics["active_disaster_alerts"] ?? [];

/* ---- Dashboard JSON for JS -------------------------------- */
$dashboardJson = json_encode([
    "monthly_activity"      => $analytics["monthly_activity"],
    "document_statuses"     => $analytics["document_statuses"],
    "blotter_status"        => $analytics["blotter_status"],
    "notification_status"   => $analytics["notification_status"],
    "resident_age_groups"   => $analytics["resident_age_groups"],
    "household_size_groups" => $analytics["household_size_groups"],
    "households_by_purok"   => $analytics["households_by_purok"],
    "staff_worktime"        => $analytics["staff_worktime"],
    "weather_history"       => $analytics["weather_history"],
    "revenue"               => $analytics["revenue"],
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);

/* ---- Page setup ------------------------------------------- */
$pageTitle           = "Admin analytics dashboard";
$additionalStylesheets = ["assets/css/admin-analytics-v2.css"];
$additionalScripts   = [
    "https://cdn.jsdelivr.net/npm/chart.js@4.4.7/dist/chart.umd.min.js",
    "assets/js/admin-analytics.js",
];
$hideSiteFooter      = true;

/* ---- Admin user display name ------------------------------ */
$adminName    = $_SESSION["staff_name"]  ?? $_SESSION["username"] ?? "Admin";
$adminInitial = strtoupper(substr($adminName, 0, 1));

require __DIR__ . "/includes/header.php";
?>
<!-- Hidden JSON data for JS charts -->
<script type="application/json" id="adashData"><?= $dashboardJson ?></script>

<div class="adash-layout">
  <!-- ======================================================
       SIDEBAR
       ====================================================== -->
  <aside class="adash-sidebar" id="adashSidebar" aria-label="Admin navigation">
    <a class="adash-brand" href="admin_analytics.php">
      <img src="assets/images/logo.png" alt="Barangay Inosluban logo" width="44" height="44">
      <div class="adash-brand-text">
        <strong>BARANGAY INOSLUBAN</strong>
        <small>LIPA CITY, BATANGAS</small>
        <small>A United Community for a Progressive Tomorrow</small>
      </div>
    </a>

    <nav class="adash-nav" aria-label="Main navigation">
      <a class="adash-nav-item is-active" href="admin_analytics.php">
        <i class="bi bi-house-fill"></i> Dashboard
      </a>
      <a class="adash-nav-item" href="resident_search.php">
        <i class="bi bi-people-fill"></i> Residents
      </a>
      <a class="adash-nav-item" href="staff_requests.php?type=documents">
        <i class="bi bi-file-earmark-text-fill"></i> Requests
      </a>
      <a class="adash-nav-item" href="staff_requests.php?type=blotter">
        <i class="bi bi-shield-fill"></i> Blotter
      </a>
      <a class="adash-nav-item" href="staff_requests.php?type=appointments">
        <i class="bi bi-calendar-check-fill"></i> Appointments
      </a>
      <a class="adash-nav-item" href="admin_announcements.php">
        <i class="bi bi-megaphone-fill"></i> Announcements
      </a>
      <a class="adash-nav-item" href="admin_audit.php">
        <i class="bi bi-bar-chart-fill"></i> Reports
      </a>
      <a class="adash-nav-item" href="admin_staff.php">
        <i class="bi bi-gear-fill"></i> Settings
      </a>
    </nav>

    <div class="adash-sidebar-footer">
      <i class="bi bi-shield-check"></i>
      <span>A safe and connected community starts here.</span>
    </div>
  </aside>
  <!-- Overlay (mobile) -->
  <div class="adash-overlay" id="adashOverlay" role="presentation"></div>

  <!-- ======================================================
       MAIN CONTENT
       ====================================================== -->
  <div class="adash-main">

    <!-- ---- Top Header ---- -->
    <header class="adash-header">
      <button class="adash-sidebar-toggle" id="adashSidebarToggle" aria-label="Toggle sidebar" aria-expanded="false" aria-controls="adashSidebar">
        <i class="bi bi-list"></i>
      </button>

      <div class="adash-header-brand">
        <div class="adash-header-icon">
          <i class="bi bi-bar-chart-line-fill"></i>
        </div>
        <div class="adash-header-title">
          <h1>Admin Analytics Dashboard</h1>
          <p>Barangay Inosluban Management Portal</p>
        </div>
      </div>

      <div class="adash-header-actions">
        <!-- Date range (UI only — backend filtering TBD) -->
        <div class="adash-date-range" title="Date range filter (UI preview — backend filtering coming soon)">
          <label>From</label>
          <input type="date" id="adashDateFrom" aria-label="Start date">
          <label>To</label>
          <input type="date" id="adashDateTo" aria-label="End date">
        </div>

        <!-- Export -->
        <button class="adash-btn-export" onclick="window.print()" type="button">
          <i class="bi bi-download"></i> Export Report
        </button>

        <!-- Admin avatar (Bootstrap dropdown) -->
        <div class="dropdown adash-avatar-wrap">
          <button class="adash-avatar" data-bs-toggle="dropdown" aria-expanded="false" aria-label="Admin menu">
            <?= e($adminInitial) ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end shadow-sm">
            <li><span class="dropdown-item-text fw-semibold"><?= e($adminName) ?></span></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="admin_staff.php"><i class="bi bi-gear me-2"></i>Settings</a></li>
            <li><a class="dropdown-item" href="admin_audit.php"><i class="bi bi-clock-history me-2"></i>Audit Log</a></li>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item text-danger" href="logout.php"><i class="bi bi-box-arrow-right me-2"></i>Sign out</a></li>
          </ul>
        </div>
      </div>
    </header>

    <!-- ---- Page body ---- -->
    <div class="adash-body">

      <?php if ($actionMessage !== "" || $actionWarning !== ""): ?>
      <div class="adash-alert <?= $actionWarning !== "" ? "adash-alert-warning" : "adash-alert-success" ?>">
        <i class="bi <?= $actionWarning !== "" ? "bi-exclamation-triangle-fill" : "bi-check-circle-fill" ?>"></i>
        <?= e($actionWarning !== "" ? $actionWarning : $actionMessage) ?>
      </div>
      <?php endif; ?>

      <?php if ($actionError !== ""): ?>
      <div class="adash-alert adash-alert-danger">
        <i class="bi bi-x-circle-fill"></i><?= e($actionError) ?>
      </div>
      <?php endif; ?>

      <!-- KPI note -->
      <p class="adash-kpi-note">
        <i class="bi bi-info-circle"></i>
        KPI changes compare this month to the same elapsed period last month.
        Updated <?= e((new DateTimeImmutable($analytics["generated_at"]))->format("M j, Y · g:i A")) ?>.
      </p>

      <!-- ======================================================
           ROW 1: 8 KPI CARDS
           ====================================================== -->
      <section class="adash-kpi-grid" aria-label="Barangay operations summary">

        <!-- 1. Total Residents -->
        <article class="adash-kpi-card">
          <div class="adash-kpi-icon-wrap adash-kpi-icon-pink">
            <i class="bi bi-people-fill"></i>
          </div>
          <div class="adash-kpi-body">
            <div class="adash-kpi-label">Total Residents</div>
            <div class="adash-kpi-value"><?= number_format($overview["residents"]) ?></div>
            <div class="adash-kpi-sub">registered records</div>
            <?= $kpiChangeBadge("residents") ?>
          </div>
        </article>

        <!-- 2. Households -->
        <article class="adash-kpi-card">
          <div class="adash-kpi-icon-wrap adash-kpi-icon-pink">
            <i class="bi bi-houses-fill"></i>
          </div>
          <div class="adash-kpi-body">
            <div class="adash-kpi-label">Households</div>
            <div class="adash-kpi-value"><?= number_format($overview["households"]) ?></div>
            <div class="adash-kpi-sub">household records</div>
            <?= $kpiChangeBadge("households") ?>
          </div>
        </article>

        <!-- 3. Pending Document Requests -->
        <article class="adash-kpi-card">
          <div class="adash-kpi-icon-wrap adash-kpi-icon-maroon">
            <i class="bi bi-file-earmark-text-fill"></i>
          </div>
          <div class="adash-kpi-body">
            <div class="adash-kpi-label">Pending Documents</div>
            <div class="adash-kpi-value"><?= number_format($overview["pending_documents"]) ?></div>
            <div class="adash-kpi-sub"><?= number_format($overview["document_requests"]) ?> total requests</div>
            <?= $kpiChangeBadge("documents") ?>
          </div>
        </article>

        <!-- 4. Upcoming Appointments -->
        <article class="adash-kpi-card">
          <div class="adash-kpi-icon-wrap adash-kpi-icon-green">
            <i class="bi bi-calendar-check-fill"></i>
          </div>
          <div class="adash-kpi-body">
            <div class="adash-kpi-label">Upcoming Appointments</div>
            <div class="adash-kpi-value"><?= number_format($overview["upcoming_appointments"]) ?></div>
            <div class="adash-kpi-sub">pending or approved</div>
            <?= $kpiChangeBadge("appointments") ?>
          </div>
        </article>

        <!-- 5. Open Blotter Cases -->
        <article class="adash-kpi-card">
          <div class="adash-kpi-icon-wrap adash-kpi-icon-orange">
            <i class="bi bi-shield-exclamation-fill"></i>
          </div>
          <div class="adash-kpi-body">
            <div class="adash-kpi-label">Open Blotter Cases</div>
            <div class="adash-kpi-value"><?= number_format($overview["open_blotter"]) ?></div>
            <div class="adash-kpi-sub">awaiting resolution</div>
            <?= $kpiChangeBadge("blotter") ?>
          </div>
        </article>

        <!-- 6. Active Staff -->
        <article class="adash-kpi-card">
          <div class="adash-kpi-icon-wrap adash-kpi-icon-blue">
            <i class="bi bi-person-workspace"></i>
          </div>
          <div class="adash-kpi-body">
            <div class="adash-kpi-label">Active Staff</div>
            <div class="adash-kpi-value"><?= number_format($overview["active_staff"]) ?></div>
            <div class="adash-kpi-sub"><?= number_format($overview["published_announcements"]) ?> announcements</div>
            <?= $kpiChangeBadge("staff") ?>
          </div>
        </article>

        <!-- 7. Active Disaster Alerts -->
        <article class="adash-kpi-card">
          <div class="adash-kpi-icon-wrap adash-kpi-icon-amber">
            <i class="bi bi-broadcast-pin"></i>
          </div>
          <div class="adash-kpi-body">
            <div class="adash-kpi-label">Active Disaster Alerts</div>
            <div class="adash-kpi-value"><?= number_format($overview["active_disaster_alerts"]) ?></div>
            <div class="adash-kpi-sub">currently active</div>
            <?= $kpiChangeBadge("alerts") ?>
          </div>
        </article>

        <!-- 8. Failed Email Deliveries -->
        <article class="adash-kpi-card">
          <div class="adash-kpi-icon-wrap adash-kpi-icon-red">
            <i class="bi bi-envelope-x-fill"></i>
          </div>
          <div class="adash-kpi-body">
            <div class="adash-kpi-label">Failed Email Deliveries</div>
            <div class="adash-kpi-value"><?= number_format($overview["failed_notifications"]) ?></div>
            <div class="adash-kpi-sub">notification failures</div>
          </div>
        </article>

      </section>

      <!-- ======================================================
           ROW 2: Residents by Purok + Age Groups | Household Sizes
           ====================================================== -->
      <p class="adash-section-label">Population &amp; Household Breakdown</p>
      <div class="adash-row-2col">

        <!-- Purok + Age split -->
        <div class="adash-card">
          <div class="adash-card-header">
            <div>
              <div class="adash-card-title">Residents by Purok and Age Group</div>
              <div class="adash-card-sub">Household distribution and demographic breakdown</div>
            </div>
          </div>
          <div class="adash-chart-split">
            <div>
              <div style="font-size:.72rem;font-weight:600;color:var(--adash-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em;">By Purok</div>
              <div class="adash-chart-wrap adash-chart-wrap-md">
                <canvas id="purokBarChart" aria-label="Households and members by purok" role="img"></canvas>
              </div>
            </div>
            <div>
              <div style="font-size:.72rem;font-weight:600;color:var(--adash-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em;">Age Groups</div>
              <div class="adash-chart-wrap adash-chart-wrap-md">
                <canvas id="ageGroupDonut" aria-label="Resident age group distribution" role="img"></canvas>
              </div>
            </div>
          </div>
        </div>

        <!-- Household sizes -->
        <div class="adash-card">
          <div class="adash-card-header">
            <div>
              <div class="adash-card-title">Household Sizes</div>
              <div class="adash-card-sub">Distribution by member count</div>
            </div>
            <a class="adash-card-link" href="staff_households.php">Registry <i class="bi bi-arrow-up-right"></i></a>
          </div>
          <!-- Summary stats -->
          <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:14px;">
            <div style="text-align:center;min-width:60px;">
              <div style="font-size:1.3rem;font-weight:800;color:var(--adash-primary);"><?= number_format($analytics["household_summary"]["households"] ?? 0) ?></div>
              <div style="font-size:.68rem;color:var(--adash-muted);">Households</div>
            </div>
            <div style="text-align:center;min-width:60px;">
              <div style="font-size:1.3rem;font-weight:800;color:var(--adash-primary);"><?= number_format($analytics["household_summary"]["members"] ?? 0) ?></div>
              <div style="font-size:.68rem;color:var(--adash-muted);">Members</div>
            </div>
            <div style="text-align:center;min-width:60px;">
              <div style="font-size:1.3rem;font-weight:800;color:var(--adash-primary);"><?= number_format($analytics["household_summary"]["average_size"] ?? 0, 1) ?></div>
              <div style="font-size:.68rem;color:var(--adash-muted);">Avg Size</div>
            </div>
          </div>
          <div class="adash-chart-wrap adash-chart-wrap-md">
            <canvas id="householdSizeChart" aria-label="Household size distribution" role="img"></canvas>
          </div>
        </div>

      </div>

      <!-- ======================================================
           ROW 3: Monthly Trends (full width)
           ====================================================== -->
      <p class="adash-section-label">Service Activity</p>
      <div class="adash-row-full">
        <div class="adash-card">
          <div class="adash-card-header">
            <div>
              <div class="adash-card-title">Monthly Trends</div>
              <div class="adash-card-sub">Documents, appointments, and blotter incidents over the last 12 months</div>
            </div>
          </div>
          <div class="adash-chart-wrap adash-chart-wrap-lg">
            <canvas id="monthlyTrendsChart" aria-label="Monthly service activity trends" role="img"></canvas>
          </div>
        </div>
      </div>

      <!-- ======================================================
           ROW 4: Status Breakdowns + Staff Hours + Payments + Notifications
           ====================================================== -->
      <p class="adash-section-label">Workload &amp; Communications</p>
      <div class="adash-row-4col">

        <!-- Doc + Blotter status donuts side-by-side in one card (col 1-2) -->
        <div class="adash-card" style="grid-column: span 2;">
          <div class="adash-card-header">
            <div>
              <div class="adash-card-title">Request &amp; Case Status Breakdown</div>
              <div class="adash-card-sub">Document requests and blotter cases by status</div>
            </div>
          </div>
          <div class="adash-chart-split">
            <div>
              <div style="font-size:.72rem;font-weight:600;color:var(--adash-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em;">Document Requests</div>
              <div class="adash-chart-wrap adash-chart-wrap-md">
                <canvas id="docStatusDonut" aria-label="Document request status breakdown" role="img"></canvas>
              </div>
            </div>
            <div>
              <div style="font-size:.72rem;font-weight:600;color:var(--adash-muted);margin-bottom:6px;text-transform:uppercase;letter-spacing:.04em;">Blotter Cases</div>
              <div class="adash-chart-wrap adash-chart-wrap-md">
                <canvas id="blotterStatusDonut" aria-label="Blotter case status breakdown" role="img"></canvas>
              </div>
            </div>
          </div>
        </div>

        <!-- Staff Hours -->
        <div class="adash-card">
          <div class="adash-card-header">
            <div>
              <div class="adash-card-title">Staff Hours</div>
              <div class="adash-card-sub">By position · month to date</div>
            </div>
            <a class="adash-card-link" href="admin_audit.php">Logs <i class="bi bi-arrow-up-right"></i></a>
          </div>
          <?php if ($analytics["staff_worktime"] !== []): ?>
          <div class="adash-chart-wrap adash-chart-wrap-md">
            <canvas id="staffHoursChart" aria-label="Staff hours by position" role="img"></canvas>
          </div>
          <?php else: ?>
          <p style="color:var(--adash-muted);font-size:.82rem;">No staff time logs recorded this month.</p>
          <?php endif; ?>
        </div>

        <!-- Payments + Notification -->
        <div style="display:flex;flex-direction:column;gap:14px;">

          <!-- Payments card -->
          <div class="adash-card" style="flex:1;">
            <div class="adash-card-header">
              <div>
                <div class="adash-card-title">Payments Collected</div>
                <div class="adash-card-sub">Document fees recorded as paid</div>
              </div>
            </div>
            <div class="adash-revenue-big">₱<?= number_format($revenue["amount"] ?? 0, 2) ?></div>
            <div class="adash-revenue-label">Total collected</div>
            <div class="adash-revenue-meta">
              <span style="font-size:.75rem;color:var(--adash-muted);">
                <strong style="color:var(--adash-text);"><?= number_format($revenue["paid_this_month"] ?? 0) ?></strong> paid this month
              </span>
              <span style="font-size:.75rem;color:var(--adash-muted);">·</span>
              <span style="font-size:.75rem;color:var(--adash-muted);">
                <strong style="color:var(--adash-text);"><?= number_format($revenue["paid_requests"] ?? 0) ?></strong> total paid requests
              </span>
            </div>
            <div class="adash-chart-wrap adash-chart-wrap-sm" style="margin-top:12px;">
              <canvas id="paymentTrendChart" aria-label="Payment trend" role="img"></canvas>
            </div>
          </div>

          <!-- Notification donut card -->
          <div class="adash-card" style="flex:1;">
            <div class="adash-card-header">
              <div>
                <div class="adash-card-title">Notification Delivery</div>
                <div class="adash-card-sub"><?= number_format($successfulDeliveries) ?> of <?= number_format($totalDeliveries) ?> delivered</div>
              </div>
            </div>
            <div class="adash-chart-wrap adash-chart-wrap-sm">
              <canvas id="notificationDonut" aria-label="Notification delivery status" role="img"></canvas>
            </div>
          </div>

        </div>
      </div>

      <!-- ======================================================
           ROW 5: Weather + Quick Insights
           ====================================================== -->
      <p class="adash-section-label">Environment &amp; Insights</p>
      <div class="adash-row-2equal">

        <!-- Weather card -->
        <div class="adash-card">
          <div class="adash-card-header">
            <div>
              <div class="adash-card-title">Recent Weather Trends</div>
              <div class="adash-card-sub">Current conditions and temperature history</div>
            </div>
            <a class="adash-card-link" href="admin_weather.php">Weather <i class="bi bi-arrow-up-right"></i></a>
          </div>

          <?php if ($currentTemp !== null): ?>
          <div class="adash-weather-current">
            <div class="adash-weather-icon-big">
              <i class="bi <?= $weatherIconClass !== "" ? e($weatherIconClass) : "bi-cloud-sun" ?>"></i>
            </div>
            <div>
              <div class="adash-weather-temp"><?= e(number_format((float)$currentTemp, 1)) ?>°C</div>
              <div class="adash-weather-cond"><?= e($currentCondition) ?></div>
              <div class="adash-weather-stats">
                <?php if ($currentHumidity !== null): ?>
                <span class="adash-weather-stat"><i class="bi bi-droplet-fill"></i> <?= e(number_format((float)$currentHumidity, 0)) ?>% humidity</span>
                <?php endif; ?>
                <?php if ($currentWind !== null): ?>
                <span class="adash-weather-stat"><i class="bi bi-wind"></i> <?= e(number_format((float)$currentWind, 1)) ?> km/h</span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php else: ?>
          <p class="adash-weather-na">No current weather snapshot available.</p>
          <?php endif; ?>

          <div class="adash-chart-wrap adash-chart-wrap-sm">
            <canvas id="weatherTrendChart" aria-label="Temperature trend chart" role="img"></canvas>
          </div>
        </div>

        <!-- Quick Insights card -->
        <div class="adash-card">
          <div class="adash-card-header">
            <div>
              <div class="adash-card-title">Quick Insights</div>
              <div class="adash-card-sub">Auto-generated from current analytics data</div>
            </div>
          </div>
          <div class="adash-insights">
            <?php foreach ($insights as $insight): ?>
            <div class="adash-insight-item type-<?= e($insight["type"]) ?>">
              <i class="bi <?= e($insightIconMap[$insight["type"]] ?? "bi-circle-fill") ?> adash-insight-icon"></i>
              <span><?= e($insight["text"]) ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

      </div>

      <!-- ======================================================
           AI PREDICTION (collapsible)
           ====================================================== -->
      <p class="adash-section-label">AI Analysis</p>
      <div class="adash-card adash-ai-panel">
        <div class="adash-ai-header">
          <div class="adash-ai-icon-wrap"><i class="bi bi-stars"></i></div>
          <div style="flex:1;min-width:0;">
            <div class="adash-card-title">AI Predictive Analysis</div>
            <div class="adash-card-sub">Uses aggregate weather and service-demand history only. No account credentials or resident identities are sent.</div>
          </div>
          <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="predict">
            <button class="adash-ai-btn" type="submit"><i class="bi bi-magic"></i> Generate Outlook</button>
          </form>
        </div>
        <?php if ($aiError !== ""): ?>
        <div class="adash-alert adash-alert-warning"><i class="bi bi-exclamation-triangle-fill"></i> <?= e($aiError) ?></div>
        <?php elseif ($aiPrediction !== ""): ?>
        <div class="adash-ai-result"><?= nl2br(e($aiPrediction)) ?></div>
        <?php else: ?>
        <p class="adash-ai-placeholder"><i class="bi bi-lightbulb"></i> Generate an AI outlook for potential document-request peak periods and weather trends. Predictions are advisory and depend on available historical data.</p>
        <?php endif; ?>
      </div>

      <!-- ======================================================
           DISASTER PREPAREDNESS (collapsible)
           ====================================================== -->
      <p class="adash-section-label">Disaster Preparedness</p>
      <div class="adash-card">
        <div class="adash-card-header">
          <div>
            <div class="adash-card-title" style="display:flex;align-items:center;gap:8px;">
              <i class="bi bi-broadcast-pin" style="color:var(--adash-primary);"></i>
              Disaster Alerts
              <span style="display:inline-flex;align-items:center;padding:2px 10px;border-radius:20px;font-size:.72rem;font-weight:700;background:var(--adash-primary-light);color:var(--adash-primary);">
                <?= number_format($overview["active_disaster_alerts"]) ?> active
              </span>
            </div>
            <div class="adash-card-sub">Active alert status and resident notification delivery</div>
          </div>
          <a class="adash-card-link" href="admin_disaster.php">Manage <i class="bi bi-arrow-up-right"></i></a>
        </div>

        <div data-adash-tabs>
          <div class="adash-tabs">
            <button class="adash-tab is-active" data-adash-tab="adashTabActive">Active Alerts</button>
            <button class="adash-tab" data-adash-tab="adashTabCreate">Create Alert</button>
          </div>

          <!-- Active alerts list -->
          <div class="adash-tab-panel is-active" id="adashTabActive">
            <?php if ($activeAlertsList !== []): ?>
            <div class="adash-alert-list">
              <?php foreach (array_slice($activeAlertsList, 0, 5) as $alert):
                $sevClass = strtolower((string) preg_replace("/[^a-z0-9]+/i", "-", $alert["severity"]));
              ?>
              <div class="adash-alert-item">
                <span class="adash-alert-severity severity-<?= e($sevClass) ?>"><?= e($alert["severity"]) ?></span>
                <div style="flex:1;min-width:0;">
                  <strong style="font-size:.82rem;"><?= e($alert["type"] . ($alert["area"] !== "" ? " · " . $alert["area"] : "")) ?></strong>
                  <p style="font-size:.78rem;color:var(--adash-muted);margin:2px 0 4px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($alert["message"]) ?></p>
                  <small style="font-size:.7rem;color:var(--adash-muted);"><?= number_format($alert["delivered"] ?? 0) ?> sent · <?= number_format($alert["failed"] ?? 0) ?> failed</small>
                </div>
                <div style="display:flex;flex-direction:column;gap:4px;flex-shrink:0;">
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="send_disaster_alert">
                    <input type="hidden" name="disaster_id" value="<?= (int)$alert["id"] ?>">
                    <button class="adash-btn-secondary" type="submit" style="font-size:.72rem;padding:4px 10px;"><i class="bi bi-send"></i> Email</button>
                  </form>
                  <form method="post" style="display:inline;">
                    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="resolve_disaster_alert">
                    <input type="hidden" name="disaster_id" value="<?= (int)$alert["id"] ?>">
                    <button class="adash-btn-danger" type="submit" style="font-size:.72rem;padding:4px 10px;"><i class="bi bi-check2-circle"></i> Resolve</button>
                  </form>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p style="color:var(--adash-muted);font-size:.82rem;padding:8px 0;">No active disaster alerts. There are no current alerts requiring action.</p>
            <?php endif; ?>
          </div>

          <!-- Create alert form -->
          <div class="adash-tab-panel" id="adashTabCreate">
            <form method="post" class="adash-disaster-form">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="create_disaster_alert">
              <div class="adash-form-row">
                <div>
                  <label for="da_type">Alert Type <span style="color:var(--adash-red);">*</span></label>
                  <input type="text" id="da_type" name="alert_type" maxlength="100" placeholder="e.g. Typhoon, Flood, Fire…" required>
                </div>
                <div>
                  <label for="da_severity">Severity <span style="color:var(--adash-red);">*</span></label>
                  <select id="da_severity" name="severity" required>
                    <option value="">— Select severity —</option>
                    <option>Information</option>
                    <option>Advisory</option>
                    <option>Watch</option>
                    <option>Warning</option>
                    <option>Emergency</option>
                  </select>
                </div>
                <div>
                  <label for="da_area">Affected Area</label>
                  <input type="text" id="da_area" name="affected_area" maxlength="255" placeholder="e.g. Purok 3, entire barangay…">
                </div>
                <div>
                  <label for="da_expires">Expires At (optional)</label>
                  <input type="datetime-local" id="da_expires" name="expires_at">
                </div>
              </div>
              <div style="margin-top:12px;">
                <label for="da_message">Alert Message <span style="color:var(--adash-red);">*</span></label>
                <textarea id="da_message" name="alert_message" maxlength="10000" placeholder="Describe the alert and instructions for residents…" required></textarea>
              </div>
              <div class="adash-form-actions">
                <button class="adash-btn-primary" type="submit"><i class="bi bi-broadcast-pin"></i> Publish Alert</button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Privacy note -->
      <div class="adash-privacy">
        <i class="bi bi-shield-lock"></i>
        Analytics use aggregate service records. Usernames, password hashes, resident names, addresses, contact details, and account credentials are not queried for this dashboard or sent for AI analysis.
      </div>

    </div><!-- /.adash-body -->
  </div><!-- /.adash-main -->
</div><!-- /.adash-layout -->
<?php require __DIR__ . "/includes/footer.php"; ?>
