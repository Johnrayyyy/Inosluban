document.querySelectorAll('[data-password-toggle]').forEach((button) => {
    button.addEventListener('click', () => {
        const input = document.querySelector(button.dataset.passwordToggle);
        const icon = button.querySelector('i');
        const showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        icon.classList.toggle('bi-eye', showing);
        icon.classList.toggle('bi-eye-slash', !showing);
        button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
    });
});
