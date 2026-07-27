import { Controller } from '@hotwired/stimulus';

/*
 * Ferme un <details> ouvert au clic extérieur ou à Échap.
 *
 * Le <details> natif porte déjà l'ouverture/fermeture au clic et au clavier : ce
 * contrôleur n'ajoute que le confort. S'il ne charge pas (offline, JS coupé), le
 * menu reste parfaitement utilisable — c'est la raison du choix de <details>
 * plutôt qu'un menu piloté par JS.
 */
export default class extends Controller {
    connect() {
        this.onOutside = (event) => {
            if (this.element.open && !this.element.contains(event.target)) {
                this.element.open = false;
            }
        };
        this.onKey = (event) => {
            if (event.key === 'Escape' && this.element.open) {
                this.element.open = false;
                this.element.querySelector('summary')?.focus();
            }
        };

        document.addEventListener('click', this.onOutside);
        document.addEventListener('keydown', this.onKey);
    }

    disconnect() {
        document.removeEventListener('click', this.onOutside);
        document.removeEventListener('keydown', this.onKey);
    }
}
