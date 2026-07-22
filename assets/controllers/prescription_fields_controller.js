import { Controller } from '@hotwired/stimulus';

/*
 * Affiche uniquement les champs de valeurs pertinents pour le type d'effort
 * sélectionné (séries/reps, distance/allure, durée, ...). La carte
 * type -> champs vient du serveur (enum PrescriptionType), donc aucune logique
 * métier n'est dupliquée ici : le contrôleur ne fait que masquer/montrer.
 *
 * Le nettoyage définitif des champs hors sous-ensemble reste fait côté serveur.
 */
export default class extends Controller {
    static targets = ['type', 'field'];
    static values = { map: Object };

    connect() {
        this.update();
    }

    update() {
        const selected = this.hasTypeTarget ? this.typeTarget.value : '';
        const relevant = this.mapValue[selected] || [];

        this.fieldTargets.forEach((element) => {
            element.hidden = !relevant.includes(element.dataset.field);
        });
    }
}
