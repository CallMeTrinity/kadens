import { Controller } from '@hotwired/stimulus';

/*
 * Édition en ligne « semi-invisible » d'un champ texte.
 *
 * Le contenu affiché (data-inline-edit-target="display") ressemble à du texte
 * jusqu'au clic : on le remplace alors par un <input>/<textarea> prérempli. On
 * enregistre au blur ou à Entrée (Échap annule). La persistance passe par un
 * `fetch` POST qui renvoie la valeur nettoyée par le serveur (texte brut), qu'on
 * réaffiche. Amélioration progressive : sans JS, un formulaire complet (replié)
 * reste le repli.
 *
 * Générique : titre/description du plan (endpoint meta) et note de case (endpoint
 * item/note) l'utilisent avec des URLs différentes.
 */
export default class extends Controller {
    static targets = ['display'];

    static values = {
        url: String,
        field: String,
        token: String,
        type: { type: String, default: 'input' }, // 'input' | 'textarea'
        placeholder: String,
    };

    start() {
        if (this.editing) return;
        this.editing = true;

        this.original = this.displayTarget.dataset.value ?? '';
        const control = document.createElement(this.typeValue === 'textarea' ? 'textarea' : 'input');
        if (this.typeValue !== 'textarea') control.type = 'text';
        control.value = this.original;
        control.className = 'kd-inlineedit__input';
        if (this.hasPlaceholderValue) control.placeholder = this.placeholderValue;

        this.displayTarget.hidden = true;
        this.displayTarget.after(control);
        this.control = control;
        control.focus();
        control.select();

        this.onBlur = () => this.save();
        this.onKey = (e) => this.handleKey(e);
        control.addEventListener('blur', this.onBlur);
        control.addEventListener('keydown', this.onKey);
    }

    handleKey(event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            this.cancel();
        } else if (event.key === 'Enter' && (this.typeValue !== 'textarea' || event.metaKey || event.ctrlKey)) {
            // Entrée valide (mono-ligne) ; en textarea, Entrée insère un saut,
            // Ctrl/Cmd+Entrée valide.
            event.preventDefault();
            this.control.blur();
        }
    }

    async save() {
        if (!this.editing) return;
        const value = this.control.value.trim();

        if (value === this.original) {
            this.finish(this.original);
            return;
        }

        try {
            const body = new FormData();
            body.append('_token', this.tokenValue);
            body.append('field', this.fieldValue);
            body.append('value', value);
            const response = await fetch(this.urlValue, { method: 'POST', body, credentials: 'same-origin' });
            if (!response.ok) {
                this.finish(this.original); // refus serveur (ex. titre vide) : on restaure
                return;
            }
            this.finish(await response.text());
        } catch (error) {
            this.finish(this.original);
        }
    }

    cancel() {
        this.finish(this.original);
    }

    finish(value) {
        this.editing = false;
        if (this.control) {
            this.control.removeEventListener('blur', this.onBlur);
            this.control.remove();
            this.control = null;
        }

        const el = this.displayTarget;
        el.dataset.value = value;
        if (value === '' && this.hasPlaceholderValue) {
            el.textContent = this.placeholderValue;
            el.classList.add('kd-inlineedit--empty');
        } else {
            el.textContent = value;
            el.classList.remove('kd-inlineedit--empty');
        }
        el.hidden = false;
    }
}
