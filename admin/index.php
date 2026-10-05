<?php
// หลังบ้านแอดมิน (หน้าตาแบบ aleanor_ai dashboard) — /admin/index.php?p=...
session_start();
require dirname(__DIR__).'/core/bootstrap.php';
require_login();
if(!is_admin()){ http_response_code(403); exit('403 — สำหรับผู้ดูแลระบบเท่านั้น'); }
run_area('admin', [
    'dashboard'    => 'admin/dashboard.php',
    'courses'      => 'admin/courses.php',
    'course'       => ['file' => 'admin/course.php', 'menu' => 'courses'],
    'instructors'  => 'admin/instructors.php',
    'instructor'   => ['file' => 'admin/instructor.php', 'menu' => 'instructors'],
    'members'      => 'admin/members.php',
    'orders'       => 'admin/orders.php',
    'order'        => ['file' => 'admin/order.php', 'menu' => 'orders'],
    'payouts'      => 'admin/payouts.php',
    'revenue'      => 'admin/revenue.php',
    'reports'      => 'admin/reports.php',
    'system'       => 'admin/system.php',
    'settings'     => ['file' => 'admin/settings.php', 'menu' => 'system'],
    'modules'      => ['file' => 'admin/modules.php', 'menu' => 'system'],
    'permissions'  => ['file' => 'admin/permissions.php', 'menu' => 'system'],
    'coupons'      => ['file' => 'admin/coupons.php', 'menu' => 'system'],
    'mail'         => ['file' => 'admin/mail.php', 'menu' => 'system'],
    'integrations' => ['file' => 'admin/integrations.php', 'menu' => 'system'],
    'tax'          => ['file' => 'admin/tax.php', 'menu' => 'system'],
    'audit'        => ['file' => 'admin/audit.php', 'menu' => 'system'],
], 'dashboard');
