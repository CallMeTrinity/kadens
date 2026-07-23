import './stimulus_bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';

/*
 * Mode hors connexion suspendu (voir CLAUDE.md §6). On n'enregistre plus de
 * service worker et on désenregistre ceux déjà installés + purge leurs caches,
 * pour lever les interférences de cache pendant le dev (une page servie depuis
 * le cache donnait l'impression qu'il fallait recharger). À réactiver plus tard.
 */
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistrations()
        .then((registrations) => registrations.forEach((registration) => registration.unregister()))
        .catch(() => {});
}
if ('caches' in window) {
    caches.keys()
        .then((keys) => keys.filter((key) => key.startsWith('kadens-')).forEach((key) => caches.delete(key)))
        .catch(() => {});
}
