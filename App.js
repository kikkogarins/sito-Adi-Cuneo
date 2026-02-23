// ============================================================
// CANTICI ADI CUNEO - APP CON CACHE AUTOMATICA
// Sistema offline con localStorage (funziona garantito!)
// ============================================================

const REPO_CANTICI = 'https://raw.githubusercontent.com/kikkogarins/Adi-Cuneo-Cantici/main';
const STORAGE_KEY = 'cantici-adi-cache';
const STORAGE_VERSION = 'v1';

let currentMode = 'base';
let isLoggedIn = false;
let currentPDFUrl = '';
let allCantici = [];
let cachedPDFs = {};

// ============================================================
// INIT
// ============================================================
window.onload = async function () {
    updateStatusBanner();
    setupOnlineOfflineListeners();

    showInfoMessage('⏳ Caricamento cantici...');
    document.getElementById('loading').classList.add('show');

    try {
        // Carica database cantici
        const data = await loadDatabase();
        allCantici = data;
        displayAllCantici();
        document.getElementById('loading').classList.remove('show');
        showInfoMessage(`✅ Caricati ${allCantici.length} cantici`);

        // DOWNLOAD AUTOMATICO in background
        if (navigator.onLine) {
            startAutomaticCaching();
        }
    } catch (error) {
        console.error('Errore:', error);
        document.getElementById('loading').classList.remove('show');
        showInfoMessage('⚠️ Errore caricamento. Uso dati offline.');
        
        // Prova a usare cache
        const cached = loadFromCache();
        if (cached && cached.cantici) {
            allCantici = cached.cantici;
            cachedPDFs = cached.pdfs || {};
            displayAllCantici();
        }
    }
};

// ============================================================
// DATABASE CANTICI
// ============================================================
async function loadDatabase() {
    // Prova prima la cache
    const cached = loadFromCache();
    if (cached && cached.cantici && !navigator.onLine) {
        console.log('📦 Uso database dalla cache (offline)');
        cachedPDFs = cached.pdfs || {};
        return cached.cantici;
    }

    // Scarica da GitHub
    const response = await fetch(`${REPO_CANTICI}/database.json`);
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    
    const data = await response.json();
    
    // Salva in cache
    saveToCache({ cantici: data, pdfs: cachedPDFs });
    
    return data;
}

// ============================================================
// CACHE AUTOMATICA PDF
// ============================================================
async function startAutomaticCaching() {
    const cached = loadFromCache();
    cachedPDFs = cached?.pdfs || {};

    const urlsToCache = allCantici.map(c => 
        `${REPO_CANTICI}/base/${c.filename}`
    );

    // Conta quanti sono già in cache
    const alreadyCached = urlsToCache.filter(url => cachedPDFs[url]).length;
    
    if (alreadyCached >= urlsToCache.length) {
        console.log('✅ Tutti i PDF già in cache');
        updateStatusBanner('online');
        return;
    }

    console.log(`📥 Download automatico: ${alreadyCached}/${urlsToCache.length}`);
    updateStatusBanner('downloading', 0, urlsToCache.length);

    let downloaded = alreadyCached;

    for (const url of urlsToCache) {
        // Salta se già in cache
        if (cachedPDFs[url]) continue;

        try {
            const response = await fetch(url);
            if (response.ok) {
                const blob = await response.blob();
                const base64 = await blobToBase64(blob);
                
                // Salva in memoria
                cachedPDFs[url] = {
                    data: base64,
                    type: 'application/pdf',
                    cached: new Date().toISOString()
                };

                downloaded++;
                updateStatusBanner('downloading', downloaded, urlsToCache.length);

                // Salva ogni 10 PDF
                if (downloaded % 10 === 0) {
                    saveToCache({ cantici: allCantici, pdfs: cachedPDFs });
                }
            }
        } catch (err) {
            console.warn(`Errore download: ${url.split('/').pop()}`);
        }

        // Pausa per non sovraccaricare
        await sleep(100);
    }

    // Salva tutto
    saveToCache({ cantici: allCantici, pdfs: cachedPDFs });
    console.log(`✅ Download completato: ${downloaded}/${urlsToCache.length}`);
    updateStatusBanner('online');
    displayAllCantici(); // Aggiorna i badge
    showInfoMessage(`✅ ${downloaded} cantici ora disponibili offline!`);
}

// ============================================================
// STORAGE LOCALE
// ============================================================
function saveToCache(data) {
    try {
        const toSave = {
            version: STORAGE_VERSION,
            timestamp: new Date().toISOString(),
            ...data
        };
        localStorage.setItem(STORAGE_KEY, JSON.stringify(toSave));
        console.log('💾 Cache salvata');
    } catch (e) {
        console.error('Errore salvataggio cache:', e);
        // Se localStorage è pieno, elimina vecchi PDF
        if (e.name === 'QuotaExceededError') {
            console.warn('⚠️ Storage pieno, salvo solo database');
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                version: STORAGE_VERSION,
                cantici: data.cantici,
                pdfs: {}
            }));
        }
    }
}

function loadFromCache() {
    try {
        const data = localStorage.getItem(STORAGE_KEY);
        if (!data) return null;
        
        const parsed = JSON.parse(data);
        if (parsed.version !== STORAGE_VERSION) {
            console.log('⚠️ Versione cache obsoleta');
            return null;
        }
        
        return parsed;
    } catch (e) {
        console.error('Errore lettura cache:', e);
        return null;
    }
}

function isPDFCached(url) {
    return cachedPDFs[url] !== undefined;
}

// ============================================================
// STATUS BANNER (ICONA ANGOLO)
// ============================================================
function updateStatusBanner(status, progress, total) {
    const banner = document.getElementById('statusBanner');
    const icon = banner.querySelector('.status-icon');

    banner.className = 'status-banner';

    if (status === 'downloading') {
        banner.classList.add('downloading');
        icon.textContent = '📥';
        banner.setAttribute('data-tooltip', `Download ${progress}/${total}`);
    } else if (!navigator.onLine) {
        banner.classList.add('offline');
        icon.textContent = '📵';
        banner.setAttribute('data-tooltip', 'Offline');
    } else {
        banner.classList.add('online');
        icon.textContent = '🌐';
        banner.setAttribute('data-tooltip', 'Online');
    }
}

function setupOnlineOfflineListeners() {
    window.addEventListener('online', () => {
        updateStatusBanner('online');
        showInfoMessage('✅ Connessione ripristinata');
        // Riprendi download se interrotto
        if (Object.keys(cachedPDFs).length < allCantici.length) {
            startAutomaticCaching();
        }
    });

    window.addEventListener('offline', () => {
        updateStatusBanner('offline');
        showInfoMessage('📵 Modalità offline');
    });
}

// ============================================================
// MODALITÀ
// ============================================================
function selectMode(mode) {
    document.querySelectorAll('.mode-card').forEach(c => c.classList.remove('active'));
    document.querySelector(`[data-mode="${mode}"]`).classList.add('active');

    if (mode === 'musicisti' && !isLoggedIn) {
        showLogin();
    } else {
        currentMode = mode;
        showInfoMessage(mode === 'base' ? 'Modalità Cantici Base' : 'Modalità Musicisti');
        displayAllCantici();
    }
}

function showLogin() {
    document.getElementById('loginModal').classList.add('show');
}

function closeLogin() {
    document.getElementById('loginModal').classList.remove('show');
    if (!isLoggedIn) selectMode('base');
}

async function handleLogin(event) {
    event.preventDefault();
    const username = document.getElementById('username').value;
    const password = document.getElementById('password').value;

    try {
        const response = await fetch('api-musicisti.php?action=login', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ username, password })
        });

        const data = await response.json();

        if (data.success) {
            isLoggedIn = true;
            currentMode = 'musicisti';
            localStorage.setItem('musicisti-token', data.token || '1');
            localStorage.setItem('musicisti-name', data.user.name);
            closeLogin();
            showInfoMessage(`✅ Benvenuto ${data.user.name}!`);
            displayAllCantici();
        } else {
            alert('❌ Credenziali non valide');
        }
    } catch (error) {
        // Prova accesso offline
        const savedToken = localStorage.getItem('musicisti-token');
        if (savedToken) {
            isLoggedIn = true;
            currentMode = 'musicisti';
            const name = localStorage.getItem('musicisti-name') || 'Musicista';
            closeLogin();
            showInfoMessage(`✅ Benvenuto ${name} (offline)`);
            displayAllCantici();
        } else {
            alert('❌ Errore connessione');
        }
    }
}

// ============================================================
// RICERCA
// ============================================================
function searchCantici() {
    const term = document.getElementById('searchInput').value.toLowerCase();
    if (!term) {
        displayAllCantici();
        return;
    }

    const filtered = allCantici.filter(c =>
        c.numero.toString().includes(term) ||
        c.titolo.toLowerCase().includes(term) ||
        c.categoria.toLowerCase().includes(term)
    );

    displayCantici(filtered);
}

function quickSearch(n) {
    document.getElementById('searchInput').value = n;
    searchCantici();
}

function displayAllCantici() {
    displayCantici(allCantici);
}

function displayCantici(cantici) {
    const grid = document.getElementById('resultsGrid');

    if (!cantici.length) {
        grid.innerHTML = `
            <div style="grid-column:1/-1;text-align:center;padding:60px 20px;">
                <span style="font-size:4rem;">🔍</span>
                <h3 style="color:var(--dark-gray);margin:20px 0;">Nessun cantico trovato</h3>
                <p style="color:#888;">Prova con altri termini</p>
            </div>`;
        return;
    }

    grid.innerHTML = cantici.map(c => {
        const url = `${REPO_CANTICI}/base/${c.filename}`;
        const cached = isPDFCached(url);

        return `
        <div class="cantico-card" onclick="openCantico(${c.numero})">
            <span class="cantico-number">#${c.numero}</span>
            <div class="cantico-title">${c.titolo}</div>
            <div class="cantico-meta">
                <span>📂</span>
                <span>${c.categoria}</span>
                ${cached ? '<span class="offline-badge">📵 Offline</span>' : ''}
            </div>
            ${currentMode === 'musicisti' ? '<span class="badge-musicisti">🎸 Con accordi</span>' : ''}
        </div>`;
    }).join('');
}

// ============================================================
// APERTURA PDF
// ============================================================
let pdfControlsTimeout;

function openCantico(numero) {
    const cantico = allCantici.find(c => c.numero === numero);
    let pdfPath;

    if (currentMode === 'base') {
        pdfPath = cantico?.filename
            ? `${REPO_CANTICI}/base/${cantico.filename}`
            : `${REPO_CANTICI}/base/${numero.toString().padStart(4, '0')}.pdf`;
    } else {
        if (cantico?.filename) {
            const base = cantico.filename.replace('.pdf', '');
            pdfPath = `${REPO_CANTICI}/musicisti/${base}-accordi.pdf`;
        } else {
            pdfPath = `${REPO_CANTICI}/musicisti/${numero.toString().padStart(4, '0')}-accordi.pdf`;
        }
    }

    currentPDFUrl = pdfPath;

    const viewer = document.getElementById('pdfViewer');
    viewer.classList.add('show');
    
    document.getElementById('pdfLoading').style.display = 'block';
    document.getElementById('pdfFrame').style.display = 'none';

    // Setup auto-hide per il pulsante chiudi
    setupPDFControlsAutoHide();

    console.log(`📄 Apertura: ${pdfPath}`);

    if (isPDFCached(pdfPath)) {
        loadPDFFromCache(pdfPath);
    } else {
        loadPDFFromNetwork(pdfPath);
    }
}

function setupPDFControlsAutoHide() {
    const viewer = document.getElementById('pdfViewer');
    const controls = document.getElementById('pdfControls');
    
    // Mostra controlli
    controls.classList.remove('fade-out');
    
    // Auto-hide dopo 3 secondi
    clearTimeout(pdfControlsTimeout);
    pdfControlsTimeout = setTimeout(() => {
        controls.classList.add('fade-out');
    }, 3000);
    
    // Mostra al movimento del mouse
    viewer.onmousemove = () => {
        controls.classList.remove('fade-out');
        clearTimeout(pdfControlsTimeout);
        pdfControlsTimeout = setTimeout(() => {
            controls.classList.add('fade-out');
        }, 3000);
    };
    
    // Mostra al touch su mobile
    viewer.ontouchstart = () => {
        controls.classList.remove('fade-out');
        clearTimeout(pdfControlsTimeout);
        pdfControlsTimeout = setTimeout(() => {
            controls.classList.add('fade-out');
        }, 3000);
    };
}

function loadPDFFromCache(url) {
    const cached = cachedPDFs[url];
    if (!cached) {
        loadPDFFromNetwork(url);
        return;
    }

    const iframe = document.getElementById('pdfFrame');
    const loading = document.getElementById('pdfLoading');

    // Converte base64 a blob URL
    const blob = base64ToBlob(cached.data, cached.type);
    const blobUrl = URL.createObjectURL(blob);

    iframe.src = blobUrl;
    iframe.onload = () => {
        loading.style.display = 'none';
        iframe.style.display = 'block';
        console.log('✅ PDF dalla cache');
    };
}

function loadPDFFromNetwork(pdfUrl) {
    const iframe = document.getElementById('pdfFrame');
    const loading = document.getElementById('pdfLoading');

    const pdfJsUrl = `https://mozilla.github.io/pdf.js/web/viewer.html?file=${encodeURIComponent(pdfUrl)}`;
    const timestamp = new Date().getTime();
    const googleDocsUrl = `https://docs.google.com/viewer?url=${encodeURIComponent(pdfUrl)}&embedded=true&t=${timestamp}`;
    const directUrl = pdfUrl;

    let currentMethod = 0;
    const methods = [pdfJsUrl, googleDocsUrl, directUrl];

    function tryNext() {
        if (currentMethod >= methods.length) {
            loading.innerHTML = `
                <div style="color:#ff6b6b;text-align:center;padding:40px;">
                    <h3>❌ PDF non disponibile</h3>
                    <p>${!navigator.onLine ? '📵 Sei offline' : 'File non trovato'}</p>
                    <button onclick="downloadPDF()" style="margin-top:20px;padding:10px 20px;background:#4CAF50;color:white;border:none;border-radius:6px;cursor:pointer;">
                        ⬇️ Scarica PDF
                    </button>
                </div>`;
            return;
        }

        iframe.src = methods[currentMethod];
        const timeout = setTimeout(() => { currentMethod++; tryNext(); }, 5000);

        iframe.onload = () => {
            clearTimeout(timeout);
            loading.style.display = 'none';
            iframe.style.display = 'block';
        };

        iframe.onerror = () => {
            clearTimeout(timeout);
            currentMethod++;
            tryNext();
        };
    }

    tryNext();
}

function closePDF() {
    const viewer = document.getElementById('pdfViewer');
    viewer.classList.remove('show');
    document.getElementById('pdfFrame').src = '';
    
    // Reset controlli
    clearTimeout(pdfControlsTimeout);
    viewer.onmousemove = null;
    viewer.ontouchstart = null;
}

function downloadPDF() {
    if (currentPDFUrl) {
        const a = document.createElement('a');
        a.href = currentPDFUrl;
        a.download = currentPDFUrl.split('/').pop();
        a.target = '_blank';
        a.click();
    }
}

function openInNewTab() {
    if (currentPDFUrl) window.open(currentPDFUrl, '_blank');
}

// ============================================================
// UTILITY
// ============================================================
function showInfoMessage(text) {
    const el = document.getElementById('infoMessage');
    document.getElementById('infoText').textContent = text;
    el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), 5000);
}

async function blobToBase64(blob) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.onloadend = () => resolve(reader.result.split(',')[1]);
        reader.onerror = reject;
        reader.readAsDataURL(blob);
    });
}

function base64ToBlob(base64, type) {
    const binary = atob(base64);
    const array = new Uint8Array(binary.length);
    for (let i = 0; i < binary.length; i++) {
        array[i] = binary.charCodeAt(i);
    }
    return new Blob([array], { type });
}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

// Menu hamburger
document.querySelector('.icon-hamburger')?.addEventListener('click', () => {
    document.body.classList.toggle('menu-open');
});

document.addEventListener('click', e => {
    const menu = document.querySelector('.header__menu');
    const ham = document.querySelector('.icon-hamburger');
    if (document.body.classList.contains('menu-open') &&
        !menu?.contains(e.target) && !ham?.contains(e.target)) {
        document.body.classList.remove('menu-open');
    }
});

document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        if (document.getElementById('loginModal').classList.contains('show')) closeLogin();
        if (document.getElementById('pdfViewer').classList.contains('show')) closePDF();
    }
});