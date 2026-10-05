<?php
// หลังบ้านแบบเบา — /admin/index.php?p=...
session_start();
require dirname(__DIR__).'/core/bootstrap.php';
require_login();
if(!is_admin()){ http_response_code(403); exit('403 — สำหรับผู้ดูแลระบบเท่านั้น'); }
run_area('admin', [
    'dashboard'   => 'admin/dashboard.php',
    'instructors' => 'admin/instructors.php',
    'instructor'  => 'admin/instructor.php',
    'courses'     => 'admin/courses.php',
    'course'      => 'admin/course.php',
    'revenue'     => 'admin/revenue.php',
    'orders'      => 'admin/orders.php',
    'order'       => 'admin/order.php',
    'coupons'     => 'admin/coupons.php',
    'reports'     => 'admin/reports.php',
    'settings'    => 'admin/settings.php',
    'audit'       => 'admin/audit.php',
], 'dashboard');
