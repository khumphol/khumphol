<?php
// ============================================================
// Payment gateway — ดัดแปลงจาก aleanor_ai/core/gateway.core.php
//   omise  — บัตร/PromptPay จ่ายในหน้าเรา (omise.js) / 3DS redirect
//   stripe — Stripe Checkout (เด้งไปหน้า Stripe แล้วกลับมา)
//   mock   — gateway จำลองสำหรับ APP_ENV=dev เท่านั้น (ทดสอบ flow บน MAMP โดยไม่ต้องมีคีย์)
//
// หลักเดิมของ aleanor_ai: ไม่เชื่อ payload/URL — ถามสถานะจากผู้ให้บริการเองเสมอ, เปิดสิทธิ์ซ้ำได้ (idempotent)
// ต่างจากเดิม: ดึง "ค่าธรรมเนียมจริง" จาก Omise (fee + fee_vat) ไว้คำนวณส่วนแบ่ง
// ============================================================

function gwProvider(){
    $p = setting('gw_provider', APP_ENV === 'dev' ? 'mock' : '');
    if($p === 'mock' && APP_ENV !== 'dev') return '';
    return in_array($p, ['omise', 'stripe', 'mock'], true) ? $p : '';
}
function gwPublicKey(){ return trim(setting('gw_public_key', '')); }
function gwSecretKey(){ return trim(setting('gw_secret_key', '')); }
function gwCurrency(){ return 'thb'; }
function gwEnabled(){
    $p = gwProvider();
    if($p === 'mock') return true;
    return $p !== '' && gwPublicKey() !== '' && gwSecretKey() !== '';
}
function gwInPage(){ return gwProvider() === 'omise'; }
function gwMinAmount(){ return 20; }                       // ขั้นต่ำที่ผู้ให้บริการรับ (THB)
function gwFeeRate(){ return (float)setting('gateway_fee_rate', '3.65'); }

function gwHttp($url, $post = null, $auth = 'basic'){
    $ch = curl_init($url);
    $headers = ['Content-Type: application/x-www-form-urlencoded'];
    $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2];
    if($auth === 'bearer') $headers[] = 'Authorization: Bearer '.gwSecretKey();
    else $opts[CURLOPT_USERPWD] = gwSecretKey().':';
    $opts[CURLOPT_HTTPHEADER] = $headers;
    if($post !== null){ $opts[CURLOPT_POST] = true; $opts[CURLOPT_POSTFIELDS] = http_build_query($post); }
    curl_setopt_array($ch, $opts);
    $body = curl_exec($ch); $err = curl_error($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $code, 'json' => json_decode((string)$body, true), 'err' => $err];
}

function gwReturnUrl($orderNo, $cancel = false){
    return site_url().'index.php?p=pay-return&order='.rawurlencode($orderNo).($cancel ? '&cancel=1' : '');
}

/**
 * เริ่มจ่าย → ['ok','ref','redirect','paid','status','error']
 */
function gwStartPayment($amount, $orderNo, $desc, array $opts = []){
    if(!gwEnabled()) return ['ok' => false, 'error' => 'ยังไม่ได้ตั้งค่าช่องทางชำระเงิน'];
    if((float)$amount < gwMinAmount()) return ['ok' => false, 'error' => 'ยอดชำระขั้นต่ำ '.gwMinAmount().' บาท'];
    $minor = (int)round((float)$amount * 100);
    $desc = mb_substr((string)$desc, 0, 255);

    if(gwProvider() === 'mock'){
        $ref = 'mock_'.bin2hex(random_bytes(8));
        return ['ok' => true, 'ref' => $ref, 'redirect' => u('pay-mock', ['order' => $orderNo]), 'paid' => false, 'status' => 'pending'];
    }

    if(gwProvider() === 'stripe'){
        $r = gwHttp('https://api.stripe.com/v1/checkout/sessions', [
            'mode' => 'payment',
            'success_url' => gwReturnUrl($orderNo),
            'cancel_url'  => gwReturnUrl($orderNo, true),
            'client_reference_id' => $orderNo,
            'metadata[order_no]' => $orderNo,
            'line_items[0][quantity]' => 1,
            'line_items[0][price_data][currency]' => gwCurrency(),
            'line_items[0][price_data][unit_amount]' => $minor,
            'line_items[0][price_data][product_data][name]' => $desc !== '' ? $desc : 'Course purchase',
        ], 'bearer');
        if($r['err'] !== '') return ['ok' => false, 'error' => 'เชื่อมต่อ Stripe ไม่ได้: '.$r['err']];
        $j = $r['json'];
        if(!is_array($j) || isset($j['error'])) return ['ok' => false, 'error' => ($j['error']['message'] ?? 'ชำระเงินไม่สำเร็จ')];
        return ['ok' => true, 'ref' => $j['id'] ?? '', 'redirect' => $j['url'] ?? null, 'paid' => false, 'status' => 'open'];
    }

    // Omise
    $source = trim((string)($opts['source'] ?? '')); $token = trim((string)($opts['token'] ?? ''));
    if($source === '' && $token === '') return ['ok' => false, 'error' => 'ไม่พบข้อมูลบัตร/ช่องทางชำระ'];
    $post = ['amount' => $minor, 'currency' => gwCurrency(), 'description' => $desc, 'metadata[order_no]' => $orderNo,
             'return_uri' => gwReturnUrl($orderNo)];
    if($source !== '') $post['source'] = $source; else $post['card'] = $token;
    $r = gwHttp('https://api.omise.co/charges', $post);
    if($r['err'] !== '') return ['ok' => false, 'error' => 'เชื่อมต่อ Omise ไม่ได้: '.$r['err']];
    $j = $r['json'];
    if(!is_array($j) || ($j['object'] ?? '') === 'error') return ['ok' => false, 'error' => ($j['message'] ?? 'ชำระเงินไม่สำเร็จ')];
    return ['ok' => true, 'ref' => $j['id'] ?? '', 'redirect' => $j['authorize_uri'] ?? null, 'paid' => !empty($j['paid']), 'status' => $j['status'] ?? ''];
}

/**
 * ถามสถานะจริงจากผู้ให้บริการ → ['ok','paid','status','order_no','fee'(null = ไม่ทราบ → ประมาณจากอัตรา)]
 * mock: สถานะเก็บใน session ของหน้า pay-mock (dev เท่านั้น)
 */
function gwRetrieve($ref){
    if(!gwEnabled() || $ref === '') return ['ok' => false, 'error' => 'gateway off'];
    if(gwProvider() === 'mock'){
        $st = $_SESSION['mock_pay'][$ref] ?? 'pending';
        return ['ok' => true, 'paid' => $st === 'successful', 'status' => $st, 'order_no' => '', 'fee' => null];
    }
    if(gwProvider() === 'stripe'){
        $r = gwHttp('https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($ref), null, 'bearer');
        $j = $r['json'];
        if(!is_array($j) || isset($j['error'])) return ['ok' => false, 'error' => $j['error']['message'] ?? 'error'];
        $paid = ($j['payment_status'] ?? '') === 'paid';
        $st = $paid ? 'successful' : ((($j['status'] ?? '') === 'expired') ? 'failed' : 'pending');
        return ['ok' => true, 'paid' => $paid, 'status' => $st, 'order_no' => $j['metadata']['order_no'] ?? ($j['client_reference_id'] ?? ''), 'fee' => null];
    }
    $r = gwHttp('https://api.omise.co/charges/'.rawurlencode($ref));
    $j = $r['json'];
    if(!is_array($j) || ($j['object'] ?? '') === 'error') return ['ok' => false, 'error' => $j['message'] ?? 'error'];
    $fee = isset($j['fee']) ? (((int)$j['fee'] + (int)($j['fee_vat'] ?? 0)) / 100) : null;
    return ['ok' => true, 'paid' => !empty($j['paid']), 'status' => $j['status'] ?? '', 'order_no' => $j['metadata']['order_no'] ?? '', 'fee' => $fee];
}

function gwRefFromWebhook($payload){
    if(!is_array($payload)) return '';
    if(gwProvider() === 'stripe'){
        $id = (string)($payload['data']['object']['id'] ?? '');
        return strpos($id, 'cs_') === 0 ? $id : '';
    }
    $id = (string)($payload['data']['id'] ?? ($payload['id'] ?? ''));
    return strpos($id, 'chrg_') === 0 ? $id : '';
}

function gwLog($orderId, $event, $ref, $payload){
    db_insert("INSERT INTO payment_events (order_id, gateway, event, ref, payload, created_at) VALUES (?,?,?,?,?,?)", [
        $orderId ? (int)$orderId : null, gwProvider() ?: 'none', mb_substr($event, 0, 60), (string)$ref,
        mb_substr(json_encode($payload, JSON_UNESCAPED_UNICODE), 0, 4000), now()]);
}

/**
 * คืนเงินผ่าน gateway (บางส่วนได้) → ['ok','ref','error']
 * Omise: POST /charges/{id}/refunds · Stripe: หา payment_intent จาก Checkout Session แล้ว POST /v1/refunds
 */
function gwRefund($ref, $amount){
    $minor = (int)round((float)$amount * 100);
    if($ref === '' || $minor <= 0) return ['ok' => false, 'error' => 'ไม่มีข้อมูลการชำระเงินสำหรับคืน'];
    if(gwProvider() === 'mock' || strpos($ref, 'mock_') === 0) return ['ok' => true, 'ref' => 'mockrf_'.bin2hex(random_bytes(6))];
    if(!gwEnabled()) return ['ok' => false, 'error' => 'gateway ยังไม่พร้อม'];
    if(gwProvider() === 'stripe'){
        $s = gwHttp('https://api.stripe.com/v1/checkout/sessions/'.rawurlencode($ref), null, 'bearer')['json'];
        $pi = is_array($s) ? (string)($s['payment_intent'] ?? '') : '';
        if($pi === '') return ['ok' => false, 'error' => 'ไม่พบ payment_intent ของรายการนี้'];
        $r = gwHttp('https://api.stripe.com/v1/refunds', ['payment_intent' => $pi, 'amount' => $minor], 'bearer');
        $j = $r['json'];
        if(!is_array($j) || isset($j['error'])) return ['ok' => false, 'error' => $j['error']['message'] ?? ('คืนเงินไม่สำเร็จ '.$r['err'])];
        return ['ok' => true, 'ref' => $j['id'] ?? ''];
    }
    $r = gwHttp('https://api.omise.co/charges/'.rawurlencode($ref).'/refunds', ['amount' => $minor]);
    $j = $r['json'];
    if(!is_array($j) || ($j['object'] ?? '') === 'error') return ['ok' => false, 'error' => $j['message'] ?? ('คืนเงินไม่สำเร็จ '.$r['err'])];
    return ['ok' => true, 'ref' => $j['id'] ?? ''];
}
