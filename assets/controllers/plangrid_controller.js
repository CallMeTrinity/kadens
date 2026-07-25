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
    static targets = ['cell', 'dialog', 'panel', 'fullLink', 'palette', 'paletteList', 'palettecard'];

    static SORTABLE_GROUP = 'kd-plan-workouts';

    initialize() {
        this.sortables = new WeakMap();
        this.dirty = false;
        // Palette : filtre client + mode tampon (une séance « armée » se pose au clic
        // sur les cases). L'état survit aux re-render de #plan-grid (porté ici).
        this.libQuery = '';
        this.libActivity = 'all';
        this.armedWorkoutId = null;
        this.armedCard = null;
    }

    connect() {
        // Intercepte les soumissions des formulaires du panneau d'édition rapide.
        // Les formulaires de la trame (ajout/retrait de case) sont hors #quick-panel :
        // ils gardent leur comportement natif (repli sans JS).
        this.onPanelSubmit = this.onPanelSubmit.bind(this);
        this.element.addEventListener('submit', this.onPanelSubmit);
        this.onKeydown = this.onKeydown.bind(this);
        this.element.addEventListener('keydown', this.onKeydown);
        this.applyLibFilter();
    }

    disconnect() {
        this.element.removeEventListener('submit', this.onPanelSubmit);
        this.element.removeEventListener('keydown', this.onKeydown);
    }

    onKeydown(event) {
        if (event.key === 'Escape') this.disarm();
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

    // ---- Palette : filtre client (offline-safe) ---------------------------

    filterLib(event) {
        this.libQuery = event.target.value.trim().toLowerCase();
        this.applyLibFilter();
    }

    pickActivity(event) {
        this.libActivity = event.currentTarget.dataset.activity;
        this.element.querySelectorAll('[data-activity-pill]').forEach((pill) => {
            pill.classList.toggle('kd-libfilter--on', pill.dataset.activity === this.libActivity);
        });
        this.applyLibFilter();
    }

    applyLibFilter() {
        if (!this.hasPalettecardTarget) return;
        this.palettecardTargets.forEach((card) => {
            const matchText = this.libQuery === '' || (card.dataset.filterText || '').toLowerCase().includes(this.libQuery);
            // Une séance peut porter plusieurs activités (data-activity espacé).
            const acts = (card.dataset.activity || '').split(' ').filter(Boolean);
            const matchAct = this.libActivity === 'all' || acts.includes(this.libActivity);
            card.hidden = !(matchText && matchAct);
        });
    }

    // ---- Palette : mode tampon (armer puis cliquer les cases) -------------

    armWorkout(event) {
        const card = event.currentTarget;
        const id = card.dataset.workoutId;
        if (!id) return;

        // Re-cliquer la séance armée la désarme.
        if (this.armedWorkoutId === id) {
            this.disarm();
            return;
        }

        this.armedWorkoutId = id;
        this.setArmedCard(card);
        this.element.classList.add('is-arming');
    }

    setArmedCard(card) {
        if (this.armedCard) this.armedCard.classList.remove('kd-palettecard--armed');
        this.armedCard = card;
        if (card) card.classList.add('kd-palettecard--armed');
    }

    disarm() {
        this.armedWorkoutId = null;
        this.setArmedCard(null);
        this.element.classList.remove('is-arming');
    }

    /** Clic sur une case : pose la séance armée (sinon rien). Ignore les clics sur
     *  une séance déjà posée (qui ouvrent l'édition rapide). */
    stampCell(event) {
        if (!this.armedWorkoutId) return;
        if (event.target.closest('.kd-planentry')) return;

        const cell = event.currentTarget;
        this.placeWorkout(this.armedWorkoutId, cell.dataset.week, cell.dataset.day);
    }

    // ---- Palette : glisser une carte dans une case ------------------------

    paletteListTargetConnected(el) {
        // Source en clone, jamais cible (put:false) : on glisse une carte vers une
        // cellule (même groupe que les cases). La palette est rendue une seule fois.
        this.sortables.set(el, Sortable.create(el, {
            group: { name: this.constructor.SORTABLE_GROUP, pull: 'clone', put: false },
            sort: false,
            draggable: '.kd-palettecard',
            animation: 150,
            ghostClass: 'kd-drag-ghost',
            chosenClass: 'kd-drag-chosen',
            dragClass: 'kd-drag-active',
            onEnd: (evt) => this.onPaletteDrop(evt),
        }));
    }

    paletteListTargetDisconnected(el) {
        const instance = this.sortables.get(el);
        if (instance) {
            instance.destroy();
            this.sortables.delete(el);
        }
    }

    onPaletteDrop(evt) {
        const moved = evt.item;
        const target = evt.to;
        // Pas un dépôt réel dans une case (retombé dans la palette ou ailleurs) : on
        // NE retire PAS `moved` (ce serait retirer la carte de la palette), Sortable
        // a déjà remis en place.
        if (target === evt.from || !target || target.dataset.plangridTarget !== 'cell') {
            return;
        }
        const workoutId = moved.dataset.workoutId;
        // Le serveur re-render la grille avec la vraie case : on retire la carte
        // déposée (le clone reste dans la palette).
        moved.remove();
        this.placeWorkout(workoutId, target.dataset.week, target.dataset.day);
    }

    // ---- Aperçu au survol (top-layer via Popover API) ---------------------

    showPreview(event) {
        // Pas d'aperçu pendant qu'une séance est armée (les cases sont en mode pose).
        if (this.armedWorkoutId) return;
        const entry = event.currentTarget;
        const preview = entry.querySelector('.kd-planpreview');
        if (!preview || typeof preview.showPopover !== 'function') return;

        this.hidePreview();
        try {
            preview.showPopover();
        } catch (error) {
            return;
        }

        // Positionnement manuel près de la case (le popover est en top-layer, donc
        // non rogné par l'overflow de la grille).
        const rect = entry.getBoundingClientRect();
        const pw = preview.offsetWidth;
        const ph = preview.offsetHeight;
        let left = rect.right + 8;
        if (left + pw > window.innerWidth - 8) left = rect.left - pw - 8;
        if (left < 8) left = 8;
        let top = rect.top;
        if (top + ph > window.innerHeight - 8) top = window.innerHeight - ph - 8;
        if (top < 8) top = 8;
        preview.style.left = `${left}px`;
        preview.style.top = `${top}px`;
        this.openPreview = preview;
    }

    hidePreview() {
        if (!this.openPreview) return;
        try {
            this.openPreview.hidePopover();
        } catch (error) {
            // Déjà retiré du DOM (re-render de grille) : rien à faire.
        }
        this.openPreview = null;
    }

    placeWorkout(workoutId, week, day) {
        if (!this.hasPaletteTarget || !workoutId || !week || !day) return;
        const url = this.paletteTarget.dataset.placeUrl;
        const token = this.paletteTarget.dataset.placeToken;
        if (!url) return;

        const body = new FormData();
        body.append('_token', token);
        body.append('workoutId', workoutId);
        body.append('week', week);
        body.append('day', day);

        this.postStream(url, body);
    }
}
