import { Controller } from '@hotwired/stimulus';

// Semaine du programme : une séance glissée sur un autre jour à venir échange les deux jours (repos compris).
// Le dépôt remplit le formulaire « Échanger », qui marche aussi sans glisser-déposer.
export default class extends Controller {
    static targets = ['day', 'from', 'to', 'swap'];

    disconnect() {
        this.release();
    }

    // La poignée est la cellule « Jour » : la ligne entière suit le pointeur.
    pick(event) {
        const row = event.currentTarget.closest('[data-day]');
        this.dragged = row.dataset.day;
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', this.dragged);
        event.dataTransfer.setDragImage(row, 16, 16);
        row.classList.add('is-dragged');
    }

    over(event) {
        // Seulement depuis cette semaine, et jamais sur le jour de départ.
        if (!this.dragged || event.currentTarget.dataset.day === this.dragged) {
            return;
        }
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        event.currentTarget.classList.add('is-drop-target');
    }

    leave(event) {
        if (!event.currentTarget.contains(event.relatedTarget)) {
            event.currentTarget.classList.remove('is-drop-target');
        }
    }

    drop(event) {
        event.preventDefault();
        const from = this.dragged;
        const to = event.currentTarget.dataset.day;
        this.release();
        if (!from || from === to || !this.hasSwapTarget) {
            return;
        }
        this.fromTarget.value = from;
        this.toTarget.value = to;
        this.swapTarget.form.requestSubmit(this.swapTarget);
    }

    release() {
        this.dragged = null;
        this.dayTargets.forEach((row) => row.classList.remove('is-dragged', 'is-drop-target'));
    }

    // Rafraîchissement par Turbo (morphing) : la semaine reste ouverte ou fermée comme l'a laissée l'utilisateur.
    keepOpen(event) {
        if (event.target === this.element && 'open' === event.detail.attributeName) {
            event.preventDefault();
        }
    }
}
