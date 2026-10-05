<?php
// ============================================================
// Aleanor Indy — วิดีโอแบบเลือกเส้นทาง (interactive branching video)
// พอร์ตจาก aleanor_ai/core/indy.core.php: project → scenes (วิดีโอ) → branches (ปุ่มทางเลือก → ฉากถัดไป)
//   ต่างจากต้นทาง: id ตัวเลข + FK CASCADE, prepared statement, ไฟล์แยกโฟลเดอร์ต่อโปรเจกต์,
//   ตรวจ mime ไฟล์วิดีโอ และเก็บความคืบหน้าในตาราง indy_progress ของตัวเอง
// ============================================================

class IndyService
{
    const VIDEO_EXT = ['mp4', 'webm'];
    const VIDEO_MIME = [
        'mp4'  => ['video/mp4', 'application/mp4', 'video/x-m4v', 'video/quicktime'],
        'webm' => ['video/webm', 'audio/webm'],
    ];

    // ── พาธไฟล์ ──
    public static function uploadDir($projectId){ return dirname(__DIR__).'/uploads/indy/'.(int)$projectId; }
    public static function isUrl($v){ return (bool)preg_match('~^https?://~i', (string)$v); }
    /** ชื่อไฟล์ที่เราสร้างเอง (กัน path traversal ตอน unlink/แสดงผล) */
    public static function isLocalFile($v){ return (bool)preg_match('~^[a-f0-9]{24}\.(mp4|webm)$~', (string)$v); }
    public static function videoUrl($projectId, $video){
        $video = (string)$video;
        if($video === '') return '';
        if(self::isUrl($video)) return $video;
        return self::isLocalFile($video) ? asset('uploads/indy/'.(int)$projectId.'/'.$video) : '';
    }
    public static function maxUploadBytes(){ return max(1, (int)setting('indy_max_upload_mb', '200')) * 1024 * 1024; }

    // ── อ่าน ──
    public static function projectsFor($ownerId){
        return db_all("SELECT p.*, c.title AS course_title,
                         (SELECT COUNT(*) FROM indy_scenes s WHERE s.project_id = p.id) AS n_scenes
                       FROM indy_projects p LEFT JOIN courses c ON c.id = p.course_id
                       WHERE p.owner_id = ? ORDER BY p.id DESC", [(int)$ownerId]);
    }
    public static function project($id){
        return db_one("SELECT * FROM indy_projects WHERE id = ?", [(int)$id]);
    }
    public static function scenes($projectId){
        return db_all("SELECT * FROM indy_scenes WHERE project_id = ? ORDER BY sort, id", [(int)$projectId]);
    }
    public static function scene($sceneId){
        return db_one("SELECT * FROM indy_scenes WHERE id = ?", [(int)$sceneId]);
    }
    public static function branches($sceneId){
        return db_all("SELECT * FROM indy_branches WHERE scene_id = ? ORDER BY sort, id", [(int)$sceneId]);
    }
    /** ทางเลือกทั้งโปรเจกต์ จัดกลุ่มตาม scene_id (ลด query ในหน้าแก้ไข/playData) */
    public static function branchesByScene($projectId){
        $out = [];
        foreach(db_all("SELECT b.* FROM indy_branches b JOIN indy_scenes s ON s.id = b.scene_id
                        WHERE s.project_id = ? ORDER BY b.sort, b.id", [(int)$projectId]) as $b)
            $out[(int)$b['scene_id']][] = $b;
        return $out;
    }
    /** บทเรียนที่อ้างโปรเจกต์นี้ (lessons.type='indy' AND ref_id) */
    public static function lessonsUsing($projectId){
        return db_all("SELECT l.id, l.title, l.course_id, c.title AS course_title FROM lessons l JOIN courses c ON c.id = l.course_id
                       WHERE l.type = 'indy' AND l.ref_id = ? ORDER BY l.id", [(int)$projectId]);
    }

    /** แก้ไขได้เมื่อเป็นเจ้าของ หรือเป็นแอดมิน */
    public static function canEdit($projectId, $userId){
        $userId = (int)$userId;
        if($userId <= 0) return false;
        $p = self::project($projectId);
        if(!$p) return false;
        if((int)$p['owner_id'] === $userId) return true;
        return (int)db_val("SELECT is_admin FROM users WHERE id = ? AND status = 1", [$userId]) === 1;
    }

    /** คอร์สต้องเป็นของเจ้าของโปรเจกต์ (หรือ NULL) */
    private static function cleanCourse($ownerId, $courseId){
        $courseId = (int)$courseId;
        if($courseId <= 0) return null;
        $ok = db_val("SELECT id FROM courses WHERE id = ? AND instructor_id = ?", [$courseId, (int)$ownerId]);
        if(!$ok) throw new InvalidArgumentException('คอร์สไม่ถูกต้อง');
        return $courseId;
    }

    // ── โปรเจกต์ ──
    public static function createProject($ownerId, $name, $courseId = null, $description = '', $hideControls = false){
        $name = mb_substr(trim((string)$name), 0, 255);
        if($name === '') throw new InvalidArgumentException('กรุณาตั้งชื่อโปรเจกต์');
        return db_insert("INSERT INTO indy_projects (owner_id, course_id, name, description, hide_controls, status, created_at) VALUES (?,?,?,?,?,1,?)",
            [(int)$ownerId, self::cleanCourse($ownerId, $courseId), $name, (string)$description, $hideControls ? 1 : 0, now()]);
    }
    /** $f: name, description, course_id, hide_controls, status (ส่งเฉพาะที่ต้องการแก้) */
    public static function updateProject($id, array $f){
        $p = self::project($id);
        if(!$p) throw new InvalidArgumentException('ไม่พบโปรเจกต์');
        $set = []; $par = [];
        if(array_key_exists('name', $f)){
            $n = mb_substr(trim((string)$f['name']), 0, 255);
            if($n === '') throw new InvalidArgumentException('กรุณาตั้งชื่อโปรเจกต์');
            $set[] = 'name = ?'; $par[] = $n;
        }
        if(array_key_exists('description', $f)){ $set[] = 'description = ?'; $par[] = (string)$f['description']; }
        if(array_key_exists('course_id', $f)){ $set[] = 'course_id = ?'; $par[] = self::cleanCourse($p['owner_id'], $f['course_id']); }
        if(array_key_exists('hide_controls', $f)){ $set[] = 'hide_controls = ?'; $par[] = $f['hide_controls'] ? 1 : 0; }
        if(array_key_exists('status', $f)){ $set[] = 'status = ?'; $par[] = $f['status'] ? 1 : 0; }
        if(!$set) return 0;
        $par[] = (int)$id;
        return db_write("UPDATE indy_projects SET ".implode(', ', $set)." WHERE id = ?", $par);
    }
    /** ลบโปรเจกต์ (ฉาก/ทางเลือก/ความคืบหน้าหายตาม FK) + ลบไฟล์วิดีโอที่อัปโหลด */
    public static function deleteProject($id){
        $id = (int)$id;
        $files = [];
        foreach(self::scenes($id) as $sc) if(self::isLocalFile($sc['video'])) $files[] = $sc['video'];
        $n = db_write("DELETE FROM indy_projects WHERE id = ?", [$id]);
        $dir = self::uploadDir($id);
        foreach($files as $fn) @unlink($dir.'/'.$fn);
        if(is_dir($dir)){
            foreach(glob($dir.'/*') ?: [] as $left) if(self::isLocalFile(basename($left))) @unlink($left);
            @rmdir($dir);
        }
        return $n;
    }

    // ── ฉาก ──
    private static function cleanVideo($video){
        $video = trim((string)$video);
        if($video === '') return '';
        if(self::isUrl($video)) return mb_substr($video, 0, 500);
        if(self::isLocalFile($video)) return $video;
        throw new InvalidArgumentException('ลิงก์วิดีโอต้องขึ้นต้นด้วย http(s)://');
    }
    public static function addScene($projectId, $name, $video = ''){
        $projectId = (int)$projectId;
        $name = mb_substr(trim((string)$name), 0, 255);
        if($name === '') throw new InvalidArgumentException('กรุณาตั้งชื่อฉาก');
        $p = self::project($projectId);
        if(!$p) throw new InvalidArgumentException('ไม่พบโปรเจกต์');
        $sort = (int)db_val("SELECT COALESCE(MAX(sort),0)+1 FROM indy_scenes WHERE project_id = ?", [$projectId]);
        $id = db_insert("INSERT INTO indy_scenes (project_id, name, video, sort, created_at) VALUES (?,?,?,?,?)",
            [$projectId, $name, self::cleanVideo($video), $sort, now()]);
        // ฉากแรก = จุดเริ่มต้นอัตโนมัติ
        if(empty($p['entry_scene_id'])) self::setEntry($projectId, $id);
        return $id;
    }
    /** $f: name, video (URL/ชื่อไฟล์/'' = ไม่มีวิดีโอ) — เปลี่ยนวิดีโอแล้วลบไฟล์เดิมที่อัปโหลดไว้ */
    public static function updateScene($sceneId, array $f){
        $sc = self::scene($sceneId);
        if(!$sc) throw new InvalidArgumentException('ไม่พบฉาก');
        $set = []; $par = [];
        if(array_key_exists('name', $f)){
            $n = mb_substr(trim((string)$f['name']), 0, 255);
            if($n === '') throw new InvalidArgumentException('กรุณาตั้งชื่อฉาก');
            $set[] = 'name = ?'; $par[] = $n;
        }
        $newVideo = null;
        if(array_key_exists('video', $f)){ $newVideo = self::cleanVideo($f['video']); $set[] = 'video = ?'; $par[] = $newVideo; }
        if(array_key_exists('sort', $f)){ $set[] = 'sort = ?'; $par[] = (int)$f['sort']; }
        if(!$set) return 0;
        $par[] = (int)$sceneId;
        $n = db_write("UPDATE indy_scenes SET ".implode(', ', $set)." WHERE id = ?", $par);
        if($newVideo !== null && $newVideo !== $sc['video'] && self::isLocalFile($sc['video']))
            @unlink(self::uploadDir($sc['project_id']).'/'.$sc['video']);
        return $n;
    }
    /** ลบฉาก — ทางเลือกที่ออก/เข้าฉากนี้หายตาม FK; ถ้าเป็นจุดเริ่มต้นให้ล้างค่า */
    public static function deleteScene($sceneId){
        $sc = self::scene($sceneId);
        if(!$sc) return 0;
        $n = db_write("DELETE FROM indy_scenes WHERE id = ?", [(int)$sceneId]);
        db_write("UPDATE indy_projects SET entry_scene_id = NULL WHERE id = ? AND entry_scene_id = ?", [(int)$sc['project_id'], (int)$sceneId]);
        if(self::isLocalFile($sc['video'])) @unlink(self::uploadDir($sc['project_id']).'/'.$sc['video']);
        return $n;
    }
    public static function setEntry($projectId, $sceneId){
        $ok = db_val("SELECT id FROM indy_scenes WHERE id = ? AND project_id = ?", [(int)$sceneId, (int)$projectId]);
        if(!$ok) throw new InvalidArgumentException('ฉากไม่อยู่ในโปรเจกต์นี้');
        return db_write("UPDATE indy_projects SET entry_scene_id = ? WHERE id = ?", [(int)$sceneId, (int)$projectId]);
    }

    // ── ทางเลือก ──
    public static function addBranch($sceneId, $targetSceneId, $label){
        $label = mb_substr(trim((string)$label), 0, 255);
        if($label === '') throw new InvalidArgumentException('กรุณาใส่ข้อความบนปุ่ม');
        $from = self::scene($sceneId); $to = self::scene($targetSceneId);
        if(!$from || !$to || (int)$from['project_id'] !== (int)$to['project_id']) throw new InvalidArgumentException('ฉากปลายทางไม่ถูกต้อง');
        if((int)$from['id'] === (int)$to['id']) throw new InvalidArgumentException('ทางเลือกต้องไปฉากอื่น');
        $sort = (int)db_val("SELECT COALESCE(MAX(sort),0)+1 FROM indy_branches WHERE scene_id = ?", [(int)$sceneId]);
        return db_insert("INSERT INTO indy_branches (scene_id, target_scene_id, label, sort) VALUES (?,?,?,?)",
            [(int)$sceneId, (int)$targetSceneId, $label, $sort]);
    }
    /** ลบทางเลือก — ส่ง $projectId เพื่อยืนยันว่าอยู่ในโปรเจกต์ที่กำลังแก้ */
    public static function deleteBranch($branchId, $projectId = null){
        if($projectId === null) return db_write("DELETE FROM indy_branches WHERE id = ?", [(int)$branchId]);
        return db_write("DELETE b FROM indy_branches b JOIN indy_scenes s ON s.id = b.scene_id WHERE b.id = ? AND s.project_id = ?",
            [(int)$branchId, (int)$projectId]);
    }

    // ── อัปโหลดวิดีโอ ──
    /**
     * รับไฟล์จาก $_FILES[...] → uploads/indy/<project_id>/<สุ่ม>.<ext>
     * @return array ['ok' => bool, 'file' => ชื่อไฟล์, 'error' => ข้อความ]
     */
    public static function storeUpload($projectId, array $file){
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        $maxMb = (int)(self::maxUploadBytes() / 1048576);
        if($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE)
            return ['ok' => false, 'file' => '', 'error' => 'ไฟล์ใหญ่เกินที่เซิร์ฟเวอร์รับได้ (upload_max_filesize = '.ini_get('upload_max_filesize').')'];
        if($err !== UPLOAD_ERR_OK) return ['ok' => false, 'file' => '', 'error' => 'อัปโหลดไม่สำเร็จ (รหัส '.(int)$err.')'];
        $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
        if(!in_array($ext, self::VIDEO_EXT, true)) return ['ok' => false, 'file' => '', 'error' => 'รองรับเฉพาะไฟล์ .mp4 และ .webm'];
        if((int)($file['size'] ?? 0) <= 0 || (int)$file['size'] > self::maxUploadBytes())
            return ['ok' => false, 'file' => '', 'error' => 'ไฟล์ต้องไม่เกิน '.$maxMb.' MB'];
        $tmp = (string)($file['tmp_name'] ?? '');
        if($tmp === '' || !is_file($tmp)) return ['ok' => false, 'file' => '', 'error' => 'ไม่พบไฟล์ที่อัปโหลด'];
        $mime = '';
        if(function_exists('finfo_open')){ $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = (string)finfo_file($fi, $tmp); finfo_close($fi); }
        if(!in_array($mime, self::VIDEO_MIME[$ext], true))
            return ['ok' => false, 'file' => '', 'error' => 'ไฟล์ไม่ใช่วิดีโอ '.strtoupper($ext).' ที่ถูกต้อง ('.$mime.')'];
        $dir = self::uploadDir($projectId);
        if(!is_dir($dir) && !@mkdir($dir, 0755, true)) return ['ok' => false, 'file' => '', 'error' => 'สร้างโฟลเดอร์เก็บไฟล์ไม่ได้'];
        $fn = bin2hex(random_bytes(12)).'.'.$ext;
        $moved = is_uploaded_file($tmp) ? @move_uploaded_file($tmp, $dir.'/'.$fn) : (PHP_SAPI === 'cli' && @copy($tmp, $dir.'/'.$fn));
        if(!$moved) return ['ok' => false, 'file' => '', 'error' => 'บันทึกไฟล์ไม่สำเร็จ'];
        return ['ok' => true, 'file' => $fn, 'error' => ''];
    }

    // ── ข้อมูลสำหรับ player ──
    /**
     * @return array|null {name, entry, scenes:{id:{name, video, branches:[{label,target}]}}, controls}
     *   null เมื่อไม่พบโปรเจกต์หรือยังไม่มีฉาก
     */
    public static function playData($projectId){
        $p = self::project($projectId);
        if(!$p) return null;
        $scenes = self::scenes($projectId);
        if(!$scenes) return null;
        $byScene = self::branchesByScene($projectId);
        $map = [];
        foreach($scenes as $sc){
            $br = [];
            foreach($byScene[(int)$sc['id']] ?? [] as $b) $br[] = ['label' => $b['label'], 'target' => (int)$b['target_scene_id']];
            $map[(string)$sc['id']] = ['name' => $sc['name'], 'video' => self::videoUrl($p['id'], $sc['video']), 'branches' => $br];
        }
        $entry = (int)$p['entry_scene_id'];
        if(!$entry || !isset($map[(string)$entry])) $entry = (int)$scenes[0]['id'];
        // controls=false → ซ่อนแถบควบคุม (ผู้เรียนเลื่อน/หยุดเองไม่ได้) ตามที่ผู้สอนตั้งค่า
        return ['name' => $p['name'], 'entry' => $entry, 'scenes' => $map, 'controls' => !(int)$p['hide_controls']];
    }

    /** ฉากนี้เป็นฉากจบ (ไม่มีทางเลือกออก) หรือไม่ */
    public static function isEnding($sceneId){
        return (int)db_val("SELECT COUNT(*) FROM indy_branches WHERE scene_id = ?", [(int)$sceneId]) === 0;
    }

    /** จำฉากล่าสุดของผู้เรียน — reached_end ติดเป็น 1 ถาวรเมื่อเคยถึงฉากจบ */
    public static function saveProgress($userId, $projectId, $sceneId){
        $ok = db_val("SELECT id FROM indy_scenes WHERE id = ? AND project_id = ?", [(int)$sceneId, (int)$projectId]);
        if(!$ok) throw new InvalidArgumentException('ฉากไม่อยู่ในโปรเจกต์นี้');
        $end = self::isEnding($sceneId) ? 1 : 0;
        db_write("INSERT INTO indy_progress (user_id, project_id, scene_id, reached_end, updated_at) VALUES (?,?,?,?,?)
                  ON DUPLICATE KEY UPDATE scene_id = VALUES(scene_id), reached_end = GREATEST(reached_end, VALUES(reached_end)), updated_at = VALUES(updated_at)",
            [(int)$userId, (int)$projectId, (int)$sceneId, $end, now()]);
        return ['scene_id' => (int)$sceneId, 'ending' => (bool)$end,
                'reached_end' => (int)db_val("SELECT reached_end FROM indy_progress WHERE user_id = ? AND project_id = ?", [(int)$userId, (int)$projectId]) === 1];
    }
    public static function progress($userId, $projectId){
        return db_one("SELECT * FROM indy_progress WHERE user_id = ? AND project_id = ?", [(int)$userId, (int)$projectId]);
    }

    /** ผู้ใช้เล่น/บันทึกความคืบหน้าโปรเจกต์นี้ได้ไหม: ลงทะเบียนคอร์สที่มีบทเรียน indy ชี้มา หรือเป็นเจ้าของ/แอดมิน */
    public static function canPlay($projectId, $userId){
        if((int)$userId <= 0) return false;
        $enrolled = db_val("SELECT 1 FROM lessons l JOIN enrollments e ON e.course_id = l.course_id AND e.user_id = ? AND e.status = 'active'
                            WHERE l.type = 'indy' AND l.ref_id = ? LIMIT 1", [(int)$userId, (int)$projectId]);
        return $enrolled ? true : self::canEdit($projectId, $userId);
    }

    /**
     * HTML สำหรับหน้าเรียน (echo ได้เลย): กล่อง player + css/js (โหลดครั้งเดียวต่อหน้า) + ข้อมูล JSON
     * ดูต่อจากฉากล่าสุดใน indy_progress (ถ้าฉากนั้นยังอยู่และไม่ใช่ฉากจบ)
     * บันทึกความคืบหน้าผ่าน api/indy.php เฉพาะเมื่อ $userId > 0
     */
    public static function renderForLesson($projectId, $userId){
        $data = self::playData($projectId);
        if(!$data) return '<div class="indy-empty"><i class="fi fi-rr-puzzle-alt"></i> บทเรียนนี้ยังไม่มีฉากวิดีโอ</div>';
        static $assets = false;
        $html = '';
        if(!$assets){
            $assets = true;
            $v = function($f){ $p = dirname(__DIR__).'/assets/indy/'.$f; return asset('assets/indy/'.$f).'?v='.(is_file($p) ? filemtime($p) : 0); };
            $html .= '<link rel="stylesheet" href="'.h($v('indy-player.css')).'">'
                   . '<script src="'.h($v('indy-player.js')).'"></script>';
        }
        $resume = null; $reachedEnd = false;
        if((int)$userId > 0){
            $pg = self::progress($userId, $projectId);
            if($pg){
                $reachedEnd = (int)$pg['reached_end'] === 1;
                $sid = (string)(int)$pg['scene_id'];
                if(isset($data['scenes'][$sid]) && $data['scenes'][$sid]['branches']) $resume = (int)$sid;
            }
        }
        $domId = 'indy-'.(int)$projectId.'-'.bin2hex(random_bytes(3));
        $cfg = [
            'project'    => (int)$projectId,
            'resume'     => $resume,
            'reachedEnd' => $reachedEnd,
            'api'        => (int)$userId > 0 ? asset('api/indy.php') : '',
            'csrf'       => (int)$userId > 0 ? csrf_token() : '',
        ];
        $jf = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
        $html .= '<div class="indy-mount" id="'.$domId.'"></div>'
               . '<script>(function(){var D='.json_encode($data, $jf).',C='.json_encode($cfg, $jf).';'
               . 'IndyPlayer.mount(document.getElementById('.json_encode($domId).'),D,{resumeScene:C.resume,'
               . 'onScene:function(id){if(!C.api)return;var f=new FormData();f.append("action","progress");f.append("project_id",C.project);f.append("scene_id",id);'
               . 'fetch(C.api,{method:"POST",body:f,credentials:"same-origin",headers:{"X-CSRF-Token":C.csrf}})'
               . '.then(function(r){return r.json();}).then(function(j){if(j&&j.ok&&j.reached_end){var el=document.getElementById('.json_encode($domId).');'
               . 'el.dispatchEvent(new CustomEvent("indy:end",{bubbles:true,detail:{project:C.project,scene:id}}));}}).catch(function(){});}});'
               . '})();</script>';
        return $html;
    }
}
