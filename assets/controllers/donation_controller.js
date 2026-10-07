import { Controller } from '@hotwired/stimulus';
import { apiFetch } from '../lib/http.js';

/* stimulusFetch: 'lazy' */
/*
 * Voluntary UPI support. Creates an "initiated" contribution on the server (which returns the
 * UPI deep link + locally generated QR), then shows it. Payment happens only in the donor's own
 * UPI app; nothing here can mark a payment as successful.
 */
export default class extends Controller {
    static targets = ['custom', 'error', 'dialog', 'qr', 'upiLink', 'reference', 'amount', 'declareLink', 'submit'];
    static values = { initiateUrl: String, story: String, min: Number, max: Number };

    selectPreset() {
        if (this.hasCustomTarget) {
            this.customTarget.value = '';
        }
        this.clearError();
    }

    customInput() {
        this.element.querySelectorAll('input[name="amount_preset"]').forEach((r) => { r.checked = false; });
        this.clearError();
    }

    amountValue() {
        const custom = this.hasCustomTarget ? this.customTarget.value.trim() : '';
        if (custom !== '') {
            return custom;
        }
        const checked = this.element.querySelector('input[name="amount_preset"]:checked');
        return checked ? checked.value : '';
    }

    async start(event) {
        event.preventDefault();
        const amount = this.amountValue();
        const numeric = Number(amount.replace(/,/g, ''));
        if (!amount || !Number.isFinite(numeric) || numeric < this.minValue || numeric > this.maxValue) {
            this.showError(`कृपया ₹${this.minValue} से ₹${this.maxValue} के बीच राशि चुनें या लिखें।`);
            return;
        }
        this.submitTarget.disabled = true;
        try {
            const data = await apiFetch(this.initiateUrlValue, { method: 'POST', body: { amount, story: this.storyValue || null } });
            this.qrTarget.src = data.qrUrl;
            this.upiLinkTarget.href = data.upiUri;
            this.referenceTarget.textContent = data.reference;
            this.amountTarget.textContent = data.amountFormatted;
            this.declareLinkTarget.href = data.declareUrl;
            this.dialogTarget.showModal();
        } catch (e) {
            this.showError(e.message);
        } finally {
            this.submitTarget.disabled = false;
        }
    }

    close() {
        this.dialogTarget.close();
    }

    backdrop(event) {
        if (event.target === this.dialogTarget) {
            this.close();
        }
    }

    showError(message) {
        this.errorTarget.textContent = message;
        this.errorTarget.hidden = false;
    }

    clearError() {
        this.errorTarget.hidden = true;
        this.errorTarget.textContent = '';
    }
}
