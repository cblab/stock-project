import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['content', 'label'];

    connect() {
        this.open = false;
        this.render();
    }

    toggle() {
        this.open = !this.open;
        this.render();
    }

    render() {
        this.contentTarget.classList.toggle('hidden', !this.open);

        if (this.hasLabelTarget) {
            this.labelTarget.textContent = this.open ? 'Explain JSON ausblenden' : 'Explain JSON anzeigen';
        }
    }
}
