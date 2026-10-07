/**
 * First-party, privacy-friendly analytics: events are queued in memory and sent in batches
 * (every 15 s or when the page is hidden). No cookies, no identifiers. "Unique" views are
 * approximated with a per-day flag in localStorage that never leaves the browser.
 */
const queue = [];
let timer = null;
const ENDPOINT = () => document.body.dataset.analyticsUrl || '/api/analytics/events';

function enabled() {
    return document.body.dataset.analytics === '1';
}

export function track(storyUuid, event) {
    if (!enabled() || !storyUuid) {
        return;
    }
    queue.push({ s: storyUuid, e: event });
    if (queue.length >= 20) {
        flushAnalytics(false);
    } else if (!timer) {
        timer = window.setTimeout(() => flushAnalytics(false), 15000);
    }
}

export function trackView(storyUuid) {
    track(storyUuid, 'story_view');
    try {
        const key = 'katha:viewed:' + new Date().toISOString().slice(0, 10);
        const seen = JSON.parse(localStorage.getItem(key) || '[]');
        if (!seen.includes(storyUuid)) {
            seen.push(storyUuid);
            localStorage.setItem(key, JSON.stringify(seen.slice(-200)));
            track(storyUuid, 'unique_view');
        }
        // Remove flags from previous days.
        for (let i = 0; i < localStorage.length; i++) {
            const k = localStorage.key(i);
            if (k && k.startsWith('katha:viewed:') && k !== key) {
                localStorage.removeItem(k);
            }
        }
    } catch (e) {
        // Storage unavailable (private mode): skip unique approximation.
    }
}

export function flushAnalytics(useBeacon) {
    if (timer) {
        window.clearTimeout(timer);
        timer = null;
    }
    if (queue.length === 0) {
        return;
    }
    const events = queue.splice(0, queue.length);
    const body = JSON.stringify({ events });
    if (useBeacon && navigator.sendBeacon) {
        navigator.sendBeacon(ENDPOINT(), new Blob([body], { type: 'application/json' }));
        return;
    }
    fetch(ENDPOINT(), { method: 'POST', body, keepalive: true, credentials: 'same-origin', headers: { 'Content-Type': 'application/json' } }).catch(() => {});
}
