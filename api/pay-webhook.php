<?php
// Webhook ผลการชำระเงิน (Omise / Stripe) — แนวเดียวกับ aleanor_ai/api/pay-webhook.php
//   1) ต้องแนบ ?secret=<gw_webhook_secret>
//   2) ไม่เชื่อ payload — ใช้แค่ ref แล้วถามสถานะจริงจาก API ผู้ให้บริการ
//   3) idempotent: หาออเดอร์จาก gateway_ref (unique) แล้ว confirmPaid() ล็อกแถว — ยิงซ้ำไม่ลงบัญชีซ้ำ
session_start();
header('Content-Type: application/json; charset=utf-8');
require dirname(__DIR__).'/core/bootstrap.php';
function wout($code, $a){ http_response_code($code); echo json_encode($a); exit; }

$secret = setting('gw_webhook_secret', '');
if($secret === '' || !hash_equals($secret, (string)($_GET['secret'] ?? ''))) wout(403, ['ok' => false, 'error' => 'bad_secret']);
if(!gwEnabled() || gwProvider() === 'mock') wout(503, ['ok' => false, 'error' => 'gateway_off']);

$in = json_decode(file_get_contents('php://input'), true);
$ref = gwRefFromWebhook($in);
if($ref === '') wout(400, ['ok' => false, 'error' => 'no_reference']);
gwLog(null, 'webhook_in', $ref, ['key' => $in['key'] ?? ($in['type'] ?? '')]);

$res = gwRetrieve($ref);
if(empty($res['ok'])) wout(502, ['ok' => false, 'error' => 'verify_failed']);
$o = db_one("SELECT * FROM orders WHERE gateway_ref = ?", [$ref]);
if(!$o && !empty($res['order_no'])) $o = db_one("SELECT * FROM orders WHERE order_no = ?", [$res['order_no']]);
if(!$o) wout(404, ['ok' => false, 'error' => 'no_order']);

try {
    if(!empty($res['paid']))            wout(200, ['ok' => true, 'changed' => OrderService::confirmPaid($o['id'], $ref, $res['fee'])]);
    if(($res['status'] ?? '') === 'failed') wout(200, ['ok' => true, 'changed' => OrderService::markFailed($o['id'], 'webhook')]);
} catch(Throwable $e){
    error_log('[aleanor_cloud webhook] '.$e);
    wout(500, ['ok' => false, 'error' => 'internal']);
}
wout(200, ['ok' => true, 'pending' => true]);
