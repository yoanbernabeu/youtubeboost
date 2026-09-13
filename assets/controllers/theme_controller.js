import { Controller } from '@hotwired/stimulus';

/*
 * Remembers whether the creator prefers the dark theme.
 *
 * The class is applied by an inline script in the layout before first paint, so
 * this controller only has to handle the toggle itself.
 */
export default class extends Controller {
    static targets = ['light', 'dark'];

    connect() {
        this.#sync();
    }

    toggle() {
        const isDark = document.documentElement.classList.toggle('dark');
        try {
            localStorage.setItem('youtubeboost-theme', isDark ? 'dark' : 'light');
        } catch {
            // Private browsing: the choice simply does not survive the session.
        }
        this.#sync();
    }

    #sync() {
        const isDark = document.documentElement.classList.contains('dark');
        this.lightTargets.forEach((element) => element.classList.toggle('hidden', isDark));
        this.darkTargets.forEach((element) => element.classList.toggle('hidden', !isDark));
    }
}
