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
 *   - bloc actif : cible du « + » de la bibliothèque
 *   - glisser une carte de bibliothèque -> l'ajouter dans un bloc (quick-add)
 *   - glisser une ligne d'exercice -> la réordonner / changer de bloc (reorder)
 *   - stepper de tours, dépliage des paramètres, filtre de bibliothèque (client)
 */
export default class extends Controller {
    static targets = ['block', 'items', 'library', 'libcard', 'search', 'quickAddForm', 'reorderForm'];

    static SORTABLE_GROUP = 'kd-exercises';

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
        this.element.addEventListener('submit', this.onSubmit);
        this.applyLibFilter();
    }

    disconnect() {
        this.element.removeEventListener('submit', this.onSubmit);
    }

    // ---- Soumission dynamique (fetch + Turbo Stream appliqué à la main) -----

    /**
     * Intercepte toute soumission de formulaire du compositeur. On envoie la
     * requête en `fetch` (format stream) et on applique le flux renvoyé, sans
     * recharger. Le formulaire d'en-tête (titre/description) est laissé au
     * comportement natif : c'est une sauvegarde volontaire qui redirige.
     */
    async onSubmit(event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement)) return;
        if (form.classList.contains('kd-composer__head')) return;

        event.preventDefault();

        try {
            const response = await fetch(form.action, {
                method: (form.getAttribute('method') || 'post').toUpperCase(),
                body: new FormData(form),
                headers: { Accept: 'text/vnd.turbo-stream.html' },
                credentials: 'same-origin',
            });
            const html = await response.text();
            renderStreamMessage(html);
        } catch (error) {
            // Repli : en cas d'échec réseau, on recharge la page d'édition.
            console.error('Composer submit failed:', error);
            window.location.reload();
        }
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

    // ---- Ajout depuis la bibliothèque (bouton +) --------------------------

    quickAdd(event) {
        const block = this.activeBlock();
        if (!block) return;
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
            filter: '.kd-libx__add',   // ne pas démarrer un drag depuis le bouton +
            preventOnFilter: false,    // ... mais laisser le clic passer
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
            handle: '.kd-cexo__handle',
            draggable: '.kd-cexo',
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
        const clone = evt.item;
        const target = evt.to;
        // Retombé dans la bibliothèque, ou ailleurs qu'un conteneur de bloc : rien.
        if (target === evt.from || !target.matches('[data-composer-target="items"]')) {
            clone.remove();
            return;
        }
        const exerciseId = clone.dataset.exerciseId;
        const blockId = target.dataset.blockId;
        const afterId = this.prevPrescribedId(clone);
        // Le serveur re-render le bloc avec la vraie ligne : on retire le clone.
        clone.remove();
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

    toggleParams(event) {
        const row = event.currentTarget.closest('.kd-cexo');
        const params = row.querySelector('.kd-cexo__params');
        if (!params) return;
        params.hidden = !params.hidden;
        row.classList.toggle('kd-cexo--open', !params.hidden);
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
