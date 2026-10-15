<?php
// ============================================================
// Aleanor LC — คลังบทเรียนกลาง (aleanor_lc): ผู้สอนค้นหาเทมเพลต → นำเข้าเป็นคอร์สใหม่ของตัวเอง (ฉบับร่าง)
// พอร์ตจาก aleanor_ai core/lc.core.php — ฝั่ง Cloud เป็น "สถาบัน" หนึ่งของ LC (ลงชื่อ HMAC-SHA256 ต่อสถาบัน)
//   ทิศทางเดียว: Cloud ดึง (pull) เสมอ · ไฟล์ต้องมาจากใต้ lc_url เท่านั้น · นำเข้า = สร้างคอร์สใหม่ทุกครั้ง ไม่เขียนทับของเดิม
// การแปลงบทเรียน (Cloud มีบทเรียน video / text / doc / indy / playground):
//   text, activity, link, pdf, playground(คู่มือ) → เอกสาร Aleanor Docs ชนิด Write · doc → Docs ชนิดเดิม · video → วิดีโอ
//   quiz → ใบงาน (Write) ไม่มีเฉลย · เฉลย + บันทึกผู้สอน/ตัวชี้วัด → เอกสาร "สำหรับผู้สอน" ที่ไม่ผูกเป็นบทเรียน (ผู้เรียนไม่เห็น)
// ตั้งค่า: lc_url, lc_institution, lc_secret (แอดมิน → Playground, VC & Streaming) · โมดูล 'lc' · สิทธิ์ผู้สอน 'lc'
// ============================================================
class LcService
{
    public static function baseUrl(){     return rtrim(trim((string)setting('lc_url', '')), '/'); }
    public static function institution(){ return (string)setting('lc_institution', ''); }
    public static function secret(){      return (string)setting('lc_secret', ''); }
    public static function configured(){  return self::baseUrl() !== '' && self::institution() !== '' && self::secret() !== ''; }
    public static function enabled(){     return PermissionService::moduleOn('lc') && self::configured(); }
    public static function logoUrl(){     return asset('assets/brand/lc-logo.png'); }

    /** เรียก API ของ LC (ลงชื่อ HMAC แบบเดียวกับ aleanor_ai) */
    public static function api($action, array $query = [], $body = null){
        if(!self::configured()) return ['ok' => false, 'status' => 0, 'json' => null, 'error' => 'ยังไม่ได้ตั้งค่าการเชื่อมต่อ Aleanor LC'];
        $qs = http_build_query(array_merge(['action' => $action], $query));
        $raw = $body === null ? '' : json_encode($body, JSON_UNESCAPED_UNICODE);
        $ts = (string)time(); $nonce = bin2hex(random_bytes(12));
        $method = $body === null ? 'GET' : 'POST';
        $sig = hash_hmac('sha256', $ts."\n".$nonce."\n".$method."\n".$qs."\n".hash('sha256', $raw), self::secret());
        $ch = curl_init(self::baseUrl().'/api/v1.php?'.$qs);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => [
            'Accept: application/json', 'Content-Type: application/json',
            'X-LC-Institution: '.self::institution(), 'X-LC-Timestamp: '.$ts, 'X-LC-Nonce: '.$nonce, 'X-LC-Signature: '.$sig]]);
        if($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $raw);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
        curl_close($ch);
        $j = json_decode((string)$resp, true);
        $ok = $err === '' && $code >= 200 && $code < 300 && is_array($j) && !empty($j['ok']);
        if(!$ok && $err === '') $err = is_array($j) && !empty($j['error']) ? $j['error'] : 'HTTP '.$code;
        return ['ok' => $ok, 'status' => $code, 'json' => $j, 'error' => $ok ? '' : $err];
    }

    /** ดาวน์โหลดไฟล์จาก LC (เฉพาะ URL ใต้ lc_url) → คืนชื่อไฟล์ในโฟลเดอร์ $dir หรือโยน RuntimeException */
    public static function download($url, $dir, array $exts, $maxBytes, $sha256 = ''){
        if(strpos((string)$url, self::baseUrl().'/') !== 0) throw new RuntimeException('ลิงก์ไฟล์ไม่ได้มาจากคลัง Aleanor LC');
        $tmp = tempnam(sys_get_temp_dir(), 'lc');
        $fh = fopen($tmp, 'wb');
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_TIMEOUT => 120, CURLOPT_FOLLOWLOCATION => false, CURLOPT_NOPROGRESS => false,
            CURLOPT_PROGRESSFUNCTION => function($c, $dlTotal, $dl) use($maxBytes){ return $dl > $maxBytes ? 1 : 0; }]);
        curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $mime = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE); $err = curl_error($ch);
        curl_close($ch); fclose($fh);
        if($err !== '' || $code !== 200){ @unlink($tmp); throw new RuntimeException('ดาวน์โหลดไฟล์ไม่สำเร็จ ('.($err ?: 'HTTP '.$code).')'); }
        if($sha256 !== '' && !hash_equals(strtolower($sha256), hash_file('sha256', $tmp))){ @unlink($tmp); throw new RuntimeException('ไฟล์เสียระหว่างดาวน์โหลด (checksum ไม่ตรง)'); }
        $map = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        $ext = $map[strtolower(trim(explode(';', $mime)[0]))] ?? '';
        if($ext === '' || !in_array($ext, $exts, true)){ @unlink($tmp); throw new RuntimeException('ชนิดไฟล์ไม่รองรับ: '.$mime); }
        if($ext !== 'pdf' && @getimagesize($tmp) === false){ @unlink($tmp); throw new RuntimeException('ไฟล์รูปไม่ถูกต้อง'); }
        $dir = rtrim($dir, '/').'/';
        if(!is_dir($dir)) @mkdir($dir, 0755, true);
        $fn = bin2hex(random_bytes(12)).'.'.$ext;
        if(!@rename($tmp, $dir.$fn)){ @unlink($tmp); throw new RuntimeException('บันทึกไฟล์ไม่สำเร็จ — ตรวจสิทธิ์เขียนโฟลเดอร์ '.$dir); }
        @chmod($dir.$fn, 0644);
        return $fn;
    }

    /** รูปใน HTML จาก LC → เก็บในโฟลเดอร์ Docs ของผู้สอน แล้วเปลี่ยน src ($files สะสมไว้ลบเมื่อล้มเหลว) */
    private static function localizeHtml($html, $docDir, $docRel, array &$files){
        if(!preg_match_all('~<img\b[^>]*\bsrc="([^"]+)"~i', (string)$html, $m)) return (string)$html;
        foreach(array_unique($m[1]) as $src){
            $url = html_entity_decode($src, ENT_QUOTES);
            if(strpos($url, self::baseUrl().'/') !== 0) continue;
            $fn = self::download($url, $docDir, ['jpg', 'png', 'webp', 'gif'], 10 * 1048576);
            $files[] = $docDir.$fn;
            $html = str_replace('src="'.$src.'"', 'src="'.$docRel.$fn.'"', $html);
        }
        return $html;
    }

    private static function box($title, $innerHtml){
        return '<div style="background-color:#eef2ff;border-radius:12px;padding-left:16px"><p><strong>'.$title.'</strong></p>'.$innerHtml.'</div>';
    }
    private static function list(array $items){ return '<ul><li>'.implode('</li><li>', array_map('h', $items)).'</li></ul>'; }

    /** แบบทดสอบ → [ใบงาน (ไม่มีเฉลย), เฉลย] เป็น HTML */
    public static function quizHtml(array $l){
        $q = $l['quiz'] ?? [];
        $sheet = ''; $key = '';
        $n = 0;
        foreach(($q['questions'] ?? []) as $qq){
            $n++;
            $pts = max(1, (int)($qq['points'] ?? 1));
            $sheet .= '<p><strong>'.$n.'.</strong> '.h((string)($qq['text'] ?? '')).' <em>('.$pts.' คะแนน)</em></p>';
            $type = (string)($qq['type'] ?? 'multiple_choice');
            $choices = $qq['choices'] ?? [];
            $correct = [];
            if($type === 'matching'){
                $sheet .= '<ul>'; foreach($choices as $c){ $sheet .= '<li>'.h((string)$c['text']).' — ………</li>'; $correct[] = $c['text'].' → '.($c['match'] ?? ''); } $sheet .= '</ul>';
            }elseif($type === 'ordering'){
                $sheet .= '<ul>'; $sh = $choices; shuffle($sh); foreach($sh as $c) $sheet .= '<li>'.h((string)$c['text']).'</li>'; $sheet .= '</ul>';
                $correct[] = implode(' → ', array_map(function($c){ return (string)$c['text']; }, $choices));
            }elseif($choices && $type !== 'essay' && $type !== 'fill_blank'){
                $sheet .= '<ul>';
                foreach($choices as $ci => $c){ $lab = mb_substr('กขคงจฉชซฌญ', $ci, 1); $sheet .= '<li>'.$lab.'. '.h((string)$c['text']).'</li>'; if(!empty($c['correct'])) $correct[] = $lab.'. '.$c['text']; }
                $sheet .= '</ul>';
            }else{
                $sheet .= '<p>…………………………………………………………</p>';
                foreach($choices as $c) if(!empty($c['correct']) || $type === 'fill_blank') $correct[] = (string)$c['text'];
            }
            $key .= '<li>ข้อ '.$n.': '.($correct ? h(implode(' / ', $correct)) : '<em>ตรวจตามดุลยพินิจผู้สอน</em>').'</li>';
        }
        $meta = [];
        if((int)($q['time_limit'] ?? 0) > 0) $meta[] = 'เวลา '.(int)$q['time_limit'].' นาที';
        if(isset($q['pass_score'])) $meta[] = 'เกณฑ์ผ่าน '.(int)$q['pass_score'].'%';
        $head = trim((string)($l['summary'] ?? ''));
        $sheetHtml = ($head !== '' ? '<p>'.h($head).'</p>' : '').($meta ? '<p><em>'.h(implode(' · ', $meta)).'</em></p>' : '').$sheet;
        return [$sheetHtml, $key !== '' ? '<ol style="list-style:none;padding-left:0">'.$key.'</ol>' : ''];
    }

    /**
     * นำเข้าแพ็กเกจ aleanor-lc v1 เป็นคอร์สใหม่ (ฉบับร่าง) ของ $userId
     * $opt = ['quiz' => bool, 'notes' => bool]
     * คืน ['course_id','course_name','warnings','counts'] หรือโยน RuntimeException (ไม่มีอะไรค้างในระบบ)
     */
    public static function import(array $pkg, array $opt, $userId){
        $userId = (int)$userId;
        if(($pkg['format'] ?? '') !== 'aleanor-lc' || (int)($pkg['format_version'] ?? 0) !== 1) throw new RuntimeException('รูปแบบแพ็กเกจไม่รองรับ (ต้องเป็น aleanor-lc v1) — อาจต้องอัปเดตระบบ');
        $t = $pkg['template'] ?? [];
        if(empty($t['uid']) || empty($t['title']) || !isset($pkg['units']) || !is_array($pkg['units'])) throw new RuntimeException('แพ็กเกจไม่สมบูรณ์');
        $root = dirname(__DIR__).'/';
        $files = []; $warn = [];
        $now = now();
        $docDir = DocsService::userDir($userId); $docRel = DocsService::userRel($userId);
        try {
            // ── 1) โหลดไฟล์ทั้งหมดก่อนแตะฐานข้อมูล ──
            $cover = '';
            if(!empty($t['cover']['url'])){
                try { $fn = self::download($t['cover']['url'], $root.'uploads/covers', ['jpg', 'png', 'webp', 'gif'], 5 * 1048576, $t['cover']['sha256'] ?? ''); $files[] = $root.'uploads/covers/'.$fn; $cover = 'uploads/covers/'.$fn; }
                catch(RuntimeException $e){ $warn[] = 'ภาพปก: '.$e->getMessage(); }
            }
            foreach($pkg['units'] as $ui => $u){
                foreach(($u['lessons'] ?? []) as $li => $l){
                    $L = &$pkg['units'][$ui]['lessons'][$li];
                    if(($l['type'] ?? '') === 'pdf' && !empty($l['file']['url'])){
                        $fn = self::download($l['file']['url'], $docDir, ['pdf'], 50 * 1048576, $l['file']['sha256'] ?? '');
                        $files[] = $docDir.$fn; $L['_file'] = $docRel.$fn;
                    }
                    if(!empty($l['content_html'])) $L['content_html'] = self::localizeHtml($l['content_html'], $docDir, $docRel, $files);
                    if(($l['type'] ?? '') === 'doc' && !empty($l['doc']['content'])){
                        $dt = (string)($l['doc']['type'] ?? '');
                        if($dt === 'write') $L['doc']['content'] = self::localizeHtml($l['doc']['content'], $docDir, $docRel, $files);
                        elseif($dt === 'present'){
                            $deck = json_decode($l['doc']['content'], true);
                            if(is_array($deck) && !empty($deck['slides'])){
                                foreach($deck['slides'] as $si => $sl){
                                    foreach(['img', 'bgImg'] as $ik){
                                        if(empty($sl[$ik])) continue;
                                        if(strpos((string)$sl[$ik], self::baseUrl().'/') !== 0){ $deck['slides'][$si][$ik] = ''; continue; }
                                        $fn = self::download($sl[$ik], $docDir, ['jpg', 'png', 'webp', 'gif'], 10 * 1048576);
                                        $files[] = $docDir.$fn; $deck['slides'][$si][$ik] = $docRel.$fn;
                                    }
                                }
                                $L['doc']['content'] = json_encode($deck, JSON_UNESCAPED_UNICODE);
                            }
                        }
                    }
                    unset($L);
                }
            }
            $bytes = 0; foreach($files as $f) $bytes += (int)@filesize($f);
            if($bytes && !DocsService::quotaAllows($userId, 0)) throw new RuntimeException('พื้นที่ Aleanor Docs ของคุณเต็ม — ลบไฟล์ที่ไม่ใช้ก่อนนำเข้า');

            // ── 2) เขียนฐานข้อมูลใน transaction เดียว ──
            $res = db_tx(function() use($pkg, $t, $opt, $userId, $cover, $now, &$warn){
                $desc = trim((string)($t['description'] ?? ''));
                foreach([['จุดประสงค์การเรียนรู้', $t['objectives'] ?? []], ['เหมาะสำหรับ', $t['audience'] ?? []]] as $d){
                    $items = array_values(array_filter(array_map('trim', (array)$d[1]), 'strlen'));
                    if($items) $desc .= "\n\n".$d[0]."\n• ".implode("\n• ", $items);
                }
                $desc .= "\n\nต้นแบบจาก Aleanor LC: ".(!empty($t['code']) ? $t['code'].' · ' : '').$t['title'].' (เวอร์ชัน '.self::ver($t['version'] ?? 0).')'.(!empty($t['license']) ? ' · '.$t['license'] : '');
                $sub = trim(implode(' · ', array_filter([$t['grade']['name_th'] ?? '', $t['area']['name_th'] ?? '', (int)($t['term'] ?? 0) ? self::termLabel($t['term']) : ''])));
                $cid = db_insert("INSERT INTO courses (instructor_id, slug, title, subtitle, description, cover, level, price, status, created_at, updated_at) VALUES (?,?,?,?,?,?, 'all', 0, 'draft', ?, ?)",
                    [$userId, make_slug($t['title']), mb_substr($t['title'], 0, 255), mb_substr($sub, 0, 255), trim($desc), $cover, $now, $now]);
                $counts = ['units' => 0, 'lessons' => 0, 'quiz' => 0, 'docs' => 0];
                $teacher = '';
                $mkDoc = function($type, $title, $content) use($cid, $userId, &$counts){
                    $counts['docs']++;
                    return DocsService::save(0, ['type' => $type, 'title' => $title, 'content' => $content, 'course_id' => $cid], $userId);
                };
                $us = 0;
                foreach($pkg['units'] as $u){
                    $sid = db_insert("INSERT INTO course_sections (course_id, title, sort_order) VALUES (?,?,?)", [$cid, mb_substr((string)($u['title'] ?? 'หน่วยการเรียนรู้'), 0, 255), ++$us]);
                    $counts['units']++;
                    $ls = 0;
                    foreach(($u['lessons'] ?? []) as $l){
                        $type = (string)($l['type'] ?? ''); $title = (string)($l['title'] ?? 'บทเรียน');
                        $lt = 'doc'; $ref = 0; $video = ''; $content = trim((string)($l['summary'] ?? ''));
                        $obj = !empty($l['objectives']) ? self::box('จุดประสงค์', self::list((array)$l['objectives'])) : '';
                        if($type === 'text' || $type === 'activity'){
                            $ref = $mkDoc('write', $title, $obj.(string)($l['content_html'] ?? ''));
                        }elseif($type === 'doc'){
                            $dt = (string)($l['doc']['type'] ?? '');
                            if(!in_array($dt, ['write', 'present', 'grid'], true)){ $warn[] = 'ข้าม "'.$title.'" — ไม่มีเอกสาร'; continue; }
                            $ref = $mkDoc($dt, ($l['doc']['title'] ?? '') ?: $title, (string)$l['doc']['content']);
                        }elseif($type === 'video'){
                            $lt = 'video'; $video = (string)($l['media_url'] ?? '');
                            if(!preg_match('~^https?://~i', $video)){ $warn[] = 'ข้าม "'.$title.'" — ไม่มีลิงก์วิดีโอ'; continue; }
                        }elseif($type === 'link'){
                            $url = (string)($l['media_url'] ?? '');
                            if(!preg_match('~^https?://~i', $url)){ $warn[] = 'ข้าม "'.$title.'" — ไม่มีลิงก์'; continue; }
                            $ref = $mkDoc('write', $title, $obj.'<p><a href="'.h($url).'" target="_blank" rel="noopener">เปิดแหล่งเรียนรู้: '.h($title).' ↗</a></p>'.(string)($l['content_html'] ?? ''));
                        }elseif($type === 'pdf'){
                            if(empty($l['_file'])){ $warn[] = 'ข้าม "'.$title.'" — ไม่มีไฟล์ PDF'; continue; }
                            $ref = $mkDoc('write', $title, $obj.'<p><a href="'.h(asset($l['_file'])).'" target="_blank" rel="noopener">📄 เปิดเอกสาร PDF: '.h($title).'</a></p>'.(string)($l['content_html'] ?? ''));
                        }elseif($type === 'playground'){
                            $slug = preg_replace('/[^a-z0-9\-]/', '', (string)($l['playground']['app'] ?? ''));
                            $pgName = trim(($l['playground']['icon'] ?? '').' '.($l['playground']['name'] ?? $slug));
                            $title = 'คู่มือ: '.$title;
                            $ref = $mkDoc('write', $title, self::box(h($pgName), '<p>แล็บจำลองใน Aleanor Playground — ผู้สอนเพิ่มบทเรียนชนิด Playground (รหัสกิจกรรม) ต่อจากคู่มือนี้ได้</p>').(string)($l['content_html'] ?? ''));
                        }elseif($type === 'quiz'){
                            if(empty($opt['quiz'])) continue;
                            [$sheet, $key] = self::quizHtml($l);
                            $ref = $mkDoc('write', 'ใบงาน: '.$title, $sheet);
                            $title = 'ใบงาน: '.$title; $counts['quiz']++; $content = '';     // คำชี้แจงอยู่ในใบงานแล้ว
                            if($key !== '') $teacher .= '<h3>เฉลย — '.h($l['title'] ?? '').'</h3>'.$key;
                        }else{
                            $warn[] = 'ข้าม "'.$title.'" — ชนิดบทเรียน '.$type.' ไม่รองรับ'; continue;
                        }
                        $notes = trim((string)($l['teacher_notes'] ?? ''));
                        if($notes !== '' || !empty($l['indicators']))
                            $teacher .= '<h3>'.h($title).'</h3>'.($notes !== '' ? '<p style="white-space:pre-line">'.h($notes).'</p>' : '').(!empty($l['indicators']) ? '<p><em>ตัวชี้วัด:</em> '.h(implode(' · ', (array)$l['indicators'])).'</p>' : '');
                        db_insert("INSERT INTO lessons (course_id, section_id, title, type, video_url, content, ref_id, ref_key, duration_min, is_preview, sort_order) VALUES (?,?,?,?,?,?,?, '', ?, 0, ?)",
                            [$cid, $sid, mb_substr($title, 0, 255), $lt, $video, $content, $ref ?: null, max(0, (int)($l['minutes'] ?? 0)), ++$ls]);
                        $counts['lessons']++;
                    }
                }
                // บันทึกผู้สอน + โครงคะแนนแนะนำ + เฉลย — เอกสารในคอร์สที่ไม่ผูกเป็นบทเรียน
                $as = '';
                foreach(($pkg['assessments'] ?? []) as $a) $as .= '<li>'.h((string)($a['name'] ?? '')).' — '.(float)($a['max_score'] ?? 0).' คะแนน</li>';
                if($as !== '') $teacher = '<h3>โครงคะแนนแนะนำ</h3><ul>'.$as.'</ul>'.$teacher;
                if(!empty($opt['notes']) && $teacher !== '')
                    $mkDoc('write', 'สำหรับผู้สอน: '.$t['title'], '<p><em>เอกสารนี้ไม่ได้ผูกเป็นบทเรียน — ผู้เรียนมองไม่เห็น</em></p>'.$teacher);
                db_insert("INSERT INTO lc_imports (template_uid, template_code, template_title, version, course_id, user_id, created_at) VALUES (?,?,?,?,?,?,?)",
                    [mb_substr($t['uid'], 0, 50), mb_substr((string)($t['code'] ?? ''), 0, 50), mb_substr($t['title'], 0, 255), (int)($t['version'] ?? 1), $cid, $userId, $now]);
                return ['course_id' => $cid, 'counts' => $counts];
            });
            return ['course_id' => $res['course_id'], 'course_name' => $t['title'], 'warnings' => $warn, 'counts' => $res['counts']];
        } catch(Throwable $e){
            foreach($files as $f) @unlink($f);
            throw ($e instanceof RuntimeException) ? $e : new RuntimeException($e->getMessage());
        }
    }

    /** คอร์สที่ผู้สอนคนนี้เคยนำเข้า (ที่ยังไม่ถูกลบ) — [template_uid => [rows]] */
    public static function importedMap($userId){
        $out = [];
        foreach(db_all("SELECT li.*, c.title AS course_title FROM lc_imports li JOIN courses c ON c.id = li.course_id WHERE li.user_id = ? ORDER BY li.id DESC", [(int)$userId]) as $r)
            $out[$r['template_uid']][] = $r;
        return $out;
    }
    /** รายงานผลกลับไปที่ LC (ล้มเหลวไม่กระทบการนำเข้า) */
    public static function report(array $tpl, $status, $courseId, $courseName, $message, $userName){
        return self::api('report', [], ['uid' => $tpl['uid'] ?? '', 'version' => (int)($tpl['version'] ?? 0), 'status' => $status,
            'course_key' => $courseId ? 'cloud-'.(int)$courseId : '', 'course_name' => $courseName, 'user' => $userName, 'message' => mb_substr((string)$message, 0, 480)]);
    }
    /** เลขเวอร์ชันที่แสดง (ตรงกับ LC): ครั้งที่ n → 1.0.0 … 1.0.9, 1.1.0 … */
    public static function ver($n){ $n = (int)$n; if($n < 1) return '-'; $k = $n + 99; return intval($k / 100).'.'.(intval($k / 10) % 10).'.'.($k % 10); }
    public static function termLabel($t){ $m = [1 => 'ภาคเรียนที่ 1', 2 => 'ภาคเรียนที่ 2', 3 => 'ภาคฤดูร้อน']; return $m[(int)$t] ?? 'ทั้งปี'; }
}
