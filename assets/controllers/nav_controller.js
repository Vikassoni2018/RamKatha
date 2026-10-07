import { Controller } from '@hotwired/stimulus';

/* Mobile navigation drawer + mobile search panel (aria-expanded, Esc, focus return, focus trap). */
export default class extends Controller {
    static targets = ['drawer', 'backdrop', 'toggle', 'searchPanel', 'searchToggle', 'searchInput'];

    connect() {
        this.onKeydown = (e) => {
            if (e.key === 'Escape') {
                this.close();
                this.closeSearch();
            } else if (e.key === 'Tab' && this.isOpen()) {
                this.trapFocus(e);
            }
        };
        document.addEventListener('keydown', this.onKeydown);
        this.onBeforeCache = () => this.close();
        document.addEventListener('turbo:before-cache', this.onBeforeCache);
    }

    disconnect() {
        document.removeEventListener('keydown', this.onKeydown);
        document.removeEventListener('turbo:before-cache', this.onBeforeCache);
        document.body.classList.remove('drawer-open');
    }

    isOpen() {
        return this.hasDrawerTarget && this.drawerTarget.classList.contains('is-open');
    }

    open() {
        this.drawerTarget.classList.add('is-open');
        this.drawerTarget.removeAttribute('inert');
        this.backdropTarget.hidden = false;
        this.toggleTarget.setAttribute('aria-expanded', 'true');
        document.body.classList.add('drawer-open');
        const first = this.drawerTarget.querySelector('a, button');
        if (first) {
            first.focus();
        }
    }

    close() {
        if (!this.isOpen()) {
            return;
        }
        this.drawerTarget.classList.remove('is-open');
        this.drawerTarget.setAttribute('inert', '');
        this.backdropTarget.hidden = true;
        this.toggleTarget.setAttribute('aria-expanded', 'false');
        document.body.classList.remove('drawer-open');
        this.toggleTarget.focus();
    }

    toggleSearch() {
        const open = this.searchPanelTarget.hidden;
        this.searchPanelTarget.hidden = !open;
        this.searchToggleTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) {
            this.searchInputTarget.focus();
        }
    }

    closeSearch() {
        if (this.hasSearchPanelTarget && !this.searchPanelTarget.hidden) {
            this.searchPanelTarget.hidden = true;
            this.searchToggleTarget.setAttribute('aria-expanded', 'false');
        }
    }

    trapFocus(event) {
        const focusable = this.drawerTarget.querySelectorAll('a[href], button:not([disabled]), input, select');
        if (focusable.length === 0) {
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }
}
