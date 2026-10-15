/* ============================================================
 * Aleanor Present — ตัววาดและตัวเล่นสไลด์ (ใช้ร่วมกัน: ตัวแก้ไข / หน้าอ่าน / ในบทเรียน / Aleanor LC)
 *   AleanorPresent.slide(s, ctx)      วาดสไลด์หนึ่งแผ่น → <section>
 *   AleanorPresent.stage(s, ctx)      กรอบสไลด์ 16:9 พร้อมธีม (ใช้ทำภาพย่อ/ตัวอย่าง)
 *   AleanorPresent.player(el, cfg)    ตัวเล่นเต็มรูปแบบ (เปลี่ยนสไลด์ เอฟเฟกต์ แสดงทีละข้อ แบบทดสอบ เลเซอร์ ภาพรวม เล่นอัตโนมัติ)
 *   AleanorPresent.mount()            หา .pv-deck[data-pv] ในหน้าแล้วสร้างตัวเล่นให้
 * ============================================================ */
(function(){
  'use strict';
  if(window.AleanorPresent) return;

  var FONTS = {
    sarabun:'Sarabun', prompt:'Prompt', kanit:'Kanit', mitr:'Mitr', itim:'Itim', mali:'Mali',
    chakra:'Chakra Petch', pridi:'Pridi', trirong:'Trirong', anuphan:'Anuphan', bai:'Bai Jamjuree', k2d:'K2D'
  };
  var LBL = {
    prev:'ก่อนหน้า', next:'ถัดไป', full:'เต็มจอ', overview:'ภาพรวมทุกสไลด์', auto:'เล่นอัตโนมัติ', laser:'เลเซอร์ชี้',
    confetti:'ฉลอง!', right:'ถูกต้อง!', wrong:'ยังไม่ถูก ลองดูเฉลย', video:'วิดีโอ'
  };
  var loaded = {};

  function el(tag, cls, txt){ var e = document.createElement(tag); if(cls) e.className = cls; if(txt != null) e.textContent = txt; return e; }
  function lines(t){ return String(t || '').split('\n').map(function(x){ return x.trim(); }).filter(Boolean); }
  /** "ค่า | คำอธิบาย" */
  function pair(line){
    var i = line.indexOf('|');
    return i < 0 ? [line.trim(), ''] : [line.slice(0, i).trim(), line.slice(i + 1).trim()];
  }

  function loadFont(key){
    var fam = FONTS[key]; if(!fam || loaded[fam]) return;
    loaded[fam] = 1;
    var l = document.createElement('link'); l.rel = 'stylesheet';
    l.href = 'https://fonts.googleapis.com/css2?family=' + encodeURIComponent(fam).replace(/%20/g, '+') + ':wght@400;600;700;800&display=swap';
    document.head.appendChild(l);
  }
  function fontStack(key){ return FONTS[key] ? '"' + FONTS[key] + '", inherit' : 'inherit'; }

  /** ค่าธีมที่ใช้จริง (ธีม + สีหลัก/ฟอนต์ที่ผู้สร้างเลือกเอง) */
  function look(deck, themes){
    var th = (themes && themes[deck.theme]) || (themes && themes.light) || { bg:'#ffffff', fg:'#1e293b', accent:'#6366f1' };
    var bg = th.bg2 ? 'linear-gradient(135deg,' + th.bg + ',' + th.bg2 + ')' : th.bg;
    return { bg:bg, solid:th.bg, fg:th.fg, ac:deck.accent || th.accent, font:deck.font || th.font || '', body:th.body || '', decor:th.decor || 'none' };
  }
  function applyLook(box, deck, themes){
    var L = look(deck, themes);
    box.style.setProperty('--pv-bg', L.bg);
    box.style.setProperty('--pv-fg', L.fg);
    box.style.setProperty('--pv-ac', L.ac);
    box.style.setProperty('--pv-font', fontStack(L.font));
    box.style.setProperty('--pv-body', L.body ? fontStack(L.body) : 'inherit');
    box.setAttribute('data-decor', L.decor);
    box.setAttribute('data-anim', deck.anim || 'rise');
    box.setAttribute('data-progress', deck.progress === 0 ? '0' : '1');
    if(L.font) loadFont(L.font);
    if(L.body) loadFont(L.body);
    return L;
  }
  /** สีพื้นเข้ม → ตัวหนังสือขาว */
  function isDark(hex){
    var m = /^#?([0-9a-f]{6}|[0-9a-f]{3})$/i.test(hex || '') ? String(hex).replace('#', '') : null;
    if(!m) return false;
    if(m.length === 3) m = m[0] + m[0] + m[1] + m[1] + m[2] + m[2];
    var r = parseInt(m.slice(0, 2), 16), g = parseInt(m.slice(2, 4), 16), b = parseInt(m.slice(4, 6), 16);
    return (0.299 * r + 0.587 * g + 0.114 * b) < 140;
  }
  function videoInfo(v){
    v = String(v || '');
    if(v.indexOf('yt:') === 0){ var id = v.slice(3); return { embed:'https://www.youtube-nocookie.com/embed/' + id + '?autoplay=1&rel=0', thumb:'https://i.ytimg.com/vi/' + id + '/hqdefault.jpg' }; }
    if(v.indexOf('vimeo:') === 0) return { embed:'https://player.vimeo.com/video/' + v.slice(6) + '?autoplay=1', thumb:'' };
    return null;
  }

  /* ============================================================
   * วาดสไลด์หนึ่งแผ่น
   * ctx: {base, deck, index, sectionNo}
   * ============================================================ */
  function slide(s, ctx){
    ctx = ctx || {};
    var base = ctx.base || '';
    var sec = el('section', 'pv-slide pv-' + (s.layout || 'content'));
    sec.dataset.i = ctx.index || 0;
    if(s.fx) sec.dataset.fx = s.fx;
    if(s.bgImg){
      sec.classList.add('pv-photo');
      sec.style.backgroundImage = 'linear-gradient(rgba(2,6,23,.5),rgba(2,6,23,.6)),url("' + (base + s.bgImg).replace(/"/g, '%22') + '")';
    }else if(s.bg){
      sec.classList.add('pv-custom');
      sec.style.setProperty('--pv-sbg', s.bg);
      sec.style.setProperty('--pv-fg', isDark(s.bg) ? '#f8fafc' : '#1e293b');
    }
    var d = 0;
    function A(node, step){ node.classList.add('pv-a'); node.style.setProperty('--d', d++); if(step && s.step) node.classList.add('pv-step'); return node; }
    function bullets(txt, step){
      var ul = el('ul', 'pv-ul');
      lines(txt).forEach(function(line){ ul.appendChild(A(el('li', '', line), step)); });
      return ul;
    }
    function pic(cls){
      var w = el('div');
      if(s.imgPos){ w.className = 'pv-img free'; w.style.left = s.imgPos.x + '%'; w.style.top = s.imgPos.y + '%'; w.style.width = s.imgPos.w + '%'; }
      else { w.className = 'pv-img' + (cls ? ' ' + cls : ''); if(s.imgW) w.style.cssText += ';width:' + s.imgW + '%;margin:0 auto;flex:0 1 auto'; }
      var im = el('img'); im.src = base + s.img; im.alt = ''; im.draggable = false; im.loading = 'lazy';
      if(s.imgRound) im.style.borderRadius = '2.2cqw';
      w.appendChild(im);
      return A(w);
    }
    var L = s.layout;
    if(L === 'title'){
      sec.appendChild(A(el('h2', 'pv-h1', s.title)));
      sec.appendChild(A(el('div', 'pv-rule')));
      if(s.body) sec.appendChild(A(el('p', 'pv-lead', s.body)));
      if(s.img) sec.appendChild(pic('sm'));
    }else if(L === 'section'){
      sec.appendChild(A(el('div', 'pv-sec-n', (ctx.sectionNo < 10 ? '0' : '') + (ctx.sectionNo || 1))));
      sec.appendChild(A(el('h2', 'pv-sec-t', s.title)));
      sec.appendChild(A(el('div', 'pv-sec-bar')));
      if(s.body) sec.appendChild(A(el('p', 'pv-lead', s.body)));
    }else if(L === 'image'){
      if(s.title) sec.appendChild(A(el('h3', 'pv-h2', s.title)));
      if(s.img) sec.appendChild(pic());
      if(s.body) sec.appendChild(A(el('p', 'pv-cap', s.body)));
    }else if(L === 'two'){
      if(s.title) sec.appendChild(A(el('h3', 'pv-h2', s.title)));
      var cols = el('div', 'pv-cols');
      cols.appendChild(bullets(s.body, true)); cols.appendChild(bullets(s.body2, true));
      sec.appendChild(cols);
    }else if(L === 'blank'){
      if(s.body) sec.appendChild(A(el('div', 'pv-free', s.body)));
      if(s.img) sec.appendChild(pic());
    }else if(L === 'quote'){
      sec.appendChild(A(el('div', 'pv-quote-m', '“')));
      sec.appendChild(A(el('p', 'pv-quote-t', s.body)));
      if(s.body2) sec.appendChild(A(el('div', 'pv-quote-a', s.body2)));
    }else if(L === 'stats'){
      if(s.title) sec.appendChild(A(el('h3', 'pv-h2', s.title)));
      var g = el('div', 'pv-stats-g');
      lines(s.body).slice(0, 6).forEach(function(line){
        var p = pair(line), c = el('div', 'pv-stat');
        var b = el('b', '', p[0]); b.dataset.count = p[0];
        if(p[0].length > 9) b.style.fontSize = '3.4cqw'; else if(p[0].length > 6) b.style.fontSize = '4.4cqw';   // ตัวเลขยาวไม่ล้นกรอบ
        c.appendChild(b); if(p[1]) c.appendChild(el('span', '', p[1]));
        g.appendChild(A(c, true));
      });
      sec.appendChild(g);
    }else if(L === 'timeline'){
      if(s.title) sec.appendChild(A(el('h3', 'pv-h2', s.title)));
      var items = lines(s.body).slice(0, 10);
      var tl = el('div', 'pv-tl' + (items.length > 5 ? ' v' : ''));
      items.forEach(function(line){
        var p = pair(line), it = el('div', 'pv-tl-i');
        it.appendChild(el('b', '', p[1] ? p[0] : ''));
        it.appendChild(el('span', '', p[1] || p[0]));
        tl.appendChild(A(it, true));
      });
      sec.appendChild(tl);
    }else if(L === 'quiz'){
      sec.appendChild(A(el('h3', 'pv-h2', s.title)));
      var qz = el('div', 'pv-qz'), letters = 'ABCDEF';
      lines(s.body).slice(0, 6).forEach(function(line, k){
        var ok = line.charAt(0) === '*';
        var b = el('button', 'pv-choice'); b.type = 'button';
        if(ok) b.dataset.ok = '1';
        b.appendChild(el('i', '', letters.charAt(k)));
        b.appendChild(el('span', '', ok ? line.slice(1).trim() : line));
        qz.appendChild(A(b));
      });
      sec.appendChild(qz);
      if(s.body2){ var ex = el('div', 'pv-explain', s.body2); ex.hidden = true; sec.appendChild(ex); }
    }else if(L === 'video'){
      if(s.title) sec.appendChild(A(el('h3', 'pv-h2', s.title)));
      var vi = videoInfo(s.video), box = el('div', 'pv-vbox');
      if(vi){
        box.dataset.src = vi.embed;
        if(vi.thumb) box.style.backgroundImage = 'url("' + vi.thumb + '")';
        box.appendChild(el('div', 'pv-vplay'));
      }
      sec.appendChild(A(box));
      if(s.body) sec.appendChild(A(el('p', 'pv-cap', s.body)));
    }else{                                                  // content
      if(s.title) sec.appendChild(A(el('h3', 'pv-h2', s.title)));
      sec.appendChild(bullets(s.body, true));
      if(s.img) sec.appendChild(pic('sm'));
    }
    return sec;
  }

  /** ลำดับของสไลด์ "หัวข้อบท" (ใช้เลข 01, 02 …) */
  function sectionNos(deck){
    var n = 0;
    return (deck.slides || []).map(function(s){ if(s.layout === 'section') n++; return n || 1; });
  }

  /** กรอบสไลด์ 16:9 ไว้แสดงแบบนิ่ง (ภาพย่อ / ตัวอย่างในตัวแก้ไข / ภาพรวม) */
  function stage(s, ctx){
    var wrap = el('div', 'pv-deck pv-static');
    wrap.style.border = 'none'; wrap.style.borderRadius = '0'; wrap.style.background = 'transparent';
    applyLook(wrap, ctx.deck, ctx.themes);
    var st = el('div', 'pv-stage'); st.appendChild(el('div', 'pv-decor'));
    st.appendChild(slide(s, ctx));
    wrap.appendChild(st);
    return wrap;
  }

  /* ── นับเลขขึ้น (สไลด์ตัวเลขเด่น) ── */
  function countUp(sec){
    [].forEach.call(sec.querySelectorAll('.pv-stat b[data-count]'), function(b){
      var raw = b.dataset.count, m = raw.match(/^([^0-9\-]*)(-?[0-9][0-9,]*\.?[0-9]*)(.*)$/);
      if(!m || window.matchMedia && matchMedia('(prefers-reduced-motion:reduce)').matches){ b.textContent = raw; return; }
      var target = parseFloat(m[2].replace(/,/g, '')), dec = (m[2].split('.')[1] || '').length, comma = m[2].indexOf(',') >= 0;
      var t0 = performance.now(), dur = 1100;
      (function step(now){
        var k = Math.min(1, (now - t0) / dur); k = 1 - Math.pow(1 - k, 3);
        var v = (target * k).toFixed(dec);
        if(comma) v = v.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        b.textContent = m[1] + v + m[3];
        if(k < 1) requestAnimationFrame(step);
      })(t0);
    });
  }

  /* ── พลุกระดาษ ── */
  function confetti(host, colors){
    if(window.matchMedia && matchMedia('(prefers-reduced-motion:reduce)').matches) return;
    var cv = el('canvas', 'pv-fx-canvas'); host.appendChild(cv);
    var W = cv.width = host.clientWidth * (window.devicePixelRatio || 1), H = cv.height = host.clientHeight * (window.devicePixelRatio || 1);
    var ctx = cv.getContext('2d'), parts = [], cols = colors && colors.length ? colors : ['#f43f5e', '#f59e0b', '#22c55e', '#3b82f6', '#a855f7'];
    for(var i = 0; i < 140; i++){
      parts.push({ x: W / 2 + (Math.random() - .5) * W * .3, y: H * .55, vx: (Math.random() - .5) * W * .022, vy: -Math.random() * H * .03 - H * .012,
                   s: (Math.random() * 6 + 5) * (window.devicePixelRatio || 1), r: Math.random() * 6, vr: (Math.random() - .5) * .3, c: cols[i % cols.length] });
    }
    var t0 = performance.now();
    (function frame(now){
      var el2 = now - t0; ctx.clearRect(0, 0, W, H);
      parts.forEach(function(p){
        p.vy += H * .0009; p.x += p.vx; p.y += p.vy; p.r += p.vr;
        ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.r); ctx.fillStyle = p.c;
        ctx.globalAlpha = Math.max(0, 1 - el2 / 2600); ctx.fillRect(-p.s / 2, -p.s / 4, p.s, p.s / 2); ctx.restore();
      });
      if(el2 < 2600) requestAnimationFrame(frame); else cv.remove();
    })(t0);
  }

  /* ============================================================
   * ตัวเล่นสไลด์
   * cfg: {deck, themes, base, lbl, start, controls(true), onChange(i)}
   * ============================================================ */
  function player(root, cfg){
    var deck = cfg.deck, themes = cfg.themes, base = cfg.base || '';
    var T = {}; Object.keys(LBL).forEach(function(k){ T[k] = (cfg.lbl && cfg.lbl[k]) || LBL[k]; });
    root.textContent = '';
    root.classList.add('pv-deck');
    var LK = applyLook(root, deck, themes);
    var st = el('div', 'pv-stage'); st.appendChild(el('div', 'pv-decor'));
    var nos = sectionNos(deck), secs = [];
    deck.slides.forEach(function(s, i){
      var sec = slide(s, { base:base, deck:deck, index:i, sectionNo:nos[i] });
      sec.hidden = true; st.appendChild(sec); secs.push(sec);
    });
    var prog = el('div', 'pv-prog'), progI = el('i'); prog.appendChild(progI); st.appendChild(prog);
    var laser = el('div', 'pv-laser'); st.appendChild(laser);
    root.appendChild(st);

    var i = -1, autoT = null;
    var bar = null, numEl = null, bAuto = null, bLaser = null, ov = null;
    if(cfg.controls !== false){
      bar = el('div', 'pv-bar');
      function btn(act, icon, title){ var b = el('button', 'pv-btn'); b.type = 'button'; b.dataset.act = act; b.title = title; b.setAttribute('aria-label', title);
        var ic = el('i', 'fi fi-rr-' + icon); b.appendChild(ic); bar.appendChild(b); return b; }
      btn('prev', 'angle-left', T.prev);
      numEl = el('span', 'pv-num'); bar.appendChild(numEl);
      btn('next', 'angle-right', T.next);
      bar.appendChild(el('span', 'pv-sp'));
      btn('overview', 'apps', T.overview);
      bAuto = btn('auto', 'play', T.auto);
      bLaser = btn('laser', 'cursor', T.laser);
      btn('confetti', 'party-horn', T.confetti);
      btn('full', 'expand', T.full);
      root.appendChild(bar);
    }

    function stepsOf(sec){ return [].slice.call(sec.querySelectorAll('.pv-step')); }
    function hiddenStep(sec){ return stepsOf(sec).filter(function(x){ return !x.classList.contains('pv-shown'); })[0]; }
    function stopVideo(sec){ [].forEach.call(sec.querySelectorAll('.pv-vbox iframe'), function(f){ f.remove(); }); }
    function show(n, dir){
      if(n < 0 || n >= secs.length) return;
      var old = secs[i];
      if(old && old !== secs[n]){ old.hidden = true; old.classList.remove('pv-in', 'pv-on'); stopVideo(old); }
      var prevI = i; i = n;
      var sec = secs[i], s = deck.slides[i];
      dir = dir || (n >= prevI ? 1 : -1);
      sec.style.setProperty('--dir', dir);
      sec.dataset.run = s.fx || deck.fx || 'fade';
      sec.classList.remove('pv-in', 'pv-on'); void sec.offsetWidth;            // เริ่มแอนิเมชันใหม่
      sec.hidden = false;
      sec.classList.add('pv-in', 'pv-on');
      // ย้อนกลับมา = เห็นทุกข้อแล้ว / ไปข้างหน้า = เริ่มซ่อนใหม่
      stepsOf(sec).forEach(function(x){ x.classList.toggle('pv-shown', dir < 0); });
      countUp(sec);
      if(numEl) numEl.textContent = (i + 1) + ' / ' + secs.length;
      progI.style.width = ((i + 1) / secs.length * 100) + '%';
      if(ov && !ov.hidden) markOv();
      if(cfg.onChange) cfg.onChange(i);
    }
    function next(){
      var h = secs[i] && hiddenStep(secs[i]);
      if(h){ h.classList.add('pv-shown'); var c = h.querySelector('b[data-count]'); if(c) countUp(h); return true; }
      if(i < secs.length - 1){ show(i + 1, 1); return true; }
      return false;
    }
    function prev(){ if(i > 0) show(i - 1, -1); }

    // ── แบบทดสอบ / วิดีโอ (คลิกในสไลด์) ──
    st.addEventListener('click', function(e){
      var c = e.target.closest ? e.target.closest('.pv-choice') : null;
      if(c){
        var qz = c.parentNode; if(qz.classList.contains('done')) return;
        qz.classList.add('done');
        var right = c.dataset.ok === '1';
        c.classList.add(right ? 'ok' : 'no');
        [].forEach.call(qz.querySelectorAll('.pv-choice[data-ok="1"]'), function(x){ x.classList.add('ok'); });
        var ex = qz.parentNode.querySelector('.pv-explain'); if(ex) ex.hidden = false;
        if(right) confetti(st, [LK.ac, '#f59e0b', '#22c55e', '#3b82f6', '#f43f5e']);
        return;
      }
      var v = e.target.closest ? e.target.closest('.pv-vbox[data-src]') : null;
      if(v && !v.querySelector('iframe')){
        var f = document.createElement('iframe'); f.src = v.dataset.src;
        f.allow = 'autoplay; encrypted-media; picture-in-picture; fullscreen'; f.allowFullscreen = true;
        v.appendChild(f);
      }
    });

    // ── ภาพรวมทุกสไลด์ ──
    function markOv(){ [].forEach.call(ov.children, function(c, k){ c.classList.toggle('on', k === i); }); }
    function overview(){
      if(!ov){
        ov = el('div', 'pv-ov'); ov.hidden = true;
        deck.slides.forEach(function(s, k){
          var cell = el('div', 'pv-ov-i');
          cell.appendChild(stage(s, { base:base, deck:deck, themes:themes, index:k, sectionNo:nos[k] }));
          cell.appendChild(el('em', '', k + 1));
          cell.addEventListener('click', function(){ ov.hidden = true; show(k); });
          ov.appendChild(cell);
        });
        root.appendChild(ov);
      }
      ov.hidden = !ov.hidden;
      if(!ov.hidden) markOv();
    }
    function auto(){
      if(autoT){ clearInterval(autoT); autoT = null; }
      else autoT = setInterval(function(){ if(!next()){ clearInterval(autoT); autoT = null; if(bAuto) bAuto.classList.remove('on'); } }, Math.max(2, deck.autoSec || 6) * 1000);
      if(bAuto){ bAuto.classList.toggle('on', !!autoT); bAuto.firstChild.className = 'fi fi-rr-' + (autoT ? 'pause' : 'play'); }
    }
    function toggleLaser(){ root.classList.toggle('pv-laser-on'); if(bLaser) bLaser.classList.toggle('on', root.classList.contains('pv-laser-on')); }
    function full(){ if(document.fullscreenElement) document.exitFullscreen(); else if(root.requestFullscreen) root.requestFullscreen(); }
    st.addEventListener('pointermove', function(e){
      if(!root.classList.contains('pv-laser-on')) return;
      var r = st.getBoundingClientRect();
      laser.style.left = (e.clientX - r.left) + 'px'; laser.style.top = (e.clientY - r.top) + 'px';
    });
    if(bar) bar.addEventListener('click', function(e){
      var b = e.target.closest ? e.target.closest('[data-act]') : null; if(!b) return;
      var a = b.dataset.act;
      if(a === 'next') next(); else if(a === 'prev') prev(); else if(a === 'full') full();
      else if(a === 'overview') overview(); else if(a === 'auto') auto(); else if(a === 'laser') toggleLaser();
      else if(a === 'confetti') confetti(st, [LK.ac, '#f59e0b', '#22c55e', '#3b82f6', '#f43f5e']);
    });
    // ปัดซ้าย/ขวาบนจอสัมผัส
    var sx = null;
    st.addEventListener('pointerdown', function(e){ if(e.pointerType !== 'mouse') sx = e.clientX; });
    st.addEventListener('pointerup', function(e){
      if(sx === null) return; var dx = e.clientX - sx; sx = null;
      if(Math.abs(dx) > 50){ if(dx < 0) next(); else prev(); }
    });
    function onKey(e){
      var t = e.target.tagName; if(t === 'INPUT' || t === 'TEXTAREA' || t === 'SELECT') return;
      var k = e.key;
      if(k === 'ArrowRight' || k === 'PageDown' || k === ' ' || k === 'Enter'){ e.preventDefault(); next(); }
      else if(k === 'ArrowLeft' || k === 'PageUp'){ e.preventDefault(); prev(); }
      else if(k === 'Home'){ show(0, -1); } else if(k === 'End'){ show(secs.length - 1, 1); }
      else if(k === 'f' || k === 'F'){ full(); } else if(k === 'o' || k === 'O'){ overview(); }
      else if(k === 'l' || k === 'L'){ toggleLaser(); } else if(k === 'c' || k === 'C'){ confetti(st, [LK.ac, '#f59e0b', '#22c55e', '#3b82f6']); }
      else if(k === 'Escape' && ov && !ov.hidden){ ov.hidden = true; }
    }
    root.setAttribute('tabindex', '0');
    root.addEventListener('keydown', onKey);

    show(Math.max(0, Math.min(secs.length - 1, cfg.start || 0)), 1);
    return { show:show, next:next, prev:prev, index:function(){ return i; }, confetti:function(){ confetti(st); },
             destroy:function(){ if(autoT) clearInterval(autoT); root.removeEventListener('keydown', onKey); root.textContent = ''; } };
  }

  function mount(){
    [].forEach.call(document.querySelectorAll('.pv-deck[data-pv]'), function(d){
      if(d.dataset.pvReady) return; d.dataset.pvReady = '1';
      var c; try{ c = JSON.parse(d.getAttribute('data-pv')); }catch(e){ return; }
      d.removeAttribute('data-pv');
      d._pv = player(d, c);
    });
  }

  window.AleanorPresent = { FONTS:FONTS, slide:slide, stage:stage, player:player, mount:mount, look:look, applyLook:applyLook,
                            sectionNos:sectionNos, confetti:confetti, loadFont:loadFont, videoInfo:videoInfo };
  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount); else mount();
})();
