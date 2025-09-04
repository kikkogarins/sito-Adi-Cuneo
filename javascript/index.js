document.addEventListener('DOMContentLoaded', function() {
    // Gestione filtri galleria
    const filterButtons = document.querySelectorAll('.filter-btn');
    const galleryItems = document.querySelectorAll('.gallery-item');
    
    if (filterButtons.length > 0) {
        filterButtons.forEach(button => {
            button.addEventListener('click', () => {
                // Rimuovi classe active da tutti i bottoni
                filterButtons.forEach(btn => btn.classList.remove('active'));
                
                // Aggiungi classe active al bottone cliccato
                button.classList.add('active');
                
                // Ottieni il filtro dal bottone
                const filter = button.getAttribute('data-filter');
                
                // Filtra gli elementi della galleria
                galleryItems.forEach(item => {
                    if (filter === 'all' || item.getAttribute('data-category') === filter) {
                        item.style.display = 'block';
                    } else {
                        item.style.display = 'none';
                    }
                });
            });
        });
    }
    
    // Versetto del giorno dinamico
    const verses = [
        {
            text: "Poiché io so i pensieri che medito per voi, dice l'Eterno, pensieri di pace e non di male, per darvi un futuro e una speranza.",
            reference: "Geremia 29:11"
        },
        {
            text: "Il Signore è il mio pastore: non manco di nulla.",
            reference: "Salmo 23:1"
        },
        {
            text: "Perché Dio ha tanto amato il mondo, che ha dato il suo unigenito Figlio, affinché chiunque crede in lui non perisca, ma abbia vita eterna.",
            reference: "Giovanni 3:16"
        },
        {
            text: "Io posso ogni cosa in colui che mi fortifica.",
            reference: "Filippesi 4:13"
        },
        {
            text: "Non temere, perché io sono con te; non ti smarrire, perché io sono il tuo Dio. Io ti fortifico, io ti soccorro, io ti sostengo con la destra della mia giustizia.",
            reference: "Isaia 41:10"
        }
    ];
    
    const verseContainer = document.querySelector('.verse-box');
    if (verseContainer) {
        // Scegli un versetto casuale
        const randomVerse = verses[Math.floor(Math.random() * verses.length)];
        
        // Aggiorna il contenuto del versetto
        const blockquote = verseContainer.querySelector('blockquote');
        const cite = verseContainer.querySelector('cite');
        
        if (blockquote && cite) {
            blockquote.textContent = randomVerse.text;
            cite.textContent = `— ${randomVerse.reference}`;
        }
    }
});