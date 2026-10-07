import { Controller } from '@hotwired/stimulus';

/* Off-canvas admin sidebar on tablets/phones. */
export default class extends Controller {
    static targets = ['sidebar', 'backdrop', 'toggle'];

    connect() {
        this.onKey = (e) => { if (e.key === 'Escape') { this.close(); } };
        document.addEventListener('keydown', this.onKey);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKey);
    }

    open() {
        this.sidebarTarget.classList.add('is-open');
        this.backdropTarget.hidden = false;
        this.toggleTarget.setAttribute('aria-expanded', 'true');
        const first = this.sidebarTarget.querySelector('a');
        if (first) { first.focus(); }
    }

    close() {
        if (!this.sidebarTarget.classList.contains('is-open')) {
            return;
        }
        this.sidebarTarget.classList.remove('is-open');
        this.backdropTarget.hidden = true;
        this.toggleTarget.setAttribute('aria-expanded', 'false');
        this.toggleTarget.focus();
    }
}
