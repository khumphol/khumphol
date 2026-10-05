<?php
// เข้าห้องเรียนเสมือน (Aleanor VC) ของคอร์ส — ตรวจสิทธิ์แล้วส่ง JWT อายุ 5 นาทีไปที่ VC gateway
require __DIR__.'/core/bootstrap.php';
require_login();
$c = db_one("SELECT * FROM courses WHERE id = ?", [(int)get('course')]);
if(!$c || !(int)$c['vc_enabled'] || !IntegrationService::vcEnabled() || !PermissionService::can($c['instructor_id'], 'vc')){ http_response_code(404); exit('ห้องเรียนเสมือนของคอร์สนี้ยังไม่เปิด'); }
$role = IntegrationService::vcRoleFor($c, current_user_id());
if(!$role){ flash('ต้องลงทะเบียนเรียนก่อนเข้าห้องเรียน', 'warning'); redirect(u('course', ['slug' => $c['slug']])); }
audit('vc_enter', 'course', (int)$c['id'], null, ['role' => $role]);
redirect(IntegrationService::vcLaunchUrl($c, current_user(), $role));
