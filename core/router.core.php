<?php
// router แบบ whitelist (?p=) — แนวเดียวกับ aleanor_ai แต่ map ในโค้ดแทนตาราง `list`
// หน้าเพจรันก่อน (จัดการ POST / redirect ได้ เพราะ output ถูก buffer) แล้วค่อยครอบด้วย layout
function run_area($AREA, array $routes, $default){
    $PAGE = get('p', $default);
    if(!isset($routes[$PAGE])){ http_response_code(404); $PAGE = '404'; }
    $TITLE = '';
    ob_start();
    try {
        if($PAGE === '404') echo '<div class="card"><h1>ไม่พบหน้านี้</h1><p class="muted">ลิงก์อาจไม่ถูกต้องหรือถูกลบไปแล้ว</p></div>';
        else require dirname(__DIR__).'/pages/'.$routes[$PAGE];
    } catch(Throwable $e){
        while(ob_get_level() > 1) ob_end_clean();
        error_log('[aleanor_cloud] '.$e);
        http_response_code(500);
        echo '<div class="alert alert-danger">เกิดข้อผิดพลาด: '.h(APP_ENV === 'dev' ? $e->getMessage() : 'กรุณาลองใหม่อีกครั้ง').'</div>';
    }
    $CONTENT = ob_get_clean();
    require dirname(__DIR__).'/views/layout.php';
}
