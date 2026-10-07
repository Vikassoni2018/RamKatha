import { Controller } from '@hotwired/stimulus';
import { apiFetch } from '../lib/http.js';
import { showToast } from '../lib/toast.js';

/* stimulusFetch: 'lazy' */
/*
 * Reorders collection items by drag & drop (pointer) or with the ↑/↓ buttons (keyboard and touch
 * friendly). The new order is saved in one transactional request.
 */
export default class extends Controller {
    static targets = ['item'];
    static values = { url: String };

    connect() {
        this.dragged = null;
    }

    dragstart(event) {
        this.dragged = event.currentTarget;
        this.dragged.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', this.dragged.dataset.uuid);
    }

    dragover(event) {
        event.preventDefault();
        const target = event.currentTarget;
        if (!this.dragged || target === this.dragged) {
            return;
        }
        this.itemTargets.forEach((i) => i.classList.remove('is-over'));
        target.classList.add('is-over');
        const rect = target.getBoundingClientRect();
        const after = event.clientY > rect.top + rect.height / 2;
        target.parentNode.insertBefore(this.dragged, after ? target.nextSibling : target);
    }

    dragend() {
        this.itemTargets.forEach((i) => i.classList.remove('is-over', 'is-dragging'));
        this.dragged = null;
        this.save();
    }

    up(event) {
        const item = event.currentTarget.closest('[data-sortable-target="item"]');
        if (item.previousElementSibling) {
            item.parentNode.insertBefore(item, item.previousElementSibling);
            event.currentTarget.focus();
            this.save();
        }
    }

    down(event) {
        const item = event.currentTarget.closest('[data-sortable-target="item"]');
        if (item.nextElementSibling) {
            item.parentNode.insertBefore(item.nextElementSibling, item);
            event.currentTarget.focus();
            this.save();
        }
    }

    async save() {
        const order = this.itemTargets.map((i) => i.dataset.uuid);
        try {
            await apiFetch(this.urlValue, { method: 'POST', body: { order } });
            showToast('क्रम सहेजा गया', 'success', 2000);
        } catch (e) {
            showToast(e.message, 'error');
        }
    }
}
