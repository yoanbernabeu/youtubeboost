import { Controller } from '@hotwired/stimulus';

/*
 * Copies the value of an element to the clipboard and confirms it briefly.
 */
export default class extends Controller {
    static values = { text: String, label: String };
    static targets = ['label'];

    async copy() {
        try {
            await navigator.clipboard.writeText(this.textValue);
            this.#flash('Copié !');
        } catch {
            this.#flash('Copie impossible');
        }
    }

    #flash(message) {
        if (!this.hasLabelTarget) {
            return;
        }
        const previous = this.labelTarget.textContent;
        this.labelTarget.textContent = message;
        setTimeout(() => {
            this.labelTarget.textContent = this.labelValue || previous;
        }, 1600);
    }
}
