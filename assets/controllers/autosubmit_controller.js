import { Controller } from '@hotwired/stimulus';

/* Submits the surrounding form when a filter/sort control changes (progressive enhancement). */
export default class extends Controller {
    submit() {
        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(() => this.element.requestSubmit(), 150);
    }
}
