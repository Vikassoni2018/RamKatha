import { Controller } from '@hotwired/stimulus';
import { beacon } from '../lib/http.js';
import { trackView } from '../lib/analytics.js';

/*
 * Story page reading behaviour: top progress bar, a "story_view" analytics event after the page
 * has been visible for a few seconds (filters out bounces/bots), and server-side reading progress
 * for logged-in users (sent at most every 20 s when it advanced by ≥ 10 %).
 */
export default class extends Controller {
    static targets = ['bar', 'body'];
    static values = { story: String, progressUrl: String, authenticated: Boolean, preview: Boolean };

    connect() {
        this.maxPercent = 0;
        this.sentPercent = 0;
        this.lastSent = 0;
        this.onScroll = () => this.update();
        window.addEventListener('scroll', this.onScroll, { passive: true });
        this.update();
        if (!this.previewValue) {
            this.viewTimer = window.setTimeout(() => trackView(this.storyValue), 4000);
        }
        this.onHide = () => this.send(true);
        window.addEventListener('pagehide', this.onHide);
    }

    disconnect() {
        window.removeEventListener('scroll', this.onScroll);
        window.removeEventListener('pagehide', this.onHide);
        window.clearTimeout(this.viewTimer);
        this.send(true);
    }

    update() {
        const body = this.hasBodyTarget ? this.bodyTarget : this.element;
        const rect = body.getBoundingClientRect();
        const total = rect.height - window.innerHeight * 0.6;
        const read = Math.min(Math.max(-rect.top + window.innerHeight * 0.4, 0), Math.max(total, 1));
        const percent = Math.round((read / Math.max(total, 1)) * 100);
        this.maxPercent = Math.max(this.maxPercent, Math.min(100, percent));
        if (this.hasBarTarget) {
            this.barTarget.style.width = Math.min(100, Math.max(0, percent)) + '%';
        }
        if (Date.now() - this.lastSent > 20000 && this.maxPercent - this.sentPercent >= 10) {
            this.send(false);
        }
    }

    send(force) {
        if (!this.authenticatedValue || this.previewValue || !this.progressUrlValue) {
            return;
        }
        if (this.maxPercent <= this.sentPercent || (this.maxPercent < 5 && !force)) {
            return;
        }
        this.lastSent = Date.now();
        this.sentPercent = this.maxPercent;
        beacon(this.progressUrlValue, { story: this.storyValue, reading: this.maxPercent });
    }
}
