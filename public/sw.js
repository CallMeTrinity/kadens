/*
 * Kadens — Service Worker (écrit à la main, pas de Workbox : AssetMapper ne
 * bundle pas, il n'y a donc aucun précache généré au build).
 *
 * Objectif : rendre l'app INSTALLABLE (Chrome exige un service worker doté d'un
 * gestionnaire `fetch`) et donner un repli lisible hors réseau. Ce n'est PAS un
 * retour au mode hors connexion complet de la Phase 9 : celui-ci servait les
 * pages de consultation en stale-while-revalidate, ce qui rendait des pages
 * périmées alors qu'on était en ligne et donnait l'illusion qu'il fallait
 * recharger. C'est ce qui l'avait fait suspendre. La règle est donc inversée :
 *
 *   → EN LIGNE, LE RÉSEAU GAGNE TOUJOURS POUR DU HTML.
 *
 * Ce qui est intercepté, et rien d'autre :
 *   - /assets/*        → cache-first. Les URL sont digestées (hash dans le nom)
 *                        donc immuables : un changement de contenu = une autre
 *                        URL, jamais du contenu périmé.
 *   - /pwa/*           → cache-first. Icônes et écrans de démarrage, immuables.
 *   - navigations      → network-first, repli sur la copie en cache puis sur
 *     (mode 'navigate')  /offline.html.
 *
 * Ce qui n'est JAMAIS intercepté (on sort du handler, le navigateur fait son
 * travail normal) :
 *   - les requêtes non-GET (toutes les mutations) ;
 *   - le cross-origin ;
 *   - **les requêtes Turbo** : une visite Turbo Drive ou un Turbo Stream est un
 *     `fetch()` dont le mode n'est pas 'navigate'. Les traiter comme un asset
 *     statique servirait des fragments périmés — exactement le piège de la
 *     Phase 9. Elles tombent dans le « rien d'autre » et vont droit au réseau.
 *
 * Le nom de cache est versionné : l'incrémenter purge tout à l'activation.
 */

const CACHE = 'kadens-v3';

// Coquille minimale, précachée à l'installation. Les assets digestés ne sont pas
// listés (leurs URL changent à chaque déploiement) : ils se peuplent au runtime.
const PRECACHE = [
    '/offline.html',
    '/manifest.json',
    '/pwa/icon-192.png',
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            .then((cache) => cache.addAll(PRECACHE))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
            .then(() => self.clients.claim())
    );
});

/** Cache-first : réservé aux URL immuables (/assets/, /pwa/). */
async function cacheFirst(request) {
    const cached = await caches.match(request);
    if (cached) {
        return cached;
    }

    const response = await fetch(request);
    if (response && response.ok) {
        const cache = await caches.open(CACHE);
        cache.put(request, response.clone());
    }

    return response;
}

/** Network-first : le réseau fait foi, le cache n'est qu'un filet hors ligne. */
async function networkFirst(request) {
    const cache = await caches.open(CACHE);

    try {
        const response = await fetch(request);
        if (response && response.ok) {
            cache.put(request, response.clone());
        }

        return response;
    } catch {
        const cached = await cache.match(request);

        return cached || cache.match('/offline.html');
    }
}

self.addEventListener('fetch', (event) => {
    const { request } = event;

    if (request.method !== 'GET') {
        return;
    }

    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    if (url.pathname.startsWith('/assets/') || url.pathname.startsWith('/pwa/')) {
        event.respondWith(cacheFirst(request));

        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(networkFirst(request));
    }

    // Tout le reste (Turbo, exports Excel, flux ICS…) : aucune interception.
});
