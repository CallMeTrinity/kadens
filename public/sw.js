/*
 * Kadens — Service Worker (écrit à la main, pas de Workbox).
 *
 * Contrainte d'archi (CLAUDE.md §2) : AssetMapper ne bundle pas, donc pas de
 * précache généré. On s'appuie sur deux faits :
 *   1. les assets sous /assets/ sont digestés (hash dans le nom) donc immuables
 *      → cache-first sans péremption, un changement de contenu = nouvelle URL ;
 *   2. les pages de consultation sont auto-suffisantes (aucun AJAX post-chargement,
 *      discipline tenue depuis la Phase 2) → une réponse HTML mise en cache suffit
 *      à les rendre hors ligne.
 *
 * Servi depuis la racine (/sw.js) pour couvrir tout le scope de l'app.
 *
 * Stratégies :
 *   - /assets/*                       → cache-first (immuable).
 *   - pages de consultation           → stale-while-revalidate (instantané +
 *     (workout/{id}, plan-template/{id}, /s/{slug})   fraîcheur en arrière-plan,
 *     cohérent avec la « référence vivante » d'une séance).
 *   - autres navigations              → network-first, repli cache puis offline.html.
 *   - icônes, manifest, autres GET     → cache-first, repli réseau.
 *   - non-GET / cross-origin           → laissés au réseau (jamais mis en cache).
 */

const VERSION = 'kadens-v2';
const CACHE = `kadens-${VERSION}`;

// Coquille minimale précachée à l'installation. Les assets digestés (CSS/JS/
// polices) ne sont pas listés ici : ils se peuplent au runtime (cache-first).
const PRECACHE = [
  '/offline.html',
  '/manifest.json',
  '/icons/icon-192.png',
  '/icons/icon-512.png',
];

// Pages dont une copie en cache doit rester consultable hors ligne.
const CONSULTATION = [
  /^\/workout\/\d+$/,
  /^\/plan-template\/\d+$/,
  /^\/s\/[^/]+$/,
];

const isConsultation = (path) => CONSULTATION.some((re) => re.test(path));

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE).then((cache) => cache.addAll(PRECACHE)).then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys()
      .then((keys) => Promise.all(keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

/** Cache-first : sert le cache s'il existe, sinon réseau puis mise en cache. */
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

/** Stale-while-revalidate : réponse immédiate depuis le cache, MAJ en fond. */
async function staleWhileRevalidate(request) {
  const cache = await caches.open(CACHE);
  const cached = await cache.match(request);
  const network = fetch(request)
    .then((response) => {
      if (response && response.ok) {
        cache.put(request, response.clone());
      }
      return response;
    })
    .catch(() => null);
  return cached || (await network) || caches.match('/offline.html');
}

/** Network-first : réseau d'abord, repli cache puis page hors ligne. */
async function networkFirst(request) {
  const cache = await caches.open(CACHE);
  try {
    const response = await fetch(request);
    if (response && response.ok && request.method === 'GET') {
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
  const url = new URL(request.url);

  // Ne jamais interférer avec les mutations ni le cross-origin.
  if (request.method !== 'GET' || url.origin !== self.location.origin) {
    return;
  }

  // Assets digestés : immuables → cache-first.
  if (url.pathname.startsWith('/assets/')) {
    event.respondWith(cacheFirst(request));
    return;
  }

  // Navigations (chargement d'une page).
  if (request.mode === 'navigate') {
    if (isConsultation(url.pathname)) {
      event.respondWith(staleWhileRevalidate(request));
    } else {
      event.respondWith(networkFirst(request));
    }
    return;
  }

  // Icônes, manifest, autres GET statiques.
  event.respondWith(cacheFirst(request));
});
