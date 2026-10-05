/* ============================================================
 * Aleanor Indy player — วิดีโอแบบเลือกเส้นทาง (standalone)
 * พอร์ตจาก aleanor_ai/pages/course/view-course.php (openIndy / indyScene)
 *
 *   IndyPlayer.mount(el, data, opts) → { go(sceneId), restart(), destroy() }
 *     data = { name, entry, scenes: { "<id>": { name, video, branches: [{label, target}] } }, controls }
 *     opts = { onScene(sceneId), onEnd(sceneId), resumeScene }
 *
 *   - ฉากจบลง → แสดงปุ่มทางเลือกทับวิดีโอ
 *   - ห้ามเลื่อนไปข้างหน้าเกินจุดที่ดูถึง (ย้อนกลับได้)
 *   - data.controls === false → ซ่อนแถบควบคุม (ผู้เรียนหยุด/เลื่อนเองไม่ได้)
 *   - ฉากที่ไม่มีทางเลือก = ฉากจบ → "จบเส้นทาง" + ปุ่มเริ่มใหม่
 *   - ลิงก์ Cloudflare Stream (iframe) ไม่มี event ให้ฟัง → แสดงทางเลือกทันที
 * ============================================================ */
(function (w) {
  'use strict';
  // ข้อความ (escape เป็น \u เพื่อไม่ขึ้นกับ charset ที่เซิร์ฟเวอร์ส่งมากับไฟล์ .js):
  //   เลือกเส้นทางของคุณ / จบเส้นทาง / เริ่มใหม่ตั้งแต่ต้น / ดูฉากนี้อีกครั้ง / ฉากนี้ไม่มีวิดีโอ /
  //   ยังไม่มีฉากในโปรเจกต์นี้ / ข้ามไปข้างหน้าไม่ได้ — แก้ทับได้ผ่าน IndyPlayer.labels
  var T = {
    choose: '\u0e40\u0e25\u0e37\u0e2d\u0e01\u0e40\u0e2a\u0e49\u0e19\u0e17\u0e32\u0e07\u0e02\u0e2d\u0e07\u0e04\u0e38\u0e13',
    end: '\u0e08\u0e1a\u0e40\u0e2a\u0e49\u0e19\u0e17\u0e32\u0e07',
    restart: '\u0e40\u0e23\u0e34\u0e48\u0e21\u0e43\u0e2b\u0e21\u0e48\u0e15\u0e31\u0e49\u0e07\u0e41\u0e15\u0e48\u0e15\u0e49\u0e19',
    replay: '\u0e14\u0e39\u0e09\u0e32\u0e01\u0e19\u0e35\u0e49\u0e2d\u0e35\u0e01\u0e04\u0e23\u0e31\u0e49\u0e07',
    noVideo: '\u0e09\u0e32\u0e01\u0e19\u0e35\u0e49\u0e44\u0e21\u0e48\u0e21\u0e35\u0e27\u0e34\u0e14\u0e35\u0e42\u0e2d',
    noScenes: '\u0e22\u0e31\u0e07\u0e44\u0e21\u0e48\u0e21\u0e35\u0e09\u0e32\u0e01\u0e43\u0e19\u0e42\u0e1b\u0e23\u0e40\u0e08\u0e01\u0e15\u0e4c\u0e19\u0e35\u0e49',
    noSeek: '\u0e02\u0e49\u0e32\u0e21\u0e44\u0e1b\u0e02\u0e49\u0e32\u0e07\u0e2b\u0e19\u0e49\u0e32\u0e44\u0e21\u0e48\u0e44\u0e14\u0e49'
  };

  function mk(tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) e.className = cls;
    if (text !== undefined && text !== null) e.textContent = text;
    return e;
  }
  function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); }
  function isIframeSrc(src) { return /cloudflarestream\.com|videodelivery\.net/i.test(src || ''); }

  function mount(el, data, opts) {
    opts = opts || {};
    if (!el) return null;
    clear(el);
    var root = mk('div', 'indy-player');
    el.appendChild(root);
    if (!data || !data.scenes || !Object.keys(data.scenes).length) {
      var em = mk('div', 'indy-novideo'); em.appendChild(mk('i', 'fi fi-rr-puzzle-alt')); em.appendChild(mk('div', '', T.noScenes));
      root.appendChild(em);
      return null;
    }
    var hideCtl = data.controls === false || data.controls === 0;   // ผู้สอนเลือกซ่อนแถบควบคุม
    var current = null, video = null, dead = false;

    function scene(id) { return data.scenes[String(id)] || null; }
    function cb(name, arg) { try { if (typeof opts[name] === 'function') opts[name](arg); } catch (e) { if (w.console) console.error(e); } }

    function stopVideo() {
      if (video) { try { video.pause(); video.removeAttribute('src'); video.load(); } catch (e) {} video = null; }
    }

    function go(id) {
      if (dead) return;
      var sc = scene(id);
      if (!sc) { if (String(id) !== String(data.entry)) go(data.entry); return; }
      current = String(id);
      stopVideo();
      clear(root);
      var stage = mk('div', 'indy-stage'); root.appendChild(stage);
      var bar = mk('div', 'indy-bar'); bar.appendChild(mk('b', '', data.name || '')); bar.appendChild(mk('span', '', '\u00b7 ' + (sc.name || '')));
      root.appendChild(bar);
      var ending = !sc.branches || sc.branches.length === 0;
      cb('onScene', parseInt(current, 10));

      function showChoices() {
        if (root.querySelector('.indy-choices')) return;
        var ov = mk('div', 'indy-choices' + (ending ? ' indy-full' : ''));
        var q = mk('div', 'indy-q');
        q.appendChild(mk('i', ending ? 'fi fi-rr-flag-checkered' : 'fi fi-rr-shuffle'));
        q.appendChild(document.createTextNode(ending ? T.end : T.choose));
        ov.appendChild(q);
        var row = mk('div', 'indy-btns');
        if (ending) {
          var rs = mk('button', 'indy-choice indy-primary', T.restart); rs.type = 'button';
          rs.addEventListener('click', function () { go(data.entry); });
          row.appendChild(rs);
          if (sc.video) {
            var rp = mk('button', 'indy-choice', T.replay); rp.type = 'button';
            rp.addEventListener('click', function () { go(current); });
            row.appendChild(rp);
          }
          cb('onEnd', parseInt(current, 10));
        } else {
          sc.branches.forEach(function (br) {
            var b = mk('button', 'indy-choice', br.label); b.type = 'button';
            b.addEventListener('click', function () { go(br.target); });   // ฉากใหม่เริ่มที่ 0
            row.appendChild(b);
          });
        }
        ov.appendChild(row);
        root.appendChild(ov);
        var first = ov.querySelector('button'); if (first) { try { first.focus({ preventScroll: true }); } catch (e) {} }
      }

      if (sc.video && isIframeSrc(sc.video)) {
        var src = sc.video;
        if (hideCtl) src += (src.indexOf('?') >= 0 ? '&' : '?') + 'controls=false';
        var f = mk('iframe'); f.src = src; f.allowFullscreen = true;
        f.setAttribute('allow', 'accelerometer; autoplay; encrypted-media; picture-in-picture');
        stage.appendChild(f);
        showChoices();
      } else if (sc.video) {
        var v = mk('video'); video = v;
        v.controls = !hideCtl; v.src = sc.video; v.preload = 'metadata'; v.autoplay = true;
        v.setAttribute('playsinline', '');
        v.setAttribute('controlsList', 'nodownload noplaybackrate');
        v.disablePictureInPicture = true;
        v.addEventListener('contextmenu', function (e) { e.preventDefault(); });
        stage.appendChild(v);

        // ปุ่มเล่นกลางจอ (autoplay ถูกบล็อก / ไม่มีแถบควบคุม)
        var play = mk('button', 'indy-play'); play.type = 'button'; play.setAttribute('aria-label', '\u0e40\u0e25\u0e48\u0e19');
        play.appendChild(mk('span')); play.style.display = 'none';
        play.addEventListener('click', function () { var p = v.play(); if (p && p.catch) p.catch(function () {}); });
        stage.appendChild(play);
        var hint = mk('div', 'indy-hint', T.noSeek); stage.appendChild(hint);

        var maxW = 0;
        // ห้ามเลื่อนข้ามไปข้างหน้า (เสมอ) — ดึงกลับเมื่อพยายามกระโดด
        v.addEventListener('seeking', function () { if (v.currentTime > maxW + 1.5) v.currentTime = maxW; });
        v.addEventListener('timeupdate', function () {
          if (!v.seeking && v.currentTime > maxW && v.currentTime <= maxW + 2) maxW = v.currentTime;
        });
        v.addEventListener('play', function () { play.style.display = 'none'; });
        v.addEventListener('pause', function () { if (!v.ended && hideCtl) play.style.display = ''; });
        v.addEventListener('ended', function () { play.style.display = 'none'; showChoices(); });
        v.addEventListener('error', function () { showChoices(); });
        var pr = v.play();
        if (pr && pr.catch) pr.catch(function () { if (video === v) play.style.display = ''; });
      } else {
        var nv = mk('div', 'indy-novideo'); nv.appendChild(mk('i', 'fi fi-rr-film')); nv.appendChild(mk('div', '', T.noVideo));
        stage.appendChild(nv);
        showChoices();
      }
    }

    // ดูต่อ: ฉากที่ค้างไว้ (ถ้ายังอยู่) มิฉะนั้นเริ่มที่ entry
    var start = (opts.resumeScene && scene(opts.resumeScene)) ? opts.resumeScene : data.entry;
    go(start);

    return {
      go: go,
      restart: function () { go(data.entry); },
      current: function () { return current === null ? null : parseInt(current, 10); },
      destroy: function () { dead = true; stopVideo(); clear(el); }
    };
  }

  w.IndyPlayer = { mount: mount, labels: T };
})(window);
