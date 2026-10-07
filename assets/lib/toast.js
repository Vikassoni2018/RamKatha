/** Accessible toast notifications (aria-live region rendered in the base layout). */
export function showToast(message, type = 'info', timeout = 4000) {
    let region = document.getElementById('toast-region');
    if (!region) {
        region = document.createElement('div');
        region.id = 'toast-region';
        region.className = 'toast-region';
        region.setAttribute('aria-live', 'polite');
        document.body.appendChild(region);
    }
    const toast = document.createElement('div');
    toast.className = 'toast' + (type === 'error' ? ' is-error' : '');
    toast.setAttribute('role', type === 'error' ? 'alert' : 'status');
    toast.textContent = message;
    region.appendChild(toast);
    window.setTimeout(() => toast.remove(), timeout);
}
