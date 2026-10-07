import { Controller } from '@hotwired/stimulus';

/* stimulusFetch: 'lazy' */
/*
 * Minimal, dependency-free rich text editor for story/page bodies. It only produces the tags the
 * server accepts (p, h2, h3, h4, blockquote, strong, em, ul, ol, li, a, hr); the server-side
 * HtmlContentSanitizer remains the security boundary. Includes an HTML source view.
 */
const BLOCKS = { p: 'P', h2: 'H2', h3: 'H3', h4: 'H4', blockquote: 'BLOCKQUOTE' };

export default class extends Controller {
    static targets = ['input'];

    connect() {
        this.input = this.hasInputTarget ? this.inputTarget : this.element.querySelector('textarea');
        if (!this.input) {
            return;
        }
        this.input.hidden = true;
        this.build();
        this.content.innerHTML = this.input.value || '<p><br></p>';
        this.updateStatus();
        this.form = this.input.closest('form');
        this.onSubmit = () => this.sync();
        if (this.form) {
            this.form.addEventListener('submit', this.onSubmit, true);
        }
        document.execCommand('defaultParagraphSeparator', false, 'p');
    }

    disconnect() {
        if (this.form) {
            this.form.removeEventListener('submit', this.onSubmit, true);
        }
        if (this.wrapper) {
            this.wrapper.remove();
            this.input.hidden = false;
        }
    }

    build() {
        this.wrapper = document.createElement('div');
        this.wrapper.className = 'rte';

        const toolbar = document.createElement('div');
        toolbar.className = 'rte-toolbar';
        toolbar.setAttribute('role', 'toolbar');
        toolbar.setAttribute('aria-label', 'Formatting');
        const buttons = [
            ['P', 'Paragraph', () => this.block('p')],
            ['H2', 'Heading 2', () => this.block('h2')],
            ['H3', 'Heading 3', () => this.block('h3')],
            ['H4', 'Heading 4', () => this.block('h4')],
            null,
            ['B', 'Bold', () => this.exec('bold')],
            ['I', 'Italic', () => this.exec('italic')],
            null,
            ['• List', 'Bulleted list', () => this.exec('insertUnorderedList')],
            ['1. List', 'Numbered list', () => this.exec('insertOrderedList')],
            ['“ Verse', 'Verse / quotation (blockquote)', () => this.block('blockquote')],
            ['—', 'Divider', () => this.exec('insertHorizontalRule')],
            null,
            ['Link', 'Insert link', () => this.link()],
            ['Unlink', 'Remove link', () => this.exec('unlink')],
            ['Clear', 'Remove formatting', () => this.exec('removeFormat')],
            null,
            ['↶', 'Undo', () => this.exec('undo')],
            ['↷', 'Redo', () => this.exec('redo')],
            ['</>', 'Toggle HTML source', () => this.toggleSource()],
        ];
        buttons.forEach((def) => {
            if (def === null) {
                const sep = document.createElement('span');
                sep.className = 'sep';
                sep.setAttribute('aria-hidden', 'true');
                toolbar.appendChild(sep);
                return;
            }
            const [label, title, action] = def;
            const button = document.createElement('button');
            button.type = 'button';
            button.textContent = label;
            button.title = title;
            button.setAttribute('aria-label', title);
            button.addEventListener('mousedown', (e) => e.preventDefault());
            button.addEventListener('click', () => { action(); this.sync(); });
            toolbar.appendChild(button);
        });

        this.content = document.createElement('div');
        this.content.className = 'rte-content story-body';
        this.content.contentEditable = 'true';
        this.content.setAttribute('role', 'textbox');
        this.content.setAttribute('aria-multiline', 'true');
        const label = this.input.labels && this.input.labels[0];
        if (label) {
            label.id = label.id || this.input.id + '_label';
            this.content.setAttribute('aria-labelledby', label.id);
            label.addEventListener('click', () => this.content.focus());
        }
        this.content.addEventListener('input', () => { this.sync(); this.updateStatus(); });
        this.content.addEventListener('paste', (e) => this.paste(e));

        this.source = document.createElement('textarea');
        this.source.className = 'rte-source';
        this.source.hidden = true;
        this.source.setAttribute('aria-label', 'HTML source');
        this.source.addEventListener('input', () => { this.input.value = this.source.value; });

        this.status = document.createElement('div');
        this.status.className = 'rte-status';

        this.wrapper.append(toolbar, this.content, this.source, this.status);
        this.input.insertAdjacentElement('afterend', this.wrapper);
    }

    exec(command, value = null) {
        this.content.focus();
        document.execCommand(command, false, value);
    }

    block(tag) {
        this.content.focus();
        const current = document.queryCommandValue('formatBlock').toLowerCase();
        document.execCommand('formatBlock', false, current === tag && tag !== 'p' ? '<p>' : '<' + BLOCKS[tag] + '>');
    }

    link() {
        const url = window.prompt('Link URL (https://…)');
        if (!url) {
            return;
        }
        if (!/^(https?:\/\/|mailto:|\/)/i.test(url)) {
            window.alert('Only http(s), mailto or relative links are allowed.');
            return;
        }
        this.exec('createLink', url);
    }

    paste(event) {
        // Paste as clean text, keeping paragraph breaks; formatting is re-applied with the toolbar.
        const text = event.clipboardData && event.clipboardData.getData('text/plain');
        if (text === undefined || text === null) {
            return;
        }
        event.preventDefault();
        const html = text
            .split(/\n{2,}/)
            .map((para) => '<p>' + this.escape(para).replace(/\n/g, '<br>') + '</p>')
            .join('');
        document.execCommand('insertHTML', false, html);
    }

    toggleSource() {
        const showing = !this.source.hidden;
        if (showing) {
            this.content.innerHTML = this.source.value;
            this.source.hidden = true;
            this.content.hidden = false;
        } else {
            this.source.value = this.content.innerHTML;
            this.source.hidden = false;
            this.content.hidden = true;
        }
        this.sync();
    }

    sync() {
        this.input.value = this.source.hidden ? this.content.innerHTML : this.source.value;
    }

    updateStatus() {
        const words = (this.content.innerText || '').trim().split(/\s+/).filter(Boolean).length;
        this.status.textContent = '';
        const left = document.createElement('span');
        left.textContent = words + ' words · ~' + Math.max(1, Math.ceil(words / 180)) + ' min read';
        const right = document.createElement('span');
        right.textContent = 'Allowed: headings, paragraphs, bold, italic, lists, verse quotes, links, dividers';
        this.status.append(left, right);
    }

    escape(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}
