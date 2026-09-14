/* ============================================================
   sw.js — Service worker da página do porteiro
   Objetivo: a entrada do evento continuar a funcionar se a
   internet falhar no local. Guarda a "casca" da aplicação
   (página, estilos, tipos de letra, leitor de QR) para que ela
   abra sem rede. Os DADOS dos convidados são guardados pela
   própria página (ver porteiro.php).

   Nada relacionado com a API é guardado em cache: os pedidos de
   check-in têm de chegar mesmo ao servidor, ou ficam em fila.
   ============================================================ */
const CACHE = 'porta-v2';   // v2: deixou de guardar páginas de outros casamentos

// Casca mínima para a página abrir offline.
const CASCA = [
  'porteiro.php',
  'assets/estilo.css',
  'assets/fontes.css',
  'assets/api.js',
  'assets/html5-qrcode.min.js',
  'assets/convite/fonts/cormorant-garamond-latin-400-normal.woff2',
  'assets/convite/fonts/cormorant-garamond-latin-600-normal.woff2',
  'assets/convite/fonts/jost-latin-400-normal.woff2',
  'assets/convite/fonts/jost-latin-500-normal.woff2',
];

self.addEventListener('install', e => {
  e.waitUntil(
    caches.open(CACHE)
      // addAll falha por inteiro se um recurso falhar: guardamos um a um.
      .then(c => Promise.all(CASCA.map(u => c.add(u).catch(() => null))))
      .then(() => self.skipWaiting())
  );
});

self.addEventListener('activate', e => {
  e.waitUntil(
    caches.keys()
      .then(ks => Promise.all(ks.filter(k => k !== CACHE).map(k => caches.delete(k))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;                    // check-ins nunca são servidos de cache
  const url = new URL(req.url);
  if (url.origin !== self.location.origin) return;
  if (url.pathname.endsWith('/api.php')) return;       // dados: sempre da rede
  if (url.pathname.endsWith('/manifest.php')) return;  // muda com o casamento aberto

  // Guarda-se a CASCA e os recursos estáticos — nunca outras páginas.
  // Com vários casamentos na mesma casa, os endereços são iguais para todos
  // (index.php é index.php); guardar páginas dava, sem rede, a página de um
  // casal a quem está a trabalhar noutro.
  const ficheiro = url.pathname.split('/').pop() || '';
  const guardavel = CASCA.includes(ficheiro) || url.pathname.includes('/assets/');

  // Rede primeiro (para ter sempre a versão mais recente), cache como recurso.
  e.respondWith(
    fetch(req)
      .then(res => {
        if (res && res.ok && guardavel) {
          const copia = res.clone();
          caches.open(CACHE).then(c => c.put(req, copia)).catch(() => {});
        }
        return res;
      })
      .catch(() => caches.match(req).then(r => r || caches.match('porteiro.php')))
  );
});
