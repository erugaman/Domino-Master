const CACHE_NAME = 'domino-master-v1';

// Forzar URLs con HTTP
const BASE_URL = 'http://frm.noip.me/desarrollo/';

const urlsToCache = [
  BASE_URL,
  BASE_URL + 'index.html',
  BASE_URL + 'config.php',
  BASE_URL + 'get_players.php',
  BASE_URL + 'get_ranking.php',
  BASE_URL + 'get_stats.php',
  BASE_URL + 'get_stats_parejas.php',
  BASE_URL + 'get_history.php',
  BASE_URL + 'crear_partida.php',
  BASE_URL + 'guardar_juego.php',
  BASE_URL + 'save_player.php',
  BASE_URL + 'delete_player.php',
  BASE_URL + 'reset_history.php'
];

self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => {
        console.log('Cache abierto');
        return cache.addAll(urlsToCache);
      })
  );
});

self.addEventListener('fetch', event => {
  event.respondWith(
    caches.match(event.request)
      .then(response => {
        if (response) {
          return response;
        }
        // Forzar que las peticiones sean HTTP
        let url = event.request.url;
        if (url.startsWith('https://frm.noip.me')) {
          url = url.replace('https://', 'http://');
          return fetch(url);
        }
        return fetch(event.request);
      })
  );
});

self.addEventListener('activate', event => {
  const cacheWhitelist = [CACHE_NAME];
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheWhitelist.indexOf(cacheName) === -1) {
            return caches.delete(cacheName);
          }
        })
      );
    })
  );
});