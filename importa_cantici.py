#!/usr/bin/env python3
"""
importa_cantici.py - Estrae il TESTO dai PDF dei cantici base (repo GitHub)
e genera data/songs/<numero>.json per l'area musicisti (senza accordi).

Uso:  pip install pdfplumber
      python3 importa_cantici.py [cartella_output]
"""
import io, json, os, re, statistics, sys, urllib.parse, urllib.request
import pdfplumber

REPO = 'https://raw.githubusercontent.com/kikkogarins/Adi-Cuneo-Cantici/main'
OUT = sys.argv[1] if len(sys.argv) > 1 else 'data/songs'
COL_W = 260               # larghezza colonna: i cantici vicini iniziano ~270pt piu' a destra
LABEL_RE = re.compile(r'^([A-ZÀ-Ý][A-ZÀ-Ý0-9 ]{2,24}):\s*(.*)$')
HEAD_RE = re.compile(r'^\d{3,4}\.$')


def fetch(url):
    with urllib.request.urlopen(url, timeout=30) as r:
        return r.read()


def page_lines(page, numero, first):
    """Righe di testo del solo cantico visibile: colonna sotto il suo titolo, fino al titolo successivo."""
    allw = [w for w in page.extract_words()
            if -1 <= w['top'] < page.height and 0 <= w['x0'] < page.width]
    mine = [w for w in allw if w['text'] == f'{numero}.' and w['top'] > 20]
    if mine:
        h = min(mine, key=lambda w: w['top'])
        left, start = h['x0'], h['top']
    elif first:
        return None
    else:
        left, start = min(w['x0'] for w in allw), -1
    # bordo destro: primo titolo di un altro cantico che parte piu' a destra (anche fuori pagina)
    everyone = page.extract_words()
    other = [w['x0'] for w in everyone if HEAD_RE.match(w['text']) and w['text'] != f'{numero}.'
             and w['x0'] > left + 20]
    right = min([left + COL_W] + [x - 3 for x in other])
    col = [w for w in allw if left - 3 <= w['x0'] < right and w['top'] >= start]
    nxt = [w for w in col if HEAD_RE.match(w['text']) and abs(w['x0'] - left) <= 4
           and w['top'] > start + 2 and w['text'] != f'{numero}.']
    end = min((w['top'] for w in nxt), default=page.height + 1) - 1
    sel = [w for w in col if w['top'] < end]
    rows = {}
    for w in sel:
        key = next((k for k in rows if abs(k - w['top']) <= 3), w['top'])
        rows.setdefault(key, []).append(w)
    lines = []
    for top in sorted(rows):
        ws = sorted(rows[top], key=lambda w: w['x0'])
        lines.append((top, ' '.join(w['text'] for w in ws).strip()))
    if lines and re.match(r'^%d\.' % numero, lines[0][1]):
        lines = lines[1:]
    return lines


def to_text(lines):
    if not lines:
        return ''
    gaps = [b[0] - a[0] for a, b in zip(lines, lines[1:])]
    base = statistics.median(gaps) if gaps else 18
    out, prev = [], None
    for top, text in lines:
        text = text.replace('[', '').replace(']', '')
        if prev is not None and top - prev > base * 1.25:
            out.append('')
        prev = top
        m = LABEL_RE.match(text)
        if not text.strip():
            continue
        if m:
            out.append('# ' + m.group(1).title())
            if m.group(2):
                out.append(m.group(2))
        else:
            out.append(text)
    # compatta righe vuote
    txt = '\n'.join(out)
    return re.sub(r'\n{3,}', '\n\n', txt).strip()


def main():
    os.makedirs(OUT, exist_ok=True)
    db = json.loads(fetch(REPO + '/database.json'))
    problemi = []
    for c in db:
        n, fn = c['numero'], c['filename']
        try:
            data = fetch(REPO + '/base/' + urllib.parse.quote(fn))
            with pdfplumber.open(io.BytesIO(data)) as pdf:
                lines = []
                for i, pg in enumerate(pdf.pages):
                    got = page_lines(pg, n, i == 0)
                    if got is None:
                        raise ValueError('titolo non trovato nella pagina')
                    lines += got
            testo = to_text(lines)
            if len(testo) < 20:
                raise ValueError('testo troppo corto')
        except Exception as e:
            problemi.append((n, c['titolo'], str(e)))
            continue
        song = {'numero': n, 'titolo': c['titolo'], 'key': 'Do', 'testo': testo,
                'categoria': c.get('categoria', ''), 'daAccordare': True}
        with open(os.path.join(OUT, f'{n}.json'), 'w', encoding='utf-8') as f:
            json.dump(song, f, ensure_ascii=False, indent=2)
    print(f'Creati {len(db) - len(problemi)} / {len(db)} cantici in {OUT}')
    for p in problemi:
        print('DA CONTROLLARE:', *p)


if __name__ == '__main__':
    main()
