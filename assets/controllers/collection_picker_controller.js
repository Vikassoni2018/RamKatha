import { Controller } from '@hotwired/stimulus';
import { apiFetch, loginUrl } from '../lib/http.js';
import { showToast } from '../lib/toast.js';

/* stimulusFetch: 'lazy' */
/* "Add to collection" dialog: lists the user's collections, toggles membership, creates new ones. */
export default class extends Controller {
    static targets = ['dialog', 'list', 'status', 'name', 'form'];
    static values = { listUrl: String, createUrl: String, story: String, authenticated: Boolean };

    async open() {
        if (!this.authenticatedValue) {
            window.location.href = loginUrl();
            return;
        }
        this.dialogTarget.showModal();
        this.statusTarget.textContent = 'लोड हो रहा है…';
        try {
            const data = await apiFetch(this.listUrlValue + '?story=' + encodeURIComponent(this.storyValue));
            this.render(data.collections || []);
        } catch (e) {
            this.statusTarget.textContent = e.message;
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

    render(collections) {
        this.listTarget.replaceChildren();
        this.statusTarget.textContent = collections.length === 0 ? 'अभी आपका कोई संग्रह नहीं है। नीचे नया संग्रह बनाएँ।' : '';
        collections.forEach((c) => {
            const li = document.createElement('li');
            const button = document.createElement('button');
            button.type = 'button';
            button.setAttribute('aria-pressed', c.contains ? 'true' : 'false');
            button.dataset.url = c.itemsUrl;
            const name = document.createElement('span');
            name.textContent = c.name;
            const state = document.createElement('small');
            state.className = 'text-muted';
            state.textContent = c.contains ? '✓ जुड़ा है' : c.count + ' कथाएँ';
            button.append(name, state);
            button.addEventListener('click', () => this.toggleItem(button, state, c));
            li.appendChild(button);
            this.listTarget.appendChild(li);
        });
    }

    async toggleItem(button, state, collection) {
        const contains = button.getAttribute('aria-pressed') === 'true';
        button.disabled = true;
        try {
            const data = await apiFetch(button.dataset.url, { method: contains ? 'DELETE' : 'POST', body: { story: this.storyValue } });
            const now = !!data.contains;
            button.setAttribute('aria-pressed', now ? 'true' : 'false');
            state.textContent = now ? '✓ जुड़ा है' : data.count + ' कथाएँ';
            showToast(now ? `“${collection.name}” में जोड़ा गया` : `“${collection.name}” से हटाया गया`, 'success');
        } catch (e) {
            showToast(e.message, 'error');
        } finally {
            button.disabled = false;
        }
    }

    async create(event) {
        event.preventDefault();
        const name = this.nameTarget.value.trim();
        if (name.length < 2) {
            this.nameTarget.focus();
            showToast('कृपया संग्रह का नाम लिखें (कम से कम 2 अक्षर)।', 'error');
            return;
        }
        try {
            const data = await apiFetch(this.createUrlValue, { method: 'POST', body: { name, story: this.storyValue } });
            this.nameTarget.value = '';
            this.render(data.collections || []);
            showToast(`नया संग्रह “${name}” बना और कथा जोड़ी गई`, 'success');
        } catch (e) {
            showToast(e.message, 'error');
        }
    }
}
