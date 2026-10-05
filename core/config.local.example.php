<?php
// คัดลอกเป็น core/config.local.php แล้วแก้ค่าให้ตรงเครื่อง (ไฟล์ config.local.php ไม่ถูก commit)
if (!defined('DB_HOST'))     define('DB_HOST', 'localhost');
if (!defined('DB_PORT'))     define('DB_PORT', 8889);
if (!defined('DB_USERNAME')) define('DB_USERNAME', 'root');
if (!defined('DB_PASSWORD')) define('DB_PASSWORD', 'root');
if (!defined('DB_NAME'))     define('DB_NAME', 'aleanor_cloud');
if (!defined('APP_ENV'))     define('APP_ENV', 'dev');
