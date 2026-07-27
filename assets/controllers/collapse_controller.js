import { Controller } from '@hotwired/stimulus';

/*
 * Replie un <details> quand une media query correspond. Posé sur le <details>
 * lui-même.
 *
 *   <details open data-controller="collapse" data-collapse-media-value="(max-width: 560px)">
 *
 * L'élément est rendu **ouvert** par le serveur : sans JS, rien n'est jamais
 * caché — c'est le repli qui est l'amélioration, pas l'ouverture. Le contrôleur
 * ne fait que fermer à l'entrée dans le palier, et rouvrir à la sortie.
 *
 * Il ne touche à rien tant que l'utilisateur n'a pas changé de largeur : une fois
 * dans le palier, on peut ouvrir et refermer librement, seul un franchissement de
 * palier repose l'état.
 */
export default class extends Controller {
    static values = {
        media: { type: String, default: '(max-width: 560px)' },
    };

    connect() {
        this.query = matchMedia(this.mediaValue);
        this.sync = () => { this.element.open = !this.query.matches; };
        this.sync();
        this.query.addEventListener('change', this.sync);
    }

    disconnect() {
        this.query?.removeEventListener('change', this.sync);
    }
}
