// ============================================================
// SERVICE WORKER — ADI Cuneo Cantici PWA
// Strategia: Cache-first per PDF, Network-first per tutto il resto
// ============================================================

const CACHE_VERSION = 'v1';
const CACHE_STATIC = `adi-static-${CACHE_VERSION}`;
const CACHE_PDF = `adi-pdf-${CACHE_VERSION}`;

const REPO_CANTICI = 'https://raw.githubusercontent.com/kikkogarins/Adi-Cuneo-Cantici/main';

// File statici da pre-cachare subito all'installazione
const STATIC_FILES = [
    '/cantici.html',
    '/index.html',
    '/storia.html',
    '/gallery.html',
    '/eventi.html',
    '/contatti.html',
    '/css/st.css',
    '/img/logo_adi_cuneo.png',
    '/img/icon-192.png',
    '/img/icon-512.png',
    '/manifest.json'
];

// ============================================================
// INSTALL — Pre-caching file statici
// ============================================================
self.addEventListener('install', event => {
    console.log('[SW] Installazione in corso...');

    event.waitUntil(
        caches.open(CACHE_STATIC)
            .then(cache => {
                console.log('[SW] Pre-caching file statici');
                // addAll non blocca se un file manca, usiamo Promise.allSettled
                return Promise.allSettled(
                    STATIC_FILES.map(file =>
                        cache.add(file).catch(err => console.warn(`[SW] Non ho trovato: ${file}`, err))
                    )
                );
            })
            .then(() => {
                console.log('[SW] Installazione completata');
                // Forza attivazione immediata senza aspettare reload
                return self.skipWaiting();
            })
    );
});

// ============================================================
// ACTIVATE — Pulizia cache vecchie
// ============================================================
self.addEventListener('activate', event => {
    console.log('[SW] Attivazione...');

    event.waitUntil(
        caches.keys()
            .then(cacheNames => {
                return Promise.all(
                    cacheNames
                        .filter(name => name !== CACHE_STATIC && name !== CACHE_PDF)
                        .map(name => {
                            console.log(`[SW] Elimino cache vecchia: ${name}`);
                            return caches.delete(name);
                        })
                );
            })
            .then(() => {
                console.log('[SW] Attivazione completata, controllo tutte le tab');
                return self.clients.claim();
            })
    );
});

// ============================================================
// FETCH — Intercetta tutte le richieste
// ============================================================
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);
    const isPDF = url.pathname.endsWith('.pdf');
    const isGitHub = url.hostname === 'raw.githubusercontent.com';
    const isGoogleDocs = url.hostname === 'docs.google.com';

    // Ignora Google Docs Viewer (non cachabile)
    if (isGoogleDocs) return;

    // PDF da GitHub → Cache-first
    if (isPDF && isGitHub) {
        event.respondWith(cachePDFStrategy(event.request));
        return;
    }

    // database.json → Network-first (sempre aggiornato)
    if (url.pathname.endsWith('database.json')) {
        event.respondWith(networkFirstStrategy(event.request));
        return;
    }

    // File statici → Cache-first con fallback network
    event.respondWith(cacheFirstStrategy(event.request));
});

// ============================================================
// STRATEGIA 1: Cache-first (file statici)
// Prova cache → se non c'è prova network → salva in cache
// ============================================================
async function cacheFirstStrategy(request) {
    const cached = await caches.match(request);
    if (cached) {
        return cached;
    }

    try {
        const networkResponse = await fetch(request);
        if (networkResponse.ok) {
            const cache = await caches.open(CACHE_STATIC);
            cache.put(request, networkResponse.clone());
        }
        return networkResponse;
    } catch (err) {
        console.warn('[SW] Offline e non in cache:', request.url);
        // Fallback pagina offline se disponibile
        return caches.match('/offline.html') || new Response('Contenuto non disponibile offline', {
            status: 503,
            headers: { 'Content-Type': 'text/plain; charset=utf-8' }
        });
    }
}

// ============================================================
// STRATEGIA 2: Network-first (database.json)
// Prova network → se offline usa cache
// ============================================================
async function networkFirstStrategy(request) {
    try {
        const networkResponse = await fetch(request);
        if (networkResponse.ok) {
            const cache = await caches.open(CACHE_STATIC);
            cache.put(request, networkResponse.clone());
        }
        return networkResponse;
    } catch (err) {
        console.warn('[SW] Offline, uso database in cache');
        const cached = await caches.match(request);
        if (cached) return cached;
        return new Response('[]', {
            status: 200,
            headers: { 'Content-Type': 'application/json' }
        });
    }
}

// ============================================================
// STRATEGIA 3: Cache-first PDF (cartella base e musicisti)
// Prova cache → se non c'è scarica e salva → poi mostra
// ============================================================
async function cachePDFStrategy(request) {
    const cached = await caches.match(request);
    if (cached) {
        console.log('[SW] PDF dalla cache:', request.url.split('/').pop());
        return cached;
    }

    try {
        console.log('[SW] PDF non in cache, scarico:', request.url.split('/').pop());
        const networkResponse = await fetch(request);
        if (networkResponse.ok) {
            const cache = await caches.open(CACHE_PDF);
            cache.put(request, networkResponse.clone());
            console.log('[SW] PDF salvato in cache:', request.url.split('/').pop());
        }
        return networkResponse;
    } catch (err) {
        console.warn('[SW] PDF non disponibile offline:', request.url.split('/').pop());
        return new Response(JSON.stringify({ error: 'PDF non disponibile offline' }), {
            status: 503,
            headers: { 'Content-Type': 'application/json' }
        });
    }
}

// ============================================================
// MESSAGGI — Comunicazione con la pagina principale
// ============================================================
self.addEventListener('message', event => {
    const { type, data } = event.data || {};

    // Ricevi lista PDF da scaricare in bulk (download iniziale)
    if (type === 'DOWNLOAD_ALL_PDFS') {
        downloadAllPDFs(data.urls, event.source);
    }

    // Controlla quali PDF sono già in cache
    if (type === 'CHECK_CACHED_PDFS') {
        checkCachedPDFs(data.urls, event.source);
    }

    // Cancella tutta la cache PDF (reset offline)
    if (type === 'CLEAR_PDF_CACHE') {
        caches.delete(CACHE_PDF).then(() => {
            event.source.postMessage({ type: 'PDF_CACHE_CLEARED' });
        });
    }
});

// ============================================================
// Download massivo PDF con progress
// ============================================================
async function downloadAllPDFs(urls, client) {
    const total = urls.length;
    let downloaded = 0;
    let failed = 0;
    const cache = await caches.open(CACHE_PDF);

    for (const url of urls) {
        // Salta se già in cache
        const existing = await caches.match(url);
        if (existing) {
            downloaded++;
            client.postMessage({
                type: 'DOWNLOAD_PROGRESS',
                downloaded,
                total,
                failed,
                filename: url.split('/').pop()
            });
            continue;
        }

        try {
            const response = await fetch(url);
            if (response.ok) {
                await cache.put(url, response);
                downloaded++;
                console.log(`[SW] Scaricato: ${url.split('/').pop()}`);
            } else {
                failed++;
            }
        } catch (err) {
            failed++;
            console.warn(`[SW] Errore download: ${url.split('/').pop()}`);
        }

        // Invia progresso alla pagina
        client.postMessage({
            type: 'DOWNLOAD_PROGRESS',
            downloaded,
            total,
            failed,
            filename: url.split('/').pop()
        });

        // Piccola pausa per non sovraccaricare GitHub
        await new Promise(resolve => setTimeout(resolve, 100));
    }

    client.postMessage({
        type: 'DOWNLOAD_COMPLETE',
        downloaded,
        total,
        failed
    });
}

// ============================================================
// Controlla quali PDF sono già in cache
// ============================================================
async function checkCachedPDFs(urls, client) {
    const cached = [];
    for (const url of urls) {
        const match = await caches.match(url);
        if (match) cached.push(url);
    }
    client.postMessage({
        type: 'CACHED_PDFS_LIST',
        cached,
        total: urls.length
    });
}