import { Controller } from '@hotwired/stimulus';
import { normalize, scoreElement, tokens } from 'search';

/*
 * Filtre + tri client d'une liste d'index (bibliothèque, séances, plans…).
 * Aucun réseau : compatible offline, cohérent avec les pages auto-suffisantes.
 *
 * Trois leviers, tous 100 % client :
 *   1. Recherche PONDÉRÉE par MOTS-CLÉS — la requête est découpée en mots, tous
 *      exigés, comparés sans accents (cf. `assets/search.js`). Au-delà de ce
 *      filtre, un match exact du nom passe avant un match en début de nom, avant
 *      un match dans le nom, avant un match ailleurs (activité, zones, nom dans
 *      l'autre langue). Les items non correspondants sont masqués.
 *   2. Facettes — des puces (activité, portée…) restreignent la liste. Chaque
 *      groupe de facette est indépendant ; « Tous » réinitialise le groupe.
 *   3. Tri — un <select> réordonne les items (nom, récence, durée, volume…).
 *
 * Quand une recherche est active, la pertinence prime, l'USAGE RÉEL départage
 * (`data-sort-usage`, quand l'item en porte un), et le tri choisi tranche en
 * dernier. C'est l'ordre utile en salle : à pertinence égale, l'exercice qu'on
 * fait toutes les semaines passe avant celui qu'on n'a jamais touché. Sans
 * recherche, le tri choisi ordonne seul la liste — l'usage n'y est qu'une des
 * options du <select>, pas un ordre imposé.
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
 *   - data-filter-text  : le reste du texte cherché (activité, zones, second
 *                          libellé…). Le nom y est ajouté automatiquement, il
 *                          n'a pas à y être répété.
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
        this.terms = [];
        this.readFacets();
        this.readSort();
        this.apply();
    }

    // --------------------------------------------------------------- Événements
    search(event) {
        this.query = normalize(event.target.value);
        this.terms = tokens(event.target.value);
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
    /*
     * État initial des facettes, lu dans le HTML rendu : la puce active d'un
     * groupe porte déjà `kd-libfilter--on` (cf. `_filterbar.html.twig`). Sans
     * cette lecture, un groupe dont le défaut n'est pas « all » — la portée d'un
     * coach, qui s'ouvre sur ses propres entrées — s'afficherait actif tout en
     * laissant passer tous les items.
     */
    readFacets() {
        this.facets = {};
        this.facetTargets
            .filter((b) => b.classList.contains('kd-libfilter--on'))
            .forEach((b) => { this.facets[b.dataset.facetGroup] = b.dataset.facetValue; });
    }

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
        const searching = this.terms.length > 0;
        const entries = this.itemTargets.map((el) => {
            const score = searching ? scoreElement(el, this.terms, this.query) : 0;
            return { el, score, visible: this.facetMatch(el) && (!searching || score > 0) };
        });

        let visible = 0;
        entries.forEach((e) => {
            e.el.hidden = !e.visible;
            if (e.visible) visible += 1;
        });

        // Réordonnancement en place : visibles triés d'abord, masqués ensuite.
        const shown = entries.filter((e) => e.visible).sort((a, b) => {
            if (searching) {
                if (a.score !== b.score) return b.score - a.score;
                // À pertinence égale, ce qu'on fait le plus souvent d'abord.
                // Un item sans `data-sort-usage` vaut 0 et part en fin de
                // peloton, ce qui est juste : rien ne dit qu'il a été fait.
                const usage = Number(b.el.dataset.sortUsage || 0) - Number(a.el.dataset.sortUsage || 0);
                if (usage !== 0) return usage;
            }
            return this.compareSort(a.el, b.el);
        });
        if (this.hasListTarget) {
            [...shown, ...entries.filter((e) => !e.visible)]
                .forEach((e) => this.listTarget.appendChild(e.el));
        }

        if (this.hasEmptyTarget) this.emptyTarget.hidden = visible !== 0;
        if (this.hasCountTarget) this.countTarget.textContent = visible;
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
