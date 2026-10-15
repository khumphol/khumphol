/* ============================================================
 * Aleanor Cover — ตัวออกแบบภาพปก (วาดด้วย canvas ทั้งหมด)
 * สถานะทั้งหมดอยู่ใน S (JSON เดียวกับที่บันทึกลงฐานข้อมูล)
 * ============================================================ */
(function(){
  'use strict';
  var dataEl = document.getElementById('cvData');
  if(!dataEl) return;
  var D = JSON.parse(dataEl.textContent || '{}');
  var L = D.L || {};
  var S = D.design;
  var docKey = D.key || '';
  var assetUrl = D.assetUrl || '';

  var $ = function(id){ return document.getElementById(id); };
  var stage = $('cvStage'), canvas = $('cvCanvas'), ctx = canvas.getContext('2d');
  var selBox = $('cvSel'), gV = $('cvGuideV'), gH = $('cvGuideH');
  var scale = 1, selId = null;
  var HAS_LS = ('letterSpacing' in CanvasRenderingContext2D.prototype);
  var HAS_FILTER = ('filter' in CanvasRenderingContext2D.prototype);
  var SWATCH = ['#ffffff','#0f172a','#6366f1','#0ea5e9','#10b981','#f59e0b','#ef4444','#ec4899','#8b5cf6','#facc15','#e2e8f0','#64748b'];

  /* ---------- ตัวช่วยทั่วไป ---------- */
  function uid(){ return 'l' + Math.random().toString(36).slice(2, 9); }
  function clone(o){ return JSON.parse(JSON.stringify(o)); }
  function el(tag, cls, txt){ var e = document.createElement(tag); if(cls) e.className = cls; if(txt != null) e.textContent = txt; return e; }
  function layerById(id){ for(var i = 0; i < S.layers.length; i++) if(S.layers[i].id === id) return S.layers[i]; return null; }
  function hex7(c){ return (c || '#000000').slice(0, 7); }
  function withAlpha(c, a){ var h = Math.round(Math.max(0, Math.min(1, a)) * 255).toString(16); return hex7(c) + (h.length < 2 ? '0' + h : h); }
  function rnd(seed){ return function(){ seed |= 0; seed = seed + 0x6D2B79F5 | 0; var t = Math.imul(seed ^ seed >>> 15, 1 | seed);
    t = t + Math.imul(t ^ t >>> 7, 61 | t) ^ t; return ((t ^ t >>> 14) >>> 0) / 4294967296; }; }

  /* ---------- รูปภาพ (โหลดครั้งเดียว วาดซ้ำเมื่อพร้อม) ---------- */
  var IMG = {};
  function getImg(src){
    if(!src) return null;
    var it = IMG[src];
    if(!it){
      it = IMG[src] = { im: new Image(), ok: false, bad: false };
      it.im.onload = function(){ it.ok = true; render(); };
      it.im.onerror = function(){ it.bad = true; };
      it.im.src = D.base + src;
    }
    return it.ok ? it.im : null;
  }

  /* ---------- ฟอนต์ (รอให้โหลดก่อนวาด ไม่งั้นตัวอักษรไทยจะกระโดด) ---------- */
  var FONTS_OK = {};
  function fontStr(l, size){
    return (l.italic ? 'italic ' : '') + (l.weight || 400) + ' ' + Math.round(size || l.size) + 'px "' + l.font + '", "Noto Sans Thai", sans-serif';
  }
  function ensureFont(l){
    var k = l.font + '|' + l.weight + '|' + (l.italic ? 1 : 0);
    if(FONTS_OK[k] || !document.fonts || !document.fonts.load) return;
    FONTS_OK[k] = 'loading';
    document.fonts.load(fontStr(l, 40), 'กขa').then(function(){ FONTS_OK[k] = true; render(); }, function(){ FONTS_OK[k] = true; });
  }

  /* ============================================================
   * วาดพื้นหลัง
   * ============================================================ */
  function drawBg(c, w, h, bg){
    c.save();
    if(bg.type === 'solid'){
      c.fillStyle = bg.color; c.fillRect(0, 0, w, h);
    }else{
      var g;
      if(bg.radial){
        g = c.createRadialGradient(w * 0.5, h * 0.45, 0, w * 0.5, h * 0.5, Math.max(w, h) * 0.75);
      }else{
        var a = (bg.angle - 90) * Math.PI / 180, r = Math.sqrt(w * w + h * h) / 2;
        g = c.createLinearGradient(w / 2 - Math.cos(a) * r, h / 2 - Math.sin(a) * r, w / 2 + Math.cos(a) * r, h / 2 + Math.sin(a) * r);
      }
      g.addColorStop(0, bg.c1); g.addColorStop(1, bg.c2);
      c.fillStyle = g; c.fillRect(0, 0, w, h);
      if(bg.type === 'image' && bg.image){
        var im = getImg(bg.image);
        if(im){
          if(HAS_FILTER){
            var f = [];
            if(bg.bright !== 100) f.push('brightness(' + bg.bright + '%)');
            if(bg.blur > 0) f.push('blur(' + (bg.blur * w / 1280) + 'px)');
            c.filter = f.length ? f.join(' ') : 'none';
          }
          var bl = bg.blur > 0 ? bg.blur * 3 : 0;           // ขยายรูปเผื่อขอบเบลอ
          fitImage(c, im, -bl, -bl, w + bl * 2, h + bl * 2, bg.fit);
          c.filter = 'none';
        }
        if(bg.ovOp > 0){ c.fillStyle = withAlpha(bg.ovColor, bg.ovOp); c.fillRect(0, 0, w, h); }
      }
    }
    drawPattern(c, w, h, bg);
    c.restore();
  }

  function drawPattern(c, w, h, bg){
    if(!bg.pattern || bg.pattern === 'none' || bg.pop <= 0) return;
    var u = Math.min(w, h) / 12 * bg.pscale, R = rnd(4271), i, j, x, y;
    c.save();
    c.globalAlpha = bg.pop;
    c.fillStyle = c.strokeStyle = bg.pcolor;
    c.lineCap = 'round'; c.lineJoin = 'round';
    switch(bg.pattern){
      case 'dots':
        var sp = u * 0.55;
        for(y = sp / 2; y < h; y += sp) for(x = sp / 2; x < w; x += sp){ c.beginPath(); c.arc(x, y, sp * 0.12, 0, 7); c.fill(); }
        break;
      case 'grid':
        c.lineWidth = Math.max(1, u * 0.025);
        for(x = 0; x <= w; x += u * 0.7){ c.beginPath(); c.moveTo(x, 0); c.lineTo(x, h); c.stroke(); }
        for(y = 0; y <= h; y += u * 0.7){ c.beginPath(); c.moveTo(0, y); c.lineTo(w, y); c.stroke(); }
        break;
      case 'lines':
        c.lineWidth = Math.max(1, u * 0.06);
        for(x = -h; x < w + h; x += u * 0.45){ c.beginPath(); c.moveTo(x, 0); c.lineTo(x + h, h); c.stroke(); }
        break;
      case 'waves':
        c.lineWidth = Math.max(1, u * 0.06);
        for(y = u * 0.5; y < h + u; y += u * 0.8){
          c.beginPath();
          for(x = 0; x <= w; x += 8){ var yy = y + Math.sin(x / (u * 1.1)) * u * 0.22; if(x === 0) c.moveTo(x, yy); else c.lineTo(x, yy); }
          c.stroke();
        }
        break;
      case 'circles':
        for(i = 0; i < 7; i++){ c.beginPath(); c.arc(R() * w, R() * h, (0.08 + R() * 0.3) * Math.max(w, h), 0, 7); c.fill(); }
        break;
      case 'rings':
        c.lineWidth = Math.max(1.5, u * 0.08);
        var cx = w * 0.82, cy = h * 0.72;
        for(i = 1; i < 16; i++){ c.beginPath(); c.arc(cx, cy, i * u * 0.7, 0, 7); c.stroke(); }
        break;
      case 'triangles':
        c.lineWidth = Math.max(1.5, u * 0.07);
        for(i = 0; i < 26; i++){
          x = R() * w; y = R() * h; var s = u * (0.25 + R() * 0.7), rot = R() * 6.28;
          c.save(); c.translate(x, y); c.rotate(rot);
          c.beginPath(); c.moveTo(0, -s); c.lineTo(s * 0.87, s * 0.5); c.lineTo(-s * 0.87, s * 0.5); c.closePath();
          if(R() > 0.55) c.fill(); else c.stroke();
          c.restore();
        }
        break;
      case 'zigzag':
        c.lineWidth = Math.max(1.5, u * 0.07);
        for(y = u * 0.4; y < h + u; y += u * 0.9){
          c.beginPath(); c.moveTo(0, y);
          for(x = 0, j = 0; x <= w + u; x += u * 0.35, j++) c.lineTo(x, y + (j % 2 ? u * 0.22 : 0));
          c.stroke();
        }
        break;
      case 'blobs':
        for(i = 0; i < 5; i++){
          x = R() * w; y = R() * h; var rr = (0.25 + R() * 0.35) * Math.max(w, h);
          var g = c.createRadialGradient(x, y, 0, x, y, rr);
          g.addColorStop(0, hex7(bg.pcolor)); g.addColorStop(1, withAlpha(bg.pcolor, 0));
          c.fillStyle = g; c.fillRect(0, 0, w, h);
        }
        break;
    }
    c.restore();
  }

  function fitImage(c, im, x, y, w, h, fit){
    var iw = im.naturalWidth, ih = im.naturalHeight;
    if(!iw || !ih) return;
    if(fit === 'contain'){
      var s = Math.min(w / iw, h / ih), dw = iw * s, dh = ih * s;
      c.drawImage(im, x + (w - dw) / 2, y + (h - dh) / 2, dw, dh);
    }else{
      var r = w / h, sw = iw, sh = ih, sx = 0, sy = 0;
      if(iw / ih > r){ sw = ih * r; sx = (iw - sw) / 2; } else { sh = iw / r; sy = (ih - sh) / 2; }
      c.drawImage(im, sx, sy, sw, sh, x, y, w, h);
    }
  }

  function roundRect(c, x, y, w, h, r){
    r = Math.max(0, Math.min(r, w / 2, h / 2));
    c.beginPath();
    c.moveTo(x + r, y); c.lineTo(x + w - r, y); c.quadraticCurveTo(x + w, y, x + w, y + r);
    c.lineTo(x + w, y + h - r); c.quadraticCurveTo(x + w, y + h, x + w - r, y + h);
    c.lineTo(x + r, y + h); c.quadraticCurveTo(x, y + h, x, y + h - r);
    c.lineTo(x, y + r); c.quadraticCurveTo(x, y, x + r, y); c.closePath();
  }

  /* ============================================================
   * ข้อความ — ตัดบรรทัดตามคำ (ภาษาไทยใช้ Intl.Segmenter แยกคำ)
   * ============================================================ */
  var SEG = null;
  try{ if(window.Intl && Intl.Segmenter) SEG = new Intl.Segmenter('th', { granularity: 'word' }); }catch(e){}
  function words(t){
    if(SEG){ var out = []; for(var it = SEG.segment(t)[Symbol.iterator](), n = it.next(); !n.done; n = it.next()) out.push(n.value.segment); return out; }
    return t.split(/(\s+)/);
  }
  function textLines(c, l){
    var txt = l.upper ? l.text.toUpperCase() : l.text;
    c.font = fontStr(l);
    if(HAS_LS) c.letterSpacing = (l.ls || 0) + 'px';
    var pad = l.box ? l.boxPad : 0;
    var maxW = l.fitW ? Infinity : Math.max(10, l.w - pad * 2);
    var lines = [];
    txt.split('\n').forEach(function(par){
      if(par === ''){ lines.push(''); return; }
      var cur = '';
      words(par).forEach(function(wd){
        var test = cur + wd;
        if(cur !== '' && c.measureText(test).width > maxW){
          lines.push(cur.replace(/\s+$/, ''));
          cur = wd.replace(/^\s+/, '');
          // คำเดียวยาวเกินบรรทัด → ตัดทีละตัวอักษร
          while(c.measureText(cur).width > maxW && cur.length > 1){
            var k = cur.length - 1;
            while(k > 1 && c.measureText(cur.slice(0, k)).width > maxW) k--;
            lines.push(cur.slice(0, k)); cur = cur.slice(k);
          }
        }else cur = test;
      });
      lines.push(cur);
    });
    var widest = 0;
    lines.forEach(function(s){ widest = Math.max(widest, c.measureText(s).width); });
    if(HAS_LS) c.letterSpacing = '0px';
    return { lines: lines, widest: widest, pad: pad, lineH: l.size * l.lh };
  }
  /** ปรับกรอบข้อความให้พอดีเนื้อหา (สูงตามจำนวนบรรทัด / กว้างพอดีถ้าเป็นป้าย) */
  function fitText(l){
    var m = textLines(ctx, l);
    var cx = l.x + l.w / 2, cy = l.y + l.h / 2;
    var nh = Math.max(l.size * l.lh, m.lines.length * m.lineH) + m.pad * 2;
    var nw = l.fitW ? Math.ceil(m.widest + m.pad * 2 + (l.stroke || 0) * 2 + 2) : l.w;
    // คงจุดยึดไว้: ขอบบนคงที่ ความกว้างขยายจากกลาง (ป้าย) หรือขอบซ้าย/ขวาตามการจัดวาง
    if(l.fitW){
      if(l.align === 'center') l.x = cx - nw / 2; else if(l.align === 'right') l.x = l.x + l.w - nw;
      l.w = nw;
    }
    l.h = nh;
    return m;
  }

  /* ============================================================
   * วาดแต่ละชั้น
   * ============================================================ */
  function drawLayer(c, l){
    if(l.hidden) return;
    c.save();
    c.translate(l.x + l.w / 2, l.y + l.h / 2);
    if(l.rot) c.rotate(l.rot * Math.PI / 180);
    c.translate(-l.w / 2, -l.h / 2);
    c.globalAlpha = l.op;
    var sh = function(on){
      if(on && l.shadow){ c.shadowColor = l.shColor; c.shadowBlur = l.shBlur; c.shadowOffsetY = l.shBlur * 0.3; }
      else { c.shadowColor = 'transparent'; c.shadowBlur = 0; c.shadowOffsetY = 0; }
    };

    if(l.type === 'text'){
      ensureFont(l);
      var m = textLines(c, l);
      if(l.box){
        sh(true);
        c.fillStyle = withAlpha(l.boxColor, l.boxOp);
        roundRect(c, 0, 0, l.w, l.h, l.boxRadius); c.fill();
      }
      c.font = fontStr(l);
      if(HAS_LS) c.letterSpacing = (l.ls || 0) + 'px';
      c.textBaseline = 'middle';
      c.textAlign = l.align;
      var tx = l.align === 'center' ? l.w / 2 : (l.align === 'right' ? l.w - m.pad : m.pad);
      m.lines.forEach(function(s, i){
        var ty = m.pad + m.lineH * (i + 0.5);
        if(l.stroke > 0){
          sh(!l.box);
          c.lineJoin = 'round'; c.lineWidth = l.stroke * 2; c.strokeStyle = l.strokeColor;
          c.strokeText(s, tx, ty);
        }
        sh(!l.box && !(l.stroke > 0));
        c.fillStyle = l.color;
        c.fillText(s, tx, ty);
      });
      if(HAS_LS) c.letterSpacing = '0px';
    }
    else if(l.type === 'image'){
      var im = getImg(l.src);
      sh(true);
      roundRect(c, 0, 0, l.w, l.h, l.radius);
      if(im){
        if(l.shadow){ c.fillStyle = '#000'; c.fill(); }      // ให้เงาเกิดตามรูปทรง แล้วค่อยวาดรูปทับ
        sh(false);
        c.save(); c.clip();
        if(l.flip){ c.translate(l.w, 0); c.scale(-1, 1); }
        if(HAS_FILTER){
          var f = [];
          if(l.bright !== 100) f.push('brightness(' + l.bright + '%)');
          if(l.gray) f.push('grayscale(1)');
          c.filter = f.length ? f.join(' ') : 'none';
        }
        fitImage(c, im, 0, 0, l.w, l.h, l.fit);
        c.filter = 'none';
        c.restore();
      }else{
        c.fillStyle = 'rgba(148,163,184,.35)'; c.fill();
      }
      if(l.border > 0){
        sh(false);
        c.lineWidth = l.border; c.strokeStyle = l.borderColor;
        roundRect(c, l.border / 2, l.border / 2, l.w - l.border, l.h - l.border, Math.max(0, l.radius - l.border / 2)); c.stroke();
      }
    }
    else{
      sh(true);
      var w = l.w, h = l.h;
      c.beginPath();
      if(l.shape === 'circle'){ c.ellipse(w / 2, h / 2, w / 2, h / 2, 0, 0, Math.PI * 2); }
      else if(l.shape === 'pill'){ roundRect(c, 0, 0, w, h, h / 2); }
      else if(l.shape === 'line'){ roundRect(c, 0, 0, w, h, Math.min(h, w) / 2); }
      else if(l.shape === 'triangle'){ c.moveTo(w / 2, 0); c.lineTo(w, h); c.lineTo(0, h); c.closePath(); }
      else if(l.shape === 'star'){
        for(var i = 0; i < 10; i++){
          var a = -Math.PI / 2 + i * Math.PI / 5, rr = i % 2 ? 0.42 : 1;
          var px = w / 2 + Math.cos(a) * w / 2 * rr, py = h / 2 + Math.sin(a) * h / 2 * rr + h * 0.04;
          if(i === 0) c.moveTo(px, py); else c.lineTo(px, py);
        }
        c.closePath();
      }
      else { roundRect(c, 0, 0, w, h, l.radius); }
      if(!l.noFill){
        if(l.fill2){
          var g = c.createLinearGradient(0, 0, w, h); g.addColorStop(0, l.fill); g.addColorStop(1, l.fill2);
          c.fillStyle = g;
        }else c.fillStyle = l.fill;
        c.fill();
      }
      if(l.stroke > 0){
        sh(l.noFill);
        c.lineWidth = l.stroke; c.strokeStyle = l.strokeColor; c.stroke();
      }
    }
    c.restore();
  }

  function paint(c, w, h){
    c.clearRect(0, 0, w, h);
    drawBg(c, w, h, S.bg);
    S.layers.forEach(function(l){ drawLayer(c, l); });
  }

  var rafId = 0;
  function render(){
    if(rafId) return;
    rafId = requestAnimationFrame(function(){
      rafId = 0;
      S.layers.forEach(function(l){ if(l.type === 'text') fitText(l); });
      paint(ctx, S.w, S.h);
      placeSel();
    });
  }

  /* ============================================================
   * ขนาดพื้นที่วาดบนจอ
   * ============================================================ */
  function layout(){
    var wrap = $('cvStageWrap');
    var availW = wrap.clientWidth - 32;
    var availH = Math.max(260, window.innerHeight - (D.embed ? 170 : 250));
    scale = Math.min(availW / S.w, availH / S.h, 1);
    stage.style.width = Math.round(S.w * scale) + 'px';
    stage.style.height = Math.round(S.h * scale) + 'px';
    if(canvas.width !== S.w || canvas.height !== S.h){ canvas.width = S.w; canvas.height = S.h; }
    $('cvSize').textContent = S.w + ' × ' + S.h + ' px';
    render();
  }
  window.addEventListener('resize', layout);

  /* ============================================================
   * กรอบเลือก + ลาก / ย่อขยาย / หมุน
   * ============================================================ */
  function placeSel(){
    var l = selId ? layerById(selId) : null;
    if(!l || l.hidden){ selBox.style.display = 'none'; return; }
    selBox.style.display = 'block';
    selBox.style.left = (l.x * scale) + 'px';
    selBox.style.top = (l.y * scale) + 'px';
    selBox.style.width = (l.w * scale) + 'px';
    selBox.style.height = (l.h * scale) + 'px';
    selBox.style.transform = 'rotate(' + (l.rot || 0) + 'deg)';
    selBox.classList.toggle('locked', !!l.locked);
    // ชิ้นเล็กบนจอ: ซ่อนจุดจับด้านข้าง ไม่งั้นจุดจับบังจนลากย้ายไม่ได้ (กดโดนจุดย่อขยายแทน)
    var sw = l.w * scale, shh = l.h * scale;
    var sideH = sw >= 56 && shh >= 30, sideV = shh >= 56 && sw >= 30;
    // ข้อความ: สูงตามเนื้อหาเอง จึงไม่มีจุดจับบน/ล่าง
    ['e', 'w'].forEach(function(h){ selBox.querySelector('.hd.' + h).style.display = sideH ? '' : 'none'; });
    ['n', 's'].forEach(function(h){ selBox.querySelector('.hd.' + h).style.display = (l.type !== 'text' && sideV) ? '' : 'none'; });
    var corners = sw >= 22 || shh >= 22;
    ['nw', 'ne', 'sw', 'se'].forEach(function(h){ selBox.querySelector('.hd.' + h).style.display = corners ? '' : 'none'; });
  }

  function toDesign(e){
    var r = stage.getBoundingClientRect();
    return { x: (e.clientX - r.left) / scale, y: (e.clientY - r.top) / scale };
  }
  function hit(p){
    for(var i = S.layers.length - 1; i >= 0; i--){
      var l = S.layers[i];
      if(l.hidden) continue;
      var cx = l.x + l.w / 2, cy = l.y + l.h / 2, a = -(l.rot || 0) * Math.PI / 180;
      var dx = p.x - cx, dy = p.y - cy;
      var lx = dx * Math.cos(a) - dy * Math.sin(a), ly = dx * Math.sin(a) + dy * Math.cos(a);
      if(Math.abs(lx) <= l.w / 2 && Math.abs(ly) <= l.h / 2) return l;
    }
    return null;
  }

  var drag = null;
  stage.addEventListener('pointerdown', function(e){
    if(e.button !== 0) return;
    var handle = e.target.getAttribute && e.target.getAttribute('data-h');
    var p = toDesign(e);
    var l = handle ? layerById(selId) : hit(p);
    if(!handle){
      select(l ? l.id : null);
      if(!l) return;
    }
    if(!l || l.locked) return;
    e.preventDefault();
    stage.setPointerCapture(e.pointerId);
    drag = { mode: handle || 'move', start: p, orig: clone(l), id: l.id, moved: false };
  });
  stage.addEventListener('pointermove', function(e){
    if(!drag) return;
    var l = layerById(drag.id); if(!l) return;
    var p = toDesign(e), o = drag.orig;
    var dx = p.x - drag.start.x, dy = p.y - drag.start.y;
    if(Math.abs(dx) + Math.abs(dy) > 0.5) drag.moved = true;
    var snapT = 7 / scale;
    gV.style.display = gH.style.display = 'none';

    if(drag.mode === 'move'){
      var nx = o.x + dx, ny = o.y + dy, cx = nx + o.w / 2, cy = ny + o.h / 2;
      if(!e.altKey){
        // จับกึ่งกลางและขอบภาพ
        if(Math.abs(cx - S.w / 2) < snapT){ nx = S.w / 2 - o.w / 2; guide(gV, S.w / 2, true); }
        else if(Math.abs(nx) < snapT){ nx = 0; guide(gV, 0, true); }
        else if(Math.abs(nx + o.w - S.w) < snapT){ nx = S.w - o.w; guide(gV, S.w, true); }
        if(Math.abs(cy - S.h / 2) < snapT){ ny = S.h / 2 - o.h / 2; guide(gH, S.h / 2, false); }
        else if(Math.abs(ny) < snapT){ ny = 0; guide(gH, 0, false); }
        else if(Math.abs(ny + o.h - S.h) < snapT){ ny = S.h - o.h; guide(gH, S.h, false); }
      }
      l.x = nx; l.y = ny;
    }
    else if(drag.mode === 'rot'){
      var ccx = o.x + o.w / 2, ccy = o.y + o.h / 2;
      var ang = Math.atan2(p.y - ccy, p.x - ccx) * 180 / Math.PI + 90;
      if(e.shiftKey) ang = Math.round(ang / 15) * 15;
      else [0, 90, 180, 270, 360, -90].forEach(function(t){ if(Math.abs(ang - t) < 4) ang = t; });
      if(ang > 180) ang -= 360;
      l.rot = Math.round(ang * 10) / 10;
    }
    else{
      resize(l, o, dx, dy, drag.mode, e.shiftKey);
    }
    render();
    if(drag.mode !== 'move' || !drag.throttle){ drag.throttle = true; setTimeout(function(){ if(drag) drag.throttle = false; syncProps(); }, 80); }
  });
  function endDrag(){
    if(!drag) return;
    var moved = drag.moved;
    drag = null;
    gV.style.display = gH.style.display = 'none';
    if(moved){ commit(); buildProps(); }
  }
  stage.addEventListener('pointerup', endDrag);
  stage.addEventListener('pointercancel', endDrag);
  stage.addEventListener('dblclick', function(e){
    var l = hit(toDesign(e));
    if(l && l.type === 'text'){ select(l.id); var ta = document.querySelector('#cvProps textarea'); if(ta){ ta.focus(); ta.select(); } }
  });

  function guide(g, v, vertical){
    g.style.display = 'block';
    if(vertical) g.style.left = (v * scale) + 'px'; else g.style.top = (v * scale) + 'px';
  }

  function resize(l, o, dx, dy, h, free){
    var a = (o.rot || 0) * Math.PI / 180, cos = Math.cos(a), sin = Math.sin(a);
    // เปลี่ยนการเลื่อนเมาส์ให้เป็นแกนของชั้นที่หมุนอยู่
    var lx = dx * cos + dy * sin, ly = -dx * sin + dy * cos;
    var sx = h.indexOf('e') >= 0 ? 1 : (h.indexOf('w') >= 0 ? -1 : 0);
    var sy = h.indexOf('s') >= 0 ? 1 : (h.indexOf('n') >= 0 ? -1 : 0);
    var nw = o.w, nh = o.h, min = 12;
    var corner = sx !== 0 && sy !== 0;

    if(l.type === 'text'){
      if(corner){
        var f = Math.max(0.05, (o.w + sx * lx) / o.w);
        l.size = Math.max(6, Math.round(o.size * f));
        l.boxPad = Math.round(o.boxPad * f);
        l.stroke = Math.round(o.stroke * f);
        nw = Math.max(min, o.w * f);
        l.w = nw; fitText(l); nh = l.h;
      }else if(sx !== 0){
        nw = Math.max(min, o.w + sx * lx);
        l.fitW = false;
        l.w = nw; fitText(l); nh = l.h;
      }else return;
    }else{
      if(sx !== 0) nw = Math.max(min, o.w + sx * lx);
      if(sy !== 0) nh = Math.max(min, o.h + sy * ly);
      var keep = corner && (l.type === 'image' ? !free : free);   // รูปคงสัดส่วนเป็นค่าเริ่มต้น / รูปทรงกด Shift
      if(keep){
        var f2 = Math.max(nw / o.w, nh / o.h);
        nw = Math.max(min, o.w * f2); nh = Math.max(min, o.h * f2);
      }
    }
    // เลื่อนจุดศูนย์กลางให้ขอบฝั่งตรงข้ามอยู่ที่เดิม
    var shL = sx * (nw - o.w) / 2, shT = (l.type === 'text') ? (nh - o.h) / 2 : sy * (nh - o.h) / 2;
    var ocx = o.x + o.w / 2, ocy = o.y + o.h / 2;
    var ncx = ocx + shL * cos - shT * sin, ncy = ocy + shL * sin + shT * cos;
    l.w = nw; l.h = nh;
    l.x = ncx - nw / 2; l.y = ncy - nh / 2;
  }

  /* ============================================================
   * ประวัติ (ย้อนกลับ/ทำซ้ำ) + บันทึกอัตโนมัติ
   * ============================================================ */
  var hist = [JSON.stringify(S)], hi = 0, dirty = false;
  function commit(){
    var snap = JSON.stringify(S);
    if(snap === hist[hi]) return;
    hist = hist.slice(0, hi + 1); hist.push(snap);
    if(hist.length > 80) hist.shift();
    hi = hist.length - 1;
    dirty = true;
    undoState();
    scheduleSave();
  }
  function undoState(){ $('cvUndo').disabled = hi <= 0; $('cvRedo').disabled = hi >= hist.length - 1; }
  function restore(i){
    hi = i; S = JSON.parse(hist[hi]);
    if(selId && !layerById(selId)) selId = null;
    dirty = true;
    layout(); buildProps(); buildLayers(); syncBgUi(); undoState(); scheduleSave();
  }
  $('cvUndo').addEventListener('click', function(){ if(hi > 0) restore(hi - 1); });
  $('cvRedo').addEventListener('click', function(){ if(hi < hist.length - 1) restore(hi + 1); });
  undoState();

  var saveT = null, saving = false, pendingSave = false;
  function scheduleSave(){
    // ภาพที่เปิดมาจากฟอร์ม (เลือกภาพปก) ยังไม่สร้างเอกสารจนกว่าจะกดบันทึก/ใช้ภาพนี้ — กันเอกสารว่างค้างใน Docs
    if(!docKey && D.pick) return;
    clearTimeout(saveT);
    saveT = setTimeout(function(){ save(false); }, 2500);
  }
  function setState(cls, txt){ var s = $('cvState'); s.className = 'cv-state ' + (cls || ''); s.textContent = txt || ''; }

  function post(url, fd){
    fd.append('csrf_token', D.csrf);
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function(r){ return r.json(); });
  }
  function exportBlob(type, q){
    var c = document.createElement('canvas'); c.width = S.w; c.height = S.h;
    paint(c.getContext('2d'), S.w, S.h);
    return new Promise(function(res){ c.toBlob(res, type || 'image/png', q); });
  }
  function title(){ return ($('cvTitle').value || '').trim() || L.untitled || 'Cover'; }

  function save(manual){
    if(saving){ pendingSave = true; return Promise.resolve(false); }
    saving = true; setState('saving', L.saving);
    var fd = new FormData();
    fd.append('action', 'save'); fd.append('type', 'cover'); fd.append('key', docKey);
    fd.append('title', title()); fd.append('content', JSON.stringify(S));
    var wasNew = !docKey;
    return post(D.saveUrl, fd).then(function(j){
      if(!j || !j.ok) throw j || {};
      docKey = j.key;
      if(wasNew){
        var url = D.editUrl + D.sep + 'key=' + encodeURIComponent(docKey) + (D.embed ? '&embed=1' : '') + (D.pick ? '&pick=1' : '');
        try{ history.replaceState(null, '', url); }catch(e){}
        if(D.folder){ var mf = new FormData(); mf.append('action', 'move'); mf.append('kind', 'doc'); mf.append('key', docKey); mf.append('folder', D.folder); post(D.fsUrl, mf); }
      }
      return exportBlob('image/jpeg', 0.92);
    }).then(function(blob){
      var rf = new FormData();
      rf.append('action', 'render'); rf.append('key', docKey); rf.append('image', blob, 'cover.jpg');
      return post(D.coverUrl, rf);
    }).then(function(j){
      if(!j || !j.ok) throw j || {};
      assetUrl = j.url;
      dirty = false;
      setState('saved', L.saved);
      return true;
    }).catch(function(err){
      if(err && err.error === 'quota') setState('error', L.full);
      else setState('error', L.failed || 'Error');
      return false;
    }).then(function(ok){
      saving = false;
      if(pendingSave){ pendingSave = false; save(false); }
      return ok;
    });
  }
  $('cvSave').addEventListener('click', function(){ clearTimeout(saveT); save(true); });
  $('cvTitle').addEventListener('input', function(){ dirty = true; scheduleSave(); });
  window.addEventListener('beforeunload', function(e){ if(dirty && (docKey || !D.pick)){ e.preventDefault(); e.returnValue = ''; } });

  // เปิดจากฟอร์ม: บันทึกแล้วส่งภาพกลับไปให้หน้าที่เรียก
  if($('cvUse')) $('cvUse').addEventListener('click', function(){
    var btn = this; btn.disabled = true;
    clearTimeout(saveT);
    save(true).then(function(ok){
      btn.disabled = false;
      if(!ok) return;
      dirty = false;
      var msg = { t: 'aleanor-cover-use', key: docKey, url: assetUrl, name: title() };
      try{ (window.parent !== window ? window.parent : window.opener).postMessage(msg, location.origin); }catch(e){}
    });
  });

  function download(type){
    exportBlob(type, 0.95).then(function(blob){
      var a = document.createElement('a');
      a.href = URL.createObjectURL(blob);
      a.download = title().replace(/[\\/:*?"<>|]+/g, ' ') + (type === 'image/png' ? '.png' : '.jpg');
      document.body.appendChild(a); a.click();
      setTimeout(function(){ URL.revokeObjectURL(a.href); a.remove(); }, 1500);
    });
  }
  $('cvDlPng').addEventListener('click', function(){ download('image/png'); });
  $('cvDlJpg').addEventListener('click', function(){ download('image/jpeg'); });

  /* ============================================================
   * แผงซ้าย
   * ============================================================ */
  document.querySelectorAll('.cv-tabs button').forEach(function(b){
    b.addEventListener('click', function(){
      document.querySelectorAll('.cv-tabs button').forEach(function(x){ x.classList.toggle('on', x === b); });
      document.querySelectorAll('.cv-work > .cv-panel:first-child .cv-pane').forEach(function(p){ p.classList.toggle('on', p.getAttribute('data-pane') === b.getAttribute('data-tab')); });
      if(b.getAttribute('data-tab') === 'img') loadDocsImages();
    });
  });

  // ── ขนาด ──
  function buildPresets(){
    var box = $('cvPresets'); box.textContent = '';
    Object.keys(D.presets).forEach(function(k){
      var p = D.presets[k];
      var b = el('button', 'cv-pre' + (S.preset === k ? ' on' : ''));
      b.type = 'button';
      var ic = el('i', 'fi ' + p.icon); b.appendChild(ic);
      b.appendChild(el('b', null, p.label));
      b.appendChild(el('span', null, k === 'custom' ? '—' : (p.w + ' × ' + p.h)));
      b.addEventListener('click', function(){
        if(k === 'custom'){ S.preset = 'custom'; buildPresets(); $('cvW').focus(); return; }
        setSize(p.w, p.h, k);
      });
      box.appendChild(b);
    });
    $('cvW').value = S.w; $('cvH').value = S.h;
  }
  function setSize(w, h, preset){
    w = Math.max(200, Math.min(4000, Math.round(w))); h = Math.max(200, Math.min(4000, Math.round(h)));
    var fx = w / S.w, fy = h / S.h, f = Math.min(fx, fy);
    S.layers.forEach(function(l){
      var cx = (l.x + l.w / 2) * fx, cy = (l.y + l.h / 2) * fy;
      l.w *= f; l.h *= f;
      if(l.type === 'text'){ l.size = Math.max(6, Math.round(l.size * f)); l.boxPad = Math.round(l.boxPad * f); l.stroke = Math.round(l.stroke * f); }
      if(l.radius) l.radius = Math.round(l.radius * f);
      l.x = cx - l.w / 2; l.y = cy - l.h / 2;
    });
    S.w = w; S.h = h; if(preset) S.preset = preset;
    buildPresets(); layout(); commit();
  }
  ['cvW', 'cvH'].forEach(function(id){
    $(id).addEventListener('change', function(){ setSize(+$('cvW').value || S.w, +$('cvH').value || S.h, 'custom'); });
  });

  // ── พื้นหลังสำเร็จรูป ──
  var BGS = [
    { type:'gradient', c1:'#4338ca', c2:'#0ea5e9', angle:135, pattern:'circles',  pcolor:'#ffffff', pop:.12 },
    { type:'gradient', c1:'#7c3aed', c2:'#ec4899', angle:120, pattern:'blobs',    pcolor:'#ffffff', pop:.2 },
    { type:'gradient', c1:'#047857', c2:'#14b8a6', angle:150, pattern:'waves',    pcolor:'#ffffff', pop:.14 },
    { type:'gradient', c1:'#f97316', c2:'#e11d48', angle:135, pattern:'triangles',pcolor:'#ffffff', pop:.16 },
    { type:'solid',    color:'#0f172a',                        pattern:'grid',     pcolor:'#6366f1', pop:.25 },
    { type:'gradient', c1:'#1e1b4b', c2:'#6d28d9', angle:160, pattern:'dots',     pcolor:'#c7d2fe', pop:.28 },
    { type:'gradient', c1:'#f59e0b', c2:'#db2777', angle:110, pattern:'rings',    pcolor:'#ffffff', pop:.15 },
    { type:'gradient', c1:'#ecfdf5', c2:'#a7f3d0', angle:135, pattern:'dots',     pcolor:'#047857', pop:.18 },
    { type:'solid',    color:'#fdf6e3',                        pattern:'lines',    pcolor:'#b45309', pop:.08 },
    { type:'gradient', c1:'#38bdf8', c2:'#1e3a8a', angle:0, radial:true, pattern:'rings', pcolor:'#ffffff', pop:.1 },
    { type:'gradient', c1:'#166534', c2:'#65a30d', angle:135, pattern:'zigzag',   pcolor:'#ffffff', pop:.12 },
    { type:'gradient', c1:'#dc2626', c2:'#f97316', angle:90,  pattern:'circles',  pcolor:'#fde68a', pop:.16 },
    { type:'gradient', c1:'#111827', c2:'#374151', angle:135, pattern:'lines',    pcolor:'#ffffff', pop:.07 },
    { type:'gradient', c1:'#ede9fe', c2:'#fce7f3', angle:135, pattern:'blobs',    pcolor:'#8b5cf6', pop:.22 },
    { type:'gradient', c1:'#e0f2fe', c2:'#bae6fd', angle:180, pattern:'waves',    pcolor:'#0369a1', pop:.16 },
    { type:'gradient', c1:'#78350f', c2:'#d97706', angle:135, pattern:'triangles',pcolor:'#fef3c7', pop:.15 },
    { type:'solid',    color:'#ffffff',                        pattern:'grid',     pcolor:'#94a3b8', pop:.22 },
    { type:'gradient', c1:'#0f766e', c2:'#1d4ed8', angle:45,  pattern:'dots',     pcolor:'#ffffff', pop:.16 }
  ];
  function buildBgs(){
    var box = $('cvBgs'); box.textContent = '';
    BGS.forEach(function(p){
      var c = el('canvas'); c.width = 240; c.height = 150;
      var bg = Object.assign(clone(S.bg), { radial:false, pscale:1, image:'', color:'#ffffff', c1:'#000000', c2:'#000000', angle:135 }, p);
      drawBg(c.getContext('2d'), 240, 150, bg);
      c.addEventListener('click', function(){
        Object.assign(S.bg, { radial:false, pscale:1 }, p);
        if(S.bg.type !== 'image') S.bg.ovOp = 0;
        syncBgUi(); render(); commit();
      });
      box.appendChild(c);
    });
  }

  // ── ช่องปรับพื้นหลัง (ผูกผ่าน data-bg) ──
  function syncBgUi(){
    document.querySelectorAll('[data-bg]').forEach(function(inp){
      var k = inp.getAttribute('data-bg'), v = S.bg[k];
      if(inp.type === 'checkbox') inp.checked = !!v;
      else if(inp.type === 'color') inp.value = hex7(v);
      else inp.value = v;
    });
    document.querySelectorAll('[data-v]').forEach(function(s){
      if(!s.classList.contains('v')) return;
      var k = s.getAttribute('data-v'), v = S.bg[k];
      s.textContent = (k === 'ovOp' || k === 'pop') ? Math.round(v * 100) + '%' : (k === 'pscale' ? (+v).toFixed(1) + '×' : (k === 'angle' ? v + '°' : (k === 'bright' ? v + '%' : v)));
    });
    $('cvBgType').querySelectorAll('button').forEach(function(b){ b.classList.toggle('on', b.getAttribute('data-v') === S.bg.type); });
    $('cvBgFit').querySelectorAll('button').forEach(function(b){ b.classList.toggle('on', b.getAttribute('data-v') === S.bg.fit); });
    $('cvBgSolid').style.display = S.bg.type === 'solid' ? '' : 'none';
    $('cvBgGrad').style.display = S.bg.type === 'solid' ? 'none' : '';
    $('cvBgImg').style.display = S.bg.type === 'image' ? '' : 'none';
  }
  document.querySelectorAll('[data-bg]').forEach(function(inp){
    var k = inp.getAttribute('data-bg');
    var apply = function(){
      var v = inp.type === 'checkbox' ? inp.checked : (inp.type === 'range' ? parseFloat(inp.value) : inp.value);
      S.bg[k] = v; syncBgUi(); render();
    };
    inp.addEventListener('input', apply);
    inp.addEventListener('change', function(){ apply(); commit(); });
  });
  $('cvBgType').addEventListener('click', function(e){
    var b = e.target.closest('button'); if(!b) return;
    S.bg.type = b.getAttribute('data-v');
    if(S.bg.type === 'image' && !S.bg.image){ $('cvFileBg').click(); }
    syncBgUi(); render(); commit();
  });
  $('cvBgFit').addEventListener('click', function(e){
    var b = e.target.closest('button'); if(!b) return;
    S.bg.fit = b.getAttribute('data-v'); syncBgUi(); render(); commit();
  });

  /* ---------- อัปโหลดรูป (ใช้ทั้งพื้นหลังและชั้นรูป) ---------- */
  function uploadImage(file, cb){
    if(!file) return;
    if(!/^image\/(png|jpe?g|webp|gif)$/i.test(file.type)){ (window.dlgAlert || alert)(L.badType); return; }
    setState('saving', L.uploading);
    var fd = new FormData(); fd.append('action', 'upload'); fd.append('file', file);
    post(D.coverUrl, fd).then(function(j){
      if(!j || !j.ok){ setState('error', j && j.error === 'quota' ? L.full : (L.failed || 'Error')); return; }
      setState('', '');
      docsImgs = null;
      cb(j.src);
    }).catch(function(){ setState('error', L.failed || 'Error'); });
  }
  function dropZone(zone, input, onFile){
    zone.addEventListener('click', function(){ input.click(); });
    input.addEventListener('change', function(){ onFile(input.files && input.files[0]); input.value = ''; });
    ['dragenter', 'dragover'].forEach(function(ev){ zone.addEventListener(ev, function(e){ e.preventDefault(); zone.classList.add('over'); }); });
    ['dragleave', 'drop'].forEach(function(ev){ zone.addEventListener(ev, function(e){ e.preventDefault(); zone.classList.remove('over'); }); });
    zone.addEventListener('drop', function(e){ if(e.dataTransfer && e.dataTransfer.files[0]) onFile(e.dataTransfer.files[0]); });
  }
  function setBgImage(src){ S.bg.image = src; S.bg.type = 'image'; if(!S.bg.ovOp) S.bg.ovOp = 0.25; syncBgUi(); render(); commit(); }
  dropZone($('cvBgDrop'), $('cvFileBg'), function(f){ uploadImage(f, setBgImage); });
  $('cvBgFromDocs').addEventListener('click', function(){ chooseFromDocs(function(it){ setBgImage(it.src); }); });

  /* ---------- รูปจาก Aleanor Docs ---------- */
  var docsImgs = null;
  function fetchDocsImages(){
    if(docsImgs) return Promise.resolve(docsImgs);
    var fd = new FormData(); fd.append('action', 'images');
    return post(D.coverUrl, fd).then(function(j){
      docsImgs = (j && j.ok ? j.items : []).filter(function(it){ return !(it.kind === 'cover' && it.key === docKey); });
      return docsImgs;
    });
  }
  function loadDocsImages(){
    var box = $('cvImgs');
    fetchDocsImages().then(function(items){
      box.textContent = '';
      if(!items.length){ box.appendChild(el('div', 'cv-empty', L.noImgs)); box.style.display = 'block'; return; }
      box.style.display = '';
      items.forEach(function(it){
        var b = el('button'); b.type = 'button'; b.title = it.name;
        var im = el('img'); im.src = it.url; im.loading = 'lazy'; im.alt = '';
        b.appendChild(im);
        b.addEventListener('click', function(){ addImage(it.src, it.w, it.h); });
        box.appendChild(b);
      });
    });
  }
  dropZone($('cvImgDrop'), $('cvFileImg'), function(f){
    uploadImage(f, function(src){
      var pr = new Image();
      pr.onload = function(){ addImage(src, pr.naturalWidth, pr.naturalHeight); loadDocsImages(); };
      pr.src = D.base + src;
    });
  });

  function chooseFromDocs(onPick){
    var ov = el('div'); ov.style.cssText = 'position:fixed;inset:0;z-index:9000;background:rgba(15,23,42,.55);display:flex;align-items:center;justify-content:center;padding:1rem';
    var box = el('div'); box.style.cssText = 'background:var(--card);border-radius:16px;width:min(760px,100%);max-height:84vh;display:flex;flex-direction:column;overflow:hidden';
    var hd = el('div'); hd.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:.9rem 1.1rem;border-bottom:1px solid var(--border);font-weight:700';
    hd.appendChild(el('span', null, $('cvBgFromDocs').textContent.trim()));
    var x = el('button'); x.type = 'button'; x.className = 'modal-close'; x.textContent = '×'; x.style.cssText = 'border:0;background:none;font-size:1.5rem;cursor:pointer;color:var(--text-muted)';
    hd.appendChild(x);
    var grid = el('div'); grid.style.cssText = 'display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:.6rem;padding:1rem;overflow:auto';
    grid.appendChild(el('div', 'cv-empty', '…'));
    box.appendChild(hd); box.appendChild(grid); ov.appendChild(box); document.body.appendChild(ov);
    var close = function(){ ov.remove(); };
    x.addEventListener('click', close);
    ov.addEventListener('click', function(e){ if(e.target === ov) close(); });
    fetchDocsImages().then(function(items){
      grid.textContent = '';
      if(!items.length){ grid.appendChild(el('div', 'cv-empty', L.noImgs)); return; }
      items.forEach(function(it){
        var b = el('button'); b.type = 'button';
        b.style.cssText = 'border:1.5px solid var(--border);border-radius:10px;padding:0;overflow:hidden;cursor:pointer;background:var(--bg);aspect-ratio:16/10';
        var im = el('img'); im.src = it.url; im.alt = ''; im.loading = 'lazy'; im.style.cssText = 'width:100%;height:100%;object-fit:cover;display:block';
        b.appendChild(im); b.title = it.name;
        b.addEventListener('click', function(){ close(); onPick(it); });
        grid.appendChild(b);
      });
    });
  }

  /* ============================================================
   * เพิ่มชั้น
   * ============================================================ */
  function baseLayer(type){
    return { id: uid(), type: type, x: 0, y: 0, w: 300, h: 100, rot: 0, op: 1, hidden: false, locked: false,
             shadow: false, shColor: '#00000059', shBlur: Math.round(20 * S.w / 1280) };
  }
  function addLayer(l){
    S.layers.push(l);
    if(l.type === 'text') fitText(l);
    select(l.id); buildLayers(); render(); commit();
  }
  var k0 = function(){ return Math.min(S.w, S.h * 1.6) / 1280; };

  document.querySelectorAll('[data-add-text]').forEach(function(b){
    b.addEventListener('click', function(){
      var t = b.getAttribute('data-add-text'), k = k0();
      var l = Object.assign(baseLayer('text'), { text:'', font:'Kanit', size:60, weight:700, italic:false, upper:false, color:'#ffffff',
        align:'left', lh:1.2, ls:0, stroke:0, strokeColor:'#000000', box:false, boxColor:'#000000', boxOp:.6, boxPad:18, boxRadius:14, fitW:false });
      if(t === 'title'){ Object.assign(l, { text:L.txtTitle, size:Math.round(96 * k), w:S.w * 0.8 }); }
      else if(t === 'subtitle'){ Object.assign(l, { text:L.txtSubtitle, font:'Prompt', size:Math.round(46 * k), weight:500, w:S.w * 0.7 }); }
      else if(t === 'body'){ Object.assign(l, { text:L.txtBody, font:'Sarabun', size:Math.round(32 * k), weight:400, w:S.w * 0.6, lh:1.45 }); }
      else if(t === 'badge'){ Object.assign(l, { text:L.txtBadge, size:Math.round(32 * k), weight:700, color:'#ffffff', box:true, boxColor:'#f59e0b', boxOp:1,
                                                 boxPad:Math.round(18 * k), boxRadius:999, fitW:true, align:'center' }); }
      else if(t === 'code'){ Object.assign(l, { text:L.txtCode, font:'Chakra Petch', size:Math.round(34 * k), weight:600, ls:Math.round(4 * k),
                                                box:true, boxColor:'#ffffff', boxOp:.18, boxPad:Math.round(14 * k), boxRadius:Math.round(10 * k), fitW:true }); }
      fitText(l);
      l.x = (S.w - l.w) / 2; l.y = (S.h - l.h) / 2;
      addLayer(l);
    });
  });

  function addImage(src, iw, ih){
    var l = Object.assign(baseLayer('image'), { src: src, fit:'cover', radius:0, border:0, borderColor:'#ffffff', flip:false, bright:100, gray:false });
    iw = iw || 800; ih = ih || 600;
    var s = Math.min(S.w * 0.5 / iw, S.h * 0.6 / ih);
    l.w = Math.round(iw * s); l.h = Math.round(ih * s);
    l.x = (S.w - l.w) / 2; l.y = (S.h - l.h) / 2;
    addLayer(l);
  }

  document.querySelectorAll('[data-add-shape]').forEach(function(b){
    b.addEventListener('click', function(){
      var t = b.getAttribute('data-add-shape'), m = Math.min(S.w, S.h);
      var l = Object.assign(baseLayer('shape'), { shape:'rect', fill:'#ffffff', fill2:'', noFill:false, stroke:0, strokeColor:'#ffffff', radius:0, op:.9 });
      l.w = m * 0.35; l.h = m * 0.35;
      if(t === 'rrect'){ l.radius = Math.round(m * 0.04); }
      else if(t === 'pill'){ l.shape = 'pill'; l.w = m * 0.5; l.h = m * 0.13; }
      else if(t === 'circle' || t === 'triangle' || t === 'star'){ l.shape = t; }
      else if(t === 'line'){ l.shape = 'line'; l.w = m * 0.5; l.h = Math.max(4, m * 0.012); }
      else if(t === 'frame'){ l.noFill = true; l.stroke = Math.round(m * 0.012); l.radius = Math.round(m * 0.03); l.w = m * 0.6; l.h = m * 0.4; }
      else if(t === 'ring'){ l.shape = 'circle'; l.noFill = true; l.stroke = Math.round(m * 0.014); }
      l.x = (S.w - l.w) / 2; l.y = (S.h - l.h) / 2;
      addLayer(l);
    });
  });

  /* ============================================================
   * แผงขวา: คุณสมบัติของชั้นที่เลือก
   * ============================================================ */
  function select(id){
    selId = id;
    placeSel(); buildProps(); buildLayers();
  }

  var propRefs = {};
  function row(parent, label){ var r = el('div', 'cv-row'); if(label) r.appendChild(el('label', null, label)); parent.appendChild(r); return r; }
  function rangeRow(parent, label, l, key, min, max, step, fmt){
    var r = row(parent, label);
    var inp = el('input'); inp.type = 'range'; inp.min = min; inp.max = max; inp.step = step || 1; inp.value = l[key];
    var v = el('span', 'v', fmt ? fmt(l[key]) : l[key]);
    inp.addEventListener('input', function(){ l[key] = parseFloat(inp.value); v.textContent = fmt ? fmt(l[key]) : l[key]; render(); });
    inp.addEventListener('change', commit);
    r.appendChild(inp); r.appendChild(v);
    propRefs[key] = function(){ inp.value = l[key]; v.textContent = fmt ? fmt(l[key]) : l[key]; };
    return r;
  }
  function colorRow(parent, label, l, key, swatches){
    var r = row(parent, label);
    var inp = el('input', 'cv-col'); inp.type = 'color'; inp.value = hex7(l[key]);
    var keepA = (l[key] || '').length === 9 ? l[key].slice(7) : '';
    inp.addEventListener('input', function(){ l[key] = inp.value + keepA; render(); });
    inp.addEventListener('change', commit);
    r.appendChild(inp);
    if(swatches){
      var sw = el('div', 'cv-sw'); sw.style.margin = '0';
      SWATCH.slice(0, 7).forEach(function(c){
        var b = el('button'); b.type = 'button'; b.style.background = c; b.title = c;
        b.addEventListener('click', function(){ l[key] = c + keepA; inp.value = c; render(); commit(); });
        sw.appendChild(b);
      });
      r.appendChild(sw);
    }
    return r;
  }
  function check(parent, label, l, key, after){
    var lb = el('label', 'cv-chk');
    var inp = el('input'); inp.type = 'checkbox'; inp.checked = !!l[key];
    inp.addEventListener('change', function(){ l[key] = inp.checked; render(); commit(); if(after) after(); });
    lb.appendChild(inp); lb.appendChild(document.createTextNode(' ' + label));
    parent.appendChild(lb);
    return lb;
  }
  function seg(parent, label, l, key, opts){
    var r = row(parent, label);
    var s = el('div', 'cv-seg');
    opts.forEach(function(o){
      var b = el('button'); b.type = 'button';
      if(o.icon){ var i = el('i', 'fi ' + o.icon + (o.flip ? ' ic-flip' : '')); b.appendChild(i); } else b.textContent = o.t;
      b.classList.toggle('on', l[key] === o.v);
      b.addEventListener('click', function(){ l[key] = o.v; s.querySelectorAll('button').forEach(function(x){ x.classList.toggle('on', x === b); }); render(); commit(); });
      s.appendChild(b);
    });
    r.appendChild(s);
    return r;
  }

  function buildProps(){
    var box = $('cvProps'); box.textContent = ''; propRefs = {};
    var l = selId ? layerById(selId) : null;
    if(!l){
      box.appendChild(el('div', 'cv-h', L.alignCanvas));
      box.appendChild(el('div', 'cv-empty', L.bgProps));
      return;
    }
    var head = el('div', 'cv-h', l.type === 'text' ? L.text : (l.type === 'image' ? L.image : L.shape));
    box.appendChild(head);

    // เครื่องมือด่วน: จัดตำแหน่งบนภาพ / ลำดับชั้น / ทำซ้ำ / ลบ
    var tools = el('div', 'cv-mini');
    [
      ['fi-rr-align-left', 'L', function(){ l.x = 0; }],
      ['fi-rr-align-center', 'C', function(){ l.x = (S.w - l.w) / 2; }],
      ['fi-rr-align-left ic-flip', 'R', function(){ l.x = S.w - l.w; }],
      ['fi-rr-arrow-small-up', 'T', function(){ l.y = 0; }],
      ['fi-rr-arrows', 'M', function(){ l.y = (S.h - l.h) / 2; }],
      ['fi-rr-arrow-small-down', 'B', function(){ l.y = S.h - l.h; }]
    ].forEach(function(t){
      var b = el('button'); b.type = 'button'; b.title = L.alignCanvas;
      b.appendChild(el('i', 'fi ' + t[0]));
      b.addEventListener('click', function(){ if(l.locked) return; t[2](); render(); commit(); });
      tools.appendChild(b);
    });
    box.appendChild(tools);
    var tools2 = el('div', 'cv-mini');
    [
      ['fi-rr-bring-front', L.front, function(){ moveLayer(l, 1); }],
      ['fi-rr-send-back', L.back, function(){ moveLayer(l, -1); }],
      ['fi-rr-copy-alt', L.dup, function(){ duplicate(l); }],
      [l.locked ? 'fi-rr-lock' : 'fi-rr-unlock', L.lock, function(){ l.locked = !l.locked; placeSel(); buildProps(); buildLayers(); commit(); }],
      [l.hidden ? 'fi-rr-eye-crossed' : 'fi-rr-eye', L.hide, function(){ l.hidden = !l.hidden; render(); buildProps(); buildLayers(); commit(); }],
      ['fi-rr-trash', L.del, function(){ removeLayer(l); }]
    ].forEach(function(t){
      var b = el('button'); b.type = 'button'; b.title = t[1];
      b.appendChild(el('i', 'fi ' + t[0]));
      b.addEventListener('click', t[2]);
      tools2.appendChild(b);
    });
    box.appendChild(tools2);

    if(l.type === 'text'){
      var ta = el('textarea', 'cv-ta'); ta.value = l.text; ta.placeholder = L.content;
      ta.addEventListener('input', function(){ l.text = ta.value; render(); buildLayers(); });
      ta.addEventListener('change', commit);
      box.appendChild(ta);

      var fr = row(box, L.font);
      var fs = el('select');
      D.fonts.forEach(function(f){ var o = el('option', null, f); o.value = f; o.style.fontFamily = '"' + f + '"'; if(f === l.font) o.selected = true; fs.appendChild(o); });
      fs.style.fontFamily = '"' + l.font + '"';
      fs.addEventListener('change', function(){ l.font = fs.value; fs.style.fontFamily = '"' + l.font + '"'; ensureFont(l); render(); commit(); });
      fr.appendChild(fs);

      var sr = row(box, L.size);
      var sn = el('input'); sn.type = 'number'; sn.min = 6; sn.max = 800; sn.value = l.size;
      sn.addEventListener('input', function(){ l.size = Math.max(6, Math.min(800, +sn.value || l.size)); render(); });
      sn.addEventListener('change', commit);
      var wsel = el('select');
      [[300,'Light'],[400,'Regular'],[500,'Medium'],[600,'SemiBold'],[700,'Bold'],[800,'ExtraBold']].forEach(function(wt){
        var o = el('option', null, wt[1]); o.value = wt[0]; if(+wt[0] === +l.weight) o.selected = true; wsel.appendChild(o);
      });
      wsel.addEventListener('change', function(){ l.weight = +wsel.value; ensureFont(l); render(); commit(); });
      sr.appendChild(sn); sr.appendChild(wsel);
      propRefs.size = function(){ sn.value = l.size; };

      seg(box, L.align, l, 'align', [{ v:'left', icon:'fi-rr-align-left' }, { v:'center', icon:'fi-rr-align-center' }, { v:'right', icon:'fi-rr-align-left', flip:true }]);
      var st = row(box, '');
      st.firstChild && st.removeChild(st.firstChild);
      var bi = el('div', 'cv-seg');
      [['italic', 'fi-rr-italic', L.italic], ['upper', 'fi-rr-text', L.upper]].forEach(function(o){
        var b = el('button'); b.type = 'button'; b.title = o[2]; b.appendChild(el('i', 'fi ' + o[1])); b.classList.toggle('on', !!l[o[0]]);
        b.addEventListener('click', function(){ l[o[0]] = !l[o[0]]; b.classList.toggle('on', !!l[o[0]]); ensureFont(l); render(); commit(); });
        bi.appendChild(b);
      });
      st.appendChild(bi);

      colorRow(box, L.color, l, 'color', true);
      rangeRow(box, L.lh, l, 'lh', 0.6, 3, 0.05, function(v){ return (+v).toFixed(2); });
      rangeRow(box, L.ls, l, 'ls', -10, 60, 1);

      var sub = el('div', 'cv-sub'); box.appendChild(sub);
      var boxWrap = el('div');
      check(sub, L.box, l, 'box', function(){ boxWrap.style.display = l.box ? '' : 'none'; });
      sub.appendChild(boxWrap); boxWrap.style.display = l.box ? '' : 'none';
      colorRow(boxWrap, L.color, l, 'boxColor', true);
      rangeRow(boxWrap, L.opacity, l, 'boxOp', 0, 1, 0.01, pct);
      rangeRow(boxWrap, L.boxPad, l, 'boxPad', 0, 200, 1);
      rangeRow(boxWrap, L.radius, l, 'boxRadius', 0, 400, 1);
      check(sub, L.fitW || 'fit', l, 'fitW').style.display = 'none';

      var sub2 = el('div', 'cv-sub'); box.appendChild(sub2);
      rangeRow(sub2, L.stroke, l, 'stroke', 0, 40, 1);
      colorRow(sub2, '', l, 'strokeColor', false);
    }
    else if(l.type === 'image'){
      var rb = el('button', 'btn btn-outline btn-sm'); rb.type = 'button'; rb.style.width = '100%'; rb.style.marginBottom = '.7rem';
      rb.appendChild(el('i', 'fi fi-rr-refresh')); rb.appendChild(document.createTextNode(' ' + L.replace));
      rb.addEventListener('click', function(){ chooseFromDocs(function(it){ l.src = it.src; render(); commit(); }); });
      box.appendChild(rb);
      seg(box, L.fit, l, 'fit', [{ v:'cover', t:L.fitCover }, { v:'contain', t:L.fitContain }]);
      rangeRow(box, L.radius, l, 'radius', 0, Math.round(Math.max(l.w, l.h) / 2), 1);
      rangeRow(box, L.brightness, l, 'bright', 20, 200, 1, function(v){ return v + '%'; });
      check(box, L.gray, l, 'gray');
      check(box, L.flip, l, 'flip');
      var sb = el('div', 'cv-sub'); box.appendChild(sb);
      rangeRow(sb, L.border, l, 'border', 0, 80, 1);
      colorRow(sb, '', l, 'borderColor', true);
    }
    else{
      var fillWrap = el('div');
      check(box, L.noFill, l, 'noFill', function(){ fillWrap.style.display = l.noFill ? 'none' : ''; });
      box.appendChild(fillWrap); fillWrap.style.display = l.noFill ? 'none' : '';
      colorRow(fillWrap, L.fill, l, 'fill', true);
      var g2 = row(fillWrap, L.fill2);
      var gc = el('input'); gc.type = 'checkbox'; gc.checked = !!l.fill2; gc.style.accentColor = 'var(--primary)';
      var gcol = el('input', 'cv-col'); gcol.type = 'color'; gcol.value = hex7(l.fill2 || '#0ea5e9'); gcol.disabled = !l.fill2;
      gc.addEventListener('change', function(){ l.fill2 = gc.checked ? gcol.value : ''; gcol.disabled = !gc.checked; render(); commit(); });
      gcol.addEventListener('input', function(){ if(gc.checked){ l.fill2 = gcol.value; render(); } });
      gcol.addEventListener('change', commit);
      g2.appendChild(gc); g2.appendChild(gcol);
      if(l.shape === 'rect') rangeRow(box, L.radius, l, 'radius', 0, Math.round(Math.min(l.w, l.h) / 2), 1);
      var ss = el('div', 'cv-sub'); box.appendChild(ss);
      rangeRow(ss, L.stroke, l, 'stroke', 0, 80, 1);
      colorRow(ss, '', l, 'strokeColor', true);
    }

    var common = el('div', 'cv-sub'); box.appendChild(common);
    rangeRow(common, L.opacity, l, 'op', 0, 1, 0.01, pct);
    rangeRow(common, L.rotate, l, 'rot', -180, 180, 1, function(v){ return Math.round(v) + '°'; });
    var shWrap = el('div');
    check(common, L.shadow, l, 'shadow', function(){ shWrap.style.display = l.shadow ? '' : 'none'; });
    common.appendChild(shWrap); shWrap.style.display = l.shadow ? '' : 'none';
    colorRow(shWrap, L.color, l, 'shColor', false);
    rangeRow(shWrap, 'Blur', l, 'shBlur', 0, 120, 1);
  }
  function pct(v){ return Math.round(v * 100) + '%'; }
  function syncProps(){ Object.keys(propRefs).forEach(function(k){ propRefs[k](); }); }

  function moveLayer(l, dir){
    var i = S.layers.indexOf(l), j = i + dir;
    if(j < 0 || j >= S.layers.length) return;
    S.layers.splice(i, 1); S.layers.splice(j, 0, l);
    render(); buildLayers(); commit();
  }
  function duplicate(l){
    var n = clone(l); n.id = uid(); n.x += 24; n.y += 24; n.locked = false;
    S.layers.splice(S.layers.indexOf(l) + 1, 0, n);
    select(n.id); render(); commit();
  }
  function removeLayer(l){
    S.layers.splice(S.layers.indexOf(l), 1);
    select(null); render(); commit();
  }

  /* ---------- รายการชั้น ---------- */
  function buildLayers(){
    var ul = $('cvLayers'); ul.textContent = '';
    $('cvLayerCount').textContent = S.layers.length ? S.layers.length : '';
    S.layers.slice().reverse().forEach(function(l){
      var li = el('li'); if(l.id === selId) li.classList.add('on');
      li.appendChild(el('i', 'fi ' + (l.type === 'text' ? 'fi-rr-text' : (l.type === 'image' ? 'fi-rr-picture' : 'fi-rr-triangle'))));
      var nm = l.type === 'text' ? (l.text || '').split('\n')[0] || L.text : (l.type === 'image' ? L.image : L.shape);
      var sp = el('span', 'nm', nm); if(l.hidden) sp.style.opacity = '.45'; if(l.type === 'text') sp.style.fontFamily = '"' + l.font + '"';
      li.appendChild(sp);
      var eye = el('button'); eye.type = 'button'; eye.title = L.hide; eye.appendChild(el('i', 'fi ' + (l.hidden ? 'fi-rr-eye-crossed' : 'fi-rr-eye')));
      eye.addEventListener('click', function(e){ e.stopPropagation(); l.hidden = !l.hidden; render(); buildLayers(); if(l.id === selId) buildProps(); commit(); });
      var lk = el('button'); lk.type = 'button'; lk.title = L.lock; lk.appendChild(el('i', 'fi ' + (l.locked ? 'fi-rr-lock' : 'fi-rr-unlock')));
      lk.addEventListener('click', function(e){ e.stopPropagation(); l.locked = !l.locked; placeSel(); buildLayers(); if(l.id === selId) buildProps(); commit(); });
      li.appendChild(eye); li.appendChild(lk);
      li.addEventListener('click', function(){ select(l.id); });
      ul.appendChild(li);
    });
  }

  /* ---------- คีย์ลัด ---------- */
  document.addEventListener('keydown', function(e){
    var tag = (e.target.tagName || '').toLowerCase();
    var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable;
    var mod = e.ctrlKey || e.metaKey;
    if(mod && (e.key === 's' || e.key === 'S')){ e.preventDefault(); clearTimeout(saveT); save(true); return; }
    if(typing) return;
    if(mod && (e.key === 'z' || e.key === 'Z')){ e.preventDefault(); if(e.shiftKey){ if(hi < hist.length - 1) restore(hi + 1); } else if(hi > 0) restore(hi - 1); return; }
    if(mod && (e.key === 'y' || e.key === 'Y')){ e.preventDefault(); if(hi < hist.length - 1) restore(hi + 1); return; }
    var l = selId ? layerById(selId) : null;
    if(!l) return;
    if(e.key === 'Escape'){ select(null); return; }
    if(mod && (e.key === 'd' || e.key === 'D')){ e.preventDefault(); duplicate(l); return; }
    if(e.key === 'Delete' || e.key === 'Backspace'){ e.preventDefault(); removeLayer(l); return; }
    if(l.locked) return;
    var step = e.shiftKey ? 10 : 1, moved = true;
    if(e.key === 'ArrowLeft') l.x -= step; else if(e.key === 'ArrowRight') l.x += step;
    else if(e.key === 'ArrowUp') l.y -= step; else if(e.key === 'ArrowDown') l.y += step;
    else moved = false;
    if(moved){ e.preventDefault(); render(); clearTimeout(nudgeT); nudgeT = setTimeout(commit, 400); }
  });
  var nudgeT = null;

  /* ---------- เริ่มต้น ---------- */
  S.layers.forEach(function(l){ if(l.type === 'text') ensureFont(l); });
  buildPresets(); buildBgs(); syncBgUi(); buildLayers(); buildProps();
  layout();
  if(document.fonts && document.fonts.ready) document.fonts.ready.then(function(){ render(); buildBgs(); });
  if(!docKey && D.quotaFull) setState('error', L.full);
})();
