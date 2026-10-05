-- ============================================================
-- aleanor_cloud — สคีมาหลัก (phase 1-2)
-- ตลาดคอร์สออนไลน์: ผู้สอนสมัคร → แอดมินอนุมัติ → สร้างคอร์ส → แอดมินรีวิว → ขาย
-- เงินทั้งหมดเป็น DECIMAL(12,2) — ห้ามใช้ FLOAT กับเงิน
-- ============================================================
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS users (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email           VARCHAR(190) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  name            VARCHAR(150) NOT NULL,
  is_admin        TINYINT(1) NOT NULL DEFAULT 0,
  status          TINYINT(1) NOT NULL DEFAULT 1,            -- 0 = ระงับ
  created_at      DATETIME NOT NULL,
  last_login_at   DATETIME NULL,
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS instructor_profiles (
  id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id           INT UNSIGNED NOT NULL,
  display_name      VARCHAR(150) NOT NULL,
  headline          VARCHAR(255) NOT NULL DEFAULT '',
  bio               TEXT,
  expertise         VARCHAR(255) NOT NULL DEFAULT '',
  sample_url        VARCHAR(500) NOT NULL DEFAULT '',
  bank_name         VARCHAR(100) NOT NULL DEFAULT '',
  bank_account_no   VARCHAR(50)  NOT NULL DEFAULT '',
  bank_account_name VARCHAR(150) NOT NULL DEFAULT '',
  status            ENUM('pending','approved','rejected','suspended') NOT NULL DEFAULT 'pending',
  review_note       VARCHAR(500) NOT NULL DEFAULT '',
  reviewed_by       INT UNSIGNED NULL,
  reviewed_at       DATETIME NULL,
  created_at        DATETIME NOT NULL,
  updated_at        DATETIME NOT NULL,
  UNIQUE KEY uq_ip_user (user_id),
  KEY k_ip_status (status),
  CONSTRAINT fk_ip_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS courses (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  instructor_id  INT UNSIGNED NOT NULL,                     -- users.id ของผู้สอน
  slug           VARCHAR(190) NOT NULL,
  title          VARCHAR(255) NOT NULL,
  subtitle       VARCHAR(255) NOT NULL DEFAULT '',
  description    MEDIUMTEXT,
  cover          VARCHAR(255) NOT NULL DEFAULT '',
  level          ENUM('all','beginner','intermediate','advanced') NOT NULL DEFAULT 'all',
  price          DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  status         ENUM('draft','pending_review','published','rejected','unpublished') NOT NULL DEFAULT 'draft',
  review_note    VARCHAR(500) NOT NULL DEFAULT '',
  submitted_at   DATETIME NULL,
  published_at   DATETIME NULL,
  reviewed_by    INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL,
  updated_at     DATETIME NOT NULL,
  UNIQUE KEY uq_courses_slug (slug),
  KEY k_courses_instructor (instructor_id),
  KEY k_courses_status (status),
  CONSTRAINT fk_courses_instructor FOREIGN KEY (instructor_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS course_sections (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id   INT UNSIGNED NOT NULL,
  title       VARCHAR(255) NOT NULL,
  sort_order  INT NOT NULL DEFAULT 0,
  KEY k_sections_course (course_id, sort_order),
  CONSTRAINT fk_sections_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS lessons (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id     INT UNSIGNED NOT NULL,
  section_id    INT UNSIGNED NOT NULL,
  title         VARCHAR(255) NOT NULL,
  type          ENUM('video','text') NOT NULL DEFAULT 'video',
  video_url     VARCHAR(500) NOT NULL DEFAULT '',           -- YouTube / Vimeo / ลิงก์ .mp4
  content       MEDIUMTEXT,
  duration_min  INT NOT NULL DEFAULT 0,
  is_preview    TINYINT(1) NOT NULL DEFAULT 0,              -- ดูฟรีก่อนซื้อ
  sort_order    INT NOT NULL DEFAULT 0,
  KEY k_lessons_section (section_id, sort_order),
  KEY k_lessons_course (course_id),
  CONSTRAINT fk_lessons_course  FOREIGN KEY (course_id)  REFERENCES courses(id) ON DELETE CASCADE,
  CONSTRAINT fk_lessons_section FOREIGN KEY (section_id) REFERENCES course_sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS coupons (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(50) NOT NULL,
  owner_type     ENUM('platform','instructor') NOT NULL,     -- ใครแบกส่วนลด
  instructor_id  INT UNSIGNED NULL,                           -- owner_type=instructor
  course_id      INT UNSIGNED NULL,                           -- NULL = ทุกคอร์ส (ของผู้สอนนั้น ถ้าเป็นคูปองผู้สอน)
  type           ENUM('percent','fixed') NOT NULL DEFAULT 'percent',
  value          DECIMAL(12,2) NOT NULL,
  max_uses       INT NOT NULL DEFAULT 0,                      -- 0 = ไม่จำกัด
  used_count     INT NOT NULL DEFAULT 0,
  starts_at      DATETIME NULL,
  ends_at        DATETIME NULL,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_by     INT UNSIGNED NOT NULL,
  created_at     DATETIME NOT NULL,
  UNIQUE KEY uq_coupons_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS orders (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no      VARCHAR(32) NOT NULL,
  user_id       INT UNSIGNED NOT NULL,
  status        ENUM('pending','paid','failed','cancelled','refunded','partially_refunded') NOT NULL DEFAULT 'pending',
  subtotal      DECIMAL(12,2) NOT NULL DEFAULT 0.00,          -- รวมราคาตั้ง
  discount      DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  total         DECIMAL(12,2) NOT NULL DEFAULT 0.00,          -- ยอดที่ลูกค้าจ่าย
  coupon_id     INT UNSIGNED NULL,
  gateway       VARCHAR(20) NOT NULL DEFAULT '',
  gateway_ref   VARCHAR(100) NULL,                            -- charge / session id — ใช้กัน webhook ซ้ำ
  gateway_fee   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  paid_at       DATETIME NULL,
  created_at    DATETIME NOT NULL,
  updated_at    DATETIME NOT NULL,
  UNIQUE KEY uq_orders_no (order_no),
  UNIQUE KEY uq_orders_gateway_ref (gateway_ref),
  KEY k_orders_user (user_id),
  KEY k_orders_status_paid (status, paid_at),
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- snapshot ส่วนแบ่ง ณ วันที่ขาย — เปลี่ยนอัตราภายหลังไม่กระทบยอดเก่า
CREATE TABLE IF NOT EXISTS order_items (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id           INT UNSIGNED NOT NULL,
  course_id          INT UNSIGNED NOT NULL,
  instructor_id      INT UNSIGNED NOT NULL,
  list_price         DECIMAL(12,2) NOT NULL,
  discount           DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  coupon_owner       ENUM('none','platform','instructor') NOT NULL DEFAULT 'none',
  paid_amount        DECIMAL(12,2) NOT NULL,                  -- list_price - discount
  gateway_fee        DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  net_amount         DECIMAL(12,2) NOT NULL DEFAULT 0.00,     -- ฐานที่ใช้แบ่ง (หลังหักค่าธรรมเนียม)
  platform_rate      DECIMAL(5,2)  NULL,                      -- เติมตอนยืนยันการจ่าย
  rule_id            INT UNSIGNED NULL,
  platform_amount    DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  instructor_amount  DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  refunded_at        DATETIME NULL,
  created_at         DATETIME NOT NULL,
  KEY k_oi_order (order_id),
  KEY k_oi_instructor (instructor_id),
  KEY k_oi_course (course_id),
  CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id),
  CONSTRAINT fk_oi_course FOREIGN KEY (course_id) REFERENCES courses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS enrollments (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NOT NULL,
  course_id      INT UNSIGNED NOT NULL,
  order_item_id  INT UNSIGNED NULL,
  source         ENUM('purchase','free','admin') NOT NULL DEFAULT 'purchase',
  status         ENUM('active','revoked') NOT NULL DEFAULT 'active',
  created_at     DATETIME NOT NULL,
  UNIQUE KEY uq_enroll (user_id, course_id),
  KEY k_enroll_course (course_id),
  CONSTRAINT fk_enroll_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_enroll_course FOREIGN KEY (course_id) REFERENCES courses(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS lesson_progress (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       INT UNSIGNED NOT NULL,
  course_id     INT UNSIGNED NOT NULL,
  lesson_id     INT UNSIGNED NOT NULL,
  completed_at  DATETIME NULL,
  last_seen_at  DATETIME NOT NULL,
  UNIQUE KEY uq_progress (user_id, lesson_id),
  KEY k_progress_course (user_id, course_id),
  CONSTRAINT fk_progress_lesson FOREIGN KEY (lesson_id) REFERENCES lessons(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- กฎส่วนแบ่ง 3 ระดับ: course > instructor > global (ระดับที่เจาะจงกว่าชนะ)
-- ไม่แก้กฎเดิม — เปลี่ยนอัตรา = ปิดกฎเก่า (ends_at) แล้วสร้างกฎใหม่ เพื่อเก็บประวัติ
CREATE TABLE IF NOT EXISTS revenue_share_rules (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope           ENUM('global','instructor','course') NOT NULL,
  instructor_id   INT UNSIGNED NULL,
  course_id       INT UNSIGNED NULL,
  platform_rate   DECIMAL(5,2) NOT NULL,                      -- % ที่แพลตฟอร์มได้
  starts_at       DATETIME NULL,
  ends_at         DATETIME NULL,
  is_active       TINYINT(1) NOT NULL DEFAULT 1,
  note            VARCHAR(255) NOT NULL DEFAULT '',
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL,
  deactivated_by  INT UNSIGNED NULL,
  deactivated_at  DATETIME NULL,
  KEY k_rsr_lookup (scope, is_active, course_id, instructor_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS refunds (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id       INT UNSIGNED NOT NULL,
  order_item_id  INT UNSIGNED NOT NULL,
  amount         DECIMAL(12,2) NOT NULL,                      -- คืนลูกค้า
  instructor_reversal DECIMAL(12,2) NOT NULL DEFAULT 0.00,    -- หักคืนจากผู้สอน
  reason         VARCHAR(500) NOT NULL DEFAULT '',
  created_by     INT UNSIGNED NOT NULL,
  created_at     DATETIME NOT NULL,
  UNIQUE KEY uq_refund_item (order_item_id),
  KEY k_refund_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- บัญชีรายได้ผู้สอน (append-only) ยอดคงเหลือ = SUM(amount)
-- status: held = อยู่ในช่วงพักเงิน, available = ถอนได้ (cron ปล่อยเมื่อถึง available_at)
CREATE TABLE IF NOT EXISTS instructor_ledger (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  instructor_id  INT UNSIGNED NOT NULL,
  type           ENUM('sale','refund','payout','adjustment') NOT NULL,
  amount         DECIMAL(12,2) NOT NULL,                      -- + เข้า / - ออก
  status         ENUM('held','available') NOT NULL DEFAULT 'held',
  available_at   DATETIME NOT NULL,
  order_item_id  INT UNSIGNED NULL,
  refund_id      INT UNSIGNED NULL,
  payout_id      INT UNSIGNED NULL,
  note           VARCHAR(255) NOT NULL DEFAULT '',
  created_by     INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL,
  UNIQUE KEY uq_ledger_sale (type, order_item_id),           -- ขายรายการเดียวลงบัญชีครั้งเดียว
  KEY k_ledger_instructor (instructor_id, status),
  KEY k_ledger_release (status, available_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- (phase 3) รอบจ่ายเงินผู้สอน — สร้างตารางไว้ก่อน
CREATE TABLE IF NOT EXISTS payouts (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  instructor_id  INT UNSIGNED NOT NULL,
  period         CHAR(7) NOT NULL,                            -- YYYY-MM
  amount         DECIMAL(12,2) NOT NULL,
  status         ENUM('pending','paid','cancelled') NOT NULL DEFAULT 'pending',
  slip           VARCHAR(255) NOT NULL DEFAULT '',
  paid_at        DATETIME NULL,
  paid_by        INT UNSIGNED NULL,
  created_at     DATETIME NOT NULL,
  UNIQUE KEY uq_payout_period (instructor_id, period)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS payout_items (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payout_id   INT UNSIGNED NOT NULL,
  ledger_id   INT UNSIGNED NOT NULL,
  UNIQUE KEY uq_payout_ledger (ledger_id),
  KEY k_pi_payout (payout_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS reviews (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  course_id   INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  rating      TINYINT NOT NULL,
  body        TEXT,
  created_at  DATETIME NOT NULL,
  UNIQUE KEY uq_review (course_id, user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS settings (
  k           VARCHAR(64) NOT NULL PRIMARY KEY,
  v           TEXT,
  updated_at  DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NULL,
  action      VARCHAR(60) NOT NULL,
  entity      VARCHAR(40) NOT NULL,
  entity_id   INT UNSIGNED NULL,
  before_json TEXT,
  after_json  TEXT,
  ip          VARCHAR(45) NOT NULL DEFAULT '',
  created_at  DATETIME NOT NULL,
  KEY k_audit_entity (entity, entity_id),
  KEY k_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- บันทึกเหตุการณ์ชำระเงิน (แนวเดียวกับ payment_events ของ aleanor_ai)
CREATE TABLE IF NOT EXISTS payment_events (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id    INT UNSIGNED NULL,
  gateway     VARCHAR(20) NOT NULL DEFAULT '',
  event       VARCHAR(60) NOT NULL,
  ref         VARCHAR(100) NOT NULL DEFAULT '',
  payload     TEXT,
  created_at  DATETIME NOT NULL,
  KEY k_pe_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET FOREIGN_KEY_CHECKS = 1;
