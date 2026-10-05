<?php
// พื้นที่ผู้สอน — /instructor/index.php?p=...  (เมนู/หน้าถูกกรองด้วยสิทธิ์ที่แอดมินกำหนด — PermissionService)
require dirname(__DIR__).'/core/bootstrap.php';
require_login();
if(!is_instructor()){ flash('พื้นที่นี้สำหรับผู้สอนที่ได้รับอนุมัติแล้ว', 'warning'); redirect(u('become-instructor')); }
run_area('instructor', [
    'dashboard'   => 'instructor/dashboard.php',
    'courses'     => ['file' => 'instructor/courses.php',     'feature' => 'courses'],
    'course-edit' => ['file' => 'instructor/course-edit.php', 'feature' => 'courses', 'menu' => 'courses'],
    'lesson-edit' => ['file' => 'instructor/lesson-edit.php', 'feature' => 'courses', 'menu' => 'courses'],
    'students'    => ['file' => 'instructor/students.php',    'feature' => 'students'],
    'coupons'     => ['file' => 'instructor/coupons.php',     'feature' => 'coupons'],
    'earnings'    => ['file' => 'instructor/earnings.php',    'feature' => 'earnings'],
    'profile'     => 'instructor/profile.php',
    'docs'        => ['file' => 'instructor/docs.php',        'feature' => 'docs', 'module' => 'docs'],
    'doc-edit'    => ['file' => 'instructor/doc-edit.php',    'feature' => 'docs', 'module' => 'docs', 'menu' => 'docs'],
    'indy'        => ['file' => 'instructor/indy.php',        'feature' => 'lesson.indy', 'module' => 'indy'],
    'indy-edit'   => ['file' => 'instructor/indy-edit.php',   'feature' => 'lesson.indy', 'module' => 'indy', 'menu' => 'indy'],
], 'dashboard');
