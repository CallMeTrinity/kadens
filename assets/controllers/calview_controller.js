import { Controller } from '@hotwired/stimulus';

/*
 * Choix de la vue calendrier par défaut selon la taille d'écran.
 *
 * Le calendrier mémorise la vue dans un cookie `kd_calview` posé côté serveur —
 * httpOnly, donc illisible ici. C'est le contrôleur PHP qui décide de rendre ce
 * contrôleur (uniquement quand rien n'est mémorisé) : on ne contrarie jamais un
 * choix explicite de l'utilisateur, on ne fait qu'un premier aiguillage.
 *
 * `replace` et non `assign` : la vue mois ne doit pas rester dans l'historique,
 * sinon le retour arrière rejouerait la redirection en boucle.
 */
export default class extends Controller {
    static values = {
        url: String,
        media: { type: String, default: '(max-width: 560px)' },
    };

    connect() {
        if (!this.urlValue) return;
        if (!matchMedia(this.mediaValue).matches) return;

        window.location.replace(this.urlValue);
    }
}
