<?php
// SSO ฝั่งผู้ออก ticket ให้ Aleanor Playground (แบบเดียวกับ aleanor_ai/playground.php)
// รับ ?return=<callback ของ Playground>&state=… → ล็อกอินอยู่ → redirect กลับพร้อม ticket อายุ 90 วินาที
require __DIR__.'/core/bootstrap.php';
function pgFail($m){ http_response_code(400); exit('<!doctype html><meta charset="utf-8"><body style="font-family:Sarabun,system-ui;padding:3rem;text-align:center">'.h($m).'<br><br><a href="index.php">กลับหน้าแรก</a>'); }
if(!IntegrationService::pgEnabled()) pgFail('ยังไม่ได้เปิดใช้การเชื่อมต่อ Playground');
$base = IntegrationService::pgBase().'/';
$return = (string)($_GET['return'] ?? $base.'sso/callback.php');
if(strncmp($return, $base, strlen($base)) !== 0) pgFail('ปลายทางไม่ถูกต้อง — ไม่ได้อยู่ใต้ URL ของ Playground ที่ตั้งไว้');
$state = preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['state'] ?? ''));
if(!current_user()){ $_SESSION['after_login'] = $_SERVER['REQUEST_URI']; redirect(u('login')); }
$t = IntegrationService::pgIssueTicket(current_user_id());
redirect($return.(strpos($return, '?') !== false ? '&' : '?').'ticket='.rawurlencode($t).($state !== '' ? '&state='.rawurlencode($state) : ''));
