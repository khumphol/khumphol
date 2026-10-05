<?php
// พื้นที่ผู้สอน — /instructor/index.php?p=...
session_start();
require dirname(__DIR__).'/core/bootstrap.php';
require_login();
if(!is_instructor()){ flash('พื้นที่นี้สำหรับผู้สอนที่ได้รับอนุมัติแล้ว', 'warning'); redirect(u('become-instructor')); }
run_area('instructor', [
    'dashboard'   => 'instructor/dashboard.php',
    'courses'     => 'instructor/courses.php',
    'course-edit' => 'instructor/course-edit.php',
    'lesson-edit' => 'instructor/lesson-edit.php',
    'coupons'     => 'instructor/coupons.php',
    'earnings'    => 'instructor/earnings.php',
    'profile'     => 'instructor/profile.php',
], 'dashboard');
