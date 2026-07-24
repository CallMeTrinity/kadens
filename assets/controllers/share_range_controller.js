import { Controller } from '@hotwired/stimulus';

/*
 * Compose et copie un lien de partage public filtré sur une plage de semaines :
 * {base}/semaines/{de}-{à} (ou {de} si une seule semaine). Stateless — la plage
 * vit dans l'URL, rien n'est stocké. Dégrade proprement : si le presse-papier
 * échoue, on affiche l'URL à copier à la main.
 */
export default class extends Controller {
    static values = { base: String };

    static targets = ['from', 'to', 'feedback'];

    async copy() {
        let from = parseInt(this.fromTarget.value, 10);
        let to = parseInt(this.toTarget.value, 10);
        if (from > to) [from, to] = [to, from];

        const range = from === to ? `${from}` : `${from}-${to}`;
        const url = `${this.baseValue}/semaines/${range}`;

        try {
            await navigator.clipboard.writeText(url);
            this.setFeedback('Lien copié');
        } catch (error) {
            this.setFeedback(url);
        }
    }

    setFeedback(text) {
        if (this.hasFeedbackTarget) this.feedbackTarget.textContent = text;
    }
}
