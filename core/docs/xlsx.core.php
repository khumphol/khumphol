<?php
// [aleanor_cloud] พอร์ตจาก aleanor_ai/core/xlsx.core.php — แก้เฉพาะพาธรูป (uploads/docs/) และชื่อฟังก์ชันพาธฐาน/ภาษา
// ============================================================
// อ่าน/เขียนไฟล์ .xlsx สำหรับ Aleanor Grid
// .xlsx = ZIP + XML (SpreadsheetML) — ทำเองด้วย ZipArchive + DOMDocument ไม่ต้องใช้ไลบรารีนอก
//
//   xlsxImport($path)            → ['ok'=>..,'title'=>..,'grid'=>[rows,cols,cells,fmt,widths]]
//   xlsxExport($title,$gridData) → ข้อมูลไบนารีของไฟล์ .xlsx (สูตรถูกคำนวณและใส่ค่าล่าสุดไว้ให้ด้วย)
// ============================================================
if(!function_exists('xlsxExport')){

function xlsxEsc($s){ return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8'); }

/** สีจาก #rrggbb → AARRGGBB ที่ Excel ใช้ */
function xlsxColor($hex){
    $hex = ltrim((string)$hex, '#');
    if(strlen($hex) === 3) $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    return strlen($hex) === 6 ? 'FF'.strtoupper($hex) : null;
}

function xlsxExport($title, $gridData){
    $book   = gridSanitize($gridData);
    $sheets = $book['sheets'];

    $sheetParts = [];                              // xml ของแต่ละชีต
    $fonts = [['b'=>0,'i'=>0,'fc'=>null]];
    $fills = [null, null];
    $xfs   = [['font'=>0,'fill'=>0,'align'=>'','num'=>0]];
    $xfKey = ['0|0||0' => 0];
    $fontKey = ['0|0|' => 0];
    $numFmts = [];                                 // รหัสรูปแบบ → numFmtId (164 ขึ้นไป)
    $builtinNum = ['0'=>1,'0.00'=>2,'#,##0'=>3,'#,##0.00'=>4,'0%'=>9,'0.00%'=>10,'0.00E+00'=>11,'mm-dd-yy'=>14,
                   'd-mmm-yy'=>15,'d-mmm'=>16,'mmm-yy'=>17,'h:mm AM/PM'=>18,'h:mm:ss AM/PM'=>19,'h:mm'=>20,
                   'h:mm:ss'=>21,'m/d/yy h:mm'=>22,'mm:ss'=>45,'@'=>49];

    foreach($sheets as $g){
        $vals = gridEvaluate($g);
        $cellStyle = [];
        foreach($g['fmt'] as $ref => $f){
            $fk = ($f['b'] ?? 0).'|'.($f['i'] ?? 0).'|'.($f['fc'] ?? '');
            if(!isset($fontKey[$fk])){ $fonts[] = ['b'=>$f['b'] ?? 0, 'i'=>$f['i'] ?? 0, 'fc'=>$f['fc'] ?? null]; $fontKey[$fk] = count($fonts) - 1; }
            $fi = $fontKey[$fk];
            $li = 0;
            if(!empty($f['bg'])){
                $c = xlsxColor($f['bg']);
                if($c){
                    foreach($fills as $i => $fl){ if($fl === $c){ $li = $i; break; } }
                    if($li === 0){ $fills[] = $c; $li = count($fills) - 1; }
                }
            }
            $al = $f['a'] ?? '';
            $ni = 0;
            if(!empty($f['n']) && strcasecmp($f['n'], 'General') !== 0){
                if(isset($builtinNum[$f['n']])) $ni = $builtinNum[$f['n']];
                else { if(!isset($numFmts[$f['n']])) $numFmts[$f['n']] = 164 + count($numFmts); $ni = $numFmts[$f['n']]; }
            }
            $key = $fi.'|'.$li.'|'.$al.'|'.$ni;
            if(!isset($xfKey[$key])){ $xfs[] = ['font'=>$fi,'fill'=>$li,'align'=>$al,'num'=>$ni]; $xfKey[$key] = count($xfs) - 1; }
            $cellStyle[$ref] = $xfKey[$key];
        }

        // วนเฉพาะขอบเขตที่มีข้อมูล/รูปแบบจริง
        $maxR = 0; $maxC = -1;
        foreach([$g['cells'], $g['fmt']] as $set){
            foreach($set as $ref => $_){
                if(!preg_match('~^([A-Z]{1,3})(\d+)$~', $ref, $m)) continue;
                $maxR = max($maxR, (int)$m[2]); $maxC = max($maxC, gridColIndex($m[1]));
            }
        }
        $maxR = min($maxR, $g['rows']); $maxC = min($maxC, $g['cols'] - 1);
        $colNames = [];
        for($c = 0; $c <= $maxC; $c++) $colNames[$c] = gridColName($c);
        $rowsXml = '';
        for($r = 1; $r <= $maxR; $r++){
            $cellsXml = '';
            for($c = 0; $c <= $maxC; $c++){
                $ref  = $colNames[$c].$r;
                $cell = $g['cells'][$ref] ?? null;
                $sty  = isset($cellStyle[$ref]) ? ' s="'.$cellStyle[$ref].'"' : '';
                if(!$cell){ if($sty !== '') $cellsXml .= '<c r="'.$ref.'"'.$sty.'/>'; continue; }
                $v = $vals[$ref] ?? ($cell['v'] ?? '');
                if(isset($cell['f']) && $cell['f'] !== ''){
                    $fml = ltrim((string)$cell['f'], '=');
                    $num = is_numeric($v);
                    $cellsXml .= '<c r="'.$ref.'"'.$sty.($num ? '' : ' t="str"').'>'.
                                 '<f>'.xlsxEsc($fml).'</f><v>'.xlsxEsc($num ? $v : (string)$v).'</v></c>';
                }elseif(is_numeric($v)){
                    $cellsXml .= '<c r="'.$ref.'"'.$sty.'><v>'.xlsxEsc($v).'</v></c>';
                }else{
                    $cellsXml .= '<c r="'.$ref.'"'.$sty.' t="inlineStr"><is><t xml:space="preserve">'.xlsxEsc($v).'</t></is></c>';
                }
            }
            if($cellsXml !== '') $rowsXml .= '<row r="'.$r.'">'.$cellsXml.'</row>';
        }

        $colsXml = '';
        if($g['widths']){
            $colsXml = '<cols>';
            foreach($g['widths'] as $col => $w){
                $i = gridColIndex($col) + 1;
                $colsXml .= '<col min="'.$i.'" max="'.$i.'" width="'.round($w / 7.5, 2).'" customWidth="1"/>';
            }
            $colsXml .= '</cols>';
        }
        $lastRef = gridColName(max(0, $g['cols'] - 1)).max(1, $g['rows']);
        $sheetParts[] = ['name'=>$g['name'], 'xml'=>
          '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
          '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.
          '<dimension ref="A1:'.$lastRef.'"/><sheetViews><sheetView workbookViewId="0"/></sheetViews>'.
          '<sheetFormatPr defaultRowHeight="15"/>'.$colsXml.
          '<sheetData>'.$rowsXml.'</sheetData></worksheet>'];
    }

    $fontXml = '';
    foreach($fonts as $ft){
        $fontXml .= '<font><sz val="11"/><name val="Sarabun"/>'.
            (!empty($ft['b']) ? '<b/>' : '').(!empty($ft['i']) ? '<i/>' : '').
            (!empty($ft['fc']) && xlsxColor($ft['fc']) ? '<color rgb="'.xlsxColor($ft['fc']).'"/>' : '').
            '</font>';
    }
    $fillXml = '<fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>';
    for($i = 2; $i < count($fills); $i++){
        $fillXml .= '<fill><patternFill patternType="solid"><fgColor rgb="'.$fills[$i].'"/><bgColor indexed="64"/></patternFill></fill>';
    }
    $xfXml = '';
    foreach($xfs as $xf){
        $al = $xf['align'] !== '' ? '<alignment horizontal="'.$xf['align'].'"/>' : '';
        $xfXml .= '<xf numFmtId="'.$xf['num'].'" fontId="'.$xf['font'].'" fillId="'.$xf['fill'].'" borderId="0" xfId="0"'.
                  ($xf['num'] ? ' applyNumberFormat="1"' : '').
                  ($xf['font'] ? ' applyFont="1"' : '').($xf['fill'] > 1 ? ' applyFill="1"' : '').
                  ($al ? ' applyAlignment="1">'.$al.'</xf>' : '/>');
    }

    $numFmtXml = '';
    if($numFmts){
        $numFmtXml = '<numFmts count="'.count($numFmts).'">';
        foreach($numFmts as $code => $id) $numFmtXml .= '<numFmt numFmtId="'.$id.'" formatCode="'.xlsxEsc($code).'"/>';
        $numFmtXml .= '</numFmts>';
    }

    $used = [];
    $sheetTags = ''; $wbRels = ''; $overrides = '';
    foreach($sheetParts as $i => $sp){
        $nm = mb_substr(preg_replace('~[\\/:*?\[\]]~u', '', $sp['name']), 0, 31);
        if(trim($nm) === '') $nm = 'Sheet'.($i + 1);
        $base = $nm; $k = 2;
        while(isset($used[mb_strtolower($nm)])){ $nm = mb_substr($base, 0, 28).'('.$k++.')'; }
        $used[mb_strtolower($nm)] = true;
        $sheetTags .= '<sheet name="'.xlsxEsc($nm).'" sheetId="'.($i + 1).'" r:id="rIdSh'.($i + 1).'"/>';
        $wbRels    .= '<Relationship Id="rIdSh'.($i + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($i + 1).'.xml"/>';
        $overrides .= '<Override PartName="/xl/worksheets/sheet'.($i + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }

    $tmp = tempnam(sys_get_temp_dir(), 'alxlsx');
    $zip = new ZipArchive();
    if($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) return false;
    $zip->addFromString('[Content_Types].xml',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'.
      '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'.
      '<Default Extension="xml" ContentType="application/xml"/>'.
      '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'.
      $overrides.
      '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'.
      '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'.
      '</Types>');
    $zip->addFromString('_rels/.rels',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.
      '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'.
      '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'.
      '</Relationships>');
    $zip->addFromString('docProps/core.xml',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '.
      'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'.
      '<dc:title>'.xlsxEsc($title).'</dc:title>'.
      '<dcterms:created xsi:type="dcterms:W3CDTF">'.gmdate('Y-m-d\TH:i:s\Z').'</dcterms:created></cp:coreProperties>');
    $zip->addFromString('xl/workbook.xml',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '.
      'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'.
      '<sheets>'.$sheetTags.'</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$wbRels.
      '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'.
      '</Relationships>');
    $zip->addFromString('xl/styles.xml',
      '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'.
      '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.
      $numFmtXml.
      '<fonts count="'.count($fonts).'">'.$fontXml.'</fonts>'.
      '<fills count="'.max(2, count($fills)).'">'.$fillXml.'</fills>'.
      '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'.
      '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'.
      '<cellXfs count="'.count($xfs).'">'.$xfXml.'</cellXfs>'.
      '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    foreach($sheetParts as $i => $sp) $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', $sp['xml']);
    $zip->close();
    $bin = file_get_contents($tmp);
    @unlink($tmp);
    return $bin;
}

/**
 * อ่าน .csv → สมุดงานของ Aleanor Grid (ชีตเดียว)
 * เดาตัวคั่น (, ; แท็บ |) และการเข้ารหัส (UTF-8 / UTF-16 / Windows-874 ที่ Excel ภาษาไทยชอบบันทึก)
 */
function csvImport($path, $name = null){
    if(!is_file($path)) return ['ok'=>false,'error'=>'ไม่พบไฟล์'];
    $raw = file_get_contents($path, false, null, 0, 64 * 1024 * 1024);
    if($raw === false || $raw === '') return ['ok'=>false,'error'=>'ไฟล์ว่าง'];
    if(substr($raw, 0, 3) === "\xEF\xBB\xBF") $raw = substr($raw, 3);
    elseif(substr($raw, 0, 2) === "\xFF\xFE") $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE');
    elseif(substr($raw, 0, 2) === "\xFE\xFF") $raw = mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE');
    elseif(!mb_check_encoding($raw, 'UTF-8')){
        $conv = function_exists('iconv') ? @iconv('CP874', 'UTF-8//IGNORE', $raw) : false;
        $raw = ($conv !== false && $conv !== '') ? $conv : mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
    // ตัวคั่น: นับจากบรรทัดแรก ๆ (ไม่นับที่อยู่ในเครื่องหมายคำพูด)
    $sample = preg_replace('~"(?:[^"]|"")*"~', '', substr($raw, 0, 20000));
    $lines = array_slice(preg_split('~\r\n|\n|\r~', $sample), 0, 10);
    $best = ','; $bestScore = -1;
    foreach([',', ';', "\t", '|'] as $d){
        $counts = array_map(function($l) use ($d){ return substr_count($l, $d); }, array_filter($lines, 'strlen'));
        if(!$counts) continue;
        $min = min($counts);
        $score = $min > 0 ? $min * 10 + (count(array_unique($counts)) === 1 ? 5 : 0) : max($counts);
        if($score > $bestScore){ $bestScore = $score; $best = $d; }
    }
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $raw); unset($raw); rewind($fh);
    $maxRows = gridMaxRows(); $maxCols = gridMaxCols(); $maxCells = gridMaxCells();
    $cells = []; $r = 0; $maxCol = 0; $n = 0;
    while(($row = fgetcsv($fh, 0, $best, '"', '')) !== false){
        $r++;
        if($r > $maxRows) break;
        if($row === [null]) continue;                     // บรรทัดว่าง
        foreach($row as $ci => $val){
            if($ci >= $maxCols) break;
            $val = (string)$val;
            if($val === '') continue;
            $cells[gridColName($ci).$r] = ['v' => mb_substr($val, 0, 2000)];
            if($ci + 1 > $maxCol) $maxCol = $ci + 1;
            if(++$n >= $maxCells) break 2;
        }
    }
    fclose($fh);
    if(!$cells) return ['ok'=>false,'error'=>'ไม่พบข้อมูลในไฟล์'];
    // หัวตาราง (แถวแรก) ตัวหนา + ความกว้างคอลัมน์ตามข้อความที่ยาวที่สุด (ประมาณ)
    $fmt = []; $widths = [];
    for($ci = 0; $ci < $maxCol; $ci++){
        $cn = gridColName($ci);
        if(isset($cells[$cn.'1'])) $fmt[$cn.'1'] = ['b'=>1];
        $len = 0;
        for($rr = 1; $rr <= min($r, 200); $rr++){ if(isset($cells[$cn.$rr])) $len = max($len, mb_strwidth($cells[$cn.$rr]['v'])); }
        $widths[$cn] = (int)max(60, min(360, $len * 8 + 20));
    }
    $title = $name !== null ? pathinfo($name, PATHINFO_FILENAME) : pathinfo($path, PATHINFO_FILENAME);
    return ['ok'=>true, 'title'=>$title, 'grid'=>['sheets'=>[[
        'name'=>'Sheet1', 'rows'=>max(20, min($maxRows, $r + 3)), 'cols'=>max(8, min($maxCols, $maxCol + 1)),
        'cells'=>$cells, 'fmt'=>$fmt, 'widths'=>$widths]], 'active'=>0]];
}

/** ลูกโดยตรงที่เป็น element (กรองชื่อได้) */
function xlsxKids($el, $local = null){
    $out = [];
    for($n = $el ? $el->firstChild : null; $n; $n = $n->nextSibling){
        if($n->nodeType === XML_ELEMENT_NODE && ($local === null || $n->localName === $local)) $out[] = $n;
    }
    return $out;
}

/** อ่าน .xlsx → สมุดงานของ Aleanor Grid (ทุกชีต) */
function xlsxImport($zipPath){
    if(!is_file($zipPath)) return ['ok'=>false,'error'=>'ไม่พบไฟล์'];
    $zip = new ZipArchive();
    if($zip->open($zipPath) !== true) return ['ok'=>false,'error'=>'เปิดไฟล์ไม่ได้ (ไม่ใช่ .xlsx?)'];
    $maxRows = function_exists('gridMaxRows') ? gridMaxRows() : 20000;
    $maxCols = function_exists('gridMaxCols') ? gridMaxCols() : 200;
    $maxCells = function_exists('gridMaxCells') ? gridMaxCells() : 250000;

    // รายชื่อชีตตามลำดับใน workbook
    $list = [];
    $wb = $zip->getFromName('xl/workbook.xml');
    $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if($wb !== false && $rels !== false){
        $d1 = new DOMDocument(); $d2 = new DOMDocument();
        if(@$d1->loadXML($wb) && @$d2->loadXML($rels)){
            $map = [];
            foreach($d2->getElementsByTagName('Relationship') as $rel) $map[$rel->getAttribute('Id')] = $rel->getAttribute('Target');
            foreach($d1->getElementsByTagName('sheet') as $sh){
                $rid = $sh->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships','id');
                if(!isset($map[$rid])) continue;
                $t = ltrim(str_replace('../','',$map[$rid]), '/');
                $list[] = ['name'=>$sh->getAttribute('name'), 'path'=>(strpos($t,'xl/') === 0 ? $t : 'xl/'.$t)];
                if(count($list) >= 20) break;
            }
        }
    }
    if(!$list) $list = [['name'=>'Sheet1', 'path'=>'xl/worksheets/sheet1.xml']];

    // ตารางข้อความร่วม (ใช้ร่วมกันทุกชีต)
    $shared = [];
    $ss = $zip->getFromName('xl/sharedStrings.xml');
    if($ss !== false){
        $ds = new DOMDocument();
        if(@$ds->loadXML($ss)){
            foreach(xlsxKids($ds->documentElement, 'si') as $si){
                $txt = '';
                foreach(xlsxKids($si) as $part){
                    if($part->localName === 't') $txt .= $part->textContent;
                    elseif($part->localName === 'r'){ foreach(xlsxKids($part, 't') as $t) $txt .= $t->textContent; }
                }
                $shared[] = $txt;
            }
        }
    }

    // สไตล์: ตัวหนา/เอียง/สีตัวอักษร/สีพื้น/จัดชิด/รูปแบบตัวเลข ต่อ index ของ cellXfs
    $xfFmt = xlsxReadStyles($zip->getFromName('xl/styles.xml'));

    $sheets = [];
    foreach($list as $item){
        $xml = $zip->getFromName($item['path']);
        if($xml === false) continue;
        $doc = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = @$doc->loadXML($xml, LIBXML_PARSEHUGE);
        libxml_clear_errors(); libxml_use_internal_errors($prev);
        if(!$ok) continue;

        $cells = []; $fmt = []; $widths = []; $maxRow = 1; $maxCol = 1; $n = 0; $sharedF = [];

        // ความกว้างคอลัมน์ (หน่วยตัวอักษรของ Excel → px)
        $colsEl = $doc->getElementsByTagName('cols')->item(0);
        foreach($colsEl ? xlsxKids($colsEl, 'col') : [] as $col){
            $w = (float)$col->getAttribute('width');
            if($w <= 0 || $col->getAttribute('hidden') === 'true' || $col->getAttribute('hidden') === '1') continue;
            $px = (int)max(40, min(600, round($w * 7 + 5)));
            $a = max(1, (int)$col->getAttribute('min')); $b = min($maxCols, (int)$col->getAttribute('max'));
            for($ci = $a; $ci <= $b && $ci - $a < 200; $ci++) $widths[gridColName($ci - 1)] = $px;
        }

        // เดินตามพี่น้องของโหนดโดยตรง — DOMNodeList จาก getElementsByTagName บน PHP 7 ช้าแบบกำลังสองกับชีตใหญ่
        $sheetData = $doc->getElementsByTagName('sheetData')->item(0);
        $cellEls = [];
        if($sheetData){ foreach(xlsxKids($sheetData, 'row') as $rowEl){ foreach(xlsxKids($rowEl, 'c') as $cEl) $cellEls[] = $cEl; } }
        foreach($cellEls as $c){
            $ref = strtoupper($c->getAttribute('r'));
            if(!preg_match('~^([A-Z]{1,3})([1-9][0-9]{0,6})$~', $ref, $m)) continue;
            $ci = gridColIndex($m[1]); $ri = (int)$m[2];
            if($ci >= $maxCols || $ri > $maxRows) continue;
            $type = $c->getAttribute('t');
            $fEl = null; $vEl = null; $isEl = null;
            foreach($c->childNodes as $ch){
                if($ch->nodeType !== XML_ELEMENT_NODE) continue;
                if($ch->localName === 'f')  $fEl  = $ch;
                if($ch->localName === 'v')  $vEl  = $ch;
                if($ch->localName === 'is') $isEl = $ch;
            }
            // ค่าที่แสดง (สำหรับสูตร = ค่าที่ Excel คำนวณไว้ล่าสุด)
            $val = '';
            if($isEl){
                foreach(xlsxKids($isEl) as $part){
                    if($part->localName === 't') $val .= $part->textContent;
                    elseif($part->localName === 'r'){ foreach(xlsxKids($part, 't') as $t) $val .= $t->textContent; }
                }
            }
            elseif($vEl){
                $raw = $vEl->textContent;
                if($type === 's'){ $val = $shared[(int)$raw] ?? ''; }
                elseif($type === 'b'){ $val = $raw === '1' ? 'TRUE' : 'FALSE'; }
                else $val = $raw;
            }
            $cell = [];
            $ftxt = $fEl ? trim($fEl->textContent) : '';
            // สูตรแบบใช้ร่วม (Excel เขียนสูตรเต็มไว้ที่ช่องแรก ช่องถัดไปอ้าง si เดียวกัน) → เลื่อนอ้างอิงตามตำแหน่ง
            if($fEl && $fEl->getAttribute('t') === 'shared' && $fEl->getAttribute('si') !== ''){
                $si = $fEl->getAttribute('si');
                if($ftxt !== '') $sharedF[$si] = [$ftxt, $ri, $ci];
                elseif(isset($sharedF[$si])) $ftxt = xlsxShiftFormula($sharedF[$si][0], $ri - $sharedF[$si][1], $ci - $sharedF[$si][2]);
            }
            if($ftxt !== ''){
                $cell['f'] = '='.mb_substr($ftxt, 0, 2000);
                $cell['v'] = mb_substr((string)$val, 0, 2000);      // สูตรเก็บค่าเสมอ แม้ผลเป็นค่าว่าง
            }elseif($val !== '') $cell['v'] = mb_substr((string)$val, 0, 2000);

            // สไตล์เก็บได้แม้ช่องว่าง (เช่น แถบสีหัวตาราง) — ตัดส่วนที่เกินขอบข้อมูลทิ้งทีหลัง
            $s = $c->getAttribute('s');
            if($s !== '' && !empty($xfFmt[(int)$s])) $fmt[$ref] = $xfFmt[(int)$s];
            if(!$cell) continue;
            $cells[$ref] = $cell;
            if($ri > $maxRow) $maxRow = $ri;
            if($ci + 1 > $maxCol) $maxCol = $ci + 1;
            if(++$n >= $maxCells) break;
        }
        // เก็บสไตล์เฉพาะช่องในขอบเขตข้อมูล (กันแถวสไตล์เปล่า ๆ ยาวไปหลายหมื่นแถว)
        foreach($fmt as $ref => $f){
            if(!preg_match('~^([A-Z]{1,3})(\d+)$~', $ref, $m) || (int)$m[2] > $maxRow + 2 || gridColIndex($m[1]) >= $maxCol + 1) unset($fmt[$ref]);
        }
        $sheets[] = [
            'name'   => $item['name'] !== '' ? $item['name'] : ('Sheet'.(count($sheets) + 1)),
            'rows'   => max(20, min($maxRows, $maxRow + 3)),
            'cols'   => max(8,  min($maxCols, $maxCol + 1)),
            'cells'  => $cells, 'fmt' => $fmt, 'widths' => $widths,
        ];
    }
    $zip->close();
    if(!$sheets) return ['ok'=>false,'error'=>'ไม่พบข้อมูลในไฟล์'];

    return ['ok'=>true, 'title'=>pathinfo($zipPath, PATHINFO_FILENAME),
            'grid'=>['sheets'=>$sheets, 'active'=>0]];
}

/** เลื่อนอ้างอิงแบบสัมพัทธ์ในสูตร (ไม่แตะข้อความในเครื่องหมายคำพูดและชื่อชีตใน '...') */
function xlsxShiftFormula($f, $dr, $dc){
    if(!$dr && !$dc) return $f;
    $parts = preg_split('~("(?:[^"]|"")*"|\'(?:[^\']|\'\')*\')~u', $f, -1, PREG_SPLIT_DELIM_CAPTURE);
    $col = function($abs, $name) use ($dc){
        if($abs !== '' || !$dc) return $abs.$name;
        $i = gridColIndex($name) + $dc;
        return $i < 0 ? '#REF!' : gridColName($i);
    };
    foreach($parts as $k => $p){
        if($p === '' || $p[0] === '"' || $p[0] === "'") continue;
        // ช่วงทั้งคอลัมน์ A:C
        $p = preg_replace_callback('~(?<![A-Za-z0-9_.$])(\$?)([A-Z]{1,3}):(\$?)([A-Z]{1,3})(?![A-Za-z0-9_(])~', function($m) use ($col){
            return $col($m[1], $m[2]).':'.$col($m[3], $m[4]);
        }, $p);
        // ช่วงทั้งแถว 2:5
        $p = preg_replace_callback('~(?<![A-Za-z0-9_.$:])(\$?)(\d{1,7}):(\$?)(\d{1,7})(?![A-Za-z0-9_.(:])~', function($m) use ($dr){
            $a = $m[1] !== '' ? $m[2] : (int)$m[2] + $dr; $b = $m[3] !== '' ? $m[4] : (int)$m[4] + $dr;
            return ($a < 1 || $b < 1) ? '#REF!' : $m[1].$a.':'.$m[3].$b;
        }, $p);
        // ช่องเดี่ยว A1 / $A$1
        $p = preg_replace_callback('~(?<![A-Za-z0-9_.])(\$?)([A-Z]{1,3})(\$?)(\d{1,7})(?![A-Za-z0-9_(])~', function($m) use ($col, $dr){
            $r = $m[3] !== '' ? (int)$m[4] : (int)$m[4] + $dr;
            $c = $col($m[1], $m[2]);
            return ($r < 1 || $c === '#REF!') ? '#REF!' : $c.$m[3].$r;
        }, $p);
        $parts[$k] = $p;
    }
    return implode('', $parts);
}

/** อ่าน styles.xml → รายการรูปแบบของ Grid ต่อ index ของ cellXfs */
function xlsxReadStyles($xml){
    $out = [];
    if(!$xml) return $out;
    $d = new DOMDocument();
    if(!@$d->loadXML($xml)) return $out;
    $builtin = [1=>'0', 2=>'0.00', 3=>'#,##0', 4=>'#,##0.00', 9=>'0%', 10=>'0.00%', 11=>'0.00E+00',
                14=>'dd/mm/yyyy', 15=>'d-mmm-yy', 16=>'d-mmm', 17=>'mmm-yy', 18=>'h:mm AM/PM', 19=>'h:mm:ss AM/PM',
                20=>'h:mm', 21=>'h:mm:ss', 22=>'dd/mm/yyyy h:mm', 37=>'#,##0 ;(#,##0)', 38=>'#,##0 ;[Red](#,##0)',
                39=>'#,##0.00;(#,##0.00)', 40=>'#,##0.00;[Red](#,##0.00)', 45=>'mm:ss', 46=>'[h]:mm:ss', 47=>'mmss.0', 49=>'@'];
    $numFmts = [];
    foreach($d->getElementsByTagName('numFmt') as $nf) $numFmts[(int)$nf->getAttribute('numFmtId')] = $nf->getAttribute('formatCode');
    $fonts = [];
    foreach($d->getElementsByTagName('fonts') as $fs){
        foreach($fs->childNodes as $font){
            if($font->nodeType !== XML_ELEMENT_NODE || $font->localName !== 'font') continue;
            $f = ['b'=>0, 'i'=>0, 'fc'=>null];
            foreach($font->childNodes as $x){
                if($x->nodeType !== XML_ELEMENT_NODE) continue;
                $on = $x->getAttribute('val'); $on = ($on === '' || $on === '1' || $on === 'true');
                if($x->localName === 'b' && $on) $f['b'] = 1;
                if($x->localName === 'i' && $on) $f['i'] = 1;
                if($x->localName === 'color'){ $rgb = $x->getAttribute('rgb'); if(strlen($rgb) === 8) $f['fc'] = '#'.strtolower(substr($rgb, 2)); }
            }
            $fonts[] = $f;
        }
        break;
    }
    $fills = [];
    foreach($d->getElementsByTagName('fills') as $fl){
        foreach($fl->childNodes as $fill){
            if($fill->nodeType !== XML_ELEMENT_NODE || $fill->localName !== 'fill') continue;
            $bg = null;
            foreach($fill->getElementsByTagName('patternFill') as $pf){
                if($pf->getAttribute('patternType') !== 'solid') continue;
                foreach($pf->getElementsByTagName('fgColor') as $fg){ $rgb = $fg->getAttribute('rgb'); if(strlen($rgb) === 8) $bg = '#'.strtolower(substr($rgb, 2)); }
            }
            $fills[] = $bg;
        }
        break;
    }
    foreach($d->getElementsByTagName('cellXfs') as $cx){
        foreach($cx->childNodes as $xf){
            if($xf->nodeType !== XML_ELEMENT_NODE || $xf->localName !== 'xf') continue;
            $g = [];
            $font = $fonts[(int)$xf->getAttribute('fontId')] ?? null;
            if($font){
                if($font['b']) $g['b'] = 1;
                if($font['i']) $g['i'] = 1;
                if($font['fc'] && $font['fc'] !== '#000000') $g['fc'] = $font['fc'];
            }
            $bg = $fills[(int)$xf->getAttribute('fillId')] ?? null;
            if($bg && $bg !== '#ffffff') $g['bg'] = $bg;
            foreach($xf->getElementsByTagName('alignment') as $al){
                $h = $al->getAttribute('horizontal');
                if(in_array($h, ['left','center','right'], true)) $g['a'] = $h;
            }
            $nid = (int)$xf->getAttribute('numFmtId');
            $code = $numFmts[$nid] ?? ($builtin[$nid] ?? '');
            if($code !== '' && strcasecmp($code, 'General') !== 0) $g['n'] = mb_substr($code, 0, 60);
            $out[] = $g;
        }
        break;
    }
    return $out;
}


}
