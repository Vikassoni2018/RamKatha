import { Controller } from '@hotwired/stimulus';
import { csrfToken } from '../lib/http.js';

/* stimulusFetch: 'lazy' */
/*
 * Media picker for image fields: choose from the local library (searchable, paginated) or upload
 * a new image (validated server-side). Stores the media id in a hidden input.
 */
export default class extends Controller {
    static targets = ['input', 'preview', 'dialog', 'grid', 'search', 'file', 'status', 'more'];
    static values = { listUrl: String, uploadUrl: String };

    open() {
        this.page = 1;
        this.dialogTarget.showModal();
        this.load(true);
    }

    close() {
        this.dialogTarget.close();
    }

    backdrop(event) {
        if (event.target === this.dialogTarget) {
            this.close();
        }
    }

    search() {
        window.clearTimeout(this.timer);
        this.timer = window.setTimeout(() => { this.page = 1; this.load(true); }, 300);
    }

    more() {
        this.page++;
        this.load(false);
    }

    async load(reset) {
        const q = this.hasSearchTarget ? this.searchTarget.value.trim() : '';
        this.statusTarget.textContent = 'Loading…';
        try {
            const response = await fetch(this.listUrlValue + '?page=' + this.page + '&q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
            const data = await response.json();
            if (reset) {
                this.gridTarget.replaceChildren();
            }
            data.items.forEach((item) => this.gridTarget.appendChild(this.tile(item)));
            this.statusTarget.textContent = data.total === 0 ? 'No images yet — upload one.' : '';
            this.moreTarget.hidden = !data.hasMore;
        } catch (e) {
            this.statusTarget.textContent = 'Could not load the media library.';
        }
    }

    tile(item) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'media-tile';
        button.setAttribute('aria-pressed', String(this.inputTarget.value) === String(item.id) ? 'true' : 'false');
        const img = document.createElement('img');
        img.src = item.thumb;
        img.alt = item.alt || '';
        img.loading = 'lazy';
        const body = document.createElement('span');
        body.className = 'media-tile-body';
        body.textContent = item.name + ' · ' + item.width + '×' + item.height;
        button.append(img, body);
        button.addEventListener('click', () => this.select(item));
        return button;
    }

    select(item) {
        this.inputTarget.value = item.id;
        this.renderPreview(item.thumb, item.name);
        this.close();
    }

    clear() {
        this.inputTarget.value = '';
        this.renderPreview(null, null);
    }

    async upload() {
        const file = this.fileTarget.files[0];
        if (!file) {
            return;
        }
        const data = new FormData();
        data.append('file', file);
        data.append('_token', csrfToken());
        this.statusTarget.textContent = 'Uploading…';
        try {
            const response = await fetch(this.uploadUrlValue, { method: 'POST', body: data, headers: { Accept: 'application/json', 'X-CSRF-Token': csrfToken() } });
            const json = await response.json();
            if (!response.ok) {
                throw new Error(json.message || 'Upload failed');
            }
            this.fileTarget.value = '';
            this.select(json.item);
        } catch (e) {
            this.statusTarget.textContent = e.message;
        }
    }

    renderPreview(url, name) {
        this.previewTarget.replaceChildren();
        if (url) {
            const img = document.createElement('img');
            img.src = url;
            img.alt = '';
            const span = document.createElement('span');
            span.className = 'text-xs text-muted';
            span.textContent = name;
            this.previewTarget.append(img, span);
        } else {
            const ph = document.createElement('span');
            ph.className = 'placeholder';
            ph.textContent = 'No image';
            this.previewTarget.appendChild(ph);
        }
    }
}
