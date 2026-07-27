import { Controller } from '@hotwired/stimulus';

/*
 * Modale réutilisable, adossée à l'élément natif <dialog>. Purement client :
 * aucun AJAX, le contenu (formulaires) est déjà dans la page — cohérent avec la
 * discipline « pages auto-suffisantes / cachables offline ». Sans JS, le
 * <dialog> reste dans le flux et ses formulaires restent postables.
 *
 * Usage :
 *   <div data-controller="dialog">
 *     <button data-action="dialog#open">Ouvrir</button>
 *     <dialog data-dialog-target="dialog" data-action="click->dialog#backdrop">
 *       ... <button data-action="dialog#close">Fermer</button> ...
 *     </dialog>
 *   </div>
 */
export default class extends Controller {
    static targets = ['dialog'];

    open() {
        this.dialogTarget.showModal();
    }

    /*
     * Variante « souris seulement », posée sur un lien : ouvre la modale à la
     * place de la navigation quand le pointeur est fin, et laisse simplement le
     * lien suivre son cours au doigt.
     *
     * C'est de l'amélioration progressive, pas une bifurcation : le HTML de base
     * est un vrai lien (clavier, clic du milieu, sans JS), et la modale n'est
     * qu'un raccourci desktop. Sur téléphone, une modale d'édition dans une case
     * de calendrier est intenable — on va sur la page.
     */
    openFine(event) {
        if (!matchMedia('(hover: hover) and (pointer: fine)').matches) return;

        event.preventDefault();
        this.open();
    }

    close() {
        this.dialogTarget.close();
    }

    // Ferme si on clique sur le fond (hors de la carte). La carte stoppe la
    // propagation via sa propre zone ; ici on ne ferme que si la cible est le
    // <dialog> lui-même (le backdrop occupe toute sa surface).
    backdrop(event) {
        if (event.target === this.dialogTarget) {
            this.dialogTarget.close();
        }
    }
}
