<?php
// คูปองของผู้สอน — ส่วนลดหักจากราคาก่อนแบ่งรายได้ (ผู้สอนและแพลตฟอร์มแบกตามสัดส่วน)
$TITLE = 'คูปอง';
$uid = current_user_id();
$myCourses = db_all("SELECT id, title FROM courses WHERE instructor_id = ? ORDER BY title", [$uid]);
if(is_post()){
    require_csrf();
    if(post('action') === 'create'){
        $code = strtoupper(preg_replace('~[^A-Za-z0-9_-]~', '', post('code')));
        $type = post('type') === 'fixed' ? 'fixed' : 'percent';
        $val = round((float)post('value'), 2);
        $cid = (int)post('course_id');
        if($cid && !in_array($cid, array_map('intval', array_column($myCourses, 'id')), true)) $cid = 0;
        if(strlen($code) < 4 || $val <= 0 || ($type === 'percent' && $val > 100)) flash('โค้ดอย่างน้อย 4 ตัว และส่วนลดต้องถูกต้อง', 'danger');
        elseif(db_val("SELECT id FROM coupons WHERE code = ?", [$code])) flash('โค้ดนี้ถูกใช้แล้ว', 'danger');
        else {
            db_insert("INSERT INTO coupons (code, owner_type, instructor_id, course_id, type, value, max_uses, starts_at, ends_at, created_by, created_at) VALUES (?, 'instructor', ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$code, $uid, $cid ?: null, $type, $val, max(0, (int)post('max_uses')), post('starts_at') ?: null, post('ends_at') ? post('ends_at').' 23:59:59' : null, $uid, now()]);
            flash('สร้างคูปองแล้ว');
        }
    }
    if(post('action') === 'toggle'){
        db_write("UPDATE coupons SET is_active = 1 - is_active WHERE id = ? AND owner_type = 'instructor' AND instructor_id = ?", [(int)post('id'), $uid]);
    }
    redirect(iu('coupons'));
}
$coupons = db_all("SELECT cp.*, c.title FROM coupons cp LEFT JOIN courses c ON c.id = cp.course_id WHERE cp.owner_type = 'instructor' AND cp.instructor_id = ? ORDER BY cp.id DESC", [$uid]);
$couponOwner = 'instructor';
require __DIR__.'/../_coupon-form.php';
