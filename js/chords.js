/* js/chords.js - Motore accordi, notazione italiana (Do Re Mi Fa Sol La Si) */
(function (w) {
  'use strict';

  const ROOTS = { DO: 0, RE: 2, MI: 4, FA: 5, SOL: 7, LA: 9, SI: 11 };
  const SHARP = ['Do', 'Do#', 'Re', 'Re#', 'Mi', 'Fa', 'Fa#', 'Sol', 'Sol#', 'La', 'La#', 'Si'];
  const FLAT  = ['Do', 'Reb', 'Re', 'Mib', 'Mi', 'Fa', 'Solb', 'Sol', 'Lab', 'La', 'Sib', 'Si'];

  // Radice + alterazione, suffisso (-, 7, maj7, sus4, dim...), eventuale basso (/Si)
  const N = '(do|re|mi|fa|sol|la|si)([#b\u266F\u266D]?)';
  const SUF = '((?:-|m|M|maj|min|sus|add|dim|aug|\\+|\u00B0|\\d|#|b|\\(|\\))*)';
  const CHORD_RE = new RegExp('^' + N + SUF + '(?:/' + N + ')?$', 'i');
  const KEY_RE = /^(Do|Re|Mi|Fa|Sol|La|Si)(#|b)?(-?)$/i;

  // Tonalità che si scrivono con i bemolle
  const FLAT_MAJOR = [5, 10, 3, 8, 1, 6]; // Fa Sib Mib Lab Reb Solb
  const FLAT_MINOR = [2, 7, 0, 5, 10, 3]; // Re- Sol- Do- Fa- Sib- Mib-

  // Gradi della scala: [semitoni dalla tonica, qualità]
  const DEG_MAJOR = [[0, ''], [2, '-'], [4, '-'], [5, ''], [7, ''], [9, '-'], [11, 'dim']];
  const DEG_MINOR = [[0, '-'], [2, 'dim'], [3, ''], [5, '-'], [7, '-'], [8, ''], [10, '']];

  function idxOf(root, acc) {
    const a = /[#\u266F]/.test(acc) ? 1 : /[b\u266D]/.test(acc) ? -1 : 0;
    return (ROOTS[root.toUpperCase()] + a + 12) % 12;
  }

  function parse(tok) {
    let t = String(tok).trim();
    if (/^\(.*\)$/.test(t)) t = t.slice(1, -1);
    const m = CHORD_RE.exec(t);
    if (!m) return null;
    return {
      idx: idxOf(m[1], m[2]),
      suf: m[3] || '',
      bass: m[4] ? idxOf(m[4], m[5]) : null,
      upper: m[1] === m[1].toUpperCase()
    };
  }

  function name(idx, flat, upper) {
    const n = (flat ? FLAT : SHARP)[((idx % 12) + 12) % 12];
    const base = n.replace(/[#b]$/, '');
    const acc = n.slice(base.length);
    return (upper ? base.toUpperCase() : base) + acc;
  }

  function transpose(tok, shift, flat) {
    const c = parse(tok);
    if (!c) return tok;
    return name(c.idx + shift, flat, c.upper) + c.suf +
      (c.bass !== null ? '/' + name(c.bass + shift, flat, c.upper) : '');
  }

  function keyInfo(k) {
    const m = KEY_RE.exec(k || '');
    return m ? { idx: idxOf(m[1], m[2] || ''), minor: !!m[3] } : null;
  }

  function usesFlats(idx, minor) {
    return (minor ? FLAT_MINOR : FLAT_MAJOR).indexOf(idx) !== -1;
  }

  function keyName(idx, minor) {
    return name(idx, usesFlats(idx, minor), false) + (minor ? '-' : '');
  }

  function keyList(minor) {
    return Array.from({ length: 12 }, (_, i) => keyName(i, minor));
  }

  function shiftBetween(from, to) {
    return (keyInfo(to).idx - keyInfo(from).idx + 12) % 12;
  }

  // Accordi della scala della tonalità scelta (+ dominante con settima)
  function diatonic(key, upper) {
    const k = keyInfo(key);
    const f = usesFlats(k.idx, k.minor);
    const list = (k.minor ? DEG_MINOR : DEG_MAJOR).map(d => name(k.idx + d[0], f, upper) + d[1]);
    list.push(name(k.idx + 7, f, upper) + '7');
    if (k.minor) list.push(name(k.idx + 7, f, upper)); // V maggiore (minore armonica)
    return list;
  }

  // ---------- Conversione: accordi sopra le parole -> [Accordo]testo ----------
  function clean(t) { return t.replace(/^\((.*)\)$/, '$1'); }
  function isChordTok(t) { return !!parse(t); }

  function isChordLine(line) {
    const toks = line.match(/\S+/g);
    return !!toks && toks.every(isChordTok);
  }

  function convert(text) {
    const L = String(text).replace(/\r/g, '').replace(/\t/g, '    ').split('\n');
    const out = [];
    for (let i = 0; i < L.length; i++) {
      const line = L[i];
      const toks = Array.from(line.matchAll(/\S+/g));
      const labels = toks.filter(t => /:$/.test(t[0]));
      const chords = toks.filter(t => isChordTok(t[0]));
      if (toks.length && chords.length && chords.length + labels.length === toks.length) {
        const next = L[i + 1];
        const lyricNext = next !== undefined && next.trim() !== '' && !isChordLine(next) && /^\s*#/.test(next) === false;
        if (!labels.length && lyricNext) {
          let res = '', p = 0;
          chords.forEach(t => {
            const c = Math.min(t.index, next.length);
            res += next.slice(p, c) + '[' + clean(t[0]) + ']';
            p = c;
          });
          out.push(res + next.slice(p));
          i++;
          continue;
        }
        out.push(toks.map(t => isChordTok(t[0]) ? '[' + clean(t[0]) + ']' : t[0]).join(' '));
        continue;
      }
      out.push(line);
    }
    return out.join('\n');
  }

  // ---------- Parsing del testo [Accordo]parole ----------
  function parseSong(testo) {
    let n = 0;
    const lines = String(testo || '').replace(/\r/g, '').split('\n').map(raw => {
      if (/^\s*#/.test(raw)) return { head: raw.replace(/^\s*#\s*/, '') };
      if (!raw.trim()) return { blank: true };
      const parts = raw.split(/\[([^\]]*)\]/);
      const segs = [];
      if (parts[0]) segs.push({ chord: null, text: parts[0] });
      for (let i = 1; i < parts.length; i += 2) {
        segs.push({ chord: parts[i], i: n++, text: parts[i + 1] || '' });
      }
      return { segs: segs, hasChords: parts.length > 1 };
    });
    return { lines: lines, count: n };
  }

  function chordsOf(testo) {
    const out = [];
    parseSong(testo).lines.forEach(l => (l.segs || []).forEach(s => { if (s.chord !== null) out[s.i] = s.chord; }));
    return out;
  }

  // ---------- Rendering (solo textContent: nessun rischio XSS) ----------
  function render(el, testo, opt) {
    opt = opt || {};
    const shift = opt.shift || 0, flat = !!opt.flat, ov = opt.overrides || {};
    el.textContent = '';
    parseSong(testo).lines.forEach(l => {
      const row = document.createElement('div');
      if (l.head !== undefined) {
        row.className = 'hd';
        row.textContent = l.head;
      } else if (l.blank) {
        row.className = 'sp';
      } else {
        row.className = 'ln';
        l.segs.forEach(s => {
          const seg = document.createElement('span');
          seg.className = 'seg';
          if (l.hasChords) {
            const ch = document.createElement('span');
            ch.className = 'ch';
            if (s.chord !== null) {
              const has = Object.prototype.hasOwnProperty.call(ov, s.i) && ov[s.i];
              const shown = has ? ov[s.i] : s.chord.split(/\s+/).filter(Boolean).map(t => transpose(t, shift, flat)).join(' ');
              ch.textContent = shown;
              if (has) ch.classList.add('mod');
              if (opt.onChord) {
                ch.classList.add('tap');
                ch.tabIndex = 0;
                ch.setAttribute('role', 'button');
                const fire = () => opt.onChord(s.i, shown, s.chord);
                ch.addEventListener('click', fire);
                ch.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); fire(); } });
              }
            }
            seg.appendChild(ch);
          }
          const tx = document.createElement('span');
          tx.className = 'tx';
          tx.textContent = s.text;
          seg.appendChild(tx);
          row.appendChild(seg);
        });
      }
      el.appendChild(row);
    });
  }

  w.Chords = {
    parse: parse, name: name, transpose: transpose, keyInfo: keyInfo, keyName: keyName,
    keyList: keyList, usesFlats: usesFlats, shiftBetween: shiftBetween, diatonic: diatonic,
    convert: convert, parseSong: parseSong, chordsOf: chordsOf, render: render
  };
})(window);
