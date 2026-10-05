/* ============================================================
 * Aleanor Grid — ตัวคำนวณสูตร
 *   ใช้ได้ทั้งในเบราว์เซอร์ (window.GridEngine) และ node (require) สำหรับทดสอบ
 *
 * รองรับ: อ้างอิงข้ามชีต ('ชื่อชีต'!A1, ชีต!$A$1:B9), ทั้งคอลัมน์ (A:A) / ทั้งแถว (1:1)
 *   ตัวดำเนินการ + - * / ^ & % = <> < > <= >= และฟังก์ชันที่ใช้บ่อยในงานจริง (ดู FN ด้านล่าง)
 * ฟังก์ชันที่ยังไม่รองรับ → #NAME? (ตัวแก้ไขจะแสดงค่าที่ Excel คำนวณไว้แทน ถ้ามี)
 * ไม่มีการรันโค้ดจากสูตร — แยกคำและตีความเองทั้งหมด
 * ============================================================ */
(function(root, factory){
  if(typeof module === 'object' && module.exports) module.exports = factory();
  else root.GridEngine = factory();
})(typeof self !== 'undefined' ? self : this, function(){
  'use strict';

  /* ---------- ค่าพิเศษ ---------- */
  function Err(code, unsupported){ this.e = code; this.unsupported = !!unsupported; }
  Err.prototype.toString = function(){ return this.e; };
  var EMPTY = { empty: true, toString: function(){ return ''; } };
  var E = {
    div0: function(){ return new Err('#DIV/0!'); }, na: function(){ return new Err('#N/A'); },
    value: function(){ return new Err('#VALUE!'); }, ref: function(){ return new Err('#REF!'); },
    name: function(unsup){ return new Err('#NAME?', unsup); }, num: function(){ return new Err('#NUM!'); }
  };
  function isErr(v){ return v instanceof Err; }

  /* ---------- ชื่อคอลัมน์ ---------- */
  function colName(i){ var s = ''; i = i | 0; while(true){ s = String.fromCharCode(65 + (i % 26)) + s; i = Math.floor(i / 26) - 1; if(i < 0) break; } return s; }
  function colIndex(n){ n = String(n).toUpperCase(); var v = 0; for(var i = 0; i < n.length; i++) v = v * 26 + (n.charCodeAt(i) - 64); return v - 1; }
  function parseRef(s){
    var m = String(s).match(/^\$?([A-Za-z]{1,3})\$?(\d{1,7})$/);
    return m ? { c: colIndex(m[1]), r: +m[2] } : null;
  }

  /* ============================================================
   * แยกคำ (tokenizer)
   * ============================================================ */
  var OPS2 = { '<=': 1, '>=': 1, '<>': 1 };
  function tokenize(src){
    var t = [], i = 0, n = src.length, m;
    var rest = function(){ return src.slice(i); };
    while(i < n){
      var ch = src.charAt(i);
      if(ch === ' ' || ch === '\t' || ch === '\n' || ch === '\r'){ i++; continue; }
      if(ch === '"'){
        var s = ''; i++;
        while(i < n){ if(src.charAt(i) === '"'){ if(src.charAt(i + 1) === '"'){ s += '"'; i += 2; continue; } break; } s += src.charAt(i++); }
        i++; t.push({ k: 'str', v: s }); continue;
      }
      if(ch === '#'){
        m = rest().match(/^#(?:DIV\/0!|N\/A|VALUE!|REF!|NAME\?|NUM!|NULL!)/i);
        if(m){ t.push({ k: 'err', v: m[0].toUpperCase() }); i += m[0].length; continue; }
      }
      if(ch === "'"){
        var nm = ''; i++;
        while(i < n){ if(src.charAt(i) === "'"){ if(src.charAt(i + 1) === "'"){ nm += "'"; i += 2; continue; } break; } nm += src.charAt(i++); }
        i++;
        if(src.charAt(i) === '!'){ i++; t.push({ k: 'sheet', v: nm }); continue; }
        t.push({ k: 'name', v: nm }); continue;
      }
      if(/[0-9.]/.test(ch)){
        m = rest().match(/^\$?\d+:\$?\d+/);
        if(m){ t.push({ k: 'rows', v: m[0] }); i += m[0].length; continue; }
        m = rest().match(/^(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?/);
        if(m){ t.push({ k: 'num', v: parseFloat(m[0]) }); i += m[0].length; continue; }
      }
      if(OPS2[src.substr(i, 2)]){ t.push({ k: 'op', v: src.substr(i, 2) }); i += 2; continue; }
      if('+-*/^&=<>%'.indexOf(ch) >= 0){ t.push({ k: 'op', v: ch }); i++; continue; }
      if(ch === '('){ t.push({ k: '(' }); i++; continue; }
      if(ch === ')'){ t.push({ k: ')' }); i++; continue; }
      if(ch === ',' || ch === ';'){ t.push({ k: ',' }); i++; continue; }
      if(ch === ':'){ t.push({ k: ':' }); i++; continue; }
      if(ch === '{' || ch === '}'){ t.push({ k: ch }); i++; continue; }
      m = rest().match(/^[^\s"'#()+\-*\/^&=<>%,;:{}!]+/);
      if(m){
        var w = m[0]; i += w.length;
        if(src.charAt(i) === '!'){ i++; t.push({ k: 'sheet', v: w }); continue; }
        if(src.charAt(i) === '('){ t.push({ k: 'fn', v: w.toUpperCase() }); continue; }
        if(/^\$?[A-Za-z]{1,3}$/.test(w) && src.charAt(i) === ':'){
          var cm2 = src.slice(i).match(/^:(\$?[A-Za-z]{1,3})(?![\d$A-Za-z])/);
          if(cm2){ t.push({ k: 'cols', v: w + ':' + cm2[1] }); i += cm2[0].length; continue; }
        }
        if(parseRef(w)){ t.push({ k: 'ref', v: w }); continue; }
        var up = w.toUpperCase();
        if(up === 'TRUE' || up === 'FALSE'){ t.push({ k: 'bool', v: up === 'TRUE' }); continue; }
        t.push({ k: 'name', v: w }); continue;
      }
      throw new Error('bad char ' + ch);
    }
    return t;
  }

  /* ============================================================
   * แปลงเป็นโครงสร้างสูตร
   * ============================================================ */
  function parse(src){
    var t = tokenize(src), p = 0;
    function peek(){ return t[p]; }
    function next(){ return t[p++]; }
    function expect(k){ var x = next(); if(!x || x.k !== k) throw new Error('expected ' + k); return x; }
    var BIN = { '=': 1, '<>': 1, '<': 1, '>': 1, '<=': 1, '>=': 1, '&': 2, '+': 3, '-': 3, '*': 4, '/': 4, '^': 5 };

    function expr(minPrec){
      var left = unary();
      while(true){
        var x = peek();
        if(!x || x.k !== 'op' || !BIN[x.v] || BIN[x.v] < minPrec) break;
        next();
        var prec = BIN[x.v];
        var right = expr(x.v === '^' ? prec : prec + 1);
        left = { t: 'bin', op: x.v, a: left, b: right };
      }
      return left;
    }
    function unary(){
      var x = peek();
      if(x && x.k === 'op' && (x.v === '-' || x.v === '+')){ next(); var a = unary(); return x.v === '-' ? { t: 'neg', a: a } : a; }
      return postfix(primary());
    }
    function postfix(node){
      while(peek() && peek().k === 'op' && peek().v === '%'){ next(); node = { t: 'pct', a: node }; }
      return node;
    }
    function refNode(sheet, tok){
      if(tok.k === 'cols'){
        var cs = tok.v.split(':');
        return { t: 'range', sheet: sheet, c1: colIndex(cs[0].replace('$', '')), c2: colIndex(cs[1].replace('$', '')), r1: 1, r2: -1 };
      }
      if(tok.k === 'rows'){
        var rs = tok.v.replace(/\$/g, '').split(':');
        return { t: 'range', sheet: sheet, r1: +rs[0], r2: +rs[1], c1: 0, c2: -1 };
      }
      var a = parseRef(tok.v);
      if(peek() && peek().k === ':'){
        var save = p; next();
        var bt = peek();
        if(bt && bt.k === 'sheet'){ next(); bt = peek(); }
        if(bt && bt.k === 'ref'){
          next(); var b = parseRef(bt.v);
          return { t: 'range', sheet: sheet, r1: Math.min(a.r, b.r), c1: Math.min(a.c, b.c), r2: Math.max(a.r, b.r), c2: Math.max(a.c, b.c) };
        }
        p = save;
      }
      return { t: 'ref', sheet: sheet, r: a.r, c: a.c };
    }
    function primary(){
      var x = next();
      if(!x) throw new Error('unexpected end');
      switch(x.k){
        case 'num':  return { t: 'num', v: x.v };
        case 'str':  return { t: 'str', v: x.v };
        case 'bool': return { t: 'bool', v: x.v };
        case 'err':  return { t: 'err', v: x.v };
        case 'ref': case 'cols': case 'rows': return refNode(null, x);
        case 'sheet':
          var r = next();
          if(!r || (r.k !== 'ref' && r.k !== 'cols' && r.k !== 'rows')) throw new Error('bad sheet ref');
          return refNode(x.v, r);
        case '(':
          var e = expr(1); expect(')'); return e;
        case 'fn':
          expect('(');
          var args = [];
          if(peek() && peek().k === ')'){ next(); return { t: 'fn', name: x.v, args: args }; }
          while(true){
            if(peek() && peek().k === ','){ args.push({ t: 'missing' }); next(); continue; }
            args.push(peek() && peek().k === ')' ? { t: 'missing' } : expr(1));
            var s = next();
            if(!s) throw new Error('unclosed fn');
            if(s.k === ')') break;
            if(s.k !== ',') throw new Error('expected ,');
          }
          return { t: 'fn', name: x.v, args: args };
        case '{':
          var items = [];
          while(peek() && peek().k !== '}'){ items.push(expr(1)); if(peek() && peek().k === ',') next(); else break; }
          expect('}');
          return { t: 'array', items: items };
        case 'name': return { t: 'name', v: x.v };
      }
      throw new Error('unexpected ' + x.k);
    }
    var tree = expr(1);
    if(p < t.length) throw new Error('trailing tokens');
    return tree;
  }

  /* ============================================================
   * การแปลงชนิดข้อมูลแบบ Excel
   * ============================================================ */
  function numStr(s){
    s = String(s).trim();
    if(s === '') return null;
    var neg = false;
    if(/^\(.*\)$/.test(s)){ neg = true; s = s.slice(1, -1); }
    var pct = /%$/.test(s); if(pct) s = s.slice(0, -1);
    s = s.replace(/,/g, '');
    if(!/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/.test(s)) return null;
    var v = parseFloat(s); if(pct) v /= 100; return neg ? -v : v;
  }
  function toNum(v){
    if(typeof v === 'number') return v;
    if(v === EMPTY || v === null || v === undefined) return 0;
    if(typeof v === 'boolean') return v ? 1 : 0;
    if(isErr(v)) return v;
    var n = numStr(v); return n === null ? E.value() : n;
  }
  function toStr(v){
    if(v === EMPTY || v === null || v === undefined) return '';
    if(typeof v === 'boolean') return v ? 'TRUE' : 'FALSE';
    if(typeof v === 'number') return fmtGeneral(v);
    return String(v);
  }
  function toBool(v){
    if(typeof v === 'boolean') return v;
    if(typeof v === 'number') return v !== 0;
    if(v === EMPTY || v === null || v === undefined) return false;
    if(isErr(v)) return v;
    var s = String(v).toUpperCase();
    if(s === 'TRUE') return true; if(s === 'FALSE') return false;
    var n = numStr(v); return n === null ? E.value() : n !== 0;
  }
  function fmtGeneral(v){
    if(!isFinite(v)) return '#NUM!';
    if(Number.isInteger(v) && Math.abs(v) < 1e15) return String(v);
    var r = Math.round(v * 1e10) / 1e10;
    var s = String(r);
    if(/e/.test(s)) s = r.toPrecision(10).replace(/\.?0+e/, 'e');
    return s;
  }
  function rank(v){ return typeof v === 'number' ? 1 : (typeof v === 'boolean' ? 3 : 2); }
  function cmp(a, b){
    if(a === EMPTY) a = (typeof b === 'string') ? '' : (typeof b === 'boolean' ? false : 0);
    if(b === EMPTY) b = (typeof a === 'string') ? '' : (typeof a === 'boolean' ? false : 0);
    var ra = rank(a), rb = rank(b);
    if(ra !== rb) return ra < rb ? -1 : 1;
    if(ra === 2){ a = String(a).toLowerCase(); b = String(b).toLowerCase(); }
    return a < b ? -1 : (a > b ? 1 : 0);
  }

  /* ============================================================
   * วันที่แบบ Excel (serial 1900)
   * ============================================================ */
  var DAY = 86400000;
  function dateToSerial(y, m, d){ return (Date.UTC(y, m - 1, d) - Date.UTC(1899, 11, 30)) / DAY; }
  function serialToParts(s){
    var dt = new Date(Date.UTC(1899, 11, 30) + Math.floor(s) * DAY);
    var frac = s - Math.floor(s), secs = Math.round(frac * 86400);
    return { y: dt.getUTCFullYear(), m: dt.getUTCMonth() + 1, d: dt.getUTCDate(), dow: dt.getUTCDay(),
             hh: Math.floor(secs / 3600) % 24, mi: Math.floor(secs / 60) % 60, ss: secs % 60 };
  }

  /* ============================================================
   * รูปแบบตัวเลข
   * ============================================================ */
  var MON = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
  var MONF = ['January','February','March','April','May','June','July','August','September','October','November','December'];
  var DOW = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'], DOWF = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
  function pad(n, w){ n = String(n); while(n.length < w) n = '0' + n; return n; }
  function splitSections(code){
    var out = [], cur = '', q = false;
    for(var i = 0; i < code.length; i++){
      var ch = code.charAt(i);
      if(ch === '"') q = !q;
      if(ch === ';' && !q){ out.push(cur); cur = ''; continue; }
      cur += ch;
    }
    out.push(cur);
    return out;
  }
  function stripQuoted(s){ return s.replace(/"[^"]*"/g, '').replace(/\\./g, ''); }
  function format(value, code){
    if(isErr(value)) return value.e;
    if(value === EMPTY || value === null || value === undefined) return '';
    code = String(code || 'General');
    var sections = splitSections(code);
    var num = typeof value === 'number' ? value : (typeof value === 'boolean' ? null : numStr(value));
    if(num === null){
      var tsec = sections.length >= 4 ? sections[3] : (/@/.test(code) ? code : null);
      if(tsec !== null) return tsec.replace(/"([^"]*)"/g, '$1').replace(/@/g, toStr(value));
      return toStr(value);
    }
    var sec = sections[0], negSec = false;
    if(sections.length > 1 && num < 0 && sections[1] !== ''){ sec = sections[1]; negSec = true; num = Math.abs(num); }
    else if(sections.length > 2 && num === 0 && sections[2] !== '') sec = sections[2];
    if(/^\s*general\s*$/i.test(sec) || sec.trim() === '') return (negSec ? '-' : '') + fmtGeneral(num);
    sec = sec.replace(/\[[^\]]*\]/g, '');
    var plain = stripQuoted(sec);
    if(/[dmyhs]/i.test(plain) && !/[0#?]/.test(plain.replace(/s\.0+/gi, 's'))) return formatDate(num, sec);
    return formatNumber(num, sec, negSec);
  }
  function formatDate(serial, code){
    var P = serialToParts(serial), out = '', hasAmPm = /am\/pm|a\/p/i.test(code);
    var tokens = code.match(/"[^"]*"|\\.|am\/pm|a\/p|y+|m+|d+|h+|s+|[^"\\ymdhsa]+|./gi) || [];
    for(var i = 0; i < tokens.length; i++){
      var tk = tokens[i], lo = tk.toLowerCase();
      if(tk.charAt(0) === '"'){ out += tk.slice(1, -1); continue; }
      if(tk.charAt(0) === '\\'){ out += tk.charAt(1); continue; }
      if(lo === 'am/pm'){ out += P.hh < 12 ? 'AM' : 'PM'; continue; }
      if(lo === 'a/p'){ out += P.hh < 12 ? 'A' : 'P'; continue; }
      if(/^y+$/.test(lo)){ out += lo.length <= 2 ? pad(P.y % 100, 2) : String(P.y); continue; }
      if(/^d+$/.test(lo)){ out += lo.length === 1 ? P.d : lo.length === 2 ? pad(P.d, 2) : lo.length === 3 ? DOW[P.dow] : DOWF[P.dow]; continue; }
      if(/^h+$/.test(lo)){ var h = hasAmPm ? ((P.hh % 12) || 12) : P.hh; out += lo.length === 1 ? h : pad(h, 2); continue; }
      if(/^s+$/.test(lo)){ out += lo.length === 1 ? P.ss : pad(P.ss, 2); continue; }
      if(/^m+$/.test(lo)){
        var prev = tokens.slice(0, i).join('').replace(/"[^"]*"/g, '').replace(/[^a-z]/gi, '').slice(-1).toLowerCase();
        var nxt = tokens.slice(i + 1).join('').replace(/"[^"]*"/g, '').replace(/[^a-z]/gi, '').charAt(0).toLowerCase();
        if((prev === 'h' || nxt === 's') && lo.length <= 2){ out += lo.length === 1 ? P.mi : pad(P.mi, 2); continue; }
        out += lo.length === 1 ? P.m : lo.length === 2 ? pad(P.m, 2) : lo.length === 3 ? MON[P.m - 1] : MONF[P.m - 1];
        continue;
      }
      out += tk;
    }
    return out;
  }
  function formatNumber(num, code, negSection){
    var quoted = [];
    // ข้อความในเครื่องหมายคำพูดแทนด้วยอักษร private-use ตัวเดียว (ไม่มีเลข 0 ปนให้ถูกนับเป็นหลักตัวเลข)
    var work = code.replace(/"([^"]*)"|\\(.)/g, function(_, q, esc){ quoted.push(q !== undefined ? q : esc); return String.fromCharCode(0xE000 + quoted.length - 1); });
    work = work.replace(/_./g, ' ').replace(/\*./g, '');
    var back = function(s){ return s.replace(/[\uE000-\uF8FF]/g, function(ch){ var q = quoted[ch.charCodeAt(0) - 0xE000]; return q === undefined ? ch : q; }); };
    var first = work.search(/[#0?]/);
    if(first < 0) return back(work);
    var lastDigit = Math.max(work.lastIndexOf('#'), work.lastIndexOf('0'), work.lastIndexOf('?'));
    var end = lastDigit + 1;
    while(end < work.length && /[,%]/.test(work.charAt(end))) end++;
    var startBody = first;
    while(startBody > 0 && /[,.]/.test(work.charAt(startBody - 1))) startBody--;
    var pre = back(work.slice(0, startBody)), body = work.slice(startBody, end), post = back(work.slice(end));
    var pct = /%/.test(body) || /%/.test(post);
    if(pct) num *= 100;
    if(/%/.test(body)) post = '%' + post;
    var bodyNoPct = body.replace(/%/g, '');
    var trail = bodyNoPct.match(/,+$/);
    if(trail) num /= Math.pow(1000, trail[0].length);
    var parts = bodyNoPct.replace(/,+$/, '').split('.');
    var intPat = parts[0], decPat = parts[1] || '';
    var decs = (decPat.match(/[0#?]/g) || []).length, minDec = (decPat.match(/0/g) || []).length;
    var neg = num < 0 && !negSection; num = Math.abs(num);
    var fixed = num.toFixed(decs), ip = fixed.split('.')[0], dp = fixed.split('.')[1] || '';
    while(dp.length > minDec && dp.charAt(dp.length - 1) === '0') dp = dp.slice(0, -1);
    var minInt = (intPat.match(/0/g) || []).length;
    while(ip.length < minInt) ip = '0' + ip;
    if(ip === '0' && minInt === 0) ip = '';
    if(/,/.test(intPat)) ip = ip.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    var s = ip + (dp.length ? '.' + dp : '');
    if(s === '') s = '0';
    return (neg ? '-' : '') + pre + s + post;
  }

  /* ============================================================
   * ตัวคำนวณ
   * ============================================================ */
  function Engine(book){
    this.parsed = new Map();
    this.setBook(book);
  }
  Engine.prototype.setBook = function(book){
    this.book = book;
    this.idx = [];
    this.names();
    this.clear();
  };
  Engine.prototype.names = function(){
    var self = this; this.byName = {};
    (this.book.sheets || []).forEach(function(sh, i){ self.byName[String(sh.name).toLowerCase()] = i; });
  };
  Engine.prototype.clear = function(){ this.cache = new Map(); this.rangeCache = new Map(); this.busy = new Set(); };
  /** แจ้งว่าช่องเปลี่ยน (อัปเดตดัชนี + ล้างผลคำนวณ) */
  Engine.prototype.touch = function(sheetIndex, ref){
    var ix = this.idx[sheetIndex];
    if(ix){
      var a = parseRef(ref);
      if(a){
        var cell = this.book.sheets[sheetIndex].cells[ref];
        if(!ix.cols[a.c]) ix.cols[a.c] = [];
        ix.cols[a.c][a.r] = cell;
        if(cell){ if(a.r > ix.maxR) ix.maxR = a.r; if(a.c > ix.maxC) ix.maxC = a.c; }
      }
    }
    this.clear();
  };
  /** โครงสร้างเปลี่ยนมาก (ลบแถว/คอลัมน์ เพิ่ม/ลบ/เปลี่ยนชื่อชีต) → สร้างดัชนีใหม่ */
  Engine.prototype.reindex = function(sheetIndex){
    if(sheetIndex === undefined) this.idx = []; else this.idx[sheetIndex] = null;
    this.names(); this.clear();
  };
  Engine.prototype.index = function(si){
    var ix = this.idx[si];
    if(ix) return ix;
    var sh = this.book.sheets[si], cols = [], maxR = 0, maxC = 0;
    var cells = (sh && sh.cells) || {};
    for(var k in cells){
      if(!Object.prototype.hasOwnProperty.call(cells, k)) continue;
      var a = parseRef(k); if(!a) continue;
      if(!cols[a.c]) cols[a.c] = [];
      cols[a.c][a.r] = cells[k];
      if(a.r > maxR) maxR = a.r; if(a.c > maxC) maxC = a.c;
    }
    ix = this.idx[si] = { cols: cols, maxR: maxR, maxC: maxC };
    return ix;
  };
  Engine.prototype.sheetOf = function(name, ctx){
    if(name === null || name === undefined) return ctx.s;
    var i = this.byName[String(name).toLowerCase()];
    return i === undefined ? -1 : i;
  };

  function literal(v){
    if(typeof v === 'number' || typeof v === 'boolean') return v;
    var s = String(v);
    if(/^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$/.test(s.trim())) return parseFloat(s);
    if(s === 'TRUE') return true; if(s === 'FALSE') return false;
    if(/^#(DIV\/0!|N\/A|VALUE!|REF!|NAME\?|NUM!|NULL!)$/.test(s)) return new Err(s);
    return s;
  }

  /** ค่าของช่อง (ผลลัพธ์จริง ไม่จัดรูปแบบ) — ช่องว่างคืน EMPTY */
  Engine.prototype.cell = function(si, r, c){
    var ix = this.index(si);
    var col = ix.cols[c], cell = col ? col[r] : undefined;
    if(!cell) return EMPTY;
    if(cell.f === undefined || cell.f === null || cell.f === ''){
      if(cell.v === undefined || cell.v === null || cell.v === '') return EMPTY;
      return literal(cell.v);
    }
    var key = si + ':' + r + ':' + c;
    var hit = this.cache.get(key);
    if(hit !== undefined) return hit;
    if(this.busy.has(key)) return E.ref();
    this.busy.add(key);
    var res;
    try{
      var tree = this.tree(cell.f);
      res = tree ? this.one(tree, { s: si, r: r, c: c }) : E.name(true);
      if(res === EMPTY) res = 0;
    }catch(e){ res = E.name(true); }
    this.busy.delete(key);
    // สูตรที่คำนวณไม่ได้ (ฟังก์ชันที่ยังไม่รองรับ) → ใช้ค่าที่ Excel คำนวณไว้
    if(isErr(res) && res.unsupported && cell.v !== undefined && cell.v !== '') res = literal(cell.v);
    this.cache.set(key, res);
    return res;
  };
  Engine.prototype.tree = function(f){
    var t = this.parsed.get(f);
    if(t !== undefined) return t;
    try{ t = parse(String(f).replace(/^=/, '')); }catch(e){ t = null; }
    this.parsed.set(f, t);
    return t;
  };

  /* ---------- ช่วงข้อมูล ---------- */
  Engine.prototype.bounds = function(node, ctx){
    var si = this.sheetOf(node.sheet, ctx);
    if(si < 0) return null;
    var ix = this.index(si);
    var r2 = node.r2 < 0 ? Math.max(ix.maxR, node.r1) : node.r2;
    var c2 = node.c2 < 0 ? Math.max(ix.maxC, node.c1) : node.c2;
    return { s: si, r1: node.r1, c1: node.c1, r2: r2, c2: c2 };
  };
  Engine.prototype.matrix = function(b){
    var key = b.s + '|' + b.r1 + '|' + b.c1 + '|' + b.r2 + '|' + b.c2;
    var m = this.rangeCache.get(key);
    if(m) return m;
    var rows = [], ix = this.index(b.s);
    var rEnd = Math.min(b.r2, Math.max(ix.maxR, b.r1));
    for(var r = b.r1; r <= b.r2; r++){
      var row = new Array(b.c2 - b.c1 + 1);
      for(var c = b.c1, k = 0; c <= b.c2; c++, k++) row[k] = r <= rEnd ? this.cell(b.s, r, c) : EMPTY;
      rows.push(row);
    }
    this.rangeCache.set(key, rows);
    return rows;
  };
  function flat(m){
    if(m._flat) return m._flat;
    var out = [];
    for(var i = 0; i < m.length; i++) for(var j = 0; j < m[i].length; j++) out.push(m[i][j]);
    try{ Object.defineProperty(m, '_flat', { value: out, enumerable: false }); }catch(e){}
    return out;
  }

  /** อาร์กิวเมนต์ของฟังก์ชัน: {range:matrix} หรือ {value} */
  Engine.prototype.arg = function(node, ctx){
    if(!node || node.t === 'missing') return { missing: true, value: EMPTY };
    if(node.t === 'range' || node.t === 'ref'){
      var b = node.t === 'ref' ? this.bounds({ sheet: node.sheet, r1: node.r, c1: node.c, r2: node.r, c2: node.c }, ctx)
                               : this.bounds(node, ctx);
      if(!b) return { value: E.ref() };
      return { range: this.matrix(b), b: b };
    }
    var v = this.run(node, ctx);
    if(v && v.matrix) return { range: v.matrix };
    return { value: v };
  };
  /** ผลลัพธ์ค่าเดียว (ช่วง → ช่องแรก) */
  Engine.prototype.one = function(node, ctx){
    var v = this.run(node, ctx);
    if(v && v.matrix){ var m = v.matrix; return m.length && m[0].length ? m[0][0] : EMPTY; }
    return v;
  };

  Engine.prototype.run = function(n, ctx){
    switch(n.t){
      case 'num': case 'str': case 'bool': return n.v;
      case 'err': return new Err(n.v);
      case 'missing': return EMPTY;
      case 'name': return E.name(true);
      case 'ref':
        var si = this.sheetOf(n.sheet, ctx);
        return si < 0 ? E.ref() : this.cell(si, n.r, n.c);
      case 'range':
        var b = this.bounds(n, ctx);
        return b ? { matrix: this.matrix(b) } : E.ref();
      case 'array': var self = this; return { matrix: [n.items.map(function(it){ return self.one(it, ctx); })] };
      case 'neg':
        var a = this.run(n.a, ctx);
        if(a && a.matrix) return mapM(a.matrix, function(x){ var q = toNum(x); return isErr(q) ? q : -q; });
        a = toNum(a); return isErr(a) ? a : -a;
      case 'pct':
        var pv = toNum(this.one(n.a, ctx)); return isErr(pv) ? pv : pv / 100;
      case 'bin': return this.binary(n, ctx);
      case 'fn': return this.call(n, ctx);
    }
    return E.value();
  };
  function mapM(m, f){ return { matrix: m.map(function(row){ return row.map(f); }) }; }

  Engine.prototype.binary = function(n, ctx){
    var a = this.run(n.a, ctx), b = this.run(n.b, ctx);
    if((a && a.matrix) || (b && b.matrix)){
      var ma = a && a.matrix, mb = b && b.matrix;
      var R = Math.max(ma ? ma.length : 1, mb ? mb.length : 1), C = Math.max(ma ? ma[0].length : 1, mb ? mb[0].length : 1);
      var out = [];
      for(var i = 0; i < R; i++){
        var row = [];
        for(var j = 0; j < C; j++){
          var x = ma ? ((ma[i] || [])[j] !== undefined ? ma[i][j] : new Err('#N/A')) : a;
          var y = mb ? ((mb[i] || [])[j] !== undefined ? mb[i][j] : new Err('#N/A')) : b;
          row.push(op(n.op, x, y));
        }
        out.push(row);
      }
      return { matrix: out };
    }
    return op(n.op, a, b);
  };
  function op(o, a, b){
    if(isErr(a)) return a; if(isErr(b)) return b;
    switch(o){
      case '&': return toStr(a) + toStr(b);
      case '=': return cmp(a, b) === 0;
      case '<>': return cmp(a, b) !== 0;
      case '<': return cmp(a, b) < 0;
      case '>': return cmp(a, b) > 0;
      case '<=': return cmp(a, b) <= 0;
      case '>=': return cmp(a, b) >= 0;
    }
    var x = toNum(a), y = toNum(b);
    if(isErr(x)) return x; if(isErr(y)) return y;
    switch(o){
      case '+': return x + y;
      case '-': return x - y;
      case '*': return x * y;
      case '/': return y === 0 ? E.div0() : x / y;
      case '^': var p = Math.pow(x, y); return isFinite(p) ? p : E.num();
    }
    return E.value();
  }

  /* ---------- เงื่อนไขของ COUNTIF / SUMIF ---------- */
  function criteria(c){
    if(isErr(c)) return function(){ return false; };
    if(typeof c === 'number' || typeof c === 'boolean'){
      return function(v){ if(v === EMPTY) return false; if(typeof v === typeof c) return v === c; var n = typeof v === 'string' ? numStr(v) : null; return n !== null && n === c; };
    }
    var s = c === EMPTY ? '' : String(c), m = s.match(/^(<=|>=|<>|<|>|=)?([\s\S]*)$/);
    var o = m[1] || '', rhs = m[2];
    var rn = numStr(rhs);
    if((o === '' || o === '=') && rhs === '') return function(v){ return v === EMPTY || v === ''; };
    if(o === '<>' && rhs === '') return function(v){ return !(v === EMPTY || v === ''); };
    if(rn !== null){
      return function(v){
        var n = typeof v === 'number' ? v : (typeof v === 'string' ? numStr(v) : null);
        if(n === null) return o === '<>';
        switch(o){ case '': case '=': return n === rn; case '<>': return n !== rn; case '<': return n < rn;
                   case '>': return n > rn; case '<=': return n <= rn; case '>=': return n >= rn; }
        return false;
      };
    }
    var up = rhs.toUpperCase();
    if(up === 'TRUE' || up === 'FALSE'){
      var bv = up === 'TRUE';
      return function(v){ var eq = typeof v === 'boolean' && v === bv; return o === '<>' ? !eq : eq; };
    }
    var wild = /[*?~]/.test(rhs);
    var re = wild ? new RegExp('^' + rhs.replace(/~([*?~])|([.+^${}()|[\]\\])|(\*)|(\?)/g, function(_, esc, meta, star){
      return esc ? '\\' + esc : meta ? '\\' + meta : star ? '[\\s\\S]*' : '.'; }) + '$', 'i') : null;
    var low = rhs.toLowerCase();
    return function(v){
      if(o === '<' || o === '>' || o === '<=' || o === '>='){
        if(typeof v !== 'string' || v === '') return false;
        var r = cmp(v, rhs); return o === '<' ? r < 0 : o === '>' ? r > 0 : o === '<=' ? r <= 0 : r >= 0;
      }
      var t = v === EMPTY ? '' : (typeof v === 'string' ? v : null);
      var eq = t !== null && (re ? re.test(t) : t.toLowerCase() === low);
      return o === '<>' ? !eq : eq;
    };
  }

  /* ============================================================
   * ฟังก์ชัน
   * ============================================================ */
  function nums(args, eng, ctx){
    var out = [];
    for(var i = 0; i < args.length; i++){
      var a = eng.arg(args[i], ctx);
      if(a.missing) continue;
      if(a.range){
        var f = flat(a.range);
        for(var j = 0; j < f.length; j++){ var v = f[j]; if(isErr(v)) return v; if(typeof v === 'number') out.push(v); }
      }else{
        var x = a.value;
        if(isErr(x)) return x;
        if(x === EMPTY) continue;
        var n = toNum(x); if(isErr(n)) return n; out.push(n);
      }
    }
    return out;
  }
  function multiIf(eng, ctx, args, start){
    var ranges = [], tests = [];
    for(var i = start; i + 1 < args.length; i += 2){
      var r = eng.arg(args[i], ctx);
      if(!r.range) return E.value();
      ranges.push(flat(r.range));
      tests.push(criteria(eng.one(args[i + 1], ctx)));
    }
    return { ranges: ranges, tests: tests, len: ranges.length ? ranges[0].length : 0 };
  }
  function passAll(mi, k){
    for(var t = 0; t < mi.tests.length; t++){ var v = mi.ranges[t][k]; if(v === undefined || !mi.tests[t](v)) return false; }
    return true;
  }
  function roundTo(x, d, mode){
    var p = Math.pow(10, d), y = x * p;
    if(Math.abs(y - Math.round(y)) < 1e-9) y = Math.round(y);
    var ay = Math.abs(y);
    var r = mode === 'up' ? Math.ceil(ay - 1e-12) : mode === 'down' ? Math.floor(ay + 1e-12) : Math.round(ay + 1e-12);
    return (y < 0 ? -r : r) / p;
  }
  function lookupEq(a, b){ return cmp(a === EMPTY ? '' : a, b === EMPTY ? '' : b) === 0; }
  function A(a, e, c, i){ return e.one(a[i], c); }

  var FN = {
    // ── ตรรกะ ──
    IF: function(a, e, c){
      var t = toBool(A(a, e, c, 0)); if(isErr(t)) return t;
      if(t) return a.length > 1 && a[1].t !== 'missing' ? A(a, e, c, 1) : (a.length > 1 ? 0 : true);
      return a.length > 2 ? (a[2].t === 'missing' ? 0 : A(a, e, c, 2)) : false;
    },
    IFS: function(a, e, c){
      for(var i = 0; i + 1 < a.length; i += 2){ var t = toBool(A(a, e, c, i)); if(isErr(t)) return t; if(t) return A(a, e, c, i + 1); }
      return E.na();
    },
    IFERROR: function(a, e, c){ var v = A(a, e, c, 0); return isErr(v) && !v.unsupported ? (a.length > 1 ? A(a, e, c, 1) : EMPTY) : v; },
    IFNA: function(a, e, c){ var v = A(a, e, c, 0); return (isErr(v) && v.e === '#N/A') ? A(a, e, c, 1) : v; },
    AND: function(a, e, c){
      var res = true, any = false;
      for(var i = 0; i < a.length; i++){
        var x = e.arg(a[i], c), list = x.range ? flat(x.range) : [x.value];
        for(var j = 0; j < list.length; j++){ var v = list[j]; if(x.range && (v === EMPTY || typeof v === 'string')) continue; var b = toBool(v); if(isErr(b)) return b; any = true; if(!b) res = false; }
      }
      return any ? res : E.value();
    },
    OR: function(a, e, c){
      var res = false, any = false;
      for(var i = 0; i < a.length; i++){
        var x = e.arg(a[i], c), list = x.range ? flat(x.range) : [x.value];
        for(var j = 0; j < list.length; j++){ var v = list[j]; if(x.range && (v === EMPTY || typeof v === 'string')) continue; var b = toBool(v); if(isErr(b)) return b; any = true; if(b) res = true; }
      }
      return any ? res : E.value();
    },
    XOR: function(a, e, c){ var n = 0; for(var i = 0; i < a.length; i++){ var b = toBool(A(a, e, c, i)); if(isErr(b)) return b; if(b) n++; } return n % 2 === 1; },
    NOT: function(a, e, c){ var b = toBool(A(a, e, c, 0)); return isErr(b) ? b : !b; },
    TRUE: function(){ return true; }, FALSE: function(){ return false; },
    // ── ตรวจชนิด ──
    ISNUMBER: function(a, e, c){ return typeof A(a, e, c, 0) === 'number'; },
    ISTEXT: function(a, e, c){ return typeof A(a, e, c, 0) === 'string'; },
    ISNONTEXT: function(a, e, c){ return typeof A(a, e, c, 0) !== 'string'; },
    ISBLANK: function(a, e, c){ return A(a, e, c, 0) === EMPTY; },
    ISERROR: function(a, e, c){ return isErr(A(a, e, c, 0)); },
    ISERR: function(a, e, c){ var v = A(a, e, c, 0); return isErr(v) && v.e !== '#N/A'; },
    ISNA: function(a, e, c){ var v = A(a, e, c, 0); return isErr(v) && v.e === '#N/A'; },
    ISLOGICAL: function(a, e, c){ return typeof A(a, e, c, 0) === 'boolean'; },
    ISEVEN: function(a, e, c){ var n = toNum(A(a, e, c, 0)); return isErr(n) ? n : Math.trunc(n) % 2 === 0; },
    ISODD: function(a, e, c){ var n = toNum(A(a, e, c, 0)); return isErr(n) ? n : Math.abs(Math.trunc(n) % 2) === 1; },
    NA: function(){ return E.na(); },
    // ── คำนวณ ──
    SUM: function(a, e, c){ var n = nums(a, e, c); if(isErr(n)) return n; var s = 0; for(var i = 0; i < n.length; i++) s += n[i]; return s; },
    AVERAGE: function(a, e, c){ var n = nums(a, e, c); if(isErr(n)) return n; if(!n.length) return E.div0(); var s = 0; for(var i = 0; i < n.length; i++) s += n[i]; return s / n.length; },
    MIN: function(a, e, c){ var n = nums(a, e, c); if(isErr(n)) return n; var m = Infinity; for(var i = 0; i < n.length; i++) if(n[i] < m) m = n[i]; return n.length ? m : 0; },
    MAX: function(a, e, c){ var n = nums(a, e, c); if(isErr(n)) return n; var m = -Infinity; for(var i = 0; i < n.length; i++) if(n[i] > m) m = n[i]; return n.length ? m : 0; },
    COUNT: function(a, e, c){
      var k = 0;
      for(var i = 0; i < a.length; i++){ var x = e.arg(a[i], c);
        if(x.range){ var f = flat(x.range); for(var j = 0; j < f.length; j++) if(typeof f[j] === 'number') k++; }
        else if(typeof x.value === 'number' || (typeof x.value === 'string' && numStr(x.value) !== null)) k++; }
      return k;
    },
    COUNTA: function(a, e, c){
      var k = 0;
      for(var i = 0; i < a.length; i++){ var x = e.arg(a[i], c); if(x.missing) continue;
        if(x.range){ var f = flat(x.range); for(var j = 0; j < f.length; j++) if(f[j] !== EMPTY) k++; }
        else if(x.value !== EMPTY) k++; }
      return k;
    },
    COUNTBLANK: function(a, e, c){ var x = e.arg(a[0], c); if(!x.range) return E.value(); var f = flat(x.range), k = 0; for(var j = 0; j < f.length; j++) if(f[j] === EMPTY || f[j] === '') k++; return k; },
    COUNTIF: function(a, e, c){ var x = e.arg(a[0], c); if(!x.range) return E.value(); var t = criteria(A(a, e, c, 1)), f = flat(x.range), k = 0; for(var j = 0; j < f.length; j++) if(t(f[j])) k++; return k; },
    COUNTIFS: function(a, e, c){ var mi = multiIf(e, c, a, 0); if(isErr(mi)) return mi; var k = 0; for(var j = 0; j < mi.len; j++) if(passAll(mi, j)) k++; return k; },
    SUMIF: function(a, e, c){
      var x = e.arg(a[0], c); if(!x.range) return E.value();
      var t = criteria(A(a, e, c, 1)), f = flat(x.range);
      var sr = a.length > 2 ? e.arg(a[2], c) : x; if(!sr.range) return E.value();
      var s2 = flat(sr.range), s = 0;
      for(var j = 0; j < f.length; j++) if(t(f[j]) && typeof s2[j] === 'number') s += s2[j];
      return s;
    },
    SUMIFS: function(a, e, c){
      var sr = e.arg(a[0], c); if(!sr.range) return E.value();
      var mi = multiIf(e, c, a, 1); if(isErr(mi)) return mi;
      var vals = flat(sr.range), s = 0;
      for(var j = 0; j < vals.length; j++) if(typeof vals[j] === 'number' && passAll(mi, j)) s += vals[j];
      return s;
    },
    AVERAGEIF: function(a, e, c){
      var x = e.arg(a[0], c); if(!x.range) return E.value();
      var t = criteria(A(a, e, c, 1)), f = flat(x.range);
      var sr = a.length > 2 ? e.arg(a[2], c) : x, s2 = flat(sr.range), s = 0, k = 0;
      for(var j = 0; j < f.length; j++) if(t(f[j]) && typeof s2[j] === 'number'){ s += s2[j]; k++; }
      return k ? s / k : E.div0();
    },
    AVERAGEIFS: function(a, e, c){
      var sr = e.arg(a[0], c); if(!sr.range) return E.value();
      var mi = multiIf(e, c, a, 1); if(isErr(mi)) return mi;
      var vals = flat(sr.range), s = 0, k = 0;
      for(var j = 0; j < vals.length; j++) if(typeof vals[j] === 'number' && passAll(mi, j)){ s += vals[j]; k++; }
      return k ? s / k : E.div0();
    },
    MINIFS: function(a, e, c){
      var sr = e.arg(a[0], c); if(!sr.range) return E.value();
      var mi = multiIf(e, c, a, 1); if(isErr(mi)) return mi;
      var vals = flat(sr.range), m = null;
      for(var j = 0; j < vals.length; j++) if(typeof vals[j] === 'number' && passAll(mi, j)) m = m === null ? vals[j] : Math.min(m, vals[j]);
      return m === null ? 0 : m;
    },
    MAXIFS: function(a, e, c){
      var sr = e.arg(a[0], c); if(!sr.range) return E.value();
      var mi = multiIf(e, c, a, 1); if(isErr(mi)) return mi;
      var vals = flat(sr.range), m = null;
      for(var j = 0; j < vals.length; j++) if(typeof vals[j] === 'number' && passAll(mi, j)) m = m === null ? vals[j] : Math.max(m, vals[j]);
      return m === null ? 0 : m;
    },
    SUMPRODUCT: function(a, e, c){
      var ms = [];
      for(var i = 0; i < a.length; i++){ var x = e.arg(a[i], c); if(!x.range){ var o = toNum(x.value); if(isErr(o)) return o; ms.push([o]); } else ms.push(flat(x.range)); }
      if(!ms.length) return 0;
      var len = ms[0].length, s = 0;
      for(var k = 1; k < ms.length; k++) if(ms[k].length !== len) return E.value();
      for(var j = 0; j < len; j++){
        var p = 1;
        for(var q = 0; q < ms.length; q++){ var v = ms[q][j]; if(isErr(v)) return v; p *= typeof v === 'number' ? v : (typeof v === 'boolean' ? (v ? 1 : 0) : 0); }
        s += p;
      }
      return s;
    },
    ROUND: function(a, e, c){ var x = toNum(A(a, e, c, 0)), d = a.length > 1 ? toNum(A(a, e, c, 1)) : 0; if(isErr(x)) return x; if(isErr(d)) return d; return roundTo(x, Math.trunc(d)); },
    ROUNDUP: function(a, e, c){ var x = toNum(A(a, e, c, 0)), d = a.length > 1 ? toNum(A(a, e, c, 1)) : 0; if(isErr(x)) return x; if(isErr(d)) return d; return roundTo(x, Math.trunc(d), 'up'); },
    ROUNDDOWN: function(a, e, c){ var x = toNum(A(a, e, c, 0)), d = a.length > 1 ? toNum(A(a, e, c, 1)) : 0; if(isErr(x)) return x; if(isErr(d)) return d; return roundTo(x, Math.trunc(d), 'down'); },
    INT: function(a, e, c){ var x = toNum(A(a, e, c, 0)); return isErr(x) ? x : Math.floor(x); },
    TRUNC: function(a, e, c){ var x = toNum(A(a, e, c, 0)); return isErr(x) ? x : Math.trunc(x); },
    ABS: function(a, e, c){ var x = toNum(A(a, e, c, 0)); return isErr(x) ? x : Math.abs(x); },
    SIGN: function(a, e, c){ var x = toNum(A(a, e, c, 0)); return isErr(x) ? x : Math.sign(x); },
    MOD: function(a, e, c){ var x = toNum(A(a, e, c, 0)), y = toNum(A(a, e, c, 1)); if(isErr(x)) return x; if(isErr(y)) return y; if(y === 0) return E.div0(); return x - y * Math.floor(x / y); },
    POWER: function(a, e, c){ return op('^', A(a, e, c, 0), A(a, e, c, 1)); },
    SQRT: function(a, e, c){ var x = toNum(A(a, e, c, 0)); if(isErr(x)) return x; return x < 0 ? E.num() : Math.sqrt(x); },
    CEILING: function(a, e, c){ var x = toNum(A(a, e, c, 0)), s = a.length > 1 ? toNum(A(a, e, c, 1)) : 1; if(isErr(x)) return x; if(isErr(s)) return s; return s === 0 ? 0 : Math.ceil(x / s - 1e-12) * s; },
    FLOOR: function(a, e, c){ var x = toNum(A(a, e, c, 0)), s = a.length > 1 ? toNum(A(a, e, c, 1)) : 1; if(isErr(x)) return x; if(isErr(s)) return s; return s === 0 ? E.div0() : Math.floor(x / s + 1e-12) * s; },
    PI: function(){ return Math.PI; },
    // ── ค้นหา ──
    INDEX: function(a, e, c){
      var x = e.arg(a[0], c); if(!x.range) return x.value === undefined ? E.value() : x.value;
      var m = x.range, r = a.length > 1 ? toNum(A(a, e, c, 1)) : 0, col = a.length > 2 ? toNum(A(a, e, c, 2)) : 0;
      if(isErr(r)) return r; if(isErr(col)) return col;
      r = Math.trunc(r); col = Math.trunc(col);
      if(m.length === 1 && a.length === 2){ col = r; r = 1; }
      if(r === 0 && m.length === 1) r = 1;
      if(col === 0 && m[0] && m[0].length === 1) col = 1;
      if(r < 1 || col < 1 || r > m.length || col > m[0].length) return E.ref();
      return m[r - 1][col - 1];
    },
    MATCH: function(a, e, c){
      var look = A(a, e, c, 0); if(isErr(look)) return look;
      var x = e.arg(a[1], c); if(!x.range) return E.na();
      var list = flat(x.range), type = a.length > 2 ? toNum(A(a, e, c, 2)) : 1;
      if(isErr(type)) return type;
      if(type === 0){
        var t = typeof look === 'string' && /[*?]/.test(look) ? criteria('=' + look) : null;
        for(var i = 0; i < list.length; i++){ if(t ? t(list[i]) : (list[i] !== EMPTY && lookupEq(list[i], look))) return i + 1; }
        return E.na();
      }
      var best = -1;
      for(var j = 0; j < list.length; j++){
        var v = list[j]; if(v === EMPTY || rank(v) !== rank(look)) continue;
        var r = cmp(v, look);
        if(type > 0 ? r <= 0 : r >= 0) best = j; else if(type > 0) break;
      }
      return best < 0 ? E.na() : best + 1;
    },
    VLOOKUP: function(a, e, c){
      var look = A(a, e, c, 0); if(isErr(look)) return look;
      var x = e.arg(a[1], c); if(!x.range) return E.na();
      var col = toNum(A(a, e, c, 2)); if(isErr(col)) return col;
      var approx = a.length > 3 ? toBool(A(a, e, c, 3)) : true;
      var m = x.range; col = Math.trunc(col);
      if(col < 1 || col > m[0].length) return E.ref();
      if(!approx){
        for(var i = 0; i < m.length; i++) if(m[i][0] !== EMPTY && lookupEq(m[i][0], look)) return m[i][col - 1];
        return E.na();
      }
      var best = -1;
      for(var j = 0; j < m.length; j++){ var v = m[j][0]; if(v === EMPTY || rank(v) !== rank(look)) continue; if(cmp(v, look) <= 0) best = j; else break; }
      return best < 0 ? E.na() : m[best][col - 1];
    },
    HLOOKUP: function(a, e, c){
      var look = A(a, e, c, 0); if(isErr(look)) return look;
      var x = e.arg(a[1], c); if(!x.range) return E.na();
      var row = Math.trunc(toNum(A(a, e, c, 2)));
      var approx = a.length > 3 ? toBool(A(a, e, c, 3)) : true, m = x.range, best = -1;
      if(row < 1 || row > m.length) return E.ref();
      for(var j = 0; j < m[0].length; j++){
        var v = m[0][j];
        if(!approx){ if(v !== EMPTY && lookupEq(v, look)) return m[row - 1][j]; continue; }
        if(v === EMPTY || rank(v) !== rank(look)) continue; if(cmp(v, look) <= 0) best = j; else break;
      }
      return approx && best >= 0 ? m[row - 1][best] : E.na();
    },
    XLOOKUP: function(a, e, c){
      var look = A(a, e, c, 0); if(isErr(look)) return look;
      var ls = e.arg(a[1], c), rs = e.arg(a[2], c); if(!ls.range || !rs.range) return E.value();
      var l = flat(ls.range), r = flat(rs.range);
      for(var i = 0; i < l.length; i++) if(l[i] !== EMPTY && lookupEq(l[i], look)) return r[i] === undefined ? E.na() : r[i];
      return a.length > 3 && a[3].t !== 'missing' ? A(a, e, c, 3) : E.na();
    },
    ROW: function(a, e, c){ if(!a.length || a[0].t === 'missing') return c.r; var n = a[0]; return n.r !== undefined ? n.r : (n.r1 !== undefined ? n.r1 : E.value()); },
    COLUMN: function(a, e, c){ if(!a.length || a[0].t === 'missing') return c.c + 1; var n = a[0]; return (n.c !== undefined ? n.c : n.c1) + 1; },
    ROWS: function(a, e, c){ var x = e.arg(a[0], c); return x.range ? x.range.length : 1; },
    COLUMNS: function(a, e, c){ var x = e.arg(a[0], c); return x.range ? x.range[0].length : 1; },
    CHOOSE: function(a, e, c){ var i = toNum(A(a, e, c, 0)); if(isErr(i)) return i; i = Math.trunc(i); return i >= 1 && i < a.length ? A(a, e, c, i) : E.value(); },
    // ── ข้อความ ──
    LEN: function(a, e, c){ var v = A(a, e, c, 0); return isErr(v) ? v : toStr(v).length; },
    LEFT: function(a, e, c){ var v = A(a, e, c, 0), n = a.length > 1 ? toNum(A(a, e, c, 1)) : 1; if(isErr(v)) return v; if(isErr(n)) return n; return toStr(v).slice(0, Math.max(0, n)); },
    RIGHT: function(a, e, c){ var v = A(a, e, c, 0), n = a.length > 1 ? toNum(A(a, e, c, 1)) : 1; if(isErr(v)) return v; if(isErr(n)) return n; var s = toStr(v); return n <= 0 ? '' : s.slice(-n); },
    MID: function(a, e, c){ var v = A(a, e, c, 0), st = toNum(A(a, e, c, 1)), n = toNum(A(a, e, c, 2)); if(isErr(v)) return v; if(isErr(st)) return st; if(isErr(n)) return n; return toStr(v).substr(Math.max(0, st - 1), Math.max(0, n)); },
    UPPER: function(a, e, c){ var v = A(a, e, c, 0); return isErr(v) ? v : toStr(v).toUpperCase(); },
    LOWER: function(a, e, c){ var v = A(a, e, c, 0); return isErr(v) ? v : toStr(v).toLowerCase(); },
    PROPER: function(a, e, c){ var v = A(a, e, c, 0); return isErr(v) ? v : toStr(v).toLowerCase().replace(/(^|[^a-z])([a-z])/g, function(_, p, ch){ return p + ch.toUpperCase(); }); },
    TRIM: function(a, e, c){ var v = A(a, e, c, 0); return isErr(v) ? v : toStr(v).replace(/ +/g, ' ').trim(); },
    CONCAT: function(a, e, c){
      var s = '';
      for(var i = 0; i < a.length; i++){ var x = e.arg(a[i], c);
        if(x.range){ var f = flat(x.range); for(var j = 0; j < f.length; j++){ if(isErr(f[j])) return f[j]; s += toStr(f[j]); } }
        else { if(isErr(x.value)) return x.value; s += toStr(x.value); } }
      return s;
    },
    CONCATENATE: function(a, e, c){ var s = ''; for(var i = 0; i < a.length; i++){ var v = A(a, e, c, i); if(isErr(v)) return v; s += toStr(v); } return s; },
    TEXTJOIN: function(a, e, c){
      var d = toStr(A(a, e, c, 0)), skip = toBool(A(a, e, c, 1)), parts = [];
      for(var i = 2; i < a.length; i++){ var x = e.arg(a[i], c), list = x.range ? flat(x.range) : [x.value];
        for(var j = 0; j < list.length; j++){ if(isErr(list[j])) return list[j]; var s = toStr(list[j]); if(skip === true && s === '') continue; parts.push(s); } }
      return parts.join(d);
    },
    REPT: function(a, e, c){ var v = A(a, e, c, 0), n = toNum(A(a, e, c, 1)); if(isErr(v)) return v; if(isErr(n)) return n; return new Array(Math.max(0, Math.trunc(n)) + 1).join(toStr(v)); },
    SUBSTITUTE: function(a, e, c){
      var s = toStr(A(a, e, c, 0)), o = toStr(A(a, e, c, 1)), nw = toStr(A(a, e, c, 2));
      if(o === '') return s;
      if(a.length > 3){ var k = Math.trunc(toNum(A(a, e, c, 3))), idx = -1; for(var i = 0; i < k; i++){ idx = s.indexOf(o, idx + 1); if(idx < 0) return s; } return s.slice(0, idx) + nw + s.slice(idx + o.length); }
      return s.split(o).join(nw);
    },
    FIND: function(a, e, c){ var f = toStr(A(a, e, c, 0)), s = toStr(A(a, e, c, 1)), st = a.length > 2 ? toNum(A(a, e, c, 2)) : 1; var i = s.indexOf(f, st - 1); return i < 0 ? E.value() : i + 1; },
    SEARCH: function(a, e, c){ var f = toStr(A(a, e, c, 0)).toLowerCase(), s = toStr(A(a, e, c, 1)).toLowerCase(), st = a.length > 2 ? toNum(A(a, e, c, 2)) : 1; var i = s.indexOf(f, st - 1); return i < 0 ? E.value() : i + 1; },
    VALUE: function(a, e, c){ var v = A(a, e, c, 0); if(typeof v === 'number') return v; if(isErr(v)) return v; var n = numStr(toStr(v)); return n === null ? E.value() : n; },
    TEXT: function(a, e, c){ var v = A(a, e, c, 0); if(isErr(v)) return v; return format(v, toStr(A(a, e, c, 1))); },
    T: function(a, e, c){ var v = A(a, e, c, 0); return typeof v === 'string' ? v : ''; },
    N: function(a, e, c){ var v = A(a, e, c, 0); return typeof v === 'number' ? v : (v === true ? 1 : 0); },
    EXACT: function(a, e, c){ return toStr(A(a, e, c, 0)) === toStr(A(a, e, c, 1)); },
    CHAR: function(a, e, c){ var n = toNum(A(a, e, c, 0)); return isErr(n) ? n : String.fromCharCode(n); },
    // ── วันที่ ──
    DATE: function(a, e, c){ var y = toNum(A(a, e, c, 0)), m = toNum(A(a, e, c, 1)), d = toNum(A(a, e, c, 2)); if(isErr(y)) return y; if(isErr(m)) return m; if(isErr(d)) return d; if(y < 1900) y += 1900; return dateToSerial(Math.trunc(y), Math.trunc(m), Math.trunc(d)); },
    TODAY: function(){ var n = new Date(); return dateToSerial(n.getFullYear(), n.getMonth() + 1, n.getDate()); },
    NOW: function(){ var n = new Date(); return dateToSerial(n.getFullYear(), n.getMonth() + 1, n.getDate()) + (n.getHours() * 3600 + n.getMinutes() * 60 + n.getSeconds()) / 86400; },
    YEAR: function(a, e, c){ var n = toNum(A(a, e, c, 0)); return isErr(n) ? n : serialToParts(n).y; },
    MONTH: function(a, e, c){ var n = toNum(A(a, e, c, 0)); return isErr(n) ? n : serialToParts(n).m; },
    DAY: function(a, e, c){ var n = toNum(A(a, e, c, 0)); return isErr(n) ? n : serialToParts(n).d; },
    WEEKDAY: function(a, e, c){ var n = toNum(A(a, e, c, 0)); if(isErr(n)) return n; var t = a.length > 1 ? toNum(A(a, e, c, 1)) : 1, d = serialToParts(n).dow; return t === 2 ? ((d + 6) % 7) + 1 : t === 3 ? (d + 6) % 7 : d + 1; },
    EDATE: function(a, e, c){ var n = toNum(A(a, e, c, 0)), m = toNum(A(a, e, c, 1)); if(isErr(n)) return n; if(isErr(m)) return m; var P = serialToParts(n); return dateToSerial(P.y, P.m + Math.trunc(m), P.d); },
    EOMONTH: function(a, e, c){ var n = toNum(A(a, e, c, 0)), m = toNum(A(a, e, c, 1)); if(isErr(n)) return n; if(isErr(m)) return m; var P = serialToParts(n); return dateToSerial(P.y, P.m + Math.trunc(m) + 1, 0); },
    DAYS: function(a, e, c){ var x = toNum(A(a, e, c, 0)), y = toNum(A(a, e, c, 1)); if(isErr(x)) return x; if(isErr(y)) return y; return Math.floor(x) - Math.floor(y); },
    DATEDIF: function(a, e, c){
      var s = toNum(A(a, e, c, 0)), en = toNum(A(a, e, c, 1)), u = toStr(A(a, e, c, 2)).toUpperCase();
      if(isErr(s)) return s; if(isErr(en)) return en; if(en < s) return E.num();
      var P1 = serialToParts(s), P2 = serialToParts(en);
      if(u === 'D') return Math.floor(en) - Math.floor(s);
      var months = (P2.y - P1.y) * 12 + (P2.m - P1.m) - (P2.d < P1.d ? 1 : 0);
      if(u === 'M') return months; if(u === 'Y') return Math.floor(months / 12);
      if(u === 'YM') return months % 12;
      return E.num();
    }
  };
  FN.AVG = FN.AVERAGE;

  Engine.prototype.call = function(n, ctx){
    var name = n.name.replace(/^_XLFN\./, '').replace(/^_XLWS\./, '');
    var f = FN[name];
    if(!f) return E.name(true);
    try{ return f(n.args, this, ctx); }
    catch(e){ return E.value(); }
  };

  /* ---------- API สำหรับตัวแก้ไข ---------- */
  Engine.prototype.valueAt = function(si, ref){ var a = parseRef(ref); return a ? this.cell(si, a.r, a.c) : EMPTY; };
  /** ข้อความที่แสดงในช่อง (จัดรูปแบบตาม fmt.n ถ้ามี) */
  Engine.prototype.display = function(si, ref){
    var v = this.valueAt(si, ref);
    var sh = this.book.sheets[si], f = sh.fmt && sh.fmt[ref];
    if(f && f.n) return format(v, f.n);
    if(isErr(v)) return v.e;
    if(v === EMPTY) return '';
    if(typeof v === 'number') return fmtGeneral(v);
    if(typeof v === 'boolean') return v ? 'TRUE' : 'FALSE';
    return String(v);
  };
  /** ค่าดิบสำหรับเก็บเป็นค่าแคชของสูตร ('' ถ้าว่าง) */
  Engine.prototype.cacheValue = function(si, ref){
    var v = this.valueAt(si, ref);
    if(v === EMPTY) return '';
    if(isErr(v)) return v.e;
    if(typeof v === 'boolean') return v ? 'TRUE' : 'FALSE';
    return String(v);
  };
  Engine.prototype.isNumeric = function(si, ref){ return typeof this.valueAt(si, ref) === 'number'; };

  return { Engine: Engine, parse: parse, tokenize: tokenize, format: format, colName: colName, colIndex: colIndex,
           parseRef: parseRef, EMPTY: EMPTY, Err: Err, fmtGeneral: fmtGeneral, FN: FN };
});
