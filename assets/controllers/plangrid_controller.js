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
 *    PRISE DU DRAG : il n'y a plus de poignée (13px, intenable au doigt). La carte
 *    entière est saisissable, et les deux gestes se départagent par le TEMPS
 *    (`delay` + `delayOnTouchOnly`) : un tap ouvre l'édition rapide, un appui long
 *    soulève la carte. Au pointeur fin le délai retombe à zéro, la souris garde son
 *    drag immédiat. `filter` protège ce qui doit rester utilisable dans la carte
 *    (le menu kebab, la note en édition en ligne). Le repli du drag reste le
 *    « Déplacer vers » du menu, seul chemin au clavier et sans JS.
 *
 *    Sous 900px, la palette n'est plus une colonne mais une FEUILLE (`openLib` /
 *    `closeLib`), ouverte par le « + » d'un jour — qui désigne du même geste la case
 *    visée : taper une carte y pose la séance, sans passer par le mode tampon.
 *
 * 2. Édition rapide : cliquer une séance ouvre une mini-modale. On charge en `fetch`
 *    le panneau de ses exercices (`app_workout_quick_panel`) dans #quick-panel, où
 *    chaque paramètre (reps/séries/repos…) est éditable. Enregistrer un exercice
 *    poste en `fetch` (format stream) et met à jour #quick-panel, sans recharger.
 *    La modale porte `data-turbo="false"` : Turbo n'intercepte rien, on applique
 *    nous-mêmes les streams (comme le compositeur). Le lien « Édition complète »
 *    renvoie au compositeur pour la structure (blocs, ordre). À la fermeture, si un
 *    enregistrement a eu lieu, on redemande le stream de la trame (`gridUrl`) pour
 *    refléter durée/volumes sur les cases : jamais de rechargement de page, qui
 *    ferait remonter en haut au moindre ajustement.
 */
export default class extends Controller {
    static targets = ['cell', 'dialog', 'panel', 'fullLink', 'palette', 'paletteList', 'palettecard',
        'sheet', 'search', 'targetLabel'];

    static values = { gridUrl: String };

    static SORTABLE_GROUP = 'kd-plan-workouts';

    // Appui long avant de soulever une carte, au doigt uniquement (mêmes valeurs que
    // le compositeur : assez court pour ne pas se faire attendre, assez long pour
    // qu'un tap reste un tap ; bouger avant la fin du délai laisse partir le scroll).
    static TOUCH_DRAG_DELAY = 320;
    static TOUCH_DRAG_THRESHOLD = 8;

    initialize() {
        this.sortables = new WeakMap();
        this.dirty = false;
        // Palette : filtre client + mode tampon (une séance « armée » se pose au clic
        // sur les cases). L'état survit aux re-render de #plan-grid (porté ici).
        this.libQuery = '';
        this.libActivity = 'all';
        this.armedWorkoutId = null;
        this.armedCard = null;
        // Case visée par la palette ouverte depuis un « + ». Non nulle = taper une
        // carte pose directement, sans armer.
        this.targetWeek = null;
        this.targetDay = null;
    }

    connect() {
        // Intercepte les soumissions des formulaires du panneau d'édition rapide.
        // Les formulaires de la trame (poser, déplacer, retirer, semaines) sont hors
        // #quick-panel : la modale porte `data-turbo="false"`, pas la section — c'est
        // donc Turbo qui les soumet et applique leur stream de #plan-grid, avec sa
        // gestion des réponses en erreur. Sans JS, ils postent normalement et le
        // serveur redirige vers l'éditeur.
        this.onPanelSubmit = this.onPanelSubmit.bind(this);
        this.element.addEventListener('submit', this.onPanelSubmit);
        // Sur le DOCUMENT et non sur la section : une fois la palette ouverte en
        // feuille, le focus peut être n'importe où, et Escape doit rester une sortie.
        this.onKeydown = this.onKeydown.bind(this);
        document.addEventListener('keydown', this.onKeydown);
        // Fermeture au clic extérieur, écoutée sur le document pour la même raison
        // que dans le compositeur : une seule autorité, est-on hors du panneau ou non.
        this.onOutside = (event) => {
            if (!this.hasSheetTarget) return;
            if (!this.sheetTarget.classList.contains('kd-libsheet--open')) return;
            // Le clic d'OUVERTURE remonte jusqu'ici dans la même phase de bouillonnement,
            // la classe étant déjà posée : sans cette garde, la feuille se refermerait
            // aussitôt ouverte.
            if (event.target.closest('[data-action*="plangrid#openLib"]')) return;
            if (event.target.closest('.kd-composer__lib')) return;
            this.closeLib();
        };
        document.addEventListener('click', this.onOutside);
        this.applyLibFilter();
    }

    disconnect() {
        this.element.removeEventListener('submit', this.onPanelSubmit);
        document.removeEventListener('keydown', this.onKeydown);
        document.removeEventListener('click', this.onOutside);
        // Une navigation Turbo pendant que la feuille est ouverte laisserait la page
        // suivante figée : l'état vit sur <body>, il ne part pas avec le contrôleur.
        document.body.classList.remove('kd-noscroll');
    }

    onKeydown(event) {
        if (event.key !== 'Escape') return;
        this.disarm();
        this.closeLib();
    }

    // ---- Glisser-déposer ---------------------------------------------------

    cellTargetConnected(el) {
        this.sortables.set(el, Sortable.create(el, {
            group: this.constructor.SORTABLE_GROUP,
            draggable: '.kd-planentry',
            // Pas de `handle` : toute la carte est la prise (cf. en-tête de fichier).
            // `filter` exclut ce qui ne doit jamais la soulever — le menu, et la note
            // en édition en ligne, où l'on saisit du texte.
            filter: '.kd-kebab, .kd-inlineedit',
            preventOnFilter: false,
            delay: this.constructor.TOUCH_DRAG_DELAY,
            delayOnTouchOnly: true,
            touchStartThreshold: this.constructor.TOUCH_DRAG_THRESHOLD,
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
        // Un enregistrement change la durée estimée affichée sur la case et les
        // volumes de la semaine : on re-rend la trame seule, sans toucher au reste
        // de la page (donc sans perdre la position de défilement).
        el.addEventListener('close', () => {
            if (this.dirty) this.refreshGrid();
        });
    }

    /** Redemande le stream de #plan-grid (route GET, aucune mutation). */
    async refreshGrid() {
        this.dirty = false;
        if (!this.hasGridUrlValue) return;

        try {
            const response = await fetch(this.gridUrlValue, {
                headers: { Accept: 'text/vnd.turbo-stream.html' },
                credentials: 'same-origin',
            });
            renderStreamMessage(await response.text());
        } catch (error) {
            console.error('Plan grid refresh failed:', error);
        }
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

    // ---- Palette en feuille (sous 900px) ----------------------------------

    /**
     * Ouvre la palette SUR une case. Un seul geste pour les deux formes : sur écran
     * large la classe n'a aucun effet de calque (les règles de feuille sont dans une
     * `@media`), c'est le focus donné à la recherche qui amène à la colonne de gauche
     * — mais la case visée est mémorisée dans les deux cas, et le prochain tap sur
     * une carte y pose la séance.
     */
    openLib(event) {
        const button = event.currentTarget;
        this.targetWeek = button.dataset.week || null;
        this.targetDay = button.dataset.day || null;
        // Viser une case exclut le mode tampon : deux intentions de pose
        // concurrentes rendraient le prochain clic imprévisible.
        this.disarm();
        this.showTargetLabel(button.dataset.cellLabel || '');

        if (this.hasSheetTarget) this.sheetTarget.classList.add('kd-libsheet--open');
        // Fige la page derrière la feuille. La classe est posée dans les deux cas :
        // c'est le CSS qui la neutralise au-dessus de 900px, où il n'y a pas de
        // feuille et où bloquer le défilement serait un bug.
        document.body.classList.add('kd-noscroll');
        if (this.hasSearchTarget) this.searchTarget.focus({ preventScroll: false });
    }

    closeLib() {
        if (this.hasSheetTarget) this.sheetTarget.classList.remove('kd-libsheet--open');
        document.body.classList.remove('kd-noscroll');
        this.targetWeek = null;
        this.targetDay = null;
        this.showTargetLabel('');
    }

    /** Rappelle la case visée en tête de palette (la feuille masque la trame). */
    showTargetLabel(label) {
        if (!this.hasTargetLabelTarget) return;
        this.targetLabelTarget.textContent = label ? `Poser dans ${label}` : '';
        this.targetLabelTarget.hidden = label === '';
    }

    // ---- Palette : mode tampon (armer puis cliquer les cases) -------------

    armWorkout(event) {
        const card = event.currentTarget;
        const id = card.dataset.workoutId;
        if (!id) return;

        // Palette ouverte sur une case : la carte y pose directement. Au doigt, le
        // mode tampon demanderait de refermer la feuille puis de retrouver la case.
        if (this.targetWeek && this.targetDay) {
            const week = this.targetWeek;
            const day = this.targetDay;
            this.closeLib();
            this.placeWorkout(id, week, day);
            return;
        }

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
            delay: this.constructor.TOUCH_DRAG_DELAY,
            delayOnTouchOnly: true,
            touchStartThreshold: this.constructor.TOUCH_DRAG_THRESHOLD,
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
        // Souris uniquement : au doigt, un tap émet un `mouseenter` synthétique
        // qui ouvrirait un popover `manual` que plus rien ne referme.
        if (!matchMedia('(hover: hover) and (pointer: fine)').matches) return;
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
