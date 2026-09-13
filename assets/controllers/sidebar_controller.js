import { Controller } from '@hotwired/stimulus';

/*
 * Off-canvas navigation on small screens. On large screens the sidebar is always
 * visible and this controller does nothing.
 */
export default class extends Controller {
    static targets = ['panel', 'overlay'];

    open() {
        this.panelTarget.classList.remove('-translate-x-full');
        this.overlayTarget.classList.remove('hidden');
        document.body.classList.add('overflow-hidden', 'lg:overflow-auto');
    }

    close() {
        this.panelTarget.classList.add('-translate-x-full');
        this.overlayTarget.classList.add('hidden');
        document.body.classList.remove('overflow-hidden', 'lg:overflow-auto');
    }

    closeOnEscape(event) {
        if (event.key === 'Escape') {
            this.close();
        }
    }
}
