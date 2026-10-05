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
if (!defined('DB_PASSWORD')) define('DB_PASSWORD', 'root');
if (!defined('DB_NAME'))     define('DB_NAME', 'aleanor_cloud');
if (!defined('APP_ENV'))     define('APP_ENV', 'dev');          // dev | prod — dev เปิดใช้ gateway จำลอง (mock)

// PHP ตั้งค่าเริ่มต้นเป็น UTC — ทุกทางเข้าต้องใช้เวลาไทย (บทเรียนจาก aleanor_ai)
date_default_timezone_set('Asia/Bangkok');
