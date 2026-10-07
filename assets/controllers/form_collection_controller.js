import { Controller } from '@hotwired/stimulus';

/* Add/remove rows of a Symfony CollectionType using its prototype (e.g. source references). */
export default class extends Controller {
    static targets = ['list', 'template'];
    static values = { index: Number, prototypeName: { type: String, default: '__name__' } };

    add(event) {
        event.preventDefault();
        const html = this.templateTarget.innerHTML.replaceAll(this.prototypeNameValue, String(this.indexValue));
        this.indexValue++;
        const wrapper = document.createElement('div');
        wrapper.innerHTML = html.trim();
        const entry = wrapper.firstElementChild;
        this.listTarget.appendChild(entry);
        const firstField = entry.querySelector('input, select, textarea');
        if (firstField) {
            firstField.focus();
        }
    }

    remove(event) {
        event.preventDefault();
        const entry = event.currentTarget.closest('[data-collection-entry]');
        if (entry) {
            entry.remove();
        }
    }
}
