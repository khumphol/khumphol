<?php
$TITLE = 'การเชื่อมต่อ';
$keys = ['playground_url' => 'url', 'playground_secret' => 'secret', 'vc_base_url' => 'url', 'vc_jwt_secret' => 'secret', 'vc_tenant_id' => 'text'];
if(is_post()){
    require_csrf();
    if(post('action') === 'lc' || post('action') === 'lc_test'){
        // Aleanor LC: URL ของคลัง + รหัสสถาบัน + secret (ออกให้ที่หลังบ้าน LC → สถาบัน)
        $url = rtrim(trim(post('lc_url')), '/');
        if($url !== '' && !preg_match('~^https?://~i', $url)){ flash('URL ต้องขึ้นต้นด้วย http(s)://', 'danger'); redirect(au('integrations')); }
        $ch = [];
        $vals = ['lc_url' => $url, 'lc_institution' => preg_replace('~[^A-Za-z0-9_-]~', '', post('lc_institution'))];
        if(post('lc_secret') !== '') $vals['lc_secret'] = post('lc_secret');
        foreach($vals as $k => $v) if(setting($k, '') !== $v){ setting_set($k, $v); $ch[$k] = $k === 'lc_secret' ? '***' : $v; }
        if($ch) audit('integrations_updated', 'settings', null, null, $ch);
        if(post('action') === 'lc_test'){
            $r = LcService::api('ping');
            flash($r['ok'] ? 'เชื่อมต่อได้ ✓ คลัง: '.($r['json']['system'] ?? '-').' · สถาบัน: '.($r['json']['institution']['name'] ?? '-') : 'เชื่อมต่อไม่สำเร็จ: '.$r['error'], $r['ok'] ? 'success' : 'danger');
        }else flash('บันทึกการตั้งค่า Aleanor LC แล้ว');
        redirect(au('integrations'));
    }
    if(post('action') === 'live'){
        // Aleanor Streaming: Cloudflare Stream (ไม่บังคับ) + แบบส่งตรง + TURN
        $turn = trim(preg_replace('~\s+~', ' ', post('live_turn_url')));
        foreach(explode(' ', $turn) as $t) if($t !== '' && !preg_match('~^turns?:~i', $t)){ flash('TURN ต้องขึ้นต้นด้วย turn: หรือ turns:', 'danger'); redirect(au('integrations')); }
        $ch = [];
        $vals = ['live_use_cf' => post('live_use_cf') ? '1' : '0', 'cf_account_id' => preg_replace('~[^A-Za-z0-9]~', '', post('cf_account_id')),
                 'live_p2p_max' => (string)max(1, min(200, (int)post('live_p2p_max'))), 'live_p2p_uplink' => (string)max(1000, min(200000, (int)post('live_p2p_uplink'))),
                 'live_turn_url' => $turn, 'live_turn_user' => mb_substr(post('live_turn_user'), 0, 200)];
        foreach(['cf_api_token', 'live_turn_pass'] as $k) if(post($k) !== '') $vals[$k] = post($k);
        foreach($vals as $k => $v) if(setting($k, '') !== $v){ setting_set($k, $v); $ch[$k] = in_array($k, ['cf_api_token', 'live_turn_pass'], true) ? '***' : $v; }
        if($ch) audit('integrations_updated', 'settings', null, null, $ch);
        if(post('cf_test') && LiveService::cfConfigured()){
            $r = LiveService::cfTest();
            flash($r['ok'] ? 'บันทึกแล้ว · เชื่อมต่อ Cloudflare Stream สำเร็จ' : 'บันทึกแล้ว แต่เชื่อมต่อ Cloudflare ไม่สำเร็จ: '.$r['error'], $r['ok'] ? 'success' : 'warning');
        }else flash(LiveService::L('admin_saved'));
        redirect(au('integrations'));
    }
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
<div class="page-header"><div><h1><i class="fi fi-rr-plug-connection"></i> การเชื่อมต่อ</h1><p>Playground · VC · Aleanor LC · Streaming — เชื่อมแอปภายนอกด้วยรูปแบบ token เดียวกับ aleanor_ai — เปิด/ปิดโมดูลได้ที่ <a href="<?= h(au('modules')) ?>">โมดูล</a></p></div></div>
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
    <p class="small muted mt">ฝั่ง VC: เพิ่มแถว <code>vc_tenants</code> และใส่ secret ใน <code>.aleanor_vc_secrets.php</code> · ห้องต่อคอร์ส = <code>course-&lt;id&gt;</code> (ผูกกับคอร์สเสมอ · เปิดที่หน้าแก้ไขคอร์สของผู้สอน)</p>
    <button class="btn btn-sm" name="action" value="gen" onclick="this.form.which.value='vc'">สร้างรหัสลับใหม่</button>
  </div>
</div>
<input type="hidden" name="which" value="">
<button class="btn btn-primary" name="action" value="save"><i class="fi fi-rr-disk"></i> บันทึก</button>
</form>

<form method="post" class="mt-2" autocomplete="off"><?= csrf_field() ?>
<div class="card"><h2 style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap"><img src="<?= h(LcService::logoUrl()) ?>" alt="Aleanor LC" style="height:28px;width:auto"> <?= LcService::configured() ? '<span class="badge badge-success">พร้อม</span>' : '<span class="badge badge-secondary">ยังไม่พร้อม</span>' ?></h2>
  <p class="small muted">คลังบทเรียนต้นแบบกลาง — ผู้สอนค้นหาและนำเข้าเป็นคอร์สใหม่ได้ที่ "คอร์สของฉัน → นำเข้าจากคลัง Aleanor LC" · ขอรหัสสถาบันและ secret จากผู้ดูแล Aleanor LC (หลังบ้าน LC → สถาบัน) · เปิด/ปิดที่ <a href="<?= h(au('modules')) ?>">โมดูล</a></p>
  <div class="row row-2">
    <div><label>URL ของคลัง</label><input type="url" name="lc_url" value="<?= h(LcService::baseUrl()) ?>" placeholder="https://lc.aleanor.example"></div>
    <div><label>รหัสสถาบัน</label><input type="text" name="lc_institution" value="<?= h(LcService::institution()) ?>" placeholder="เช่น aleanor-cloud"></div>
  </div>
  <label>Secret (เว้นว่าง = ไม่เปลี่ยน)</label><input type="password" name="lc_secret" placeholder="<?= LcService::secret() !== '' ? 'ตั้งไว้แล้ว ••••' : '' ?>" autocomplete="new-password">
  <div class="row mt"><button class="btn btn-primary" name="action" value="lc"><i class="fi fi-rr-disk"></i> บันทึก Aleanor LC</button>
    <button class="btn btn-outline" name="action" value="lc_test"><i class="fi fi-rr-plug-connection"></i> บันทึกและทดสอบการเชื่อมต่อ</button></div>
</div>
</form>

<?php $cfOk = LiveService::cfConfigured(); $lm = LiveService::mode(); ?>
<form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="action" value="live">
<div class="card"><h2><i class="fi fi-rr-broadcast-tower"></i> Aleanor Streaming <span class="badge badge-success"><?= $lm === 'cf' ? 'Cloudflare Stream' : 'ส่งตรง (P2P)' ?></span></h2>
  <p class="small muted">ผู้สอนกดสอนสดจากเบราว์เซอร์ที่หน้าแก้ไขคอร์ส ผู้เรียนที่ลงทะเบียนดูในหน้าเรียน · เปิด/ปิดทั้งระบบที่ <a href="<?= h(au('modules')) ?>">โมดูล</a> · รายผู้สอนที่ <a href="<?= h(au('permissions')) ?>">สิทธิ์ผู้สอน</a> · <?= h(LiveService::L('admin_now')) ?> <b><?= $lm === 'cf' ? h(LiveService::L('mode_cf')) : h(LiveService::L('mode_p2p', [':n' => LiveService::p2pMax()])) ?></b></p>
  <div class="row row-2">
    <div>
      <label class="tgl"><input type="checkbox" name="live_use_cf" value="1" <?= setting('live_use_cf', '1') === '1' ? 'checked' : '' ?>><span class="tgl-track"></span> <?= h(LiveService::L('admin_use_cf')) ?></label>
      <p class="small muted"><?= h(LiveService::L('admin_use_cf_hint')) ?></p>
      <label>Cloudflare Account ID</label><input type="text" name="cf_account_id" value="<?= h(setting('cf_account_id', '')) ?>" autocomplete="off">
      <label>Cloudflare API Token (เว้นว่าง = ไม่เปลี่ยน)</label><input type="password" name="cf_api_token" placeholder="<?= setting('cf_api_token', '') !== '' ? 'ตั้งไว้แล้ว ••••' : '' ?>" autocomplete="new-password">
      <?php if($cfOk): ?><label class="small" style="display:flex;gap:6px;align-items:center;margin-top:.5rem"><input type="checkbox" name="cf_test" value="1"> ทดสอบการเชื่อมต่อตอนบันทึก</label><?php endif; ?>
    </div>
    <div>
      <label><?= h(LiveService::L('admin_p2p_max')) ?></label><input type="number" name="live_p2p_max" min="1" max="200" value="<?= LiveService::p2pMax() ?>">
      <p class="small muted"><?= h(LiveService::L('admin_p2p_max_hint')) ?></p>
      <label><?= h(LiveService::L('admin_uplink')) ?></label><input type="number" name="live_p2p_uplink" min="1000" max="200000" step="500" value="<?= LiveService::uplink() ?>">
      <p class="small muted"><?= h(LiveService::L('admin_uplink_hint')) ?></p>
      <label><?= h(LiveService::L('admin_turn')) ?></label><input type="text" name="live_turn_url" value="<?= h(setting('live_turn_url', '')) ?>" placeholder="turn:turn.example.com:3478">
      <p class="small muted"><?= h(LiveService::L('admin_turn_hint')) ?></p>
      <div class="row row-2">
        <div><label><?= h(LiveService::L('admin_turn_user')) ?></label><input type="text" name="live_turn_user" value="<?= h(setting('live_turn_user', '')) ?>" autocomplete="off"></div>
        <div><label><?= h(LiveService::L('admin_turn_pass')) ?></label><input type="password" name="live_turn_pass" placeholder="<?= setting('live_turn_pass', '') !== '' ? 'ตั้งไว้แล้ว ••••' : '' ?>" autocomplete="new-password"></div>
      </div>
    </div>
  </div>
  <button class="btn btn-primary mt"><i class="fi fi-rr-disk"></i> บันทึก Aleanor Streaming</button>
</div>
</form>
