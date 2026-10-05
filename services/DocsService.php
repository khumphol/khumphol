<?php
// ============================================================
// Aleanor Docs — Write / Grid / Present (พอร์ตจาก aleanor_ai/core/document.core.php)
//
//   write    → เอกสารข้อความ (เก็บเป็น HTML ที่กรองแล้ว)
//   grid     → ตารางคำนวณ    (เก็บเป็น JSON — core/docs/grid.core.php)
//   present  → สไลด์นำเสนอ    (เก็บเป็น JSON — core/docs/present.core.php)
//
// ต่างจากต้นฉบับ: ใช้ id เป็น INT (owner_id → users.id, course_id → courses.id),
// prepared statement ทุกคำสั่ง, งานหลายตารางทำใน db_tx() เดียว
//
// ถังขยะ: status 1 = ใช้งาน · 2 = อยู่ในถังขยะ · (ลบถาวร = ลบแถวทิ้งจริง)
//   trash_of = โฟลเดอร์ที่ลากของชิ้นนี้ลงถังไปด้วย → ถังขยะโชว์แค่รายการหลัก
// โควตา: ขนาดเนื้อหาเอกสาร + asset + ไฟล์อัปโหลด (ของในถังยังนับจนกว่าจะลบถาวร)
// ============================================================

require_once dirname(__DIR__).'/core/docs/lang.php';
require_once dirname(__DIR__).'/core/docs/grid.core.php';
require_once dirname(__DIR__).'/core/docs/present.core.php';

class DocsService
{
    const KINDS = ['folder', 'doc', 'file'];

    // ── ชนิดเอกสาร ──────────────────────────────────────────
    public static function types(){
        return [
            'write'   => ['name' => 'Aleanor Write',   'icon' => 'fi-rr-document',     'img' => 'assets/docs/write.png',   'color' => '#2563eb', 'ext' => 'docx'],
            'grid'    => ['name' => 'Aleanor Grid',    'icon' => 'fi-rr-table-layout', 'img' => 'assets/docs/grid.png',    'color' => '#059669', 'ext' => 'xlsx'],
            'present' => ['name' => 'Aleanor Present', 'icon' => 'fi-rr-presentation', 'img' => 'assets/docs/present.png', 'color' => '#d97706', 'ext' => 'pptx'],
        ];
    }
    public static function typeInfo($t){ $all = self::types(); return $all[$t] ?? $all['write']; }
    public static function typeValid($t){ return is_string($t) && array_key_exists($t, self::types()); }

    /** ไอคอนของชนิดเอกสาร — ใช้รูปจริงถ้ามี ไม่งั้นถอยไปใช้ไอคอนฟอนต์ */
    public static function typeIcon($type, $size = 22, $class = ''){
        $ti = self::typeInfo($type);
        if(!empty($ti['img']) && is_file(dirname(__DIR__).'/'.$ti['img'])){
            return '<img src="'.h(asset($ti['img'])).'" alt="" width="'.(int)$size.'" height="'.(int)$size.'"'
                 .($class ? ' class="'.h($class).'"' : '')
                 .' style="width:'.(int)$size.'px;height:'.(int)$size.'px;object-fit:contain;vertical-align:middle">';
        }
        return '<i class="fi '.h($ti['icon']).($class ? ' '.h($class) : '').'" style="font-size:'.(int)$size.'px"></i>';
    }

    /** ไอคอนตามนามสกุลไฟล์ → [class, สี] */
    public static function fileIcon($ext){
        $ext = strtolower((string)$ext);
        $map = [
            'pdf'=>['fi-rr-file-pdf','#dc2626'],
            'doc'=>['fi-rr-file-word','#2563eb'], 'docx'=>['fi-rr-file-word','#2563eb'], 'odt'=>['fi-rr-file-word','#2563eb'], 'rtf'=>['fi-rr-file-word','#2563eb'],
            'xls'=>['fi-rr-file-excel','#059669'],'xlsx'=>['fi-rr-file-excel','#059669'],'csv'=>['fi-rr-file-excel','#059669'],'ods'=>['fi-rr-file-excel','#059669'],
            'ppt'=>['fi-rr-file-powerpoint','#d97706'], 'pptx'=>['fi-rr-file-powerpoint','#d97706'], 'odp'=>['fi-rr-file-powerpoint','#d97706'],
            'png'=>['fi-rr-picture','#7c3aed'], 'jpg'=>['fi-rr-picture','#7c3aed'], 'jpeg'=>['fi-rr-picture','#7c3aed'],
            'gif'=>['fi-rr-picture','#7c3aed'], 'webp'=>['fi-rr-picture','#7c3aed'],
            'zip'=>['fi-rr-file-zipper','#64748b'], 'txt'=>['fi-rr-file','#64748b'],
        ];
        return $map[$ext] ?? ['fi-rr-file','#64748b'];
    }

    /** นามสกุลที่อนุญาตให้อัปโหลด (ไม่มีสคริปต์ / html / svg) */
    public static function allowedUploadExt(){
        return ['pdf','docx','doc','xlsx','xls','pptx','ppt','txt','csv','rtf','odt','ods','odp',
                'png','jpg','jpeg','gif','webp','zip'];
    }
    /** นามสกุลไฟล์ที่แปลงเป็นเอกสารในระบบได้ → ชนิดเอกสาร */
    public static function convertibleExt(){ return ['xlsx'=>'grid', 'csv'=>'grid', 'docx'=>'write', 'pptx'=>'present']; }

    /** ขนาดเนื้อหาเอกสารสูงสุดที่รับบันทึก (ไบต์) */
    public static function maxContentBytes(){ return 48 * 1024 * 1024; }

    /** เอกสารใหญ่ต้องใช้หน่วยความจำตอนแปลง JSON ราว 12 เท่าของขนาด */
    public static function raiseMemory($bytes){
        $need = 64 * 1024 * 1024 + (int)$bytes * 12;
        $cur = trim((string)ini_get('memory_limit'));
        if($cur === '-1') return;
        $n = (int)$cur; $u = strtolower(substr($cur, -1));
        if($u === 'g') $n *= 1073741824; elseif($u === 'm') $n *= 1048576; elseif($u === 'k') $n *= 1024;
        if($n < $need) @ini_set('memory_limit', (string)min(1024, (int)ceil($need / 1048576)).'M');
    }

    // ── ตัวกรอง HTML (allowlist) ─────────────────────────────
    // อนุญาตเฉพาะแท็ก/แอตทริบิวต์ที่ตัวแก้ไขสร้างได้จริง นอกนั้นตัดทิ้งแต่เก็บข้อความไว้
    public static function allowedTags(){
        return [
            'p'=>['style'], 'br'=>[], 'hr'=>[],
            'h1'=>['style'],'h2'=>['style'],'h3'=>['style'],'h4'=>['style'],
            'strong'=>[], 'b'=>[], 'em'=>[], 'i'=>[], 'u'=>[], 's'=>[], 'sub'=>[], 'sup'=>[],
            'ul'=>['style'], 'ol'=>['style'], 'li'=>['style'],
            'blockquote'=>[], 'pre'=>[], 'code'=>[],
            'table'=>['style'], 'thead'=>[], 'tbody'=>[], 'tr'=>[],
            'th'=>['colspan','rowspan','style'], 'td'=>['colspan','rowspan','style'],
            'a'=>['href','target','rel'],
            'img'=>['src','alt','width','height','style'],
            'span'=>['style'], 'div'=>['style'],
            'figure'=>[], 'figcaption'=>[],
        ];
    }
    public static function allowedStyles(){
        return ['text-align','font-weight','font-style','text-decoration','color','background-color',
                'width','height','max-width','margin','margin-left','margin-right','padding-left',
                'font-size','line-height','display','float','border-radius'];
    }

    /** กรอง style ให้เหลือเฉพาะ property ที่อนุญาต และค่าที่ไม่มี url()/expression */
    public static function cleanStyle($style){
        $ok = self::allowedStyles(); $out = [];
        foreach(explode(';', (string)$style) as $rule){
            if(strpos($rule, ':') === false) continue;
            [$k, $v] = array_map('trim', explode(':', $rule, 2));
            $k = strtolower($k);
            if(!in_array($k, $ok, true)) continue;
            if(preg_match('~url\s*\(|expression|javascript:|@import|\\\\~i', $v)) continue;
            if(strlen($v) > 80) continue;
            // จำกัดค่าที่รับได้ของบาง property (กันเอาไปซ่อน/ลอยทับเนื้อหา)
            if($k === 'display' && !in_array(strtolower($v), ['block','inline','inline-block'], true)) continue;
            if($k === 'float'   && !in_array(strtolower($v), ['left','right','none'], true)) continue;
            $out[] = $k.':'.$v;
        }
        return implode(';', $out);
    }

    /** ลิงก์ปลอดภัยไหม (กัน javascript: / data:) */
    public static function safeHref($href){
        $href = trim((string)$href);
        if($href === '') return '';
        if(preg_match('~^(?:https?://|mailto:|tel:|/|\#)~i', $href)) return $href;
        if(preg_match('~^[a-z][a-z0-9+.-]*:~i', preg_replace('~[\x00-\x20]+~', '', $href))) return '';   // สคีมอื่น = ตัดทิ้ง
        return $href;                                                    // path สัมพัทธ์ภายในเว็บ
    }
    /** รูปภาพ: อนุญาตเฉพาะไฟล์ในระบบ, http(s) และ data:image */
    public static function safeImgSrc($src){
        $src = trim((string)$src);
        if($src === '') return '';
        if(preg_match('~^data:image/(png|jpeg|jpg|gif|webp);base64,[A-Za-z0-9+/=\s]+$~i', $src)) return $src;
        if(preg_match('~^https?://~i', $src)) return $src;
        if(preg_match('~^[a-z][a-z0-9+.-]*:~i', preg_replace('~[\x00-\x20]+~', '', $src))) return '';
        return $src;
    }

    /** กรอง HTML ของเอกสาร — เรียกทุกครั้งก่อนบันทึกและก่อนแสดงผล */
    public static function sanitizeHtml($html){
        $html = (string)$html;
        if(trim($html) === '') return '';
        $html = preg_replace('~<(script|style|iframe|object|embed|form|input|link|meta|template|svg|math)\b[^>]*>.*?</\1\s*>~is', '', $html);
        $html = preg_replace('~<(script|style|iframe|object|embed|form|input|link|meta|template|svg|math)\b[^>]*/?>~is', '', $html);

        $allowed = self::allowedTags();
        $doc = new DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"><div id="aleanor-doc-root">'.$html.'</div>',
                       LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        $root = $doc->getElementById('aleanor-doc-root');
        if(!$root) return '';

        // คอมเมนต์ / processing instruction ทิ้งหมด
        $xp = new DOMXPath($doc);
        foreach(iterator_to_array($xp->query('.//comment() | .//processing-instruction()', $root), false) as $n) $n->parentNode->removeChild($n);

        // ไล่จากใบไปราก เพื่อให้ลบ/แทนที่ระหว่างวนไม่พลาดโหนด
        foreach(array_reverse(iterator_to_array($xp->query('.//*', $root), false)) as $el){
            if(!$el->parentNode) continue;
            $tag = strtolower($el->nodeName);
            if(!isset($allowed[$tag])){
                // ไม่อนุญาต → ถอดแท็กออกแต่เก็บลูกไว้
                while($el->firstChild) $el->parentNode->insertBefore($el->firstChild, $el);
                $el->parentNode->removeChild($el);
                continue;
            }
            foreach(iterator_to_array($el->attributes, false) as $attr){
                $an = strtolower($attr->nodeName);
                if(!in_array($an, $allowed[$tag], true)){ $el->removeAttribute($attr->nodeName); continue; }
                $av = $attr->nodeValue;
                if($an === 'style'){
                    $clean = self::cleanStyle($av);
                    if($clean === '') $el->removeAttribute('style'); else $el->setAttribute('style', $clean);
                }elseif($an === 'href'){
                    $clean = self::safeHref($av);
                    if($clean === '') $el->removeAttribute('href'); else $el->setAttribute('href', $clean);
                }elseif($an === 'src'){
                    $clean = self::safeImgSrc($av);
                    if($clean === ''){ $el->parentNode->removeChild($el); continue 2; }
                    $el->setAttribute('src', $clean);
                }elseif(in_array($an, ['width','height','colspan','rowspan'], true)){
                    $el->setAttribute($an, (string)max(0, (int)$av));
                }elseif($an === 'target'){
                    $el->setAttribute('target', '_blank');
                    $el->setAttribute('rel', 'noopener noreferrer');
                }elseif($an === 'rel'){
                    $el->setAttribute('rel', 'noopener noreferrer');
                }
            }
        }
        $out = '';
        foreach($root->childNodes as $child) $out .= $doc->saveHTML($child);
        return trim($out);
    }

    /** เก็บรูปเป็น path สัมพัทธ์กับรากแอปเสมอ (uploads/docs/..) — ย้ายโดเมน/โฟลเดอร์แล้วรูปยังขึ้น */
    public static function toStorage($html){
        $base = app_base();
        if($base === '/') return (string)$html;
        return str_replace(['src="'.$base, "src='".$base], ['src="', "src='"], (string)$html);
    }
    /** กรอง + เติมพาธฐานให้รูป — ใช้ทุกครั้งที่จะแสดงเอกสาร write */
    public static function renderWriteHtml($html){
        $clean = self::sanitizeHtml($html);
        return preg_replace('~(<img\b[^>]*\bsrc=")(?!https?://|data:|/)~i', '$1'.app_base(), $clean);
    }

    /** เนื้อหาที่บันทึกได้: write = HTML กรองแล้ว, grid/present = JSON ที่ทำความสะอาดแล้ว */
    public static function normalizeContent($type, $content){
        if($type === 'write')   return self::toStorage(self::sanitizeHtml($content));
        if($type === 'grid')    return json_encode(gridSanitize($content), JSON_UNESCAPED_UNICODE);
        if($type === 'present') return json_encode(presentSanitize($content), JSON_UNESCAPED_UNICODE);
        return '';
    }
    /** เนื้อหาเปล่าของเอกสารใหม่ */
    public static function blankContent($type){
        if($type === 'grid')    return json_encode(gridBlank(), JSON_UNESCAPED_UNICODE);
        if($type === 'present') return json_encode(presentBlank(), JSON_UNESCAPED_UNICODE);
        return '';
    }

    // ── อ่าน/เขียนเอกสาร ─────────────────────────────────────
    /** เอกสารที่ยังใช้งานอยู่ (status 1) */
    public static function get($id){
        $id = (int)$id;
        return $id > 0 ? db_one("SELECT * FROM docs_documents WHERE id = ? AND status = 1", [$id]) : null;
    }

    public static function isAdminUser($userId){
        static $cache = [];
        $userId = (int)$userId;
        if(!isset($cache[$userId])) $cache[$userId] = (int)db_val("SELECT is_admin FROM users WHERE id = ? AND status = 1", [$userId]) === 1;
        return $cache[$userId];
    }

    /** แก้ไขได้ไหม — เจ้าของ หรือผู้ดูแล (รับ id หรือแถวเอกสาร) */
    public static function canEdit($doc, $userId){
        if(!is_array($doc)) $doc = self::get((int)$doc);
        $userId = (int)$userId;
        if(!$doc || $userId <= 0) return false;
        if((int)$doc['owner_id'] === $userId) return true;
        return self::isAdminUser($userId);
    }

    /** รายการเอกสารของเจ้าของ (ใช้กับตัวเลือกเอกสารในบทเรียน) */
    public static function listForOwner($userId){
        return array_map(function($r){ return ['id' => (int)$r['id'], 'title' => $r['title'], 'type' => $r['type']]; },
            db_all("SELECT id, title, type FROM docs_documents WHERE owner_id = ? AND status = 1 ORDER BY updated_at DESC, id DESC", [(int)$userId]));
    }

    /** จำนวนเอกสารแต่ละชนิดของเจ้าของ */
    public static function countByType($userId){
        $out = array_fill_keys(array_keys(self::types()), 0);
        foreach(db_all("SELECT type, COUNT(*) n FROM docs_documents WHERE owner_id = ? AND status = 1 GROUP BY type", [(int)$userId]) as $r)
            if(isset($out[$r['type']])) $out[$r['type']] = (int)$r['n'];
        return $out;
    }

    /** คอร์สที่ผูกเอกสารได้ — ของผู้สอนคนนี้เท่านั้น (แอดมินเลือกได้ทุกคอร์ส) */
    public static function coursesFor($userId){
        if(self::isAdminUser($userId)) return db_all("SELECT id, title FROM courses ORDER BY title");
        return db_all("SELECT id, title FROM courses WHERE instructor_id = ? ORDER BY title", [(int)$userId]);
    }
    private static function validCourse($courseId, $userId){
        $courseId = (int)$courseId;
        if($courseId <= 0) return null;
        $ok = self::isAdminUser($userId) ? db_val("SELECT id FROM courses WHERE id = ?", [$courseId])
                                         : db_val("SELECT id FROM courses WHERE id = ? AND instructor_id = ?", [$courseId, (int)$userId]);
        return $ok ? $courseId : null;
    }

    /**
     * บันทึกเอกสาร — $id = 0 สร้างใหม่ · คืน id
     * $fields: type, title, content, course_id (null = ไม่เปลี่ยน/ไม่ผูก), folder_id (สร้างใหม่เท่านั้น)
     */
    public static function save($id, array $fields, $userId){
        $userId = (int)$userId;
        $existing = $id ? self::get($id) : null;
        $type  = $existing ? $existing['type'] : (self::typeValid($fields['type'] ?? '') ? $fields['type'] : 'write');   // ชนิดเปลี่ยนไม่ได้หลังสร้าง
        $title = trim(mb_substr(strip_tags((string)($fields['title'] ?? '')), 0, 200));
        if($title === '') $title = 'ไม่มีชื่อ';
        $content = array_key_exists('content', $fields) ? self::normalizeContent($type, $fields['content']) : null;
        $course  = array_key_exists('course_id', $fields) ? self::validCourse($fields['course_id'], $userId) : false;
        $now = now();

        if($existing){
            return db_tx(function() use($existing, $title, $content, $course, $userId, $now){
                self::snapshot($existing, $userId);                       // เก็บฉบับก่อนหน้าไว้ก่อนเขียนทับ
                db_write("UPDATE docs_documents SET title = ?, content = ?, course_id = ?, updated_at = ? WHERE id = ?", [
                    $title, $content === null ? $existing['content'] : $content,
                    $course === false ? ($existing['course_id'] === null ? null : (int)$existing['course_id']) : $course,
                    $now, (int)$existing['id']]);
                return (int)$existing['id'];
            });
        }
        $folder = self::ownFolder($fields['folder_id'] ?? null, $userId);
        return db_insert("INSERT INTO docs_documents (owner_id, course_id, folder_id, type, title, content, status, created_at, updated_at)
                          VALUES (?,?,?,?,?,?,1,?,?)", [
            $userId, $course === false ? null : $course, $folder, $type, $title,
            $content === null ? self::blankContent($type) : $content, $now, $now]);
    }

    /** ขนาดเอกสาร → จำนวนฉบับที่เก็บ (เล็ก 20 · กลาง 5 · ใหญ่ 3) */
    public static function versionKeep($size){
        return $size < 512 * 1024 ? 20 : ($size < 4 * 1048576 ? 5 : 3);
    }

    /** เก็บฉบับก่อนหน้า — เอกสารใหญ่เก็บน้อยลงและไม่ถี่กว่าทุก 5 นาที */
    public static function snapshot(array $doc, $userId){
        $size = strlen((string)$doc['content']);
        $keep = self::versionKeep($size);
        if($size >= 512 * 1024){
            $last = db_val("SELECT created_at FROM docs_versions WHERE doc_id = ? ORDER BY id DESC LIMIT 1", [(int)$doc['id']]);
            if($last && strtotime($last) > time() - 300) return;
        }
        db_insert("INSERT INTO docs_versions (doc_id, title, content, user_id, created_at) VALUES (?,?,?,?,?)",
            [(int)$doc['id'], (string)$doc['title'], (string)$doc['content'], (int)$userId ?: null, now()]);
        $cut = db_val("SELECT id FROM docs_versions WHERE doc_id = ? ORDER BY id DESC LIMIT 1 OFFSET ".((int)$keep - 1), [(int)$doc['id']]);
        if($cut) db_write("DELETE FROM docs_versions WHERE doc_id = ? AND id < ?", [(int)$doc['id'], (int)$cut]);
    }

    public static function versions($docId, $limit = 20){
        return db_all("SELECT id, title, user_id, created_at, CHAR_LENGTH(content) AS size FROM docs_versions WHERE doc_id = ? ORDER BY id DESC LIMIT ".(int)$limit, [(int)$docId]);
    }

    /** ย้อนกลับไปฉบับเก่า (ฉบับปัจจุบันถูกเก็บเป็น snapshot ก่อน จึงย้อนกลับได้อีก) */
    public static function restoreVersion($docId, $versionId, $userId){
        $doc = self::get($docId);
        if(!$doc || !self::canEdit($doc, $userId)) return false;
        $v = db_one("SELECT * FROM docs_versions WHERE id = ? AND doc_id = ?", [(int)$versionId, (int)$docId]);
        if(!$v) return false;
        self::save((int)$docId, ['title' => $v['title'], 'content' => $v['content']], $userId);
        return true;
    }

    /** ลบเอกสาร = ย้ายลงถังขยะ (ยังกู้คืนได้ และยังนับพื้นที่อยู่จนกว่าจะลบถาวร) */
    public static function delete($id, $userId){
        $d = self::get($id);
        if(!$d || !self::canEdit($d, $userId)) return false;
        db_write("UPDATE docs_documents SET status = 2, deleted_at = ?, trash_of = NULL WHERE id = ?", [now(), (int)$d['id']]);
        return true;
    }

    // ── โควตา ───────────────────────────────────────────────
    /** โควตาของผู้ใช้ (ไบต์) — 0 = ไม่จำกัด · ตั้งค่ากลางที่ settings.docs_quota_mb */
    public static function quotaBytes($userId){
        $mb = (int)setting('docs_quota_mb', '1024');
        return $mb <= 0 ? 0 : $mb * 1024 * 1024;
    }
    /** พื้นที่ที่ใช้ (ไบต์) — ของในถังขยะ (2) ยังนับอยู่ */
    public static function usedBytes($userId){
        $u = (int)$userId;
        return (int)db_val("SELECT COALESCE(SUM(CHAR_LENGTH(content) + asset_size), 0) FROM docs_documents WHERE owner_id = ? AND status IN (1,2)", [$u])
             + (int)db_val("SELECT COALESCE(SUM(size), 0) FROM docs_files WHERE owner_id = ? AND status IN (1,2)", [$u]);
    }
    /** พื้นที่ที่ถังขยะกินอยู่ — ล้างถังแล้วได้คืนเท่านี้ */
    public static function trashBytes($userId){
        $u = (int)$userId;
        return (int)db_val("SELECT COALESCE(SUM(CHAR_LENGTH(content) + asset_size), 0) FROM docs_documents WHERE owner_id = ? AND status = 2", [$u])
             + (int)db_val("SELECT COALESCE(SUM(size), 0) FROM docs_files WHERE owner_id = ? AND status = 2", [$u]);
    }
    /** คำนวณสรุปโควตา (pure — เทสต์ได้ไม่ต้องมีฐานข้อมูล) */
    public static function quotaCalc($limit, $used, $trash = 0){
        $limit = (int)$limit; $used = (int)$used;
        return [
            'limit'     => $limit,
            'used'      => $used,
            'trash'     => (int)$trash,
            'left'      => $limit > 0 ? max(0, $limit - $used) : PHP_INT_MAX,
            'pct'       => $limit > 0 ? min(100, round($used * 100 / $limit, 1)) : 0,
            'full'      => $limit > 0 && $used >= $limit,
            'unlimited' => $limit === 0,
        ];
    }
    public static function quotaInfo($userId){
        return self::quotaCalc(self::quotaBytes($userId), self::usedBytes($userId), self::trashBytes($userId));
    }
    /** พื้นที่พอสำหรับข้อมูลเพิ่ม $need ไบต์ไหม */
    public static function quotaAllows($userId, $need = 0){
        $q = self::quotaInfo($userId);
        return $q['unlimited'] || ($q['used'] + (int)$need) <= $q['limit'];
    }
    public static function formatBytes($b){
        $b = (float)$b;
        if($b >= 1073741824) return number_format($b / 1073741824, 2).' GB';
        if($b >= 1048576)    return number_format($b / 1048576, 1).' MB';
        if($b >= 1024)       return number_format($b / 1024, 0).' KB';
        return number_format($b).' B';
    }

    // ── ที่เก็บไฟล์ของผู้ใช้ uploads/docs/<user_id>/ ─────────
    public static function storageRoot(){
        $dir = dirname(__DIR__).'/uploads/docs/';
        if(!is_dir($dir)) @mkdir($dir, 0755, true);
        // กันรันสคริปต์ซ้ำอีกชั้น (uploads/.htaccess กันไว้แล้ว) + ไม่ให้ดูรายชื่อไฟล์
        if(!is_file($dir.'.htaccess')) @file_put_contents($dir.'.htaccess',
            "# Aleanor Docs — ไฟล์ผู้ใช้: ห้ามรันสคริปต์ ห้ามเปิดดูรายการไฟล์\n"
           ."<FilesMatch \"\\.(php[0-9]?|phtml|phar|pl|py|cgi|sh|html?|svg|js)$\">\n  Require all denied\n</FilesMatch>\n"
           ."Options -Indexes\n<IfModule mod_headers.c>\n  Header set X-Content-Type-Options nosniff\n</IfModule>\n");
        return $dir;
    }
    public static function userDir($userId, $create = true){
        $dir = self::storageRoot().(int)$userId.'/';
        if($create && !is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir;
    }
    public static function userRel($userId){ return 'uploads/docs/'.(int)$userId.'/'; }

    /** ลบไฟล์จริงออกจากดิสก์ (กันพาธหลุดออกนอกโฟลเดอร์ของผู้ใช้) */
    public static function fileUnlink($stored, $userId){
        $stored = basename((string)$stored);
        if($stored === '' || $stored === '.htaccess' || strpos($stored, '..') !== false) return false;
        $p = self::userDir($userId, false).$stored;
        return is_file($p) ? @unlink($p) : false;
    }

    /** ชื่อไฟล์ที่ปลอดภัย (ตัดอักขระที่ใช้ในชื่อไฟล์ไม่ได้) */
    public static function cleanFileName($name, $fallback = 'file'){
        $name = trim(preg_replace('~[\\\\/:*?"<>|\x00-\x1F]~u', '', (string)$name));
        $name = trim(preg_replace('~\s+~u', ' ', $name));
        return $name === '' ? $fallback : mb_substr($name, 0, 200);
    }

    /**
     * รับไฟล์อัปโหลดเข้าที่เก็บของผู้ใช้ — คืน ['ok'=>true,'id'=>..] หรือ ['ok'=>false,'error'=>..]
     * $moveUploaded = false ใช้ตอนเทสต์ (copy แทน move_uploaded_file)
     */
    public static function fileStore($userId, $tmpPath, $origName, $folderId = null, $moveUploaded = true){
        $userId = (int)$userId;
        $size = (int)@filesize($tmpPath);
        if($size <= 0 || $size > 100 * 1024 * 1024) return ['ok' => false, 'error' => 'too_large'];
        $ext = strtolower(pathinfo((string)$origName, PATHINFO_EXTENSION));
        if(!in_array($ext, self::allowedUploadExt(), true)) return ['ok' => false, 'error' => 'bad_type', 'ext' => $ext];
        if(in_array($ext, ['png','jpg','jpeg','gif','webp'], true) && @getimagesize($tmpPath) === false) return ['ok' => false, 'error' => 'bad_type', 'ext' => $ext];
        if(!self::quotaAllows($userId, $size)) return ['ok' => false, 'error' => 'quota'];
        $dir = self::userDir($userId);
        if(!is_dir($dir)) return ['ok' => false, 'error' => 'mkdir_failed'];
        $stored = bin2hex(random_bytes(16)).'.'.$ext;
        $ok = $moveUploaded ? @move_uploaded_file($tmpPath, $dir.$stored) : @copy($tmpPath, $dir.$stored);
        if(!$ok) return ['ok' => false, 'error' => 'write_failed'];
        $name = self::cleanFileName($origName, 'file.'.$ext);
        $id = db_insert("INSERT INTO docs_files (owner_id, folder_id, name, stored_name, ext, size, status, created_at) VALUES (?,?,?,?,?,?,1,?)",
            [$userId, self::ownFolder($folderId, $userId), $name, $stored, $ext, $size, now()]);
        return ['ok' => true, 'id' => $id, 'name' => $name, 'size' => $size];
    }

    /** รูปในเอกสาร (Write / Present) — คืน path สัมพัทธ์ uploads/docs/<uid>/img-xxx.png */
    public static function imageStore($userId, $tmpPath, $moveUploaded = true){
        $size = (int)@filesize($tmpPath);
        if($size <= 0 || $size > 8 * 1024 * 1024) return ['ok' => false, 'error' => 'too_large'];
        if(!self::quotaAllows($userId, $size)) return ['ok' => false, 'error' => 'quota'];
        $info = @getimagesize($tmpPath);
        $map  = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
        if(!$info || !isset($map[$info[2]])) return ['ok' => false, 'error' => 'not_image'];
        $name = 'img-'.bin2hex(random_bytes(12)).'.'.$map[$info[2]];
        $dir = self::userDir($userId);
        $ok = $moveUploaded ? @move_uploaded_file($tmpPath, $dir.$name) : @copy($tmpPath, $dir.$name);
        if(!$ok) return ['ok' => false, 'error' => 'write_failed'];
        return ['ok' => true, 'url' => self::userRel($userId).$name, 'w' => $info[0], 'h' => $info[1]];
    }

    public static function fileGet($id, $userId, $status = 1){
        return db_one("SELECT * FROM docs_files WHERE id = ? AND owner_id = ? AND status = ?", [(int)$id, (int)$userId, (int)$status]);
    }
    /** ลบไฟล์ = ย้ายลงถังขยะ (ไฟล์จริงยังอยู่ กู้คืนได้) */
    public static function fileDelete($id, $userId){
        return db_write("UPDATE docs_files SET status = 2, deleted_at = ?, trash_of = NULL WHERE id = ? AND owner_id = ? AND status = 1",
            [now(), (int)$id, (int)$userId]) > 0;
    }

    /**
     * เปิดไฟล์ Office ที่อัปโหลดไว้ด้วยแอปในระบบ (xlsx/csv → Grid, docx → Write, pptx → Present)
     * แปลงครั้งแรกครั้งเดียวแล้วจำคู่ไว้ใน doc_id — เปิดครั้งถัดไปไปที่เอกสารเดิม
     */
    public static function fileOpen($id, $userId){
        $f = self::fileGet($id, $userId);
        if(!$f) return ['ok' => false, 'error' => 'not_found'];
        $map = self::convertibleExt();
        if(!isset($map[$f['ext']])) return ['ok' => false, 'error' => 'bad_type'];
        if(!empty($f['doc_id']) && ($d = self::get($f['doc_id'])) && (int)$d['owner_id'] === (int)$userId)
            return ['ok' => true, 'id' => (int)$d['id'], 'type' => $d['type'], 'existing' => true];
        $path = self::userDir($userId, false).basename($f['stored_name']);
        if(!is_file($path)) return ['ok' => false, 'error' => 'file_missing'];
        // เนื้อหาที่แปลงแล้วมักใหญ่กว่าไฟล์ต้นฉบับ (xlsx บีบอัดไว้) — กันไว้ราว 3 เท่า
        if(!self::quotaAllows($userId, (int)$f['size'] * 3)) return ['ok' => false, 'error' => 'quota'];
        $res = self::importFile($path, $f['ext'], $f['name'], $userId);
        if(!$res['ok']) return $res;
        $docId = self::save(0, ['type' => $res['type'], 'title' => $res['title'], 'content' => $res['content'],
                                'folder_id' => $f['folder_id']], $userId);
        db_write("UPDATE docs_files SET doc_id = ? WHERE id = ?", [$docId, (int)$f['id']]);
        return ['ok' => true, 'id' => $docId, 'type' => $res['type'], 'existing' => false, 'count' => $res['count']];
    }

    // ── นำเข้า / ส่งออกไฟล์ Office ───────────────────────────
    public static function loadOffice(){
        $d = dirname(__DIR__).'/core/docs/';
        require_once $d.'docx.core.php';
        require_once $d.'xlsx.core.php';
        require_once $d.'pptx.core.php';
    }

    /**
     * แปลงไฟล์ Office เป็นเนื้อหาเอกสาร — รูปในไฟล์แตกไปไว้ที่ uploads/docs/<uid>/
     * คืน ['ok'=>true,'type'=>..,'title'=>..,'content'=>..,'count'=>..] หรือ ['ok'=>false,'error'=>..]
     */
    public static function importFile($path, $ext, $origName, $userId){
        self::loadOffice();
        $ext = strtolower((string)$ext);
        $map = self::convertibleExt();
        if(!isset($map[$ext])) return ['ok' => false, 'error' => 'bad_type'];
        $type = $map[$ext];
        self::raiseMemory(@filesize($path) * ($ext === 'xlsx' ? 12 : 3));
        $imgDir = self::userDir($userId); $imgRel = self::userRel($userId);
        if($ext === 'xlsx')     $r = xlsxImport($path);
        elseif($ext === 'csv')  $r = csvImport($path, $origName);
        elseif($ext === 'docx') $r = docxImport($path, $imgDir, $imgRel);
        else                    $r = pptxImport($path, $imgDir, $imgRel);
        if(empty($r['ok'])) return ['ok' => false, 'error' => 'parse', 'message' => $r['error'] ?? ''];

        $title = trim((string)($r['title'] ?? ''));
        if($title === '' || $title === basename($path) || $title === pathinfo($path, PATHINFO_FILENAME)) $title = pathinfo((string)$origName, PATHINFO_FILENAME);
        if($type === 'grid'){
            $content = json_encode($r['grid'], JSON_UNESCAPED_UNICODE);
            $count = 0; foreach($r['grid']['sheets'] as $sh) $count += count($sh['cells']);
        }elseif($type === 'present'){
            $content = json_encode($r['deck'], JSON_UNESCAPED_UNICODE);
            $count = count($r['deck']['slides'] ?? []);
        }else{
            $content = $r['html'];
            $count = mb_strlen(strip_tags($r['html']));
        }
        return ['ok' => true, 'type' => $type, 'title' => mb_substr($title, 0, 200), 'content' => $content, 'count' => $count];
    }

    /** ส่งออกเป็นไฟล์ — คืน ['bin'=>..,'name'=>..,'mime'=>..] หรือ null */
    public static function export(array $doc){
        self::loadOffice();
        $root = dirname(__DIR__).'/';
        if($doc['type'] === 'write'){
            $ext = 'docx'; $bin = docxExport($doc['title'], self::renderWriteHtml($doc['content']), $root);
        }elseif($doc['type'] === 'grid'){
            self::raiseMemory(strlen((string)$doc['content']));
            $ext = 'xlsx'; $bin = xlsxExport($doc['title'], $doc['content']);
        }elseif($doc['type'] === 'present'){
            $ext = 'pptx'; $bin = pptxExport($doc['title'], $doc['content'], $root);
        }else return null;
        if($bin === false || $bin === null) return null;
        $mime = [
            'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ];
        return ['bin' => $bin, 'name' => mb_substr(self::cleanFileName($doc['title'], 'document'), 0, 80).'.'.$ext, 'mime' => $mime[$ext], 'ext' => $ext];
    }

    /** ส่งออกตาราง grid เป็น CSV (ชีตที่เปิดอยู่ ใช้ค่าที่คำนวณแล้ว) */
    public static function exportCsv(array $doc, $sheetIndex = null){
        if($doc['type'] !== 'grid') return null;
        self::raiseMemory(strlen((string)$doc['content']));
        $book = gridSanitize($doc['content']);
        $i = $sheetIndex === null ? (int)$book['active'] : max(0, min(count($book['sheets']) - 1, (int)$sheetIndex));
        $g = $book['sheets'][$i];
        $vals = gridEvaluate($g);
        $maxR = 0; $maxC = -1;
        foreach($g['cells'] as $ref => $_){
            if(!preg_match('~^([A-Z]{1,3})(\d+)$~', $ref, $m)) continue;
            $maxR = max($maxR, (int)$m[2]); $maxC = max($maxC, gridColIndex($m[1]));
        }
        $fh = fopen('php://temp', 'w+');
        fwrite($fh, "\xEF\xBB\xBF");                       // BOM ให้ Excel อ่านภาษาไทยถูก
        for($r = 1; $r <= $maxR; $r++){
            $row = [];
            for($c = 0; $c <= $maxC; $c++){
                $ref = gridColName($c).$r;
                $v = $vals[$ref] ?? ($g['cells'][$ref]['v'] ?? '');
                if(is_bool($v)) $v = $v ? 'TRUE' : 'FALSE';
                $v = (string)$v;
                if($v !== '' && strpos('=+-@', $v[0]) !== false && !is_numeric($v)) $v = "'".$v;   // กัน CSV injection
                $row[] = $v;
            }
            fputcsv($fh, $row, ',', '"', '\\');
        }
        rewind($fh); $bin = stream_get_contents($fh); fclose($fh);
        return ['bin' => $bin, 'name' => mb_substr(self::cleanFileName($doc['title'], 'sheet'), 0, 80).'.csv', 'mime' => 'text/csv; charset=utf-8', 'ext' => 'csv'];
    }

    // ── โฟลเดอร์ ────────────────────────────────────────────
    public static function folderGet($id){
        $id = (int)$id;
        return $id > 0 ? db_one("SELECT * FROM docs_folders WHERE id = ? AND status = 1", [$id]) : null;
    }
    /** โฟลเดอร์นี้เป็นของผู้ใช้และยังใช้งานอยู่ → คืน id ไม่งั้น null (= ชั้นบนสุด) */
    public static function ownFolder($folderId, $userId){
        $f = self::folderGet($folderId);
        return ($f && (int)$f['owner_id'] === (int)$userId) ? (int)$f['id'] : null;
    }
    /** โฟลเดอร์ลูก (null = ระดับบนสุด) */
    public static function folders($ownerId, $parentId = null){
        $p = (int)$parentId;
        return $p > 0 ? db_all("SELECT * FROM docs_folders WHERE owner_id = ? AND status = 1 AND parent_id = ? ORDER BY name", [(int)$ownerId, $p])
                      : db_all("SELECT * FROM docs_folders WHERE owner_id = ? AND status = 1 AND parent_id IS NULL ORDER BY name", [(int)$ownerId]);
    }
    /** ทุกโฟลเดอร์ของผู้ใช้ (ใช้กับกล่องย้ายไปโฟลเดอร์) พร้อมพาธเต็ม */
    public static function allFolders($ownerId){
        $rows = db_all("SELECT id, parent_id, name FROM docs_folders WHERE owner_id = ? AND status = 1", [(int)$ownerId]);
        $by = []; foreach($rows as $r) $by[(int)$r['id']] = $r;
        $out = [];
        foreach($by as $id => $r){
            $path = [$r['name']]; $p = (int)$r['parent_id']; $guard = 0;
            while($p && isset($by[$p]) && $guard++ < 20){ array_unshift($path, $by[$p]['name']); $p = (int)$by[$p]['parent_id']; }
            $out[] = ['id' => $id, 'path' => implode(' / ', $path)];
        }
        usort($out, function($a, $b){ return strcmp($a['path'], $b['path']); });
        return $out;
    }
    /** เส้นทางโฟลเดอร์ (breadcrumb) จากบนสุดลงมา */
    public static function folderPath($id){
        $path = []; $guard = 0;
        while($id && $guard++ < 20){
            $f = self::folderGet($id);
            if(!$f) break;
            array_unshift($path, $f);
            $id = (int)$f['parent_id'];
        }
        return $path;
    }
    public static function folderCreate($name, $ownerId, $parentId = null){
        $name = trim(mb_substr(strip_tags((string)$name), 0, 150));
        if($name === '') return null;
        return db_insert("INSERT INTO docs_folders (owner_id, parent_id, name, status, created_at) VALUES (?,?,?,1,?)",
            [(int)$ownerId, self::ownFolder($parentId, $ownerId), $name, now()]);
    }
    public static function folderRename($id, $name, $ownerId){
        $f = self::folderGet($id);
        if(!$f || (int)$f['owner_id'] !== (int)$ownerId) return false;
        $name = trim(mb_substr(strip_tags((string)$name), 0, 150));
        if($name === '') return false;
        db_write("UPDATE docs_folders SET name = ? WHERE id = ?", [$name, (int)$id]);
        return true;
    }
    /** id ของโฟลเดอร์นี้ + โฟลเดอร์ลูกหลานทั้งหมดที่สถานะ $status */
    public static function folderTree($id, $ownerId, $status = 1){
        $ids = [(int)$id]; $edge = $ids; $guard = 0;
        while($edge && $guard++ < 30){
            $in = implode(',', array_map('intval', $edge));
            $edge = [];
            foreach(db_all("SELECT id FROM docs_folders WHERE owner_id = ? AND status = ? AND parent_id IN (".$in.")", [(int)$ownerId, (int)$status]) as $r){
                if(!in_array((int)$r['id'], $ids, true)){ $ids[] = (int)$r['id']; $edge[] = (int)$r['id']; }
            }
        }
        return $ids;
    }
    /** ลบโฟลเดอร์ = ลากทั้งโฟลเดอร์ โฟลเดอร์ย่อย และของข้างในลงถังขยะพร้อมกัน (กู้คืนทั้งชุดได้) */
    public static function folderDelete($id, $ownerId){
        $f = self::folderGet($id);
        if(!$f || (int)$f['owner_id'] !== (int)$ownerId) return false;
        $root = (int)$id; $o = (int)$ownerId; $now = now();
        $in = implode(',', self::folderTree($root, $o, 1));
        db_tx(function() use($root, $o, $now, $in){
            db_write("UPDATE docs_documents SET status = 2, deleted_at = ?, trash_of = ? WHERE status = 1 AND owner_id = ? AND folder_id IN (".$in.")", [$now, $root, $o]);
            db_write("UPDATE docs_files SET status = 2, deleted_at = ?, trash_of = ? WHERE status = 1 AND owner_id = ? AND folder_id IN (".$in.")", [$now, $root, $o]);
            // โฟลเดอร์ย่อยติดป้ายว่าถูกลบไปพร้อมโฟลเดอร์แม่ ส่วนตัวโฟลเดอร์เองเป็นรายการหลักในถังขยะ
            db_write("UPDATE docs_folders SET status = 2, deleted_at = ?, trash_of = ? WHERE status = 1 AND owner_id = ? AND id IN (".$in.") AND id <> ?", [$now, $root, $o, $root]);
            db_write("UPDATE docs_folders SET status = 2, deleted_at = ?, trash_of = NULL WHERE id = ?", [$now, $root]);
        });
        return true;
    }
    /** ย้ายเอกสาร/ไฟล์/โฟลเดอร์ เข้าโฟลเดอร์ (null/0 = ชั้นบนสุด) */
    public static function moveTo($kind, $itemId, $folderId, $ownerId){
        $fid = (int)$folderId > 0 ? self::ownFolder($folderId, $ownerId) : null;
        if((int)$folderId > 0 && $fid === null) return false;
        if($kind === 'file'){
            if(!self::fileGet($itemId, $ownerId)) return false;
            db_write("UPDATE docs_files SET folder_id = ? WHERE id = ?", [$fid, (int)$itemId]);
            return true;
        }
        if($kind === 'folder'){
            $f = self::folderGet($itemId);
            if(!$f || (int)$f['owner_id'] !== (int)$ownerId) return false;
            if($fid !== null && in_array($fid, self::folderTree((int)$itemId, $ownerId, 1), true)) return false;   // ห้ามย้ายเข้าลูกหลานตัวเอง
            db_write("UPDATE docs_folders SET parent_id = ? WHERE id = ?", [$fid, (int)$itemId]);
            return true;
        }
        $d = self::get($itemId);
        if(!$d || (int)$d['owner_id'] !== (int)$ownerId) return false;
        db_write("UPDATE docs_documents SET folder_id = ? WHERE id = ?", [$fid, (int)$itemId]);
        return true;
    }
    /** เปลี่ยนชื่อเอกสาร */
    public static function rename($id, $title, $userId){
        $d = self::get($id);
        $title = trim(mb_substr(strip_tags((string)$title), 0, 200));
        if(!$d || $title === '' || !self::canEdit($d, $userId)) return false;
        db_write("UPDATE docs_documents SET title = ?, updated_at = ? WHERE id = ?", [$title, now(), (int)$id]);
        return true;
    }

    /** เอกสารในโฟลเดอร์ (ค้นหา = ทุกโฟลเดอร์) */
    public static function listIn($ownerId, $folderId = null, array $opt = []){
        $w = ['status = 1', 'owner_id = ?']; $p = [(int)$ownerId];
        if(($opt['q'] ?? '') !== ''){ $w[] = 'title LIKE ?'; $p[] = '%'.addcslashes($opt['q'], '%_\\').'%'; }
        elseif((int)$folderId > 0){ $w[] = 'folder_id = ?'; $p[] = (int)$folderId; }
        else $w[] = 'folder_id IS NULL';
        if(!empty($opt['type']) && self::typeValid($opt['type'])){ $w[] = 'type = ?'; $p[] = $opt['type']; }
        return db_all("SELECT id, owner_id, course_id, folder_id, type, title, CHAR_LENGTH(content) AS size, created_at, updated_at
                       FROM docs_documents WHERE ".implode(' AND ', $w)." ORDER BY updated_at DESC, id DESC", $p);
    }
    /** ไฟล์อัปโหลดในโฟลเดอร์ */
    public static function filesIn($ownerId, $folderId = null, array $opt = []){
        $w = ['status = 1', 'owner_id = ?']; $p = [(int)$ownerId];
        if(($opt['q'] ?? '') !== ''){ $w[] = 'name LIKE ?'; $p[] = '%'.addcslashes($opt['q'], '%_\\').'%'; }
        elseif((int)$folderId > 0){ $w[] = 'folder_id = ?'; $p[] = (int)$folderId; }
        else $w[] = 'folder_id IS NULL';
        return db_all("SELECT * FROM docs_files WHERE ".implode(' AND ', $w)." ORDER BY created_at DESC, id DESC", $p);
    }

    // ── ถังขยะ ──────────────────────────────────────────────
    /** รายการในถังขยะ (เฉพาะรายการหลัก) เรียงตามเวลาที่ลบ ใหม่สุดก่อน */
    public static function trashList($userId){
        $o = (int)$userId; $out = [];
        foreach(db_all("SELECT * FROM docs_folders WHERE owner_id = ? AND status = 2 AND trash_of IS NULL", [$o]) as $f)
            $out[] = ['kind' => 'folder', 'id' => (int)$f['id'], 'name' => $f['name'], 'deleted' => $f['deleted_at'],
                      'size' => self::trashFolderSize($f['id'], $o), 'meta' => '', 'type' => ''];
        foreach(db_all("SELECT id, type, title, deleted_at, CHAR_LENGTH(content) + asset_size AS sz FROM docs_documents WHERE owner_id = ? AND status = 2 AND trash_of IS NULL", [$o]) as $d)
            $out[] = ['kind' => 'doc', 'id' => (int)$d['id'], 'name' => $d['title'], 'deleted' => $d['deleted_at'],
                      'size' => (int)$d['sz'], 'meta' => self::typeInfo($d['type'])['name'], 'type' => $d['type']];
        foreach(db_all("SELECT * FROM docs_files WHERE owner_id = ? AND status = 2 AND trash_of IS NULL", [$o]) as $f)
            $out[] = ['kind' => 'file', 'id' => (int)$f['id'], 'name' => $f['name'], 'deleted' => $f['deleted_at'],
                      'size' => (int)$f['size'], 'meta' => strtoupper($f['ext']), 'type' => ''];
        usort($out, function($a, $b){ return strcmp((string)$b['deleted'], (string)$a['deleted']); });
        return $out;
    }
    /** ขนาดรวมของทุกอย่างในโฟลเดอร์ที่ถูกลบทั้งก้อน */
    public static function trashFolderSize($folderId, $userId){
        return (int)db_val("SELECT COALESCE(SUM(CHAR_LENGTH(content) + asset_size), 0) FROM docs_documents WHERE owner_id = ? AND status = 2 AND trash_of = ?", [(int)$userId, (int)$folderId])
             + (int)db_val("SELECT COALESCE(SUM(size), 0) FROM docs_files WHERE owner_id = ? AND status = 2 AND trash_of = ?", [(int)$userId, (int)$folderId]);
    }
    /** จำนวนชิ้นในถังขยะ (นับเฉพาะรายการหลัก) */
    public static function trashCount($userId){
        $n = 0;
        foreach(['docs_folders', 'docs_documents', 'docs_files'] as $t)
            $n += (int)db_val("SELECT COUNT(*) FROM ".$t." WHERE owner_id = ? AND status = 2 AND trash_of IS NULL", [(int)$userId]);
        return $n;
    }
    /** โฟลเดอร์ปลายทางยังใช้งานอยู่ไหม — ถ้าไม่ กู้คืนไปไว้ชั้นบนสุดแทน (ของจะได้ไม่หาย) */
    private static function trashHomeFolder($folderId, $userId){
        return $folderId ? self::ownFolder($folderId, $userId) : null;
    }

    /** กู้คืนจากถังขยะ — โฟลเดอร์กู้คืนพร้อมของข้างในทั้งหมด */
    public static function trashRestore($kind, $id, $userId){
        $id = (int)$id; $o = (int)$userId;
        if($kind === 'folder'){
            $f = db_one("SELECT * FROM docs_folders WHERE id = ? AND owner_id = ? AND status = 2", [$id, $o]);
            if(!$f) return false;
            $up = self::trashHomeFolder($f['parent_id'], $o);            // โฟลเดอร์แม่ถูกลบไปแล้ว → ไว้ชั้นบนสุด
            db_tx(function() use($id, $o, $up){
                db_write("UPDATE docs_folders SET status = 1, deleted_at = NULL, trash_of = NULL, parent_id = ? WHERE id = ?", [$up, $id]);
                db_write("UPDATE docs_folders SET status = 1, deleted_at = NULL, trash_of = NULL WHERE owner_id = ? AND status = 2 AND trash_of = ?", [$o, $id]);
                db_write("UPDATE docs_documents SET status = 1, deleted_at = NULL, trash_of = NULL WHERE owner_id = ? AND status = 2 AND trash_of = ?", [$o, $id]);
                db_write("UPDATE docs_files SET status = 1, deleted_at = NULL, trash_of = NULL WHERE owner_id = ? AND status = 2 AND trash_of = ?", [$o, $id]);
            });
            return true;
        }
        if($kind === 'file'){
            $f = db_one("SELECT * FROM docs_files WHERE id = ? AND owner_id = ? AND status = 2", [$id, $o]);
            if(!$f) return false;
            db_write("UPDATE docs_files SET status = 1, deleted_at = NULL, trash_of = NULL, folder_id = ? WHERE id = ?", [self::trashHomeFolder($f['folder_id'], $o), $id]);
            return true;
        }
        $d = db_one("SELECT * FROM docs_documents WHERE id = ? AND owner_id = ? AND status = 2", [$id, $o]);
        if(!$d) return false;
        db_write("UPDATE docs_documents SET status = 1, deleted_at = NULL, trash_of = NULL, folder_id = ? WHERE id = ?", [self::trashHomeFolder($d['folder_id'], $o), $id]);
        return true;
    }

    /** ลบถาวร — ลบแถวและไฟล์บนดิสก์จริง พื้นที่คืนให้ผู้ใช้ทันที */
    public static function trashPurge($kind, $id, $userId){
        $id = (int)$id; $o = (int)$userId; $unlink = [];
        if($kind === 'folder'){
            if(!db_val("SELECT id FROM docs_folders WHERE id = ? AND owner_id = ? AND status = 2", [$id, $o])) return false;
            foreach(db_all("SELECT stored_name FROM docs_files WHERE owner_id = ? AND status = 2 AND trash_of = ?", [$o, $id]) as $f) $unlink[] = $f['stored_name'];
            foreach(db_all("SELECT asset FROM docs_documents WHERE owner_id = ? AND status = 2 AND trash_of = ? AND asset IS NOT NULL AND asset <> ''", [$o, $id]) as $a) $unlink[] = $a['asset'];
            db_tx(function() use($id, $o){
                db_write("DELETE v FROM docs_versions v JOIN docs_documents d ON d.id = v.doc_id WHERE d.owner_id = ? AND d.status = 2 AND d.trash_of = ?", [$o, $id]);
                db_write("DELETE FROM docs_documents WHERE owner_id = ? AND status = 2 AND trash_of = ?", [$o, $id]);
                db_write("DELETE FROM docs_files WHERE owner_id = ? AND status = 2 AND trash_of = ?", [$o, $id]);
                db_write("DELETE FROM docs_folders WHERE owner_id = ? AND status = 2 AND trash_of = ?", [$o, $id]);
                db_write("DELETE FROM docs_folders WHERE id = ?", [$id]);
            });
        }elseif($kind === 'file'){
            $f = db_one("SELECT * FROM docs_files WHERE id = ? AND owner_id = ? AND status = 2", [$id, $o]);
            if(!$f) return false;
            $unlink[] = $f['stored_name'];
            db_write("DELETE FROM docs_files WHERE id = ?", [$id]);
        }else{
            $d = db_one("SELECT id, asset FROM docs_documents WHERE id = ? AND owner_id = ? AND status = 2", [$id, $o]);
            if(!$d) return false;
            if(!empty($d['asset'])) $unlink[] = $d['asset'];
            db_tx(function() use($id){
                db_write("DELETE FROM docs_versions WHERE doc_id = ?", [$id]);
                db_write("DELETE FROM docs_documents WHERE id = ?", [$id]);
                db_write("UPDATE docs_files SET doc_id = NULL WHERE doc_id = ?", [$id]);
            });
        }
        foreach($unlink as $s) self::fileUnlink($s, $o);           // ลบไฟล์จริงหลังลบแถวสำเร็จแล้ว
        return true;
    }

    /** ล้างถังขยะทั้งหมด — คืนจำนวนชิ้นที่ลบ */
    public static function trashEmpty($userId){
        $n = 0;
        foreach(self::trashList($userId) as $it) if(self::trashPurge($it['kind'], $it['id'], $userId)) $n++;
        return $n;
    }

    /** จำนวนวันที่เก็บของในถังขยะก่อนลบเอง (0 = ไม่ลบเอง) */
    public static function trashDays(){ return (int)setting('docs_trash_days', '30'); }

    /** ลบของที่ค้างในถังขยะเกินกำหนดโดยอัตโนมัติ — คืนจำนวนที่ลบ */
    public static function trashAutoPurge($userId){
        $days = self::trashDays();
        if($days <= 0) return 0;
        $cut = date('Y-m-d H:i:s', time() - $days * 86400);
        // เช็คถูก ๆ ก่อนว่ามีของเก่าพอจะลบไหม จะได้ไม่ต้องไล่ทั้งถังทุกครั้งที่เปิดหน้า
        $has = false;
        foreach(['docs_documents', 'docs_files', 'docs_folders'] as $t)
            if(db_val("SELECT 1 FROM ".$t." WHERE owner_id = ? AND status = 2 AND deleted_at IS NOT NULL AND deleted_at <= ? LIMIT 1", [(int)$userId, $cut])){ $has = true; break; }
        if(!$has) return 0;
        $n = 0;
        foreach(self::trashList($userId) as $it){
            if(!$it['deleted'] || $it['deleted'] > $cut) continue;
            if(self::trashPurge($it['kind'], $it['id'], $userId)) $n++;
        }
        return $n;
    }

    /** ส่วนหัวที่ทุกหน้าของ Docs ใช้: CSS/JS ร่วม + ค่าตั้งต้นของ JS (URL api, CSRF, ข้อความปุ่ม) */
    public static function pageHead(){
        $v = function($f){ return '?v='.(int)@filemtime(dirname(__DIR__).'/'.$f); };
        $cfg = ['api' => asset('api/docs.php'), 'csrf' => csrf_token(),
                'L' => ['cancel' => docs_t('common.cancel'), 'del' => docs_t('common.delete'), 'confirm' => docs_t('common.confirm')]];
        return '<link rel="stylesheet" href="'.h(asset('assets/docs/docs.css').$v('assets/docs/docs.css')).'">'
             .'<script>window.DOCS='.json_encode($cfg, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG).';</script>'
             .'<script src="'.h(asset('assets/docs/docs-ui.js').$v('assets/docs/docs-ui.js')).'"></script>';
    }

    // ── แสดงผลแบบอ่านอย่างเดียว (หน้าดูเอกสาร / ฝังในบทเรียน) ──
    /** CSS ของตัวอ่าน — เติมค่าสำรองให้ตัวแปรสี เผื่อหน้าที่ฝังไม่มีตัวแปรชุดเดียวกับหลังบ้าน */
    public static function renderCss(){
        $css = '
  .docs-render { color:var(--text); }
  .docs-render .vd-paper { background:var(--card); border:1px solid var(--border); border-radius:var(--radius);
              padding:2rem 2.4rem; line-height:1.85; font-size:1rem; color:var(--text); overflow-wrap:anywhere; }
  .docs-render .vd-paper h1{font-size:1.6rem;font-weight:700;margin:1.2rem 0 .6rem}
  .docs-render .vd-paper h2{font-size:1.3rem;font-weight:700;margin:1.1rem 0 .5rem}
  .docs-render .vd-paper h3{font-size:1.1rem;font-weight:700;margin:1rem 0 .45rem}
  .docs-render .vd-paper p{margin:.55rem 0}
  .docs-render .vd-paper ul,.docs-render .vd-paper ol{margin:.55rem 0 .55rem 1.5rem}
  .docs-render .vd-paper img{max-width:100%;height:auto;border-radius:8px}
  .docs-render .vd-paper table{border-collapse:collapse;width:100%;margin:.8rem 0;display:block;overflow-x:auto}
  .docs-render .vd-paper th,.docs-render .vd-paper td{border:1px solid var(--border);padding:7px 10px}
  .docs-render .vd-paper th{background:color-mix(in srgb,var(--primary) 8%,transparent);font-weight:700}
  .docs-render .vd-paper blockquote{border-left:3px solid var(--primary);margin:.7rem 0;padding:.2rem 0 .2rem 1rem;color:var(--text-secondary)}
  .docs-render .vd-paper pre{background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:.8rem 1rem;overflow-x:auto}
  @media(max-width:640px){ .docs-render .vd-paper{padding:1.2rem 1rem} }
  .docs-render .vd-grid { overflow-x:auto; padding:1rem; }
  .docs-render .ag-table { border-collapse:collapse; width:100%; font-size:.9rem; }
  .docs-render .ag-table td { border:1px solid var(--border); padding:6px 10px; white-space:pre-wrap; }
  .docs-render .ag-tabs { display:flex; gap:4px; flex-wrap:wrap; margin-bottom:.6rem; }
  .docs-render .ag-tab { padding:4px 12px; border-radius:8px; border:1px solid var(--border); background:var(--bg); color:var(--text-secondary); cursor:pointer; font:inherit; font-size:.82rem; }
  .docs-render .ag-tab.on { background:var(--card); color:var(--primary); border-color:var(--primary); }
'.presentCss();
        $fallback = ['--border' => '#e2e8f0', '--card' => '#ffffff', '--radius' => '14px', '--text-secondary' => '#64748b',
                     '--text-muted' => '#94a3b8', '--primary' => '#6366f1', '--bg' => '#f1f5f9', '--text' => '#1e293b'];
        foreach($fallback as $k => $v) $css = str_replace('var('.$k.')', 'var('.$k.','.$v.')', $css);
        return $css;
    }

    /** HTML ของเนื้อหา (ไม่รวม CSS/JS) ตามชนิดเอกสาร */
    public static function renderBody(array $doc, $idPrefix = 'pv'){
        if($doc['type'] === 'write') return '<div class="vd-paper">'.self::renderWriteHtml($doc['content']).'</div>';
        if($doc['type'] === 'grid'){
            self::raiseMemory(strlen((string)$doc['content']));
            return '<div class="vd-paper vd-grid">'.gridRenderHtml($doc['content']).'</div>';
        }
        if($doc['type'] === 'present') return presentRenderHtml($doc['content'], $idPrefix);
        return '';
    }

    /**
     * HTML ที่ปลอดภัยสำหรับฝังในหน้าเรียน — write: HTML ที่กรองแล้ว · grid: ตาราง · present: ตัวเล่นสไลด์ + JS ในตัว
     * คืน '' ถ้าไม่พบเอกสาร / อยู่ในถังขยะ / ปิดโมดูล docs
     */
    public static function renderForLesson($docId){
        if(class_exists('PermissionService') && !PermissionService::moduleOn('docs')) return '';
        $doc = self::get((int)$docId);
        if(!$doc) return '';
        $uid = 'dr'.(int)$doc['id'].substr(bin2hex(random_bytes(3)), 0, 6);
        $h  = '<style>'.self::renderCss().'</style>';
        $h .= '<div class="docs-render docs-'.h($doc['type']).'" id="'.$uid.'">'.self::renderBody($doc, $uid.'d').'</div>';
        if($doc['type'] === 'present') $h .= '<script>'.presentJs().'</script>';
        return $h;
    }
}
