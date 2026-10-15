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
// ตัววาด/ตัวเล่นสไลด์อยู่ที่ core/docs/present/present.js + present.css (ใช้ร่วมกับตัวแก้ไข และพอร์ตไป Aleanor LC ได้ทั้งไฟล์)
// ช่องเพิ่มเติมของชุดสไลด์: accent (สีหลักเอง) · font (ฟอนต์หัวข้อ) · anim (ข้อความเข้ามา) · progress · autoSec
// ช่องเพิ่มเติมของสไลด์: fx (เอฟเฟกต์เฉพาะแผ่น) · step (แสดงทีละข้อ) · bg (สีพื้น) · bgImg (ภาพพื้นหลัง) · video (yt:ID / vimeo:ID)
// ============================================================
if(!function_exists('presentBlank')){

/** ชนิดสไลด์ที่ใช้ได้ — ไม่พึ่งระบบภาษา จึงเรียกจาก ajax/ตัวส่งออกได้ */
function presentLayoutKeys(){
    return ['title'=>'fi-rr-text','content'=>'fi-rr-list','two'=>'fi-rr-columns-3','image'=>'fi-rr-picture',
            'section'=>'fi-rr-bookmark','quote'=>'fi-rr-quote-right','stats'=>'fi-rr-chart-histogram',
            'timeline'=>'fi-rr-time-forward','quiz'=>'fi-rr-interrogation','video'=>'fi-rr-play-alt','blank'=>'fi-rr-square'];
}
/** สำหรับแสดงผล (มีชื่อภาษาไทย/อังกฤษ) */
function presentLayouts(){
    $out = [];
    foreach(presentLayoutKeys() as $k => $icon){
        $out[$k] = ['name'=>presentT('doc.slide_'.$k, $k), 'icon'=>$icon];
    }
    return $out;
}
/** ข้อความตามภาษา (แอปหลักใช้ __() / Aleanor LC ใช้ docs_t()) */
function presentT($key, $fallback = ''){
    if(function_exists('docs_t')) return docs_t($key);
    if(function_exists('__')){ $v = __($key); return $v === $key && $fallback !== '' ? $fallback : $v; }
    return $fallback !== '' ? $fallback : $key;
}
/**
 * ธีม — bg ต้องเป็นสีทึบเสมอ (ไฟล์ .pptx ใช้) · bg2 = ไล่สีไปถึง · font = ฟอนต์หัวข้อ · decor = ลวดลายพื้นหลัง
 */
function presentThemes(){
    return [
        'light'  => ['bg'=>'#ffffff','fg'=>'#1e293b','accent'=>'#6366f1','font'=>'','decor'=>'none'],
        'dark'   => ['bg'=>'#0f172a','fg'=>'#e2e8f0','accent'=>'#818cf8','font'=>'','decor'=>'glow'],
        'indigo' => ['bg'=>'#eef2ff','fg'=>'#312e81','accent'=>'#4f46e5','font'=>'prompt','decor'=>'blobs'],
        'teal'   => ['bg'=>'#ecfeff','fg'=>'#0f766e','accent'=>'#0d9488','font'=>'kanit','decor'=>'dots'],
        'warm'   => ['bg'=>'#fffbeb','fg'=>'#78350f','accent'=>'#d97706','font'=>'mitr','decor'=>'corner'],
        'ocean'  => ['bg'=>'#0284c7','bg2'=>'#1e3a8a','fg'=>'#f0f9ff','accent'=>'#fde047','font'=>'prompt','decor'=>'waves'],
        'sunset' => ['bg'=>'#f97316','bg2'=>'#db2777','fg'=>'#fff7ed','accent'=>'#fef08a','font'=>'kanit','decor'=>'blobs'],
        'forest' => ['bg'=>'#064e3b','bg2'=>'#14532d','fg'=>'#ecfdf5','accent'=>'#4ade80','font'=>'mitr','decor'=>'dots'],
        'candy'  => ['bg'=>'#fdf2f8','bg2'=>'#ede9fe','fg'=>'#4c1d95','accent'=>'#ec4899','font'=>'mali','decor'=>'blobs'],
        'chalk'  => ['bg'=>'#1f3d2c','fg'=>'#f1f5f9','accent'=>'#fde68a','font'=>'itim','body'=>'itim','decor'=>'chalk'],
        'paper'  => ['bg'=>'#fbf7ee','fg'=>'#3f3a32','accent'=>'#b45309','font'=>'pridi','decor'=>'lines'],
        'neon'   => ['bg'=>'#09090b','fg'=>'#fafafa','accent'=>'#22d3ee','font'=>'chakra','decor'=>'grid'],
        'aurora' => ['bg'=>'#0f172a','bg2'=>'#1e1b4b','fg'=>'#f8fafc','accent'=>'#c4b5fd','font'=>'prompt','decor'=>'aurora'],
        'mint'   => ['bg'=>'#f0fdf4','fg'=>'#14532d','accent'=>'#16a34a','font'=>'kanit','decor'=>'corner'],
        'royal'  => ['bg'=>'#2e1065','bg2'=>'#581c87','fg'=>'#faf5ff','accent'=>'#fbbf24','font'=>'trirong','decor'=>'glow'],
        'kids'   => ['bg'=>'#fef9c3','fg'=>'#1e3a8a','accent'=>'#f43f5e','font'=>'mali','body'=>'mali','decor'=>'confetti'],
        'mono'   => ['bg'=>'#fafafa','fg'=>'#111827','accent'=>'#111827','font'=>'anuphan','decor'=>'none'],
    ];
}
/** ฟอนต์หัวข้อที่เลือกได้ (Google Fonts ภาษาไทย) */
function presentFonts(){ return ['sarabun','prompt','kanit','mitr','itim','mali','chakra','pridi','trirong','anuphan','bai','k2d']; }

/** เอฟเฟกต์เปลี่ยนสไลด์ที่ใช้ได้ */
function presentFx(){ return ['none','fade','slide','rise','zoom','flip','cube','blur','swirl','wipe','curtain','bounce']; }
/** การเข้ามาของข้อความในสไลด์ */
function presentAnims(){ return ['none','rise','fade','pop','left','zoom','type']; }

function presentBlank(){
    return ['theme'=>'light', 'fx'=>'fade', 'anim'=>'rise', 'accent'=>'', 'font'=>'', 'progress'=>1, 'autoSec'=>6, 'slides'=>[
        ['layout'=>'title','title'=>'','body'=>'','notes'=>''],
    ]];
}

/** ลิงก์ YouTube / Vimeo → "yt:ID" / "vimeo:ID" (เก็บแค่รหัส ไม่เก็บ URL ดิบ) */
function presentVideoId($v){
    $v = trim((string)$v);
    if($v === '') return '';
    if(preg_match('~^(yt|vimeo):([A-Za-z0-9_-]{4,20})$~', $v)) return $v;
    if(preg_match('~(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:.*&)?v=|embed/|live/|shorts/|v/)|youtu\.be/)([A-Za-z0-9_-]{6,20})~', $v, $m)) return 'yt:'.$m[1];
    if(preg_match('~vimeo\.com/(?:video/)?([0-9]{4,12})~', $v, $m)) return 'vimeo:'.$m[1];
    return '';
}
function presentColor($v){ $v = trim((string)$v); return preg_match('~^#[0-9a-fA-F]{6}$~', $v) ? strtolower($v) : ''; }

/** อ่าน + ทำความสะอาดข้อมูลสไลด์ — เรียกทั้งตอนบันทึกและตอนแสดงผล */
function presentSanitize($data){
    $d = is_string($data) ? json_decode($data, true) : $data;
    if(!is_array($d)) $d = [];
    $themes  = presentThemes();
    $layouts = presentLayoutKeys();
    $theme   = (isset($d['theme']) && isset($themes[$d['theme']])) ? $d['theme'] : 'light';
    $fx      = (isset($d['fx']) && in_array($d['fx'], presentFx(), true)) ? $d['fx'] : 'fade';
    $anim    = (isset($d['anim']) && in_array($d['anim'], presentAnims(), true)) ? $d['anim'] : 'rise';
    $font    = (isset($d['font']) && in_array($d['font'], presentFonts(), true)) ? $d['font'] : '';

    $slides = [];
    $src = (isset($d['slides']) && is_array($d['slides'])) ? $d['slides'] : [];
    foreach($src as $s){
        if(!is_array($s)) continue;
        $layout = (isset($s['layout']) && isset($layouts[$s['layout']])) ? $s['layout'] : 'content';
        $img = '';
        if(!empty($s['img'])){
            $v = trim((string)$s['img']);
            // อนุญาตเฉพาะไฟล์ในระบบ กัน path หลุดออกนอกโฟลเดอร์
            if(preg_match(presentImgPattern(), $v) && strpos($v, '..') === false) $img = $v;
        }
        // ตำแหน่ง/ขนาดรูปแบบวางอิสระ (คิดเป็น % ของสไลด์ เพื่อให้ย่อขยายตามจอ)
        $pos = null;
        if(!empty($s['imgPos']) && is_array($s['imgPos'])){
            $px = isset($s['imgPos']['x']) ? (float)$s['imgPos']['x'] : 50;
            $py = isset($s['imgPos']['y']) ? (float)$s['imgPos']['y'] : 50;
            $pw = isset($s['imgPos']['w']) ? (float)$s['imgPos']['w'] : 45;
            $pos = ['x'=>max(0, min(100, $px)), 'y'=>max(0, min(100, $py)), 'w'=>max(5, min(100, $pw))];
        }
        $imgW = !empty($s['imgW']) ? max(5, min(100, (float)$s['imgW'])) : 0;      // 0 = ขนาดอัตโนมัติ (ห้ามบีบเป็น 5% ตอน normalize ซ้ำ)
        $bgImg = '';
        if(!empty($s['bgImg'])){
            $v = trim((string)$s['bgImg']);
            if(preg_match(presentImgPattern(), $v) && strpos($v, '..') === false) $bgImg = $v;
        }
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
            'fx'     => (isset($s['fx']) && in_array($s['fx'], presentFx(), true)) ? $s['fx'] : '',
            'step'   => !empty($s['step']) ? 1 : 0,
            'bg'     => presentColor($s['bg'] ?? ''),
            'bgImg'  => $bgImg,
            'video'  => presentVideoId($s['video'] ?? ''),
        ];
        if(count($slides) >= 200) break;
    }
    if(!$slides) $slides = presentBlank()['slides'];
    return ['theme'=>$theme, 'fx'=>$fx, 'anim'=>$anim, 'accent'=>presentColor($d['accent'] ?? ''), 'font'=>$font,
            'progress'=>(isset($d['progress']) && !$d['progress']) ? 0 : 1,
            'autoSec'=>isset($d['autoSec']) ? max(2, min(120, (int)$d['autoSec'])) : 6, 'slides'=>$slides];
}

/** โฟลเดอร์รูปที่ยอมให้สไลด์อ้างถึง */
function presentImgPattern(){ return '~^uploads/docs/[A-Za-z0-9_./-]+$~'; }

/**
 * แปลงสไลด์ชนิดใหม่เป็นแบบพื้นฐาน (title/content/two/image/blank) — ใช้ตอนส่งออก .pptx
 * โน้ตผู้สอนเก็บเฉลยแบบทดสอบ/ลิงก์วิดีโอไว้ให้
 */
function presentFlatten($deck){
    foreach($deck['slides'] as &$s){
        $L = $s['layout'];
        $pairs = function($t, $sep){ $o = []; foreach(presentBullets($t) as $l){ $p = array_map('trim', explode('|', $l, 2)); $o[] = isset($p[1]) && $p[1] !== '' ? $p[0].$sep.$p[1] : $p[0]; } return implode("\n", $o); };
        if($L === 'section'){ $s['layout'] = 'title'; }
        elseif($L === 'quote'){ $s['layout'] = 'title'; $s['title'] = '“'.$s['body'].'”'; $s['body'] = $s['body2'] !== '' ? '— '.$s['body2'] : ''; }
        elseif($L === 'stats'){ $s['layout'] = 'content'; $s['body'] = $pairs($s['body'], ' — '); }
        elseif($L === 'timeline'){ $s['layout'] = 'content'; $s['body'] = $pairs($s['body'], ': '); }
        elseif($L === 'quiz'){
            $ch = []; $ans = []; $abc = 'ABCDEF';
            foreach(array_slice(presentBullets($s['body']), 0, 6) as $k => $l){
                $ok = substr($l, 0, 1) === '*'; $t = $ok ? trim(substr($l, 1)) : $l;
                $ch[] = $abc[$k].'. '.$t; if($ok) $ans[] = $abc[$k];
            }
            $s['layout'] = 'content'; $s['body'] = implode("\n", $ch);
            $s['notes'] = trim(($ans ? 'เฉลย: '.implode(', ', $ans) : '').($s['body2'] !== '' ? "\n".$s['body2'] : '')."\n".$s['notes']);
        }
        elseif($L === 'video'){
            $url = strpos($s['video'], 'yt:') === 0 ? 'https://youtu.be/'.substr($s['video'], 3) : (strpos($s['video'], 'vimeo:') === 0 ? 'https://vimeo.com/'.substr($s['video'], 6) : '');
            $s['layout'] = 'content'; $s['body'] = trim($s['body']."\n".$url);
        }
    }
    unset($s);
    return $deck;
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

/** ป้ายข้อความของตัวเล่น */
function presentPlayerLabels(){
    $o = [];
    foreach(['prev'=>'doc.pv_prev','next'=>'doc.pv_next','full'=>'doc.pv_full','overview'=>'doc.pv_overview','auto'=>'doc.pv_auto',
             'laser'=>'doc.pv_laser','confetti'=>'doc.pv_confetti'] as $k => $key){
        $v = presentT($key, '');
        if($v !== '' && $v !== $key) $o[$k] = $v;
    }
    return $o;
}

/**
 * แสดงสไลด์ทั้งชุด (หน้าอ่าน / ในบทเรียน) — ตัวเล่นสร้างจาก present.js
 * ข้อความทั้งหมดอยู่ใน <noscript> ด้วย เผื่อเบราว์เซอร์ปิด JavaScript
 */
function presentRenderHtml($data, $idPrefix = 'pv'){
    $p  = presentSanitize($data);
    $base = function_exists('app_base') ? app_base() : (function_exists('appBase') ? appBase() : '/');
    $id = preg_replace('~[^A-Za-z0-9_-]~','', $idPrefix);
    $cfg = ['deck'=>$p, 'themes'=>presentThemes(), 'base'=>$base, 'lbl'=>presentPlayerLabels()];
    $h  = '<div class="pv-deck" id="'.$id.'" data-pv="'.htmlspecialchars(json_encode($cfg, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), ENT_QUOTES).'">';
    $h .= '<noscript><div style="padding:1rem">';
    foreach($p['slides'] as $s){
        if($s['title'] !== '') $h .= '<h3>'.htmlspecialchars($s['title']).'</h3>';
        foreach(presentBullets($s['body']) as $b) $h .= '<p>'.htmlspecialchars(ltrim($b, '*')).'</p>';
    }
    $h .= '</div></noscript></div>';
    return $h;
}

/** CSS ของสไลด์ — ใช้ร่วมกันทั้งตัวแก้ไข หน้าอ่าน และในบทเรียน */
function presentCss(){
    static $css = null;
    if($css === null) $css = (string)@file_get_contents(__DIR__.'/present/present.css');
    return $css;
}

/** JS ของตัววาด/ตัวเล่นสไลด์ (แทรกในแท็ก <script>) */
function presentJs(){
    static $js = null;
    if($js === null) $js = str_replace('</script', '<\/script', (string)@file_get_contents(__DIR__.'/present/present.js'));
    return $js;
}

}
