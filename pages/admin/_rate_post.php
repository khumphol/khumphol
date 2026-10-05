<?php
// จัดการ POST ของกล่องตั้งอัตรา (action=set_rate / end_rule) — เรียกก่อน output
if(!function_exists('rate_handle_post')){
    function rate_handle_post($scope, $target, $back){
        if(!is_post()) return;
        $a = post('action');
        if($a !== 'set_rate' && $a !== 'end_rule') return;
        require_csrf();
        try {
            if($a === 'set_rate'){
                $starts = post('starts_at') ? date('Y-m-d H:i:s', strtotime(post('starts_at'))) : null;
                $ends   = post('ends_at')   ? date('Y-m-d H:i:s', strtotime(post('ends_at')))   : null;
                if(post('platform_rate') === '' || !is_numeric(post('platform_rate'))) throw new InvalidArgumentException('กรุณาระบุอัตรา');
                RevenueShareService::setRate($scope, $target, (float)post('platform_rate'), $starts, $ends, post('note'));
                flash('บันทึกอัตราใหม่แล้ว (ยอดขายเดิมไม่เปลี่ยน)');
            } else {
                $r = db_one("SELECT * FROM revenue_share_rules WHERE id = ?", [(int)post('rule_id')]);
                if($r && $r['scope'] === $scope && ($scope === 'global' || (int)($scope === 'course' ? $r['course_id'] : $r['instructor_id']) === (int)$target)){
                    RevenueShareService::endRule($r['id']);
                    flash('ปิดกฎแล้ว — กลับไปใช้อัตราระดับที่กว้างกว่า');
                }
            }
        } catch(InvalidArgumentException $e){ flash($e->getMessage(), 'danger'); }
        redirect($back);
    }
}
