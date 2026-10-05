-- ============================================================
-- 005 — Aleanor Docs (Write / Grid / Present) — พอร์ตจาก aleanor_ai
--   documents/document_versions/document_files/document_folders → docs_*
--   ใช้ id เป็น INT ทั้งหมด (owner_id → users.id, course_id → courses.id)
--   สถานะ: 1 = ใช้งาน · 2 = อยู่ในถังขยะ · 0 = ลบถาวรแล้ว (แถวถูกลบจริงตอนลบถาวร)
--   trash_of = โฟลเดอร์ที่ลากของชิ้นนี้ลงถังไปด้วย → ถังขยะโชว์แค่รายการหลัก
-- รันซ้ำได้
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS docs_folders (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id    INT UNSIGNED NOT NULL,
  parent_id   INT UNSIGNED NULL,
  name        VARCHAR(150) NOT NULL DEFAULT '',
  status      TINYINT(1) NOT NULL DEFAULT 1,
  trash_of    INT UNSIGNED NULL,
  deleted_at  DATETIME NULL,
  created_at  DATETIME NOT NULL,
  KEY k_dfo_owner (owner_id, status),
  KEY k_dfo_parent (parent_id),
  KEY k_dfo_trash (trash_of)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS docs_documents (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id    INT UNSIGNED NOT NULL,
  course_id   INT UNSIGNED NULL,
  folder_id   INT UNSIGNED NULL,
  type        VARCHAR(20) NOT NULL DEFAULT 'write',      -- write | grid | present
  title       VARCHAR(255) NOT NULL DEFAULT '',
  content     LONGTEXT,                                  -- write: HTML ที่กรองแล้ว | grid/present: JSON
  asset       VARCHAR(255) NULL,                         -- ไฟล์ผลลัพธ์ผูกเอกสาร (สำรองไว้ให้ Aleanor Cover)
  asset_size  BIGINT NOT NULL DEFAULT 0,
  status      TINYINT(1) NOT NULL DEFAULT 1,
  trash_of    INT UNSIGNED NULL,
  deleted_at  DATETIME NULL,
  created_at  DATETIME NOT NULL,
  updated_at  DATETIME NOT NULL,
  KEY k_doc_owner (owner_id, status),
  KEY k_doc_folder (folder_id),
  KEY k_doc_course (course_id),
  KEY k_doc_trash (trash_of)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ประวัติฉบับ — กันงานหายเวลาแก้ทับ (เก็บล่าสุด 20/5/3 ฉบับตามขนาดเอกสาร)
CREATE TABLE IF NOT EXISTS docs_versions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  doc_id      INT UNSIGNED NOT NULL,
  title       VARCHAR(255) NOT NULL DEFAULT '',
  content     LONGTEXT,
  user_id     INT UNSIGNED NULL,
  created_at  DATETIME NOT NULL,
  KEY k_dv_doc (doc_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ไฟล์ที่ผู้ใช้อัปโหลดเอง (เก็บที่ uploads/docs/<user_id>/)
CREATE TABLE IF NOT EXISTS docs_files (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  owner_id    INT UNSIGNED NOT NULL,
  folder_id   INT UNSIGNED NULL,
  name        VARCHAR(255) NOT NULL DEFAULT '',          -- ชื่อไฟล์ที่ผู้ใช้เห็น
  stored_name VARCHAR(255) NOT NULL DEFAULT '',          -- ชื่อไฟล์จริงบนดิสก์
  ext         VARCHAR(10) NOT NULL DEFAULT '',
  size        BIGINT NOT NULL DEFAULT 0,
  doc_id      INT UNSIGNED NULL,                         -- เอกสารที่แปลงจากไฟล์นี้แล้ว (เปิดซ้ำไปที่เดิม)
  status      TINYINT(1) NOT NULL DEFAULT 1,
  trash_of    INT UNSIGNED NULL,
  deleted_at  DATETIME NULL,
  created_at  DATETIME NOT NULL,
  KEY k_df_owner (owner_id, status),
  KEY k_df_folder (folder_id),
  KEY k_df_trash (trash_of)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ค่ากลาง: โควตา 1 GB ต่อคน (0 = ไม่จำกัด), ถังขยะลบเองหลัง 30 วัน (0 = ไม่ลบเอง)
INSERT INTO settings (k, v, updated_at) VALUES
 ('docs_quota_mb', '1024', NOW()),
 ('docs_trash_days', '30', NOW()),
 ('mod_docs', '1', NOW())
ON DUPLICATE KEY UPDATE k = k;
