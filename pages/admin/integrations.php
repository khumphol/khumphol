<?php
$TITLE = 'Playground & VC';
$keys = ['playground_url' => 'url', 'playground_secret' => 'secret', 'vc_base_url' => 'url', 'vc_jwt_secret' => 'secret', 'vc_tenant_id' => 'text'];
if(is_post()){
    require_csrf();
    if(post('action') === 'gen'){ $k = post('which') === 'vc' ? 'vc_jwt_secret' : 'playground_secret'; setting_set($k, bin2hex(random_bytes(32))); audit('secret_rotated', 'settings', null, null, ['key' => $k]); flash('สร้างรหัสลับใหม่แล้ว — อย่าลืมไปตั้งที่แอดปลายทางด้วย'); redirect(au('integrations')); }
    $ch = [];
    foreach($keys as $k => $t){
        $v = post($k);
        if($t === 'secret' && $v === '') continue;
        if($t === 'url' && $v !== '' && !preg_match('~^https?://~i', $v)){ flash('URL ต้องขึ้นต้นด้วย http(s)://', 'danger'); redirect(au('integrations')); }
        if(setting($k, '') !== $v){ setting_set($k, rtrim($v, '/')); $ch[$k] = $t === 'secret' ? '***' : $v; }
    }
    if($ch) audit('integrations_updated', 'settings', null, null, $ch);
    flash('บันทึกแล้ว'); redirect(au('integrations'));
}
$pgOk = IntegrationService::pgEnabled(); $vcOk = IntegrationService::vcEnabled();
?>
<div class="page-header"><div><h1><i class="fi fi-rr-plug-connection"></i> Playground & VC</h1><p>เชื่อมแอปภายนอกด้วยรูปแบบ token เดียวกับ aleanor_ai — เปิด/ปิดโมดูลได้ที่ <a href="<?= h(au('modules')) ?>">โมดูล</a></p></div></div>
<form method="post"><?= csrf_field() ?>
<div class="row row-2">
  <div class="card"><h2><i class="fi fi-rr-cube"></i> Aleanor Playground <?= $pgOk ? '<span class="badge badge-success">พร้อม</span>' : '<span class="badge badge-secondary">ยังไม่พร้อม</span>' ?></h2>
    <label>URL ของ Playground</label><input type="url" name="playground_url" value="<?= h(setting('playground_url', '')) ?>" placeholder="https://playground.example.com">
    <label>รหัสลับร่วม (เว้นว่าง = ไม่เปลี่ยน)</label><input type="password" name="playground_secret" placeholder="<?= setting('playground_secret', '') !== '' ? 'ตั้งไว้แล้ว ••••' : '' ?>" autocomplete="new-password">
    <p class="small muted mt">ตั้งใน Playground <code>core/config.local.php</code>:<br><code>define('ALEANOR_URL', '<?= h(rtrim(site_url(), '/')) ?>');</code><br><code>define('ALEANOR_API_KEY', '&lt;รหัสลับเดียวกัน&gt;');</code></p>
    <p class="small muted">บทเรียนชนิด Playground ใช้ "assignment key" ที่สร้างในหน้า teacher ของ Playground</p>
    <button class="btn btn-sm" name="action" value="gen" onclick="this.form.which.value='pg'">สร้างรหัสลับใหม่</button>
  </div>
  <div class="card"><h2><i class="fi fi-rr-users-alt"></i> Aleanor VC <?= $vcOk ? '<span class="badge badge-success">พร้อม</span>' : '<span class="badge badge-secondary">ยังไม่พร้อม</span>' ?></h2>
    <label>URL ของ VC</label><input type="url" name="vc_base_url" value="<?= h(setting('vc_base_url', '')) ?>" placeholder="https://vc.example.com">
    <label>Tenant ID (ตรงกับ vc_tenants ในฝั่ง VC)</label><input type="text" name="vc_tenant_id" value="<?= h(setting('vc_tenant_id', 'cloud')) ?>">
    <label>JWT secret (เว้นว่าง = ไม่เปลี่ยน)</label><input type="password" name="vc_jwt_secret" placeholder="<?= setting('vc_jwt_secret', '') !== '' ? 'ตั้งไว้แล้ว ••••' : '' ?>" autocomplete="new-password">
    <p class="small muted mt">ฝั่ง VC: เพิ่มแถว <code>vc_tenants</code> และใส่ secret ใน <code>.aleanor_vc_secrets.php</code> · ห้องต่อคอร์ส = <code>course-&lt;id&gt;</code> (เปิดที่หน้าแก้ไขคอร์สของผู้สอน)</p>
    <button class="btn btn-sm" name="action" value="gen" onclick="this.form.which.value='vc'">สร้างรหัสลับใหม่</button>
  </div>
</div>
<input type="hidden" name="which" value="">
<button class="btn btn-primary" name="action" value="save"><i class="fi fi-rr-disk"></i> บันทึก</button>
</form>
