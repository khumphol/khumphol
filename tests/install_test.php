<?php
// ============================================================
// เทสต์ตัวติดตั้ง (install/)
// รัน:  /Applications/MAMP/bin/php/php8.4.1/bin/php tests/install_test.php [--unit]
//   - unit: แยก SQL, กรองข้อมูลตัวอย่าง, ตรวจคีย์ gateway, สร้าง config, ล็อก (ใช้ไฟล์ชั่วคราว ไม่แตะ core/ หรือ storage/)
//   - integration: ติดตั้งลงฐานใหม่ชั่วคราว aleanor_cloud_installtest_ci (ใช้ผู้ใช้ DB จาก config.local.php) แล้ว DROP ทิ้ง
//     ไม่แตะฐาน aleanor_cloud
// ============================================================
require __DIR__.'/../core/config.core.php';          // ค่า DB_* ของเครื่องนี้ (ใช้เฉพาะ host/port/user/pass)
$tmp = sys_get_temp_dir().'/aleanor_install_test_'.getmypid();
@mkdir($tmp, 0700, true);
define('INST_CONFIG', $tmp.'/config.local.php');
define('INST_LOCK', $tmp.'/install.lock');
define('ALEANOR_INSTALLER', 1);
require __DIR__.'/../install/installer.lib.php';

$pass = 0; $fail = 0;
function eq($label, $expected, $actual){
    global $pass, $fail;
    if($expected === $actual){ $pass++; echo "  ✓ $label\n"; }
    else { $fail++; echo "  ✗ $label\n      expected: ".var_export($expected, true)."\n      actual:   ".var_export($actual, true)."\n"; }
}
function ok($label, $cond){ eq($label, true, (bool)$cond); }
function section($t){ echo "\n$t\n"; }

section('inst_split_sql()');
$st = inst_split_sql("-- หัวไฟล์; มี semicolon\nSET NAMES utf8mb4;\nINSERT INTO t VALUES ('a;b', 'it''s', \"x;\"); /* c; */\n# hash; comment\nSELECT 1");
eq('แยกได้ 3 คำสั่ง (ข้าม ; ในคอมเมนต์/สตริง)', 3, count($st));
eq('สตริงที่มี ; และ \'\' ไม่ถูกตัด', "INSERT INTO t VALUES ('a;b', 'it''s', \"x;\")", $st[1]);
eq('คำสั่งสุดท้ายไม่มี ; ก็ได้', 'SELECT 1', $st[2]);
$all = [];
foreach(inst_sql_files() as $f) $all = array_merge($all, inst_split_sql(file_get_contents($f)));
ok('ทุกไฟล์ใน sql/0*.sql แยกได้', count($all) > 50);
ok('ลำดับไฟล์เริ่ม 001_schema', basename(inst_sql_files()[0]) === '001_schema.sql');

section('ข้อมูลตัวอย่าง');
ok('INSERT IGNORE INTO users = ข้อมูลตัวอย่าง', inst_is_demo_stmt("INSERT IGNORE INTO users (id) VALUES (1)"));
ok('INSERT INTO lessons = ข้อมูลตัวอย่าง', inst_is_demo_stmt("INSERT INTO `lessons` VALUES (1)"));
ok('settings ไม่ใช่ข้อมูลตัวอย่าง', !inst_is_demo_stmt("INSERT INTO settings (k, v) VALUES ('a','b')"));
ok('revenue_share_rules ไม่ใช่ข้อมูลตัวอย่าง', !inst_is_demo_stmt("INSERT INTO revenue_share_rules (scope) SELECT 'global'"));
ok('CREATE TABLE users ไม่ใช่ข้อมูลตัวอย่าง', !inst_is_demo_stmt("CREATE TABLE IF NOT EXISTS users (id INT)"));

section('ตรวจค่า');
eq('แอดมินครบ → ไม่มี error', [], inst_validate_admin('A', 'a@b.co', 'password1', 'password1'));
eq('แอดมินผิด 3 อย่าง', 3, count(inst_validate_admin('A', 'not-mail', 'short', 'other')));
eq('ไม่ตั้ง gateway ได้', [], inst_validate_gateway('', 'test', '', ''));
eq('Omise test + คีย์ test ผ่าน', [], inst_validate_gateway('omise', 'test', 'pkey_test_1', 'skey_test_1'));
eq('Omise live + คีย์ test ไม่ผ่าน', 2, count(inst_validate_gateway('omise', 'live', 'pkey_test_1', 'skey_test_1')));
eq('Omise live + คีย์ live ผ่าน', [], inst_validate_gateway('omise', 'live', 'pkey_5abc', 'skey_5abc'));
eq('Stripe test + คีย์ live ไม่ผ่าน', 1, count(inst_validate_gateway('stripe', 'test', 'pk_live_1', '')));
eq('Stripe live + คีย์ว่าง (ใส่ทีหลัง) ผ่าน', [], inst_validate_gateway('stripe', 'live', '', ''));
eq('ผู้ให้บริการแปลก ๆ ไม่ผ่าน', 1, count(inst_validate_gateway('paypal', 'test', '', '')));
eq('base URL เติม / ท้าย', 'https://ex.com/app/', inst_normalize_base_url(' https://ex.com/app '));
eq('base URL ไม่มี scheme → false', false, inst_normalize_base_url('ex.com'));
ok('ชื่อฐานกัน injection', !inst_valid_db_name('x`; DROP DATABASE y') && inst_valid_db_name('aleanor_cloud_2'));

section('config.local.php + ล็อก');
$cfg = inst_render_config(['host' => 'db.local', 'port' => 3307, 'user' => "u'ser", 'pass' => 'p$a\\ss\'"', 'name' => 'acl'], 'prod');
file_put_contents($tmp.'/check.php', $cfg."\necho json_encode([DB_HOST, DB_PORT, DB_USERNAME, DB_PASSWORD, DB_NAME, APP_ENV]);");
$out = shell_exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($tmp.'/check.php'));
eq('config ที่สร้างรันได้และค่าตรง (รวมอักขระพิเศษในรหัส)', ['db.local', 3307, "u'ser", 'p$a\\ss\'"', 'acl', 'prod'], json_decode((string)$out, true));
ok('config ไม่มี CHANGE_ME เหลือ', strpos($cfg, 'CHANGE_ME') === false);
ok('ยังไม่ติดตั้ง', !inst_installed());
eq('inst_finish เขียน config + lock', '', inst_finish($cfg, ['db' => 'acl']));
ok('ติดตั้งแล้ว → ล็อก', inst_installed() && is_file(INST_LOCK));
ok('install.lock เป็น JSON มีเวลา', isset(json_decode(file_get_contents(INST_LOCK), true)['installed_at']));
@unlink(INST_LOCK);
ok('มีแค่ config ก็ถือว่าติดตั้งแล้ว', inst_installed());
@unlink(INST_CONFIG); @unlink($tmp.'/check.php');

if(!in_array('--unit', $argv, true)){
    section('integration: ติดตั้งลงฐานใหม่ชั่วคราว');
    $name = 'aleanor_cloud_installtest_ci';
    $db = ['host' => DB_HOST, 'port' => DB_PORT, 'user' => DB_USERNAME, 'pass' => DB_PASSWORD, 'name' => $name];
    [$c, $err] = inst_db_connect($db);
    if(!$c){ ok('เชื่อมต่อ DB ('.$err.')', false); }
    else {
        mysqli_query($c, "DROP DATABASE IF EXISTS `$name`");
        eq('สร้างฐานใหม่ได้', '', inst_db_prepare($c, $name));
        eq('ฐานใหม่ว่าง', 0, inst_db_table_count($c));
        $r = inst_run_schema($c, false);
        ok('รันสคีมาทุกไฟล์ไม่มี error'.($r['error'] ? ' — '.$r['error'] : ''), $r['ok'] && count($r['files']) === count(inst_sql_files()));
        ok('ข้ามข้อมูลตัวอย่าง', $r['skipped'] >= 5);
        $q = function($sql) use ($c){ $x = mysqli_query($c, $sql); return $x ? mysqli_fetch_row($x)[0] : null; };
        eq('ไม่มีผู้ใช้ตัวอย่าง', '0', $q("SELECT COUNT(*) FROM users"));
        eq('มีกฎส่วนแบ่ง global', '1', $q("SELECT COUNT(*) FROM revenue_share_rules WHERE scope = 'global'"));
        ok('มี settings ตั้งต้น', (int)$q("SELECT COUNT(*) FROM settings") > 20);
        $files = inst_sql_files();
        eq('บันทึก schema_version', substr(basename(end($files)), 0, 3), $q("SELECT v FROM settings WHERE k = 'schema_version'"));
        ok('ตารางล่าสุด (lc_imports) มีแล้ว', $q("SHOW TABLES LIKE 'lc_imports'") === 'lc_imports');
        ok('ฐานที่มีตารางแล้วนับได้ > 0 (ตัวติดตั้งจะปฏิเสธ)', inst_db_table_count($c) > 30);
        $id = inst_create_admin($c, 'แอดมิน', 'boss@example.test', 'secret-pass-1');
        ok('สร้างแอดมินได้', $id > 0);
        $row = mysqli_fetch_assoc(mysqli_query($c, "SELECT is_admin, status, password_hash FROM users WHERE id = $id"));
        ok('เป็นแอดมิน + รหัสเป็น bcrypt ที่ตรวจผ่าน', $row['is_admin'] == 1 && $row['status'] == 1 && password_verify('secret-pass-1', $row['password_hash']));
        eq('audit log การติดตั้ง', '1', $q("SELECT COUNT(*) FROM audit_logs WHERE action = 'install.admin'"));

        mysqli_query($c, "DROP DATABASE `$name`");
        eq('ติดตั้งแบบมีข้อมูลตัวอย่าง: สร้างฐานใหม่', '', inst_db_prepare($c, $name));
        $r = inst_run_schema($c, true);
        ok('รันสคีมา + ตัวอย่างได้', $r['ok'] && $r['skipped'] === 0);
        eq('มีบัญชีทดสอบ 3 บัญชี', '3', $q("SELECT COUNT(*) FROM users WHERE email LIKE '%@aleanor.test'"));
        $id = inst_create_admin($c, 'แอดมินจริง', 'admin@aleanor.test', 'new-pass-123');
        ok('อีเมลซ้ำบัญชีตัวอย่าง → อัปเดตแทน (รหัสใหม่ใช้ได้)', $id === 1 && password_verify('new-pass-123', $q("SELECT password_hash FROM users WHERE id = 1")));
        mysqli_query($c, "DROP DATABASE `$name`");
        echo "  (DROP ฐาน $name แล้ว)\n";
    }
}
@rmdir($tmp);
echo "\nผ่าน $pass · ไม่ผ่าน $fail\n";
exit($fail ? 1 : 0);
