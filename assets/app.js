import './stimulus_bootstrap.js';
/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';

/*
 * Le service worker n'est PAS enregistré ici : il l'est dans base.html.twig,
 * sous condition `app.environment == 'prod'` (en dev, le même bloc désenregistre
 * au contraire ce qui traîne). Le conditionner côté serveur est la seule façon
 * de le faire sans exposer l'environnement au JS.
 */
