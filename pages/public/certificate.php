<?php
// ใบประกาศ + หน้าตรวจสอบสาธารณะ (?serial=) — ไม่ต้องล็อกอิน
$cert = get('serial') !== '' ? CertificateService::bySerial(get('serial')) : null;
$TITLE = 'ใบประกาศ';
if(get('serial') === ''){ ?>
  <div class="auth card"><h1>ตรวจสอบใบประกาศ</h1><form method="get"><input type="hidden" name="p" value="certificate">
    <label>เลขที่ใบประกาศ</label><input type="text" name="serial" placeholder="AC-XXXXXXXX" required><button class="btn btn-primary btn-block mt">ตรวจสอบ</button></form></div>
<?php return; }
if(!$cert){ echo '<div class="auth card"><h1>ไม่พบใบประกาศ</h1><p class="muted">เลขที่ '.h(get('serial')).' ไม่มีในระบบ</p></div>'; return; }
$valid = !$cert['revoked_at'];
?>
<style>
.cert{max-width:900px;margin:0 auto;aspect-ratio:1.414;background:#fff;color:#1e293b;border-radius:18px;padding:5% 7%;position:relative;box-shadow:0 20px 50px -25px rgba(0,0,0,.35);
  display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;border:10px solid #eef2ff;outline:2px solid #6366f1;outline-offset:-22px}
.cert h1{font-size:clamp(1.4rem,3.6vw,2.6rem);margin:.2em 0;color:#4f46e5;letter-spacing:.02em}
.cert .name{font-size:clamp(1.3rem,3.4vw,2.4rem);font-weight:700;margin:.4em 0;border-bottom:2px solid #c7d2fe;padding:0 1em .2em}
.cert .muted{color:#64748b}
@media print{ .topbar,.footer,.no-print{display:none !important} body{background:#fff} .cert{box-shadow:none} }
</style>
<?php if(!$valid): ?><div class="alert alert-danger">ใบประกาศนี้ถูกเพิกถอนแล้ว</div><?php endif; ?>
<div class="cert">
  <div class="muted">ใบประกาศนียบัตร · <?= h(setting('site_name', 'Aleanor Cloud')) ?></div>
  <h1>Certificate of Completion</h1>
  <div class="muted">มอบให้เพื่อแสดงว่า</div>
  <div class="name"><?= h($cert['name_on_cert']) ?></div>
  <div class="muted">ได้เรียนจบคอร์ส</div>
  <div style="font-size:1.35rem;font-weight:700;margin:.3em 0"><?= h($cert['course_title']) ?></div>
  <div class="muted">สอนโดย <?= h($cert['instructor_name']) ?> · ออกให้เมื่อ <?= h(date('d/m/Y', strtotime($cert['issued_at']))) ?></div>
  <div class="muted small" style="position:absolute;bottom:6%">เลขที่ <?= h($cert['serial']) ?> · ตรวจสอบได้ที่ <?= h(site_url().'index.php?p=certificate&serial='.$cert['serial']) ?></div>
</div>
<p class="no-print" style="text-align:center;margin-top:1rem"><button class="btn" onclick="window.print()">พิมพ์ / บันทึก PDF</button></p>
