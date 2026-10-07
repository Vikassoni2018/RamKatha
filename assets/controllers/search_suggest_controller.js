import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
/* Accessible combobox suggestions for the search box (debounced, keyboard navigable). */
export default class extends Controller {
    static targets = ['input', 'list'];
    static values = { url: String };

    connect() {
        this.active = -1;
        this.timer = null;
        this.controller = null;
        this.inputTarget.setAttribute('role', 'combobox');
        this.inputTarget.setAttribute('aria-autocomplete', 'list');
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.inputTarget.setAttribute('aria-controls', this.listTarget.id);
        this.onDocClick = (e) => {
            if (!this.element.contains(e.target)) {
                this.hide();
            }
        };
        document.addEventListener('click', this.onDocClick);
    }

    disconnect() {
        document.removeEventListener('click', this.onDocClick);
    }

    input() {
        window.clearTimeout(this.timer);
        const q = this.inputTarget.value.trim();
        if (q.length < 2) {
            this.hide();
            return;
        }
        this.timer = window.setTimeout(() => this.fetch(q), 250);
    }

    async fetch(q) {
        if (this.controller) {
            this.controller.abort();
        }
        this.controller = new AbortController();
        try {
            const response = await fetch(this.urlValue + '?q=' + encodeURIComponent(q), { signal: this.controller.signal, headers: { Accept: 'application/json' } });
            const data = await response.json();
            this.render(data.results || []);
        } catch (e) {
            // aborted or network error: keep quiet
        }
    }

    render(results) {
        this.listTarget.replaceChildren();
        this.active = -1;
        if (results.length === 0) {
            this.hide();
            return;
        }
        results.forEach((item, index) => {
            const li = document.createElement('li');
            li.setAttribute('role', 'presentation');
            const a = document.createElement('a');
            a.href = item.url;
            a.id = this.listTarget.id + '-' + index;
            a.setAttribute('role', 'option');
            a.textContent = item.title; // never innerHTML with remote data
            li.appendChild(a);
            this.listTarget.appendChild(li);
        });
        this.listTarget.hidden = false;
        this.inputTarget.setAttribute('aria-expanded', 'true');
    }

    keydown(event) {
        const options = this.listTarget.querySelectorAll('[role="option"]');
        if (this.listTarget.hidden || options.length === 0) {
            return;
        }
        if (event.key === 'ArrowDown') {
            event.preventDefault();
            this.move(options, 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            this.move(options, -1);
        } else if (event.key === 'Enter' && this.active >= 0) {
            event.preventDefault();
            options[this.active].click();
        } else if (event.key === 'Escape') {
            this.hide();
        }
    }

    move(options, delta) {
        this.active = (this.active + delta + options.length) % options.length;
        options.forEach((o, i) => o.setAttribute('aria-selected', i === this.active ? 'true' : 'false'));
        this.inputTarget.setAttribute('aria-activedescendant', options[this.active].id);
    }

    hide() {
        this.listTarget.hidden = true;
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.inputTarget.removeAttribute('aria-activedescendant');
    }
}
