import { Controller } from '@hotwired/stimulus';
import { visit } from '@hotwired/turbo';

// Import long (archive complète) : enchaîne les étapes côté serveur et affiche l'avancement.
// Sans JS, le bouton « Continuer » fait une étape par envoi du formulaire.
export default class extends Controller {
    static targets = ['form', 'button', 'fill', 'label', 'status'];

    connect() {
        this.stopped = false;
        this.step();
    }

    disconnect() {
        this.stopped = true;
    }

    resume(event) {
        event.preventDefault();
        this.stopped = false;
        this.step();
    }

    abandon() {
        this.stopped = true;
    }

    async step() {
        this.buttonTarget.disabled = true;
        this.buttonTarget.textContent = 'Import en cours…';
        try {
            const response = await fetch(this.formTarget.action, { method: 'POST', body: new FormData(this.formTarget), headers: { Accept: 'application/json' } });
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            const state = await response.json();
            if (state.redirect) {
                visit(state.redirect);
                return;
            }
            this.fillTarget.style.setProperty('--meter-value', `${state.percent}%`);
            this.fillTarget.parentElement.setAttribute('aria-valuenow', state.done);
            this.labelTarget.textContent = `${state.done} / ${state.total} traces (${state.percent} %)`;
            if (!this.stopped) {
                this.step();
            }
        } catch {
            this.buttonTarget.disabled = false;
            this.buttonTarget.textContent = 'Reprendre';
            this.statusTarget.textContent = 'L\'import s\'est interrompu (connexion ou serveur). Les traces déjà lues sont enregistrées : « Reprendre » continue là où il s\'est arrêté.';
        }
    }
}
