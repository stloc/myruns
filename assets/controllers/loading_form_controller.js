import { Controller } from '@hotwired/stimulus';

// Les traitements longs (import, analyse IA) désactivent le bouton et l'annoncent.
export default class extends Controller {
    connect() {
        // Turbo garde une copie de la page pour le retour arrière : on la remet dans son état initial.
        this.restore = () => this.reset();
        document.addEventListener('turbo:before-cache', this.restore);
    }

    disconnect() {
        document.removeEventListener('turbo:before-cache', this.restore);
    }

    submit({ submitter: button }) {
        if (!button) {
            return;
        }
        // Le bouton désactivé n'est plus envoyé : on recopie sa valeur dans un champ caché.
        if (button.name) {
            this.hidden = document.createElement('input');
            this.hidden.type = 'hidden';
            this.hidden.name = button.name;
            this.hidden.value = button.value;
            this.element.append(this.hidden);
        }
        if (button.dataset.loadingLabel) {
            this.label = { button, text: button.textContent };
            button.textContent = button.dataset.loadingLabel;
        }
        this.disabled = [...this.element.querySelectorAll('button:not(:disabled)')];
        this.disabled.forEach((b) => { b.disabled = true; });
    }

    reset() {
        this.hidden?.remove();
        if (this.label) {
            this.label.button.textContent = this.label.text;
        }
        this.disabled?.forEach((b) => { b.disabled = false; });
        this.hidden = this.label = this.disabled = null;
    }
}
