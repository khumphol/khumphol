<?php
// โหลดทุกอย่างที่ทุกทางเข้าต้องใช้ (index.php / instructor / admin / api / cron / tests)
require_once __DIR__.'/config.core.php';
require_once __DIR__.'/db.core.php';
require_once __DIR__.'/password.core.php';
require_once __DIR__.'/csrf.core.php';
require_once __DIR__.'/app.core.php';
require_once __DIR__.'/gateway.core.php';
require_once __DIR__.'/mail.core.php';
foreach(['RevenueShareService', 'LedgerService', 'OrderService', 'PayoutService', 'MailService', 'PermissionService', 'CertificateService', 'IntegrationService'] as $__s)
    require_once dirname(__DIR__).'/services/'.$__s.'.php';
unset($__s);
require_once __DIR__.'/router.core.php';
