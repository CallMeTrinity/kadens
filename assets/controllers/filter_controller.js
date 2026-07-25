import { Controller } from '@hotwired/stimulus';

/*
 * Filtre + tri client d'une liste d'index (bibliothèque, séances, plans…).
 * Aucun réseau : compatible offline, cohérent avec les pages auto-suffisantes.
 *
 * Trois leviers, tous 100 % client :
 *   1. Recherche PONDÉRÉE — un match exact du nom passe avant un match en début
 *      de nom, avant un match dans le nom, avant un match ailleurs (activité,
 *      zones…). Les items non correspondants sont masqués.
 *   2. Facettes — des puces (activité, portée…) restreignent la liste. Chaque
 *      groupe de facette est indépendant ; « Tous » réinitialise le groupe.
 *   3. Tri — un <select> réordonne les items (nom, récence, durée, volume…).
 *
 * Quand une recherche est active, la pertinence prime et le tri sert de
 * départage ; sans recherche, le tri choisi ordonne la liste.
 *
 * Cibles :
 *   - input : le champ de recherche
 *   - list  : le conteneur des items (réordonné en place)
 *   - item  : chaque élément filtrable
 *   - facet : chaque puce de facette (optionnel)
 *   - sort  : le <select> de tri (optionnel)
 *   - empty : bloc affiché quand aucun élément ne correspond (optionnel)
 *   - count : reçoit le nombre d'éléments visibles (optionnel)
 *
 * Attributs portés par chaque item :
 *   - data-filter-name  : nom principal (base du classement exact/préfixe)
 *   - data-filter-text  : texte complet cherché (nom + activité + zones…)
 *   - data-facet-<grp>  : valeurs d'appartenance à un groupe (séparées par des
 *                          espaces), ex. data-facet-activity="gym running"
 *   - data-sort-<clef>  : valeur de tri, ex. data-sort-name, data-sort-created
 *
 * Attributs portés par chaque puce de facette :
 *   - data-facet-group  : le groupe (ex. "activity")
 *   - data-facet-value  : la valeur retenue (ex. "gym", ou "all" pour réinit.)
 *
 * Options du <select> de tri :
 *   - value        : la clef (suffixe de data-sort-<clef>)
 *   - data-dir     : "asc" | "desc"
 *   - data-numeric : présent => comparaison numérique (sinon alphabétique)
 */
export default class extends Controller {
    static targets = ['input', 'list', 'item', 'facet', 'sort', 'empty', 'count'];

    connect() {
        this.query = '';
        this.facets = {};
        this.readSort();
        this.apply();
    }

    // --------------------------------------------------------------- Événements
    search(event) {
        this.query = (event.target.value || '').trim().toLowerCase();
        this.apply();
    }

    pickFacet(event) {
        const btn = event.currentTarget;
        const group = btn.dataset.facetGroup;
        this.facets[group] = btn.dataset.facetValue;
        // état visuel : une seule puce active par groupe
        this.facetTargets
            .filter((b) => b.dataset.facetGroup === group)
            .forEach((b) => b.classList.toggle('kd-libfilter--on', b === btn));
        this.apply();
    }

    sort() {
        this.readSort();
        this.apply();
    }

    // ------------------------------------------------------------------ Logique
    readSort() {
        if (!this.hasSortTarget) {
            this.sortKey = 'name';
            this.sortDir = 'asc';
            this.sortNumeric = false;
            return;
        }
        const opt = this.sortTarget.selectedOptions[0];
        this.sortKey = this.sortTarget.value || 'name';
        this.sortDir = opt?.dataset.dir === 'desc' ? 'desc' : 'asc';
        this.sortNumeric = opt ? 'numeric' in opt.dataset : false;
    }

    apply() {
        const q = this.query;
        const entries = this.itemTargets.map((el) => {
            const score = this.score(el, q);
            return { el, score, visible: this.facetMatch(el) && (q === '' || score > 0) };
        });

        let visible = 0;
        entries.forEach((e) => {
            e.el.hidden = !e.visible;
            if (e.visible) visible += 1;
        });

        // Réordonnancement en place : visibles triés d'abord, masqués ensuite.
        const shown = entries.filter((e) => e.visible).sort((a, b) => {
            if (q !== '' && a.score !== b.score) return b.score - a.score;
            return this.compareSort(a.el, b.el);
        });
        if (this.hasListTarget) {
            [...shown, ...entries.filter((e) => !e.visible)]
                .forEach((e) => this.listTarget.appendChild(e.el));
        }

        if (this.hasEmptyTarget) this.emptyTarget.hidden = visible !== 0;
        if (this.hasCountTarget) this.countTarget.textContent = visible;
    }

    /** Pertinence : 4 exact, 3 préfixe du nom, 2 dans le nom, 1 ailleurs, 0 rien. */
    score(el, q) {
        if (q === '') return 0;
        const name = (el.dataset.filterName || '').toLowerCase();
        const text = (el.dataset.filterText || '').toLowerCase();
        if (name === q) return 4;
        if (name.startsWith(q)) return 3;
        if (name.includes(q)) return 2;
        if (text.includes(q)) return 1;
        return 0;
    }

    facetMatch(el) {
        return Object.entries(this.facets).every(([group, value]) => {
            if (!value || value === 'all') return true;
            const key = `facet${group.charAt(0).toUpperCase()}${group.slice(1)}`;
            const owned = (el.dataset[key] || '').split(' ').filter(Boolean);
            return owned.includes(value);
        });
    }

    compareSort(a, b) {
        const attr = `sort${this.sortKey.charAt(0).toUpperCase()}${this.sortKey.slice(1)}`;
        const va = a.dataset[attr] || '';
        const vb = b.dataset[attr] || '';
        const cmp = this.sortNumeric
            ? Number(va) - Number(vb)
            : va.localeCompare(vb, 'fr', { sensitivity: 'base' });
        return this.sortDir === 'desc' ? -cmp : cmp;
    }
}
