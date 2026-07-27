import { Controller } from '@hotwired/stimulus';

/*
 * Sélecteur de trajectoire d'exercice (bloc « Progression prévue » d'un plan).
 * Les charts sont pré-rendus côté serveur (auto-suffisant, aucun AJAX) ; ce
 * contrôleur ne fait que masquer tout sauf celui de l'exercice choisi. Sans JS,
 * tous les charts restent visibles — la page reste lisible et cachable offline.
 */
export default class extends Controller {
    static targets = ['select', 'chart'];

    connect() {
        this.change();
    }

    change() {
        const selected = this.hasSelectTarget ? this.selectTarget.value : null;
        this.chartTargets.forEach((chart) => {
            chart.hidden = selected !== null && chart.dataset.progressionExercise !== selected;
        });
    }
}
