<?php
$MENU = 'instructors';
$uid = (int)get('id');
$p = db_one("SELECT ip.*, u.email, u.name, u.created_at AS joined FROM instructor_profiles ip JOIN users u ON u.id = ip.user_id WHERE ip.user_id = ?", [$uid]);
if(!$p){ echo '<div class="card">ไม่พบผู้สอน</div>'; return; }
$TITLE = $p['display_name'];
$back = au('instructor', ['id' => $uid]);

require __DIR__.'/_rate_post.php';
rate_handle_post('instructor', $uid, $back);
if(is_post()){
    require_csrf();
    $a = post('action');
    $map = ['approve' => 'approved', 'reject' => 'rejected', 'suspend' => 'suspended', 'reinstate' => 'approved'];
    if(isset($map[$a])){
        if($a === 'reject' && post('note') === ''){ flash('กรุณาระบุเหตุผล', 'danger'); redirect($back); }
        db_write("UPDATE instructor_profiles SET status = ?, review_note = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ? WHERE user_id = ?",
            [$map[$a], mb_substr(post('note'), 0, 500), current_user_id(), now(), now(), $uid]);
        audit('instructor_'.$a, 'instructor', $uid, ['status' => $p['status']], ['status' => $map[$a], 'note' => post('note')]);
        MailService::instructorStatus($uid, $map[$a], post('note'));
        flash('อัปเดตสถานะผู้สอนแล้ว');
    }
    if($a === 'adjust'){
        $amt = round((float)post('amount'), 2);
        if($amt != 0 && post('note') !== ''){ LedgerService::adjust($uid, $amt, post('note')); flash('ปรับยอดแล้ว'); }
        else flash('ระบุจำนวนและเหตุผล', 'danger');
    }
    redirect($back);
}
$bal = LedgerService::balances($uid);
$courses = db_all("SELECT * FROM courses WHERE instructor_id = ? ORDER BY updated_at DESC", [$uid]);
$rateScope = 'instructor'; $rateTarget = $uid; $rateEffective = RevenueShareService::rateFor(0, $uid);
?>
<a class="small" href="<?= h(au('instructors')) ?>">← ผู้สอนทั้งหมด</a>
<h1><?= h($p['display_name']) ?> <?= status_badge($p['status']) ?></h1>
<div class="grid g2" style="align-items:start">
  <div class="card">
    <p><strong><?= h($p['name']) ?></strong> · <?= h($p['email']) ?><br><span class="muted small">สมัครสมาชิก <?= h($p['joined']) ?> · ยื่นใบสมัคร <?= h($p['created_at']) ?></span></p>
    <p><strong><?= h($p['headline']) ?></strong></p><p class="small">ความเชี่ยวชาญ: <?= h($p['expertise']) ?></p>
    <div class="prose small"><?= h($p['bio']) ?></div>
    <?php if($p['sample_url']): ?><p class="small">ผลงาน: <a href="<?= h($p['sample_url']) ?>" target="_blank" rel="noopener noreferrer"><?= h($p['sample_url']) ?></a></p><?php endif; ?>
    <p class="small muted">บัญชีรับเงิน: <?= h(trim($p['bank_name'].' '.$p['bank_account_no'].' '.$p['bank_account_name']) ?: '—') ?></p>
    <?php if($p['review_note']): ?><p class="small">หมายเหตุการตรวจ: <?= h($p['review_note']) ?></p><?php endif; ?>
    <form method="post" class="mt"><?= csrf_field() ?>
      <input type="text" name="note" placeholder="หมายเหตุ / เหตุผล (จำเป็นเมื่อไม่อนุมัติ)">
      <div class="row mt">
        <?php if($p['status'] === 'pending' || $p['status'] === 'rejected'): ?><button class="btn btn-primary" name="action" value="approve">อนุมัติ</button><?php endif; ?>
        <?php if($p['status'] === 'pending'): ?><button class="btn btn-danger" name="action" value="reject">ไม่อนุมัติ</button><?php endif; ?>
        <?php if($p['status'] === 'approved'): ?><button class="btn btn-danger" name="action" value="suspend" onclick="return confirm('ระงับผู้สอนนี้?')">ระงับ</button><?php endif; ?>
        <?php if($p['status'] === 'suspended'): ?><button class="btn" name="action" value="reinstate">คืนสถานะ</button><?php endif; ?>
      </div>
    </form>
  </div>
  <div class="card">
    <h2>ยอดเงิน</h2>
    <div class="grid g2"><div class="stat"><div class="label">พักเงิน</div><div class="value"><?= baht($bal['held']) ?></div></div>
      <div class="stat"><div class="label">ถอนได้</div><div class="value"><?= baht($bal['available']) ?></div></div></div>
    <form method="post" class="mt"><?= csrf_field() ?><input type="hidden" name="action" value="adjust">
      <label>ปรับยอด (± บาท)</label><div class="row"><input type="number" step="0.01" name="amount" style="max-width:140px"><input type="text" name="note" placeholder="เหตุผล" style="flex:1"><button class="btn">ปรับ</button></div></form>
    <h2 class="mt2">คอร์ส</h2>
    <?php foreach($courses as $c): ?><div class="row between small" style="padding:.3rem 0"><a href="<?= h(au('course', ['id' => $c['id']])) ?>"><?= h($c['title']) ?></a><?= status_badge($c['status']) ?></div><?php endforeach; ?>
    <?php if(!$courses): ?><p class="muted small">ยังไม่มีคอร์ส</p><?php endif; ?>
  </div>
</div>
<p class="small"><a href="<?= h(au('permissions')) ?>"><i class="fi fi-rr-shield-check"></i> ตั้งสิทธิ์การใช้งานของผู้สอนคนนี้</a></p>
<div class="mt"><?php require __DIR__.'/_rate.php'; ?></div>
