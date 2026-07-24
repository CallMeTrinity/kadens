import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';
import Sortable from 'sortablejs';

/*
 * Éditeur de trame de plan. Deux responsabilités, toutes deux en amélioration
 * progressive (sans JS : le placement/retrait par formulaire et les boutons
 * restent le repli fonctionnel) :
 *
 * 1. Glisser-déposer d'une séance d'une case à l'autre (SortableJS). Chaque cellule
 *    (`data-plangrid-target="cell"`) est source ET cible d'un même groupe. Sur dépôt,
 *    on lit l'URL de déplacement et le jeton CSRF portés par la carte déplacée, on
 *    poste en `fetch` (format stream) et on applique le Turbo Stream qui met à jour
 *    #plan-grid. Comme la grille est re-rendue à chaque mutation, chaque cellule
 *    détruit son instance Sortable à la déconnexion et la recrée à la connexion.
 *
 * 2. Édition rapide : cliquer une séance ouvre une mini-modale. On charge en `fetch`
 *    le panneau de ses exercices (`app_workout_quick_panel`) dans #quick-panel, où
 *    chaque paramètre (reps/séries/repos…) est éditable. Enregistrer un exercice
 *    poste en `fetch` (format stream) et met à jour #quick-panel, sans recharger.
 *    La modale porte `data-turbo="false"` : Turbo n'intercepte rien, on applique
 *    nous-mêmes les streams (comme le compositeur). Le lien « Édition complète »
 *    renvoie au compositeur pour la structure (blocs, ordre). À la fermeture, si un
 *    enregistrement a eu lieu, on recharge la page pour refléter durée/titre sur les
 *    cases.
 */
export default class extends Controller {
    static targets = ['cell', 'dialog', 'panel', 'fullLink'];

    static SORTABLE_GROUP = 'kd-plan-workouts';

    initialize() {
        this.sortables = new WeakMap();
        this.dirty = false;
    }

    connect() {
        // Intercepte les soumissions des formulaires du panneau d'édition rapide.
        // Les formulaires de la trame (ajout/retrait de case) sont hors #quick-panel :
        // ils gardent leur comportement natif (repli sans JS).
        this.onPanelSubmit = this.onPanelSubmit.bind(this);
        this.element.addEventListener('submit', this.onPanelSubmit);
    }

    disconnect() {
        this.element.removeEventListener('submit', this.onPanelSubmit);
    }

    // ---- Glisser-déposer ---------------------------------------------------

    cellTargetConnected(el) {
        this.sortables.set(el, Sortable.create(el, {
            group: this.constructor.SORTABLE_GROUP,
            handle: '.kd-planitem__handle',
            draggable: '.kd-planentry',
            animation: 150,
            ghostClass: 'kd-drag-ghost',
            chosenClass: 'kd-drag-chosen',
            dragClass: 'kd-drag-active',
            onEnd: (evt) => this.onMove(evt),
        }));
    }

    cellTargetDisconnected(el) {
        const instance = this.sortables.get(el);
        if (instance) {
            instance.destroy();
            this.sortables.delete(el);
        }
    }

    onMove(evt) {
        const card = evt.item;
        const target = evt.to;
        // Déposé dans la même case (le jour/semaine ne change pas) : rien à faire.
        // L'ordre au sein d'un jour n'est pas signifiant, on n'envoie donc rien.
        if (target === evt.from) return;

        const url = card.dataset.moveUrl;
        const token = card.dataset.moveToken;
        const week = target.dataset.week;
        const day = target.dataset.day;
        if (!url || !week || !day) return;

        const body = new FormData();
        body.append('_token', token);
        body.append('week', week);
        body.append('day', day);

        this.postStream(url, body);
    }

    async postStream(url, body) {
        try {
            const response = await fetch(url, {
                method: 'POST',
                body,
                headers: { Accept: 'text/vnd.turbo-stream.html' },
                credentials: 'same-origin',
            });
            renderStreamMessage(await response.text());
        } catch (error) {
            console.error('Plan grid move failed:', error);
            window.location.reload();
        }
    }

    // ---- Mini-modale d'édition rapide -------------------------------------

    dialogTargetConnected(el) {
        // ESC / bouton / backdrop ferment le <dialog> et déclenchent l'event `close`.
        // Un enregistrement change la durée estimée (voire le titre) affichée sur les
        // cases : on recharge alors la page pour la refléter dans la grille.
        el.addEventListener('close', () => {
            if (this.dirty) window.location.reload();
        });
    }

    async edit(event) {
        const button = event.currentTarget;
        const panelUrl = button.dataset.panelUrl;
        const fullUrl = button.dataset.fullUrl;
        if (!panelUrl || !this.hasPanelTarget || !this.hasDialogTarget) return;

        this.dirty = false;
        if (this.hasFullLinkTarget && fullUrl) this.fullLinkTarget.href = fullUrl;
        this.panelTarget.innerHTML = '<p class="kd-quickedit__loading">Chargement…</p>';
        this.dialogTarget.showModal();

        try {
            const response = await fetch(panelUrl, { credentials: 'same-origin' });
            this.panelTarget.innerHTML = await response.text();
        } catch (error) {
            console.error('Quick panel load failed:', error);
            this.panelTarget.innerHTML = '<p class="kd-quickedit__error">Chargement impossible.</p>';
        }
    }

    /** Enregistrement d'un exercice depuis le panneau : fetch + Turbo Stream appliqué. */
    async onPanelSubmit(event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (!this.hasPanelTarget || !this.panelTarget.contains(form)) return;

        event.preventDefault();
        this.dirty = true;

        try {
            const response = await fetch(form.action, {
                method: (form.getAttribute('method') || 'post').toUpperCase(),
                body: new FormData(form),
                headers: { Accept: 'text/vnd.turbo-stream.html' },
                credentials: 'same-origin',
            });
            renderStreamMessage(await response.text());
        } catch (error) {
            console.error('Quick edit failed:', error);
            window.location.reload();
        }
    }

    close() {
        if (this.hasDialogTarget) this.dialogTarget.close();
    }

    backdrop(event) {
        if (event.target === this.dialogTarget) this.dialogTarget.close();
    }
}
