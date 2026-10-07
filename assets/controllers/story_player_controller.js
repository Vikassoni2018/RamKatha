import { Controller } from '@hotwired/stimulus';
import { format } from './player_controller.js';

/*
 * Inline player UI on the story page. It owns no <audio> element: it drives the global player
 * through events, so playback continues seamlessly in the sticky mini player while browsing.
 * Keyboard: all controls are native buttons/ranges; Space/K toggles while focus is in the card,
 * J/L skip 10 s.
 */
export default class extends Controller {
    static targets = ['playButton', 'playIcon', 'pauseIcon', 'seek', 'current', 'total', 'speed', 'volume', 'muteIcon', 'unmuteIcon', 'resume', 'status'];
    static values = {
        uuid: String,
        src: String,
        title: String,
        url: String,
        cover: String,
        duration: Number,
        startAt: { type: Number, default: 0 },
    };

    connect() {
        this.isCurrent = false;
        this.onState = (e) => this.render(e.detail);
        window.addEventListener('player:state', this.onState);
        this.totalTarget.textContent = format(this.durationValue);
        this.seekTarget.max = String(this.durationValue || 0);
        if (this.hasResumeTarget && this.startAtValue > 5) {
            this.resumeTarget.hidden = false;
        }
        window.dispatchEvent(new CustomEvent('player:query'));
    }

    disconnect() {
        window.removeEventListener('player:state', this.onState);
    }

    toggle() {
        if (!this.isCurrent) {
            window.dispatchEvent(new CustomEvent('player:load', {
                detail: {
                    autoplay: true,
                    track: {
                        uuid: this.uuidValue,
                        src: this.srcValue,
                        title: this.titleValue,
                        url: this.urlValue,
                        cover: this.coverValue,
                        duration: this.durationValue,
                        startAt: this.startAtValue,
                    },
                },
            }));
            return;
        }
        this.send('toggle');
    }

    back() { this.ensureLoaded(() => this.send('skip', -10)); }
    forward() { this.ensureLoaded(() => this.send('skip', 10)); }

    seek(event) {
        const value = Number(event.target.value);
        this.updateSeekFill(value, Number(this.seekTarget.max));
        this.currentTarget.textContent = format(value);
        if (this.isCurrent) {
            this.send('seek', value);
        } else {
            this.startAtValue = value;
        }
    }

    rate(event) {
        this.send('rate', event.target.value);
        try { localStorage.setItem('katha:rate', event.target.value); } catch (e) { /* ignore */ }
    }

    volume(event) { this.send('volume', event.target.value); }
    mute() { this.send('mute'); }

    keydown(event) {
        if (event.target.matches('input, select, textarea')) {
            return;
        }
        if (event.key === ' ' || event.key === 'k' || event.key === 'K') {
            event.preventDefault();
            this.toggle();
        } else if (event.key === 'j' || event.key === 'J') {
            this.back();
        } else if (event.key === 'l' || event.key === 'L') {
            this.forward();
        }
    }

    ensureLoaded(callback) {
        if (this.isCurrent) {
            callback();
        } else {
            this.toggle();
        }
    }

    send(action, value) {
        window.dispatchEvent(new CustomEvent('player:command', { detail: { action, value } }));
    }

    render(state) {
        this.isCurrent = state.uuid === this.uuidValue;
        if (!this.isCurrent) {
            this.setPlaying(false);
            return;
        }
        if (this.hasResumeTarget) {
            this.resumeTarget.hidden = true;
        }
        this.setPlaying(state.playing);
        this.playButtonTarget.classList.toggle('is-loading', state.loading && state.playing);
        const duration = state.duration || this.durationValue;
        this.seekTarget.max = String(Math.floor(duration));
        if (document.activeElement !== this.seekTarget) {
            this.seekTarget.value = String(Math.floor(state.currentTime));
        }
        this.updateSeekFill(state.currentTime, duration);
        this.currentTarget.textContent = format(state.currentTime);
        this.totalTarget.textContent = format(duration);
        this.speedTarget.value = String(state.rate);
        if (this.hasVolumeTarget) {
            this.volumeTarget.value = String(state.muted ? 0 : state.volume);
            this.volumeTarget.style.setProperty('--pct', (state.muted ? 0 : state.volume * 100) + '%');
            this.muteIconTarget.hidden = !state.muted;
            this.unmuteIconTarget.hidden = state.muted;
        }
    }

    setPlaying(playing) {
        this.playIconTarget.hidden = playing;
        this.pauseIconTarget.hidden = !playing;
        this.playButtonTarget.setAttribute('aria-label', playing ? 'कथा रोकें' : 'कथा सुनें');
        this.playButtonTarget.setAttribute('aria-pressed', playing ? 'true' : 'false');
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = playing ? 'चल रहा है' : '';
        }
    }

    updateSeekFill(current, duration) {
        const pct = duration ? Math.min(100, (current / duration) * 100) : 0;
        this.seekTarget.style.setProperty('--pct', pct + '%');
    }
}
