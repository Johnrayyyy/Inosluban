<?php
$portalModuleIcons = [
    "admin_analytics.php" => "bi-bar-chart-line-fill",
    "admin_announcements.php" => "bi-megaphone",
    "admin_audit.php" => "bi-shield-lock",
    "admin_disaster.php" => "bi-broadcast-pin",
    "admin_staff.php" => "bi-person-plus",
    "admin_weather.php" => "bi-cloud-sun",
    "staff_dashboard.php" => "bi-house-fill",
    "staff_document_templates.php" => "bi-file-earmark-richtext",
    "staff_households.php" => "bi-houses",
    "staff_leave.php" => "bi-calendar2-heart",
    "staff_profile.php" => "bi-person-gear",
    "staff_pickup_scanner.php" => "bi-qr-code-scan",
    "staff_requests.php" => "bi-file-earmark-text",
    "staff_verify_document.php" => "bi-shield-check",
    "announcement.php" => "bi-megaphone",
    "appointment.php" => "bi-calendar3",
    "blotter.php" => "bi-shield-check",
    "blotter_status.php" => "bi-shield-check",
    "contact.php" => "bi-telephone",
    "dashboard.php" => "bi-house-fill",
    "document_request.php" => "bi-file-earmark-text",
    "document_request_status.php" => "bi-file-earmark-text",
    "profile.php" => "bi-person",
    "resident_search.php" => "bi-search",
];
$portalModule = basename($_SERVER["PHP_SELF"]);
$portalModuleIcon = $portalModuleIcons[$portalModule] ?? "bi-grid";
?>
<div class="portal-topbar-brand">
    <span class="portal-topbar-icon"><i class="bi <?= e($portalModuleIcon) ?>"></i></span>
    <span><strong><?= e($pageTitle ?? "Barangay Inosluban") ?></strong><small>Barangay
            Inosluban Management Portal</small></span>
</div>
