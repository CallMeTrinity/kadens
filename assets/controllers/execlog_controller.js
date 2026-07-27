import { Controller } from '@hotwired/stimulus';
import { renderStreamMessage } from '@hotwired/turbo';

/*
 * Pointage d'une séance en cours (page /schedule/{id}/execute).
 *
 * Quatre choses, et rien d'autre :
 *
 *   1. UN EXERCICE À LA FOIS. Tous les panneaux sont rendus par le serveur, ce
 *      contrôleur n'en montre qu'un et bascule sur commande du rail. Rendre la
 *      page entière est la condition du hors ligne : au milieu d'une séance, on
 *      ne va pas rechercher l'exercice suivant sur le réseau. Sans JS, tous les
 *      panneaux restent affichés à la suite et le rail devient une liste
 *      d'ancres — la page marche, elle défile au lieu de basculer.
 *
 *   2. ENREGISTREMENT AUTOMATIQUE. Corriger une charge et passer au champ
 *      suivant enregistre : en salle, personne ne cherche un bouton « valider »
 *      après chaque nombre.
 *
 *   3. AFFICHAGE OPTIMISTE. La coche bascule AVANT la réponse du serveur. C'est
 *      ce qui rend la page utilisable sur un réseau de sous-sol : le geste ne
 *      doit jamais attendre le réseau. La réponse réconcilie ensuite le DOM
 *      (Turbo Stream), et corrige donc un optimisme qui se serait trompé.
 *
 *   4. FILE HORS LIGNE. Un POST qui échoue faute de réseau est mis en file
 *      locale et rejoué à la reconnexion. C'est le seul endroit de l'app qui
 *      écrit hors réseau, et il est cantonné à cette page.
 *
 * Les minuteurs (chrono de séance, repos) sont purement client : un repos n'est
 * pas une donnée de séance, et le début de séance se DÉRIVE de la première série
 * validée côté serveur, ce qui évite une colonne de plus et survit au
 * rechargement comme au changement d'appareil.
 *
 * Pourquoi la file n'est pas dans le service worker : celui-ci ne doit
 * intercepter que des GET (voir public/sw.js). Intercepter les POST pour les
 * mettre en file y ferait rentrer de la logique métier, invisible et
 * indébogable. Ici la file est lisible dans localStorage, et le rejeu passe par
 * le même chemin que n'importe quelle soumission.
 *
 * Le stockage est localStorage et non IndexedDB : la charge utile est une
 * poignée de champs par série, quelques dizaines par séance. IndexedDB
 * apporterait de l'asynchrone et une centaine de lignes de plomberie pour
 * stocker moins d'un kilo-octet.
 */
export default class extends Controller {
    static targets = [
        'form', 'check', 'value', 'net', 'dialog', 'bar',
        'panel', 'railItem', 'railLink', 'clock',
        'rest', 'restValue', 'restStop', 'restPrescribed',
    ];

    static values = {
        queueKey: String,
        startedAt: String,
        targetMinutes: Number,
    };

    connect() {
        // Signale que le JS est branché : les boutons « enregistrer » de repli
        // (utiles seulement sans JS) se masquent en CSS.
        this.element.classList.add('kd-exec--js');

        // Dernier refus du serveur, à afficher. Distinct de la file, qui ne
        // contient que ce qui attend le réseau.
        this.failed = null;
        this.current = 0;
        this.restEndsAt = null;

        this.onSubmit = this.onSubmit.bind(this);
        this.onChange = this.onChange.bind(this);
        this.onOnline = this.onOnline.bind(this);
        this.onOffline = this.onOffline.bind(this);

        this.element.addEventListener('submit', this.onSubmit);
        this.element.addEventListener('change', this.onChange);
        window.addEventListener('online', this.onOnline);
        window.addEventListener('offline', this.onOffline);

        // Un seul intervalle pour les deux minuteurs : ils tiquent à la même
        // seconde, deux timers seraient deux occasions de dériver.
        this.ticker = setInterval(() => this.tick(), 1000);
        this.tick();

        if (this.hasRestTarget) this.restTarget.hidden = false;

        this.showFirstUnfinished();
        this.renderNetwork();
        if (navigator.onLine) this.flush();
    }

    disconnect() {
        clearInterval(this.ticker);
        this.element.removeEventListener('submit', this.onSubmit);
        this.element.removeEventListener('change', this.onChange);
        window.removeEventListener('online', this.onOnline);
        window.removeEventListener('offline', this.onOffline);
    }

    // ---- Navigation entre exercices -----------------------------------------

    /*
     * On ouvre sur le premier exercice non terminé, pas sur le premier tout
     * court : reprendre une séance au milieu est le cas courant (on repose le
     * téléphone entre deux exercices, l'écran se verrouille, on revient).
     */
    showFirstUnfinished() {
        const index = this.panelTargets.findIndex((p) => !p.classList.contains('is-complete'));
        this.show(index === -1 ? 0 : index);
    }

    select(event) {
        event.preventDefault();
        this.show(event.params.index);
    }

    previous(event) {
        event.preventDefault();
        this.show(this.current - 1);
    }

    next(event) {
        event.preventDefault();
        this.show(this.current + 1);
    }

    show(index) {
        const count = this.panelTargets.length;
        if (count === 0) return;

        this.current = Math.max(0, Math.min(index, count - 1));

        this.panelTargets.forEach((panel, i) => {
            panel.hidden = i !== this.current;
        });
        this.railItemTargets.forEach((item, i) => {
            item.classList.toggle('is-current', i === this.current);
        });

        this.applyPrescribedRest();
        this.scrollRailIntoView();
    }

    /*
     * Le rail peut être plus long que l'écran : la pastille courante doit rester
     * visible, sinon on perd le fil dès le quatrième exercice.
     */
    scrollRailIntoView() {
        const item = this.railItemTargets[this.current];
        if (item) item.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    }

    // ---- Soumissions --------------------------------------------------------

    /*
     * N'intercepte QUE les formulaires de ligne (cibles `form`). Les autres
     * formulaires de la page (terminer, remise à zéro) redirigent et doivent
     * suivre le chemin normal du navigateur : les lister comme exceptions serait
     * fragile, on part donc de la liste blanche.
     */
    onSubmit(event) {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !this.formTargets.includes(form)) return;

        event.preventDefault();

        const body = new FormData(form);
        // Un bouton submit porteur d'un name/value n'entre pas dans FormData :
        // c'est pourtant lui qui dit « valider » ou « dévalider ».
        const submitter = event.submitter;
        if (submitter && submitter.name) {
            body.append(submitter.name, submitter.value);
        }

        const op = body.get('op');
        this.applyOptimistic(form, op);
        // Valider une série lance le repos : c'est le geste qui suit, toujours.
        if (op !== 'unlog') this.autoStartRest();
        this.send(this.actionOf(form), body);
    }

    /*
     * L'URL du formulaire, lue par ATTRIBUT et non par propriété.
     *
     * `form.action` n'est fiable que si aucun contrôle du formulaire ne s'appelle
     * `action` : les contrôles nommés deviennent des propriétés du <form> et
     * masquent les siennes. Le bouton de validation s'appelle donc `op`, mais on
     * lit quand même l'attribut — la prochaine personne qui ajoutera un champ ici
     * n'aura pas à connaître ce piège.
     */
    actionOf(form) {
        return form.getAttribute('action');
    }

    /*
     * Un champ de valeur modifié enregistre la ligne, et la valide si elle ne
     * l'était pas : saisir « 12 reps » sur une série non pointée veut dire
     * qu'on vient de la faire, pas qu'on prépare une saisie.
     */
    onChange(event) {
        const input = event.target;
        if (!this.valueTargets.includes(input)) return;

        const form = input.closest('form');
        if (!form || !this.formTargets.includes(form)) return;

        const body = new FormData(form);
        body.append('op', 'log');

        this.applyOptimistic(form, 'log');
        this.send(this.actionOf(form), body);
    }

    /*
     * Bascule l'état de la ligne sans attendre le serveur. On ne touche qu'à ce
     * dont dépend l'affichage : la classe portée par le <li> (le CSS choisit
     * l'icône), l'état ARIA, et la valeur que postera le prochain tap.
     */
    applyOptimistic(form, op) {
        const line = form.closest('.kd-execline');
        if (!line) return;

        const done = op !== 'unlog';
        line.classList.toggle('is-done', done);

        const check = form.querySelector('.kd-execline__check');
        if (check) {
            check.value = done ? 'unlog' : 'log';
            check.setAttribute('aria-pressed', done ? 'true' : 'false');
        }

        this.refreshRemaining();
    }

    /*
     * Envoie une soumission.
     *
     * DEUX échecs à ne surtout pas confondre, et c'est la leçon de l'incident qui
     * a produit 65 gestes en attente sans que rien ne le signale :
     *
     *   - le réseau est absent (`fetch` lève) -> on met en file et on garde
     *     l'affichage optimiste. C'est le cas normal en salle, il est silencieux
     *     par conception.
     *   - le serveur RÉPOND et refuse (4xx/5xx) -> mettre en file ne sert à rien,
     *     le rejeu échouera identiquement. On le dit à l'écran.
     *
     * Traiter les deux pareil transforme n'importe quel bug serveur en file qui
     * gonfle en silence, ce qui est exactement ce qui s'est produit.
     */
    async send(url, body) {
        let response;

        try {
            response = await fetch(url, {
                method: 'POST',
                body,
                headers: { Accept: 'text/vnd.turbo-stream.html' },
                credentials: 'same-origin',
            });
        } catch {
            // Pas de réseau : c'est le cas prévu.
            this.enqueue(url, body);
            this.renderNetwork();

            return;
        }

        if (!response.ok) {
            this.failed = `Le serveur a refusé l'enregistrement (${response.status}).`;
            this.renderNetwork();

            return;
        }

        this.failed = null;
        renderStreamMessage(await response.text());
        // Le panneau vient d'être remplacé : il est rendu visible par défaut, il
        // faut réappliquer la sélection courante.
        this.show(this.current);
        this.renderNetwork();
    }

    // ---- File hors ligne ----------------------------------------------------

    get queue() {
        try {
            return JSON.parse(localStorage.getItem(this.queueKeyValue) || '[]');
        } catch {
            return [];
        }
    }

    set queue(entries) {
        try {
            localStorage.setItem(this.queueKeyValue, JSON.stringify(entries));
        } catch {
            // Stockage plein ou refusé (navigation privée) : on perd la
            // synchronisation différée, pas la séance en cours. Rien à faire de
            // mieux ici que de ne pas casser la page.
        }
    }

    /*
     * Empile un geste. Les gestes portant la même cible (même exercice, même
     * série) s'écrasent : ce qui compte à la reconnexion est l'état final de la
     * série, pas l'historique des hésitations. Ça évite aussi qu'une série
     * cochée puis décochée dix fois parte en dix requêtes.
     */
    enqueue(url, body) {
        const entry = { url, fields: Array.from(body.entries()) };
        const key = this.entryKey(entry);

        this.queue = [...this.queue.filter((e) => this.entryKey(e) !== key), entry];
    }

    entryKey(entry) {
        const fields = new Map(entry.fields);

        return `${entry.url}|${fields.get('prescribedId')}|${fields.get('setIndex')}`;
    }

    /*
     * Rejoue la file, dans l'ordre.
     *
     * Même distinction que `send`, et elle est ici vitale : une entrée que le
     * serveur refuse est ABANDONNÉE, pas conservée. Sans ça, un seul geste
     * définitivement invalide bloque la file pour toujours — tout ce qui suit
     * reste coincé derrière lui, indéfiniment « en attente ».
     *
     * Une panne réseau, elle, interrompt le rejeu et le reprendra plus tard :
     * insister sur les suivants ne ferait que multiplier les délais d'attente.
     * Les entrées passées sortent au fur et à mesure, donc une interruption ne
     * rejoue jamais deux fois la même chose — et l'endpoint est idempotent.
     */
    async flush() {
        let pending = this.queue;
        if (pending.length === 0) return;

        while (pending.length > 0) {
            const entry = pending[0];
            const body = new FormData();
            entry.fields.forEach(([name, value]) => body.append(name, value));

            let response;
            try {
                response = await fetch(entry.url, {
                    method: 'POST',
                    body,
                    headers: { Accept: 'text/vnd.turbo-stream.html' },
                    credentials: 'same-origin',
                });
            } catch {
                // Toujours pas de réseau : on garde la file intacte.
                break;
            }

            if (response.ok) {
                renderStreamMessage(await response.text());
                this.show(this.current);
                this.failed = null;
            } else {
                // Refus du serveur : le rejeu échouera à l'identique. On jette
                // l'entrée pour débloquer la file, et on le signale.
                this.failed = `${response.status} — un geste non enregistrable a été abandonné.`;
            }

            pending = pending.slice(1);
            this.queue = pending;
        }

        this.renderNetwork();
    }

    onOnline() {
        this.flush();
        this.renderNetwork();
    }

    onOffline() {
        this.renderNetwork();
    }

    /*
     * L'indicateur ne parle que quand il a quelque chose à dire : hors ligne, en
     * attente de synchronisation, ou refus du serveur. Une pastille « connecté »
     * permanente n'informe de rien et occupe de la hauteur qu'on n'a pas.
     */
    renderNetwork() {
        if (!this.hasNetTarget) return;

        const waiting = this.queue.length;
        const offline = !navigator.onLine;

        if (!offline && waiting === 0 && !this.failed) {
            this.netTarget.hidden = true;

            return;
        }

        this.netTarget.hidden = false;
        this.netTarget.classList.toggle('is-offline', offline || Boolean(this.failed));

        // Un refus du serveur passe devant : c'est la seule des trois situations
        // où l'utilisateur doit agir plutôt qu'attendre.
        if (this.failed) {
            this.netTarget.textContent = this.failed;

            return;
        }

        this.netTarget.textContent = offline
            ? `Hors ligne — ${waiting} geste${waiting > 1 ? 's' : ''} en attente`
            : `Synchronisation de ${waiting} geste${waiting > 1 ? 's' : ''}…`;
    }

    // ---- Minuteurs ----------------------------------------------------------

    tick() {
        this.renderClock();
        this.renderRest();
    }

    /*
     * Temps écoulé depuis la PREMIÈRE série validée, pas depuis l'ouverture de la
     * page : on ouvre parfois la séance la veille pour la relire. La valeur vient
     * du serveur (`completedAt` du premier LoggedSet), donc elle est juste après
     * un rechargement et sur un autre appareil.
     */
    renderClock() {
        if (!this.hasClockTarget) return;

        if (!this.startedAtValue) {
            this.clockTarget.textContent = '--:--';

            return;
        }

        const started = new Date(this.startedAtValue);
        const seconds = Math.max(0, Math.floor((Date.now() - started.getTime()) / 1000));
        this.clockTarget.textContent = this.formatDuration(seconds);

        if (this.targetMinutesValue > 0) {
            this.clockTarget.classList.toggle('is-over', seconds > this.targetMinutesValue * 60);
        }
    }

    /*
     * Le repos prescrit de l'exercice affiché devient un préréglage de plus, en
     * tête. Il change d'un exercice à l'autre, d'où la réécriture à chaque
     * bascule plutôt qu'un rendu figé côté serveur.
     */
    applyPrescribedRest() {
        if (!this.hasRestPrescribedTarget) return;

        const panel = this.panelTargets[this.current];
        const seconds = panel ? parseInt(panel.dataset.execlogRestValue || '', 10) : NaN;

        if (Number.isNaN(seconds) || seconds <= 0) {
            this.restPrescribedTarget.hidden = true;

            return;
        }

        this.restPrescribedTarget.hidden = false;
        this.restPrescribedTarget.dataset.execlogSecondsParam = String(seconds);
        this.restPrescribedTarget.textContent = `${seconds} s`;
    }

    /*
     * Après une validation, on lance le repos prescrit s'il y en a un. Le repos
     * est la suite logique de la série : le déclencher à la main serait un geste
     * de plus, au moment précis où on n'en veut aucun.
     */
    autoStartRest() {
        const panel = this.panelTargets[this.current];
        const seconds = panel ? parseInt(panel.dataset.execlogRestValue || '', 10) : NaN;

        if (!Number.isNaN(seconds) && seconds > 0) {
            this.beginRest(seconds);
        }
    }

    startRest(event) {
        this.beginRest(Number(event.params.seconds));
    }

    beginRest(seconds) {
        if (!seconds || seconds <= 0) return;

        this.restEndsAt = Date.now() + seconds * 1000;
        if (this.hasRestStopTarget) this.restStopTarget.hidden = false;
        this.renderRest();
    }

    stopRest() {
        this.restEndsAt = null;
        if (this.hasRestStopTarget) this.restStopTarget.hidden = true;
        this.renderRest();
    }

    renderRest() {
        if (!this.hasRestValueTarget) return;

        if (this.restEndsAt === null) {
            this.restValueTarget.textContent = '--:--';
            this.restValueTarget.classList.remove('is-running', 'is-over');

            return;
        }

        const left = Math.round((this.restEndsAt - Date.now()) / 1000);

        if (left <= 0) {
            // On laisse le compteur passer en négatif plutôt que de s'arrêter :
            // savoir qu'on traîne depuis 40 secondes vaut mieux qu'un zéro figé.
            this.restValueTarget.textContent = `+${this.formatDuration(-left)}`;
            this.restValueTarget.classList.remove('is-running');
            this.restValueTarget.classList.add('is-over');

            return;
        }

        this.restValueTarget.textContent = this.formatDuration(left);
        this.restValueTarget.classList.add('is-running');
        this.restValueTarget.classList.remove('is-over');
    }

    /** mm:ss, ou h:mm:ss au-delà d'une heure (même règle que UnitFormatter). */
    formatDuration(seconds) {
        const hours = Math.floor(seconds / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        const secs = seconds % 60;
        const pad = (n) => String(n).padStart(2, '0');

        return hours > 0 ? `${hours}:${pad(minutes)}:${pad(secs)}` : `${pad(minutes)}:${pad(secs)}`;
    }

    // ---- Clôture ------------------------------------------------------------

    /*
     * Terminer avec des séries non pointées ouvre la question au lieu de poster.
     * Le décompte se lit dans le DOM et non dans une valeur serveur : c'est le
     * seul chiffre juste quand des validations sont encore en file hors ligne.
     */
    finish(event) {
        if (this.remaining() === 0) return;

        event.preventDefault();
        if (this.hasDialogTarget) this.dialogTarget.showModal();
    }

    closeDialog() {
        if (this.hasDialogTarget) this.dialogTarget.close();
    }

    /** Séries prévues non pointées. Les séries « en plus » ne comptent pas. */
    remaining() {
        return this.element.querySelectorAll('.kd-execline:not(.is-done):not(.is-extra)').length;
    }

    /*
     * Tient le libellé du bouton de clôture d'accord avec l'affichage optimiste.
     * Sans ça, cocher hors ligne laisserait « Terminer · 4 » alors que l'écran
     * montre tout coché.
     */
    refreshRemaining() {
        const label = this.element.querySelector('.kd-execbar__label');
        if (!label) return;

        const left = this.remaining();
        label.textContent = left === 0 ? 'Terminer' : `Terminer · ${left}`;
    }
}
