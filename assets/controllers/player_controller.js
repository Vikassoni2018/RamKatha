import { Controller } from '@hotwired/stimulus';
import { beacon } from '../lib/http.js';
import { track } from '../lib/analytics.js';

/*
 * Global audio player. Lives in a data-turbo-permanent element, so playback continues while the
 * visitor browses other pages. Other controllers talk to it through window events:
 *   player:load    {track, autoplay}      load a story's audio
 *   player:command {action, value}        toggle | play | pause | seek | skip | rate | volume | mute | close
 *   player:query                           ask for a player:state broadcast
 * It broadcasts player:state {uuid, playing, currentTime, duration, rate, volume, muted, loading}.
 *
 * Progress: logged-in users → POST /api/progress (every 15 s, on pause/end/unload);
 *           guests → localStorage (last 30 stories).
 * Media Session: title/cover and play/pause/seek controls on the lock screen, notification and headsets.
 */
const GUEST_KEY = 'katha:progress';
const LAST_TRACK_KEY = 'katha:lastTrack';
const RATES = [0.75, 1, 1.25, 1.5, 2];

export default class extends Controller {
    static targets = ['audio', 'bar', 'cover', 'title', 'time', 'playIcon', 'pauseIcon', 'playButton', 'speed'];
    static values = {
        progressUrl: String,
        authenticated: Boolean,
        threshold: { type: Number, default: 90 },
    };

    connect() {
        this.track = null;
        this.lastSaved = 0;
        this.milestones = new Set();
        this.played = false;
        this.completedSent = false;

        this.onLoad = (e) => this.load(e.detail.track, e.detail.autoplay !== false);
        this.onCommand = (e) => this.command(e.detail.action, e.detail.value);
        this.onQuery = () => this.broadcast();
        this.onPageHide = () => this.saveProgress(true);
        this.onTurboLoad = () => this.syncBodyClass();
        window.addEventListener('player:load', this.onLoad);
        window.addEventListener('player:command', this.onCommand);
        window.addEventListener('player:query', this.onQuery);
        window.addEventListener('pagehide', this.onPageHide);
        document.addEventListener('turbo:load', this.onTurboLoad);

        const audio = this.audioTarget;
        this.handlers = {
            play: () => { this.updateIcons(); this.firstPlay(); this.syncMediaSession(); this.broadcast(); },
            pause: () => { this.updateIcons(); this.saveProgress(false); this.syncMediaSession(); this.broadcast(); },
            timeupdate: () => this.onTime(),
            loadedmetadata: () => { this.applyStart(); this.syncMediaSession(); this.broadcast(); },
            seeked: () => this.syncMediaSession(),
            ended: () => { this.markEnd(); this.saveProgress(false); this.updateIcons(); this.syncMediaSession(); this.broadcast(); },
            ratechange: () => { this.syncMediaSession(); this.broadcast(); },
            volumechange: () => this.broadcast(),
            waiting: () => { this.loading = true; this.broadcast(); },
            playing: () => { this.loading = false; this.broadcast(); },
            error: () => { this.loading = false; this.broadcast(); },
        };
        Object.entries(this.handlers).forEach(([event, handler]) => audio.addEventListener(event, handler));
        this.setMediaSessionHandlers(true);

        this.restoreLastTrack();
    }

    disconnect() {
        window.removeEventListener('player:load', this.onLoad);
        window.removeEventListener('player:command', this.onCommand);
        window.removeEventListener('player:query', this.onQuery);
        window.removeEventListener('pagehide', this.onPageHide);
        document.removeEventListener('turbo:load', this.onTurboLoad);
        Object.entries(this.handlers).forEach(([event, handler]) => this.audioTarget.removeEventListener(event, handler));
        this.setMediaSessionHandlers(false);
    }

    // ---- public API (events + mini player buttons) -------------------------------------

    load(track, autoplay = true) {
        if (!track || !track.src) {
            return;
        }
        const audio = this.audioTarget;
        if (this.track && this.track.uuid === track.uuid) {
            if (autoplay) {
                audio.play().catch(() => {});
            }
            return;
        }
        this.saveProgress(false);
        this.track = track;
        this.milestones = new Set();
        this.played = false;
        this.completedSent = false;
        this.pendingStart = this.resolveStart(track);
        audio.src = track.src;
        audio.playbackRate = this.storedRate();
        audio.load();

        this.titleTarget.textContent = track.title || '';
        this.titleTarget.setAttribute('href', track.url || '#');
        if (track.cover) {
            this.coverTarget.src = track.cover;
            this.coverTarget.hidden = false;
        } else {
            this.coverTarget.hidden = true;
        }
        this.speedTarget.value = String(audio.playbackRate);
        this.show();
        this.syncMediaSession();
        this.rememberTrack();
        if (autoplay) {
            audio.play().catch(() => {});
        }
        this.broadcast();
    }

    command(action, value) {
        const audio = this.audioTarget;
        if (!this.track && action !== 'close') {
            return;
        }
        switch (action) {
            case 'toggle':
                audio.paused ? audio.play().catch(() => {}) : audio.pause();
                break;
            case 'play':
                audio.play().catch(() => {});
                break;
            case 'pause':
                audio.pause();
                break;
            case 'seek':
                if (Number.isFinite(audio.duration)) {
                    audio.currentTime = Math.max(0, Math.min(audio.duration, Number(value)));
                }
                break;
            case 'skip':
                audio.currentTime = Math.max(0, Math.min(audio.duration || Infinity, audio.currentTime + Number(value)));
                break;
            case 'rate':
                audio.playbackRate = RATES.includes(Number(value)) ? Number(value) : 1;
                this.speedTarget.value = String(audio.playbackRate);
                try { localStorage.setItem('katha:rate', String(audio.playbackRate)); } catch (e) { /* ignore */ }
                break;
            case 'volume':
                audio.volume = Math.max(0, Math.min(1, Number(value)));
                audio.muted = audio.volume === 0;
                break;
            case 'mute':
                audio.muted = !audio.muted;
                break;
            case 'close':
                this.close();
                return;
        }
        this.broadcast();
    }

    // Mini player buttons
    toggle() { this.command('toggle'); }
    back() { this.command('skip', -10); }
    forward() { this.command('skip', 10); }
    changeRate(event) { this.command('rate', event.target.value); }
    close() {
        this.saveProgress(false);
        this.audioTarget.pause();
        this.element.querySelector('.mini-player').classList.remove('is-visible');
        document.body.classList.remove('has-player');
        try { localStorage.removeItem(LAST_TRACK_KEY); } catch (e) { /* ignore */ }
        this.track = null;
        this.syncMediaSession();
        this.broadcast();
    }

    // ---- Media Session (lock screen, notification, headset buttons) ---------------------

    setMediaSessionHandlers(enable) {
        if (!('mediaSession' in navigator)) {
            return;
        }
        const handlers = {
            play: () => this.command('play'),
            pause: () => this.command('pause'),
            seekbackward: (details) => this.command('skip', -(details.seekOffset || 10)),
            seekforward: (details) => this.command('skip', details.seekOffset || 10),
            seekto: (details) => this.command('seek', details.seekTime),
            stop: () => this.close(),
        };
        Object.entries(handlers).forEach(([action, handler]) => {
            try {
                navigator.mediaSession.setActionHandler(action, enable ? handler : null);
            } catch (e) { /* action not supported by this browser */ }
        });
    }

    syncMediaSession() {
        if (!('mediaSession' in navigator)) {
            return;
        }
        const session = navigator.mediaSession;
        const audio = this.audioTarget;
        if (!this.track) {
            this.mediaSessionUuid = null;
            session.metadata = null;
            session.playbackState = 'none';
            return;
        }
        if (typeof MediaMetadata !== 'undefined' && this.mediaSessionUuid !== this.track.uuid) {
            this.mediaSessionUuid = this.track.uuid;
            session.metadata = new MediaMetadata({
                title: this.track.title || '',
                artist: document.querySelector('meta[property="og:site_name"]')?.content || '',
                artwork: this.track.cover ? [{ src: new URL(this.track.cover, window.location.href).href }] : [],
            });
        }
        session.playbackState = audio.paused ? 'paused' : 'playing';
        if (Number.isFinite(audio.duration) && audio.duration > 0 && typeof session.setPositionState === 'function') {
            try {
                session.setPositionState({ duration: audio.duration, playbackRate: audio.playbackRate, position: Math.min(audio.currentTime, audio.duration) });
            } catch (e) { /* inconsistent intermediate state, the next event fixes it */ }
        }
    }

    // ---- internals -----------------------------------------------------------------------

    show() {
        this.element.querySelector('.mini-player').classList.add('is-visible');
        this.syncBodyClass();
    }

    syncBodyClass() {
        const visible = this.element.querySelector('.mini-player').classList.contains('is-visible');
        document.body.classList.toggle('has-player', visible);
    }

    onTime() {
        const audio = this.audioTarget;
        const pct = audio.duration ? (audio.currentTime / audio.duration) * 100 : 0;
        this.barTarget.style.width = pct + '%';
        this.timeTarget.textContent = format(audio.currentTime) + ' / ' + format(audio.duration);

        if (this.track && audio.duration) {
            [25, 50, 75].forEach((m) => {
                if (pct >= m && !this.milestones.has(m)) {
                    this.milestones.add(m);
                    track(this.track.uuid, 'audio_' + m);
                }
            });
            if (pct >= this.thresholdValue && !this.completedSent) {
                this.completedSent = true;
                track(this.track.uuid, 'audio_complete');
                this.saveProgress(false);
            }
        }
        if (!audio.paused && Date.now() - this.lastSaved > 15000) {
            this.saveProgress(false);
        }
        const now = Date.now();
        if (!this.lastBroadcast || now - this.lastBroadcast > 250) {
            this.lastBroadcast = now;
            this.broadcast();
        }
    }

    firstPlay() {
        if (this.track && !this.played) {
            this.played = true;
            track(this.track.uuid, 'audio_play');
        }
    }

    markEnd() {
        if (this.track && !this.completedSent) {
            this.completedSent = true;
            track(this.track.uuid, 'audio_complete');
        }
    }

    applyStart() {
        const audio = this.audioTarget;
        if (this.pendingStart && this.pendingStart > 0 && audio.duration && this.pendingStart < audio.duration - 5) {
            audio.currentTime = this.pendingStart;
        }
        this.pendingStart = 0;
        this.onTime();
    }

    resolveStart(track) {
        const server = Number(track.startAt || 0);
        const local = this.guestPosition(track.uuid);
        return Math.max(server, local);
    }

    saveProgress(unloading) {
        if (!this.track) {
            return;
        }
        const audio = this.audioTarget;
        const position = Math.floor(audio.currentTime || 0);
        const duration = Number.isFinite(audio.duration) ? Math.floor(audio.duration) : null;
        if (position < 3) {
            return;
        }
        this.lastSaved = Date.now();
        this.rememberTrack();

        if (this.authenticatedValue && this.progressUrlValue) {
            beacon(this.progressUrlValue, { story: this.track.uuid, position, duration });
        }
        this.storeGuestPosition(this.track.uuid, position, duration);
        if (unloading) {
            this.rememberTrack();
        }
    }

    guestPosition(uuid) {
        try {
            const all = JSON.parse(localStorage.getItem(GUEST_KEY) || '{}');
            const entry = all[uuid];
            if (!entry) {
                return 0;
            }
            // Finished stories start again from the beginning.
            if (entry.d && entry.t / entry.d * 100 >= this.thresholdValue) {
                return 0;
            }
            return entry.t || 0;
        } catch (e) {
            return 0;
        }
    }

    storeGuestPosition(uuid, position, duration) {
        try {
            const all = JSON.parse(localStorage.getItem(GUEST_KEY) || '{}');
            all[uuid] = { t: position, d: duration, at: Date.now() };
            const entries = Object.entries(all).sort((a, b) => b[1].at - a[1].at).slice(0, 30);
            localStorage.setItem(GUEST_KEY, JSON.stringify(Object.fromEntries(entries)));
        } catch (e) {
            // ignore storage errors
        }
    }

    rememberTrack() {
        if (!this.track) {
            return;
        }
        try {
            localStorage.setItem(LAST_TRACK_KEY, JSON.stringify({ ...this.track, startAt: Math.floor(this.audioTarget.currentTime || 0) }));
        } catch (e) { /* ignore */ }
    }

    restoreLastTrack() {
        try {
            const saved = JSON.parse(localStorage.getItem(LAST_TRACK_KEY) || 'null');
            if (saved && saved.src) {
                this.load(saved, false);
            }
        } catch (e) { /* ignore */ }
    }

    storedRate() {
        try {
            const rate = Number(localStorage.getItem('katha:rate'));
            return RATES.includes(rate) ? rate : 1;
        } catch (e) {
            return 1;
        }
    }

    updateIcons() {
        const playing = !this.audioTarget.paused;
        this.playIconTarget.hidden = playing;
        this.pauseIconTarget.hidden = !playing;
        this.playButtonTarget.setAttribute('aria-label', playing ? 'रोकें' : 'चलाएँ');
    }

    broadcast() {
        const audio = this.audioTarget;
        window.dispatchEvent(new CustomEvent('player:state', {
            detail: {
                uuid: this.track ? this.track.uuid : null,
                playing: this.track ? !audio.paused : false,
                currentTime: audio.currentTime || 0,
                duration: Number.isFinite(audio.duration) ? audio.duration : (this.track ? Number(this.track.duration || 0) : 0),
                rate: audio.playbackRate,
                volume: audio.volume,
                muted: audio.muted,
                loading: !!this.loading,
            },
        }));
    }
}

export function format(seconds) {
    if (!Number.isFinite(seconds) || seconds < 0) {
        return '0:00';
    }
    const s = Math.floor(seconds % 60);
    const m = Math.floor((seconds / 60) % 60);
    const h = Math.floor(seconds / 3600);
    const pad = (n) => String(n).padStart(2, '0');
    return h > 0 ? `${h}:${pad(m)}:${pad(s)}` : `${m}:${pad(s)}`;
}
