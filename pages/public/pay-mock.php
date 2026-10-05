<?php
// gateway จำลอง (APP_ENV=dev เท่านั้น) — กดเลือกผลการจ่ายเพื่อทดสอบ flow ครบวง
$TITLE = 'Gateway จำลอง';
require_login();
if(gwProvider() !== 'mock'){ http_response_code(404); echo '<div class="card">ไม่พบหน้านี้</div>'; return; }
$o = db_one("SELECT * FROM orders WHERE order_no = ? AND user_id = ?", [get('order'), current_user_id()]);
if(!$o || !$o['gateway_ref']){ echo '<div class="card">ไม่พบคำสั่งซื้อ</div>'; return; }
if(is_post()){
    require_csrf();
    $_SESSION['mock_pay'][$o['gateway_ref']] = post('result') === 'success' ? 'successful' : 'failed';
    redirect(u('pay-return', ['order' => $o['order_no']]));
}
?>
<div class="auth card" style="text-align:center">
  <span class="badge badge-amber">โหมดทดสอบ</span>
  <h1 class="mt">Gateway จำลอง</h1>
  <p class="muted">คำสั่งซื้อ <?= h($o['order_no']) ?> · ยอด <strong><?= baht($o['total']) ?></strong></p>
  <form method="post" class="row">
    <?= csrf_field() ?>
    <button class="btn btn-primary" name="result" value="success">จ่ายสำเร็จ</button>
    <button class="btn btn-danger" name="result" value="fail">จ่ายไม่สำเร็จ</button>
  </form>
</div>
