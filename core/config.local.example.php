<?php
// คัดลอกเป็น core/config.local.php แล้วแก้ค่าให้ตรงเครื่อง (config.local.php ถูก .gitignore — ห้าม commit)
// เครื่องพัฒนาบน MAMP: DB_PORT 8889 + ผู้ใช้/รหัสค่าเริ่มต้นของ MAMP, APP_ENV 'dev' (เปิด gateway จำลอง)
if (!defined('DB_HOST'))     define('DB_HOST', 'localhost');
if (!defined('DB_PORT'))     define('DB_PORT', 3306);
if (!defined('DB_USERNAME')) define('DB_USERNAME', 'CHANGE_ME');
if (!defined('DB_PASSWORD')) define('DB_PASSWORD', 'CHANGE_ME');
if (!defined('DB_NAME'))     define('DB_NAME', 'aleanor_cloud');
if (!defined('APP_ENV'))     define('APP_ENV', 'prod');   // 'dev' บนเครื่องพัฒนาเท่านั้น
