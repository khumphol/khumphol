/* ============================================================
 * ตัวเลือกภาพปก — ใส่ให้ช่องอัปโหลดรูปที่มี data-cover="course|path|slide|ads|splash|cert|square"
 *   [สร้างด้วย Aleanor Cover] [เลือกจาก Aleanor Docs] + อัปโหลดเองได้เหมือนเดิม
 * ภาพที่เลือกจะถูกใส่ลงช่อง <input type=file> จริง (DataTransfer) ฟอร์มเดิมจึงบันทึกได้โดยไม่ต้องแก้ฝั่งเซิร์ฟเวอร์
 *   data-cover-title="[name=cl_name]"  ช่องชื่อเรื่องในฟอร์มเดียวกัน (ใส่เป็นข้อความตั้งต้นของภาพปก)
 * ============================================================ */
(function(){
  'use strict';
  var C = window.CP_CFG;
  if(!C || typeof DataTransfer === 'undefined') return;
  var L = C.L || {};

  function el(tag, cls, txt){ var e = document.createElement(tag); if(cls) e.className = cls; if(txt != null) e.textContent = txt; return e; }
  function icon(name){ return el('i', 'fi ' + name); }

  var css = el('style');
  css.textContent =
    '.cp-bar{display:flex;flex-wrap:wrap;gap:6px;margin:8px 0 2px;align-items:center}' +
    '.cp-btn{display:inline-flex;align-items:center;gap:6px;height:34px;padding:0 12px;border-radius:10px;cursor:pointer;font:inherit;font-size:.8rem;font-weight:600;' +
    'border:1.5px solid var(--border);background:var(--card);color:var(--text-secondary);transition:.15s}' +
    '.cp-btn:hover{border-color:var(--primary);color:var(--primary)}' +
    '.cp-btn.cp-make{border-color:color-mix(in srgb,#db2777 45%,var(--border));color:#db2777;background:color-mix(in srgb,#db2777 6%,var(--card))}' +
    '.cp-btn.cp-make:hover{background:#db2777;color:#fff;border-color:#db2777}' +
    '.cp-thumb{height:34px;border-radius:8px;border:1.5px solid var(--border);object-fit:cover;aspect-ratio:16/10;display:none}' +
    '.cp-note{font-size:.74rem;color:#059669;display:none;align-items:center;gap:4px}' +
    '.cp-ov{position:fixed;inset:0;z-index:100000;background:rgba(15,23,42,.6);backdrop-filter:blur(3px);display:flex;align-items:center;justify-content:center;padding:2vh 2vw}' +
    '.cp-box{background:var(--card);border-radius:18px;width:min(900px,100%);max-height:92vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 30px 80px rgba(0,0,0,.35)}' +
    '.cp-box.big{width:min(1500px,100%);height:94vh;max-height:none}' +
    '.cp-hd{display:flex;align-items:center;gap:.7rem;padding:.8rem 1rem;border-bottom:1px solid var(--border)}' +
    '.cp-hd b{flex:1;font-size:.98rem}' +
    '.cp-x{width:36px;height:36px;border-radius:10px;border:0;background:var(--bg);color:var(--text-secondary);cursor:pointer;font-size:1.3rem;line-height:1}' +
    '.cp-x:hover{color:#dc2626}' +
    '.cp-tools{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:.7rem 1rem;border-bottom:1px solid var(--border)}' +
    '.cp-tabs{display:inline-flex;background:var(--bg);border:1.5px solid var(--border);border-radius:11px;padding:3px}' +
    '.cp-tabs button{border:0;background:transparent;padding:6px 12px;border-radius:8px;cursor:pointer;font:inherit;font-size:.8rem;color:var(--text-secondary)}' +
    '.cp-tabs button.on{background:var(--card);color:var(--primary);font-weight:600;box-shadow:0 1px 3px rgba(0,0,0,.08)}' +
    '.cp-q{flex:1;min-width:160px;height:36px;border-radius:10px;border:1.5px solid var(--border);padding:0 12px;font:inherit;font-size:.85rem;background:var(--card);color:var(--text)}' +
    '.cp-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:.8rem;padding:1rem;overflow:auto}' +
    '.cp-it{border:1.5px solid var(--border);border-radius:12px;overflow:hidden;cursor:pointer;background:var(--card);text-align:left;padding:0;font:inherit;color:var(--text);position:relative}' +
    '.cp-it:hover{border-color:var(--primary);box-shadow:0 8px 20px rgba(0,0,0,.08);transform:translateY(-1px)}' +
    '.cp-it img{width:100%;aspect-ratio:16/10;object-fit:cover;display:block;background:var(--bg)}' +
    '.cp-it span{display:block;padding:.45rem .6rem;font-size:.76rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' +
    '.cp-it small{display:block;padding:0 .6rem .5rem;font-size:.68rem;color:var(--text-muted)}' +
    '.cp-match{position:absolute;top:7px;left:7px;background:#059669;color:#fff;font-size:.64rem;font-weight:700;padding:2px 8px;border-radius:20px}' +
    '.cp-empty{grid-column:1/-1;text-align:center;color:var(--text-muted);padding:2.5rem 1rem;font-size:.86rem;line-height:1.7}' +
    '.cp-frame{flex:1;border:0;width:100%;background:var(--bg)}' +
    '.cp-busy{position:absolute;inset:0;background:rgba(255,255,255,.7);display:flex;align-items:center;justify-content:center;font-size:.86rem;color:var(--primary);font-weight:600}' +
    '@media(max-width:640px){.cp-ov{padding:0}.cp-box,.cp-box.big{border-radius:0;height:100vh;max-height:100vh}}';
  document.head.appendChild(css);

  /* ---------- ใส่ภาพลงช่องไฟล์ ---------- */
  function applyUrl(inp, url, name, bar){
    return fetch(url, { credentials: 'same-origin' }).then(function(r){
      if(!r.ok) throw new Error('fetch');
      return r.blob();
    }).then(function(blob){
      var type = blob.type || 'image/jpeg';
      var ext = /png/.test(type) ? '.png' : (/webp/.test(type) ? '.webp' : (/gif/.test(type) ? '.gif' : '.jpg'));
      var base = String(name || 'cover').replace(/\.[a-z0-9]{2,5}$/i, '').replace(/[\\/:*?"<>|]+/g, ' ').trim().slice(0, 80) || 'cover';
      var file = new File([blob], base + ext, { type: type, lastModified: Date.now() });
      var dt = new DataTransfer();
      if(inp.multiple && inp.files){ for(var i = 0; i < inp.files.length; i++) dt.items.add(inp.files[i]); }
      dt.items.add(file);
      inp.files = dt.files;
      inp.dispatchEvent(new Event('input', { bubbles: true }));
      inp.dispatchEvent(new Event('change', { bubbles: true }));
      if(bar){
        var th = bar.querySelector('.cp-thumb'); th.src = URL.createObjectURL(blob); th.style.display = 'block';
        var nt = bar.querySelector('.cp-note'); nt.style.display = 'inline-flex';
      }
    });
  }

  /* ---------- หน้าต่างซ้อน ---------- */
  function modal(title, big){
    var ov = el('div', 'cp-ov'), box = el('div', 'cp-box' + (big ? ' big' : ''));
    var hd = el('div', 'cp-hd');
    var ic = el('img'); ic.src = C.icon || (C.base + 'media/docs/cover.png'); ic.style.cssText = 'width:26px;height:26px;object-fit:contain';
    ic.onerror = function(){ ic.replaceWith(icon('fi-rr-picture')); };
    hd.appendChild(ic); hd.appendChild(el('b', null, title));
    var x = el('button', 'cp-x', '×'); x.type = 'button'; x.setAttribute('aria-label', 'close');
    hd.appendChild(x);
    box.appendChild(hd); ov.appendChild(box); document.body.appendChild(ov);
    var prevOverflow = document.body.style.overflow; document.body.style.overflow = 'hidden';
    var onKey = function(e){ if(e.key === 'Escape') close(); };
    function close(){ ov.remove(); document.body.style.overflow = prevOverflow; document.removeEventListener('keydown', onKey); if(ov._onClose) ov._onClose(); }
    x.addEventListener('click', close);
    ov.addEventListener('click', function(e){ if(e.target === ov) close(); });
    document.addEventListener('keydown', onKey);
    return { ov: ov, box: box, close: close };
  }

  function busy(box, on){
    var b = box.querySelector('.cp-busy');
    if(on && !b){ b = el('div', 'cp-busy', L.loading); box.style.position = 'relative'; box.appendChild(b); }
    if(!on && b) b.remove();
  }

  /* data-cover-orient="[name=ct_orientation]" — ฟอร์มเลือกแนวตั้งอยู่ → ใช้ขนาดแนวตั้ง (preset + 'p') */
  function livePreset(inp, preset){
    var sel = inp.getAttribute('data-cover-orient');
    if(!sel) return preset;
    var scope = inp.form || document;
    var o = scope.querySelector(sel + ':checked') || scope.querySelector(sel);
    return (o && o.value === 'portrait' && C.presets[preset + 'p']) ? preset + 'p' : preset;
  }

  /* ---------- สร้างใหม่ด้วย Aleanor Cover ---------- */
  function openCreate(inp, preset, bar){
    preset = livePreset(inp, preset);
    var title = '';
    var sel = inp.getAttribute('data-cover-title');
    if(sel){
      var scope = inp.form || document;
      var t = scope.querySelector(sel) || document.querySelector(sel);
      if(t) title = (t.value || '').trim();
    }
    var m = modal(L.create, true);
    var src = C.editUrl + C.sep + 'preset=' + encodeURIComponent(preset) + '&embed=1&pick=1' + (title ? '&title=' + encodeURIComponent(title) : '');
    var fr = el('iframe', 'cp-frame'); fr.src = src; fr.setAttribute('title', 'Aleanor Cover');
    m.box.appendChild(fr);
    function onMsg(e){
      if(e.origin !== location.origin || !e.data || e.data.t !== 'aleanor-cover-use' || e.source !== fr.contentWindow) return;
      busy(m.box, true);
      applyUrl(inp, e.data.url, e.data.name, bar).then(function(){ m.close(); toast(L.applied); })
        .catch(function(){ busy(m.box, false); toast(L.failed, true); });
    }
    window.addEventListener('message', onMsg);
    m.ov._onClose = function(){ window.removeEventListener('message', onMsg); };
  }

  /* ---------- เลือกจาก Aleanor Docs ---------- */
  function openDocs(inp, preset, bar){
    var base = preset;
    preset = livePreset(inp, preset);
    var want = C.presets[preset] || null;
    var m = modal(L.title, false);
    var tools = el('div', 'cp-tools');
    var tabs = el('div', 'cp-tabs');
    var tCov = el('button', 'on', L.covers), tFile = el('button', null, L.files);
    tCov.type = tFile.type = 'button';
    tabs.appendChild(tCov); tabs.appendChild(tFile);
    var q = el('input', 'cp-q'); q.type = 'search'; q.placeholder = L.search;
    var mk = el('button', 'cp-btn cp-make'); mk.type = 'button'; mk.appendChild(icon('fi-rr-magic-wand')); mk.appendChild(document.createTextNode(' ' + L.create));
    mk.addEventListener('click', function(){ m.close(); openCreate(inp, base, bar); });
    tools.appendChild(tabs); tools.appendChild(q); tools.appendChild(mk);
    var grid = el('div', 'cp-grid'); grid.appendChild(el('div', 'cp-empty', L.loading));
    m.box.appendChild(tools); m.box.appendChild(grid);

    var items = [], kind = 'cover';
    function ratioMatch(it){
      if(!want || !it.w || !it.h) return false;
      return Math.abs(it.w / it.h - want.w / want.h) < 0.04;
    }
    function draw(){
      grid.textContent = '';
      var qq = q.value.trim().toLowerCase();
      var list = items.filter(function(it){ return it.kind === kind && (!qq || (it.name || '').toLowerCase().indexOf(qq) >= 0); });
      list.sort(function(a, b){ return (ratioMatch(b) ? 1 : 0) - (ratioMatch(a) ? 1 : 0); });
      if(!list.length){
        var em = el('div', 'cp-empty', L.empty);
        if(kind === 'cover'){ em.appendChild(el('br')); var b = el('button', 'cp-btn cp-make'); b.type = 'button'; b.style.marginTop = '.8rem';
          b.appendChild(icon('fi-rr-magic-wand')); b.appendChild(document.createTextNode(' ' + L.create));
          b.addEventListener('click', function(){ m.close(); openCreate(inp, base, bar); }); em.appendChild(b); }
        grid.appendChild(em); return;
      }
      list.forEach(function(it){
        var card = el('button', 'cp-it'); card.type = 'button';
        var im = el('img'); im.src = it.url; im.alt = ''; im.loading = 'lazy';
        card.appendChild(im);
        if(ratioMatch(it)) card.appendChild(el('em', 'cp-match', L.match));
        card.appendChild(el('span', null, it.name || ''));
        card.appendChild(el('small', null, it.w && it.h ? (it.w + ' × ' + it.h) : ''));
        card.addEventListener('click', function(){
          busy(m.box, true);
          applyUrl(inp, it.url, it.name, bar).then(function(){ m.close(); toast(L.applied); })
            .catch(function(){ busy(m.box, false); toast(L.failed, true); });
        });
        grid.appendChild(card);
      });
    }
    tCov.addEventListener('click', function(){ kind = 'cover'; tCov.classList.add('on'); tFile.classList.remove('on'); draw(); });
    tFile.addEventListener('click', function(){ kind = 'file'; tFile.classList.add('on'); tCov.classList.remove('on'); draw(); });
    q.addEventListener('input', draw);

    var fd = new FormData(); fd.append('csrf_token', C.csrf); fd.append('action', 'images');
    fetch(C.coverUrl, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function(r){ return r.json(); })
      .then(function(j){
        items = (j && j.ok) ? j.items : [];
        if(!items.some(function(it){ return it.kind === 'cover'; }) && items.some(function(it){ return it.kind === 'file'; })){
          kind = 'file'; tFile.classList.add('on'); tCov.classList.remove('on');
        }
        draw();
      })
      .catch(function(){ grid.textContent = ''; grid.appendChild(el('div', 'cp-empty', L.failed)); });
  }

  /* ---------- แจ้งผลสั้น ๆ ---------- */
  function toast(msg, bad){
    var t = el('div', null, msg);
    t.style.cssText = 'position:fixed;left:50%;bottom:28px;transform:translateX(-50%);z-index:100001;padding:10px 18px;border-radius:12px;font-size:.86rem;font-weight:600;color:#fff;box-shadow:0 10px 30px rgba(0,0,0,.2);background:' + (bad ? '#dc2626' : '#059669');
    document.body.appendChild(t);
    setTimeout(function(){ t.style.transition = 'opacity .3s'; t.style.opacity = '0'; setTimeout(function(){ t.remove(); }, 320); }, 2600);
  }

  /* ---------- ติดปุ่มให้ช่องอัปโหลด ---------- */
  function enhance(root){
    (root || document).querySelectorAll('input[type=file][data-cover]').forEach(function(inp){
      if(inp._cp) return; inp._cp = true;
      var preset = inp.getAttribute('data-cover') || 'course';
      var anchor = inp.closest('.file-drop') || inp.closest('label') || inp;
      var bar = el('div', 'cp-bar');
      var mk = el('button', 'cp-btn cp-make'); mk.type = 'button';
      mk.appendChild(icon('fi-rr-magic-wand')); mk.appendChild(document.createTextNode(' ' + L.create));
      var dc = el('button', 'cp-btn'); dc.type = 'button';
      dc.appendChild(icon('fi-rr-folder-open')); dc.appendChild(document.createTextNode(' ' + L.docs));
      var th = el('img', 'cp-thumb'); th.alt = '';
      var nt = el('span', 'cp-note'); nt.appendChild(icon('fi-rr-check')); nt.appendChild(document.createTextNode(' ' + L.applied));
      bar.appendChild(mk); bar.appendChild(dc); bar.appendChild(th); bar.appendChild(nt);
      anchor.parentNode.insertBefore(bar, anchor.nextSibling);
      mk.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); openCreate(inp, preset, bar); });
      dc.addEventListener('click', function(e){ e.preventDefault(); e.stopPropagation(); openDocs(inp, preset, bar); });
      // เลือกไฟล์เองทีหลัง → ซ่อนภาพตัวอย่างของตัวเลือก
      inp.addEventListener('change', function(ev){ if(ev.isTrusted){ th.style.display = 'none'; nt.style.display = 'none'; } });
    });
  }
  window.AleanorCoverPicker = { enhance: enhance, applyUrl: applyUrl };
  function start(){ enhance(document); setTimeout(function(){ enhance(document); }, 400); }
  if(document.readyState !== 'loading') start(); else document.addEventListener('DOMContentLoaded', start);
  // ฟอร์มในหน้าต่างซ้อนที่สร้างทีหลัง
  new MutationObserver(function(ms){
    for(var i = 0; i < ms.length; i++){ if(ms[i].addedNodes.length){ enhance(document); break; } }
  }).observe(document.body, { childList: true, subtree: true });
})();
