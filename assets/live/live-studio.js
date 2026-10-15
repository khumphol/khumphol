/* ============================================================
 * Aleanor Streaming — ห้องออกอากาศของผู้สอน (สอนสดจากเบราว์เซอร์)
 *
 *  ภาพ  : กล้อง / แชร์หน้าจอ / หน้าจอ + กล้องมุมจอ → ประกอบเป็นภาพเดียว (Compositor)
 *         Chrome/Edge ใช้ MediaStreamTrackProcessor/Generator (ภาพไม่ค้างแม้ผู้สอนสลับไปเปิดสไลด์ในหน้าต่างอื่น)
 *         เบราว์เซอร์อื่นใช้ canvas.captureStream แทน
 *  เสียง : ไมโครโฟน + เสียงจากหน้าจอที่แชร์ (ถ้ามี) ผสมด้วย Web Audio
 *  ส่ง  : cf = WHIP ขึ้น Cloudflare / p2p = ตอบรับผู้ชมทีละคน (Hub)
 *  ตัวจับเวลา: ใช้ Web Worker — แท็บที่อยู่เบื้องหลังจะไม่ถูกหน่วงเวลาจนภาพ/การตอบผู้ชมค้าง
 * ============================================================ */
(function(){
  'use strict';
  var AL = window.AleanorLive = window.AleanorLive || {};

  var QUALITY = {
    sd:  { w: 854,  h: 480,  kbps: 1200 },
    hd:  { w: 1280, h: 720,  kbps: 2500 },
    fhd: { w: 1920, h: 1080, kbps: 4500 }
  };

  function waitIce(pc, ms){
    return new Promise(function(res){
      if(pc.iceGatheringState === 'complete') return res();
      var t = setTimeout(done, ms || 2500);
      function done(){ clearTimeout(t); pc.removeEventListener('icegatheringstatechange', chk); res(); }
      function chk(){ if(pc.iceGatheringState === 'complete') done(); }
      pc.addEventListener('icegatheringstatechange', chk);
    });
  }
  function post(api, data){
    var fd = new FormData();
    Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
    return fetch(api, { method:'POST', body:fd, credentials:'same-origin' }).then(function(r){ return r.json(); });
  }
  function el(tag, cls, txt){ var e = document.createElement(tag); if(cls) e.className = cls; if(txt != null) e.textContent = txt; return e; }

  /* ── ตัวจับเวลาใน Worker ── */
  function Ticker(ms, fn){
    var w = null, iv = null;
    try{
      var src = 'var t=null;onmessage=function(e){clearInterval(t);if(e.data>0)t=setInterval(function(){postMessage(1);},e.data);};';
      w = new Worker(URL.createObjectURL(new Blob([src], {type:'text/javascript'})));
      w.onmessage = fn; w.postMessage(ms);
    }catch(e){ w = null; iv = setInterval(fn, ms); }
    return { stop: function(){ if(w){ w.postMessage(0); w.terminate(); w = null; } if(iv){ clearInterval(iv); iv = null; } } };
  }

  /* ============================================================
   * Compositor — ภาพที่ออกอากาศ (แทร็กเดียวตลอดไลฟ์ สลับแหล่งภาพได้โดยผู้ชมไม่ต้องต่อใหม่)
   * ============================================================ */
  function Compositor(q, opt){
    var self = this;
    this.W = q.w; this.H = q.h; this.opt = opt;
    this.layout = 'camera'; this.camOn = true; this.corner = 3; this.big = false;
    this.frames = { screen:null, cam:null };
    this.readers = {}; this.videos = {};
    this.tp = typeof window.MediaStreamTrackProcessor === 'function' && typeof window.MediaStreamTrackGenerator === 'function'
           && typeof window.OffscreenCanvas === 'function' && typeof window.VideoFrame === 'function';
    if(this.tp){
      this.canvas = new OffscreenCanvas(this.W, this.H);
      this.gen = new MediaStreamTrackGenerator({ kind:'video' });
      this.writer = this.gen.writable.getWriter();
      this.track = this.gen;
    }else{
      this.canvas = document.createElement('canvas');
      this.canvas.width = this.W; this.canvas.height = this.H;
      this.track = this.canvas.captureStream(30).getVideoTracks()[0];
    }
    this.ctx = this.canvas.getContext('2d', { alpha:false });
    this.ticker = Ticker(33, function(){ self.draw(); });
  }
  Compositor.prototype.resize = function(q){
    this.W = q.w; this.H = q.h;
    this.canvas.width = q.w; this.canvas.height = q.h;
  };
  Compositor.prototype.setSource = function(slot, track){
    var self = this;
    if(this.readers[slot]){ try{ this.readers[slot].cancel(); }catch(e){} this.readers[slot] = null; }
    if(this.frames[slot] && this.frames[slot].close) try{ this.frames[slot].close(); }catch(e){}
    this.frames[slot] = null;
    if(this.videos[slot]){ this.videos[slot].srcObject = null; this.videos[slot] = null; }
    if(!track) return;
    if(this.tp){
      var reader = new MediaStreamTrackProcessor({ track: track }).readable.getReader();
      this.readers[slot] = reader;
      (function pump(){
        reader.read().then(function(r){
          if(r.done || self.readers[slot] !== reader){ if(r.value) r.value.close(); return; }
          var old = self.frames[slot];
          self.frames[slot] = r.value;
          if(old) old.close();
          pump();
        }).catch(function(){});
      })();
    }else{
      var v = document.createElement('video');
      v.muted = true; v.playsInline = true; v.srcObject = new MediaStream([track]);
      v.play().catch(function(){});
      this.videos[slot] = v; this.frames[slot] = v;
    }
  };
  function srcSize(f){ return f ? { w: f.displayWidth || f.videoWidth || 0, h: f.displayHeight || f.videoHeight || 0 } : { w:0, h:0 }; }
  Compositor.prototype.fit = function(f, x, y, w, h, cover){
    var s = srcSize(f); if(!s.w || !s.h) return false;
    var r = cover ? Math.max(w / s.w, h / s.h) : Math.min(w / s.w, h / s.h);
    var dw = s.w * r, dh = s.h * r;
    var c = this.ctx;
    if(cover){
      // ตัดส่วนเกินจากต้นทาง ให้ภาพเต็มกรอบ
      var sw = w / r, sh = h / r;
      c.drawImage(f, (s.w - sw) / 2, (s.h - sh) / 2, sw, sh, x, y, w, h);
    }else{
      c.drawImage(f, x + (w - dw) / 2, y + (h - dh) / 2, dw, dh);
    }
    return true;
  };
  Compositor.prototype.draw = function(){
    var c = this.ctx, W = this.W, H = this.H, o = this.opt;
    var scr = this.frames.screen, cam = this.camOn ? this.frames.cam : null;
    c.fillStyle = '#0f172a'; c.fillRect(0, 0, W, H);
    var drew = false;
    try{
      if((this.layout === 'screen' || this.layout === 'both') && scr) drew = this.fit(scr, 0, 0, W, H, false);
      else if(this.layout === 'camera' && cam) drew = this.fit(cam, 0, 0, W, H, true);
      if(!drew) this.placeholder();
      // กล้องมุมจอ
      if(this.layout === 'both' && scr && cam){
        var bw = Math.round(W * (this.big ? 0.3 : 0.2)), bh = Math.round(bw * 3 / 4), m = Math.round(W * 0.018);
        // มุม: 0 บนซ้าย · 1 บนขวา · 2 ล่างซ้าย · 3 ล่างขวา
        var bx = (this.corner % 2 === 1) ? W - bw - m : m;
        var by = (this.corner >= 2) ? H - bh - m : m;
        var rad = Math.round(bw * 0.06);
        c.save();
        c.shadowColor = 'rgba(0,0,0,.45)'; c.shadowBlur = Math.round(W * 0.012);
        rrect(c, bx, by, bw, bh, rad); c.fillStyle = '#0f172a'; c.fill();
        c.shadowBlur = 0;
        rrect(c, bx, by, bw, bh, rad); c.clip();
        this.fit(cam, bx, by, bw, bh, true);
        c.restore();
        c.lineWidth = Math.max(2, Math.round(W * 0.0025)); c.strokeStyle = 'rgba(255,255,255,.85)';
        rrect(c, bx, by, bw, bh, rad); c.stroke();
      }
    }catch(e){}
    if(this.tp && this.writer.desiredSize > 0){
      var vf = new VideoFrame(this.canvas, { timestamp: Math.round(performance.now() * 1000) });
      this.writer.write(vf).catch(function(){ try{ vf.close(); }catch(e){} });
    }
  };
  /** ไม่มีภาพจากกล้อง/หน้าจอ → ขึ้นชื่อผู้สอน + ข้อความ */
  Compositor.prototype.placeholder = function(){
    var c = this.ctx, W = this.W, H = this.H, o = this.opt;
    var g = c.createLinearGradient(0, 0, W, H); g.addColorStop(0, '#1e293b'); g.addColorStop(1, '#0f172a');
    c.fillStyle = g; c.fillRect(0, 0, W, H);
    var r = Math.round(H * 0.11);
    c.beginPath(); c.arc(W / 2, H * 0.4, r, 0, Math.PI * 2); c.fillStyle = o.color || '#0ea5b7'; c.fill();
    c.fillStyle = '#fff'; c.textAlign = 'center'; c.textBaseline = 'middle';
    c.font = '700 ' + Math.round(r * 0.9) + 'px ' + o.font;
    c.fillText(o.initials || 'A', W / 2, H * 0.4 + r * 0.05);
    c.font = '600 ' + Math.round(H * 0.045) + 'px ' + o.font;
    c.fillText(o.name || '', W / 2, H * 0.62);
    c.fillStyle = 'rgba(255,255,255,.6)';
    c.font = '400 ' + Math.round(H * 0.032) + 'px ' + o.font;
    c.fillText(o.idle ? o.idle() : '', W / 2, H * 0.7);
  };
  Compositor.prototype.stop = function(){
    this.ticker.stop();
    this.setSource('screen', null); this.setSource('cam', null);
    try{ this.track.stop(); }catch(e){}
  };
  function rrect(c, x, y, w, h, r){
    c.beginPath(); c.moveTo(x + r, y); c.arcTo(x + w, y, x + w, y + h, r); c.arcTo(x + w, y + h, x, y + h, r);
    c.arcTo(x, y + h, x, y, r); c.arcTo(x, y, x + w, y, r); c.closePath();
  }

  /* ============================================================
   * Mixer — ไมค์ + เสียงจากหน้าจอที่แชร์ → แทร็กเสียงเดียว
   * ============================================================ */
  function Mixer(){
    var AC = window.AudioContext || window.webkitAudioContext;
    this.ac = new AC();
    this.dest = this.ac.createMediaStreamDestination();
    this.micGain = this.ac.createGain(); this.micGain.connect(this.dest);
    this.sysGain = this.ac.createGain(); this.sysGain.connect(this.dest);
    this.analyser = this.ac.createAnalyser(); this.analyser.fftSize = 512;
    this.buf = new Uint8Array(this.analyser.fftSize);
    this.track = this.dest.stream.getAudioTracks()[0];
    this.src = {};
  }
  Mixer.prototype.set = function(slot, track){
    if(this.src[slot]){ try{ this.src[slot].disconnect(); }catch(e){} this.src[slot] = null; }
    if(!track) return;
    var s = this.ac.createMediaStreamSource(new MediaStream([track]));
    s.connect(slot === 'mic' ? this.micGain : this.sysGain);
    if(slot === 'mic') s.connect(this.analyser);
    this.src[slot] = s;
  };
  Mixer.prototype.level = function(){
    if(!this.src.mic) return 0;
    this.analyser.getByteTimeDomainData(this.buf);
    var sum = 0;
    for(var i = 0; i < this.buf.length; i++){ var v = (this.buf[i] - 128) / 128; sum += v * v; }
    return Math.min(1, Math.sqrt(sum / this.buf.length) * 3.2);
  };

  /* ============================================================
   * ส่งภาพผ่าน Cloudflare (WHIP)
   * ============================================================ */
  function WhipPublisher(info, stream, kbps, onState){
    this.info = info; this.stream = stream; this.kbps = kbps; this.onState = onState;
    this.pc = null; this.res = ''; this.closed = false; this.tries = 0;
  }
  WhipPublisher.prototype.start = function(){
    var self = this;
    this.drop();
    var pc = this.pc = new RTCPeerConnection({ iceServers: this.info.ice || [], bundlePolicy:'max-bundle' });
    var v = this.stream.getVideoTracks()[0], a = this.stream.getAudioTracks()[0];
    pc.addTransceiver(v, { direction:'sendonly', streams:[this.stream], sendEncodings:[{ maxBitrate: this.kbps * 1000 }] });
    if(a) pc.addTransceiver(a, { direction:'sendonly', streams:[this.stream] });
    pc.onconnectionstatechange = function(){
      if(pc !== self.pc) return;
      self.onState(pc.connectionState);
      if(pc.connectionState === 'connected') self.tries = 0;
      if(pc.connectionState === 'failed') self.retry();
    };
    return pc.createOffer().then(function(of){ return pc.setLocalDescription(of); })
      .then(function(){ return waitIce(pc, 2500); })
      .then(function(){ return fetch(self.info.whip, { method:'POST', headers:{ 'Content-Type':'application/sdp' }, body: pc.localDescription.sdp }); })
      .then(function(r){
        if(!r.ok) throw new Error('WHIP ' + r.status);
        var loc = r.headers.get('Location'); if(loc) self.res = new URL(loc, self.info.whip).href;
        return r.text();
      })
      .then(function(sdp){ return pc.setRemoteDescription({ type:'answer', sdp: sdp }); })
      .catch(function(e){ self.onState('failed', e); self.retry(); });
  };
  WhipPublisher.prototype.retry = function(){
    var self = this;
    if(this.closed) return;
    this.tries++;
    setTimeout(function(){ if(!self.closed) self.start(); }, Math.min(15000, 2000 * this.tries));
  };
  WhipPublisher.prototype.drop = function(){
    if(this.res){ fetch(this.res, { method:'DELETE' }).catch(function(){}); this.res = ''; }
    if(this.pc){ try{ this.pc.close(); }catch(e){} this.pc = null; }
  };
  WhipPublisher.prototype.setKbps = function(kbps){
    this.kbps = kbps;
    if(!this.pc) return;
    this.pc.getSenders().forEach(function(s){
      if(!s.track || s.track.kind !== 'video') return;
      var p = s.getParameters(); if(!p.encodings || !p.encodings.length) p.encodings = [{}];
      p.encodings[0].maxBitrate = kbps * 1000;
      s.setParameters(p).catch(function(){});
    });
  };
  WhipPublisher.prototype.stop = function(){ this.closed = true; this.drop(); };

  /* ============================================================
   * ส่งตรงถึงผู้ชม (P2P Hub) — รับคำขอดูจากเซิร์ฟเวอร์ แล้วตอบทีละคน
   * ============================================================ */
  function P2PHub(api, csrf, info, stream, q, cb){
    this.api = api; this.csrf = csrf; this.info = info; this.stream = stream; this.q = q; this.cb = cb;
    this.peers = {}; this.busy = false; this.closed = false; this.screen = false;
  }
  P2PHub.prototype.poll = function(){
    var self = this;
    if(this.busy || this.closed) return;
    this.busy = true;
    var have = Object.keys(this.peers).join(',');
    post(this.api, { csrf_token:this.csrf, action:'host_poll', session:this.info.session, have:have }).then(function(j){
      if(!j.ok){ self.cb.error(j.error); return; }
      (j.closed || []).forEach(function(k){ self.drop(k); });
      self.cb.viewers(j.watching || [], j.count || 0, self.peers);
      var jobs = (j.offers || []).filter(function(o){ return !self.peers[o.peer]; }).map(function(o){ return function(){ return self.answer(o); }; });
      return jobs.reduce(function(p, f){ return p.then(f); }, Promise.resolve());
    }).catch(function(){ self.cb.error('net'); }).then(function(){ self.busy = false; });
  };
  P2PHub.prototype.answer = function(o){
    var self = this;
    var live = Object.keys(this.peers).length;
    if(live >= (this.info.max || 30)){
      return post(this.api, { csrf_token:this.csrf, action:'host_reject', session:this.info.session, peer:o.peer }).catch(function(){});
    }
    var pc = new RTCPeerConnection({ iceServers: this.info.ice || [], bundlePolicy:'max-bundle' });
    var entry = this.peers[o.peer] = { pc: pc, name: o.name, state: 'new' };
    pc.onconnectionstatechange = function(){
      entry.state = pc.connectionState;
      if(pc.connectionState === 'connected') self.rebalance();
      if(pc.connectionState === 'failed' || pc.connectionState === 'closed') self.drop(o.peer);
      self.cb.peers(self.peers);
    };
    return pc.setRemoteDescription({ type:'offer', sdp:o.sdp })
      .then(function(){ self.stream.getTracks().forEach(function(t){ pc.addTrack(t, self.stream); }); })
      .then(function(){ return pc.createAnswer(); })
      .then(function(an){ return pc.setLocalDescription(an); })
      .then(function(){ return waitIce(pc, 2000); })
      .then(function(){ return post(self.api, { csrf_token:self.csrf, action:'host_answer', session:self.info.session, peer:o.peer, sdp:pc.localDescription.sdp }); })
      .then(function(){ self.rebalance(); })
      .catch(function(){ self.drop(o.peer); });
  };
  /** แบ่งแบนด์วิดท์ขาขึ้นของผู้สอนให้ผู้ชมแต่ละคน — คนเยอะขึ้น ภาพแต่ละสายเบาลงอัตโนมัติ */
  P2PHub.prototype.rebalance = function(){
    var keys = Object.keys(this.peers), n = Math.max(1, keys.length);
    var per = Math.max(250, Math.min(this.q.kbps, Math.floor((this.info.uplink || 12000) / n)));
    var scale = per < 500 ? 2 : (per < 900 && this.q.h > 480 ? 1.5 : 1);
    var screen = this.screen;
    keys.forEach(function(k){
      this.peers[k].pc.getSenders().forEach(function(s){
        if(!s.track || s.track.kind !== 'video') return;
        var p = s.getParameters(); if(!p.encodings || !p.encodings.length) return;
        p.encodings[0].maxBitrate = per * 1000;
        p.encodings[0].scaleResolutionDownBy = screen ? 1 : scale;      // จอที่แชร์ต้องคมพออ่านตัวหนังสือ ลดเฟรมเรตแทน
        p.degradationPreference = screen ? 'maintain-resolution' : 'balanced';
        s.setParameters(p).catch(function(){});
      });
    }, this);
    this.perKbps = per;
  };
  P2PHub.prototype.setQuality = function(q){ this.q = q; this.rebalance(); };
  P2PHub.prototype.drop = function(k){
    var e = this.peers[k]; if(!e) return;
    delete this.peers[k];
    try{ e.pc.close(); }catch(x){}
    this.rebalance();
  };
  P2PHub.prototype.stop = function(){
    this.closed = true;
    Object.keys(this.peers).forEach(function(k){ this.drop(k); }, this);
  };

  /* ============================================================
   * อัดวิดีโอไว้ในเครื่องผู้สอน
   * ============================================================ */
  function Recorder(stream){
    var types = ['video/webm;codecs=vp9,opus', 'video/webm;codecs=vp8,opus', 'video/webm', 'video/mp4'];
    this.type = '';
    for(var i = 0; i < types.length; i++){ if(window.MediaRecorder && MediaRecorder.isTypeSupported(types[i])){ this.type = types[i]; break; } }
    this.chunks = [];
    var self = this;
    this.rec = new MediaRecorder(stream, this.type ? { mimeType: this.type, videoBitsPerSecond: 2500000 } : {});
    this.rec.ondataavailable = function(e){ if(e.data && e.data.size) self.chunks.push(e.data); };
    this.rec.start(3000);
  }
  Recorder.prototype.stop = function(){
    var self = this;
    return new Promise(function(res){
      if(self.rec.state === 'inactive') return res(new Blob(self.chunks, { type: self.type || 'video/webm' }));
      self.rec.onstop = function(){ res(new Blob(self.chunks, { type: (self.type || 'video/webm').split(';')[0] })); };
      self.rec.stop();
    });
  };

  /* ============================================================
   * Studio — ผูกกับหน้าจอ (data-r="...")
   * ============================================================ */
  AL.studio = function(root, o){
    var L = o.lbl || {};
    var $ = function(r){ return root.querySelector('[data-r="' + r + '"]'); };
    var api = o.api, csrf = o.csrf;
    var st = {
      ready:false, live:false, info:null, layout:'camera', camOn:true, micOn:true, quality: o.quality || 'hd',
      cam:null, mic:null, screen:null, comp:null, mix:null, out:null, pub:null, rec:null, t0:0,
      pollT:null, uiT:null, wake:null, recBlob:null, recUrl:''
    };
    var Q = function(){ return QUALITY[st.quality] || QUALITY.hd; };
    var canScreen = !!(navigator.mediaDevices && navigator.mediaDevices.getDisplayMedia);
    if(!canScreen) root.classList.add('no-screen');

    function msg(text, kind){
      var m = $('msg'); if(!m) return;
      m.textContent = text || ''; m.dataset.k = kind || ''; m.hidden = !text;
      clearTimeout(msg.t); if(text && kind !== 'err') msg.t = setTimeout(function(){ m.hidden = true; }, 6000);
    }
    function setStatus(kind, text){
      var s = $('status'); s.dataset.k = kind; s.textContent = '';
      if(kind === 'live') s.appendChild(el('span', 'lst-dot'));
      s.appendChild(document.createTextNode(text));
    }
    function fmt(sec){
      sec = Math.max(0, Math.floor(sec)); var h = Math.floor(sec / 3600), m = Math.floor(sec % 3600 / 60), s = sec % 60;
      return (h ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (s < 10 ? '0' : '') + s;
    }

    // ── อุปกรณ์ ──
    function stopTracks(s){ if(s) s.getTracks().forEach(function(t){ t.stop(); }); }
    function openCam(){
      var id = $('camSel').value;
      stopTracks(st.cam); st.cam = null;
      return navigator.mediaDevices.getUserMedia({ video: {
          deviceId: id ? { exact:id } : undefined, width:{ ideal:1280 }, height:{ ideal:720 }, frameRate:{ ideal:30 } } })
        .then(function(s){ st.cam = s; st.comp.setSource('cam', s.getVideoTracks()[0]); })
        .catch(function(e){ st.comp.setSource('cam', null); msg(L.camFail + (e && e.name ? ' (' + e.name + ')' : ''), 'err'); });
    }
    function openMic(){
      var id = $('micSel').value;
      stopTracks(st.mic); st.mic = null;
      return navigator.mediaDevices.getUserMedia({ audio: { deviceId: id ? { exact:id } : undefined,
          echoCancellation:true, noiseSuppression:true, autoGainControl:true } })
        .then(function(s){ st.mic = s; st.mix.set('mic', s.getAudioTracks()[0]); })
        .catch(function(e){ st.mix.set('mic', null); msg(L.micFail + (e && e.name ? ' (' + e.name + ')' : ''), 'err'); });
    }
    function listDevices(){
      return navigator.mediaDevices.enumerateDevices().then(function(ds){
        [['camSel', 'videoinput', L.camera], ['micSel', 'audioinput', L.mic]].forEach(function(x){
          var sel = $(x[0]), cur = sel.value; sel.textContent = '';
          ds.filter(function(d){ return d.kind === x[1]; }).forEach(function(d, i){
            var op = el('option', '', d.label || (x[2] + ' ' + (i + 1))); op.value = d.deviceId; sel.appendChild(op);
          });
          if(cur) sel.value = cur;
        });
      });
    }
    function shareScreen(){
      if(!canScreen) return Promise.resolve(false);
      return navigator.mediaDevices.getDisplayMedia({
          video: { frameRate:{ ideal:30, max:30 }, width:{ ideal:1920 }, height:{ ideal:1080 } },
          audio: true, selfBrowserSurface:'exclude', surfaceSwitching:'include', systemAudio:'include' })
        .then(function(s){
          stopTracks(st.screen); st.screen = s;
          var vt = s.getVideoTracks()[0];
          if('contentHint' in vt) vt.contentHint = 'detail';
          st.comp.setSource('screen', vt);
          st.mix.set('sys', s.getAudioTracks()[0] || null);
          vt.addEventListener('ended', function(){                    // กด "หยุดแชร์" ที่แถบของเบราว์เซอร์
            if(st.screen !== s) return;
            st.screen = null; st.comp.setSource('screen', null); st.mix.set('sys', null);
            setLayout('camera');
            msg(L.screenStopped);
          });
          return true;
        })
        .catch(function(){ return false; });
    }

    // ── เปิดอุปกรณ์ครั้งแรก (ต้องมาจากการกดของผู้ใช้ เพราะเสียงต้องเปิดจาก user gesture) ──
    function prepare(){
      if(st.ready) return Promise.resolve();
      if(!window.RTCPeerConnection || !navigator.mediaDevices){ msg(L.noSupport, 'err'); return Promise.reject(); }
      st.comp = new Compositor(Q(), { font: o.font || 'sans-serif', name: o.hostName || '', initials: o.initials || 'A',
                                      color: o.color, idle: function(){ return st.camOn ? L.waitingCam : L.camOffNote; } });
      st.mix = new Mixer();
      if(st.mix.ac.state === 'suspended') st.mix.ac.resume();
      st.out = new MediaStream([st.comp.track, st.mix.track]);
      var pv = $('preview'); pv.srcObject = new MediaStream([st.comp.track]); pv.muted = true; pv.play().catch(function(){});
      root.classList.add('is-ready');
      $('setup').hidden = true;
      return Promise.all([openCam(), openMic()]).then(listDevices).then(function(){
        st.ready = true; setLayout(st.layout); meter();
      });
    }
    function meter(){
      var bar = $('meterFill');
      (function loop(){
        if(!st.mix) return;
        bar.style.width = Math.round((st.micOn ? st.mix.level() : 0) * 100) + '%';
        requestAnimationFrame(loop);
      })();
    }

    // ── เลือกแหล่งภาพ ──
    function setLayout(l){
      if((l === 'screen' || l === 'both') && !st.screen){
        return shareScreen().then(function(ok){ if(ok) setLayout(l); });
      }
      st.layout = l;
      if(st.comp){ st.comp.layout = l; }
      if(st.comp && 'contentHint' in st.comp.track) st.comp.track.contentHint = (l === 'camera') ? 'motion' : 'detail';
      if(st.pub && st.pub.rebalance){ st.pub.screen = (l !== 'camera'); st.pub.rebalance(); }
      [].forEach.call(root.querySelectorAll('[data-src]'), function(b){ b.classList.toggle('on', b.dataset.src === l); });
      $('corner').hidden = (l !== 'both');
      $('size').hidden = (l !== 'both');
      $('stopShare').hidden = !st.screen;
    }
    [].forEach.call(root.querySelectorAll('[data-src]'), function(b){
      b.addEventListener('click', function(){ prepare().then(function(){ setLayout(b.dataset.src); }); });
    });
    $('stopShare').addEventListener('click', function(){
      if(!st.screen) return;
      stopTracks(st.screen); st.screen = null; st.comp.setSource('screen', null); st.mix.set('sys', null);
      setLayout('camera');
    });
    $('corner').addEventListener('click', function(){ if(st.comp){ st.comp.corner = (st.comp.corner + 1) % 4; } });
    $('size').addEventListener('click', function(){ if(st.comp){ st.comp.big = !st.comp.big; this.classList.toggle('on', st.comp.big); } });
    $('mic').addEventListener('click', function(){
      st.micOn = !st.micOn;
      if(st.mix) st.mix.micGain.gain.value = st.micOn ? 1 : 0;
      this.classList.toggle('off', !st.micOn);
      this.querySelector('i').className = 'fi fi-rr-' + (st.micOn ? 'microphone' : 'microphone-slash');
      this.title = st.micOn ? L.micMute : L.micUnmute;
    });
    $('cam').addEventListener('click', function(){
      st.camOn = !st.camOn;
      if(st.comp) st.comp.camOn = st.camOn;
      if(st.cam) st.cam.getVideoTracks().forEach(function(t){ t.enabled = st.camOn; });
      this.classList.toggle('off', !st.camOn);
      this.querySelector('i').className = 'fi fi-rr-' + (st.camOn ? 'video-camera-alt' : 'video-slash');
      this.title = st.camOn ? L.camOff : L.camOn;
    });
    $('camSel').addEventListener('change', function(){ if(st.ready) openCam(); });
    $('micSel').addEventListener('change', function(){ if(st.ready) openMic(); });
    $('qSel').value = st.quality;
    $('qSel').addEventListener('change', function(){
      st.quality = this.value;
      try{ localStorage.setItem('alsQuality', st.quality); }catch(e){}
      if(st.comp) st.comp.resize(Q());
      if(st.pub && st.pub.setQuality) st.pub.setQuality(Q());
      if(st.pub && st.pub.setKbps) st.pub.setKbps(Q().kbps);
    });
    $('setupGo').addEventListener('click', function(){ prepare().catch(function(){}); });
    $('gear').addEventListener('click', function(){ var p = $('settings'); p.hidden = !p.hidden; this.classList.toggle('on', !p.hidden); });

    // ── ออกอากาศ ──
    function startPublishing(info){
      st.info = info; st.live = true;
      st.t0 = Date.now() - (info.elapsed || 0) * 1000;
      root.classList.add('is-live');
      $('go').classList.add('stop'); $('goTxt').textContent = L.stop;
      $('title').disabled = true;
      setStatus('wait', L.connecting);
      if(info.mode === 'cf'){
        st.pub = new WhipPublisher(info, st.out, Q().kbps, function(s){
          if(s === 'connected') setStatus('live', 'LIVE');
          else if(s === 'failed' || s === 'disconnected') setStatus('wait', L.reconnecting);
        });
        st.pub.start();
        // ผู้ชมผ่าน Cloudflare ไม่ต้องให้ผู้สอนตอบรับ แต่ยังต้องส่งสัญญาณว่ายังออนไลน์ + ดึงรายชื่อผู้ชม
        st.pollT = Ticker(5000, hostPoll);
      }else{
        st.pub = new P2PHub(api, csrf, info, st.out, Q(), {
          viewers: renderViewers,
          peers: function(){},
          error: function(e){ if(e === 'ended' || e === 'not_host') endedRemotely(); }
        });
        st.pub.screen = (st.layout !== 'camera');
        st.pollT = Ticker(1500, function(){ st.pub.poll(); });
        setStatus('live', 'LIVE');
      }
      st.uiT = Ticker(1000, function(){ $('timer').textContent = fmt((Date.now() - st.t0) / 1000); });
      if($('rec').checked && window.MediaRecorder && !st.rec){
        try{ st.rec = new Recorder(st.out); $('recDot').hidden = false; }catch(e){ msg(L.recFail, 'err'); }
      }
      if(navigator.wakeLock) navigator.wakeLock.request('screen').then(function(w){ st.wake = w; }).catch(function(){});
      $('summary').hidden = true;
      if(o.onLive) o.onLive(true);
    }
    var polling = false;
    function hostPoll(){
      if(polling || !st.info) return;
      polling = true;
      post(api, { csrf_token:csrf, action:'host_poll', session:st.info.session, have:'' }).then(function(j){
        if(!j.ok){ if(j.error === 'ended' || j.error === 'not_host') endedRemotely(); return; }
        renderViewers(j.watching || [], j.count || 0, null);
      }).catch(function(){}).then(function(){ polling = false; });
    }
    function renderViewers(list, n, peers){
      $('count').textContent = n;
      var box = o.viewersBox; if(!box) return;
      box.textContent = '';
      if(!list.length){ box.appendChild(el('div', 'lsv-empty', L.noViewers)); return; }
      list.forEach(function(v){
        var row = el('div', 'lsv-row');
        var im;
        if(o.photoBase){
          im = el('img', 'lsv-ava'); im.src = o.photoBase + encodeURIComponent(v.photo || 'default.png');
          im.onerror = function(){ this.onerror = null; this.src = o.photoBase + 'default.png'; };
        }else im = el('span', 'lsv-ava lsv-ini', ((v.name || '?').trim().charAt(0) || '?').toUpperCase());   // ระบบที่ไม่มีรูปโปรไฟล์ → อักษรแรกของชื่อ
        row.appendChild(im); row.appendChild(el('span', 'lsv-name', v.name || '—'));
        box.appendChild(row);
      });
      if(o.onCount) o.onCount(n);
    }
    function go(){
      if(st.live) return stop();
      prepare().then(function(){
        var b = $('go'); b.disabled = true;
        return post(api, { csrf_token:csrf, action:'host_start', course_key:o.course, title:$('title').value }).then(function(j){
          b.disabled = false;
          if(!j.ok){ msg(j.error === 'cf' ? L.cfFail + (j.message ? ': ' + j.message : '') : L.startFail + ' (' + (j.error || '') + ')', 'err'); return; }
          startPublishing(j);
          post(api, { csrf_token:csrf, action:'host_notify', session:j.session }).catch(function(){});
          msg(L.started);
        });
      }).catch(function(){ $('go').disabled = false; });
    }
    function teardown(){
      st.live = false;
      if(st.pollT){ st.pollT.stop(); st.pollT = null; }
      if(st.uiT){ st.uiT.stop(); st.uiT = null; }
      if(st.pub){ st.pub.stop(); st.pub = null; }
      if(st.wake){ st.wake.release().catch(function(){}); st.wake = null; }
      root.classList.remove('is-live');
      $('go').classList.remove('stop'); $('goTxt').textContent = L.start; $('go').disabled = false;
      $('title').disabled = false;
      setStatus('idle', L.idle); $('timer').textContent = '0:00'; $('count').textContent = '0';
      if(o.viewersBox){ o.viewersBox.textContent = ''; o.viewersBox.appendChild(el('div', 'lsv-empty', L.noViewers)); }
      if(o.onLive) o.onLive(false);
      var recDone = st.rec ? st.rec.stop() : Promise.resolve(null);
      st.rec = null; $('recDot').hidden = true;
      return recDone;
    }
    function stop(){
      var ask = window.dlgConfirm || function(m, cb){ if(confirm(m)) cb(); };
      ask(L.confirmStop, function(){
        var info = st.info;
        $('go').disabled = true;
        teardown().then(function(blob){
          return post(api, { csrf_token:csrf, action:'host_stop', session:info.session }).then(function(j){ showSummary(j, blob, info); });
        }).catch(function(){ showSummary(null, null, info); });
      });
    }
    function endedRemotely(){
      if(!st.live) return;
      var info = st.info;
      teardown().then(function(blob){ showSummary(null, blob, info); msg(L.endedElsewhere, 'err'); });
    }
    function showSummary(j, blob, info){
      var box = $('summary'); box.hidden = false;
      $('sumDur').textContent = j ? fmt(j.duration) : '—';
      $('sumViewers').textContent = j ? j.viewers : '—';
      $('sumPeak').textContent = j ? j.peak : '—';
      var dl = $('sumRec');
      if(st.recUrl){ URL.revokeObjectURL(st.recUrl); st.recUrl = ''; }
      if(blob && blob.size){
        st.recUrl = URL.createObjectURL(blob);
        var ext = /mp4/.test(blob.type) ? 'mp4' : 'webm';
        dl.href = st.recUrl;
        var d = new Date(), z = function(n){ return (n < 10 ? '0' : '') + n; };
        dl.download = (o.fileBase || 'live') + '-' + d.getFullYear() + z(d.getMonth() + 1) + z(d.getDate()) + '-' + z(d.getHours()) + z(d.getMinutes()) + '.' + ext;
        $('sumRecSize').textContent = (blob.size / 1048576).toFixed(1) + ' MB';
        dl.hidden = false;
      }else dl.hidden = true;
      if(o.onEnded) o.onEnded(info);
    }
    $('go').addEventListener('click', go);

    // เปิดหน้านี้ใหม่ระหว่างไลฟ์ (รีเฟรช) → ออกอากาศต่อในไลฟ์เดิม
    function resume(){
      prepare().then(function(){ startPublishing(st.pending); st.pending = null; $('resume').hidden = true; });
    }
    $('resumeGo').addEventListener('click', resume);
    $('resumeEnd').addEventListener('click', function(){
      var info = st.pending || st.other;
      if(!info) return;
      post(api, { csrf_token:csrf, action:'host_stop', session:info.session }).then(function(){ location.reload(); });
    });

    window.addEventListener('beforeunload', function(e){ if(st.live){ e.preventDefault(); e.returnValue = ''; } });

    try{ var q = localStorage.getItem('alsQuality'); if(q && QUALITY[q]){ st.quality = q; $('qSel').value = q; } }catch(e){}
    setStatus('idle', L.idle);
    post(api, { csrf_token:csrf, action:'host_state', course_key:o.course }).then(function(j){
      if(!j.ok) return;
      var mb = $('modeTxt');
      if(mb) mb.textContent = (j.mode === 'cf') ? L.modeCf : L.modeP2p;
      if(!j.live) return;
      var box = $('resume'); box.hidden = false;
      if(j.mine){ st.pending = j; $('resumeTxt').textContent = L.resumeMine; $('resumeGo').hidden = false; }
      else { st.other = j; $('resumeTxt').textContent = (L.otherLive || '').replace(':name', j.host || ''); $('resumeGo').hidden = true; $('go').disabled = true; }
    }).catch(function(){});

    return { state: st };
  };
})();
