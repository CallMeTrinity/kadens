import { Controller } from '@hotwired/stimulus';

/*
 * Filtre client d'une liste d'index (bibliothèque, séances, plans…).
 * Aucun réseau : compatible offline, cohérent avec les pages auto-suffisantes.
 *
 * Cibles :
 *   - input : le champ de recherche
 *   - item  : chaque élément filtrable (porte data-filter-text="…")
 *   - empty : bloc affiché quand aucun élément ne correspond (optionnel)
 *   - count : reçoit le nombre d'éléments visibles (optionnel)
 */
export default class extends Controller {
    static targets = ['input', 'item', 'empty', 'count'];

    connect() {
        this.filter();
    }

    filter() {
        const query = (this.hasInputTarget ? this.inputTarget.value : '').trim().toLowerCase();
        let visible = 0;

        this.itemTargets.forEach((el) => {
            const haystack = (el.dataset.filterText || '').toLowerCase();
            const show = query === '' || haystack.includes(query);
            el.hidden = !show;
            if (show) visible += 1;
        });

        if (this.hasEmptyTarget) {
            this.emptyTarget.hidden = visible !== 0;
        }
        if (this.hasCountTarget) {
            this.countTarget.textContent = visible;
        }
    }
}
