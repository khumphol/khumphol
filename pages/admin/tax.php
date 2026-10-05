<?php
$TITLE = 'ภาษี & ใบเสร็จ';
$keys = ['vat_enabled' => 'bool', 'vat_rate' => 'num', 'seller_name' => 'text', 'seller_tax_id' => 'text', 'seller_address' => 'text', 'withholding_tax_rate' => 'num', 'payout_min_amount' => 'num'];
if(is_post()){
    require_csrf();
    $ch = [];
    foreach($keys as $k => $t){
        $v = $t === 'bool' ? (post($k) ? '1' : '0') : post($k);
        if($t === 'num' && (!is_numeric($v) || $v < 0)){ flash('ค่าตัวเลขไม่ถูกต้อง', 'danger'); redirect(au('tax')); }
        $old = setting($k, '');
        if($old !== $v){ setting_set($k, $v); $ch[$k] = [$old, $v]; }
    }
    if($ch) audit('tax_settings', 'settings', null, array_map(function($x){ return $x[0]; }, $ch), array_map(function($x){ return $x[1]; }, $ch));
    flash('บันทึกแล้ว'); redirect(au('tax'));
}
?>
<div class="page-header"><div><h1><i class="fi fi-rr-receipt"></i> ภาษี & ใบเสร็จ</h1><p>VAT แบบราคารวมภาษี (ราคาที่แสดง = ราคาสุทธิ) · หัก ณ ที่จ่ายคิดตอนสร้างรอบจ่ายผู้สอน</p></div></div>
<form method="post" class="card"><?= csrf_field() ?>
  <h2>ใบเสร็จ / ใบกำกับภาษี</h2>
  <label class="tgl"><input type="checkbox" name="vat_enabled" value="1" <?= setting('vat_enabled', '0') === '1' ? 'checked' : '' ?>><span class="tgl-track"></span> จดทะเบียน VAT (แสดง VAT ในใบเสร็จ)</label>
  <div class="grid g3">
    <div><label>อัตรา VAT (%)</label><input type="number" step="0.01" name="vat_rate" value="<?= h(setting('vat_rate', '7')) ?>"></div>
    <div><label>ชื่อผู้ขาย</label><input type="text" name="seller_name" value="<?= h(setting('seller_name', '')) ?>"></div>
    <div><label>เลขประจำตัวผู้เสียภาษี</label><input type="text" name="seller_tax_id" value="<?= h(setting('seller_tax_id', '')) ?>"></div>
  </div>
  <label>ที่อยู่ผู้ขาย</label><textarea name="seller_address" rows="2"><?= h(setting('seller_address', '')) ?></textarea>
  <h2 class="mt2">การจ่ายเงินผู้สอน</h2>
  <div class="grid g3">
    <div><label>หัก ณ ที่จ่าย (%)</label><input type="number" step="0.01" name="withholding_tax_rate" value="<?= h(setting('withholding_tax_rate', '3')) ?>"></div>
    <div><label>ยอดขั้นต่ำต่อรอบ (บาท)</label><input type="number" step="0.01" name="payout_min_amount" value="<?= h(setting('payout_min_amount', '500')) ?>"></div>
  </div>
  <button class="btn btn-primary mt"><i class="fi fi-rr-disk"></i> บันทึก</button>
</form>
