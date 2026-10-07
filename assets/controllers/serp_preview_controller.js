import { Controller } from '@hotwired/stimulus';

/* Live search-result snippet preview while editing SEO fields (falls back to title/excerpt). */
export default class extends Controller {
    static targets = ['title', 'description', 'fallbackTitle', 'fallbackDescription', 'outTitle', 'outDescription'];
    static values = { suffix: String };

    connect() {
        this.update();
    }

    update() {
        const title = (this.titleTarget.value || (this.hasFallbackTitleTarget ? this.fallbackTitleTarget.value : '')) + this.suffixValue;
        const description = this.descriptionTarget.value || (this.hasFallbackDescriptionTarget ? this.fallbackDescriptionTarget.value : '');
        this.outTitleTarget.textContent = this.clip(title, 65);
        this.outDescriptionTarget.textContent = this.clip(description, 160);
    }

    clip(text, max) {
        const chars = [...text.trim()];
        return chars.length > max ? chars.slice(0, max - 1).join('') + '…' : chars.join('');
    }
}
