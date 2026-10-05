/* ============================================================
 * Aleanor Docs — กล่อง dialog กลาง (แทน alert/confirm/prompt ของเบราว์เซอร์)
 * ยกมาจาก aleanor_ai/dashboard/index.php + แนบ X-CSRF-Token ให้ทุก fetch ไปที่ api/docs.php
 * ต้องตั้ง window.DOCS = {api:'...', csrf:'...', L:{cancel,del,confirm}} ก่อนโหลดไฟล์นี้
 * ============================================================ */
(function(){
  var CFG = window.DOCS || {};
  var L = CFG.L || {cancel:'ยกเลิก', del:'ลบ', confirm:'ตกลง'};

  // แนบ CSRF header ให้คำขอที่ไป api/docs.php (ตัวแก้ไขที่พอร์ตมายังแนบ csrf_token ในฟอร์มด้วย — ฝั่งเซิร์ฟเวอร์รับได้ทั้งคู่)
  if(CFG.api && window.fetch){
    var nativeFetch = window.fetch;
    window.fetch = function(url, opt){
      try{
        if(typeof url === 'string' && url.indexOf(CFG.api) === 0 && opt && String(opt.method || 'GET').toUpperCase() === 'POST'){
          opt.headers = opt.headers || {};
          if(opt.headers instanceof Headers) opt.headers.set('X-CSRF-Token', CFG.csrf);
          else opt.headers['X-CSRF-Token'] = CFG.csrf;
          opt.credentials = opt.credentials || 'same-origin';
        }
      }catch(e){}
      return nativeFetch.call(window, url, opt);
    };
  }

  var ov, icEl, msgEl, actEl;
  function ensure(){ if(ov) return;
    ov = document.createElement('div'); ov.className = 'dlg-ov';
    var box = document.createElement('div'); box.className = 'dlg-box';
    icEl = document.createElement('div'); icEl.className = 'dlg-ic'; box.appendChild(icEl);
    msgEl = document.createElement('div'); msgEl.className = 'dlg-msg'; box.appendChild(msgEl);
    actEl = document.createElement('div'); actEl.className = 'dlg-act'; box.appendChild(actEl);
    ov.appendChild(box); document.body.appendChild(ov);
    ov.addEventListener('click', function(e){ if(e.target === ov) close(); });
    document.addEventListener('keydown', function(e){ if(e.key === 'Escape' && ov.classList.contains('on')) close(); });
  }
  function close(){ if(ov) ov.classList.remove('on'); var i = ov && ov.querySelector('.dlg-input'); if(i) i.parentNode.removeChild(i); }
  function setIcon(name, kind){ icEl.className = 'dlg-ic ' + (kind || 'info'); icEl.textContent = ''; var i = document.createElement('i'); i.className = 'fi ' + name; icEl.appendChild(i); }
  function mkbtn(label, cls){ var b = document.createElement('button'); b.type = 'button'; b.className = 'btn ' + cls; b.textContent = label; return b; }

  window.dlgConfirm = function(msg, onYes, opts){ opts = opts || {}; ensure(); close();
    var del = /ลบ|delete|remove|ล้าง/i.test(msg);
    setIcon(opts.icon || (del ? 'fi-rr-trash' : 'fi-rr-exclamation'), opts.kind || (del ? 'danger' : 'warn'));
    msgEl.textContent = msg; actEl.textContent = '';
    var cancel = mkbtn(L.cancel, 'btn-outline'); cancel.addEventListener('click', close);
    var ok = mkbtn(opts.okLabel || (del ? L.del : L.confirm), opts.okClass || (del ? 'btn-danger-solid' : 'btn-primary'));
    ok.addEventListener('click', function(){ close(); if(typeof onYes === 'function') onYes(); });
    actEl.appendChild(cancel); actEl.appendChild(ok); ov.classList.add('on'); setTimeout(function(){ ok.focus(); }, 30);
  };
  window.dlgAlert = function(msg, onOk){ ensure(); close(); setIcon('fi-rr-info', 'info'); msgEl.textContent = msg; actEl.textContent = '';
    var ok = mkbtn(L.confirm, 'btn-primary'); ok.addEventListener('click', function(){ close(); if(typeof onOk === 'function') onOk(); });
    actEl.appendChild(ok); ov.classList.add('on'); setTimeout(function(){ ok.focus(); }, 30);
  };
  window.dlgPrompt = function(msg, def, onOk, opts){ opts = opts || {}; ensure(); close();
    setIcon(opts.icon || 'fi-rr-pencil', 'info'); msgEl.textContent = msg; actEl.textContent = '';
    var inp = document.createElement('input');
    inp.type = opts.type || 'text'; inp.value = (def === undefined || def === null) ? '' : String(def);
    inp.className = 'dlg-input';
    msgEl.parentNode.insertBefore(inp, msgEl.nextSibling);
    function done(val){ close(); if(typeof onOk === 'function') onOk(val); }
    var cancel = mkbtn(L.cancel, 'btn-outline'); cancel.addEventListener('click', close);
    var ok = mkbtn(opts.okLabel || L.confirm, 'btn-primary'); ok.addEventListener('click', function(){ done(inp.value); });
    inp.addEventListener('keydown', function(e){ if(e.key === 'Enter'){ e.preventDefault(); done(inp.value); } });
    actEl.appendChild(cancel); actEl.appendChild(ok); ov.classList.add('on');
    setTimeout(function(){ inp.focus(); inp.select(); }, 30);
  };
  // แทน alert() ของเบราว์เซอร์ → modal
  window.alert = function(msg){ window.dlgAlert(String(msg)); };
})();
