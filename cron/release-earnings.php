<?php
// ปล่อยรายได้ผู้สอนที่พ้นช่วงพักเงิน (held → available)
// ตั้ง cron วันละครั้ง เช่น:  5 0 * * *  /Applications/MAMP/bin/php/php8.4.1/bin/php /path/to/aleanor_cloud/cron/release-earnings.php
if(PHP_SAPI !== 'cli'){ http_response_code(403); exit; }
require dirname(__DIR__).'/core/bootstrap.php';
$n = LedgerService::releaseDue();
echo date('Y-m-d H:i:s')." released $n ledger rows\n";
