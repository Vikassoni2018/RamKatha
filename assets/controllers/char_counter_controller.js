import { Controller } from '@hotwired/stimulus';

/* Live character counter for SEO / social fields: <div data-controller="char-counter" data-char-counter-max-value="160">. */
export default class extends Controller {
    static values = { max: Number };

    connect() {
        this.field = this.element.querySelector('input, textarea');
        const label = this.element.querySelector('label');
        if (!this.field || !label) {
            return;
        }
        this.counter = document.createElement('span');
        this.counter.className = 'char-counter';
        this.counter.setAttribute('aria-live', 'polite');
        label.appendChild(this.counter);
        this.onInput = () => this.update();
        this.field.addEventListener('input', this.onInput);
        this.update();
    }

    disconnect() {
        if (this.field) {
            this.field.removeEventListener('input', this.onInput);
        }
    }

    update() {
        const length = [...this.field.value].length;
        this.counter.textContent = length + ' / ' + this.maxValue;
        this.counter.classList.toggle('is-over', length > this.maxValue);
    }
}
