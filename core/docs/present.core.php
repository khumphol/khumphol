<?php
// [aleanor_cloud] พอร์ตจาก aleanor_ai/core/present.core.php — แก้เฉพาะพาธรูป (uploads/docs/) และชื่อฟังก์ชันพาธฐาน/ภาษา
// ============================================================
// Aleanor Present — สไลด์นำเสนอ
//
// รูปแบบที่เก็บใน documents.doc_content (JSON):
//   { "theme":"light",
//     "slides":[
//       {"layout":"title","title":"หัวเรื่อง","body":"คำโปรย"},
//       {"layout":"content","title":"หัวข้อ","body":"บรรทัด 1\nบรรทัด 2","notes":"โน้ตผู้สอน"},
//       {"layout":"image","title":"...","img":"uploads/docs/12/a.png"}
//     ] }
//
// body เก็บเป็น "ข้อความล้วน" บรรทัดละหัวข้อ — แสดงผลเป็นบูลเล็ต
// ============================================================
if(!function_exists('presentBlank')){

/** ชนิดสไลด์ที่ใช้ได้ — ไม่พึ่งระบบภาษา จึงเรียกจาก ajax/ตัวส่งออกได้ */
function presentLayoutKeys(){
    return ['title'=>'fi-rr-text','content'=>'fi-rr-list','two'=>'fi-rr-columns-3',
            'image'=>'fi-rr-picture','blank'=>'fi-rr-square'];
}
/** สำหรับแสดงผล (มีชื่อภาษาไทย/อังกฤษ) */
function presentLayouts(){
    $out = [];
    foreach(presentLayoutKeys() as $k => $icon){
        $out[$k] = ['name'=>(function_exists('docs_t') ? docs_t('doc.slide_'.$k) : $k), 'icon'=>$icon];
    }
    return $out;
}
function presentThemes(){
    return [
        'light'  => ['bg'=>'#ffffff','fg'=>'#1e293b','accent'=>'#6366f1'],
        'dark'   => ['bg'=>'#0f172a','fg'=>'#e2e8f0','accent'=>'#818cf8'],
        'indigo' => ['bg'=>'#eef2ff','fg'=>'#312e81','accent'=>'#4f46e5'],
        'teal'   => ['bg'=>'#ecfeff','fg'=>'#0f766e','accent'=>'#0d9488'],
        'warm'   => ['bg'=>'#fffbeb','fg'=>'#78350f','accent'=>'#d97706'],
    ];
}

/** เอฟเฟกต์เปลี่ยนสไลด์ที่ใช้ได้ */
function presentFx(){ return ['none','fade','slide','zoom','flip']; }

function presentBlank(){
    return ['theme'=>'light', 'fx'=>'fade', 'slides'=>[
        ['layout'=>'title','title'=>'','body'=>'','notes'=>''],
    ]];
}

/** อ่าน + ทำความสะอาดข้อมูลสไลด์ — เรียกทั้งตอนบันทึกและตอนแสดงผล */
function presentSanitize($data){
    $d = is_string($data) ? json_decode($data, true) : $data;
    if(!is_array($d)) $d = [];
    $themes  = presentThemes();
    $layouts = presentLayoutKeys();
    $theme   = (isset($d['theme']) && isset($themes[$d['theme']])) ? $d['theme'] : 'light';
    $fx      = (isset($d['fx']) && in_array($d['fx'], presentFx(), true)) ? $d['fx'] : 'fade';

    $slides = [];
    $src = (isset($d['slides']) && is_array($d['slides'])) ? $d['slides'] : [];
    foreach($src as $s){
        if(!is_array($s)) continue;
        $layout = (isset($s['layout']) && isset($layouts[$s['layout']])) ? $s['layout'] : 'content';
        $img = '';
        if(!empty($s['img'])){
            $v = trim((string)$s['img']);
            // อนุญาตเฉพาะไฟล์ในระบบ กัน path หลุดออกนอกโฟลเดอร์
            if(preg_match('~^uploads/docs/[A-Za-z0-9_./-]+$~', $v) && strpos($v, '..') === false) $img = $v;
        }
        // ตำแหน่ง/ขนาดรูปแบบวางอิสระ (คิดเป็น % ของสไลด์ เพื่อให้ย่อขยายตามจอ)
        $pos = null;
        if(!empty($s['imgPos']) && is_array($s['imgPos'])){
            $px = isset($s['imgPos']['x']) ? (float)$s['imgPos']['x'] : 50;
            $py = isset($s['imgPos']['y']) ? (float)$s['imgPos']['y'] : 50;
            $pw = isset($s['imgPos']['w']) ? (float)$s['imgPos']['w'] : 45;
            $pos = ['x'=>max(0, min(100, $px)), 'y'=>max(0, min(100, $py)), 'w'=>max(5, min(100, $pw))];
        }
        $imgW = isset($s['imgW']) ? max(5, min(100, (float)$s['imgW'])) : 0;
        $slides[] = [
            'layout' => $layout,
            'imgPos' => $pos,
            'imgW'   => $imgW,
            'imgRound' => !empty($s['imgRound']) ? 1 : 0,
            'title'  => mb_substr(trim(strip_tags((string)($s['title'] ?? ''))), 0, 300),
            'body'   => mb_substr(trim(strip_tags((string)($s['body']  ?? ''))), 0, 4000),
            'body2'  => mb_substr(trim(strip_tags((string)($s['body2'] ?? ''))), 0, 4000),
            'img'    => $img,
            'notes'  => mb_substr(trim(strip_tags((string)($s['notes'] ?? ''))), 0, 3000),
        ];
        if(count($slides) >= 200) break;
    }
    if(!$slides) $slides = presentBlank()['slides'];
    return ['theme'=>$theme, 'fx'=>$fx, 'slides'=>$slides];
}

/** จำนวนสไลด์ (ใช้โชว์ในรายการ) */
function presentCount($data){
    $p = presentSanitize($data);
    return count($p['slides']);
}

/** แปลง body เป็นรายการบูลเล็ต */
function presentBullets($body){
    $lines = preg_split('~\r\n|\n|\r~', (string)$body);
    $out = [];
    foreach($lines as $l){ $l = trim($l); if($l !== '') $out[] = $l; }
    return $out;
}

/**
 * แสดงสไลด์ทั้งชุดเป็น HTML (หน้าอ่าน / ในบทเรียน)
 * ใส่ปุ่มเลื่อนและโหมดเต็มจอมาให้ในตัว
 */
function presentRenderHtml($data, $idPrefix = 'pv'){
    $p  = presentSanitize($data);
    $th = presentThemes()[$p['theme']];
    $base = function_exists('app_base') ? app_base() : (function_exists('appBase') ? appBase() : '/');
    $id = preg_replace('~[^A-Za-z0-9_-]~','', $idPrefix);

    $h  = '<div class="pv-deck" id="'.$id.'" data-fx="'.htmlspecialchars($p['fx']).'" style="--pv-bg:'.$th['bg'].';--pv-fg:'.$th['fg'].';--pv-ac:'.$th['accent'].'">';
    $h .= '<div class="pv-stage">';
    foreach($p['slides'] as $i => $s){
        $h .= '<section class="pv-slide pv-'.$s['layout'].'"'.($i === 0 ? '' : ' hidden').' data-i="'.$i.'">';
        if($s['layout'] === 'title'){
            $h .= '<h2 class="pv-h1">'.htmlspecialchars($s['title']).'</h2>';
            if($s['body'] !== '') $h .= '<p class="pv-lead">'.nl2br(htmlspecialchars($s['body'])).'</p>';
        }elseif($s['layout'] === 'image'){
            if($s['title'] !== '') $h .= '<h3 class="pv-h2">'.htmlspecialchars($s['title']).'</h3>';
            if($s['img'] !== '')   $h .= presentImgHtml($s, $base);
            if($s['body'] !== '')  $h .= '<p class="pv-cap">'.htmlspecialchars($s['body']).'</p>';
        }elseif($s['layout'] === 'two'){
            if($s['title'] !== '') $h .= '<h3 class="pv-h2">'.htmlspecialchars($s['title']).'</h3>';
            $h .= '<div class="pv-cols">';
            foreach([$s['body'], $s['body2']] as $col){
                $h .= '<ul class="pv-ul">';
                foreach(presentBullets($col) as $b) $h .= '<li>'.htmlspecialchars($b).'</li>';
                $h .= '</ul>';
            }
            $h .= '</div>';
        }elseif($s['layout'] === 'blank'){
            if($s['body'] !== '') $h .= '<div class="pv-free">'.nl2br(htmlspecialchars($s['body'])).'</div>';
        }else{                                        // content
            if($s['title'] !== '') $h .= '<h3 class="pv-h2">'.htmlspecialchars($s['title']).'</h3>';
            $bl = presentBullets($s['body']);
            if($bl){
                $h .= '<ul class="pv-ul">';
                foreach($bl as $b) $h .= '<li>'.htmlspecialchars($b).'</li>';
                $h .= '</ul>';
            }
            if($s['img'] !== '') $h .= presentImgHtml($s, $base, 'sm');
        }
        $h .= '</section>';
    }
    $h .= '</div>';
    $h .= '<div class="pv-bar">'.
          '<button type="button" class="pv-btn" data-act="prev" aria-label="prev"><i class="fi fi-rr-angle-left"></i></button>'.
          '<span class="pv-num"><b>1</b> / '.count($p['slides']).'</span>'.
          '<button type="button" class="pv-btn" data-act="next" aria-label="next"><i class="fi fi-rr-angle-right"></i></button>'.
          '<button type="button" class="pv-btn pv-full" data-act="full" aria-label="fullscreen"><i class="fi fi-rr-expand"></i></button>'.
          '</div></div>';
    return $h;
}

/** แท็กรูปในสไลด์ — วางตามกริดปกติ หรือวางอิสระตามตำแหน่งที่ลากไว้ */
function presentImgHtml($s, $base, $cls = ''){
    $src = htmlspecialchars($base.ltrim($s['img'],'/'));
    $imgStyle = !empty($s['imgRound']) ? ' style="border-radius:14px"' : '';
    if(!empty($s['imgPos'])){
        $p = $s['imgPos'];
        return '<div class="pv-img free" style="left:'.$p['x'].'%;top:'.$p['y'].'%;width:'.$p['w'].'%">'.
               '<img src="'.$src.'" alt=""'.$imgStyle.'></div>';
    }
    $w = !empty($s['imgW']) ? ' style="width:'.$s['imgW'].'%;margin:0 auto"' : '';
    return '<div class="pv-img'.($cls ? ' '.$cls : '').'"'.$w.'><img src="'.$src.'" alt=""'.$imgStyle.'></div>';
}

/** CSS ของตัวแสดงสไลด์ — ใช้ร่วมกันทั้งหน้าอ่านและในบทเรียน */
function presentCss(){
    return '
  .pv-deck { --pv-bg:#fff; --pv-fg:#1e293b; --pv-ac:#6366f1; border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; background:var(--pv-bg); }
  .pv-stage { position:relative; aspect-ratio:16/9; background:var(--pv-bg); color:var(--pv-fg); }
  .pv-slide { position:absolute; inset:0; padding:6% 7%; display:flex; flex-direction:column; justify-content:center; gap:.7rem; overflow:auto; }
  .pv-slide[hidden] { display:none; }
  .pv-title { align-items:center; text-align:center; }
  .pv-h1 { font-size:clamp(1.4rem,3.6vw,2.6rem); font-weight:800; line-height:1.25; margin:0; color:var(--pv-fg); }
  .pv-h2 { font-size:clamp(1.05rem,2.3vw,1.6rem); font-weight:700; margin:0 0 .4rem; color:var(--pv-ac); }
  .pv-lead { font-size:clamp(.9rem,1.6vw,1.15rem); opacity:.8; margin:0; }
  .pv-ul { margin:0; padding-left:1.3em; display:flex; flex-direction:column; gap:.45em; }
  .pv-ul li { font-size:clamp(.86rem,1.6vw,1.12rem); line-height:1.55; }
  .pv-ul li::marker { color:var(--pv-ac); }
  .pv-cols { display:grid; grid-template-columns:1fr 1fr; gap:1.6rem; }
  .pv-img { display:flex; align-items:center; justify-content:center; min-height:0; flex:1; }
  .pv-img img { max-width:100%; max-height:100%; object-fit:contain; border-radius:10px; }
  .pv-img.sm { flex:0 0 auto; max-height:42%; }
  .pv-img.free { position:absolute; transform:translate(-50%,-50%); flex:none; min-height:0; max-height:none; }
  .pv-img.free img { width:100%; height:auto; max-height:none; }
  /* เอฟเฟกต์เปลี่ยนสไลด์ */
  .pv-deck[data-fx="fade"]  .pv-slide { animation:pvFade .34s ease; }
  .pv-deck[data-fx="slide"] .pv-slide { animation:pvSlide .34s cubic-bezier(.22,.8,.3,1); }
  .pv-deck[data-fx="zoom"]  .pv-slide { animation:pvZoom .34s cubic-bezier(.22,.8,.3,1); }
  .pv-deck[data-fx="flip"]  .pv-slide { animation:pvFlip .44s ease; transform-origin:center; }
  @keyframes pvFade  { from { opacity:0 } to { opacity:1 } }
  @keyframes pvSlide { from { opacity:0; transform:translateX(38px) } to { opacity:1; transform:none } }
  @keyframes pvZoom  { from { opacity:0; transform:scale(.94) } to { opacity:1; transform:none } }
  @keyframes pvFlip  { from { opacity:0; transform:rotateY(24deg) } to { opacity:1; transform:none } }
  @media (prefers-reduced-motion:reduce){ .pv-deck .pv-slide { animation:none !important; } }
  .pv-cap { text-align:center; font-size:.9rem; opacity:.75; margin:0; }
  .pv-free { font-size:clamp(.9rem,1.7vw,1.15rem); line-height:1.7; }
  .pv-bar { display:flex; align-items:center; justify-content:center; gap:.6rem; padding:.55rem; background:var(--card); border-top:1px solid var(--border); }
  .pv-btn { width:34px; height:30px; border-radius:8px; border:1px solid var(--border); background:var(--card); color:var(--text-secondary); cursor:pointer; }
  .pv-btn:hover { background:color-mix(in srgb,var(--primary) 10%,transparent); color:var(--primary); }
  .pv-num { font-size:.82rem; color:var(--text-secondary); min-width:64px; text-align:center; }
  .pv-deck:fullscreen { border:none; border-radius:0; display:flex; flex-direction:column; }
  .pv-deck:fullscreen .pv-stage { flex:1; aspect-ratio:auto; }
  .pv-deck:fullscreen .pv-bar { background:rgba(0,0,0,.35); border:none; }
  .pv-deck:fullscreen .pv-btn { background:transparent; color:#fff; border-color:rgba(255,255,255,.3); }
  .pv-deck:fullscreen .pv-num { color:#fff; }';
}

/** JS ของตัวแสดงสไลด์ (เลื่อนสไลด์ / เต็มจอ / ปุ่มลูกศร) */
function presentJs(){
    return "
(function(){
  function init(deck){
    if(deck.dataset.pvReady) return;
    deck.dataset.pvReady = '1';
    var slides = deck.querySelectorAll('.pv-slide');
    var numEl  = deck.querySelector('.pv-num b');
    var i = 0;
    function show(n){
      if(n < 0) n = 0;
      if(n > slides.length - 1) n = slides.length - 1;
      i = n;
      for(var k = 0; k < slides.length; k++) slides[k].hidden = (k !== i);
      if(numEl) numEl.textContent = i + 1;
    }
    deck.addEventListener('click', function(e){
      var b = e.target.closest ? e.target.closest('[data-act]') : null;
      if(!b) return;
      var a = b.dataset.act;
      if(a === 'next') show(i + 1);
      else if(a === 'prev') show(i - 1);
      else if(a === 'full'){
        if(document.fullscreenElement) document.exitFullscreen();
        else if(deck.requestFullscreen) deck.requestFullscreen();
      }
    });
    deck.setAttribute('tabindex','0');
    deck.addEventListener('keydown', function(e){
      if(e.key === 'ArrowRight' || e.key === 'PageDown' || e.key === ' '){ e.preventDefault(); show(i + 1); }
      else if(e.key === 'ArrowLeft' || e.key === 'PageUp'){ e.preventDefault(); show(i - 1); }
      else if(e.key === 'Home'){ show(0); }
      else if(e.key === 'End'){ show(slides.length - 1); }
    });
    show(0);
  }
  function boot(){ document.querySelectorAll('.pv-deck').forEach(init); }
  if(document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();";
}

}
