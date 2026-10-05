<?php
// ส่งอีเมลที่ค้างในคิว — ตั้ง cron ทุก 5 นาที:  */5 * * * *  php /path/to/aleanor_cloud/cron/send-mail.php
if(PHP_SAPI !== 'cli'){ http_response_code(403); exit; }
require dirname(__DIR__).'/core/bootstrap.php';
$r = MailService::flush(100);
echo date('Y-m-d H:i:s')." mail sent {$r['sent']} failed {$r['failed']}".(mailEnabled() ? '' : ' (mail disabled)')."\n";
