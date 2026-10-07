import { Controller } from '@hotwired/stimulus';

/*
 * Modal confirmation for destructive/important form submissions:
 *   <form data-controller="confirm" data-confirm-message-value="..." data-action="submit->confirm#ask">
 * Uses an accessible native <dialog>; falls back to window.confirm when unsupported.
 */
export default class extends Controller {
    static values = { message: String, title: { type: String, default: 'Please confirm' }, confirmLabel: { type: String, default: 'Confirm' }, cancelLabel: { type: String, default: 'Cancel' }, danger: Boolean };

    ask(event) {
        if (this.confirmed) {
            this.confirmed = false;
            return;
        }
        event.preventDefault();
        event.stopImmediatePropagation();
        const submitter = event.submitter || null;

        if (typeof HTMLDialogElement !== 'function') {
            if (window.confirm(this.messageValue)) {
                this.proceed(submitter);
            }
            return;
        }

        const dialog = document.createElement('dialog');
        dialog.className = 'modal';
        dialog.setAttribute('aria-labelledby', 'confirm-title');
        const header = document.createElement('div');
        header.className = 'modal-header';
        const h2 = document.createElement('h2');
        h2.id = 'confirm-title';
        h2.textContent = this.titleValue;
        header.appendChild(h2);
        const body = document.createElement('div');
        body.className = 'modal-body';
        const p = document.createElement('p');
        p.textContent = this.messageValue;
        body.appendChild(p);
        const footer = document.createElement('div');
        footer.className = 'modal-footer';
        const cancel = document.createElement('button');
        cancel.type = 'button';
        cancel.className = 'btn btn-ghost';
        cancel.textContent = this.cancelLabelValue;
        const ok = document.createElement('button');
        ok.type = 'button';
        ok.className = 'btn ' + (this.dangerValue ? 'btn-danger' : 'btn-primary');
        ok.textContent = this.confirmLabelValue;
        footer.append(cancel, ok);
        dialog.append(header, body, footer);
        document.body.appendChild(dialog);

        const cleanup = () => dialog.remove();
        cancel.addEventListener('click', () => { dialog.close(); });
        dialog.addEventListener('close', cleanup);
        dialog.addEventListener('click', (e) => { if (e.target === dialog) { dialog.close(); } });
        ok.addEventListener('click', () => {
            dialog.close();
            this.proceed(submitter);
        });
        dialog.showModal();
        ok.focus();
    }

    proceed(submitter) {
        this.confirmed = true;
        if (submitter) {
            this.element.requestSubmit(submitter);
        } else {
            this.element.requestSubmit();
        }
    }
}
