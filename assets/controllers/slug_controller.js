import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
/* Suggests a transliterated slug from the (Hindi) title until the editor types a slug manually. */
export default class extends Controller {
    static targets = ['source', 'slug', 'preview'];
    static values = { url: String, locked: Boolean };

    connect() {
        this.manual = this.lockedValue || (this.slugTarget.value !== '');
        this.slugTarget.addEventListener('input', () => { this.manual = this.slugTarget.value !== ''; this.render(); });
        this.render();
    }

    async suggest() {
        if (this.manual) {
            return;
        }
        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(async () => {
            const text = this.sourceTarget.value.trim();
            if (!text) {
                return;
            }
            try {
                const response = await fetch(this.urlValue + '?text=' + encodeURIComponent(text), { headers: { Accept: 'application/json' } });
                const data = await response.json();
                this.slugTarget.placeholder = data.slug;
                this.render(data.slug);
            } catch (e) { /* ignore */ }
        }, 300);
    }

    render(suggested = null) {
        if (this.hasPreviewTarget) {
            this.previewTarget.textContent = this.slugTarget.value || suggested || this.slugTarget.placeholder || '…';
        }
    }
}
