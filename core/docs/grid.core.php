<?php
// [aleanor_cloud] พอร์ตจาก aleanor_ai/core/grid.core.php — แก้เฉพาะพาธรูป (uploads/docs/) และชื่อฟังก์ชันพาธฐาน/ภาษา
// ============================================================
// Aleanor Grid — ตารางคำนวณ
//
// รูปแบบข้อมูลที่เก็บใน documents.doc_content (JSON):
//   { "rows":20, "cols":8,
//     "cells": { "A1": {"v":"ยอดขาย"}, "B2": {"v":"120"}, "B5": {"f":"=SUM(B2:B4)"} },
//     "fmt":   { "A1": {"b":1,"a":"center","bg":"#eef"} },
//     "widths":{ "A":160 } }
//
// v = ค่าที่พิมพ์ไว้ | f = สูตร (ขึ้นต้นด้วย =) — ผลลัพธ์คำนวณตอนแสดงผล ไม่เก็บลงฐานข้อมูล
// ============================================================
if(!function_exists('gridBlank')){

/** ขีดจำกัดของสมุดงาน — ใช้ร่วมกันทั้งตัวนำเข้า ตัวบันทึก และตัวแก้ไข */
function gridMaxRows(){  return 20000; }
function gridMaxCols(){  return 200; }
function gridMaxCells(){ return 250000; }
function gridRefOk($ref){ return (bool)preg_match('~^[A-Z]{1,3}[1-9][0-9]{0,4}$~', (string)$ref); }

function gridBlankSheet($name = null, $rows = 20, $cols = 8){
    return ['name'=>$name === null ? 'Sheet1' : $name, 'rows'=>$rows, 'cols'=>$cols,
            'cells'=>[], 'fmt'=>[], 'widths'=>[]];
}
function gridBlank($rows = 20, $cols = 8){
    return ['sheets'=>[gridBlankSheet(null, $rows, $cols)], 'active'=>0];
}

/** จัดรูปชีตเดียวให้อยู่ในโครงมาตรฐาน */
function gridSheetNorm($d, $fallbackName = 'Sheet1'){
    if(!is_array($d)) $d = [];
    $rows = isset($d['rows']) ? max(1, min(gridMaxRows(), (int)$d['rows'])) : 20;
    $cols = isset($d['cols']) ? max(1, min(gridMaxCols(), (int)$d['cols'])) : 8;
    $name = isset($d['name']) ? trim(mb_substr(strip_tags((string)$d['name']), 0, 40)) : '';
    if($name === '') $name = $fallbackName;
    return [
        'name'   => $name,
        'rows'   => $rows,
        'cols'   => $cols,
        'cells'  => (isset($d['cells'])  && is_array($d['cells']))  ? $d['cells']  : [],
        'fmt'    => (isset($d['fmt'])    && is_array($d['fmt']))    ? $d['fmt']    : [],
        'widths' => (isset($d['widths']) && is_array($d['widths'])) ? $d['widths'] : [],
    ];
}

/**
 * อ่าน JSON ของสมุดงาน — คืน ['sheets'=>[...], 'active'=>int]
 * รองรับรูปแบบเดิม (ชีตเดียว มี cells อยู่ระดับบนสุด) โดยห่อให้เป็นชีตแรกอัตโนมัติ
 */
function gridParse($json){
    $d = is_string($json) ? json_decode($json, true) : $json;
    if(!is_array($d)) $d = [];
    if(isset($d['sheets']) && is_array($d['sheets']) && $d['sheets']){
        $sheets = [];
        foreach($d['sheets'] as $i => $sh){
            $sheets[] = gridSheetNorm($sh, 'Sheet'.($i + 1));
            if(count($sheets) >= 20) break;
        }
    }else{
        $sheets = [gridSheetNorm($d, 'Sheet1')];        // รูปแบบเดิม
    }
    $active = isset($d['active']) ? (int)$d['active'] : 0;
    if($active < 0 || $active >= count($sheets)) $active = 0;
    return ['sheets'=>$sheets, 'active'=>$active];
}

/** ชีตที่ต้องการ (ค่าเริ่มต้น = ชีตที่เปิดอยู่) */
function gridSheet($json, $index = null){
    $b = gridParse($json);
    if($index === null) $index = $b['active'];
    return $b['sheets'][$index] ?? $b['sheets'][0];
}

/** ชื่อคอลัมน์: 0→A, 25→Z, 26→AA */
function gridColName($i){
    $s = '';
    $i = (int)$i;
    while(true){ $s = chr(65 + ($i % 26)).$s; $i = intdiv($i, 26) - 1; if($i < 0) break; }
    return $s;
}
function gridColIndex($name){
    $name = strtoupper((string)$name); $n = 0;
    for($i = 0; $i < strlen($name); $i++){ $n = $n * 26 + (ord($name[$i]) - 64); }
    return $n - 1;
}

/** ทำความสะอาดก่อนบันทึก — กันข้อมูลบวมและกันค่าที่ไม่คาดคิด */
function gridSanitizeSheet($sheet){
    $g = gridSheetNorm($sheet);
    $cells = []; $fmt = []; $widths = [];
    $n = 0;
    foreach($g['cells'] as $ref => $c){
        if(!gridRefOk($ref) || !is_array($c)) continue;
        $out = [];
        // สูตรเก็บคู่กับค่าที่คำนวณได้ล่าสุด (v) — หน้าอ่าน/ส่งออกใช้ค่านี้ได้เลยไม่ต้องคำนวณใหม่
        $isF = isset($c['f']) && is_scalar($c['f']) && $c['f'] !== '';
        if(isset($c['v']) && is_scalar($c['v']) && ($c['v'] !== '' || $isF)) $out['v'] = mb_substr((string)$c['v'], 0, 2000);
        if($isF) $out['f'] = mb_substr((string)$c['f'], 0, 2000);
        if($out){ $cells[$ref] = $out; if(++$n >= gridMaxCells()) break; }
    }
    foreach($g['fmt'] as $ref => $f){
        if(!gridRefOk($ref) || !is_array($f)) continue;
        $out = [];
        if(!empty($f['b'])) $out['b'] = 1;
        if(!empty($f['i'])) $out['i'] = 1;
        if(isset($f['a']) && in_array($f['a'], ['left','center','right'], true)) $out['a'] = $f['a'];
        if(isset($f['bg']) && preg_match('~^#[0-9a-fA-F]{3,6}$~', $f['bg'])) $out['bg'] = $f['bg'];
        if(isset($f['fc']) && preg_match('~^#[0-9a-fA-F]{3,6}$~', $f['fc'])) $out['fc'] = $f['fc'];
        if(isset($f['n']) && is_string($f['n']) && $f['n'] !== '' && strlen($f['n']) <= 60 && !preg_match('~[<>]~', $f['n'])) $out['n'] = $f['n'];
        if($out) $fmt[$ref] = $out;
    }
    foreach($g['widths'] as $col => $w){
        if(!preg_match('~^[A-Z]{1,3}$~', $col)) continue;
        $w = (int)$w; if($w >= 40 && $w <= 600) $widths[$col] = $w;
    }
    return ['name'=>$g['name'], 'rows'=>$g['rows'], 'cols'=>$g['cols'],
            'cells'=>$cells, 'fmt'=>$fmt, 'widths'=>$widths];
}

/** ทำความสะอาดทั้งสมุดงาน */
function gridSanitize($data){
    $b = gridParse($data);
    $sheets = [];
    foreach($b['sheets'] as $sh) $sheets[] = gridSanitizeSheet($sh);
    if(!$sheets) $sheets = [gridBlankSheet()];
    return ['sheets'=>$sheets, 'active'=>min($b['active'], count($sheets) - 1)];
}

// ── คำนวณสูตรฝั่งเซิร์ฟเวอร์ (ใช้ตอนแสดงผลและตอนส่งออก) ──
/** คืนค่าที่คำนวณแล้วของทุกช่อง: ['A1'=>'ยอดขาย', 'B5'=>120, ...] */
function gridEvaluate($g){
    $g = gridSheetNorm(isset($g['cells']) ? $g : gridSheet($g));
    $out = []; $busy = [];
    foreach($g['cells'] as $ref => $c) $out[$ref] = gridCellValue($g, $ref, $busy);
    return $out;
}

function gridCellValue($g, $ref, &$busy){
    if(isset($busy[$ref])) return '#REF';                       // สูตรวนกลับมาหาตัวเอง
    $c = $g['cells'][$ref] ?? null;
    if(!$c) return '';
    if(!isset($c['f']) || $c['f'] === '') return $c['v'] ?? '';
    // ค่าที่คำนวณไว้แล้ว (จากตัวแก้ไข หรือจาก Excel ตอนนำเข้า) แม่นกว่าตัวคำนวณอย่างง่ายฝั่งเซิร์ฟเวอร์
    // (ช่องสูตรที่มีคีย์ v แม้เป็นค่าว่าง = คำนวณแล้วได้ค่าว่าง; ไฟล์รุ่นเก่าไม่มีคีย์ v จึงคำนวณเอง)
    if(array_key_exists('v', $c) && $c['v'] !== null) return $c['v'];
    $busy[$ref] = true;
    $res = gridEvalFormula($g, (string)$c['f'], $busy);
    unset($busy[$ref]);
    return $res;
}

/** แปลงสูตรเป็นตัวเลข — รองรับ + - * / ( ) , อ้างอิงช่อง ช่วง และฟังก์ชันพื้นฐาน */
function gridEvalFormula($g, $formula, &$busy){
    $f = trim($formula);
    if($f === '' || $f[0] !== '=') return $f;
    $expr = substr($f, 1);

    // ฟังก์ชันรวมค่า: SUM/AVERAGE/MIN/MAX/COUNT ที่รับช่วงได้
    $expr = preg_replace_callback('~\b(SUM|AVERAGE|AVG|MIN|MAX|COUNT|COUNTA)\s*\(([^()]*)\)~i',
        function($m) use($g, &$busy){
            $nums = []; $texts = 0;
            foreach(explode(',', $m[2]) as $part){
                foreach(gridExpandRange(trim($part)) as $ref){
                    $v = gridCellValue($g, $ref, $busy);
                    if($v === '' || $v === null) continue;
                    if(is_numeric($v)) $nums[] = (float)$v; else $texts++;
                }
            }
            $fn = strtoupper($m[1]);
            if($fn === 'COUNT')  return (string)count($nums);
            if($fn === 'COUNTA') return (string)(count($nums) + $texts);
            if(!$nums) return '0';
            if($fn === 'SUM') return (string)array_sum($nums);
            if($fn === 'AVERAGE' || $fn === 'AVG') return (string)(array_sum($nums) / count($nums));
            if($fn === 'MIN') return (string)min($nums);
            if($fn === 'MAX') return (string)max($nums);
            return '0';
        }, $expr);
    if($expr === null) return '#ERR';

    // ROUND(x,n) / ABS(x) / INT(x)
    $expr = preg_replace_callback('~\bROUND\s*\(([^(),]*),([^(),]*)\)~i', function($m) use($g,&$busy){
        $a = gridNum(gridEvalFormula($g, '='.$m[1], $busy));
        $b = (int)gridNum(gridEvalFormula($g, '='.$m[2], $busy));
        return (string)round($a, max(0, min(8, $b)));
    }, $expr);
    $expr = preg_replace_callback('~\b(ABS|INT)\s*\(([^()]*)\)~i', function($m) use($g,&$busy){
        $a = gridNum(gridEvalFormula($g, '='.$m[2], $busy));
        return (string)(strtoupper($m[1]) === 'ABS' ? abs($a) : (int)$a);
    }, $expr);

    // IF(cond, a, b)
    $expr = preg_replace_callback('~\bIF\s*\(([^(),]*),([^(),]*),([^(),]*)\)~i', function($m) use($g,&$busy){
        $cond = gridEvalCompare($g, $m[1], $busy);
        $pick = $cond ? $m[2] : $m[3];
        $r = gridEvalFormula($g, '='.$pick, $busy);
        return is_numeric($r) ? (string)$r : '"'.str_replace('"','',(string)$r).'"';
    }, $expr);

    // อ้างอิงช่องเดี่ยว → ค่าตัวเลข
    $expr = preg_replace_callback('~\b([A-Z]{1,2}[1-9][0-9]{0,3})\b~', function($m) use($g,&$busy){
        return (string)gridNum(gridCellValue($g, $m[1], $busy));
    }, $expr);

    // เหลือเฉพาะตัวเลขและเครื่องหมายคำนวณเท่านั้นจึงจะคิดต่อ
    if(preg_match('~^[\s0-9%.+\-*/()]*$~', $expr)){
        $val = gridCalcArithmetic($expr);
        return $val === null ? '#ERR' : $val;
    }
    if(preg_match('~^"([^"]*)"$~', trim($expr), $m)) return $m[1];
    return '#ERR';
}

function gridEvalCompare($g, $cond, &$busy){
    if(preg_match('~^(.*?)(>=|<=|<>|!=|=|>|<)(.*)$~', $cond, $m)){
        $l = gridNum(gridEvalFormula($g, '='.$m[1], $busy));
        $r = gridNum(gridEvalFormula($g, '='.$m[3], $busy));
        switch($m[2]){
            case '>=': return $l >= $r;  case '<=': return $l <= $r;
            case '>':  return $l >  $r;  case '<':  return $l <  $r;
            case '<>': case '!=': return $l != $r;
            default:   return $l == $r;
        }
    }
    return gridNum(gridEvalFormula($g, '='.$cond, $busy)) != 0;
}

function gridNum($v){
    if(is_numeric($v)) return (float)$v;
    $v = preg_replace('~[,\s]~', '', (string)$v);
    return is_numeric($v) ? (float)$v : 0.0;
}

/** "B2:B7" → ['B2','B3',...] | "B2" → ['B2'] */
function gridExpandRange($ref){
    $ref = strtoupper(trim($ref));
    if(preg_match('~^([A-Z]{1,2})([1-9][0-9]{0,3}):([A-Z]{1,2})([1-9][0-9]{0,3})$~', $ref, $m)){
        $c1 = gridColIndex($m[1]); $c2 = gridColIndex($m[3]);
        $r1 = (int)$m[2];          $r2 = (int)$m[4];
        if($c1 > $c2){ $t=$c1; $c1=$c2; $c2=$t; }
        if($r1 > $r2){ $t=$r1; $r1=$r2; $r2=$t; }
        if(($c2-$c1+1)*($r2-$r1+1) > 5000) return [];            // กันช่วงใหญ่เกินไป
        $out = [];
        for($c = $c1; $c <= $c2; $c++) for($r = $r1; $r <= $r2; $r++) $out[] = gridColName($c).$r;
        return $out;
    }
    if(preg_match('~^[A-Z]{1,2}[1-9][0-9]{0,3}$~', $ref)) return [$ref];
    return [];
}

/** คิดเลขจากสตริงที่มีแต่ตัวเลขและเครื่องหมาย (ไม่ใช้ eval) */
function gridCalcArithmetic($expr){
    $tokens = [];
    if(!preg_match_all('~\d+\.?\d*|[+\-*/()%]~', $expr, $m)) return null;
    $tokens = $m[0];
    $pos = 0;
    $parseExpr = function() use(&$parseExpr, &$tokens, &$pos){
        $val = null; $parseTerm = null;
        // term: factor (*|/ factor)*
        $parseFactor = function() use(&$parseExpr, &$tokens, &$pos, &$parseFactor){
            if($pos >= count($tokens)) return null;
            $t = $tokens[$pos];
            if($t === '('){ $pos++; $v = $parseExpr(); if(isset($tokens[$pos]) && $tokens[$pos] === ')') $pos++; return $v; }
            if($t === '-'){ $pos++; $v = $parseFactor(); return $v === null ? null : -$v; }
            if($t === '+'){ $pos++; return $parseFactor(); }
            if(is_numeric($t)){ $pos++;
                if(isset($tokens[$pos]) && $tokens[$pos] === '%'){ $pos++; return ((float)$t)/100; }
                return (float)$t; }
            return null;
        };
        $v = $parseFactor();
        if($v === null) return null;
        while($pos < count($tokens) && ($tokens[$pos] === '*' || $tokens[$pos] === '/')){
            $op = $tokens[$pos++]; $r = $parseFactor();
            if($r === null) return null;
            if($op === '/'){ if((float)$r == 0.0) return null; $v = $v / $r; } else $v = $v * $r;
        }
        while($pos < count($tokens) && ($tokens[$pos] === '+' || $tokens[$pos] === '-')){
            $op = $tokens[$pos++];
            // ฝั่งขวาของ + - ต้องคิด * / ให้เสร็จก่อน
            $r = $parseFactor();
            if($r === null) return null;
            while($pos < count($tokens) && ($tokens[$pos] === '*' || $tokens[$pos] === '/')){
                $op2 = $tokens[$pos++]; $r2 = $parseFactor();
                if($r2 === null) return null;
                if($op2 === '/'){ if((float)$r2 == 0.0) return null; $r = $r / $r2; } else $r = $r * $r2;
            }
            $v = ($op === '+') ? $v + $r : $v - $r;
        }
        return $v;
    };
    $val = $parseExpr();
    if($val === null || $pos < count($tokens)) return null;
    if(!is_finite($val)) return null;
    return rtrim(rtrim(number_format($val, 10, '.', ''), '0'), '.') ?: '0';
}

// ── รูปแบบตัวเลข (ตรรกะเดียวกับ dashboard/js/grid-engine.js) ──
function gridFmtGeneral($n){
    if(!is_finite($n)) return '#NUM!';
    if(floor($n) == $n && abs($n) < 1e15) return (string)(int)$n;
    $r = round($n, 10);
    $s = rtrim(rtrim(sprintf('%.10F', $r), '0'), '.');
    if(abs($r) >= 1e21 || (abs($r) > 0 && abs($r) < 1e-6)) $s = preg_replace('~\.?0+e~', 'e', sprintf('%.9e', $r));
    return $s;
}
function gridNumStr($s){
    $s = trim((string)$s);
    if($s === '') return null;
    $neg = false;
    if(preg_match('~^\((.*)\)$~', $s, $m)){ $neg = true; $s = $m[1]; }
    $pct = substr($s, -1) === '%'; if($pct) $s = substr($s, 0, -1);
    $s = str_replace(',', '', $s);
    if(!preg_match('~^[+-]?(\d+\.?\d*|\.\d+)([eE][+-]?\d+)?$~', $s)) return null;
    $v = (float)$s; if($pct) $v /= 100;
    return $neg ? -$v : $v;
}
function gridSplitSections($code){
    $out = []; $cur = ''; $q = false;
    for($i = 0, $n = strlen($code); $i < $n; $i++){
        $ch = $code[$i];
        if($ch === '"') $q = !$q;
        if($ch === ';' && !$q){ $out[] = $cur; $cur = ''; continue; }
        $cur .= $ch;
    }
    $out[] = $cur;
    return $out;
}
/** ข้อความที่แสดงของค่า $v ตามรหัสรูปแบบแบบ Excel ($code) */
function gridFormatValue($v, $code){
    $v = (string)$v;
    if($v === '' || preg_match('~^#(DIV/0!|N/A|VALUE!|REF!|NAME\?|NUM!|NULL!)$~', $v)) return $v;
    $code = (string)$code; if($code === '') $code = 'General';
    $sections = gridSplitSections($code);
    $num = ($v === 'TRUE' || $v === 'FALSE') ? null : gridNumStr($v);
    if($num === null){
        $tsec = count($sections) >= 4 ? $sections[3] : (strpos($code, '@') !== false ? $code : null);
        if($tsec !== null) return str_replace('@', $v, preg_replace('~"([^"]*)"~', '$1', $tsec));
        return $v;
    }
    $sec = $sections[0]; $negSec = false;
    if(count($sections) > 1 && $num < 0 && $sections[1] !== ''){ $sec = $sections[1]; $negSec = true; $num = abs($num); }
    elseif(count($sections) > 2 && $num == 0 && $sections[2] !== '') $sec = $sections[2];
    if(trim($sec) === '' || preg_match('~^\s*general\s*$~i', $sec)) return ($negSec ? '-' : '').gridFmtGeneral($num);
    $sec = preg_replace('~\[[^\]]*\]~', '', $sec);
    $plain = preg_replace(['~"[^"]*"~', '~\\\\.~'], '', $sec);
    if(preg_match('~[dmyhs]~i', $plain) && !preg_match('~[0#?]~', preg_replace('~s\.0+~i', 's', $plain))) return gridFormatDate($num, $sec);
    return gridFormatNumber($num, $sec, $negSec);
}
function gridFormatDate($serial, $code){
    $days = (int)floor($serial);
    $ts = gmmktime(0, 0, 0, 12, 30, 1899) + $days * 86400;
    $secs = (int)round(($serial - floor($serial)) * 86400);
    $P = ['y'=>(int)gmdate('Y', $ts), 'm'=>(int)gmdate('n', $ts), 'd'=>(int)gmdate('j', $ts), 'dow'=>(int)gmdate('w', $ts),
          'hh'=>intdiv($secs, 3600) % 24, 'mi'=>intdiv($secs, 60) % 60, 'ss'=>$secs % 60];
    $MON = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    $MONF = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    $DOW = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat']; $DOWF = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    $ampm = (bool)preg_match('~am/pm|a/p~i', $code);
    preg_match_all('~"[^"]*"|\\\\.|am/pm|a/p|y+|m+|d+|h+|s+|[^"\\\\ymdhsa]+|.~iu', $code, $mm);
    $tokens = $mm[0]; $out = '';
    $letters = function($arr){ return strtolower(preg_replace(['~"[^"]*"~', '~[^a-z]~i'], '', implode('', $arr))); };
    foreach($tokens as $i => $tk){
        $lo = strtolower($tk);
        if($tk[0] === '"'){ $out .= substr($tk, 1, -1); continue; }
        if($tk[0] === '\\'){ $out .= substr($tk, 1, 1); continue; }
        if($lo === 'am/pm'){ $out .= $P['hh'] < 12 ? 'AM' : 'PM'; continue; }
        if($lo === 'a/p'){ $out .= $P['hh'] < 12 ? 'A' : 'P'; continue; }
        $L = strlen($lo);
        if(preg_match('~^y+$~', $lo)){ $out .= $L <= 2 ? sprintf('%02d', $P['y'] % 100) : $P['y']; continue; }
        if(preg_match('~^d+$~', $lo)){ $out .= $L === 1 ? $P['d'] : ($L === 2 ? sprintf('%02d', $P['d']) : ($L === 3 ? $DOW[$P['dow']] : $DOWF[$P['dow']])); continue; }
        if(preg_match('~^h+$~', $lo)){ $h = $ampm ? (($P['hh'] % 12) ?: 12) : $P['hh']; $out .= $L === 1 ? $h : sprintf('%02d', $h); continue; }
        if(preg_match('~^s+$~', $lo)){ $out .= $L === 1 ? $P['ss'] : sprintf('%02d', $P['ss']); continue; }
        if(preg_match('~^m+$~', $lo)){
            $prev = substr($letters(array_slice($tokens, 0, $i)), -1);
            $next = substr($letters(array_slice($tokens, $i + 1)), 0, 1);
            if(($prev === 'h' || $next === 's') && $L <= 2){ $out .= $L === 1 ? $P['mi'] : sprintf('%02d', $P['mi']); continue; }
            $out .= $L === 1 ? $P['m'] : ($L === 2 ? sprintf('%02d', $P['m']) : ($L === 3 ? $MON[$P['m'] - 1] : $MONF[$P['m'] - 1]));
            continue;
        }
        $out .= $tk;
    }
    return $out;
}
function gridFormatNumber($num, $code, $negSection){
    $quoted = [];
    // ข้อความในเครื่องหมายคำพูดแทนด้วยอักษร private-use ตัวเดียว (ไม่มีเลข 0 ปนให้ถูกนับเป็นหลักตัวเลข)
    $work = preg_replace_callback('~"([^"]*)"|\\\\(.)~u', function($m) use (&$quoted){
        $quoted[] = isset($m[2]) ? $m[2] : $m[1];
        return mb_chr(0xE000 + count($quoted) - 1, 'UTF-8');
    }, $code);
    $work = preg_replace(['~_.~u', '~\*.~u'], [' ', ''], $work);
    $back = function($s) use (&$quoted){
        return preg_replace_callback('~[\x{E000}-\x{F8FF}]~u', function($m) use (&$quoted){
            $k = mb_ord($m[0], 'UTF-8') - 0xE000; return $quoted[$k] ?? $m[0];
        }, $s);
    };
    if(!preg_match('~[#0?]~', $work, $fm, PREG_OFFSET_CAPTURE)) return $back($work);
    $first = $fm[0][1];
    $end = max((int)strrpos($work, '#'), (int)strrpos($work, '0'), (int)strrpos($work, '?')) + 1;
    while($end < strlen($work) && strpos(',%', $work[$end]) !== false) $end++;
    $start = $first;
    while($start > 0 && strpos(',.', $work[$start - 1]) !== false) $start--;
    $pre = $back(substr($work, 0, $start)); $body = substr($work, $start, $end - $start); $post = $back((string)substr($work, $end));
    if(strpos($body, '%') !== false || strpos($post, '%') !== false) $num *= 100;
    if(strpos($body, '%') !== false) $post = '%'.$post;
    $body = str_replace('%', '', $body);
    if(preg_match('~,+$~', $body, $tr)){ $num /= pow(1000, strlen($tr[0])); $body = rtrim($body, ','); }
    $parts = explode('.', $body, 2);
    $intPat = $parts[0]; $decPat = $parts[1] ?? '';
    $decs = preg_match_all('~[0#?]~', $decPat); $minDec = substr_count($decPat, '0');
    $neg = $num < 0 && !$negSection; $num = abs($num);
    $fixed = number_format($num, $decs, '.', '');
    $ip = explode('.', $fixed)[0]; $dp = explode('.', $fixed)[1] ?? '';
    while(strlen($dp) > $minDec && substr($dp, -1) === '0') $dp = substr($dp, 0, -1);
    $minInt = substr_count($intPat, '0');
    $ip = str_pad($ip, $minInt, '0', STR_PAD_LEFT);
    if($ip === '0' && $minInt === 0) $ip = '';
    if(strpos($intPat, ',') !== false) $ip = preg_replace('~\B(?=(\d{3})+(?!\d))~', ',', $ip);
    $s = $ip.($dp !== '' ? '.'.$dp : '');
    if($s === '') $s = '0';
    return ($neg ? '-' : '').$pre.$s.$post;
}

/** แสดงสมุดงานเป็น HTML อ่านอย่างเดียว (หน้าอ่านเอกสาร / ในบทเรียน) */
function gridRenderHtml($json){
    $book = gridSanitize($json);
    $multi = count($book['sheets']) > 1;
    $uid = 'gv'.substr(md5(uniqid('', true)), 0, 6);

    $h = '<div class="ag-book" id="'.$uid.'">';
    if($multi){
        $h .= '<div class="ag-tabs">';
        foreach($book['sheets'] as $i => $sh){
            $h .= '<button type="button" class="ag-tab'.($i === 0 ? ' on' : '').'" data-sheet="'.$i.'">'
                 .htmlspecialchars($sh['name']).'</button>';
        }
        $h .= '</div>';
    }
    foreach($book['sheets'] as $i => $g){
        $vals = gridEvaluate($g);
        // วาดเฉพาะขอบเขตที่มีข้อมูลจริง — ชีตใหญ่ (หลายพันแถว × หลายร้อยคอลัมน์) จะได้ไม่วนช่องว่าง
        $maxR = 0; $maxC = -1;
        foreach($g['cells'] as $ref => $_){
            if(!preg_match('~^([A-Z]{1,3})(\d+)$~', $ref, $m)) continue;
            $maxR = max($maxR, (int)$m[2]); $maxC = max($maxC, gridColIndex($m[1]));
        }
        $maxR = min($maxR, $g['rows']); $maxC = min($maxC, $g['cols'] - 1);
        $colNames = [];
        for($c = 0; $c <= $maxC; $c++) $colNames[$c] = gridColName($c);
        $h .= '<div class="ag-view"'.($i === 0 ? '' : ' hidden').' data-sheet="'.$i.'">';
        $h .= '<table class="ag-table"><tbody>';
        for($r = 1; $r <= $maxR; $r++){
            $rowHtml = ''; $has = false;
            for($c = 0; $c <= $maxC; $c++){
                $ref = $colNames[$c].$r;
                $v   = $vals[$ref] ?? ($g['cells'][$ref]['v'] ?? '');
                if($v !== '' && $v !== null) $has = true;
                $f   = $g['fmt'][$ref] ?? [];
                $st  = '';
                if(!empty($f['b']))  $st .= 'font-weight:700;';
                if(!empty($f['i']))  $st .= 'font-style:italic;';
                if(!empty($f['a']))  $st .= 'text-align:'.$f['a'].';';
                if(!empty($f['bg'])) $st .= 'background:'.$f['bg'].';';
                if(!empty($f['fc'])) $st .= 'color:'.$f['fc'].';';
                if(empty($f['a']) && gridNumStr($v) !== null && is_numeric($v)) $st .= 'text-align:right;';
                $show = (!empty($f['n']) && $v !== '' && $v !== null) ? gridFormatValue($v, $f['n']) : (string)$v;
                $rowHtml .= '<td'.($st ? ' style="'.$st.'"' : '').'>'.htmlspecialchars($show).'</td>';
            }
            if($has) $h .= '<tr>'.$rowHtml.'</tr>';
        }
        $h .= '</tbody></table></div>';
    }
    $h .= '</div>';
    if($multi){
        $h .= '<script>(function(){var b=document.getElementById("'.$uid.'");if(!b)return;'
             .'b.addEventListener("click",function(e){var t=e.target.closest?e.target.closest(".ag-tab"):null;if(!t)return;'
             .'b.querySelectorAll(".ag-tab").forEach(function(x){x.classList.toggle("on",x===t);});'
             .'b.querySelectorAll(".ag-view").forEach(function(v){v.hidden=(v.dataset.sheet!==t.dataset.sheet);});});})();</script>';
    }
    return $h;
}

}
