<?php
// ============================================================
// ค่าตั้งค่าหลัก (shipped) — แนวเดียวกับ aleanor_ai
// ค่าจริงเฉพาะเครื่องใส่ใน core/config.local.php (คัดลอกจาก config.local.example.php)
// ============================================================

// โค้ดเขียนแบบเช็คค่าที่คืนมาเอง — ปิด exception ของ mysqli (PHP 8.1+)
if (function_exists('mysqli_report')) { mysqli_report(MYSQLI_REPORT_OFF); }

$__localConfig = __DIR__ . '/config.local.php';
if (is_file($__localConfig)) { require_once $__localConfig; }
unset($__localConfig);

if (!defined('DB_HOST'))     define('DB_HOST', 'localhost');
if (!defined('DB_PORT'))     define('DB_PORT', 8889);           // MAMP
if (!defined('DB_USERNAME')) define('DB_USERNAME', 'root');
if (!defined('DB_PASSWORD')) define('DB_PASSWORD', '');             // ใส่ค่าจริงใน config.local.php เท่านั้น
if (!defined('DB_NAME'))     define('DB_NAME', 'aleanor_cloud');
if (!defined('APP_ENV'))     define('APP_ENV', 'prod');         // dev | prod — ค่าเริ่มต้นปลอดภัย = prod; เครื่องพัฒนาตั้ง dev ใน config.local.php (เปิด gateway จำลอง)

// PHP ตั้งค่าเริ่มต้นเป็น UTC — ทุกทางเข้าต้องใช้เวลาไทย (บทเรียนจาก aleanor_ai)
date_default_timezone_set('Asia/Bangkok');

// ── การแสดง/บันทึกข้อผิดพลาด: production ไม่แสดงให้ผู้ใช้เห็น แต่เขียนลง storage/logs ──
if (!defined('LOG_DIR')) define('LOG_DIR', dirname(__DIR__).'/storage/logs');
error_reporting(E_ALL);
ini_set('display_errors', APP_ENV === 'dev' ? '1' : '0');
ini_set('log_errors', '1');
if (is_dir(LOG_DIR) || @mkdir(LOG_DIR, 0775, true)) ini_set('error_log', LOG_DIR.'/php-'.date('Y-m').'.log');
