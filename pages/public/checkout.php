<?php
$TITLE = 'ชำระเงิน';
require_login();
$me = current_user();
$c = db_one("SELECT * FROM courses WHERE id = ? AND status = 'published'", [(int)get('course', post('course'))]);
if(!$c){ echo '<div class="card">ไม่พบคอร์ส</div>'; return; }
if(OrderService::isEnrolled($me['id'], $c['id'])) redirect(u('learn', ['course' => $c['id']]));
if((int)$c['instructor_id'] === (int)$me['id']){ echo '<div class="card">คุณเป็นผู้สอนของคอร์สนี้ ไม่ต้องซื้อ</div>'; return; }
if((float)$c['price'] <= 0) redirect(u('course', ['slug' => $c['slug']]));

$code = strtoupper(post('coupon', get('coupon')));
$quote = OrderService::quote($c, $code);
$err = $quote['coupon_error'];

if(is_post() && post('action') === 'pay'){
    require_csrf();
    if($err === ''){
        $order = OrderService::createOrder($me['id'], $quote);
        if((float)$order['total'] <= 0){
            // คูปอง 100% — ไม่ต้องผ่าน gateway
            OrderService::attachGatewayRef($order['id'], 'coupon', null);
            OrderService::confirmPaid($order['id']);
            flash('ลงทะเบียนเรียบร้อย');
            redirect(u('learn', ['course' => $c['id']]));
        }
        $res = gwStartPayment($order['total'], $order['order_no'], $c['title'], ['token' => post('omise_token'), 'source' => post('omise_source')]);
        gwLog($order['id'], 'payment_started', $res['ref'] ?? '', ['ok' => $res['ok'], 'status' => $res['status'] ?? '']);
        if(empty($res['ok'])){
            OrderService::markFailed($order['id'], $res['error'] ?? '');
            $err = $res['error'] ?? 'ชำระเงินไม่สำเร็จ';
        } else {
            OrderService::attachGatewayRef($order['id'], gwProvider(), $res['ref']);
            if(!empty($res['redirect'])) redirect($res['redirect']);   // Stripe Checkout / 3DS / PromptPay / mock
            redirect(u('pay-return', ['order' => $order['order_no']]));
        }
    }
}
?>
<h1>ชำระเงิน</h1>
<?php if($err): ?><div class="alert alert-danger"><?= h($err) ?></div><?php endif; ?>
<div class="grid g2" style="align-items:start">
  <div class="card">
    <div class="row" style="flex-wrap:nowrap">
      <img src="<?= h(cover_url($c)) ?>" alt="" style="width:120px;border-radius:9px;aspect-ratio:16/9;object-fit:cover">
      <div><strong><?= h($c['title']) ?></strong><div class="small muted"><?= h($c['subtitle']) ?></div></div>
    </div>
    <form method="get" class="row mt">
      <input type="hidden" name="p" value="checkout"><input type="hidden" name="course" value="<?= (int)$c['id'] ?>">
      <input type="text" name="coupon" value="<?= h($code) ?>" placeholder="โค้ดส่วนลด" style="max-width:220px">
      <button class="btn">ใช้โค้ด</button>
    </form>
  </div>
  <div class="card">
    <div class="row between"><span>ราคา</span><span><?= baht($quote['list']) ?></span></div>
    <?php if((float)$quote['discount'] > 0): ?>
      <div class="row between" style="color:var(--green)"><span>ส่วนลด (<?= h($quote['coupon']['code']) ?>)</span><span>-<?= baht($quote['discount']) ?></span></div>
    <?php endif; ?>
    <div class="row between mt" style="font-size:1.25rem;font-weight:700;border-top:1px solid var(--border);padding-top:.6rem"><span>ยอดชำระ</span><span><?= baht($quote['total']) ?></span></div>
    <form method="post" id="payForm" class="mt">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="pay">
      <input type="hidden" name="course" value="<?= (int)$c['id'] ?>">
      <input type="hidden" name="coupon" value="<?= h($quote['coupon'] ? $quote['coupon']['code'] : '') ?>">
      <input type="hidden" name="omise_token" id="omiseToken"><input type="hidden" name="omise_source" id="omiseSource">
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
// Omise: เปิดหน้าต่างกรอกบัตร/PromptPay ของ Omise แล้วส่ง token กลับมาที่ฟอร์ม (แนวเดียวกับ aleanor_ai)
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
