/* ============================================================
 * Aleanor Streaming — แชทระหว่างไลฟ์ (ผู้สอน + ผู้เรียน) · ตาราง live_messages ผ่าน api/live.php
 * ใช้: var c = AleanorLive.chat(el, {api, csrf, lbl}); c.setSession(key | '')  — ว่าง = ไม่มีไลฟ์ (ปิดช่องพิมพ์)
 * ============================================================ */
(function(){
  'use strict';
  var AL = window.AleanorLive = window.AleanorLive || {};
  function el(tag, cls, txt){ var e = document.createElement(tag); if(cls) e.className = cls; if(txt != null) e.textContent = txt; return e; }
  function post(api, data){
    var fd = new FormData();
    Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
    return fetch(api, { method:'POST', body:fd, credentials:'same-origin' }).then(function(r){ return r.json(); });
  }
  if(!AL.post) AL.post = post;

  AL.chat = function(root, o){
    var L = o.lbl || {}, api = o.api, csrf = o.csrf;
    var session = '', last = 0, busy = false, timer = null, live = false;
    root.classList.add('lsc');
    root.textContent = '';
    var list = el('div', 'lsc-msgs'); root.appendChild(list);
    var empty = el('div', 'lsc-empty', L.chatEmpty || 'ยังไม่มีข้อความ'); list.appendChild(empty);
    var form = el('form', 'lsc-f');
    var inp = el('input', 'form-control'); inp.type = 'text'; inp.maxLength = 500; inp.placeholder = L.chatPh || 'พิมพ์ข้อความ…';
    var btn = el('button', 'btn btn-primary btn-sm', L.chatSend || 'ส่ง'); btn.type = 'submit';
    form.appendChild(inp); form.appendChild(btn); root.appendChild(form);
    var note = el('div', 'lsc-note'); root.appendChild(note);

    function setEnabled(on){ inp.disabled = btn.disabled = !on; }
    function add(m){
      if(m.id <= last) return;
      last = m.id;
      if(empty.parentNode) empty.remove();
      var row = el('div', 'lsc-m' + (m.me ? ' me' : '') + (m.host ? ' host' : ''));
      var who = el('div', 'lsc-who');
      if(m.host){ var b = el('b', '', L.chatHost || 'ผู้สอน'); who.appendChild(b); who.appendChild(document.createTextNode(' ')); }
      who.appendChild(document.createTextNode((m.me ? '' : (m.name || '—') + ' · ') + m.time));
      row.appendChild(who); row.appendChild(el('div', 'lsc-b', m.body));
      list.appendChild(row);
    }
    function poll(){
      if(!session || busy) return;
      busy = true;
      var near = list.scrollHeight - list.scrollTop - list.clientHeight < 60;
      post(api, { csrf_token:csrf, action:'chat_poll', session:session, after:last }).then(function(j){
        if(!j || !j.ok) return;
        (j.messages || []).forEach(add);
        if(near || last && list.dataset.first !== '1'){ list.scrollTop = list.scrollHeight; list.dataset.first = '1'; }
        if(!j.live && live){ live = false; setEnabled(false); note.textContent = L.chatClosed || ''; }
      }).catch(function(){}).then(function(){ busy = false; });
    }
    form.addEventListener('submit', function(e){
      e.preventDefault();
      var v = inp.value.trim();
      if(!v || !session) return;
      btn.disabled = true;
      post(api, { csrf_token:csrf, action:'chat_send', session:session, body:v, after:last }).then(function(j){
        if(j && j.ok){ inp.value = ''; note.textContent = ''; (j.messages || []).forEach(add); list.scrollTop = list.scrollHeight; }
        else if(j && j.error === 'slow') note.textContent = L.chatSlow || '';
        else if(j && j.error === 'ended'){ live = false; setEnabled(false); note.textContent = L.chatClosed || ''; }
      }).catch(function(){}).then(function(){ if(live) btn.disabled = false; inp.focus(); });
    });

    setEnabled(false);
    return {
      /** ไลฟ์จบ — เก็บข้อความไว้ให้อ่าน แต่ปิดช่องพิมพ์ */
      end: function(){ clearInterval(timer); timer = null; poll(); live = false; setEnabled(false); if(session) note.textContent = L.chatClosed || ''; },
      setSession: function(k){
        if(k === session){ if(k && !live){ live = true; setEnabled(true); note.textContent = ''; } return; }
        session = k || ''; last = 0; list.textContent = ''; list.appendChild(empty); list.dataset.first = '';
        clearInterval(timer); timer = null;
        live = !!session; setEnabled(live); note.textContent = '';
        if(session){ poll(); timer = setInterval(function(){ if(!document.hidden) poll(); }, 3000); }
      }
    };
  };
})();
