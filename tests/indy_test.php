<?php
// ============================================================
// เทสต์ Aleanor Indy (IndyService) — ทำใน transaction แล้วโยน exception ท้ายสุดเพื่อ rollback ทิ้ง
// รัน:  /Applications/MAMP/bin/php/php8.4.1/bin/php tests/indy_test.php
// ============================================================
require __DIR__.'/../core/bootstrap.php';
require_once __DIR__.'/../services/IndyService.php';   // เผื่อ bootstrap ยังไม่ได้ require
if(PHP_SAPI !== 'cli'){ http_response_code(403); exit('CLI only'); }

$pass = 0; $fail = 0;
function eq($label, $expected, $actual){
    global $pass, $fail;
    if($expected === $actual){ $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label\n      expected: ".var_export($expected, true)."\n      actual:   ".var_export($actual, true)."\n"; }
}
function throws($label, callable $fn){
    try { $fn(); eq($label, 'exception', 'no exception'); } catch(InvalidArgumentException $e){ eq($label, true, true); }
}
class IndyTestRollback extends Exception {}

$tmpFiles = [];
try {
    db_tx(function() use (&$tmpFiles){
        // ── ข้อมูลตั้งต้น: ผู้สอน, ผู้เรียน, คอร์ส, บทเรียน indy ──
        $t = now();
        $owner   = db_insert("INSERT INTO users (email, password_hash, name, is_admin, status, created_at) VALUES (?,?,?,0,1,?)", ['indy-owner-'.uniqid().'@test.local', 'x', 'Indy Owner', $t]);
        $learner = db_insert("INSERT INTO users (email, password_hash, name, is_admin, status, created_at) VALUES (?,?,?,0,1,?)", ['indy-learner-'.uniqid().'@test.local', 'x', 'Indy Learner', $t]);
        $other   = db_insert("INSERT INTO users (email, password_hash, name, is_admin, status, created_at) VALUES (?,?,?,0,1,?)", ['indy-other-'.uniqid().'@test.local', 'x', 'Other', $t]);
        $course  = db_insert("INSERT INTO courses (instructor_id, slug, title, description, status, created_at, updated_at) VALUES (?,?,?,'', 'draft', ?, ?)", [$owner, 'indy-test-'.uniqid(), 'Indy Test', $t, $t]);
        $otherCourse = db_insert("INSERT INTO courses (instructor_id, slug, title, description, status, created_at, updated_at) VALUES (?,?,?,'', 'draft', ?, ?)", [$other, 'indy-test-o-'.uniqid(), 'Other', $t, $t]);

        echo "\nโปรเจกต์\n";
        throws('ชื่อว่าง → ปฏิเสธ', function() use ($owner){ IndyService::createProject($owner, '   '); });
        throws('ผูกคอร์สของคนอื่น → ปฏิเสธ', function() use ($owner, $otherCourse){ IndyService::createProject($owner, 'X', $otherCourse); });
        $pid = IndyService::createProject($owner, 'ร้านกาแฟ', $course, 'desc', false);
        eq('สร้างโปรเจกต์ได้ id > 0', true, $pid > 0);
        eq('projectsFor() เห็นโปรเจกต์', [$pid], array_map('intval', array_column(IndyService::projectsFor($owner), 'id')));
        eq('playData() ไม่มีฉาก → null', null, IndyService::playData($pid));
        eq('canEdit เจ้าของ', true, IndyService::canEdit($pid, $owner));
        eq('canEdit คนอื่น', false, IndyService::canEdit($pid, $other));

        echo "\nฉาก + ทางเลือก\n";
        $s1 = IndyService::addScene($pid, 'เปิดร้าน', 'https://cdn.example.com/a.mp4');
        $s2 = IndyService::addScene($pid, 'ทักทาย', 'https://cdn.example.com/b.mp4');
        $s3 = IndyService::addScene($pid, 'จบดี', '');
        throws('ลิงก์ไม่ใช่ http(s) → ปฏิเสธ', function() use ($pid){ IndyService::addScene($pid, 'bad', 'javascript:alert(1)'); });
        eq('ฉากแรก = จุดเริ่มต้นอัตโนมัติ', $s1, (int)IndyService::project($pid)['entry_scene_id']);
        eq('scenes() เรียงตาม sort', [$s1, $s2, $s3], array_map('intval', array_column(IndyService::scenes($pid), 'id')));
        $b1 = IndyService::addBranch($s1, $s2, 'ทักทายลูกค้า');
        $b2 = IndyService::addBranch($s1, $s3, 'ข้ามไปตอนจบ');
        IndyService::addBranch($s2, $s3, 'ปิดการขาย');
        throws('ทางเลือกไปฉากตัวเอง → ปฏิเสธ', function() use ($s1){ IndyService::addBranch($s1, $s1, 'loop'); });
        throws('ข้อความว่าง → ปฏิเสธ', function() use ($s1, $s2){ IndyService::addBranch($s1, $s2, ' '); });
        eq('branches(s1) 2 ปุ่มตามลำดับ', ['ทักทายลูกค้า', 'ข้ามไปตอนจบ'], array_column(IndyService::branches($s1), 'label'));

        // ทางเลือกข้ามโปรเจกต์ไม่ได้
        $pid2 = IndyService::createProject($owner, 'อีกโปรเจกต์');
        $x1 = IndyService::addScene($pid2, 'x1');
        throws('ทางเลือกข้ามโปรเจกต์ → ปฏิเสธ', function() use ($s1, $x1){ IndyService::addBranch($s1, $x1, 'ข้าม'); });
        throws('setEntry ฉากโปรเจกต์อื่น → ปฏิเสธ', function() use ($pid, $x1){ IndyService::setEntry($pid, $x1); });
        eq('deleteBranch ด้วย project ผิด → ไม่ลบ', 0, IndyService::deleteBranch($b2, $pid2));

        echo "\nplayData()\n";
        $d = IndyService::playData($pid);
        eq('คีย์ระดับบน', ['name', 'entry', 'scenes', 'controls'], array_keys($d));
        eq('name', 'ร้านกาแฟ', $d['name']);
        eq('entry = s1 (int)', $s1, $d['entry']);
        eq('controls = true (ไม่ซ่อน)', true, $d['controls']);
        eq('scenes คีย์เป็น id', [(string)$s1, (string)$s2, (string)$s3], array_map('strval', array_keys($d['scenes'])));
        eq('scene shape', ['name', 'video', 'branches'], array_keys($d['scenes'][(string)$s1]));
        eq('s1 branches', [['label' => 'ทักทายลูกค้า', 'target' => $s2], ['label' => 'ข้ามไปตอนจบ', 'target' => $s3]], $d['scenes'][(string)$s1]['branches']);
        eq('URL วิดีโอคงเดิม', 'https://cdn.example.com/a.mp4', $d['scenes'][(string)$s1]['video']);
        eq('s3 ไม่มีวิดีโอ/ทางเลือก', ['', []], [$d['scenes'][(string)$s3]['video'], $d['scenes'][(string)$s3]['branches']]);
        IndyService::updateProject($pid, ['hide_controls' => true]);
        eq('hide_controls → controls = false', false, IndyService::playData($pid)['controls']);
        IndyService::setEntry($pid, $s2);
        eq('setEntry → entry = s2', $s2, IndyService::playData($pid)['entry']);
        IndyService::setEntry($pid, $s1);

        echo "\nอัปโหลดวิดีโอ\n";
        $fake = tempnam(sys_get_temp_dir(), 'indy'); file_put_contents($fake, 'not a video'); $tmpFiles[] = $fake;
        $r = IndyService::storeUpload($pid, ['name' => 'a.mp4', 'tmp_name' => $fake, 'size' => filesize($fake), 'error' => UPLOAD_ERR_OK]);
        eq('ไฟล์ข้อความนามสกุล .mp4 → ปฏิเสธ (mime)', false, $r['ok']);
        $r = IndyService::storeUpload($pid, ['name' => 'a.php', 'tmp_name' => $fake, 'size' => 10, 'error' => UPLOAD_ERR_OK]);
        eq('นามสกุล .php → ปฏิเสธ', false, $r['ok']);
        // mp4 จริงขนาดเล็ก (ftyp box) ให้ finfo รู้จักว่าเป็น video/mp4
        $mp4 = tempnam(sys_get_temp_dir(), 'indy'); $tmpFiles[] = $mp4;
        file_put_contents($mp4, pack('N', 24).'ftypisom'.pack('N', 512).'isomiso2'.pack('N', 8).'free');
        $r = IndyService::storeUpload($pid, ['name' => 'clip.MP4', 'tmp_name' => $mp4, 'size' => filesize($mp4), 'error' => UPLOAD_ERR_OK]);
        eq('mp4 จริง → รับ', true, $r['ok']);
        $uploaded = IndyService::uploadDir($pid).'/'.$r['file'];
        eq('ไฟล์อยู่ใน uploads/indy/<pid>/', true, is_file($uploaded));
        IndyService::updateScene($s3, ['video' => $r['file']]);
        eq('playData ให้ URL ของไฟล์อัปโหลด', true, (bool)preg_match('~uploads/indy/'.$pid.'/[a-f0-9]{24}\.mp4$~', IndyService::playData($pid)['scenes'][(string)$s3]['video']));

        echo "\nความคืบหน้า + ฉากจบ\n";
        eq('isEnding(s1) = false', false, IndyService::isEnding($s1));
        eq('isEnding(s3) = true', true, IndyService::isEnding($s3));
        eq('canPlay ผู้เรียนยังไม่ลงทะเบียน = false', false, IndyService::canPlay($pid, $learner));
        $sec = db_val("SELECT id FROM course_sections WHERE course_id = ? LIMIT 1", [$course]) ?: db_insert("INSERT INTO course_sections (course_id, title, sort_order) VALUES (?, 'บทที่ 1', 1)", [$course]);
        db_insert("INSERT INTO lessons (course_id, section_id, title, type, ref_id, sort_order) VALUES (?,?,?,'indy',?,1)", [$course, (int)$sec, 'Indy บท', $pid]);
        db_insert("INSERT INTO enrollments (user_id, course_id, source, status, created_at) VALUES (?,?,'free','active',?)", [$learner, $course, $t]);
        eq('canPlay ผู้เรียนลงทะเบียนแล้ว = true', true, IndyService::canPlay($pid, $learner));
        eq('canPlay คนนอก = false', false, IndyService::canPlay($pid, $other));
        eq('lessonsUsing() เจอ 1 บท', 1, count(IndyService::lessonsUsing($pid)));
        throws('saveProgress ฉากโปรเจกต์อื่น → ปฏิเสธ', function() use ($learner, $pid, $x1){ IndyService::saveProgress($learner, $pid, $x1); });
        $r = IndyService::saveProgress($learner, $pid, $s1);
        eq('ฉาก s1: ไม่ใช่ฉากจบ', [false, false], [$r['ending'], $r['reached_end']]);
        $r = IndyService::saveProgress($learner, $pid, $s3);
        eq('ฉาก s3: ถึงฉากจบ', [true, true], [$r['ending'], $r['reached_end']]);
        $r = IndyService::saveProgress($learner, $pid, $s2);
        eq('เริ่มใหม่ → reached_end ยังติดอยู่, scene = s2', [true, $s2], [$r['reached_end'], (int)IndyService::progress($learner, $pid)['scene_id']]);
        $html = IndyService::renderForLesson($pid, $learner);
        eq('renderForLesson: มี container + โหลด player', true, strpos($html, 'class="indy-mount"') !== false && strpos($html, 'indy-player.js') !== false);
        eq('renderForLesson: ดูต่อจาก s2', true, strpos($html, '"resume":'.$s2) !== false);
        eq('renderForLesson: ไม่มี </script> หลุดจาก JSON', 2, substr_count($html, '</script>'));
        IndyService::saveProgress($learner, $pid, $s3);
        eq('renderForLesson: ค้างที่ฉากจบ → เริ่มที่ entry (resume null)', true, strpos(IndyService::renderForLesson($pid, $learner), '"resume":null') !== false);

        echo "\nลบ (cascade)\n";
        IndyService::deleteScene($s2);
        eq('ลบฉาก s2 → ทางเลือกเข้า/ออก s2 หายตาม FK', ['ข้ามไปตอนจบ'], array_column(IndyService::branches($s1), 'label'));
        IndyService::deleteScene($s1);
        eq('ลบฉากเริ่มต้น → entry_scene_id = NULL', null, IndyService::project($pid)['entry_scene_id']);
        eq('playData ถอยไปใช้ฉากแรกที่เหลือ', $s3, IndyService::playData($pid)['entry']);
        IndyService::deleteProject($pid);
        eq('ลบโปรเจกต์ → ไม่มีฉากเหลือ', 0, (int)db_val("SELECT COUNT(*) FROM indy_scenes WHERE project_id = ?", [$pid]));
        eq('ลบโปรเจกต์ → progress หาย', 0, (int)db_val("SELECT COUNT(*) FROM indy_progress WHERE project_id = ?", [$pid]));
        eq('ลบโปรเจกต์ → ไฟล์วิดีโอถูกลบ + โฟลเดอร์หาย', [false, false], [is_file($uploaded), is_dir(IndyService::uploadDir($pid))]);
        IndyService::deleteProject($pid2);

        throw new IndyTestRollback();
    });
} catch(IndyTestRollback $e){
    echo "\n(rollback ข้อมูลทดสอบแล้ว)\n";
} catch(Throwable $e){
    $fail++;
    echo "\n✗ ERROR: ".get_class($e).': '.$e->getMessage()."\n".$e->getTraceAsString()."\n";
}
foreach($tmpFiles as $f) @unlink($f);
echo "\nผ่าน $pass · ไม่ผ่าน $fail\n";
exit($fail ? 1 : 0);
