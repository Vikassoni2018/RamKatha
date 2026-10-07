import { Controller } from '@hotwired/stimulus';
import { track } from '../lib/analytics.js';
import { showToast } from '../lib/toast.js';

/* stimulusFetch: 'lazy' */
/* Native share sheet (when available), copy link, and share-click tracking. No third-party widgets. */
export default class extends Controller {
    static targets = ['native'];
    static values = { url: String, title: String, story: String };

    connect() {
        if (this.hasNativeTarget && navigator.share) {
            this.nativeTarget.hidden = false;
        }
    }

    async native() {
        try {
            await navigator.share({ title: this.titleValue, url: this.urlValue });
            track(this.storyValue, 'share');
        } catch (e) {
            // user cancelled
        }
    }

    async copy() {
        try {
            if (navigator.clipboard && window.isSecureContext) {
                await navigator.clipboard.writeText(this.urlValue);
            } else {
                const input = document.createElement('input');
                input.value = this.urlValue;
                document.body.appendChild(input);
                input.select();
                document.execCommand('copy');
                input.remove();
            }
            showToast('लिंक कॉपी हो गया', 'success');
            track(this.storyValue, 'share');
        } catch (e) {
            showToast('लिंक कॉपी नहीं हो सका', 'error');
        }
    }

    tracked() {
        track(this.storyValue, 'share');
    }
}
