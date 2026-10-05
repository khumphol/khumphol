<?php
// hub "ผู้ดูแลระบบ" — การ์ดเมนูแบบ aleanor_ai/dashboard/administrator/admin-users.php
$TITLE = 'ผู้ดูแลระบบ';
$sections = [
    'ผู้ใช้งานและสิทธิ์' => [
        ['permissions', 'สิทธิ์ของผู้สอน', 'กำหนดว่าผู้สอนใช้ฟังก์ชันใดได้บ้าง (ค่าเริ่มต้น + รายคน)', 'fi-rr-shield-check', '#6366f1'],
        ['members', 'สมาชิก', 'ผู้ใช้ทั้งหมด ระงับบัญชี ตั้งเป็นแอดมิน', 'fi-rr-users', '#0ea5e9'],
        ['settings', 'ตั้งค่าเว็บไซต์', 'ชื่อเว็บ การพักเงิน ช่องทางชำระเงิน', 'fi-rr-settings', '#64748b'],
    ],
    'การเงิน' => [
        ['revenue', 'ส่วนแบ่งรายได้', 'อัตรา 3 ระดับ + ประวัติ', 'fi-rr-chart-pie-alt', '#8b5cf6'],
        ['payouts', 'รอบจ่ายเงินผู้สอน', 'สร้างรอบรายเดือน CSV โอนเงิน แนบสลิป', 'fi-rr-money-check-edit', '#10b981'],
        ['coupons', 'คูปองแพลตฟอร์ม', 'ส่วนลดที่แพลตฟอร์มออกเอง', 'fi-rr-ticket', '#f59e0b'],
        ['tax', 'ภาษี & ใบเสร็จ', 'VAT, ข้อมูลผู้ขาย, หัก ณ ที่จ่าย', 'fi-rr-receipt', '#ef4444'],
    ],
    'โมดูล & การเชื่อมต่อ' => [
        ['modules', 'โมดูล', 'เปิด/ปิด Indy · Docs · Playground · VC · ใบประกาศ', 'fi-rr-apps', '#06b6d4'],
        ['integrations', 'Playground & VC', 'URL + รหัสลับ สำหรับ SSO / JWT', 'fi-rr-plug-connection', '#14b8a6'],
        ['mail', 'อีเมล', 'ตั้งค่า SMTP + กล่องจดหมายออก', 'fi-rr-envelope', '#ec4899'],
    ],
    'ระบบ' => [
        ['audit', 'Audit log', 'ประวัติการเปลี่ยนแปลงทั้งหมด', 'fi-rr-time-past', '#475569'],
    ],
];
$info = [
    'PHP' => PHP_VERSION, 'MySQL' => db_val("SELECT VERSION()"), 'โหมด' => APP_ENV,
    'ช่องทางชำระเงิน' => gwProvider() ?: 'ปิด', 'อีเมลค้างส่ง' => (int)db_val("SELECT COUNT(*) FROM mail_queue WHERE status = 'queued'"),
];
?>
<div class="page-header"><div><h1><i class="fi fi-rr-settings"></i> ผู้ดูแลระบบ</h1><p>จัดการผู้ใช้ สิทธิ์ การเงิน และโมดูลของแพลตฟอร์ม</p></div></div>
<?php foreach($sections as $title => $cards): ?>
  <div class="menu-section-title"><?= h($title) ?></div>
  <div class="menu-grid">
    <?php foreach($cards as $c): ?>
      <a class="menu-card" href="<?= h(au($c[0])) ?>">
        <div class="menu-card-icon" style="background:<?= h($c[4]) ?>1a;color:<?= h($c[4]) ?>"><i class="fi <?= h($c[3]) ?>"></i></div>
        <div class="menu-card-text"><div class="menu-card-name"><?= h($c[1]) ?></div><div class="menu-card-desc"><?= h($c[2]) ?></div></div>
      </a>
    <?php endforeach; ?>
  </div>
<?php endforeach; ?>
<div class="menu-section-title">สถานะระบบ</div>
<div class="card"><div class="table-wrap"><table><?php foreach($info as $k => $v): ?><tr><td class="muted" style="width:220px"><?= h($k) ?></td><td><strong><?= h($v) ?></strong></td></tr><?php endforeach; ?></table></div></div>
