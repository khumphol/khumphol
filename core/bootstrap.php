<?php
// โหลดทุกอย่างที่ทุกทางเข้าต้องใช้ (index.php / instructor / admin / api / cron / tests)
require_once __DIR__.'/config.core.php';
require_once __DIR__.'/db.core.php';
require_once __DIR__.'/password.core.php';
require_once __DIR__.'/csrf.core.php';
require_once __DIR__.'/app.core.php';
require_once __DIR__.'/gateway.core.php';
require_once __DIR__.'/mail.core.php';
foreach(['RevenueShareService', 'LedgerService', 'OrderService', 'PayoutService', 'MailService', 'PermissionService', 'CertificateService', 'IntegrationService', 'IndyService'] as $__s)
    require_once dirname(__DIR__).'/services/'.$__s.'.php';
unset($__s);
require_once __DIR__.'/router.core.php';

// ── session + header ความปลอดภัย (ทุกทางเข้าเว็บ — CLI ข้าม) ──
if(PHP_SAPI !== 'cli'){
    if(session_status() === PHP_SESSION_NONE){
        $__https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        session_name('ACSESS');
        session_set_cookie_params(['lifetime' => 0, 'path' => app_base(), 'secure' => $__https, 'httponly' => true, 'samesite' => 'Lax']);
        ini_set('session.use_strict_mode', '1');
        session_start();
        unset($__https);
    }
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
}
