<?php if (!empty($errors)): ?>
    <div class="alert alert-danger" role="alert">
        <i class="bi bi-exclamation-circle me-2"></i>
        <ul class="mb-0 ps-3">
            <?php foreach ($errors as $error): ?>
                <li><?= e($error) ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>
<?php if (!empty($_SESSION['success'])): ?>
    <div class="alert alert-success" role="alert">
        <i class="bi bi-check-circle me-2"></i><?= e((string) $_SESSION['success']) ?>
    </div>
    <?php unset($_SESSION['success']); ?>
<?php endif; ?>
