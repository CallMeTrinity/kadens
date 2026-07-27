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
 * EXCEPTION ASSUMÉE, et une seule : /schedule/<id>/execute, la page qu'on tient
 * en main pendant la séance. Elle reste **network-first** — la règle ci-dessus
 * n'est donc pas cassée, en ligne on sert bien la page fraîche — mais son repli
 * hors ligne est SA PROPRE copie en cache, jamais /offline.html : une salle en
 * sous-sol est le cas normal, pas l'incident. C'est la seule page de l'app dont
 * la version en cache vaut mieux que rien.
 *
 * Ce que ça ne fait PAS : mettre en file les validations. Le service worker ne
 * touche à aucun POST, la file vit dans le contrôleur Stimulus `execlog` où elle
 * est lisible et débogable (localStorage). Un SW qui rejouerait des mutations
 * en arrière-plan serait invisible le jour où il se tromperait.
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

const CACHE = 'kadens-v4';

/** La page d'exécution d'une séance datée : /schedule/<id>/execute. */
const EXECUTE_PATH = /^\/schedule\/\d+\/execute\/?$/;

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

/**
 * Network-first : le réseau fait foi, le cache n'est qu'un filet hors ligne.
 *
 * `fallbackToOffline` distingue les deux usages. Pour une navigation ordinaire,
 * servir une copie en cache d'une page quelconque est ce qu'on ne veut pas
 * (pages périmées, illusion qu'il faut recharger) : on préfère /offline.html.
 * Pour la page d'exécution, c'est l'inverse — sa copie de tout à l'heure est
 * exactement ce dont on a besoin en salle.
 */
async function networkFirst(request, fallbackToOffline = true) {
    const cache = await caches.open(CACHE);

    try {
        const response = await fetch(request);
        if (response && response.ok) {
            cache.put(request, response.clone());
        }

        return response;
    } catch {
        const cached = await cache.match(request);
        if (cached) {
            return cached;
        }

        return fallbackToOffline ? cache.match('/offline.html') : Response.error();
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

    /*
     * La page d'exécution, avant le test de navigation : elle doit être traitée
     * QUEL QUE SOIT le mode de la requête. Arriver dessus par un lien de l'app
     * est une visite Turbo Drive, donc un `fetch()` dont le mode n'est pas
     * 'navigate' — sans cette branche, la page ne serait mise en cache que si on
     * y atterrissait par une navigation complète, et le mode hors ligne
     * dépendrait du chemin emprunté pour y arriver.
     *
     * Reste network-first : en ligne, c'est bien la page fraîche qui est servie.
     * Seul le repli change (sa propre copie, pas /offline.html).
     */
    if (EXECUTE_PATH.test(url.pathname)) {
        event.respondWith(networkFirst(request, false));

        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(networkFirst(request));
    }

    // Tout le reste (Turbo, exports Excel, flux ICS…) : aucune interception.
});
