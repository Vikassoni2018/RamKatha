import './stimulus_bootstrap.js';
import * as Turbo from '@hotwired/turbo';

/*
 * Public site entrypoint. Stylesheets are plain <link> tags in base.html.twig: importing CSS
 * from JavaScript would make AssetMapper emit "data:" modules, which the Content-Security-Policy
 * (script-src 'self' + nonce) rightly blocks. Stimulus controllers marked "lazy" are only
 * downloaded on pages that use them.
 */

import { flushAnalytics } from './lib/analytics.js';

// Keep Turbo's progress bar subtle and in brand colour (set in CSS); show it only after 250ms.
if (Turbo.config && Turbo.config.drive) {
    Turbo.config.drive.progressBarDelay = 250;
}

// Flush pending analytics before the page is hidden / navigated away.
document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
        flushAnalytics(true);
    }
});
document.addEventListener('turbo:before-visit', () => flushAnalytics(true));
