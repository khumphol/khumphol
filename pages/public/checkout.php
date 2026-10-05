<?php
// ชำระเงิน — ซื้อทันที (?course=) หรือจากตะกร้า (?cart=1) · คูปองใช้ได้ทั้งตะกร้า (กระจายส่วนลดรายคอร์ส)
$TITLE = 'ชำระเงิน';
require_login();
$me = current_user();
$fromCart = get('cart', post('cart')) === '1';
if($fromCart){
    $courses = db_all("SELECT c.* FROM cart_items ci JOIN courses c ON c.id = ci.course_id WHERE ci.user_id = ? AND c.status = 'published' ORDER BY ci.id", [(int)$me['id']]);
} else {
    $c = db_one("SELECT * FROM courses WHERE id = ? AND status = 'published'", [(int)get('course', post('course'))]);
    $courses = $c ? [$c] : [];
}
// ตัดคอร์สที่เป็นเจ้าของ / ลงทะเบียนแล้ว / ฟรี
$courses = array_values(array_filter($courses, function($c) use($me){
    return (int)$c['instructor_id'] !== (int)$me['id'] && (float)$c['price'] > 0 && !OrderService::isEnrolled($me['id'], $c['id']);
}));
if(!$courses){
    if(!$fromCart && !empty($c) && OrderService::isEnrolled($me['id'], $c['id'])) redirect(u('learn', ['course' => $c['id']]));
    echo '<div class="card">ไม่มีคอร์สที่ต้องชำระเงิน — <a href="'.h(u('courses')).'">เลือกดูคอร์ส</a></div>'; return;
}
$code = strtoupper(post('coupon', get('coupon')));
$quote = OrderService::quoteCart($courses, $code);
$err = $quote['coupon_error'];
$self = $fromCart ? ['cart' => '1'] : ['course' => $courses[0]['id']];

if(is_post() && post('action') === 'pay'){
    require_csrf();
    if($err === ''){
        $order = OrderService::createOrder($me['id'], $quote, ['name' => post('bill_name'), 'tax_id' => post('bill_tax_id'), 'address' => post('bill_address')]);
        if((float)$order['total'] <= 0){
            OrderService::attachGatewayRef($order['id'], 'coupon', null);
            OrderService::confirmPaid($order['id']);
            flash('ลงทะเบียนเรียบร้อย');
            redirect(u('my-learning'));
        }
        $desc = count($courses) > 1 ? 'คอร์สออนไลน์ '.count($courses).' คอร์ส' : $courses[0]['title'];
        $res = gwStartPayment($order['total'], $order['order_no'], $desc, ['token' => post('omise_token'), 'source' => post('omise_source')]);
        gwLog($order['id'], 'payment_started', $res['ref'] ?? '', ['ok' => $res['ok'], 'status' => $res['status'] ?? '']);
        if(empty($res['ok'])){
            OrderService::markFailed($order['id'], $res['error'] ?? '');
            $err = $res['error'] ?? 'ชำระเงินไม่สำเร็จ';
        } else {
            OrderService::attachGatewayRef($order['id'], gwProvider(), $res['ref']);
            if(!empty($res['redirect'])) redirect($res['redirect']);
            redirect(u('pay-return', ['order' => $order['order_no']]));
        }
    }
}
?>
<h1>ชำระเงิน</h1>
<?php if($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<div class="grid g2" style="align-items:start">
  <div class="card">
    <?php foreach($quote['items'] as $it): $c = $it['course']; ?>
    <div class="row mb" style="flex-wrap:nowrap">
      <img src="<?= h(cover_url($c)) ?>" alt="" style="width:110px;border-radius:9px;aspect-ratio:16/9;object-fit:cover">
      <div style="flex:1"><strong><?= h($c['title']) ?></strong><div class="small muted"><?= h($c['subtitle']) ?></div></div>
      <div class="right nowrap"><?php if((float)$it['discount'] > 0): ?><div class="small muted" style="text-decoration:line-through"><?= baht($it['list']) ?></div><?php endif; ?><strong><?= baht($it['paid']) ?></strong></div>
    </div>
    <?php endforeach; ?>
    <form method="get" class="row mt">
      <input type="hidden" name="p" value="checkout"><?php foreach($self as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endforeach; ?>
      <input type="text" name="coupon" value="<?= h($code) ?>" placeholder="โค้ดส่วนลด" style="max-width:220px">
      <button class="btn">ใช้โค้ด</button>
    </form>
  </div>
  <div class="card">
    <div class="row between"><span>ราคารวม</span><span><?= baht($quote['list']) ?></span></div>
    <?php if((float)$quote['discount'] > 0): ?>
      <div class="row between" style="color:var(--green)"><span>ส่วนลด (<?= h($quote['coupon']['code']) ?>)</span><span>-<?= baht($quote['discount']) ?></span></div>
    <?php endif; ?>
    <div class="row between mt" style="font-size:1.25rem;font-weight:700;border-top:1px solid var(--border);padding-top:.6rem"><span>ยอดชำระ</span><span><?= baht($quote['total']) ?></span></div>
    <?php if(setting('vat_enabled', '0') === '1'): ?><div class="small muted right">รวม VAT <?= h(setting('vat_rate', '7')) ?>% แล้ว</div><?php endif; ?>
    <form method="post" id="payForm" class="mt">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="pay">
      <?php foreach($self as $k => $v): ?><input type="hidden" name="<?= h($k) ?>" value="<?= h($v) ?>"><?php endforeach; ?>
      <input type="hidden" name="coupon" value="<?= h($quote['coupon'] ? $quote['coupon']['code'] : '') ?>">
      <input type="hidden" name="omise_token" id="omiseToken"><input type="hidden" name="omise_source" id="omiseSource">
      <details class="small mb"><summary>ต้องการใบกำกับภาษีในนามบุคคล/บริษัท</summary>
        <label>ชื่อ / บริษัท</label><input type="text" name="bill_name"><label>เลขประจำตัวผู้เสียภาษี</label><input type="text" name="bill_tax_id" maxlength="20">
        <label>ที่อยู่</label><textarea name="bill_address" rows="2"></textarea></details>
      <?php if(!gwEnabled() && (float)$quote['total'] > 0): ?>
        <div class="alert alert-warning">ยังไม่ได้ตั้งค่าช่องทางชำระเงิน</div>
      <?php else: ?>
        <button class="btn btn-primary btn-block" id="payBtn"><?= (float)$quote['total'] > 0 ? 'ชำระเงิน '.baht($quote['total']) : 'ยืนยันรับคอร์ส' ?></button>
      <?php endif; ?>
    </form>
    <p class="small muted mt">ชำระผ่าน <?= h(gwProvider() === 'mock' ? 'gateway จำลอง (โหมดทดสอบ)' : ucfirst(gwProvider())) ?> — ข้อมูลบัตรไม่ผ่านเซิร์ฟเวอร์ของเรา</p>
  </div>
</div>
<?php if(gwInPage() && (float)$quote['total'] > 0): ?>
<script src="https://cdn.omise.co/omise.js"></script>
<script>
OmiseCard.configure({ publicKey: <?= json_encode(gwPublicKey()) ?> });
document.getElementById('payForm').addEventListener('submit', function(e){
  if(document.getElementById('omiseToken').value || document.getElementById('omiseSource').value) return;
  e.preventDefault();
  OmiseCard.open({
    amount: <?= (int)round($quote['total'] * 100) ?>, currency: 'THB', frameLabel: <?= json_encode(setting('site_name', 'Aleanor Cloud')) ?>,
    submitLabel: 'ชำระเงิน', otherPaymentMethods: 'promptpay',
    onCreateTokenSuccess: function(t){
      document.getElementById(t.indexOf('tokn_') === 0 ? 'omiseToken' : 'omiseSource').value = t;
      document.getElementById('payForm').submit();
    }
  });
});
</script>
<?php endif; ?>
