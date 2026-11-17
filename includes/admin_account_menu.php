<?php
$adminMenuName = trim((string) ($_SESSION["user"]["full_name"] ?? "Admin"));
$adminMenuParts = preg_split(
    "/\s+/",
    $adminMenuName,
    -1,
    PREG_SPLIT_NO_EMPTY,
);
$adminMenuInitial = strtoupper(
    count($adminMenuParts) > 1
        ? substr($adminMenuParts[0], 0, 1) .
            substr($adminMenuParts[count($adminMenuParts) - 1], 0, 1)
        : substr($adminMenuParts[0] ?? "A", 0, 2),
);
?>
<div class="dropdown admin-account-menu">
    <button type="button" class="admin-account-avatar" data-bs-toggle="dropdown"
        data-bs-auto-close="outside" aria-expanded="false"
        aria-label="Open admin account menu">
        <?= e($adminMenuInitial) ?>
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
        <li><span class="dropdown-item-text fw-semibold"><?= e(
            $adminMenuName,
        ) ?><small class="d-block text-muted">Administrator</small></span></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="admin_staff.php"><i
                    class="bi bi-person-gear me-2"></i>Settings</a></li>
        <li><a class="dropdown-item" href="admin_audit.php"><i
                    class="bi bi-clock-history me-2"></i>Audit log</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-danger" href="logout.php"><i
                    class="bi bi-box-arrow-right me-2"></i>Log out</a></li>
    </ul>
</div>
