<?php
$TITLE = 'อีเมล';
$keys = ['mail_enabled', 'mail_host', 'mail_port', 'mail_encryption', 'mail_user', 'mail_pass', 'mail_from', 'mail_from_name'];
if(is_post()){
    require_csrf();
    if(post('action') === 'save'){
        $ch = [];
        foreach($keys as $k){
            $v = $k === 'mail_enabled' ? (post($k) ? '1' : '0') : post($k);
            if($k === 'mail_pass' && $v === '') continue;
            if(setting($k, '') !== $v){ setting_set($k, $v); $ch[$k] = $k === 'mail_pass' ? '***' : $v; }
        }
        if($ch) audit('mail_settings', 'settings', null, null, $ch);
        flash('บันทึกแล้ว');
    }
    if(post('action') === 'test'){
        $r = mailEnabled() ? mailSmtp(mailConfig(), current_user()['email'], 'ทดสอบอีเมลจาก '.setting('site_name', 'Aleanor Cloud'), MailService::wrap('ทดสอบ', '<p>ระบบอีเมลทำงานปกติ</p>')) : ['ok' => false, 'error' => 'ยังไม่ได้เปิด/ตั้งค่าอีเมล'];
        !empty($r['ok']) ? flash('ส่งอีเมลทดสอบไปที่ '.current_user()['email'].' แล้ว') : flash('ส่งไม่สำเร็จ: '.$r['error'], 'danger');
    }
    if(post('action') === 'flush'){ $r = MailService::flush(100); flash("ส่ง {$r['sent']} · ไม่สำเร็จ {$r['failed']}"); }
    if(post('action') === 'retry'){ db_write("UPDATE mail_queue SET status = 'queued', attempts = 0 WHERE status = 'failed'"); flash('นำกลับเข้าคิวแล้ว'); }
    redirect(au('mail'));
}
$queue = db_all("SELECT id, to_email, subject, template, status, attempts, last_error, created_at, sent_at FROM mail_queue ORDER BY id DESC LIMIT 100");
$cnt = db_one("SELECT SUM(status='queued') q, SUM(status='sent') s, SUM(status='failed') f FROM mail_queue");
?>
<div class="page-header"><div><h1><i class="fi fi-rr-envelope"></i> อีเมล</h1><p>อีเมลเข้าคิวเสมอ แล้ว cron <code>cron/send-mail.php</code> เป็นผู้ส่ง — แจ้งผู้ซื้อ ผู้สอน (ยอดขาย/อนุมัติ/รอบจ่าย) และการคืนเงิน</p></div></div>
<div class="row row-2">
<form method="post" class="card"><?= csrf_field() ?><input type="hidden" name="action" value="save">
  <h2>SMTP</h2>
  <label class="tgl"><input type="checkbox" name="mail_enabled" value="1" <?= setting('mail_enabled', '0') === '1' ? 'checked' : '' ?>><span class="tgl-track"></span> เปิดส่งอีเมล</label>
  <div class="grid g2">
    <div><label>Host</label><input type="text" name="mail_host" value="<?= h(setting('mail_host', '')) ?>" placeholder="smtp.gmail.com"></div>
    <div><label>Port</label><input type="number" name="mail_port" value="<?= h(setting('mail_port', '587')) ?>"></div>
    <div><label>Encryption</label><select name="mail_encryption"><?php foreach(['tls', 'ssl', 'none'] as $e): ?><option <?= setting('mail_encryption', 'tls') === $e ? 'selected' : '' ?>><?= $e ?></option><?php endforeach; ?></select></div>
    <div><label>Username</label><input type="text" name="mail_user" value="<?= h(setting('mail_user', '')) ?>" autocomplete="off"></div>
    <div><label>Password (เว้นว่าง = ไม่เปลี่ยน)</label><input type="password" name="mail_pass" placeholder="<?= setting('mail_pass', '') !== '' ? 'ตั้งไว้แล้ว ••••' : '' ?>" autocomplete="new-password"></div>
    <div><label>From</label><input type="email" name="mail_from" value="<?= h(setting('mail_from', '')) ?>"></div>
    <div><label>ชื่อผู้ส่ง</label><input type="text" name="mail_from_name" value="<?= h(setting('mail_from_name', '')) ?>"></div>
  </div>
  <button class="btn btn-primary mt">บันทึก</button>
</form>
<div class="card"><h2>คิว</h2>
  <div class="grid g3"><div class="stat"><div class="label">รอส่ง</div><div class="value"><?= (int)$cnt['q'] ?></div></div>
    <div class="stat"><div class="label">ส่งแล้ว</div><div class="value"><?= (int)$cnt['s'] ?></div></div><div class="stat"><div class="label">ล้มเหลว</div><div class="value"><?= (int)$cnt['f'] ?></div></div></div>
  <form method="post" class="row mt"><?= csrf_field() ?><button class="btn btn-sm" name="action" value="test">ส่งอีเมลทดสอบถึงฉัน</button>
    <button class="btn btn-sm" name="action" value="flush">ส่งคิวตอนนี้</button><button class="btn btn-sm" name="action" value="retry">ลองส่งที่ล้มเหลวอีกครั้ง</button></form>
</div></div>
<div class="card"><div class="table-wrap"><table><thead><tr><th>เวลา</th><th>ถึง</th><th>เรื่อง</th><th>ชนิด</th><th>สถานะ</th></tr></thead><tbody>
  <?php foreach($queue as $m): ?><tr><td class="small nowrap"><?= h($m['created_at']) ?></td><td class="small"><?= h($m['to_email']) ?></td><td><?= h($m['subject']) ?></td><td class="small muted"><?= h($m['template']) ?></td>
    <td><?= ['queued' => '<span class="badge badge-warning">รอส่ง</span>', 'sent' => '<span class="badge badge-success">ส่งแล้ว</span>', 'failed' => '<span class="badge badge-danger">ล้มเหลว</span>', 'skipped' => '<span class="badge badge-secondary">ข้าม</span>'][$m['status']] ?>
      <?php if($m['last_error']): ?><div class="small muted"><?= h($m['last_error']) ?></div><?php endif; ?></td></tr><?php endforeach; ?>
  <?php if(!$queue): ?><tr><td colspan="5" class="muted">ยังไม่มีอีเมล</td></tr><?php endif; ?>
</tbody></table></div></div>
