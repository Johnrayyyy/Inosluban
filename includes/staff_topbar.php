<header class="dashboard-topbar portal-topbar">
    <?php require __DIR__ . "/portal_topbar_brand.php"; ?>
    <div class="portal-topbar-actions">
        <button class="portal-theme-toggle" type="button" data-portal-theme-toggle
            aria-label="Switch to dark mode"><i class="bi bi-moon-stars"></i></button>
        <?php if (($_SESSION["user"]["role"] ?? "") === "admin"): ?>
        <?php require __DIR__ . "/admin_account_menu.php"; ?>
        <?php else: ?>
        <?php require __DIR__ . "/staff_account_menu.php"; ?>
        <?php endif; ?>
    </div>
</header>
