import { Controller } from '@hotwired/stimulus';

/*
 * Aperçu au survol d'une séance en lecture seule (consultation d'un plan, page
 * publique). Promeut le panneau `.kd-planpreview` en top-layer via la Popover
 * API pour qu'il échappe à l'overflow de la grille, et le positionne près de la
 * case. Purement client, aucun AJAX (offline-safe).
 *
 * L'éditeur de trame a sa propre implémentation (contrôleur `plangrid`, couplée
 * au mode tampon) ; celle-ci est la variante allégée pour les vues figées.
 */
export default class extends Controller {
    static targets = ['panel'];

    show() {
        const preview = this.hasPanelTarget ? this.panelTarget : this.element.querySelector('.kd-planpreview');
        if (!preview || typeof preview.showPopover !== 'function') return;

        this.hide();
        try {
            preview.showPopover();
        } catch (error) {
            return;
        }

        const rect = this.element.getBoundingClientRect();
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
        this.open = preview;
    }

    hide() {
        if (!this.open) return;
        try {
            this.open.hidePopover();
        } catch (error) {
            // Déjà retiré du DOM : rien à faire.
        }
        this.open = null;
    }
}
