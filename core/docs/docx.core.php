<?php
// [aleanor_cloud] พอร์ตจาก aleanor_ai/core/docx.core.php — แก้เฉพาะพาธรูป (uploads/docs/) และชื่อฟังก์ชันพาธฐาน/ภาษา
// ============================================================
// อ่าน/เขียนไฟล์ .docx สำหรับ Aleanor Write
//
// .docx คือไฟล์ ZIP ที่ข้างในเป็น XML (OOXML) — PHP อ่านเขียนได้เองด้วย ZipArchive + DOMDocument
// ไม่ต้องพึ่งไลบรารีภายนอก จึงไม่ต้องลง Composer เพิ่มในระบบ
//
//   docxImport($path)        → ['title'=>..., 'html'=>...]   นำเข้าเป็นเอกสารในระบบ
//   docxExport($title,$html) → ข้อมูลไบนารีของไฟล์ .docx      ส่งออกให้ดาวน์โหลด
// ============================================================
if(!function_exists('docxImport')){

define('DOCX_NS_W', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
define('DOCX_NS_R', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
define('DOCX_NS_A', 'http://schemas.openxmlformats.org/drawingml/2006/main');

// ── นำเข้า ─────────────────────────────────────────────────
/**
 * อ่าน .docx แล้วแปลงเป็น HTML ที่ Aleanor Write ใช้ได้
 * รูปในไฟล์จะถูกแตกออกมาเก็บใน uploads/docs/<user_id>/ แล้วชี้ src มาที่ไฟล์นั้น
 */
function docxImport($zipPath, $imgDir = null, $imgRelBase = 'uploads/docs/'){
    if(!is_file($zipPath)) return ['ok'=>false, 'error'=>'ไม่พบไฟล์'];
    $zip = new ZipArchive();
    if($zip->open($zipPath) !== true) return ['ok'=>false, 'error'=>'เปิดไฟล์ไม่ได้ (ไม่ใช่ .docx?)'];

    $xml = $zip->getFromName('word/document.xml');
    if($xml === false){ $zip->close(); return ['ok'=>false, 'error'=>'ไม่ใช่ไฟล์ Word (.docx)']; }

    if($imgDir === null) $imgDir = dirname(__DIR__, 2).'/uploads/docs/';
    if(!is_dir($imgDir)) @mkdir($imgDir, 0755, true);

    // แผนที่ rId → ไฟล์รูปใน word/media/
    $rels = [];
    $relXml = $zip->getFromName('word/_rels/document.xml.rels');
    if($relXml !== false){
        $rd = new DOMDocument();
        if(@$rd->loadXML($relXml)){
            foreach($rd->getElementsByTagName('Relationship') as $rel){
                $rels[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
            }
        }
    }

    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $loaded = @$doc->loadXML($xml);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    if(!$loaded){ $zip->close(); return ['ok'=>false, 'error'=>'อ่านเนื้อหาในไฟล์ไม่ได้']; }

    $xp = new DOMXPath($doc);
    $xp->registerNamespace('w', DOCX_NS_W);
    $xp->registerNamespace('r', DOCX_NS_R);
    $xp->registerNamespace('a', DOCX_NS_A);

    $body = $xp->query('//w:body')->item(0);
    if(!$body){ $zip->close(); return ['ok'=>false, 'error'=>'ไม่พบเนื้อหาในไฟล์']; }

    $html = '';
    $listOpen = '';                                   // '' | 'ul' | 'ol'
    foreach($body->childNodes as $node){
        if($node->nodeType !== XML_ELEMENT_NODE) continue;
        $name = $node->localName;
        if($name === 'p'){
            $info = docxParaInfo($xp, $node);
            $inner = docxRuns($xp, $node, $rels, $zip, $imgDir, $imgRelBase);
            if($info['list']){
                $tag = $info['list'];
                if($listOpen !== $tag){ if($listOpen) $html .= '</'.$listOpen.'>'; $html .= '<'.$tag.'>'; $listOpen = $tag; }
                $html .= '<li>'.$inner.'</li>';
                continue;
            }
            if($listOpen){ $html .= '</'.$listOpen.'>'; $listOpen = ''; }
            if(trim(strip_tags($inner)) === '' && strpos($inner, '<img') === false) continue;   // ข้ามย่อหน้าว่าง
            $tag   = $info['heading'] ? 'h'.$info['heading'] : 'p';
            $style = $info['align'] ? ' style="text-align:'.$info['align'].'"' : '';
            $html .= '<'.$tag.$style.'>'.$inner.'</'.$tag.'>';
        }elseif($name === 'tbl'){
            if($listOpen){ $html .= '</'.$listOpen.'>'; $listOpen = ''; }
            $html .= docxTable($xp, $node, $rels, $zip, $imgDir, $imgRelBase);
        }
    }
    if($listOpen) $html .= '</'.$listOpen.'>';
    $zip->close();

    // ชื่อเอกสาร: หัวข้อแรก ถ้าไม่มีก็ใช้ชื่อไฟล์
    $title = '';
    if(preg_match('~<h[1-4][^>]*>(.*?)</h[1-4]>~is', $html, $m)) $title = trim(strip_tags($m[1]));
    if($title === '') $title = pathinfo($zipPath, PATHINFO_FILENAME);

    return ['ok'=>true, 'title'=>mb_substr($title, 0, 200), 'html'=>$html];
}

/** อ่านคุณสมบัติของย่อหน้า: หัวข้อระดับไหน / จัดชิด / เป็นรายการไหม */
function docxParaInfo($xp, $p){
    $out = ['heading'=>0, 'align'=>'', 'list'=>''];
    $style = $xp->query('./w:pPr/w:pStyle/@w:val', $p)->item(0);
    if($style){
        $v = strtolower($style->nodeValue);
        if(preg_match('~heading\s*([1-6])~', $v, $m) || preg_match('~^h([1-6])$~', $v, $m)){
            $out['heading'] = min(4, (int)$m[1]);
        }elseif(strpos($v, 'title') !== false){ $out['heading'] = 1; }
        if(strpos($v, 'listparagraph') !== false) $out['list'] = 'ul';
    }
    $jc = $xp->query('./w:pPr/w:jc/@w:val', $p)->item(0);
    if($jc){
        $v = $jc->nodeValue;
        if(in_array($v, ['center','right','both','left'], true)) $out['align'] = ($v === 'both' ? 'justify' : $v);
    }
    if($xp->query('./w:pPr/w:numPr', $p)->length > 0){
        $fmt = $xp->query('./w:pPr/w:numPr/w:numId/@w:val', $p)->item(0);
        $out['list'] = 'ul';                                   // แยก ul/ol ต้องอ่าน numbering.xml — ใช้ ul เป็นค่าตั้งต้น
        if($fmt && (int)$fmt->nodeValue % 2 === 0) $out['list'] = 'ol';
    }
    return $out;
}

/** แปลง run (w:r) ในย่อหน้าเป็น HTML — ตัวหนา/เอียง/ขีดเส้น/ขีดฆ่า/ลิงก์/รูป */
function docxRuns($xp, $p, $rels, $zip, $imgDir, $imgRelBase, $inLink = false){
    $html = '';
    foreach($xp->query('.//w:r | .//w:hyperlink', $p) as $node){
        if($node->localName === 'hyperlink'){
            $rid  = $node->getAttributeNS(DOCX_NS_R, 'id');
            $href = ($rid && isset($rels[$rid])) ? $rels[$rid] : '';
            $txt  = docxRuns($xp, $node, $rels, $zip, $imgDir, $imgRelBase, true);
            $html .= $href ? '<a href="'.htmlspecialchars($href, ENT_QUOTES).'" target="_blank" rel="noopener noreferrer">'.$txt.'</a>' : $txt;
            continue;
        }
        if(!$inLink && $node->parentNode && $node->parentNode->localName === 'hyperlink') continue;   // นับไปแล้วตอนทำลิงก์

        // รูปภาพ
        $blip = $xp->query('.//a:blip/@r:embed', $node)->item(0);
        if($blip){
            $rid = $blip->nodeValue;
            if(isset($rels[$rid])){
                $target = ltrim(str_replace('\\', '/', $rels[$rid]), '/');
                if(strpos($target, 'media/') === 0) $target = 'word/'.$target;
                elseif(strpos($target, 'word/') !== 0) $target = 'word/'.$target;
                $bin = $zip->getFromName($target);
                if($bin !== false && strlen($bin) > 64){
                    $ext = strtolower(pathinfo($target, PATHINFO_EXTENSION));
                    if(!in_array($ext, ['png','jpg','jpeg','gif','webp'], true)) $ext = 'png';
                    $name = md5($rid.$target.microtime(true).rand()).'.'.$ext;
                    if(@file_put_contents($imgDir.$name, $bin) !== false){
                        $html .= '<img src="'.htmlspecialchars($imgRelBase.$name, ENT_QUOTES).'" alt="">';
                    }
                }
            }
            continue;
        }
        if($xp->query('.//w:br', $node)->length > 0 && $xp->query('.//w:t', $node)->length === 0){ $html .= '<br>'; continue; }

        $text = '';
        foreach($xp->query('.//w:t', $node) as $t) $text .= $t->textContent;
        if($text === '') continue;
        $text = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        if($xp->query('./w:rPr/w:b',      $node)->length) $text = '<strong>'.$text.'</strong>';
        if($xp->query('./w:rPr/w:i',      $node)->length) $text = '<em>'.$text.'</em>';
        if($xp->query('./w:rPr/w:u',      $node)->length) $text = '<u>'.$text.'</u>';
        if($xp->query('./w:rPr/w:strike', $node)->length) $text = '<s>'.$text.'</s>';
        $html .= $text;
    }
    return $html;
}

/** แปลงตาราง w:tbl เป็น <table> */
function docxTable($xp, $tbl, $rels, $zip, $imgDir, $imgRelBase){
    $html = '<table>';
    $rowNo = 0;
    foreach($xp->query('./w:tr', $tbl) as $tr){
        $rowNo++;
        $html .= '<tr>';
        foreach($xp->query('./w:tc', $tr) as $tc){
            $cell = '';
            foreach($xp->query('./w:p', $tc) as $p){
                $inner = docxRuns($xp, $p, $rels, $zip, $imgDir, $imgRelBase);
                if(trim(strip_tags($inner)) !== '' || strpos($inner, '<img') !== false) $cell .= ($cell ? '<br>' : '').$inner;
            }
            $span = $xp->query('./w:tcPr/w:gridSpan/@w:val', $tc)->item(0);
            $attr = ($span && (int)$span->nodeValue > 1) ? ' colspan="'.(int)$span->nodeValue.'"' : '';
            $tag  = $rowNo === 1 ? 'th' : 'td';
            $html .= '<'.$tag.$attr.'>'.($cell !== '' ? $cell : '&nbsp;').'</'.$tag.'>';
        }
        $html .= '</tr>';
    }
    return $html.'</table>';
}

// ── ส่งออก ─────────────────────────────────────────────────
function docxEsc($s){ return htmlspecialchars($s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8'); }

/** สร้างไฟล์ .docx จาก HTML ของเอกสาร — คืนค่าเป็นข้อมูลไบนารี */
function docxExport($title, $html, $imgRoot = null){
    if($imgRoot === null) $imgRoot = dirname(__DIR__, 2).'/';
    $doc = new DOMDocument('1.0', 'UTF-8');
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="r">'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors(); libxml_use_internal_errors($prev);
    $root = $doc->getElementById('r');

    $media = [];                                   // rId → ['name'=>..,'bin'=>..,'ext'=>..]
    $links = [];                                   // rId → url (ลิงก์ภายนอก)
    $GLOBALS['__docx_links'] = &$links;
    $bodyXml = $root ? docxBlocks($root, $media, $imgRoot) : '';
    if(trim($bodyXml) === '') $bodyXml = docxPara('', []);

    $documentXml =
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<w:document xmlns:w="'.DOCX_NS_W.'" xmlns:r="'.DOCX_NS_R.'" '.
        'xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" '.
        'xmlns:a="'.DOCX_NS_A.'" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'.
      '<w:body>'.$bodyXml.
      '<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'.
      '<w:pgMar w:top="1134" w:right="1134" w:bottom="1134" w:left="1134" w:header="709" w:footer="709" w:gutter="0"/>'.
      '</w:sectPr></w:body></w:document>';

    $overrides = ''; $relsMedia = '';
    foreach($links as $rid => $url){
        $relsMedia .= '<Relationship Id="'.$rid.'" Type="'.DOCX_NS_R.'/hyperlink" Target="'.docxEsc($url).'" TargetMode="External"/>';
    }
    $extSeen = [];
    foreach($media as $rid => $m){
        $extSeen[$m['ext']] = true;
        $relsMedia .= '<Relationship Id="'.$rid.'" Type="'.DOCX_NS_R.'/image" Target="media/'.$m['name'].'"/>';
    }
    $defaults = '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'.
                '<Default Extension="xml" ContentType="application/xml"/>';
    foreach(array_keys($extSeen) as $e){
        $ct = ($e === 'png') ? 'image/png' : (($e === 'gif') ? 'image/gif' : (($e === 'webp') ? 'image/webp' : 'image/jpeg'));
        $defaults .= '<Default Extension="'.$e.'" ContentType="'.$ct.'"/>';
    }

    $tmp = tempnam(sys_get_temp_dir(), 'aldocx');
    $zip = new ZipArchive();
    if($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    $zip->addFromString('[Content_Types].xml',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.$defaults.
      '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'.
      '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'.
      '<Override PartName="/word/numbering.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.numbering+xml"/>'.
      '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'.
      $overrides.'</Types>');
    $zip->addFromString('_rels/.rels',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
      '<Relationship Id="rId1" Type="'.DOCX_NS_R.'/officeDocument" Target="word/document.xml"/>'.
      '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'.
      '</Relationships>');
    $zip->addFromString('docProps/core.xml',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '.
      'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'.
      '<dc:title>'.docxEsc($title).'</dc:title>'.
      '<dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created>'.
      '</cp:coreProperties>');
    $zip->addFromString('word/_rels/document.xml.rels',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
      '<Relationship Id="rIdStyles" Type="'.DOCX_NS_R.'/styles" Target="styles.xml"/>'.
      '<Relationship Id="rIdNum" Type="'.DOCX_NS_R.'/numbering" Target="numbering.xml"/>'.$relsMedia.
      '</Relationships>');
    $zip->addFromString('word/styles.xml', docxStylesXml());
    $zip->addFromString('word/numbering.xml', docxNumberingXml());
    $zip->addFromString('word/document.xml', $documentXml);
    foreach($media as $m) $zip->addFromString('word/media/'.$m['name'], $m['bin']);
    $zip->close();
    $bin = file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}

/** นิยามรายการของ Word: numId 1 = จุด, numId 2 = ตัวเลข */
function docxNumberingXml(){
    $bullet = '<w:abstractNum w:abstractNumId="0"><w:multiLevelType w:val="hybridMultilevel"/>'.
      '<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="bullet"/><w:lvlText w:val="&#9679;"/>'.
      '<w:lvlJc w:val="left"/><w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr>'.
      '<w:rPr><w:rFonts w:ascii="Arial" w:hAnsi="Arial" w:hint="default"/></w:rPr></w:lvl></w:abstractNum>';
    $number = '<w:abstractNum w:abstractNumId="1"><w:multiLevelType w:val="hybridMultilevel"/>'.
      '<w:lvl w:ilvl="0"><w:start w:val="1"/><w:numFmt w:val="decimal"/><w:lvlText w:val="%1."/>'.
      '<w:lvlJc w:val="left"/><w:pPr><w:ind w:left="720" w:hanging="360"/></w:pPr></w:lvl></w:abstractNum>';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<w:numbering xmlns:w="'.DOCX_NS_W.'">'.$bullet.$number.
      '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>'.
      '<w:num w:numId="2"><w:abstractNumId w:val="1"/></w:num></w:numbering>';
}

function docxStylesXml(){
    $h = '';
    $sizes = [1=>36, 2=>30, 3=>26, 4=>24];
    foreach($sizes as $lvl => $sz){
        $h .= '<w:style w:type="paragraph" w:styleId="Heading'.$lvl.'"><w:name w:val="heading '.$lvl.'"/>'.
              '<w:pPr><w:keepNext/><w:spacing w:before="240" w:after="120"/><w:outlineLvl w:val="'.($lvl-1).'"/></w:pPr>'.
              '<w:rPr><w:b/><w:sz w:val="'.$sz.'"/><w:szCs w:val="'.$sz.'"/></w:rPr></w:style>';
    }
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<w:styles xmlns:w="'.DOCX_NS_W.'">'.
      '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Sarabun" w:hAnsi="Sarabun" w:cs="Sarabun"/>'.
      '<w:sz w:val="28"/><w:szCs w:val="28"/></w:rPr></w:rPrDefault></w:docDefaults>'.
      '<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/></w:style>'.
      '<w:style w:type="paragraph" w:styleId="Quote"><w:name w:val="Quote"/>'.
      '<w:pPr><w:ind w:left="720"/></w:pPr><w:rPr><w:i/></w:rPr></w:style>'.$h.'</w:styles>';
}

/** ไล่ block ระดับบนของ HTML → ย่อหน้า/ตารางของ Word */
function docxBlocks($el, &$media, $imgRoot, $depth = 0){
    $out = '';
    foreach($el->childNodes as $n){
        if($n->nodeType === XML_TEXT_NODE){
            $t = trim($n->textContent);
            if($t !== '') $out .= docxPara($n->textContent, []);
            continue;
        }
        if($n->nodeType !== XML_ELEMENT_NODE) continue;
        $tag = strtolower($n->nodeName);
        if(in_array($tag, ['h1','h2','h3','h4'], true)){
            $out .= docxPara($n, [], ['style'=>'Heading'.substr($tag,1)], $media, $imgRoot);
        }elseif($tag === 'p' || $tag === 'div'){
            $out .= docxPara($n, [], ['align'=>docxAlignOf($n)], $media, $imgRoot);
        }elseif($tag === 'blockquote'){
            $out .= docxPara($n, [], ['style'=>'Quote'], $media, $imgRoot);
        }elseif($tag === 'pre'){
            foreach(preg_split('~\r\n|\n|\r~', $n->textContent) as $line) $out .= docxPara($line, ['mono'=>true]);
        }elseif($tag === 'ul' || $tag === 'ol'){
            foreach($n->getElementsByTagName('li') as $li){
                $out .= docxPara($li, [], ['num'=>($tag === 'ul' ? 1 : 2)], $media, $imgRoot);
            }
        }elseif($tag === 'table'){
            $out .= docxTableXml($n, $media, $imgRoot);
        }elseif($tag === 'hr'){
            $out .= '<w:p><w:pPr><w:pBdr><w:bottom w:val="single" w:sz="6" w:space="1" w:color="auto"/></w:pBdr></w:pPr></w:p>';
        }elseif($tag === 'img'){
            $out .= '<w:p>'.docxImageRun($n, $media, $imgRoot).'</w:p>';
        }elseif($tag === 'br'){
            $out .= docxPara('', []);
        }else{
            $out .= docxBlocks($n, $media, $imgRoot, $depth + 1);
        }
    }
    return $out;
}

function docxAlignOf($n){
    $st = $n->getAttribute('style');
    if($st && preg_match('~text-align\s*:\s*(left|center|right|justify)~i', $st, $m)){
        return strtolower($m[1]) === 'justify' ? 'both' : strtolower($m[1]);
    }
    return '';
}

/** สร้างย่อหน้า Word หนึ่งย่อหน้า จากสตริงหรือ DOM element */
function docxPara($src, $fmt = [], $opt = [], &$media = null, $imgRoot = ''){
    $runs = '';
    if(is_string($src)){
        if(trim($src) !== '') $runs = docxRunXml($src, $fmt);
    }else{
        $runs = docxInline($src, $fmt, $media, $imgRoot);
    }
    $pPr = '';
    if(!empty($opt['style'])) $pPr .= '<w:pStyle w:val="'.$opt['style'].'"/>';
    if(!empty($opt['num']))   $pPr .= '<w:numPr><w:ilvl w:val="0"/><w:numId w:val="'.(int)$opt['num'].'"/></w:numPr>';
    if(!empty($opt['align'])) $pPr .= '<w:jc w:val="'.$opt['align'].'"/>';
    if($pPr !== '') $pPr = '<w:pPr>'.$pPr.'</w:pPr>';
    return '<w:p>'.$pPr.$runs.'</w:p>';
}

/** ไล่ inline ภายในย่อหน้า (ตัวหนา/เอียง/ลิงก์/รูป/ขึ้นบรรทัด) */
function docxInline($el, $fmt, &$media, $imgRoot){
    $out = '';
    foreach($el->childNodes as $n){
        if($n->nodeType === XML_TEXT_NODE){
            if($n->textContent !== '') $out .= docxRunXml($n->textContent, $fmt);
            continue;
        }
        if($n->nodeType !== XML_ELEMENT_NODE) continue;
        $tag = strtolower($n->nodeName);
        $f = $fmt;
        if($tag === 'strong' || $tag === 'b') $f['b'] = true;
        elseif($tag === 'em' || $tag === 'i') $f['i'] = true;
        elseif($tag === 'u') $f['u'] = true;
        elseif($tag === 's' || $tag === 'strike' || $tag === 'del') $f['s'] = true;
        elseif($tag === 'code') $f['mono'] = true;
        elseif($tag === 'br'){ $out .= '<w:r><w:br/></w:r>'; continue; }
        elseif($tag === 'img'){ $out .= docxImageRun($n, $media, $imgRoot); continue; }
        elseif($tag === 'a'){
            $href = trim($n->getAttribute('href'));
            $f['link'] = true;
            if($href !== '' && preg_match('~^(https?://|mailto:)~i', $href)){
                $links =& $GLOBALS['__docx_links'];
                $rid = 'rIdLink'.(count($links) + 1);
                $links[$rid] = $href;
                $out .= '<w:hyperlink r:id="'.$rid.'">'.docxInline($n, $f, $media, $imgRoot).'</w:hyperlink>';
                continue;
            }
        }
        $out .= docxInline($n, $f, $media, $imgRoot);
    }
    return $out;
}

function docxRunXml($text, $fmt){
    $rPr = '';
    if(!empty($fmt['b']))    $rPr .= '<w:b/>';
    if(!empty($fmt['i']))    $rPr .= '<w:i/>';
    if(!empty($fmt['u']))    $rPr .= '<w:u w:val="single"/>';
    if(!empty($fmt['s']))    $rPr .= '<w:strike/>';
    if(!empty($fmt['mono'])) $rPr .= '<w:rFonts w:ascii="Consolas" w:hAnsi="Consolas"/>';
    if(!empty($fmt['link'])) $rPr .= '<w:color w:val="2563EB"/><w:u w:val="single"/>';
    if($rPr !== '') $rPr = '<w:rPr>'.$rPr.'</w:rPr>';
    return '<w:r>'.$rPr.'<w:t xml:space="preserve">'.docxEsc($text).'</w:t></w:r>';
}

/** ฝังรูปลงไฟล์ Word */
function docxImageRun($img, &$media, $imgRoot){
    if($media === null) return '';
    $src = $img->getAttribute('src');
    if($src === '') return '';
    $bin = null; $ext = 'png';
    if(preg_match('~^data:image/([a-z0-9.+-]+);base64,(.+)$~is', $src, $m)){
        $ext = strtolower($m[1]); if($ext === 'jpeg') $ext = 'jpg';
        $bin = base64_decode(preg_replace('~\s+~', '', $m[2]), true);
    }elseif(!preg_match('~^https?://~i', $src)){
        $rel  = ltrim(preg_replace('~^/+~', '', parse_url($src, PHP_URL_PATH)), '/');
        $rel  = preg_replace('~^.*?(uploads/)~', '$1', $rel);
        $path = rtrim($imgRoot, '/').'/'.$rel;
        if(is_file($path) && strpos(realpath($path), realpath(rtrim($imgRoot,'/'))) === 0){
            $bin = file_get_contents($path);
            $e = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if(in_array($e, ['png','jpg','jpeg','gif','webp'], true)) $ext = ($e === 'jpeg' ? 'jpg' : $e);
        }
    }
    if($bin === null || strlen($bin) < 64) return '';

    $info = @getimagesizefromstring($bin);
    $wPx = $info ? (int)$info[0] : 600; $hPx = $info ? (int)$info[1] : 400;
    $maxW = 600;                                             // ให้พอดีหน้ากระดาษ A4
    if($wPx > $maxW){ $hPx = (int)round($hPx * $maxW / $wPx); $wPx = $maxW; }
    $cx = $wPx * 9525; $cy = max(1, $hPx) * 9525;            // px → EMU

    $n    = count($media) + 1;
    $rid  = 'rIdImg'.$n;
    $media[$rid] = ['name'=>'image'.$n.'.'.$ext, 'bin'=>$bin, 'ext'=>$ext];

    return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'.
      '<wp:extent cx="'.$cx.'" cy="'.$cy.'"/><wp:docPr id="'.$n.'" name="Picture '.$n.'"/>'.
      '<a:graphic xmlns:a="'.DOCX_NS_A.'"><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'.
      '<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'.
      '<pic:nvPicPr><pic:cNvPr id="'.$n.'" name="Picture '.$n.'"/><pic:cNvPicPr/></pic:nvPicPr>'.
      '<pic:blipFill><a:blip r:embed="'.$rid.'"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>'.
      '<pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="'.$cx.'" cy="'.$cy.'"/></a:xfrm>'.
      '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr></pic:pic>'.
      '</a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
}

/** ตาราง HTML → ตาราง Word */
function docxTableXml($table, &$media, $imgRoot){
    $rows = [];
    foreach($table->getElementsByTagName('tr') as $tr){
        $cells = [];
        foreach($tr->childNodes as $c){
            if($c->nodeType !== XML_ELEMENT_NODE) continue;
            $tag = strtolower($c->nodeName);
            if($tag !== 'td' && $tag !== 'th') continue;
            $cells[] = ['el'=>$c, 'head'=>($tag === 'th'), 'span'=>max(1, (int)$c->getAttribute('colspan'))];
        }
        if($cells) $rows[] = $cells;
    }
    if(!$rows) return '';
    $cols = 0;
    foreach($rows[0] as $c) $cols += $c['span'];
    $cols = max(1, $cols);
    $w = (int)floor(9360 / $cols);

    $grid = '<w:tblGrid>'.str_repeat('<w:gridCol w:w="'.$w.'"/>', $cols).'</w:tblGrid>';
    $xml  = '<w:tbl><w:tblPr><w:tblW w:w="0" w:type="auto"/>'.
            '<w:tblBorders>'.
            '<w:top w:val="single" w:sz="4" w:color="BFBFBF"/><w:left w:val="single" w:sz="4" w:color="BFBFBF"/>'.
            '<w:bottom w:val="single" w:sz="4" w:color="BFBFBF"/><w:right w:val="single" w:sz="4" w:color="BFBFBF"/>'.
            '<w:insideH w:val="single" w:sz="4" w:color="BFBFBF"/><w:insideV w:val="single" w:sz="4" w:color="BFBFBF"/>'.
            '</w:tblBorders></w:tblPr>'.$grid;
    foreach($rows as $cells){
        $xml .= '<w:tr>';
        foreach($cells as $c){
            $tcPr = '<w:tcW w:w="'.($w * $c['span']).'" w:type="dxa"/>';
            if($c['span'] > 1)  $tcPr .= '<w:gridSpan w:val="'.$c['span'].'"/>';
            if($c['head'])      $tcPr .= '<w:shd w:val="clear" w:color="auto" w:fill="EEF1FF"/>';
            $inner = docxPara($c['el'], $c['head'] ? ['b'=>true] : [], [], $media, $imgRoot);
            $xml .= '<w:tc><w:tcPr>'.$tcPr.'</w:tcPr>'.$inner.'</w:tc>';
        }
        $xml .= '</w:tr>';
    }
    return $xml.'</w:tbl>';
}

}
