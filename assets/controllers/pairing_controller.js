import { Controller } from '@hotwired/stimulus';

/*
 * Le QR d'appairage vivant (KL-47) : un décompte, et l'attente de la
 * confirmation « ce téléphone-là s'est connecté ».
 *
 * Amélioration progressive stricte. Sans JS, le panneau reste utilisable : le
 * QR est dessiné côté serveur, le code de secours est écrit en toutes lettres,
 * et l'échéance est affichée en clair (« Valable jusqu'à 14:32 »). Ce contrôleur
 * ne fait que remplacer cette heure par un décompte, et prévenir quand le code
 * a été consommé ou qu'il est mort.
 *
 * Les deux timers s'arrêtent dès qu'il n'y a plus rien à dire — code consommé,
 * échéance passée, ou panneau retiré du DOM. Un code ne vit que deux minutes :
 * le sondage est borné par la nature de ce qu'il observe, pas par un compteur
 * qu'il faudrait tenir.
 */
export default class extends Controller {
    static targets = ['live', 'countdown', 'paired', 'device', 'expired'];
    static values = {
        expiresAt: String,
        statusUrl: String,
        // Deux secondes : assez pour que la confirmation paraisse immédiate à
        // qui vient de scanner, assez peu pour rester une soixantaine d'appels
        // sur toute la fenêtre de vie du code.
        poll: { type: Number, default: 2000 },
    };

    connect() {
        this.expiresAt = Date.parse(this.expiresAtValue);

        // Une date illisible ne doit pas transformer le repli serveur en
        // « expiré dans NaN » : on laisse alors la page telle qu'elle est rendue.
        if (Number.isNaN(this.expiresAt)) {
            return;
        }

        this.tick();
        this.ticker = setInterval(() => this.tick(), 1000);
        this.poller = setInterval(() => this.check(), this.pollValue);
    }

    disconnect() {
        this.stop();
    }

    stop() {
        clearInterval(this.ticker);
        clearInterval(this.poller);
    }

    tick() {
        const left = Math.round((this.expiresAt - Date.now()) / 1000);

        if (left <= 0) {
            this.expire();

            return;
        }

        const minutes = Math.floor(left / 60);
        const seconds = String(left % 60).padStart(2, '0');
        this.countdownTarget.textContent = `Expire dans ${minutes}:${seconds}`;
    }

    async check() {
        try {
            const response = await fetch(this.statusUrlValue, {
                headers: { Accept: 'application/json' },
            });

            // Un code régénéré ailleurs a été supprimé : il n'y a plus rien à
            // observer, et réessayer ne ferait qu'empiler des 404.
            if (!response.ok) {
                this.stop();

                return;
            }

            const status = await response.json();

            if (status.used) {
                this.confirm(status.device);
            } else if (status.expired) {
                this.expire();
            }
        } catch (error) {
            // Réseau coupé : on retentera au tour suivant. Le QR affiché reste
            // valable, ce n'est pas au poste de bureau de décider qu'il est mort.
        }
    }

    /* Le code est consommé : le QR ne vaut plus rien, on le retire de l'écran
       plutôt que de laisser croire qu'un second téléphone pourrait le scanner. */
    confirm(device) {
        this.stop();
        this.liveTarget.hidden = true;
        this.expiredTarget.hidden = true;
        this.deviceTarget.textContent = device || 'Un appareil';
        this.pairedTarget.hidden = false;
    }

    expire() {
        this.stop();
        this.liveTarget.hidden = true;
        this.expiredTarget.hidden = false;
    }
}
