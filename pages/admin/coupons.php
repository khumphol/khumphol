<?php
// คูปองแพลตฟอร์ม — แพลตฟอร์มออกส่วนลดเอง ผู้สอนได้ส่วนแบ่งเท่ากับขายราคาเต็ม
$TITLE = 'คูปองแพลตฟอร์ม';
$myCourses = db_all("SELECT id, title FROM courses WHERE status = 'published' ORDER BY title");
if(is_post()){
    require_csrf();
    if(post('action') === 'create'){
        $code = strtoupper(preg_replace('~[^A-Za-z0-9_-]~', '', post('code')));
        $type = post('type') === 'fixed' ? 'fixed' : 'percent';
        $val = round((float)post('value'), 2);
        if(strlen($code) < 4 || $val <= 0 || ($type === 'percent' && $val > 100)) flash('โค้ดอย่างน้อย 4 ตัว และส่วนลดต้องถูกต้อง', 'danger');
        elseif(db_val("SELECT id FROM coupons WHERE code = ?", [$code])) flash('โค้ดนี้ถูกใช้แล้ว', 'danger');
        else {
            $id = db_insert("INSERT INTO coupons (code, owner_type, course_id, type, value, max_uses, starts_at, ends_at, created_by, created_at) VALUES (?, 'platform', ?, ?, ?, ?, ?, ?, ?, ?)",
                [$code, (int)post('course_id') ?: null, $type, $val, max(0, (int)post('max_uses')), post('starts_at') ?: null, post('ends_at') ? post('ends_at').' 23:59:59' : null, current_user_id(), now()]);
            audit('coupon_created', 'coupon', $id, null, ['code' => $code, 'type' => $type, 'value' => $val]);
            flash('สร้างคูปองแล้ว');
        }
    }
    if(post('action') === 'toggle') db_write("UPDATE coupons SET is_active = 1 - is_active WHERE id = ?", [(int)post('id')]);
    redirect(au('coupons'));
}
$coupons = db_all("SELECT cp.*, c.title FROM coupons cp LEFT JOIN courses c ON c.id = cp.course_id WHERE cp.owner_type = 'platform' ORDER BY cp.id DESC");
$couponOwner = 'platform';
require __DIR__.'/../_coupon-form.php';
$inst = db_all("SELECT cp.*, c.title, COALESCE(ip.display_name, u.name) AS teacher FROM coupons cp LEFT JOIN courses c ON c.id = cp.course_id
                JOIN users u ON u.id = cp.instructor_id LEFT JOIN instructor_profiles ip ON ip.user_id = cp.instructor_id
                WHERE cp.owner_type = 'instructor' ORDER BY cp.id DESC LIMIT 100");
?>
<div class="card table-wrap mt"><h2>คูปองของผู้สอน (ดูอย่างเดียว)</h2><?php if(!$inst): ?><p class="muted small">ไม่มี</p><?php else: ?><table>
  <tr><th>โค้ด</th><th>ผู้สอน</th><th>ส่วนลด</th><th>คอร์ส</th><th class="num">ใช้แล้ว</th><th>สถานะ</th></tr>
  <?php foreach($inst as $cp): ?><tr><td><?= h($cp['code']) ?></td><td><?= h($cp['teacher']) ?></td><td><?= $cp['type'] === 'percent' ? h((float)$cp['value']).'%' : baht($cp['value']) ?></td>
    <td><?= h($cp['title'] ?? 'ทุกคอร์ส') ?></td><td class="num"><?= (int)$cp['used_count'] ?></td><td><?= (int)$cp['is_active'] ? 'ใช้งาน' : 'ปิด' ?></td></tr><?php endforeach; ?>
</table><?php endif; ?></div>
