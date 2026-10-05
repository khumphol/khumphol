<?php
// router แบบ whitelist (?p=) — แนวเดียวกับ aleanor_ai แต่ map ในโค้ดแทนตาราง `list`
// route: 'p' => 'file.php'  หรือ  'p' => ['file' => 'file.php', 'feature' => 'docs', 'module' => 'docs', 'menu' => 'courses']
//   feature = สิทธิ์ผู้สอน (PermissionService) · module = ต้องเปิดโมดูล · menu = เมนูข้างที่ไฮไลต์
// หน้าเพจรันก่อน (จัดการ POST / redirect ได้ เพราะ output ถูก buffer) แล้วค่อยครอบด้วย layout
function route_allowed($AREA, $r){
    if(!empty($r['module']) && !PermissionService::moduleOn($r['module'])) return false;
    if($AREA === 'instructor' && !empty($r['feature']) && !PermissionService::can(current_user_id(), $r['feature'])) return false;
    return true;
}

function run_area($AREA, array $routes, $default){
    $PAGE = get('p', $default);
    $r = $routes[$PAGE] ?? null;
    if(is_string($r)) $r = ['file' => $r];
    $denied = $r && !route_allowed($AREA, $r);
    $MENU = $r['menu'] ?? null;
    $TITLE = '';
    ob_start();
    try {
        if(!$r){ http_response_code(404); echo '<div class="card"><h1>ไม่พบหน้านี้</h1><p class="muted">ลิงก์อาจไม่ถูกต้องหรือถูกลบไปแล้ว</p></div>'; }
        elseif($denied){ http_response_code(403); $TITLE = 'ไม่มีสิทธิ์';
            echo '<div class="alert alert-danger"><i class="fi fi-rr-lock"></i> คุณไม่มีสิทธิ์ใช้งานส่วนนี้ หรือโมดูลนี้ถูกปิดอยู่ — ติดต่อผู้ดูแลระบบ</div>'; }
        else require dirname(__DIR__).'/pages/'.$r['file'];
    } catch(Throwable $e){
        while(ob_get_level() > 1) ob_end_clean();
        error_log('[aleanor_cloud] '.$e);
        http_response_code(500);
        echo '<div class="alert alert-danger">เกิดข้อผิดพลาด: '.h(APP_ENV === 'dev' ? $e->getMessage() : 'กรุณาลองใหม่อีกครั้ง').'</div>';
    }
    $CONTENT = ob_get_clean();
    require dirname(__DIR__).'/views/'.($AREA === 'public' ? 'layout.php' : 'dashboard.php');
}

/** เมนูข้างของพื้นที่หลังบ้าน (แบน ๆ แบบ aleanor_ai) — กรองตามโมดูล/สิทธิ์แล้ว */
function dashboard_menu($AREA){
    $items = $AREA === 'admin' ? [
        ['dashboard',   'หน้าแรก',          'fi-rr-home'],
        ['courses',     'คอร์ส',             'fi-rr-books'],
        ['instructors', 'ผู้สอน',            'fi-rr-chalkboard-user'],
        ['members',     'สมาชิก',            'fi-rr-users'],
        ['orders',      'คำสั่งซื้อ & คืนเงิน', 'fi-rr-shopping-cart'],
        ['payouts',     'รอบจ่ายเงินผู้สอน',  'fi-rr-money-check-edit'],
        ['revenue',     'ส่วนแบ่งรายได้',     'fi-rr-chart-pie-alt'],
        ['reports',     'รายงาน',            'fi-rr-chart-histogram'],
        ['system',      'ผู้ดูแลระบบ',        'fi-rr-settings'],
    ] : [
        ['dashboard',   'หน้าแรก',           'fi-rr-home'],
        ['courses',     'คอร์สของฉัน',        'fi-rr-books',           'courses'],
        ['students',    'ผู้เรียน',            'fi-rr-graduation-cap',  'students'],
        ['docs',        'Aleanor Docs',       'fi-rr-document',        'docs'],
        ['indy',        'Aleanor Indy',       'fi-rr-shuffle',         'lesson.indy'],
        ['coupons',     'คูปอง',              'fi-rr-ticket',          'coupons'],
        ['earnings',    'รายได้',              'fi-rr-sack-dollar',     'earnings'],
        ['profile',     'โปรไฟล์ & บัญชีรับเงิน', 'fi-rr-id-badge'],
    ];
    $out = [];
    foreach($items as $it){
        if(isset($it[3]) && !PermissionService::can(current_user_id(), $it[3])) continue;
        $out[] = ['p' => $it[0], 'label' => $it[1], 'icon' => $it[2]];
    }
    return $out;
}
