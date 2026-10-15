<?php
// ============================================================
// Aleanor Cover — ออกแบบภาพปก (พอร์ตจาก aleanor_ai core/document.core.php ส่วน Cover — ไฟล์เดียวกันทั้ง aleanor_cloud และ aleanor_lc)
// เนื้อหาเก็บเป็น JSON (พื้นหลัง + ชั้นข้อความ/รูป/รูปทรง) → เบราว์เซอร์เรนเดอร์เป็น JPEG (assets/docs/aleanor-cover.js)
// ภาพที่เรนเดอร์ผูกกับเอกสารผ่าน docs_documents.asset / asset_size (นับรวมโควตา · ลบถาวรแล้ว unlink)
// ต่างจากต้นฉบับ: รูปที่ใช้ได้อยู่ใต้ uploads/docs/ · uploads/covers/ · assets/ ของระบบนี้เท่านั้น · ป้ายชื่อขนาดเป็น key ของ docs_t()
// ============================================================
if(!function_exists('coverPresets')){

/** ขนาดภาพตามที่ที่จะเอาไปใช้ในระบบจริง */
function coverPresets(){
    return [
        'course' => ['w'=>1280, 'h'=>720,  'icon'=>'fi-rr-book-alt',              'label'=>'cover.p_course'],
        'path'   => ['w'=>1280, 'h'=>720,  'icon'=>'fi-rr-road',                  'label'=>'cover.p_path'],
        'slide'  => ['w'=>1920, 'h'=>720,  'icon'=>'fi-rr-rectangle-horizontal',  'label'=>'cover.p_slide'],
        'ads'    => ['w'=>1200, 'h'=>400,  'icon'=>'fi-rr-megaphone',             'label'=>'cover.p_ads'],
        'splash' => ['w'=>1600, 'h'=>900,  'icon'=>'fi-rr-flag',                  'label'=>'cover.p_splash'],
        'cert'   => ['w'=>2000, 'h'=>1414, 'icon'=>'fi-rr-diploma',               'label'=>'cover.p_cert'],
        'certp'  => ['w'=>1414, 'h'=>2000, 'icon'=>'fi-rr-diploma',               'label'=>'cover.p_certp'],
        'square' => ['w'=>1080, 'h'=>1080, 'icon'=>'fi-rr-square',                'label'=>'cover.p_square'],
        'custom' => ['w'=>1280, 'h'=>720,  'icon'=>'fi-rr-expand',                'label'=>'cover.p_custom'],
    ];
}

/** ฟอนต์ที่ใช้ได้ (รองรับภาษาไทย) — โหลดจาก Google Fonts */
function coverFonts(){
    return ['Kanit','Prompt','Sarabun','Mitr','Noto Sans Thai','IBM Plex Sans Thai','Bai Jamjuree',
            'Chakra Petch','Pridi','Charm','Itim','Sriracha','Inter','Poppins','Playfair Display'];
}
function coverFontsCssUrl(){
    $fam = [];
    foreach(coverFonts() as $f){
        $w = in_array($f, ['Charm','Itim','Sriracha'], true) ? ':wght@400;700' : ':wght@300;400;600;700;800';
        if(in_array($f, ['Itim','Sriracha'], true)) $w = '';
        if($f === 'Pridi' || $f === 'Mitr') $w = ':wght@300;400;600;700';
        if($f === 'Playfair Display') $w = ':wght@400;700;800';
        $fam[] = 'family='.str_replace(' ', '+', $f).$w;
    }
    return 'https://fonts.googleapis.com/css2?'.implode('&', $fam).'&display=swap';
}

function coverColor($v, $def = '#000000'){
    $v = trim((string)$v);
    return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', $v) ? strtolower($v) : $def;
}
function coverNum($v, $min, $max, $def){
    if(!is_numeric($v)) return $def;
    $v = (float)$v;
    return max($min, min($max, $v));
}

/** พาธรูปที่อนุญาตให้ใช้ในภาพปก (ไฟล์ในระบบเท่านั้น ไม่รับลิงก์ภายนอก) */
function coverSafeSrc($src){
    $src = ltrim(trim((string)$src), '/');
    if($src === '' || strpos($src, '..') !== false || preg_match('~^[a-z][a-z0-9+.-]*:~i', $src)) return '';
    if(!preg_match('~\.(png|jpe?g|gif|webp|svg)$~i', $src)) return '';
    foreach(['uploads/docs/', 'uploads/covers/', 'assets/'] as $ok){
        if(strpos($src, $ok) === 0) return $src;
    }
    return '';
}

/** ตรวจ/ตัดค่าทุกช่องให้อยู่ในขอบเขต — เก็บเฉพาะที่ตัวแก้ไขสร้างได้จริง */
function coverSanitize($content){
    $d = is_string($content) ? json_decode($content, true) : $content;
    if(!is_array($d)) $d = [];
    $P = coverPresets();
    $preset = isset($P[$d['preset'] ?? '']) ? $d['preset'] : 'course';
    $out = [
        'v'      => 1,
        'preset' => $preset,
        'w'      => (int)coverNum($d['w'] ?? $P[$preset]['w'], 200, 4000, $P[$preset]['w']),
        'h'      => (int)coverNum($d['h'] ?? $P[$preset]['h'], 200, 4000, $P[$preset]['h']),
    ];

    $b = is_array($d['bg'] ?? null) ? $d['bg'] : [];
    $out['bg'] = [
        'type'     => in_array($b['type'] ?? '', ['solid','gradient','image'], true) ? $b['type'] : 'gradient',
        'color'    => coverColor($b['color'] ?? '', '#4f46e5'),
        'c1'       => coverColor($b['c1'] ?? '', '#4f46e5'),
        'c2'       => coverColor($b['c2'] ?? '', '#0ea5e9'),
        'angle'    => (int)coverNum($b['angle'] ?? 135, 0, 360, 135),
        'radial'   => !empty($b['radial']),
        'pattern'  => in_array($b['pattern'] ?? '', ['none','dots','grid','lines','waves','circles','triangles','blobs','rings','zigzag'], true) ? $b['pattern'] : 'none',
        'pcolor'   => coverColor($b['pcolor'] ?? '', '#ffffff'),
        'pop'      => coverNum($b['pop'] ?? 0.15, 0, 1, 0.15),
        'pscale'   => coverNum($b['pscale'] ?? 1, 0.3, 4, 1),
        'image'    => coverSafeSrc($b['image'] ?? ''),
        'fit'      => ($b['fit'] ?? '') === 'contain' ? 'contain' : 'cover',
        'bright'   => (int)coverNum($b['bright'] ?? 100, 20, 200, 100),
        'blur'     => (int)coverNum($b['blur'] ?? 0, 0, 40, 0),
        'ovColor'  => coverColor($b['ovColor'] ?? '', '#000000'),
        'ovOp'     => coverNum($b['ovOp'] ?? 0, 0, 1, 0),
    ];

    $fonts = coverFonts();
    $layers = [];
    foreach(array_slice(is_array($d['layers'] ?? null) ? $d['layers'] : [], 0, 80) as $l){
        if(!is_array($l)) continue;
        $type = $l['type'] ?? '';
        if(!in_array($type, ['text','image','shape'], true)) continue;
        $o = [
            'id'     => substr(preg_replace('~[^A-Za-z0-9_-]~', '', (string)($l['id'] ?? '')), 0, 24) ?: substr(md5(uniqid('', true)), 0, 10),
            'type'   => $type,
            'x'      => round(coverNum($l['x'] ?? 0, -8000, 12000, 0), 1),
            'y'      => round(coverNum($l['y'] ?? 0, -8000, 12000, 0), 1),
            'w'      => round(coverNum($l['w'] ?? 300, 4, 12000, 300), 1),
            'h'      => round(coverNum($l['h'] ?? 100, 4, 12000, 100), 1),
            'rot'    => round(coverNum($l['rot'] ?? 0, -360, 360, 0), 1),
            'op'     => coverNum($l['op'] ?? 1, 0, 1, 1),
            'hidden' => !empty($l['hidden']),
            'locked' => !empty($l['locked']),
            'shadow' => !empty($l['shadow']),
            'shColor'=> coverColor($l['shColor'] ?? '', '#00000066'),
            'shBlur' => (int)coverNum($l['shBlur'] ?? 18, 0, 120, 18),
        ];
        if($type === 'text'){
            $o += [
                'text'    => mb_substr(strip_tags((string)($l['text'] ?? '')), 0, 600, 'UTF-8'),
                'font'    => in_array($l['font'] ?? '', $fonts, true) ? $l['font'] : 'Kanit',
                'size'    => (int)coverNum($l['size'] ?? 64, 6, 800, 64),
                'weight'  => (int)coverNum($l['weight'] ?? 700, 100, 900, 700),
                'italic'  => !empty($l['italic']),
                'upper'   => !empty($l['upper']),
                'fitW'    => !empty($l['fitW']),                 // กว้างพอดีข้อความ (ป้าย/รหัสวิชา) — ไม่ตัดบรรทัดเอง
                'color'   => coverColor($l['color'] ?? '', '#ffffff'),
                'align'   => in_array($l['align'] ?? '', ['left','center','right'], true) ? $l['align'] : 'left',
                'lh'      => coverNum($l['lh'] ?? 1.2, 0.6, 3, 1.2),
                'ls'      => coverNum($l['ls'] ?? 0, -20, 80, 0),
                'stroke'  => (int)coverNum($l['stroke'] ?? 0, 0, 40, 0),
                'strokeColor' => coverColor($l['strokeColor'] ?? '', '#000000'),
                'box'     => !empty($l['box']),
                'boxColor'=> coverColor($l['boxColor'] ?? '', '#000000'),
                'boxOp'   => coverNum($l['boxOp'] ?? 0.6, 0, 1, 0.6),
                'boxPad'  => (int)coverNum($l['boxPad'] ?? 18, 0, 300, 18),
                'boxRadius'=> (int)coverNum($l['boxRadius'] ?? 14, 0, 400, 14),
            ];
        }elseif($type === 'image'){
            $src = coverSafeSrc($l['src'] ?? '');
            if($src === '') continue;
            $o += [
                'src'     => $src,
                'fit'     => ($l['fit'] ?? '') === 'contain' ? 'contain' : 'cover',
                'radius'  => (int)coverNum($l['radius'] ?? 0, 0, 4000, 0),
                'border'  => (int)coverNum($l['border'] ?? 0, 0, 80, 0),
                'borderColor' => coverColor($l['borderColor'] ?? '', '#ffffff'),
                'flip'    => !empty($l['flip']),
                'bright'  => (int)coverNum($l['bright'] ?? 100, 20, 200, 100),
                'gray'    => !empty($l['gray']),
            ];
        }else{
            $o += [
                'shape'   => in_array($l['shape'] ?? '', ['rect','circle','triangle','star','line','pill'], true) ? $l['shape'] : 'rect',
                'fill'    => coverColor($l['fill'] ?? '', '#ffffff'),
                'fill2'   => coverColor($l['fill2'] ?? '', ''),
                'noFill'  => !empty($l['noFill']),
                'stroke'  => (int)coverNum($l['stroke'] ?? 0, 0, 80, 0),
                'strokeColor' => coverColor($l['strokeColor'] ?? '', '#ffffff'),
                'radius'  => (int)coverNum($l['radius'] ?? 0, 0, 4000, 0),
            ];
        }
        $layers[] = $o;
    }
    $out['layers'] = $layers;
    return $out;
}

/** ค่าเริ่มต้นของภาพปกใหม่ — ใส่ชื่อเรื่องไว้ให้เลย แก้ต่อได้ทันที */
function coverBlank($preset = 'course', $title = ''){
    $P = coverPresets();
    if(!isset($P[$preset])) $preset = 'course';
    $w = $P[$preset]['w']; $h = $P[$preset]['h'];
    $s = min($w, $h * 1.6) / 1280;                  // สเกลขนาดตัวอักษรตามขนาดภาพ
    $title = trim((string)$title) !== '' ? mb_substr(trim(strip_tags($title)), 0, 120, 'UTF-8') : 'ชื่อเรื่องของคุณ';
    return coverSanitize([
        'preset' => $preset, 'w' => $w, 'h' => $h,
        'bg' => ['type'=>'gradient', 'c1'=>'#4338ca', 'c2'=>'#0ea5e9', 'angle'=>135, 'pattern'=>'circles', 'pcolor'=>'#ffffff', 'pop'=>0.12],
        'layers' => [
            ['id'=>'t1','type'=>'text','text'=>$title,'font'=>'Kanit','size'=>round(88*$s),'weight'=>700,'color'=>'#ffffff',
             'x'=>round($w*0.07),'y'=>round($h*0.34),'w'=>round($w*0.8),'h'=>round(220*$s),'lh'=>1.15,'shadow'=>true,'shColor'=>'#0000004d','shBlur'=>24],
            ['id'=>'t2','type'=>'text','text'=>'คำอธิบายสั้น ๆ','font'=>'Prompt','size'=>round(38*$s),'weight'=>400,'color'=>'#e0e7ff',
             'x'=>round($w*0.07),'y'=>round($h*0.34 + 240*$s),'w'=>round($w*0.7),'h'=>round(60*$s),'lh'=>1.3],
        ],
    ]);
}

}
