import { Controller } from '@hotwired/stimulus';

/*
 * Copie une URL de partage dans le presse-papiers et affiche un retour bref.
 * Dégrade proprement : sans JS, le lien « Ouvrir la page publique » reste
 * cliquable à côté du bouton.
 */
export default class extends Controller {
    static values = { url: String };
    static targets = ['feedback'];

    async copy() {
        try {
            await navigator.clipboard.writeText(this.urlValue);
            this.notify('Lien copié');
        } catch (error) {
            this.notify('Copie impossible, copie le lien manuellement');
        }
    }

    notify(message) {
        if (this.hasFeedbackTarget) {
            this.feedbackTarget.textContent = message;
        }
    }
}
