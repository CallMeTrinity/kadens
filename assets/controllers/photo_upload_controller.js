import { Controller } from '@hotwired/stimulus';

/*
 * Réduit une photo dans le navigateur avant de l'envoyer au récit d'un objectif
 * (#42). Trois gains d'un seul ré-encodage canvas :
 *
 *   - le poids : une photo de téléphone (4 à 10 Mo) passe sous la limite
 *     d'upload du mutualisé, et le récit se charge vite ;
 *   - la vie privée : le ré-encodage ne recopie aucune métadonnée EXIF, donc
 *     ni position GPS ni appareil, sur un récit qui peut devenir public ;
 *   - l'orientation : `imageOrientation: 'from-image'` l'applique aux pixels,
 *     la photo reste droite une fois l'EXIF parti.
 *
 * Amélioration progressive : sans JS, ou si le navigateur ne sait pas décoder
 * le fichier (HEIC hors Safari), l'original part tel quel et le serveur fait
 * ses propres contrôles (GoalPhotoStorage, qui retire aussi l'EXIF d'un JPEG).
 */
const MAX_EDGE = 2000;
const QUALITY = 0.85;

export default class extends Controller {
    static targets = ['input', 'status', 'submit'];

    async shrink() {
        const file = this.inputTarget.files[0];
        if (!file || !file.type.startsWith('image/') || typeof createImageBitmap !== 'function' || typeof DataTransfer !== 'function') {
            return;
        }

        this.busy(true, 'Préparation de la photo…');

        try {
            const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
            const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
            const canvas = document.createElement('canvas');
            canvas.width = Math.round(bitmap.width * scale);
            canvas.height = Math.round(bitmap.height * scale);
            canvas.getContext('2d').drawImage(bitmap, 0, 0, canvas.width, canvas.height);
            bitmap.close();

            const blob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', QUALITY));
            if (!blob) {
                throw new Error('encodage impossible');
            }

            const name = file.name.replace(/\.[^.]+$/, '') + '.jpg';
            const transfer = new DataTransfer();
            transfer.items.add(new File([blob], name, { type: 'image/jpeg' }));
            this.inputTarget.files = transfer.files;

            this.busy(false, `Photo prête : ${canvas.width} × ${canvas.height}, ${Math.round(blob.size / 1024)} Ko, sans métadonnées.`);
        } catch (error) {
            this.busy(false, 'Réduction impossible dans ce navigateur : la photo partira telle quelle.');
        }
    }

    busy(isBusy, message) {
        if (this.hasSubmitTarget) {
            this.submitTarget.disabled = isBusy;
        }
        if (this.hasStatusTarget) {
            this.statusTarget.textContent = message;
        }
    }
}
