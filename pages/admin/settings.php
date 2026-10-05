<?php
$TITLE = 'ตั้งค่า';
$keys = [
    'site_name'          => ['ชื่อเว็บไซต์', 'text'],
    'site_base_url'      => ['URL เว็บไซต์ (ว่าง = เดาจากคำขอ) ใช้กับ return URL ของ gateway', 'url'],
    'earnings_hold_days' => ['พักรายได้ผู้สอน (วัน)', 'number'],
    'payout_min_amount'  => ['ยอดขั้นต่ำในการโอนรายเดือน (บาท)', 'number'],
    'gateway_fee_rate'   => ['ค่าธรรมเนียม gateway โดยประมาณ (%) — ใช้เมื่อ gateway ไม่ส่งค่าจริง', 'number'],
    'default_platform_rate' => ['อัตราสำรองเมื่อไม่มีกฎ global (%)', 'number'],
    'gw_provider'        => ['ช่องทางชำระเงิน', 'select'],
    'gw_public_key'      => ['Public key', 'text'],
    'gw_secret_key'      => ['Secret key (เว้นว่าง = ไม่เปลี่ยน)', 'secret'],
    'gw_webhook_secret'  => ['Webhook secret', 'text'],
];
if(is_post()){
    require_csrf();
    $changed = [];
    foreach($keys as $k => $def){
        if(!isset($_POST[$k])) continue;
        $v = post($k);
        if($def[1] === 'secret' && $v === '') continue;
        if($def[1] === 'number' && $v !== '' && !is_numeric($v)){ flash($def[0].' ต้องเป็นตัวเลข', 'danger'); redirect(au('settings')); }
        if($k === 'gw_provider' && !in_array($v, ['', 'omise', 'stripe', 'mock'], true)) continue;
        $old = setting($k, '');
        if((string)$old !== (string)$v){
            setting_set($k, $v);
            $changed[$k] = $def[1] === 'secret' ? ['***', '***'] : [$old, $v];
        }
    }
    if($changed) audit('settings_updated', 'settings', null, array_map(function($x){ return $x[0]; }, $changed), array_map(function($x){ return $x[1]; }, $changed));
    flash($changed ? 'บันทึกแล้ว' : 'ไม่มีการเปลี่ยนแปลง');
    redirect(au('settings'));
}
?>
<h1>ตั้งค่า</h1>
<form method="post" class="card"><?= csrf_field() ?>
  <?php foreach($keys as $k => $def): ?>
    <label><?= h($def[0]) ?></label>
    <?php if($def[1] === 'select'): $cur = setting($k, ''); ?>
      <select name="<?= $k ?>"><?php foreach(['' => 'ปิด', 'omise' => 'Omise', 'stripe' => 'Stripe'] + (APP_ENV === 'dev' ? ['mock' => 'จำลอง (dev เท่านั้น)'] : []) as $v => $l): ?>
        <option value="<?= $v ?>" <?= $cur === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select>
    <?php elseif($def[1] === 'secret'): ?>
      <input type="password" name="<?= $k ?>" value="" placeholder="<?= setting($k, '') !== '' ? 'ตั้งไว้แล้ว ••••' : 'ยังไม่ได้ตั้ง' ?>" autocomplete="new-password">
    <?php else: ?>
      <input type="<?= $def[1] === 'number' ? 'text' : $def[1] ?>" name="<?= $k ?>" value="<?= h(setting($k, '')) ?>">
    <?php endif; ?>
  <?php endforeach; ?>
  <p class="small muted mt">Webhook URL: <code><?= h(site_url().'api/pay-webhook.php?secret=<webhook secret>') ?></code></p>
  <p class="small muted">อัตราส่วนแบ่งทั้งระบบตั้งที่หน้า <a href="<?= h(au('revenue')) ?>">ส่วนแบ่งรายได้</a> · ทุกการเปลี่ยนแปลงลง <a href="<?= h(au('audit')) ?>">audit log</a></p>
  <button class="btn btn-primary mt">บันทึก</button>
</form>
