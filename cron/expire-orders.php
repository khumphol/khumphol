<?php
// ออเดอร์ค้างจ่ายเกิน 24 ชม. → failed (คืนสิทธิ์คูปอง) — ตั้ง cron ทุกชั่วโมง:  0 * * * *  php /path/to/aleanor_cloud/cron/expire-orders.php
if(PHP_SAPI !== 'cli'){ http_response_code(403); exit; }
require dirname(__DIR__).'/core/bootstrap.php';
echo date('Y-m-d H:i:s').' expired '.OrderService::expireStale(24)." pending orders\n";
