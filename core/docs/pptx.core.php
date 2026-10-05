<?php
// [aleanor_cloud] พอร์ตจาก aleanor_ai/core/pptx.core.php — แก้เฉพาะพาธรูป (uploads/docs/) และชื่อฟังก์ชันพาธฐาน/ภาษา
// ============================================================
// เขียนไฟล์ .pptx สำหรับ Aleanor Present
// .pptx = ZIP + XML (PresentationML) — ประกอบเองด้วย ZipArchive ไม่ต้องใช้ไลบรารีนอก
// ขนาดสไลด์ 16:9 (12192000 × 6858000 EMU)
// ============================================================
if(!function_exists('pptxExport')){

define('PPTX_W', 12192000);
define('PPTX_H', 6858000);
define('PPTX_NS_R', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

function pptxEsc($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8'); }
function pptxHex($c){ $c = ltrim((string)$c, '#'); return strlen($c) === 6 ? strtoupper($c) : '1E293B'; }

/** กล่องข้อความหนึ่งกล่อง */
function pptxTextBox($id, $name, $x, $y, $w, $h, $paras, $align = 'l'){
    $body = '';
    foreach($paras as $p){
        $txt  = $p['t'] ?? '';
        $sz   = (int)($p['sz'] ?? 2000);
        $b    = !empty($p['b']) ? ' b="1"' : '';
        $col  = pptxHex($p['c'] ?? '1E293B');
        $bul  = !empty($p['bullet']);
        $al   = $p['a'] ?? $align;
        $body .= '<a:p><a:pPr algn="'.$al.'"'.($bul ? ' marL="285750" indent="-285750"' : '').'>'.
                 ($bul ? '<a:buChar char="&#8226;"/>' : '<a:buNone/>').'</a:pPr>'.
                 ($txt === '' ? '' :
                   '<a:r><a:rPr lang="th-TH" sz="'.$sz.'"'.$b.' dirty="0"><a:solidFill><a:srgbClr val="'.$col.'"/></a:solidFill>'.
                   '<a:latin typeface="Sarabun"/><a:cs typeface="Sarabun"/></a:rPr>'.
                   '<a:t>'.pptxEsc($txt).'</a:t></a:r>').
                 '</a:p>';
    }
    if($body === '') $body = '<a:p><a:endParaRPr lang="th-TH"/></a:p>';
    return '<p:sp><p:nvSpPr><p:cNvPr id="'.$id.'" name="'.pptxEsc($name).'"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'.
      '<p:spPr><a:xfrm><a:off x="'.(int)$x.'" y="'.(int)$y.'"/><a:ext cx="'.(int)$w.'" cy="'.(int)$h.'"/></a:xfrm>'.
      '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom><a:noFill/></p:spPr>'.
      '<p:txBody><a:bodyPr wrap="square" rtlCol="0"><a:normAutofit/></a:bodyPr><a:lstStyle/>'.$body.'</p:txBody></p:sp>';
}

/** รูปหนึ่งรูป */
function pptxPic($id, $rid, $x, $y, $w, $h){
    return '<p:pic><p:nvPicPr><p:cNvPr id="'.$id.'" name="Picture '.$id.'"/><p:cNvPicPr/><p:nvPr/></p:nvPicPr>'.
      '<p:blipFill><a:blip r:embed="'.$rid.'"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'.
      '<p:spPr><a:xfrm><a:off x="'.(int)$x.'" y="'.(int)$y.'"/><a:ext cx="'.(int)$w.'" cy="'.(int)$h.'"/></a:xfrm>'.
      '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>';
}

function pptxExport($title, $deckData, $imgRoot = null){
    if($imgRoot === null) $imgRoot = dirname(__DIR__, 2).'/';
    $deck   = presentSanitize($deckData);
    $themes = presentThemes();
    $th     = $themes[$deck['theme']];
    $bg     = pptxHex($th['bg']); $fg = pptxHex($th['fg']); $ac = pptxHex($th['accent']);

    $PAD  = 800000;
    $slideXml = []; $slideRels = []; $slideMedia = [];

    foreach($deck['slides'] as $si => $s){
        $shapes = ''; $id = 2; $rels = ''; $media = [];
        $bul = function($txt, $sz = 1800) use($fg){
            $out = [];
            foreach(presentBullets($txt) as $b) $out[] = ['t'=>$b, 'sz'=>$sz, 'c'=>$fg, 'bullet'=>true];
            return $out;
        };

        if($s['layout'] === 'title'){
            $shapes .= pptxTextBox($id++, 'Title', $PAD, PPTX_H*0.30, PPTX_W - $PAD*2, 1400000,
                        [['t'=>$s['title'], 'sz'=>4000, 'b'=>1, 'c'=>$fg, 'a'=>'ctr']], 'ctr');
            if($s['body'] !== ''){
                $shapes .= pptxTextBox($id++, 'Sub', $PAD, PPTX_H*0.30 + 1500000, PPTX_W - $PAD*2, 900000,
                            [['t'=>$s['body'], 'sz'=>2000, 'c'=>$fg, 'a'=>'ctr']], 'ctr');
            }
        }elseif($s['layout'] === 'two'){
            if($s['title'] !== '') $shapes .= pptxTextBox($id++, 'Head', $PAD, $PAD*0.7, PPTX_W - $PAD*2, 800000,
                                                [['t'=>$s['title'], 'sz'=>2800, 'b'=>1, 'c'=>$ac]]);
            $colW = (PPTX_W - $PAD*2 - 500000) / 2;
            $shapes .= pptxTextBox($id++, 'Left',  $PAD, $PAD*0.7 + 950000, $colW, PPTX_H - $PAD*2, $bul($s['body']));
            $shapes .= pptxTextBox($id++, 'Right', $PAD + $colW + 500000, $PAD*0.7 + 950000, $colW, PPTX_H - $PAD*2, $bul($s['body2']));
        }elseif($s['layout'] === 'blank'){
            $paras = [];
            foreach(preg_split('~\r\n|\n|\r~', $s['body']) as $line) $paras[] = ['t'=>trim($line), 'sz'=>2000, 'c'=>$fg];
            $shapes .= pptxTextBox($id++, 'Free', $PAD, $PAD, PPTX_W - $PAD*2, PPTX_H - $PAD*2, $paras ?: [['t'=>'']]);
        }else{  // content / image
            if($s['title'] !== '') $shapes .= pptxTextBox($id++, 'Head', $PAD, $PAD*0.7, PPTX_W - $PAD*2, 800000,
                                                [['t'=>$s['title'], 'sz'=>2800, 'b'=>1, 'c'=>$ac]]);
            $topY = $PAD*0.7 + 950000;
            if($s['img'] !== ''){
                $path = rtrim($imgRoot,'/').'/'.ltrim($s['img'],'/');
                $real = @realpath($path); $rootReal = @realpath(rtrim($imgRoot,'/'));
                if($real && $rootReal && strpos($real, $rootReal) === 0 && is_file($real)){
                    $bin = file_get_contents($real);
                    $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
                    if($ext === 'jpeg') $ext = 'jpg';
                    if(in_array($ext, ['png','jpg','gif','webp'], true) && strlen($bin) > 64){
                        $info = @getimagesizefromstring($bin);
                        $iw = $info ? (int)$info[0] : 800; $ih = $info ? (int)$info[1] : 450;
                        $availW = PPTX_W - $PAD*2;
                        $availH = PPTX_H - $topY - $PAD*0.7;
                        if($s['layout'] === 'content'){ $availH = $availH * 0.45; }
                        $scale = min($availW / max(1,$iw), $availH / max(1,$ih));
                        $dw = (int)($iw * $scale); $dh = (int)($ih * $scale);
                        $rid = 'rIdImg'.($si+1);
                        $name = 'image'.($si+1).'.'.$ext;
                        $media[$name] = $bin;
                        $rels .= '<Relationship Id="'.$rid.'" Type="'.PPTX_NS_R.'/image" Target="../media/'.$name.'"/>';
                        $imgY = ($s['layout'] === 'image') ? $topY : (PPTX_H - $PAD*0.7 - $dh);
                        $shapes .= pptxPic($id++, $rid, (PPTX_W - $dw)/2, $imgY, $dw, $dh);
                        if($s['layout'] === 'image' && $s['body'] !== ''){
                            $shapes .= pptxTextBox($id++, 'Cap', $PAD, $imgY + $dh + 150000, PPTX_W - $PAD*2, 500000,
                                        [['t'=>$s['body'], 'sz'=>1500, 'c'=>$fg, 'a'=>'ctr']], 'ctr');
                        }
                    }
                }
            }
            if($s['layout'] !== 'image' && trim($s['body']) !== ''){
                $bodyH = $s['img'] !== '' ? (PPTX_H - $topY) * 0.45 : PPTX_H - $topY - $PAD*0.7;
                $shapes .= pptxTextBox($id++, 'Body', $PAD, $topY, PPTX_W - $PAD*2, $bodyH, $bul($s['body']));
            }
        }

        $notes = $s['notes'];
        $slideXml[]   = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
          '<p:sld xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '.
          'xmlns:r="'.PPTX_NS_R.'" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'.
          '<p:cSld><p:bg><p:bgPr><a:solidFill><a:srgbClr val="'.$bg.'"/></a:solidFill><a:effectLst/></p:bgPr></p:bg>'.
          '<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'.
          '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/>'.
          '<a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'.$shapes.
          '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>';
        $slideRels[]  = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
          '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
          '<Relationship Id="rIdLayout" Type="'.PPTX_NS_R.'/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'.$rels.
          '</Relationships>';
        $slideMedia[] = $media;
        if(!empty($notes)) { /* โน้ตผู้สอนเก็บไว้ในระบบ — ไม่ใส่ใน pptx เพื่อให้ไฟล์เบา */ }
    }

    $n = count($slideXml);
    $sldIdLst = ''; $presRels = '';
    for($i = 1; $i <= $n; $i++){
        $sldIdLst .= '<p:sldId id="'.(255 + $i).'" r:id="rIdSld'.$i.'"/>';
        $presRels .= '<Relationship Id="rIdSld'.$i.'" Type="'.PPTX_NS_R.'/slide" Target="slides/slide'.$i.'.xml"/>';
    }

    $overrides = '';
    for($i = 1; $i <= $n; $i++){
        $overrides .= '<Override PartName="/ppt/slides/slide'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
    }
    $extSeen = [];
    foreach($slideMedia as $m) foreach($m as $name => $bin) $extSeen[strtolower(pathinfo($name, PATHINFO_EXTENSION))] = true;
    $defaults = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'.
                '<Default Extension="xml" ContentType="application/xml"/>';
    foreach(array_keys($extSeen) as $e){
        $ct = ($e === 'png') ? 'image/png' : (($e === 'gif') ? 'image/gif' : (($e === 'webp') ? 'image/webp' : 'image/jpeg'));
        $defaults .= '<Default Extension="'.$e.'" ContentType="'.$ct.'"/>';
    }

    $tmp = tempnam(sys_get_temp_dir(), 'alpptx');
    $zip = new ZipArchive();
    if($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;

    $zip->addFromString('[Content_Types].xml',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.$defaults.
      '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'.
      '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'.
      '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'.
      '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'.
      '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'.
      $overrides.'</Types>');
    $zip->addFromString('_rels/.rels',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
      '<Relationship Id="rId1" Type="'.PPTX_NS_R.'/officeDocument" Target="ppt/presentation.xml"/>'.
      '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'.
      '</Relationships>');
    $zip->addFromString('docProps/core.xml',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '.
      'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'.
      '<dc:title>'.pptxEsc($title).'</dc:title>'.
      '<dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created></cp:coreProperties>');
    $zip->addFromString('ppt/presentation.xml',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<p:presentation xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '.
      'xmlns:r="'.PPTX_NS_R.'" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" saveSubsetFonts="1">'.
      '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rIdMaster"/></p:sldMasterIdLst>'.
      '<p:sldIdLst>'.$sldIdLst.'</p:sldIdLst>'.
      '<p:sldSz cx="'.PPTX_W.'" cy="'.PPTX_H.'"/><p:notesSz cx="'.PPTX_H.'" cy="'.PPTX_W.'"/></p:presentation>');
    $zip->addFromString('ppt/_rels/presentation.xml.rels',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
      '<Relationship Id="rIdMaster" Type="'.PPTX_NS_R.'/slideMaster" Target="slideMasters/slideMaster1.xml"/>'.
      '<Relationship Id="rIdTheme" Type="'.PPTX_NS_R.'/theme" Target="theme/theme1.xml"/>'.$presRels.
      '</Relationships>');
    $zip->addFromString('ppt/slideMasters/slideMaster1.xml', pptxMasterXml($bg));
    $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
      '<Relationship Id="rIdLayout1" Type="'.PPTX_NS_R.'/slideLayout" Target="../slideLayouts/slideLayout1.xml"/>'.
      '<Relationship Id="rIdTheme" Type="'.PPTX_NS_R.'/theme" Target="../theme/theme1.xml"/>'.
      '</Relationships>');
    $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', pptxLayoutXml());
    $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
      '<Relationship Id="rIdMaster" Type="'.PPTX_NS_R.'/slideMaster" Target="../slideMasters/slideMaster1.xml"/>'.
      '</Relationships>');
    $zip->addFromString('ppt/theme/theme1.xml', pptxThemeXml($ac, $fg, $bg));
    foreach($slideXml as $i => $xml){
        $zip->addFromString('ppt/slides/slide'.($i+1).'.xml', $xml);
        $zip->addFromString('ppt/slides/_rels/slide'.($i+1).'.xml.rels', $slideRels[$i]);
        foreach($slideMedia[$i] as $name => $bin) $zip->addFromString('ppt/media/'.$name, $bin);
    }
    $zip->close();
    $bin = file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}

// ── นำเข้า .pptx ─────────────────────────────────────────────
/**
 * อ่าน .pptx → โครงสไลด์ของ Aleanor Present
 * กติกาเดา: กล่องข้อความที่ฟอนต์ใหญ่สุดในสไลด์ = หัวข้อ ที่เหลือ = เนื้อหา (บรรทัดละบูลเล็ต)
 */
function pptxImport($zipPath, $imgDir = null, $imgRelBase = 'uploads/docs/'){
    if(!is_file($zipPath)) return ['ok'=>false, 'error'=>'ไม่พบไฟล์'];
    $zip = new ZipArchive();
    if($zip->open($zipPath) !== true) return ['ok'=>false, 'error'=>'เปิดไฟล์ไม่ได้ (ไม่ใช่ .pptx?)'];
    if($zip->getFromName('ppt/presentation.xml') === false){
        $zip->close(); return ['ok'=>false, 'error'=>'ไม่ใช่ไฟล์ PowerPoint (.pptx)'];
    }
    if($imgDir === null) $imgDir = dirname(__DIR__, 2).'/uploads/docs/';
    if(!is_dir($imgDir)) @mkdir($imgDir, 0755, true);

    // ลำดับสไลด์จาก presentation.xml + rels
    $order = [];
    $pres = $zip->getFromName('ppt/presentation.xml');
    $prel = $zip->getFromName('ppt/_rels/presentation.xml.rels');
    if($pres !== false && $prel !== false){
        $d1 = new DOMDocument(); $d2 = new DOMDocument();
        if(@$d1->loadXML($pres) && @$d2->loadXML($prel)){
            $map = [];
            foreach($d2->getElementsByTagName('Relationship') as $rel) $map[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
            foreach($d1->getElementsByTagName('sldId') as $sld){
                $rid = $sld->getAttributeNS(PPTX_NS_R, 'id');
                if(isset($map[$rid])){
                    $t = ltrim(str_replace('../', '', $map[$rid]), '/');
                    $order[] = (strpos($t, 'ppt/') === 0) ? $t : 'ppt/'.$t;
                }
            }
        }
    }
    if(!$order){
        for($i = 0; $i < $zip->numFiles; $i++){
            $n = $zip->getNameIndex($i);
            if(preg_match('~^ppt/slides/slide\d+\.xml$~', $n)) $order[] = $n;
        }
        natsort($order); $order = array_values($order);
    }

    $slides = [];
    foreach($order as $path){
        $xml = $zip->getFromName($path);
        if($xml === false) continue;
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = @$doc->loadXML($xml);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        if(!$ok) continue;

        // แผนที่รูปของสไลด์นี้
        $relPath = preg_replace('~/slides/([^/]+)$~', '/slides/_rels/$1.rels', $path);
        $rmap = [];
        $rx = $zip->getFromName($relPath);
        if($rx !== false){
            $dr = new DOMDocument();
            if(@$dr->loadXML($rx)){
                foreach($dr->getElementsByTagName('Relationship') as $rel) $rmap[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
            }
        }

        $boxes = [];
        foreach($doc->getElementsByTagName('sp') as $sp){
            $paras = []; $maxSz = 0;
            // ตำแหน่งกล่อง — ใช้เดาว่าเป็นสองคอลัมน์ไหม
            $bx = null; $by = null;
            foreach($sp->getElementsByTagName('off') as $off){ $bx = (int)$off->getAttribute('x'); $by = (int)$off->getAttribute('y'); break; }
            foreach($sp->getElementsByTagName('p') as $pEl){
                if($pEl->namespaceURI !== 'http://schemas.openxmlformats.org/drawingml/2006/main') continue;
                $line = '';
                foreach($pEl->getElementsByTagName('t') as $t) $line .= $t->textContent;
                foreach($pEl->getElementsByTagName('rPr') as $rPr){
                    $sz = (int)$rPr->getAttribute('sz');
                    if($sz > $maxSz) $maxSz = $sz;
                }
                $line = trim(preg_replace('~\s+~u', ' ', $line));
                if($line !== '') $paras[] = $line;
            }
            if($paras) $boxes[] = ['sz'=>$maxSz, 'lines'=>$paras, 'x'=>$bx, 'y'=>$by];
        }

        // รูปแรกของสไลด์
        $img = '';
        foreach($doc->getElementsByTagName('blip') as $blip){
            $rid = $blip->getAttributeNS(PPTX_NS_R, 'embed');
            if(!$rid || !isset($rmap[$rid])) continue;
            $t = ltrim(str_replace('../', '', $rmap[$rid]), '/');
            $target = (strpos($t, 'ppt/') === 0) ? $t : 'ppt/'.$t;
            $bin = $zip->getFromName($target);
            if($bin === false || strlen($bin) < 64) continue;
            $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
            if(!in_array($ext, ['png','jpg','jpeg','gif','webp'], true)) $ext = 'png';
            $name = md5($path.$rid.microtime(true).rand()).'.'.$ext;
            if(@file_put_contents($imgDir.$name, $bin) !== false){ $img = $imgRelBase.$name; }
            break;
        }

        // แยกหัวข้อกับเนื้อหา — กล่องที่ฟอนต์ใหญ่สุดถือเป็นหัวข้อ
        $title = ''; $body = []; $body2 = ''; $titleSz = 0;
        $rest = [];
        if($boxes){
            $ti = 0; $best = -1;
            foreach($boxes as $i => $b){ if($b['sz'] > $best){ $best = $b['sz']; $ti = $i; } }
            $title   = $boxes[$ti]['lines'][0];
            $titleSz = $boxes[$ti]['sz'];
            if(count($boxes[$ti]['lines']) > 1) $body = array_merge($body, array_slice($boxes[$ti]['lines'], 1));
            foreach($boxes as $i => $b){ if($i !== $ti) $rest[] = $b; }
        }

        // สองกล่องวางข้างกัน (x ต่างกันเกิน 1 ใน 4 ของความกว้างสไลด์, y ใกล้กัน) = สองคอลัมน์
        $isTwo = false;
        if(count($rest) === 2 && $rest[0]['x'] !== null && $rest[1]['x'] !== null){
            $dx = abs($rest[0]['x'] - $rest[1]['x']);
            $dy = abs((int)$rest[0]['y'] - (int)$rest[1]['y']);
            if($dx > PPTX_W / 4 && $dy < PPTX_H / 6) $isTwo = true;
        }
        if($isTwo){
            $left  = ($rest[0]['x'] <= $rest[1]['x']) ? $rest[0] : $rest[1];
            $right = ($rest[0]['x'] <= $rest[1]['x']) ? $rest[1] : $rest[0];
            $body  = array_merge($body, $left['lines']);
            $body2 = implode("\n", $right['lines']);
        }else{
            foreach($rest as $b) $body = array_merge($body, $b['lines']);
        }

        if($isTwo)                       $layout = 'two';
        elseif($img !== '' && !$body)    $layout = 'image';
        elseif($img !== '')              $layout = 'content';
        elseif($titleSz >= 3200 && count($body) <= 2) $layout = 'title';   // ฟอนต์ใหญ่ + ข้อความน้อย = หน้าปก
        elseif($title !== '' && !$body)  $layout = 'title';
        else                             $layout = 'content';

        $slides[] = [
            'layout' => $layout,
            'title'  => mb_substr($title, 0, 300),
            'body'   => mb_substr(implode("\n", $body), 0, 4000),
            'body2'  => mb_substr($body2, 0, 4000),
            'img'    => $img,
            'notes'  => '',
        ];
        if(count($slides) >= 200) break;
    }
    $zip->close();
    if(!$slides) return ['ok'=>false, 'error'=>'ไม่พบสไลด์ในไฟล์'];

    $title = '';
    foreach($slides as $s){ if(trim($s['title']) !== ''){ $title = $s['title']; break; } }
    if($title === '') $title = pathinfo($zipPath, PATHINFO_FILENAME);

    return ['ok'=>true, 'title'=>mb_substr($title, 0, 200), 'deck'=>['theme'=>'light', 'slides'=>$slides]];
}

function pptxMasterXml($bg){
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<p:sldMaster xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '.
      'xmlns:r="'.PPTX_NS_R.'" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main">'.
      '<p:cSld><p:bg><p:bgPr><a:solidFill><a:srgbClr val="'.$bg.'"/></a:solidFill><a:effectLst/></p:bgPr></p:bg>'.
      '<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'.
      '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'.
      '</p:spTree></p:cSld><p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" '.
      'accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'.
      '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rIdLayout1"/></p:sldLayoutIdLst>'.
      '<p:txStyles><p:titleStyle/><p:bodyStyle/><p:otherStyle/></p:txStyles></p:sldMaster>';
}
function pptxLayoutXml(){
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<p:sldLayout xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" '.
      'xmlns:r="'.PPTX_NS_R.'" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" type="blank" preserve="1">'.
      '<p:cSld name="Blank"><p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr>'.
      '<p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>'.
      '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>';
}
function pptxThemeXml($accent, $fg, $bg){
    $s = function($v){ return '<a:srgbClr val="'.$v.'"/>'; };
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="Aleanor">'.
      '<a:themeElements><a:clrScheme name="Aleanor">'.
      '<a:dk1>'.$s($fg).'</a:dk1><a:lt1>'.$s($bg).'</a:lt1>'.
      '<a:dk2>'.$s($fg).'</a:dk2><a:lt2>'.$s($bg).'</a:lt2>'.
      '<a:accent1>'.$s($accent).'</a:accent1><a:accent2>'.$s($accent).'</a:accent2>'.
      '<a:accent3>'.$s($accent).'</a:accent3><a:accent4>'.$s($accent).'</a:accent4>'.
      '<a:accent5>'.$s($accent).'</a:accent5><a:accent6>'.$s($accent).'</a:accent6>'.
      '<a:hlink>'.$s($accent).'</a:hlink><a:folHlink>'.$s($accent).'</a:folHlink></a:clrScheme>'.
      '<a:fontScheme name="Aleanor">'.
      '<a:majorFont><a:latin typeface="Sarabun"/><a:ea typeface=""/><a:cs typeface="Sarabun"/></a:majorFont>'.
      '<a:minorFont><a:latin typeface="Sarabun"/><a:ea typeface=""/><a:cs typeface="Sarabun"/></a:minorFont></a:fontScheme>'.
      '<a:fmtScheme name="Aleanor">'.
      '<a:fillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'.
      '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:fillStyleLst>'.
      '<a:lnStyleLst><a:ln w="6350"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>'.
      '<a:ln w="12700"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>'.
      '<a:ln w="19050"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln></a:lnStyleLst>'.
      '<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle>'.
      '<a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>'.
      '<a:bgFillStyleLst><a:solidFill><a:schemeClr val="phClr"/></a:solidFill>'.
      '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:bgFillStyleLst>'.
      '</a:fmtScheme></a:themeElements><a:objectDefaults/><a:extraClrSchemeLst/></a:theme>';
}

}
