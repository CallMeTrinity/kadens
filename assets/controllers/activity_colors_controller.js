import { Controller } from '@hotwired/stimulus';

/*
 * Réglage « Couleurs des activités » (/profile/settings).
 *
 * Le vrai champ de chaque ligne est un <input type="color"> : les pastilles n'en
 * sont qu'un raccourci, et sans JS le sélecteur natif suffit. Ce contrôleur
 * ajoute trois confort, sans rien enregistrer lui-même :
 * - l'aperçu en direct de la pastille de la ligne (il réécrit --act, la
 *   variable que lit la pastille, cf. les classes porteuses .kd-act--*) ;
 * - le signalement d'un doublon (« = Natation ») — un avertissement, pas un
 *   refus : deux activités de la même couleur restent lisibles par l'icône ;
 * - « Par défaut », qui remet chaque ligne sur la valeur de `data-default`.
 *
 * La garde de lisibilité (contraste avec le blanc) reste côté serveur, dans
 * ActivityColorsType : c'est elle qui fait foi.
 */
export default class extends Controller {
    static targets = ['row', 'input', 'clash'];

    connect() {
        this.refresh();
    }

    pick(event) {
        const row = event.currentTarget.closest('[data-activity-colors-target="row"]');
        const input = row?.querySelector('[data-activity-colors-target="input"]');
        if (!input) return;

        input.value = event.currentTarget.dataset.color;
        this.refresh();
    }

    sync() {
        this.refresh();
    }

    reset() {
        this.inputTargets.forEach((input) => {
            input.value = input.dataset.default;
        });
        this.refresh();
    }

    refresh() {
        const values = this.rowTargets.map((row) => this.valueOf(row));

        this.rowTargets.forEach((row, index) => {
            const value = values[index];

            row.style.setProperty('--act', value);
            row.querySelectorAll('[data-color]').forEach((swatch) => {
                swatch.setAttribute('aria-pressed', String(swatch.dataset.color === value));
            });

            const twin = this.rowTargets.find((other, j) => j !== index && values[j] === value);
            const clash = row.querySelector('[data-activity-colors-target="clash"]');
            if (clash) {
                clash.hidden = !twin;
                clash.textContent = twin ? `= ${twin.dataset.label}` : '';
            }
        });
    }

    valueOf(row) {
        const input = row.querySelector('[data-activity-colors-target="input"]');

        return (input?.value || '').toLowerCase();
    }
}
