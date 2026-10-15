/* ============================================================
 * Aleanor Streaming — ตัวดูไลฟ์ของผู้เรียน
 *   p2p : ส่ง offer ไปที่เซิร์ฟเวอร์ → รอเครื่องผู้สอนตอบ answer กลับ (ต่อตรงกับเครื่องผู้สอน)
 *   cf  : ส่ง offer ไปที่ Cloudflare (WHEP) → ได้ answer ทันที (ถ้าเรียกตรงไม่ได้ ใช้ตัวเล่นของ Cloudflare แทน)
 * ใช้: AleanorLive.viewer(el, {api, csrf, course, lbl, onLive})
 * ============================================================ */
(function(){
  'use strict';
  var AL = window.AleanorLive = window.AleanorLive || {};

  /** รอรวบรวมเส้นทางเชื่อมต่อ (ICE) ให้ครบก่อนส่ง SDP — ไม่ต้องรับส่ง candidate ทีละตัวผ่านเซิร์ฟเวอร์ */
  AL.waitIce = function(pc, ms){
    return new Promise(function(res){
      if(pc.iceGatheringState === 'complete') return res();
      var t = setTimeout(done, ms || 2500);
      function done(){ clearTimeout(t); pc.removeEventListener('icegatheringstatechange', chk); res(); }
      function chk(){ if(pc.iceGatheringState === 'complete') done(); }
      pc.addEventListener('icegatheringstatechange', chk);
    });
  };
  AL.post = function(api, data){
    var fd = new FormData();
    Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
    return fetch(api, { method:'POST', body:fd, credentials:'same-origin' })
      .then(function(r){ return r.json(); });
  };
  function el(tag, cls, txt){ var e = document.createElement(tag); if(cls) e.className = cls; if(txt != null) e.textContent = txt; return e; }
  function icon(name){ return el('i', 'fi fi-rr-' + name); }
  function sleep(ms){ return new Promise(function(r){ setTimeout(r, ms); }); }

  AL.viewer = function(root, o){
    var L = o.lbl || {};
    var api = o.api, csrf = o.csrf, course = o.course;
    var info = null, pc = null, peer = '', whepRes = '', stopped = false, gen = 0;
    var pingT = null, statusT = null, retry = 0, unmuted = false, hadLive = false;

    // ── หน้าตาตัวเล่น ──
    root.classList.add('alv');
    var stage = el('div', 'alv-stage'); root.appendChild(stage);
    var video = el('video', 'alv-video'); video.playsInline = true; video.autoplay = true; video.muted = true;
    video.setAttribute('playsinline', ''); stage.appendChild(video);
    var frame = null;                                    // ตัวเล่น Cloudflare (สำรอง)
    var badge = el('div', 'alv-badge'); badge.appendChild(el('span', 'alv-dot')); badge.appendChild(document.createTextNode('LIVE'));
    var clock = el('span', 'alv-clock'); badge.appendChild(clock); stage.appendChild(badge);
    var over = el('div', 'alv-over'); var oIc = el('div', 'alv-over-ic'); var oTx = el('div', 'alv-over-tx'); var oSub = el('div', 'alv-over-sub');
    over.appendChild(oIc); over.appendChild(oTx); over.appendChild(oSub); stage.appendChild(over);
    var sound = el('button', 'alv-sound'); sound.type = 'button'; sound.appendChild(icon('volume-mute')); sound.appendChild(el('span', '', L.tapSound || 'แตะเพื่อเปิดเสียง'));
    stage.appendChild(sound);
    var bar = el('div', 'alv-bar');
    var bVol = el('button', 'alv-b'); bVol.type = 'button'; bVol.title = L.sound || 'เสียง'; bVol.appendChild(icon('volume-mute'));
    var vol = el('input', 'alv-vol'); vol.type = 'range'; vol.min = 0; vol.max = 1; vol.step = 0.05; vol.value = 1;
    var host = el('span', 'alv-host');
    var sp = el('span', 'alv-sp');
    var bPip = el('button', 'alv-b'); bPip.type = 'button'; bPip.title = L.pip || 'ภาพซ้อน'; bPip.appendChild(icon('picture'));
    var bFull = el('button', 'alv-b'); bFull.type = 'button'; bFull.title = L.fullscreen || 'เต็มจอ'; bFull.appendChild(icon('expand'));
    [bVol, vol, host, sp, bPip, bFull].forEach(function(x){ bar.appendChild(x); });
    stage.appendChild(bar);
    if(!document.pictureInPictureEnabled) bPip.style.display = 'none';

    function setOver(kind, text, sub){
      over.hidden = !kind;
      if(!kind) return;
      over.dataset.k = kind;
      oIc.textContent = '';
      if(kind === 'wait') oIc.appendChild(el('div', 'alv-spin'));
      else oIc.appendChild(icon(kind === 'off' ? 'video-slash' : kind === 'full' ? 'users-alt' : kind === 'ended' ? 'flag-checkered' : 'triangle-warning'));
      oTx.textContent = text || ''; oSub.textContent = sub || '';
    }
    function setSoundUi(){
      var on = !video.muted;
      bVol.textContent = ''; bVol.appendChild(icon(on ? 'volume' : 'volume-mute'));
      sound.hidden = on || !video.srcObject;
    }
    function enableSound(){
      unmuted = true; video.muted = false; video.volume = +vol.value;
      video.play().catch(function(){}); setSoundUi();
    }
    sound.addEventListener('click', enableSound);
    bVol.addEventListener('click', function(){ if(video.muted) enableSound(); else { video.muted = true; unmuted = false; setSoundUi(); } });
    vol.addEventListener('input', function(){ video.volume = +vol.value; if(video.muted && +vol.value > 0) enableSound(); });
    bFull.addEventListener('click', function(){
      if(document.fullscreenElement) document.exitFullscreen();
      else if(stage.requestFullscreen) stage.requestFullscreen();
      else if(video.webkitEnterFullscreen) video.webkitEnterFullscreen();
    });
    bPip.addEventListener('click', function(){
      if(document.pictureInPictureElement) document.exitPictureInPicture();
      else video.requestPictureInPicture().catch(function(){});
    });
    var tick = setInterval(function(){
      if(!info || !info.t0) return;
      var s = Math.max(0, Math.floor((Date.now() - info.t0) / 1000));
      var h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), ss = s % 60;
      clock.textContent = (h ? h + ':' : '') + (m < 10 && h ? '0' : '') + m + ':' + (ss < 10 ? '0' : '') + ss;
    }, 1000);

    function play(){
      var p = video.play();
      if(p && p.catch) p.catch(function(){ video.muted = true; video.play().catch(function(){}); setSoundUi(); });
    }

    // ── เชื่อมต่อ ──
    function closePc(){
      if(pc){ try{ pc.close(); }catch(e){} pc = null; }
      if(peer){ AL.post(api, {csrf_token:csrf, action:'leave', session:info ? info.session : '', peer:peer}).catch(function(){}); peer = ''; }
      if(whepRes){ fetch(whepRes, {method:'DELETE'}).catch(function(){}); whepRes = ''; }
    }
    function newPc(){
      var p = new RTCPeerConnection({ iceServers: info.ice || [], bundlePolicy:'max-bundle' });
      p.addTransceiver('video', {direction:'recvonly'});
      p.addTransceiver('audio', {direction:'recvonly'});
      var ms = new MediaStream();
      p.ontrack = function(e){
        ms.addTrack(e.track);
        if(video.srcObject !== ms){ video.srcObject = ms; }
        video.muted = !unmuted; play(); setSoundUi();
      };
      var lostT = null;
      p.onconnectionstatechange = function(){
        var st = p.connectionState;
        if(st === 'connected'){ clearTimeout(lostT); retry = 0; setOver(null); }
        else if(p !== pc){ clearTimeout(lostT); }          // สายเก่าที่ปิดไปแล้ว (จบไลฟ์/ต่อใหม่) — ไม่ต้องทำอะไร
        else if(st === 'failed'){ reconnect(); }
        else if(st === 'disconnected'){ clearTimeout(lostT); lostT = setTimeout(function(){ if(p === pc && p.connectionState !== 'connected') reconnect(); }, 6000); }
      };
      return p;
    }
    function connect(){
      var my = ++gen;
      closePc();
      setOver('wait', L.connecting || 'กำลังเชื่อมต่อ…');
      pc = newPc();
      var thePc = pc;
      return pc.createOffer()
        .then(function(of){ return thePc.setLocalDescription(of); })
        .then(function(){ return AL.waitIce(thePc, 2500); })
        .then(function(){
          if(my !== gen) return;
          var sdp = thePc.localDescription.sdp;
          return info.mode === 'cf' ? whep(sdp) : p2p(sdp, my);
        })
        .then(function(ans){
          if(my !== gen || !ans) return;
          return thePc.setRemoteDescription({type:'answer', sdp:ans});
        })
        .catch(function(err){
          if(my !== gen) return;
          if(err && err.code === 'full'){ setOver('full', L.full || 'ห้องเต็ม', (L.fullSub || '').replace(':n', err.max || '')); closePc(); setTimeout(function(){ if(my === gen) connect(); }, 20000); return; }
          if(err && err.code === 'ended') return ended();
          if(err && err.code === 'whep'){ return cfFrame(); }
          reconnect();
        });
    }
    function whep(sdp){
      return fetch(info.whep, { method:'POST', headers:{'Content-Type':'application/sdp'}, body:sdp })
        .then(function(r){
          if(!r.ok) throw {code:'whep'};
          var loc = r.headers.get('Location');
          if(loc) whepRes = new URL(loc, info.whep).href;
          return r.text();
        }, function(){ throw {code:'whep'}; });
    }
    /** WHEP เรียกตรงจากหน้านี้ไม่ได้ (เครือข่าย/CORS) → ใช้ตัวเล่นของ Cloudflare ที่ต่อ WHEP ให้เอง */
    function cfFrame(){
      closePc();
      video.hidden = true; sound.hidden = true; bar.hidden = true;
      if(!frame){
        frame = el('iframe', 'alv-frame');
        frame.allow = 'autoplay; fullscreen; picture-in-picture'; frame.allowFullscreen = true;
        frame.src = info.whep.replace(/\/webRTC\/play\/?$/, '/iframe') + '?autoplay=true&muted=true';
        stage.insertBefore(frame, badge);
      }
      setOver(null);
    }
    function p2p(sdp, my){
      return AL.post(api, {csrf_token:csrf, action:'join', session:info.session, offer:sdp}).then(function(j){
        if(!j.ok){
          if(j.error === 'full') throw {code:'full', max:j.max};
          if(j.live === false || j.error === 'ended') throw {code:'ended'};
          if(j.error === 'self') throw {code:'self'};
          throw {code:j.error};
        }
        if(j.live === false) throw {code:'ended'};
        peer = j.peer;
        var t0 = Date.now();
        // เครื่องผู้สอนตอบรับภายในไม่กี่วินาที (ดึงคำขอทุก ~1.5 วินาที)
        return (function waitAns(){
          if(my !== gen) return null;
          if(Date.now() - t0 > 30000) throw {code:'timeout'};
          return sleep(1000).then(function(){
            return AL.post(api, {csrf_token:csrf, action:'wait', session:info.session, peer:peer});
          }).then(function(w){
            if(!w.ok) throw {code:w.error};
            if(w.live === false) throw {code:'ended'};
            if(w.state === 'answered') return w.answer;
            if(w.state === 'full') throw {code:'full', max:info.max};
            if(w.state === 'closed') throw {code:'closed'};
            return waitAns();
          });
        })();
      });
    }
    function reconnect(){
      if(stopped || !info) return;                       // จบไลฟ์ไปแล้ว — ไม่ต้องต่อใหม่ (กันจอค้าง "กำลังเชื่อมต่อใหม่")
      var my = ++gen;
      closePc();
      retry++;
      setOver('wait', L.reconnecting || 'กำลังเชื่อมต่อใหม่…');
      var delay = Math.min(15000, 1500 * retry);
      setTimeout(function(){
        if(my !== gen || stopped) return;
        // เช็กก่อนว่าไลฟ์ยังอยู่ไหม
        refresh(true);
      }, delay);
    }
    function ended(){
      gen++; closePc();
      video.srcObject = null; setSoundUi();
      if(frame){ frame.remove(); frame = null; video.hidden = false; bar.hidden = false; }
      info = null; root.classList.remove('is-live');
      setOver('ended', L.ended || 'จบการสอนสดแล้ว', L.endedSub || '');
      if(o.onLive) o.onLive(false);
    }

    /** ถามสถานะไลฟ์ของรายวิชา */
    function refresh(force){
      return AL.post(api, {csrf_token:csrf, action:'status', course_key:course}).then(function(j){
        if(!j.ok){ setOver('err', L.error || 'โหลดไลฟ์ไม่ได้', j.error || ''); return; }
        if(!j.live){
          if(info) ended();
          else if(hadLive) setOver('ended', L.ended || 'จบการสอนสดแล้ว', L.endedSub || '');      // จบไปแล้ว → คงข้อความ "จบแล้ว" ไว้
          else setOver('off', L.waiting || 'ยังไม่เริ่มสอนสด', L.waitingSub || '');
          return;
        }
        hadLive = true;
        var same = info && info.session === j.session;
        if(same && !force) return;
        info = j; info.t0 = Date.now() - (j.elapsed || 0) * 1000;
        root.classList.add('is-live');
        host.textContent = j.host ? (L.by || 'สอนโดย') + ' ' + j.host : '';
        if(o.onLive) o.onLive(true, j);
        if(j.mode === 'cf' && frame) return;
        connect();
      }).catch(function(){ setOver('err', L.error || 'โหลดไลฟ์ไม่ได้', L.netErr || ''); });
    }

    // ผู้ชมยังดูอยู่ (นับรายชื่อ/เวลาที่ดู) + เช็กว่าไลฟ์จบหรือยัง
    pingT = setInterval(function(){
      if(!info) return;                                   // ฟังอยู่ในแท็บพื้นหลังก็ยังนับว่าดูอยู่
      AL.post(api, {csrf_token:csrf, action:'ping', session:info.session, peer:peer}).then(function(j){
        if(j && j.ok && j.live === false) ended();
        if(j && j.ok && peer && j.state === 'closed') reconnect();
      }).catch(function(){});
    }, 15000);
    // ยังไม่มีไลฟ์ / จบไปแล้ว → เช็กเป็นระยะ ผู้สอนเริ่มเมื่อไรก็ขึ้นเอง
    statusT = setInterval(function(){ if(!info && !document.hidden) refresh(false); }, 20000);
    window.addEventListener('pagehide', function(){ stopped = true; closePc(); });

    refresh(false);
    return {
      refresh: refresh,
      destroy: function(){ stopped = true; gen++; clearInterval(pingT); clearInterval(statusT); clearInterval(tick); closePc(); root.textContent = ''; }
    };
  };
})();
