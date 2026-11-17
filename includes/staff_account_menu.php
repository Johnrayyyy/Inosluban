<?php
$staffMenuName = trim((string) ($_SESSION["user"]["full_name"] ?? "Staff"));
$staffMenuParts = preg_split(
    "/\s+/",
    $staffMenuName,
    -1,
    PREG_SPLIT_NO_EMPTY,
);
$staffMenuInitial = strtoupper(substr($staffMenuParts[0] ?? "S", 0, 1));
?>
<div class="dropdown portal-account-menu">
    <button type="button" class="portal-account-avatar" data-bs-toggle="dropdown"
        data-bs-auto-close="outside" aria-expanded="false" aria-label="Open account menu">
        <?= e($staffMenuInitial) ?>
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
        <li><span class="dropdown-item-text fw-semibold"><?= e(
            $staffMenuName,
        ) ?><small class="d-block text-muted"><?= e(
            current_staff_role() === "BHW" ? "BHW" : "Staff",
        ) ?></small></span></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="staff_profile.php"><i
                    class="bi bi-person-gear me-2"></i>Settings</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-danger" href="logout.php"><i
                    class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
    </ul>
</div>
