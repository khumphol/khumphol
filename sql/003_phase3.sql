-- ============================================================
-- 003 — phase 3-4: รอบจ่ายเงิน, คืนเงินบางส่วน/ผ่าน gateway, ตะกร้า, อีเมล, ใบประกาศ, สิทธิ์ผู้สอน, ภาษี
-- รันซ้ำได้ (install.sh ใช้กับฐานใหม่) — ฐานเดิมให้รันไฟล์นี้ครั้งเดียว
-- ============================================================
SET NAMES utf8mb4;

-- ── คืนเงินบางส่วน: refunds หลายครั้งต่อรายการ ──
ALTER TABLE refunds DROP INDEX uq_refund_item, ADD KEY k_refund_item (order_item_id),
  ADD COLUMN gateway_refund_ref VARCHAR(100) NULL AFTER reason,
  ADD COLUMN via_gateway TINYINT(1) NOT NULL DEFAULT 0 AFTER gateway_refund_ref;
ALTER TABLE order_items ADD COLUMN refunded_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER instructor_amount,
  ADD COLUMN instructor_reversed DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER refunded_amount;
-- ledger: ขายลงได้ครั้งเดียวต่อรายการ แต่คืนเงินได้หลายแถว → unique เฉพาะแถว sale
ALTER TABLE instructor_ledger DROP INDEX uq_ledger_sale,
  ADD COLUMN sale_item_id INT UNSIGNED AS (IF(type = 'sale', order_item_id, NULL)) STORED,
  ADD UNIQUE KEY uq_ledger_sale (sale_item_id),
  ADD KEY k_ledger_item (order_item_id);

-- ── รอบจ่ายเงินผู้สอน ──
ALTER TABLE payouts
  CHANGE amount gross DECIMAL(12,2) NOT NULL,
  ADD COLUMN withholding_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER gross,
  ADD COLUMN withholding_tax DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER withholding_rate,
  ADD COLUMN net_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER withholding_tax,
  ADD COLUMN bank_snapshot VARCHAR(400) NOT NULL DEFAULT '' AFTER net_amount,
  ADD COLUMN transfer_ref VARCHAR(100) NOT NULL DEFAULT '' AFTER slip,
  ADD COLUMN note VARCHAR(255) NOT NULL DEFAULT '' AFTER transfer_ref,
  ADD COLUMN cancelled_at DATETIME NULL AFTER paid_by,
  ADD KEY k_payout_status (status, period);
-- รอบที่ยกเลิกแล้วสร้างใหม่ในเดือนเดียวกันได้ → unique เฉพาะรอบที่ยังไม่ยกเลิก
ALTER TABLE payouts DROP INDEX uq_payout_period,
  ADD COLUMN active_period CHAR(7) AS (IF(status = 'cancelled', NULL, period)) STORED,
  ADD UNIQUE KEY uq_payout_active (instructor_id, active_period);

ALTER TABLE instructor_profiles
  ADD COLUMN tax_id VARCHAR(20) NOT NULL DEFAULT '' AFTER bank_account_name,
  ADD COLUMN tax_name VARCHAR(200) NOT NULL DEFAULT '' AFTER tax_id,
  ADD COLUMN tax_address VARCHAR(500) NOT NULL DEFAULT '' AFTER tax_name;

-- ── ตะกร้า ──
CREATE TABLE IF NOT EXISTS cart_items (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  course_id   INT UNSIGNED NOT NULL,
  created_at  DATETIME NOT NULL,
  UNIQUE KEY uq_cart (user_id, course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── อีเมล (คิว — cron เป็นคนส่ง ไม่ให้ SMTP ช้าถ่วงหน้าเว็บ) ──
CREATE TABLE IF NOT EXISTS mail_queue (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  to_email     VARCHAR(190) NOT NULL,
  subject      VARCHAR(255) NOT NULL,
  body_html    MEDIUMTEXT,
  template     VARCHAR(40) NOT NULL DEFAULT '',
  status       ENUM('queued','sent','failed','skipped') NOT NULL DEFAULT 'queued',
  attempts     TINYINT NOT NULL DEFAULT 0,
  last_error   VARCHAR(255) NOT NULL DEFAULT '',
  created_at   DATETIME NOT NULL,
  sent_at      DATETIME NULL,
  KEY k_mail_status (status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── ใบประกาศ ──
CREATE TABLE IF NOT EXISTS certificates (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  serial      VARCHAR(20) NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  course_id   INT UNSIGNED NOT NULL,
  name_on_cert VARCHAR(150) NOT NULL,
  course_title VARCHAR(255) NOT NULL,
  instructor_name VARCHAR(150) NOT NULL,
  issued_at   DATETIME NOT NULL,
  revoked_at  DATETIME NULL,
  UNIQUE KEY uq_cert_serial (serial),
  UNIQUE KEY uq_cert_user_course (user_id, course_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── สิทธิ์การใช้งานของผู้สอน: instructor_id NULL = ค่าเริ่มต้นของผู้สอนทุกคน ──
CREATE TABLE IF NOT EXISTS instructor_permissions (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  instructor_id  INT UNSIGNED NULL,
  feature        VARCHAR(40) NOT NULL,
  allowed        TINYINT(1) NOT NULL,
  updated_by     INT UNSIGNED NULL,
  updated_at     DATETIME NOT NULL,
  scope_id       INT UNSIGNED AS (IFNULL(instructor_id, 0)) STORED,
  UNIQUE KEY uq_perm (scope_id, feature)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ── ใบเสร็จ/ภาษี ──
ALTER TABLE orders ADD COLUMN receipt_no VARCHAR(30) NULL AFTER order_no,
  ADD COLUMN vat_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00 AFTER gateway_fee,
  ADD COLUMN vat_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER vat_rate,
  ADD COLUMN bill_name VARCHAR(200) NOT NULL DEFAULT '' AFTER vat_amount,
  ADD COLUMN bill_tax_id VARCHAR(20) NOT NULL DEFAULT '' AFTER bill_name,
  ADD COLUMN bill_address VARCHAR(500) NOT NULL DEFAULT '' AFTER bill_tax_id,
  ADD UNIQUE KEY uq_orders_receipt (receipt_no);

INSERT INTO settings (k, v, updated_at) VALUES
 ('withholding_tax_rate', '3', NOW()),        -- % หัก ณ ที่จ่ายตอนโอนให้ผู้สอน (บุคคลธรรมดา 3%)
 ('vat_enabled', '0', NOW()), ('vat_rate', '7', NOW()),
 ('seller_name', '', NOW()), ('seller_tax_id', '', NOW()), ('seller_address', '', NOW()),
 ('mail_enabled', '0', NOW()), ('mail_host', '', NOW()), ('mail_port', '587', NOW()), ('mail_user', '', NOW()),
 ('mail_pass', '', NOW()), ('mail_encryption', 'tls', NOW()), ('mail_from', '', NOW()), ('mail_from_name', 'Aleanor Cloud', NOW()),
 ('cert_enabled', '1', NOW()), ('cert_min_progress', '100', NOW())
ON DUPLICATE KEY UPDATE k = k;
