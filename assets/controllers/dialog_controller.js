import { Controller } from '@hotwired/stimulus';

/* Opens/closes a native <dialog> (focus handling and Esc are built in). Closes on backdrop click. */
export default class extends Controller {
    static targets = ['dialog'];

    open(event) {
        if (event) {
            event.preventDefault();
        }
        this.returnFocus = document.activeElement;
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
        if (this.returnFocus) {
            this.returnFocus.focus();
        }
    }

    backdrop(event) {
        if (event.target === this.dialogTarget) {
            this.close();
        }
    }
}
