import { Controller } from '@hotwired/stimulus';

/*
 * Asks for confirmation before an action that cannot be undone: a write to
 * YouTube, or the deletion of a reference photo.
 */
export default class extends Controller {
    static values = { message: String };

    ask(event) {
        if (!window.confirm(this.messageValue)) {
            event.preventDefault();
        }
    }
}
