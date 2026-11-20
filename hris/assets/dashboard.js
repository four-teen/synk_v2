const accountMenu = document.querySelector('.hris-account-menu');

if (accountMenu) {
    const toggle = accountMenu.querySelector('summary');

    document.addEventListener('click', (event) => {
        if (accountMenu.open && !accountMenu.contains(event.target)) {
            accountMenu.open = false;
        }
    });

    accountMenu.addEventListener('focusout', (event) => {
        if (event.relatedTarget && !accountMenu.contains(event.relatedTarget)) {
            accountMenu.open = false;
        }
    });

    accountMenu.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && accountMenu.open) {
            event.preventDefault();
            accountMenu.open = false;
            toggle.focus();
        }
    });

    accountMenu.querySelectorAll('[data-avatar-fallback]').forEach((avatar) => {
        const showFallback = () => {
            const fallback = avatar.dataset.avatarFallback;
            if (avatar.getAttribute('src') !== fallback) avatar.src = fallback;
        };
        avatar.addEventListener('error', showFallback, { once: true });
        if (avatar.complete && !avatar.naturalWidth) showFallback();
    });
}

// Catch failed portraits in the initial list and after a live search replaces its rows.
document.addEventListener('error', (event) => {
    const photo = event.target;
    if (photo instanceof HTMLImageElement && photo.closest('.hris-faculty-avatar')) photo.hidden = true;
}, true);
document.querySelectorAll('.hris-faculty-avatar img').forEach((photo) => {
    if (photo.complete && !photo.naturalWidth) photo.hidden = true;
});
