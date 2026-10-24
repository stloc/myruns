import { Controller } from '@hotwired/stimulus';

// Recherche d'activités : changer de critère de tri remet son sens habituel (allure : la plus rapide d'abord).
export default class extends Controller {
    static targets = ['sort', 'direction'];

    reset() {
        this.directionTarget.value = this.sortTarget.selectedOptions[0].dataset.direction;
    }
}
