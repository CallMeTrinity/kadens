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
