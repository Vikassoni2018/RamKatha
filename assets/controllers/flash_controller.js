import { Controller } from '@hotwired/stimulus';

/* Dismissible flash message; success/info messages fade out after a while. */
export default class extends Controller {
    static values = { autoDismiss: { type: Number, default: 0 } };

    connect() {
        if (this.autoDismissValue > 0) {
            this.timer = window.setTimeout(() => this.dismiss(), this.autoDismissValue);
        }
    }

    disconnect() {
        window.clearTimeout(this.timer);
    }

    dismiss() {
        this.element.remove();
    }
}
