<?php
$TITLE = 'ตะกร้า';
require_login();
$uid = current_user_id();
if(is_post()){
    require_csrf();
    $cid = (int)post('course');
    if(post('action') === 'add'){
        $c = db_one("SELECT * FROM courses WHERE id = ? AND status = 'published'", [$cid]);
        if($c && (float)$c['price'] > 0 && (int)$c['instructor_id'] !== $uid && !OrderService::isEnrolled($uid, $cid)){
            db_write("INSERT IGNORE INTO cart_items (user_id, course_id, created_at) VALUES (?,?,?)", [$uid, $cid, now()]);
            flash('เพิ่มลงตะกร้าแล้ว');
        }
        redirect(post('back') === 'course' && $c ? u('course', ['slug' => $c['slug']]) : u('cart'));
    }
    if(post('action') === 'remove') db_write("DELETE FROM cart_items WHERE user_id = ? AND course_id = ?", [$uid, $cid]);
    redirect(u('cart'));
}
$items = db_all("SELECT c.* FROM cart_items ci JOIN courses c ON c.id = ci.course_id WHERE ci.user_id = ? ORDER BY ci.id", [$uid]);
$total = 0; foreach($items as $c) $total += (float)$c['price'];
?>
<h1><i class="fi fi-rr-shopping-cart" style="color:var(--primary)"></i> ตะกร้า</h1>
<?php if(!$items): ?><div class="card muted">ตะกร้าว่าง — <a href="<?= h(u('courses')) ?>">เลือกดูคอร์ส</a></div><?php return; endif; ?>
<div class="grid g2" style="align-items:start">
  <div class="card"><?php foreach($items as $c): ?>
    <div class="ck-item">
      <?= course_cover($c, '') ?>
      <div style="flex:1"><a href="<?= h(u('course', ['slug' => $c['slug']])) ?>"><strong><?= h($c['title']) ?></strong></a>
        <?php if($c['status'] !== 'published'): ?><div class="small" style="color:var(--red)">คอร์สนี้ปิดการขายแล้ว</div><?php endif; ?></div>
      <strong class="nowrap"><?= baht($c['price']) ?></strong>
      <form method="post"><?= csrf_field() ?><input type="hidden" name="course" value="<?= (int)$c['id'] ?>"><button class="btn btn-sm btn-danger" name="action" value="remove">ลบ</button></form>
    </div><?php endforeach; ?></div>
  <div class="card">
    <div class="row between" style="font-size:1.2rem;font-weight:700"><span>รวม <?= count($items) ?> คอร์ส</span><span><?= baht($total) ?></span></div>
    <a class="btn btn-primary btn-block mt" href="<?= h(u('checkout', ['cart' => '1'])) ?>">ไปชำระเงิน</a>
    <p class="small muted mt">ใส่โค้ดส่วนลดได้ในหน้าถัดไป</p>
  </div>
</div>
