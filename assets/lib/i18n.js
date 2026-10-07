/**
 * UI strings for the Stimulus controllers, in the page language (Hindi or English).
 * base.html.twig renders the "js.*" translations from translations/messages.*.yaml into
 * <script type="application/json" id="i18n-messages">, so the YAML files stay the single source of
 * truth. Keys are used without the "js." prefix; placeholders look like Symfony's: %name%.
 *
 *   t('share.copied')                         → "लिंक कॉपी हो गया" / "Link copied"
 *   t('collection.added', { name: 'Morning' }) → "“Morning” में जोड़ा गया" / "Added to “Morning”"
 */
let source = null;
let messages = {};

function load() {
    // Turbo replaces <body> on every visit, so re-read when the element changes.
    const element = document.getElementById('i18n-messages');
    if (element !== source) {
        source = element;
        try {
            messages = element ? JSON.parse(element.textContent || '{}') : {};
        } catch {
            messages = {};
        }
    }

    return messages;
}

export function t(key, params = {}) {
    let text = load()[key] ?? key;
    for (const [name, value] of Object.entries(params)) {
        text = text.split(`%${name}%`).join(String(value));
    }

    return text;
}

/** "hi" or "en" — the language of the current page. */
export function pageLocale() {
    return document.documentElement.lang === 'en' ? 'en' : 'hi';
}
