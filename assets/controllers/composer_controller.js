import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';

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
 *   - bloc actif : cible du « + » de la bibliothèque
 *   - glisser une carte de bibliothèque -> l'ajouter dans un bloc (quick-add)
 *   - glisser une ligne d'exercice -> la réordonner / changer de bloc (reorder)
 *   - stepper de tours, dépliage des paramètres, filtre de bibliothèque (client)
 */
export default class extends Controller {
    static targets = ['block', 'libcard', 'search', 'quickAddForm', 'reorderForm'];

    connect() {
        this.libQuery = '';
        this.libActivity = 'all';
        this.activeBlockId = null;
        this.drag = null;
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

    // ---- Ajout depuis la bibliothèque -------------------------------------

    quickAdd(event) {
        const block = this.activeBlock();
        if (!block) return;
        this.submitQuickAdd(event.currentTarget.dataset.exerciseId, block.dataset.blockId);
    }

    submitQuickAdd(exerciseId, blockId) {
        if (!this.hasQuickAddFormTarget || !exerciseId || !blockId) return;
        const form = this.quickAddFormTarget;
        form.querySelector('[name="exerciseId"]').value = exerciseId;
        form.querySelector('[name="blockId"]').value = blockId;
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

    // ---- Glisser-déposer ---------------------------------------------------

    libDragStart(event) {
        this.drag = { mode: 'lib', exerciseId: event.currentTarget.dataset.exerciseId };
        event.dataTransfer.effectAllowed = 'copy';
        event.dataTransfer.setData('text/plain', 'lib');
    }

    exoDragStart(event) {
        event.stopPropagation();
        const row = event.currentTarget;
        this.drag = { mode: 'exo', prescribedId: row.dataset.prescribedId };
        row.classList.add('kd-cexo--dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', 'exo');
    }

    dragEnd() {
        this.drag = null;
        this.element.querySelectorAll('.kd-cblock--drop, .kd-cexo--dragging, .kd-cexo--dropafter')
            .forEach((el) => el.classList.remove('kd-cblock--drop', 'kd-cexo--dragging', 'kd-cexo--dropafter'));
    }

    blockDragOver(event) {
        if (!this.drag) return;
        event.preventDefault();
        event.currentTarget.classList.add('kd-cblock--drop');
    }

    blockDragLeave(event) {
        event.currentTarget.classList.remove('kd-cblock--drop', 'kd-cexo--dropafter');
    }

    exoDragOver(event) {
        if (!this.drag) return;
        event.preventDefault();
        event.stopPropagation();
        event.currentTarget.classList.add('kd-cexo--dropafter');
    }

    blockDrop(event) {
        if (!this.drag) return;
        event.preventDefault();
        const block = event.currentTarget;
        // Dépôt dans le corps du bloc (hors ligne) -> à la fin du bloc.
        const rows = block.querySelectorAll('[data-composer-target="exo"]');
        const afterId = rows.length ? rows[rows.length - 1].dataset.prescribedId : 0;
        this.performDrop(block.dataset.blockId, afterId);
    }

    exoDrop(event) {
        if (!this.drag) return;
        event.preventDefault();
        event.stopPropagation();
        const row = event.currentTarget;
        this.performDrop(row.dataset.blockId, row.dataset.prescribedId);
    }

    performDrop(targetBlockId, afterId) {
        const drag = this.drag;
        this.dragEnd();
        if (!drag) return;

        // Soumettre un formulaire pendant le cycle de l'événement `drop` est ignoré
        // par le navigateur tant que le glisser n'est pas complètement terminé (la
        // 1re action « ne faisait rien », la 2e passait). On diffère au tick suivant.
        if (drag.mode === 'lib') {
            this.defer(() => this.submitQuickAdd(drag.exerciseId, targetBlockId));
        } else if (drag.mode === 'exo') {
            // Dépôt sur soi-même = aucun changement.
            if (String(afterId) === String(drag.prescribedId)) return;
            this.defer(() => this.submitReorder(drag.prescribedId, targetBlockId, afterId));
        }
    }

    defer(fn) {
        window.setTimeout(fn, 0);
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
