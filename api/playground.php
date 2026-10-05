<?php
// API ให้เซิร์ฟเวอร์ Playground เรียก (X-Api-Key = playground_secret) — actions: ping | sso_verify
header('Content-Type: application/json; charset=utf-8');
require dirname(__DIR__).'/core/bootstrap.php';
function pout($a, $code = 200){ http_response_code($code); echo json_encode($a, JSON_UNESCAPED_UNICODE); exit; }
if(!IntegrationService::pgEnabled()) pout(['ok' => false, 'error' => 'playground integration disabled'], 403);
if(!hash_equals(IntegrationService::pgSecret(), (string)($_SERVER['HTTP_X_API_KEY'] ?? ''))) pout(['ok' => false, 'error' => 'unauthorized'], 401);
switch($_GET['action'] ?? ''){
    case 'ping': pout(['ok' => true, 'system' => 'aleanor_cloud', 'school' => setting('site_name', ''), 'time' => date('c')]);
    case 'sso_verify':
        if(($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') pout(['ok' => false, 'error' => 'method not allowed'], 405);
        $b = json_decode(file_get_contents('php://input'), true);
        $uid = IntegrationService::pgVerifyTicket($b['ticket'] ?? '');
        if(!$uid) pout(['ok' => false, 'error' => 'invalid or expired ticket'], 401);
        $p = IntegrationService::pgProfile($uid);
        $p ? pout(['ok' => true, 'user' => $p]) : pout(['ok' => false, 'error' => 'user not found'], 404);
    default: pout(['ok' => false, 'error' => 'unknown action'], 400);
}
