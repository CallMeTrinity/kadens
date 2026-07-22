import './stimulus_bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';

/*
 * PWA (Phase 9) : enregistrement du service worker manuel.
 * Servi depuis la racine (/sw.js) pour couvrir tout le scope de l'app. Le SW
 * n'est actif qu'en contexte sécurisé (https ou localhost) ; ailleurs on ignore
 * silencieusement.
 */
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch((error) => {
            console.error('Service worker registration failed:', error);
        });
    });
}
