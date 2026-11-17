<?php
$residentMenuName = trim((string) ($_SESSION["user"]["full_name"] ?? "Resident"));
$residentMenuParts = preg_split(
    "/\s+/",
    $residentMenuName,
    -1,
    PREG_SPLIT_NO_EMPTY,
);
$residentMenuInitial = strtoupper(
    count($residentMenuParts) > 1
        ? substr($residentMenuParts[0], 0, 1) .
            substr($residentMenuParts[count($residentMenuParts) - 1], 0, 1)
        : substr($residentMenuParts[0] ?? "R", 0, 2),
);
?>
<div class="dropdown portal-account-menu">
    <button type="button" class="portal-account-avatar" data-bs-toggle="dropdown"
        data-bs-auto-close="outside" aria-expanded="false"
        aria-label="Open resident account menu">
        <?= e($residentMenuInitial) ?>
    </button>
    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
        <li><span class="dropdown-item-text fw-semibold"><?= e(
            $residentMenuName,
        ) ?><small class="d-block text-muted">Resident Account</small></span></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="profile.php"><i
                    class="bi bi-person-gear me-2"></i>Account settings</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-danger" href="logout.php"><i
                    class="bi bi-box-arrow-right me-2"></i>Log out</a></li>
    </ul>
</div>
