<footer class="site-footer">
    <div class="container d-flex justify-content-between align-items-center gap-3">
        <span>&copy; <?= date('Y') ?> Barangay Inosluban</span>
        <span class="small">Secure resident services</span>
    </div>
</footer>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php if (function_exists('is_logged_in') && is_logged_in()): ?>
<button class="ai-chat-launcher" type="button" data-ai-chat-open aria-label="Open barangay AI assistant"><i class="bi bi-stars"></i></button>
<div class="ai-chat-backdrop" data-ai-chat-backdrop aria-hidden="true"></div>
<section class="ai-chat-widget" data-ai-chat aria-hidden="true">
    <header><span><i class="bi bi-stars"></i> Barangay Assistant</span><button type="button" data-ai-chat-close aria-label="Close chat">&times;</button></header>
    <div class="ai-chat-messages" data-ai-chat-messages><div class="ai-chat-message ai-chat-bot">Hi! Ask me anything about Barangay Inosluban or the services in this portal.</div></div>
    <form class="ai-chat-form" data-ai-chat-form><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>"><input name="message" type="text" maxlength="4000" placeholder="Type your message..." autocomplete="off" required><button type="submit" aria-label="Send message"><i class="bi bi-send-fill"></i></button></form>
</section>
<?php endif; ?>
<script src="assets/js/app.js?v=<?= hash_file('sha256', __DIR__ . '/../assets/js/app.js') ?>"></script>
</body>
</html>
