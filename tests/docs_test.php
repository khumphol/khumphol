<?php
// ============================================================
// เทสต์ Aleanor Docs
// รัน:  /Applications/MAMP/bin/php/php8.4.1/bin/php tests/docs_test.php
//   - unit: ตัวกรอง HTML, คำนวณโควตา, grid/present sanitize (ไม่แตะฐานข้อมูล)
//   - integration: ถังขยะ/โฟลเดอร์/ฉบับย้อนหลัง/โควตา ทำใน db_tx แล้ว rollback ทิ้ง (ข้ามได้ด้วย --unit)
// ============================================================
require __DIR__.'/../core/bootstrap.php';
require_once __DIR__.'/../services/DocsService.php';

$pass = 0; $fail = 0;
function eq($label, $expected, $actual){
    global $pass, $fail;
    if($expected === $actual){ $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label\n      expected: ".var_export($expected, true)."\n      actual:   ".var_export($actual, true)."\n"; }
}
function ok($label, $cond){ eq($label, true, (bool)$cond); }
function section($t){ echo "\n$t\n"; }

// ── 1) ตัวกรอง HTML ──
section('sanitizeHtml()');
$s = DocsService::sanitizeHtml('<p>สวัสดี<script>alert(1)</script></p><script src="x.js"></script>');
ok('ตัด <script> ทิ้งทั้งแท็กและเนื้อใน', stripos($s, 'script') === false && strpos($s, 'alert') === false);
eq('ข้อความปกติยังอยู่', '<p>สวัสดี</p>', $s);
$s = DocsService::sanitizeHtml('<p onclick="x()" onmouseover="y()" style="color:red">a</p><img src="a.png" onerror="alert(1)">');
ok('ตัดแอตทริบิวต์ on* ทั้งหมด', !preg_match('~\son[a-z]+=~i', $s));
ok('style ที่อนุญาตยังอยู่', strpos($s, 'style="color:red"') !== false);
$s = DocsService::sanitizeHtml('<a href="javascript:alert(1)">x</a><a href=" jav&#x09;ascript:alert(1)">y</a><a href="https://ok.test" target="_top">z</a>');
ok('ลิงก์ javascript: ถูกตัด href', strpos($s, 'javascript') === false && strpos($s, 'ascript:') === false);
ok('target ถูกบังคับเป็น _blank + rel noopener', strpos($s, 'target="_blank"') !== false && strpos($s, 'noopener') !== false);
$s = DocsService::sanitizeHtml('<img src="data:text/html;base64,PHNjcmlwdD4=">ข้อความ<img src="uploads/docs/2/a.png">');
ok('รูป data: ที่ไม่ใช่ image ถูกลบทั้งแท็ก', strpos($s, 'data:text') === false);
ok('รูปในระบบยังอยู่', strpos($s, 'uploads/docs/2/a.png') !== false);
$s = DocsService::sanitizeHtml('<div style="background-image:url(javascript:x);color:blue;position:fixed">x</div>');
eq('style: ตัด url()/property ที่ไม่อนุญาต', '<div style="color:blue">x</div>', $s);
$s = DocsService::sanitizeHtml('<iframe src="https://evil"></iframe><svg onload="x()"><circle/></svg><custom-tag>ข้อความ</custom-tag><!-- c -->');
ok('ตัด iframe/svg/คอมเมนต์ แต่เก็บข้อความของแท็กที่ไม่รู้จัก', stripos($s, 'iframe') === false && stripos($s, 'svg') === false && strpos($s, '<!--') === false && strpos($s, 'ข้อความ') !== false);
eq('อินพุตว่าง → สตริงว่าง', '', DocsService::sanitizeHtml('   '));
eq('cleanStyle กัน display:none', 'color:red', DocsService::cleanStyle('display:none;color:red'));
eq('safeHref: path สัมพัทธ์ผ่าน', 'page.html', DocsService::safeHref('page.html'));
eq('safeHref: vbscript: ตัด', '', DocsService::safeHref('vbscript:x'));

section('renderForLesson / grid / present (pure)');
$g = gridSanitize(['sheets' => [['name' => 'S', 'rows' => 3, 'cols' => 2, 'cells' => ['A1' => ['v' => '<b>x</b>'], 'A2' => ['v' => '2'], 'A3' => ['f' => '=A2*3']],
                                  'fmt' => ['A1' => ['bg' => 'red;background:url(x)']]]]]);
ok('grid: สีที่ไม่ใช่ #hex ถูกตัด', empty($g['sheets'][0]['fmt']['A1']['bg']));
$gh = gridRenderHtml($g);
ok('gridRenderHtml escape ค่าในช่อง', strpos($gh, '&lt;b&gt;x&lt;/b&gt;') !== false && strpos($gh, '<b>x</b>') === false);
ok('gridRenderHtml คำนวณสูตร =A2*3 → 6', strpos($gh, '>6<') !== false);
$p = presentSanitize(['slides' => [['layout' => 'image', 'title' => '<script>x</script>T', 'img' => '../../etc/passwd'],
                                   ['layout' => 'content', 'img' => 'uploads/docs/2/a.png']]]);
ok('present: ตัดแท็กในหัวข้อ', strpos($p['slides'][0]['title'], '<') === false);
eq('present: รูปนอก uploads/docs/ ถูกตัด', '', $p['slides'][0]['img']);
eq('present: รูปใน uploads/docs/ ผ่าน', 'uploads/docs/2/a.png', $p['slides'][1]['img']);

// ── 2) โควตา ──
section('quotaCalc()');
$q = DocsService::quotaCalc(100, 40, 10);
eq('ใช้ 40/100 → 40%', 40.0, (float)$q['pct']);
eq('เหลือ 60', 60, $q['left']);
eq('ยังไม่เต็ม', false, $q['full']);
$q = DocsService::quotaCalc(100, 150);
eq('ใช้เกิน → 100% และเต็ม', [100.0, true, 0], [(float)$q['pct'], $q['full'], $q['left']]);
$q = DocsService::quotaCalc(0, 999999);
eq('limit 0 = ไม่จำกัด', [true, false, 0], [$q['unlimited'], $q['full'], (int)$q['pct']]);
eq('formatBytes', ['512 B', '2 KB', '1.5 MB', '1.00 GB'],
   [DocsService::formatBytes(512), DocsService::formatBytes(2048), DocsService::formatBytes(1.5 * 1048576), DocsService::formatBytes(1073741824)]);
eq('versionKeep เล็ก/กลาง/ใหญ่ = 20/5/3', [20, 5, 3],
   [DocsService::versionKeep(1000), DocsService::versionKeep(1048576), DocsService::versionKeep(5 * 1048576)]);

if(in_array('--unit', $argv ?? [], true)){ echo "\nผ่าน $pass · ไม่ผ่าน $fail\n"; exit($fail ? 1 : 0); }

// ── 3) integration (rollback) ──
class DocsTestRollback extends Exception {}
$teacher = (int)db_val("SELECT u.id FROM users u JOIN instructor_profiles ip ON ip.user_id = u.id WHERE ip.status = 'approved' ORDER BY u.id LIMIT 1");
$other   = (int)db_val("SELECT id FROM users WHERE id <> ? AND is_admin = 0 ORDER BY id LIMIT 1", [$teacher]);
if(!$teacher){ echo "\n(ข้าม integration: ไม่มีผู้สอนในฐานข้อมูล)\n"; }
else try {
    db_tx(function() use($teacher, $other){
        $_SESSION['uid'] = $teacher;

        section('เอกสาร + ฉบับย้อนหลัง');
        $id = DocsService::save(0, ['type' => 'write', 'title' => 'ทดสอบ', 'content' => '<p onclick="x()">v1</p><script>bad()</script>'], $teacher);
        $d = DocsService::get($id);
        eq('บันทึกแล้วกรอง HTML', '<p>v1</p>', $d['content']);
        DocsService::save($id, ['title' => 'ทดสอบ', 'content' => '<p>v2</p>'], $teacher);
        DocsService::save($id, ['title' => 'ทดสอบ 3', 'content' => '<p>v3</p>'], $teacher);
        eq('เก็บฉบับก่อนหน้า 2 ฉบับ', 2, count(DocsService::versions($id)));
        $old = DocsService::versions($id)[1];                               // ฉบับเก่าสุด = v1
        ok('ย้อนกลับฉบับเก่าได้', DocsService::restoreVersion($id, $old['id'], $teacher));
        eq('เนื้อหากลับเป็น v1', '<p>v1</p>', DocsService::get($id)['content']);
        eq('ชนิดเปลี่ยนไม่ได้หลังสร้าง', 'write', (function() use($id, $teacher){ DocsService::save($id, ['type' => 'grid', 'title' => 'x', 'content' => '{}'], $teacher); return DocsService::get($id)['type']; })());
        for($i = 0; $i < 25; $i++) DocsService::save($id, ['title' => 't', 'content' => '<p>'.$i.'</p>'], $teacher);
        eq('เก็บไม่เกิน 20 ฉบับ', 20, (int)db_val("SELECT COUNT(*) FROM docs_versions WHERE doc_id = ?", [$id]));

        section('สิทธิ์');
        ok('เจ้าของแก้ได้', DocsService::canEdit($id, $teacher));
        if($other) ok('คนอื่นแก้ไม่ได้', !DocsService::canEdit($id, $other));
        $admin = (int)db_val("SELECT id FROM users WHERE is_admin = 1 AND status = 1 LIMIT 1");
        if($admin) ok('แอดมินแก้ได้', DocsService::canEdit($id, $admin));
        ok('listForOwner มีเอกสารนี้', in_array(['id' => $id, 'title' => 't', 'type' => 'write'], DocsService::listForOwner($teacher), true));
        $html = DocsService::renderForLesson($id);
        ok('renderForLesson คืน HTML ที่กรองแล้ว', strpos($html, '<p>24</p>') !== false && stripos($html, 'onclick') === false);

        section('ถังขยะ: 1 → 2 → 1 → 2 → ลบถาวร');
        $f1 = DocsService::folderCreate('โฟลเดอร์ A', $teacher);
        $f2 = DocsService::folderCreate('ย่อย B', $teacher, $f1);
        $d2 = DocsService::save(0, ['type' => 'grid', 'title' => 'ตาราง', 'content' => gridBlank(), 'folder_id' => $f2], $teacher);
        eq('เอกสารอยู่ในโฟลเดอร์ย่อย', $f2, (int)DocsService::get($d2)['folder_id']);
        eq('breadcrumb 2 ชั้น', ['โฟลเดอร์ A', 'ย่อย B'], array_column(DocsService::folderPath($f2), 'name'));
        ok('ย้ายโฟลเดอร์แม่เข้าลูกตัวเองไม่ได้', !DocsService::moveTo('folder', $f1, $f2, $teacher));
        if($other) ok('ย้ายเอกสารเข้าโฟลเดอร์คนอื่นไม่ได้', !DocsService::moveTo('doc', $id, $f1, $other));

        $usedBefore = DocsService::usedBytes($teacher);
        ok('ลบโฟลเดอร์ (ลงถัง)', DocsService::folderDelete($f1, $teacher));
        eq('สถานะเอกสารข้างใน = 2', 2, (int)db_val("SELECT status FROM docs_documents WHERE id = ?", [$d2]));
        eq('trash_of ชี้โฟลเดอร์หลัก', $f1, (int)db_val("SELECT trash_of FROM docs_documents WHERE id = ?", [$d2]));
        eq('ถังขยะโชว์เฉพาะรายการหลัก (1 โฟลเดอร์)', [['folder', $f1]], array_map(function($r){ return [$r['kind'], $r['id']]; }, DocsService::trashList($teacher)));
        eq('ของในถังยังกินโควตา', $usedBefore, DocsService::usedBytes($teacher));
        ok('trashBytes > 0', DocsService::trashBytes($teacher) > 0);
        eq('get() ไม่คืนเอกสารในถัง', null, DocsService::get($d2));

        ok('กู้คืนโฟลเดอร์', DocsService::trashRestore('folder', $f1, $teacher));
        eq('ทุกอย่างกลับเป็นสถานะ 1', [1, 1, 1], [(int)db_val("SELECT status FROM docs_folders WHERE id = ?", [$f1]),
            (int)db_val("SELECT status FROM docs_folders WHERE id = ?", [$f2]), (int)db_val("SELECT status FROM docs_documents WHERE id = ?", [$d2])]);
        eq('ถังว่าง', 0, DocsService::trashCount($teacher));

        // เอกสารเดี่ยว: ลบ → โฟลเดอร์แม่ถูกลบตามหลัง → กู้คืนแล้วไปอยู่ชั้นบนสุด
        ok('ลบเอกสารเดี่ยว', DocsService::delete($d2, $teacher));
        DocsService::folderDelete($f1, $teacher);
        ok('กู้คืนเอกสาร', DocsService::trashRestore('doc', $d2, $teacher));
        eq('โฟลเดอร์เดิมอยู่ในถัง → กู้คืนไปไว้ชั้นบนสุด', null, DocsService::get($d2)['folder_id']);

        ok('ลบเอกสารอีกครั้ง', DocsService::delete($d2, $teacher));
        $used = DocsService::usedBytes($teacher);
        ok('ลบถาวรเอกสาร', DocsService::trashPurge('doc', $d2, $teacher));
        eq('แถวหายจริง', null, db_one("SELECT id FROM docs_documents WHERE id = ?", [$d2]));
        ok('พื้นที่คืนหลังลบถาวร', DocsService::usedBytes($teacher) < $used);
        ok('ลบถาวรซ้ำไม่ได้', !DocsService::trashPurge('doc', $d2, $teacher));
        ok('กู้คืนของที่ไม่อยู่ในถังไม่ได้', !DocsService::trashRestore('doc', $id, $teacher));
        eq('ล้างถัง (เหลือโฟลเดอร์ A)', 1, DocsService::trashEmpty($teacher));
        eq('โฟลเดอร์ย่อยถูกลบถาวรด้วย', 0, (int)db_val("SELECT COUNT(*) FROM docs_folders WHERE id IN (?,?)", [$f1, $f2]));

        section('ลบอัตโนมัติเมื่อค้างเกินกำหนด');
        DocsService::delete($id, $teacher);
        db_write("UPDATE docs_documents SET deleted_at = ? WHERE id = ?", [date('Y-m-d H:i:s', time() - 40 * 86400), $id]);
        $days = DocsService::trashDays();
        eq('trashAutoPurge ลบของเกิน '.$days.' วัน', $days > 0 ? 1 : 0, DocsService::trashAutoPurge($teacher));

        section('ไฟล์อัปโหลด + โควตา');
        $tmp = tempnam(sys_get_temp_dir(), 'dt');
        file_put_contents($tmp, "a,b\n1,2\n3,=A2+B2\n");
        $r = DocsService::fileStore($teacher, $tmp, 'ทดสอบ.csv', null, false);
        ok('เก็บไฟล์ .csv ได้', $r['ok']);
        $r2 = DocsService::fileStore($teacher, $tmp, 'shell.php', null, false);
        eq('ไฟล์ .php ถูกปฏิเสธ', 'bad_type', $r2['error'] ?? '');
        $o = DocsService::fileOpen($r['id'], $teacher);
        ok('เปิดไฟล์ CSV ด้วย Grid', $o['ok'] && $o['type'] === 'grid');
        $o2 = DocsService::fileOpen($r['id'], $teacher);
        ok('เปิดซ้ำไปที่เอกสารเดิม', $o2['ok'] && $o2['existing'] && $o2['id'] === $o['id']);
        $stored = DocsService::fileGet($r['id'], $teacher)['stored_name'];
        DocsService::fileDelete($r['id'], $teacher);
        ok('ไฟล์จริงยังอยู่ตอนอยู่ในถัง', is_file(DocsService::userDir($teacher, false).$stored));
        DocsService::trashPurge('file', $r['id'], $teacher);
        ok('ลบถาวรแล้วไฟล์จริงหาย', !is_file(DocsService::userDir($teacher, false).$stored));
        $prevQ = setting('docs_quota_mb', '1024');
        setting_set('docs_quota_mb', '0');
        ok('โควตา 0 = ไม่จำกัด', DocsService::quotaAllows($teacher, PHP_INT_MAX / 2));
        setting_set('docs_quota_mb', '1');
        ok('โควตา 1MB ไม่พอสำหรับ 2MB', !DocsService::quotaAllows($teacher, 2 * 1048576));
        setting_set('docs_quota_mb', $prevQ);
        @unlink($tmp);

        throw new DocsTestRollback();
    });
} catch(DocsTestRollback $e){
    setting(null);
    echo "\n  (rollback ข้อมูลทดสอบแล้ว)\n";
}

echo "\nผ่าน $pass · ไม่ผ่าน $fail\n";
exit($fail ? 1 : 0);
