<?php
// รอบจ่ายเงินผู้สอนรายเดือน — สร้างรอบ / CSV โอนเงิน / แนบสลิป & ทำเครื่องหมายจ่ายแล้ว / ยกเลิก
$TITLE = 'รอบจ่ายเงินผู้สอน';
$period = preg_match('~^\d{4}-\d{2}$~', get('period')) ? get('period') : date('Y-m', strtotime('first day of last month'));
$back = au('payouts', ['period' => $period]);

if(get('export') === 'csv'){
    while(ob_get_level()) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payouts-'.$period.'.csv"');
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['payout_id', 'ผู้สอน', 'ธนาคาร', 'เลขบัญชี', 'ชื่อบัญชี', 'เลขผู้เสียภาษี', 'ยอดรวม', 'หัก ณ ที่จ่าย', 'ยอดโอน', 'สถานะ']);
    foreach(PayoutService::csvRows($period) as $r) fputcsv($out, [$r['id'], $r['name'], $r['bank_name'], $r['bank_account_no'], $r['bank_account_name'], $r['tax_id'], $r['gross'], $r['withholding_tax'], $r['net_amount'], $r['status']]);
    fclose($out); exit;
}
if(is_post()){
    require_csrf();
    try {
        switch(post('action')){
            case 'run':
                $ids = PayoutService::createRun($period);
                flash($ids ? 'สร้างรอบจ่าย '.count($ids).' รายการ' : 'ไม่มีผู้สอนที่ยอดถึงขั้นต่ำในรอบนี้', $ids ? 'success' : 'warning'); break;
            case 'paid':
                $slip = upload_image('slip', 'slips');
                PayoutService::markPaid((int)post('id'), post('transfer_ref'), $slip) ? flash('บันทึกการโอนแล้ว (ส่งอีเมลแจ้งผู้สอน)') : flash('รายการนี้ไม่อยู่ในสถานะรอโอน', 'danger'); break;
            case 'cancel':
                PayoutService::cancel((int)post('id'), post('note')) ? flash('ยกเลิกรอบแล้ว — ยอดกลับเข้ายอดถอนได้ของผู้สอน') : flash('ยกเลิกไม่ได้', 'danger'); break;
        }
    } catch(Throwable $e){ flash($e->getMessage(), 'danger'); }
    redirect($back);
}
$preview = PayoutService::preview($period);
$payouts = db_all("SELECT p.*, COALESCE(ip.display_name, u.name) name FROM payouts p JOIN users u ON u.id = p.instructor_id
                   LEFT JOIN instructor_profiles ip ON ip.user_id = p.instructor_id WHERE p.period = ? ORDER BY p.status = 'pending' DESC, p.id", [$period]);
$sum = ['gross' => 0, 'wht' => 0, 'net' => 0];
foreach($payouts as $p) if($p['status'] !== 'cancelled'){ $sum['gross'] += $p['gross']; $sum['wht'] += $p['withholding_tax']; $sum['net'] += $p['net_amount']; }
?>
<div class="page-header"><div><h1><i class="fi fi-rr-money-check-edit"></i> รอบจ่ายเงินผู้สอน</h1>
  <p>รวมยอด "ถอนได้" ถึงสิ้นเดือน · ขั้นต่ำ <?= baht(PayoutService::minAmount()) ?> · หัก ณ ที่จ่าย <?= h(PayoutService::withholdingRate()) ?>% — <a href="<?= h(au('tax')) ?>">ตั้งค่า</a></p></div>
  <form class="row" method="get"><input type="hidden" name="p" value="payouts"><input type="month" name="period" value="<?= h($period) ?>" style="width:180px"><button class="btn btn-sm">เลือกรอบ</button></form>
</div>
<div class="stat-grid">
  <div class="stat-card"><div><div class="stat-label">รอบ</div><div class="stat-value"><?= h($period) ?></div><div class="stat-sub"><?= count($payouts) ?> รายการ</div></div><div class="stat-icon" style="background:rgba(99,102,241,.1);color:#6366f1"><i class="fi fi-rr-calendar"></i></div></div>
  <div class="stat-card"><div><div class="stat-label">ยอดรวม</div><div class="stat-value"><?= baht($sum['gross']) ?></div></div><div class="stat-icon" style="background:rgba(16,185,129,.1);color:#10b981"><i class="fi fi-rr-sack-dollar"></i></div></div>
  <div class="stat-card"><div><div class="stat-label">หัก ณ ที่จ่าย</div><div class="stat-value"><?= baht($sum['wht']) ?></div></div><div class="stat-icon" style="background:rgba(245,158,11,.1);color:#f59e0b"><i class="fi fi-rr-receipt"></i></div></div>
  <div class="stat-card"><div><div class="stat-label">ยอดโอนจริง</div><div class="stat-value"><?= baht($sum['net']) ?></div></div><div class="stat-icon" style="background:rgba(6,182,212,.1);color:#06b6d4"><i class="fi fi-rr-bank"></i></div></div>
</div>

<div class="card"><div class="card-header"><div class="card-header-title">ผู้สอนที่ยังไม่อยู่ในรอบ <?= h($period) ?></div>
  <?php if(array_filter($preview, function($p){ return $p['eligible']; })): ?>
  <form method="post"><?= csrf_field() ?><button class="btn btn-primary btn-sm" name="action" value="run" onclick="return confirm('สร้างรอบจ่ายสำหรับผู้สอนที่ยอดถึงขั้นต่ำ?')"><i class="fi fi-rr-add"></i> สร้างรอบจ่าย</button></form><?php endif; ?></div>
  <div class="table-wrap"><?php if(!$preview): ?><p class="muted" style="padding:1rem 1.5rem">ไม่มียอดถอนได้ที่ค้างอยู่</p><?php else: ?><table>
    <tr><th>ผู้สอน</th><th>บัญชีรับเงิน</th><th class="num">รายการ</th><th class="num">ยอดถอนได้</th><th></th></tr>
    <?php foreach($preview as $iid => $p): ?><tr><td><a href="<?= h(au('instructor', ['id' => $iid])) ?>"><?= h($p['name']) ?></a></td><td class="small"><?= h(trim($p['bank']) ?: '— ยังไม่ระบุ —') ?></td>
      <td class="num"><?= (int)$p['n'] ?></td><td class="num"><strong><?= baht($p['gross']) ?></strong></td>
      <td><?= $p['eligible'] ? '<span class="badge badge-success">เข้ารอบ</span>' : '<span class="badge badge-secondary">ยังไม่ถึงขั้นต่ำ</span>' ?></td></tr><?php endforeach; ?>
  </table><?php endif; ?></div></div>

<div class="card"><div class="card-header"><div class="card-header-title">รายการในรอบ <?= h($period) ?></div>
  <?php if($payouts): ?><a class="btn btn-outline btn-sm" href="<?= h(au('payouts', ['period' => $period, 'export' => 'csv'])) ?>"><i class="fi fi-rr-download"></i> CSV สำหรับโอนเงิน</a><?php endif; ?></div>
  <div class="table-wrap"><?php if(!$payouts): ?><p class="muted" style="padding:1rem 1.5rem">ยังไม่มีรอบจ่ายในเดือนนี้</p><?php else: ?><table>
    <tr><th>#</th><th>ผู้สอน</th><th>บัญชี</th><th class="num">ยอดรวม</th><th class="num">หัก ณ ที่จ่าย</th><th class="num">ยอดโอน</th><th>สถานะ</th><th></th></tr>
    <?php foreach($payouts as $p): ?>
    <tr><td class="muted"><?= (int)$p['id'] ?></td><td><?= h($p['name']) ?></td><td class="small"><?= h($p['bank_snapshot'] ?: '—') ?></td>
      <td class="num"><?= baht($p['gross']) ?></td><td class="num"><?= baht($p['withholding_tax']) ?> <span class="small muted">(<?= h((float)$p['withholding_rate']) ?>%)</span></td>
      <td class="num"><strong><?= baht($p['net_amount']) ?></strong></td>
      <td><?= $p['status'] === 'paid' ? '<span class="badge badge-success">โอนแล้ว</span><div class="small muted">'.h($p['paid_at']).($p['transfer_ref'] ? ' · '.h($p['transfer_ref']) : '').'</div>'
            : ($p['status'] === 'cancelled' ? '<span class="badge badge-secondary">ยกเลิก</span>' : '<span class="badge badge-warning">รอโอน</span>') ?>
          <?php if($p['slip']): ?><div><a class="small" href="<?= h(asset($p['slip'])) ?>" target="_blank">ดูสลิป</a></div><?php endif; ?></td>
      <td class="right"><?php if($p['status'] === 'pending'): ?>
        <form method="post" enctype="multipart/form-data" class="row" style="justify-content:flex-end"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <input type="text" name="transfer_ref" placeholder="เลขอ้างอิงการโอน" style="width:150px"><input type="file" name="slip" accept="image/*" style="width:190px">
          <button class="btn btn-primary btn-sm" name="action" value="paid">โอนแล้ว</button>
          <button class="btn btn-danger btn-sm" name="action" value="cancel" onclick="return confirm('ยกเลิกรอบจ่ายนี้? ยอดจะกลับไปเป็นยอดถอนได้')">ยกเลิก</button></form><?php endif; ?></td></tr>
    <?php endforeach; ?>
  </table><?php endif; ?></div></div>
