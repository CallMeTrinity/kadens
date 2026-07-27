import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';
import Sortable from 'sortablejs';

/*
 * Compositeur de séance (éditeur). La persistance reste 100 % côté serveur :
 * chaque mutation poste un formulaire qui renvoie un Turbo Stream mettant à jour
 * #workout-blocks.
 *
 * Point clé de fiabilité : la <section> porte `data-turbo="false"`, donc Turbo
 * n'intercepte AUCUN formulaire du compositeur. On applique nous-mêmes la
 * réponse. `onSubmit` capte toute soumission (bouton réel OU requestSubmit des
 * formulaires cachés), fait un `fetch` explicite en demandant le format stream,
 * puis `renderStreamMessage` applique le <turbo-stream> au DOM. Aucune dépendance
 * au routage de formulaire de Turbo (c'est lui qui échouait sur les formulaires
 * hors conteneur).
 *
 * Glisser-déposer : délégué à SortableJS (tactile + retour visuel + tri
 * inter-listes), il ne fait QUE la mécanique client. Sur dépôt, on remplit les
 * formulaires cachés et on soumet (quick-add / reorder). Comme #workout-blocks
 * est re-rendu à chaque mutation, chaque conteneur d'exercices détruit son
 * instance Sortable à la déconnexion et la recrée à la connexion (via les hooks
 * de cible Stimulus itemsTargetConnected/Disconnected). Groupe partagé
 * « kd-exercises » : la bibliothèque est source en clone (pull:'clone', put:false),
 * les blocs sont sources ET cibles.
 *
 * PRISE DU DRAG : il n'y a plus de poignée. La carte entière est saisissable, et
 * les deux gestes se départagent par le TEMPS (`delay` + `delayOnTouchOnly`) :
 * un tap déplie les paramètres, un appui long soulève la carte. C'est ce qui rend
 * une poignée de 15px inutile — au doigt elle était intenable, et elle volait de
 * la largeur au nom de l'exercice. Au pointeur fin, `delayOnTouchOnly` remet le
 * délai à zéro : la souris garde le drag immédiat, l'icône de préhension n'est
 * plus qu'une affordance. `filter` protège ce qui doit rester cliquable/saisissable
 * dans la carte (le menu, le panneau de paramètres ouvert).
 *
 *   - bloc actif : cible de l'ajout depuis la bibliothèque
 *   - taper une carte de bibliothèque -> l'ajouter au bloc actif (quick-add)
 *   - glisser une carte de bibliothèque -> l'ajouter dans un bloc au point de dépôt
 *   - glisser une ligne d'exercice -> la réordonner / changer de bloc (reorder)
 *   - stepper de tours, dépliage des paramètres, filtre de bibliothèque (client)
 *   - sous 900px la bibliothèque est une feuille : `openLib` / `closeLib`
 */
export default class extends Controller {
    static targets = ['block', 'items', 'library', 'libcard', 'search', 'quickAddForm', 'reorderForm'];

    static SORTABLE_GROUP = 'kd-exercises';

    // Appui long avant de soulever une carte, au doigt uniquement. Assez court pour
    // ne pas se faire attendre, assez long pour qu'un tap reste un tap. Le seuil de
    // mouvement laisse le scroll partir en premier : bouger avant la fin du délai
    // annule le drag, on défile normalement.
    static TOUCH_DRAG_DELAY = 320;
    static TOUCH_DRAG_THRESHOLD = 8;

    // initialize() tourne AVANT les callbacks xTargetConnected (eux-mêmes avant
    // connect()). L'état lu par ces callbacks doit donc être posé ici, sinon
    // this.sortables / this.activeBlockId sont undefined au premier target.
    initialize() {
        this.libQuery = '';
        this.libActivity = 'all';
        this.activeBlockId = null;
        this.sortables = new WeakMap();
    }

    connect() {
        this.onSubmit = this.onSubmit.bind(this);
        this.onKey = (event) => {
            if (event.key === 'Escape') this.closeLib();
        };
        // Fermeture au clic extérieur. Écoutée sur le DOCUMENT et non sur le voile :
        // le voile ne recouvre que ce qui est sous la feuille dans l'ordre de
        // peinture, un clic sur un calque au-dessus (menu, en-tête) ne le traversait
        // pas. Ici, une seule autorité : est-on hors du panneau, oui ou non.
        this.onOutside = (event) => {
            if (!this.element.classList.contains('kd-libsheet--open')) return;
            // Le clic d'OUVERTURE remonte jusqu'ici dans la même phase de bouillonnement,
            // la classe étant déjà posée : sans cette garde, la feuille se refermerait
            // aussitôt ouverte.
            if (event.target.closest('[data-action*="composer#openLib"]')) return;
            if (event.target.closest('.kd-composer__lib')) return;
            this.closeLib();
        };
        this.element.addEventListener('submit', this.onSubmit);
        document.addEventListener('keydown', this.onKey);
        document.addEventListener('click', this.onOutside);
        this.applyLibFilter();
    }

    disconnect() {
        this.element.removeEventListener('submit', this.onSubmit);
        document.removeEventListener('keydown', this.onKey);
        document.removeEventListener('click', this.onOutside);
        // Une navigation Turbo pendant que la feuille est ouverte laisserait la page
        // suivante figée : l'état vit sur <body>, il ne part pas avec le contrôleur.
        document.body.classList.remove('kd-noscroll');
    }

    // ---- Soumission dynamique (fetch + Turbo Stream appliqué à la main) -----

    /**
     * Intercepte toute soumission de formulaire du compositeur (en-tête inclus, mais
     * le titre/description passent désormais par l'édition en ligne, hors de cette
     * section). On envoie la requête en `fetch` (format stream) et on applique le flux
     * renvoyé, sans recharger.
     *
     * L'enregistrement des paramètres d'un exercice est automatique (sur `change`).
     * Ces formulaires vivent dans `.kd-cexo__params` : le serveur renvoie alors un
     * stream CIBLÉ qui ne remplace que la ligne de résumé de l'exercice, pas tout
     * #workout-blocks. Le formulaire reste donc intact — panneau ouvert, curseur en
     * place — et on ne touche PAS au focus (sinon on déplacerait le curseur du
     * champ suivant que l'utilisateur est en train de remplir). Pour les AUTRES
     * mutations (bloc, ajout, réordonnancement…), #workout-blocks est reconstruit :
     * on mémorise le champ actif pour le lui rendre après le re-render.
     */
    async onSubmit(event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;

        event.preventDefault();

        const isParamSave = form.closest('.kd-cexo__params') !== null;

        const active = document.activeElement;
        const activeName = !isParamSave && active && active.name ? active.name : null;
        const caret = active && typeof active.selectionStart === 'number' ? active.selectionStart : null;

        try {
            const response = await fetch(form.action, {
                method: (form.getAttribute('method') || 'post').toUpperCase(),
                body: new FormData(form),
                headers: { Accept: 'text/vnd.turbo-stream.html' },
                credentials: 'same-origin',
            });
            renderStreamMessage(await response.text());
            // Le flux réécrit des lignes dont le serveur ignore l'état déplié. rAF :
            // `renderStreamMessage` rend de façon asynchrone, le DOM n'est pas encore
            // à jour au retour de l'appel (même raison que dans restoreFocus).
            requestAnimationFrame(() => this.syncExpanded());
            if (!isParamSave) this.restoreFocus(activeName, caret);
        } catch (error) {
            console.error('Composer submit failed:', error);
            // Sur un save de paramètre, ne PAS recharger : ça effacerait la saisie
            // en cours. On recharge seulement pour les mutations structurelles.
            if (!isParamSave) window.location.reload();
        }
    }

    /**
     * Rend le focus au champ qui vient d'auto-enregistrer (retrouvé par son nom,
     * unique grâce aux formulaires nommés). S'il vit dans un panneau de paramètres,
     * on ré-ouvre ce panneau (fermé par le re-render). rAF : laisse le DOM du stream
     * se poser avant de refocaliser.
     */
    restoreFocus(name, caret) {
        if (!name) return;
        requestAnimationFrame(() => {
            const el = this.element.querySelector(`[name="${CSS.escape(name)}"]`);
            if (!el) return;

            const row = el.closest('.kd-cexo__params')?.closest('.kd-cexo');
            if (row) this.setParamsOpen(row, true);

            el.focus();
            if (caret !== null && typeof el.setSelectionRange === 'function') {
                try {
                    el.setSelectionRange(caret, caret);
                } catch (error) {
                    // Certains types d'input n'acceptent pas setSelectionRange : sans importance.
                }
            }
        });
    }

    // ---- Bloc actif --------------------------------------------------------

    blockTargetConnected(el) {
        if (this.activeBlockId === null) {
            this.activeBlockId = el.dataset.blockId;
        }
        this.refreshActive();
    }

    activateBlock(event) {
        const block = event.currentTarget;
        this.activeBlockId = block.dataset.blockId;
        this.refreshActive();
    }

    refreshActive() {
        this.blockTargets.forEach((el) => {
            el.classList.toggle('kd-cblock--active', el.dataset.blockId === this.activeBlockId);
        });
    }

    activeBlock() {
        return this.blockTargets.find((el) => el.dataset.blockId === this.activeBlockId)
            || this.blockTargets[0]
            || null;
    }

    // ---- Ajout depuis la bibliothèque (tap sur une carte) ------------------

    /** La carte entière est le déclencheur : l'exercice part dans le bloc actif, et
     *  la feuille se referme (sur écran large, la classe n'était pas posée). */
    quickAdd(event) {
        const block = this.activeBlock();
        if (!block) return;
        this.closeLib();
        this.submitQuickAdd(event.currentTarget.dataset.exerciseId, block.dataset.blockId);
    }

    submitQuickAdd(exerciseId, blockId, afterId) {
        if (!this.hasQuickAddFormTarget || !exerciseId || !blockId) return;
        const form = this.quickAddFormTarget;
        form.querySelector('[name="exerciseId"]').value = exerciseId;
        form.querySelector('[name="blockId"]').value = blockId;
        // afterId absent -> ajout en fin de bloc (bouton +). afterId défini (0 = tête,
        // sinon id du voisin précédent) -> placement précis du glisser-déposer.
        form.querySelector('[name="afterId"]').value =
            (afterId === undefined || afterId === null) ? '' : String(afterId);
        form.requestSubmit();
    }

    submitReorder(prescribedId, targetBlockId, afterId) {
        if (!this.hasReorderFormTarget) return;
        const form = this.reorderFormTarget;
        form.querySelector('[name="prescribedId"]').value = prescribedId;
        form.querySelector('[name="targetBlockId"]').value = targetBlockId;
        form.querySelector('[name="afterId"]').value = afterId;
        form.requestSubmit();
    }

    // ---- Glisser-déposer (SortableJS) -------------------------------------

    /** Bibliothèque : source uniquement, en clone. Connectée une seule fois
     *  (hors #workout-blocks, jamais re-rendue). */
    libraryTargetConnected(el) {
        this.sortables.set(el, Sortable.create(el, {
            group: { name: this.constructor.SORTABLE_GROUP, pull: 'clone', put: false },
            sort: false,
            draggable: '.kd-libx',
            delay: this.constructor.TOUCH_DRAG_DELAY,
            delayOnTouchOnly: true,
            touchStartThreshold: this.constructor.TOUCH_DRAG_THRESHOLD,
            animation: 150,
            ghostClass: 'kd-drag-ghost',
            chosenClass: 'kd-drag-chosen',
            dragClass: 'kd-drag-active',
            onEnd: (evt) => this.onLibDrop(evt),
        }));
    }

    libraryTargetDisconnected(el) {
        this.destroySortable(el);
    }

    /** Chaque conteneur d'exercices d'un bloc : source ET cible. Re-rendu à
     *  chaque mutation, donc (dé)connecté en boucle. */
    itemsTargetConnected(el) {
        this.sortables.set(el, Sortable.create(el, {
            group: { name: this.constructor.SORTABLE_GROUP, pull: true, put: true },
            draggable: '.kd-cexo',
            // Pas de `handle` : toute la carte est la prise (cf. en-tête de fichier).
            // `filter` exclut ce qui ne doit jamais soulever la carte — le menu, et
            // le panneau de paramètres déplié, où l'on saisit du texte.
            filter: '.kd-kebab, .kd-cexo__params',
            preventOnFilter: false,
            delay: this.constructor.TOUCH_DRAG_DELAY,
            delayOnTouchOnly: true,
            touchStartThreshold: this.constructor.TOUCH_DRAG_THRESHOLD,
            animation: 150,
            ghostClass: 'kd-drag-ghost',
            chosenClass: 'kd-drag-chosen',
            dragClass: 'kd-drag-active',
            onEnd: (evt) => this.onExoDrop(evt),
        }));
    }

    itemsTargetDisconnected(el) {
        this.destroySortable(el);
    }

    destroySortable(el) {
        const instance = this.sortables.get(el);
        if (instance) {
            instance.destroy();
            this.sortables.delete(el);
        }
    }

    /** Dépôt d'une carte de bibliothèque (clone) dans un bloc -> quick-add au
     *  point de dépôt. */
    onLibDrop(evt) {
        // En mode clone, `evt.item` est la carte d'origine déplacée par Sortable,
        // et `evt.clone` reste dans la bibliothèque à sa place.
        const moved = evt.item;
        const target = evt.to;
        // Retombé dans la bibliothèque, ou ailleurs qu'un conteneur de bloc : pas de
        // dépôt réel. On NE touche PAS à `moved` : le retirer effacerait la carte de
        // la bibliothèque (il fallait recharger pour la revoir). Sortable a déjà remis
        // les choses en place.
        if (target === evt.from || !target || !target.matches('[data-composer-target="items"]')) {
            return;
        }
        const exerciseId = moved.dataset.exerciseId;
        const blockId = target.dataset.blockId;
        const afterId = this.prevPrescribedId(moved);
        // Le serveur re-render le bloc avec la vraie ligne : on retire la carte
        // déposée (le clone reste dans la bibliothèque).
        moved.remove();
        this.submitQuickAdd(exerciseId, blockId, afterId);
    }

    /** Dépôt d'une ligne d'exercice existante -> reorder (intra ou inter-blocs). */
    onExoDrop(evt) {
        const row = evt.item;
        const target = evt.to;
        // Pas de déplacement réel : on n'envoie rien.
        if (target === evt.from && evt.oldIndex === evt.newIndex) return;

        const prescribedId = row.dataset.prescribedId;
        const targetBlockId = target.dataset.blockId;
        const afterId = this.prevPrescribedId(row);
        this.submitReorder(prescribedId, targetBlockId, afterId);
    }

    /** Id de l'exercice prescrit précédent dans le conteneur (0 si en tête).
     *  Ignore le placeholder « déposez ici » et l'élément lui-même. */
    prevPrescribedId(el) {
        let sib = el.previousElementSibling;
        while (sib) {
            if (sib.matches('.kd-cexo') && sib.dataset.prescribedId) {
                return sib.dataset.prescribedId;
            }
            sib = sib.previousElementSibling;
        }
        return 0;
    }

    // ---- Petits contrôles inline ------------------------------------------

    roundsInc(event) {
        this.stepRounds(event.currentTarget, 1);
    }

    roundsDec(event) {
        this.stepRounds(event.currentTarget, -1);
    }

    stepRounds(button, delta) {
        const input = button.closest('.kd-cblock__rounds').querySelector('input');
        if (!input) return;
        const next = Math.max(1, (parseInt(input.value, 10) || 1) + delta);
        input.value = next;
        input.closest('form').requestSubmit();
    }

    submitForm(event) {
        event.target.closest('form').requestSubmit();
    }

    /** Taper une carte d'exercice la déplie (il n'y a plus de bouton dédié). */
    toggleParams(event) {
        const row = event.currentTarget.closest('.kd-cexo');
        if (!row) return;
        const params = row.querySelector('.kd-cexo__params');
        if (!params) return;
        this.setParamsOpen(row, params.hidden);
    }

    /**
     * Source unique de l'état déplié. Il est porté par `.kd-cexo--open` sur la CARTE
     * (qui survit au stream ciblé remplaçant la seule ligne de résumé) ; le bouton,
     * lui, est re-rendu à `aria-expanded="false"` par le serveur, d'où la resynchro
     * après chaque flux (voir syncExpanded).
     */
    setParamsOpen(row, open) {
        const params = row.querySelector('.kd-cexo__params');
        if (!params) return;
        params.hidden = !open;
        row.classList.toggle('kd-cexo--open', open);
        row.querySelector('.kd-cexo__main')?.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    /** Réaligne `aria-expanded` sur la classe après un re-render (le serveur ne sait
     *  pas quelles cartes étaient dépliées). */
    syncExpanded() {
        this.element.querySelectorAll('.kd-cexo').forEach((row) => {
            row.querySelector('.kd-cexo__main')
                ?.setAttribute('aria-expanded', row.classList.contains('kd-cexo--open') ? 'true' : 'false');
        });
    }

    // ---- Bibliothèque en feuille (sous 900px) ------------------------------

    /**
     * Ouvre la bibliothèque sur un bloc donné. Un seul geste pour les deux formes :
     * sur écran large la classe n'a aucun effet (les règles de feuille sont dans une
     * `@media`), et c'est le focus donné à la recherche qui amène l'utilisateur à la
     * colonne de gauche.
     */
    openLib(event) {
        const blockId = event.currentTarget.dataset.blockId;
        if (blockId) {
            this.activeBlockId = blockId;
            this.refreshActive();
        }
        this.element.classList.add('kd-libsheet--open');
        // Fige la page derrière la feuille. La classe est posée dans les deux cas :
        // c'est le CSS qui la neutralise au-dessus de 900px, où il n'y a pas de
        // feuille et où bloquer le défilement serait un bug.
        document.body.classList.add('kd-noscroll');
        if (this.hasSearchTarget) {
            this.searchTarget.focus({ preventScroll: false });
        }
    }

    closeLib() {
        this.element.classList.remove('kd-libsheet--open');
        document.body.classList.remove('kd-noscroll');
    }

    // ---- Filtre de bibliothèque (client, offline-safe) --------------------

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
        this.libcardTargets.forEach((card) => {
            const matchText = this.libQuery === '' || (card.dataset.filterText || '').toLowerCase().includes(this.libQuery);
            const matchAct = this.libActivity === 'all' || card.dataset.activity === this.libActivity;
            card.hidden = !(matchText && matchAct);
        });
    }
}
