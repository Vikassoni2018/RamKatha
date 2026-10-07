import { Controller } from '@hotwired/stimulus';

/* Generic disclosure: shows/hides a target and keeps aria-expanded in sync on the trigger. */
export default class extends Controller {
    static targets = ['content', 'trigger'];

    toggle() {
        const show = this.contentTarget.hidden;
        this.contentTarget.hidden = !show;
        if (this.hasTriggerTarget) {
            this.triggerTarget.setAttribute('aria-expanded', show ? 'true' : 'false');
        }
    }
}
