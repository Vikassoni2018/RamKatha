import { Controller } from '@hotwired/stimulus';
import { apiFetch, loginUrl } from '../lib/http.js';
import { showToast } from '../lib/toast.js';

/* stimulusFetch: 'lazy' */
/* Bookmark toggle without page reload. Sends the desired state, so retries are idempotent. */
export default class extends Controller {
    static targets = ['label', 'iconOn', 'iconOff'];
    static values = { url: String, bookmarked: Boolean, authenticated: Boolean };

    async toggle() {
        if (!this.authenticatedValue) {
            window.location.href = loginUrl();
            return;
        }
        const next = !this.bookmarkedValue;
        this.render(next);
        this.element.disabled = true;
        try {
            const data = await apiFetch(this.urlValue, { method: 'POST', body: { bookmarked: next } });
            this.bookmarkedValue = data.bookmarked;
            this.render(data.bookmarked);
            showToast(data.bookmarked ? 'बुकमार्क में सहेजा गया' : 'बुकमार्क से हटाया गया', 'success');
        } catch (e) {
            this.render(this.bookmarkedValue);
            if (e.status === 401) {
                window.location.href = loginUrl();
                return;
            }
            showToast(e.message, 'error');
        } finally {
            this.element.disabled = false;
        }
    }

    render(state) {
        this.element.setAttribute('aria-pressed', state ? 'true' : 'false');
        if (this.hasLabelTarget) {
            this.labelTarget.textContent = state ? 'सहेजा गया' : 'सहेजें';
        }
        if (this.hasIconOnTarget) {
            this.iconOnTarget.hidden = !state;
            this.iconOffTarget.hidden = state;
        }
    }
}
