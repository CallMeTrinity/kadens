import { Controller } from '@hotwired/stimulus';

/**
 * Onglets progressifs.
 *
 * Le serveur rend TOUS les panneaux, chacun précédé de son titre : sans JS la
 * page reste complète, lisible et imprimable (condition du cache offline —
 * aucune donnée n'est chargée après coup). Ce contrôleur ne fait que
 * l'améliorer : il révèle la barre d'onglets, masque les titres devenus
 * redondants, n'en laisse qu'un panneau visible et pose la sémantique ARIA.
 *
 * Motif « progressive enhancement » : la barre d'onglets est rendue `hidden`
 * côté serveur et dévoilée ici. Sans ça, un utilisateur sans JS verrait des
 * boutons inertes.
 *
 * Markup attendu :
 *   <div data-controller="tabs">
 *     <div data-tabs-target="list" hidden>
 *       <button data-tabs-target="tab" data-tabs-panel-param="a">…</button>
 *     </div>
 *     <section data-tabs-target="panel" data-tabs-name="a" aria-labelledby="…">
 *       <h2 data-tabs-target="panelTitle">…</h2>
 *     </section>
 *   </div>
 */
export default class extends Controller {
    static targets = ['list', 'tab', 'panel', 'panelTitle'];

    connect() {
        if (this.tabTargets.length < 2) {
            return;
        }

        this.listTarget.hidden = false;
        this.listTarget.setAttribute('role', 'tablist');

        this.tabTargets.forEach((tab, index) => {
            const panel = this.#panelFor(tab);
            if (!panel) {
                return;
            }

            // Les id sont dérivés du nom de panneau : le composant peut être
            // rendu plusieurs fois sur une page sans collision d'ancres.
            const name = panel.dataset.tabsName;
            tab.id = `tab-${name}`;
            panel.id = `panel-${name}`;

            tab.setAttribute('role', 'tab');
            tab.setAttribute('aria-controls', panel.id);
            panel.setAttribute('role', 'tabpanel');
            panel.setAttribute('aria-labelledby', tab.id);

            tab.addEventListener('click', () => this.#select(index));
            tab.addEventListener('keydown', (event) => this.#onKeydown(event, index));
        });

        // Les titres de panneau ne servaient qu'au repli sans JS : les onglets
        // les remplacent. On les retire de l'affichage ET de l'arbre a11y.
        this.panelTitleTargets.forEach((title) => {
            title.hidden = true;
        });

        this.#select(0);
    }

    #panelFor(tab) {
        return this.panelTargets.find((p) => p.dataset.tabsName === tab.dataset.tabsPanelParam) ?? null;
    }

    #select(index) {
        this.tabTargets.forEach((tab, i) => {
            const active = i === index;
            const panel = this.#panelFor(tab);

            tab.setAttribute('aria-selected', active ? 'true' : 'false');
            // Roving tabindex : un seul onglet dans l'ordre de tabulation, les
            // flèches naviguent entre les autres (motif ARIA « tabs »).
            tab.tabIndex = active ? 0 : -1;
            tab.classList.toggle('is-active', active);

            if (panel) {
                panel.hidden = !active;
            }
        });
    }

    #onKeydown(event, index) {
        const last = this.tabTargets.length - 1;
        let next = null;

        switch (event.key) {
            case 'ArrowRight': next = index === last ? 0 : index + 1; break;
            case 'ArrowLeft':  next = index === 0 ? last : index - 1; break;
            case 'Home':       next = 0; break;
            case 'End':        next = last; break;
            default: return;
        }

        event.preventDefault();
        this.#select(next);
        this.tabTargets[next].focus();
    }
}
