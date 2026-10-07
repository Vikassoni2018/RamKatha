/**
 * Small fetch wrapper for the few JSON endpoints (progress, bookmarks, collections, donations).
 * Sends the CSRF token header; the server validates it with Symfony's same-origin CSRF manager.
 */
export function csrfToken() {
    const meta = document.querySelector('meta[name="csrf-token"]');
    return meta ? meta.getAttribute('content') : '';
}

export class HttpError extends Error {
    constructor(message, status, data) {
        super(message);
        this.status = status;
        this.data = data;
    }
}

export async function apiFetch(url, { method = 'GET', body = null, signal = undefined } = {}) {
    const headers = {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': csrfToken(),
    };
    const options = { method, headers, credentials: 'same-origin', signal };
    if (body !== null) {
        headers['Content-Type'] = 'application/json';
        options.body = JSON.stringify(body);
    }

    let response;
    try {
        response = await fetch(url, options);
    } catch (e) {
        throw new HttpError('नेटवर्क त्रुटि। कृपया पुनः प्रयास करें।', 0, null);
    }

    let data = null;
    const type = response.headers.get('Content-Type') || '';
    if (type.includes('application/json')) {
        data = await response.json().catch(() => null);
    }

    if (response.status === 401) {
        throw new HttpError((data && data.message) || 'कृपया पहले लॉग इन करें।', 401, data);
    }
    if (!response.ok) {
        throw new HttpError((data && (data.message || data.error)) || 'कुछ गलत हो गया। कृपया पुनः प्रयास करें।', response.status, data);
    }

    return data;
}

/** Fire-and-forget POST that survives page unload (used for progress/analytics). */
export function beacon(url, payload) {
    const body = JSON.stringify({ ...payload, _token: csrfToken() });
    if (navigator.sendBeacon) {
        const ok = navigator.sendBeacon(url, new Blob([body], { type: 'application/json' }));
        if (ok) {
            return;
        }
    }
    fetch(url, { method: 'POST', body, keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken() } }).catch(() => {});
}

export function loginUrl() {
    const base = document.body.dataset.loginUrl || '/login';
    return base + '?_target_path=' + encodeURIComponent(window.location.pathname + window.location.search);
}
