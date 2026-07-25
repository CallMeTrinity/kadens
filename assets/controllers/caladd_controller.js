import { Controller } from '@hotwired/stimulus';

/*
 * Modales de sélection du calendrier (remplacent les anciens dropdowns) :
 *  - « poser une séance » : ouverte par le « + » d'un jour (data-caladd-date-param),
 *    la date choisie alimente un champ caché ; chaque carte est un bouton submit
 *    (clic = pose immédiate, endpoint lean `app_scheduled_workout_place`) ;
 *  - « instancier un plan » : ouverte par le bouton en tête, carte cliquée =
 *    sélection (alimente le <select> caché du PlanInstantiationType), puis date +
 *    « Instancier ».
 *
 * Recherche / filtre d'activité / tri sont 100 % client (offline-safe). Les outils
 * (recherche, tri, filtres) vivent HORS du <form> pour qu'un Entrée n'y déclenche
 * pas une pose accidentelle ; seules les cartes/les champs sont dans le <form>.
 */
export default class extends Controller {
    static targets = [
        'workoutDialog', 'dateInput', 'dateLabel',
        'workoutList', 'workoutCard', 'workoutEmpty',
        'planDialog', 'planList', 'planCard', 'planEmpty',
        'planSelectWrap', 'planSubmit', 'planDate',
    ];

    // ----------------------------------------------------------------- Séance
    openWorkout(event) {
        const date = event.params.date;
        this.dateInputTarget.value = date;
        if (this.hasDateLabelTarget) {
            this.dateLabelTarget.textContent = this.humanDate(date);
        }
        this.resetWorkoutFilters();
        this.workoutDialogTarget.showModal();
    }

    filterWorkouts(event) {
        this.workoutQuery = (event.target.value || '').trim().toLowerCase();
        this.applyWorkoutFilter();
    }

    pickWorkoutActivity(event) {
        const btn = event.currentTarget;
        this.workoutActivity = btn.dataset.activity;
        btn.parentElement.querySelectorAll('[data-activity]').forEach((b) => {
            b.classList.toggle('kd-libfilter--on', b === btn);
        });
        this.applyWorkoutFilter();
    }

    sortWorkouts(event) {
        this.sortCards(this.workoutListTarget, this.workoutCardTargets, event.target.value);
    }

    applyWorkoutFilter() {
        const q = this.workoutQuery || '';
        const act = this.workoutActivity || 'all';
        let visible = 0;
        this.workoutCardTargets.forEach((card) => {
            const matchText = !q || (card.dataset.filterText || '').includes(q);
            const matchAct = act === 'all' || (card.dataset.activity || '').split(' ').includes(act);
            const show = matchText && matchAct;
            card.hidden = !show;
            if (show) visible += 1;
        });
        if (this.hasWorkoutEmptyTarget) {
            this.workoutEmptyTarget.hidden = visible > 0;
        }
    }

    resetWorkoutFilters() {
        this.workoutQuery = '';
        this.workoutActivity = 'all';
        const dlg = this.workoutDialogTarget;
        const search = dlg.querySelector('input[type="search"]');
        if (search) search.value = '';
        dlg.querySelectorAll('[data-activity]').forEach((b) => {
            b.classList.toggle('kd-libfilter--on', b.dataset.activity === 'all');
        });
        const sort = dlg.querySelector('.kd-picker__sort');
        if (sort) sort.selectedIndex = 0;
        this.applyWorkoutFilter();
        this.sortCards(this.workoutListTarget, this.workoutCardTargets, 'title');
    }

    // -------------------------------------------------------------------- Plan
    openPlan() {
        if (this.hasPlanDateTarget && !this.planDateTarget.value) {
            this.planDateTarget.value = new Date().toISOString().slice(0, 10);
        }
        this.planDialogTarget.showModal();
    }

    filterPlans(event) {
        const q = (event.target.value || '').trim().toLowerCase();
        let visible = 0;
        this.planCardTargets.forEach((card) => {
            const show = !q || (card.dataset.filterText || '').includes(q);
            card.hidden = !show;
            if (show) visible += 1;
        });
        if (this.hasPlanEmptyTarget) {
            this.planEmptyTarget.hidden = visible > 0;
        }
    }

    sortPlans(event) {
        this.sortCards(this.planListTarget, this.planCardTargets, event.target.value);
    }

    selectPlan(event) {
        const card = event.currentTarget;
        const select = this.planSelectWrapTarget.querySelector('select');
        if (select) select.value = card.dataset.planId;
        this.planCardTargets.forEach((c) => {
            c.classList.toggle('kd-palettecard--armed', c === card);
        });
        if (this.hasPlanSubmitTarget) this.planSubmitTarget.disabled = false;
    }

    // ------------------------------------------------------------------ Commun
    close(event) {
        event.target.closest('dialog')?.close();
    }

    backdrop(event) {
        if (event.target.tagName === 'DIALOG') event.target.close();
    }

    /** Réordonne les cartes dans leur conteneur (title=alpha, autres=numérique). */
    sortCards(container, cards, key) {
        if (!container) return;
        const attr = { title: 'sortTitle', duration: 'sortDuration', weeks: 'sortWeeks' }[key] || 'sortTitle';
        const numeric = key === 'duration' || key === 'weeks';
        [...cards]
            .sort((a, b) => {
                const va = a.dataset[attr] || '';
                const vb = b.dataset[attr] || '';
                return numeric ? Number(va) - Number(vb) : va.localeCompare(vb);
            })
            .forEach((c) => container.appendChild(c));
    }

    humanDate(iso) {
        const d = new Date(`${iso}T00:00:00`);
        return d.toLocaleDateString('fr-FR', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
    }
}
