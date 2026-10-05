-- ============================================================
-- 006 — Aleanor Indy: วิดีโอแบบเลือกเส้นทาง (โปรเจกต์ → ฉาก → ทางเลือก)
-- พอร์ตจาก aleanor_ai (indy_projects / indy_scenes / indy_branches ที่อ้างกันด้วย *_key)
-- ที่นี่ใช้ id ตัวเลข + foreign key + ON DELETE CASCADE แทนการลบตามทีละตาราง
-- รันซ้ำได้ (CREATE TABLE IF NOT EXISTS)
--   * entry_scene_id ไม่ผูก FK (อ้างวนกับ indy_scenes) — IndyService::deleteScene() ล้างให้เอง
--     และ playData() ถอยไปใช้ฉากแรกถ้าค่าไม่ถูกต้อง
--   * บทเรียนชี้มาที่โปรเจกต์ด้วย lessons.type='indy' + lessons.ref_id (ดู 004_modules.sql)
-- ============================================================
SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS indy_projects (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  owner_id       INT UNSIGNED NOT NULL,
  course_id      INT UNSIGNED NULL,                     -- ผูกคอร์ส (ไม่บังคับ) — ใช้จัดกลุ่มในหน้าผู้สอน
  name           VARCHAR(255) NOT NULL,
  description    TEXT NULL,
  entry_scene_id INT UNSIGNED NULL,                     -- ฉากเริ่มต้น (NULL = ใช้ฉากแรกตามลำดับ)
  hide_controls  TINYINT(1) NOT NULL DEFAULT 0,         -- 1 = ซ่อนแถบควบคุมวิดีโอ (ผู้เรียนหยุด/เลื่อนเองไม่ได้)
  status         TINYINT(1) NOT NULL DEFAULT 1,
  created_at     DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY k_indy_owner (owner_id),
  KEY k_indy_course (course_id),
  CONSTRAINT fk_indy_owner  FOREIGN KEY (owner_id)  REFERENCES users(id)   ON DELETE CASCADE,
  CONSTRAINT fk_indy_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS indy_scenes (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  project_id INT UNSIGNED NOT NULL,
  name       VARCHAR(255) NOT NULL,
  video      VARCHAR(500) NOT NULL DEFAULT '',          -- ชื่อไฟล์ใน uploads/indy/<project_id>/ หรือ URL http(s)
  sort       INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY k_isc_project (project_id, sort),
  CONSTRAINT fk_isc_project FOREIGN KEY (project_id) REFERENCES indy_projects(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS indy_branches (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  scene_id        INT UNSIGNED NOT NULL,                -- ฉากต้นทาง (ปุ่มโผล่เมื่อฉากนี้จบ)
  target_scene_id INT UNSIGNED NOT NULL,                -- ฉากปลายทาง
  label           VARCHAR(255) NOT NULL,
  sort            INT NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  KEY k_ib_scene (scene_id, sort),
  KEY k_ib_target (target_scene_id),
  CONSTRAINT fk_ib_scene  FOREIGN KEY (scene_id)        REFERENCES indy_scenes(id) ON DELETE CASCADE,
  CONSTRAINT fk_ib_target FOREIGN KEY (target_scene_id) REFERENCES indy_scenes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ความคืบหน้าผู้เรียน: ฉากล่าสุด (ดูต่อ) + เคยถึงฉากจบหรือยัง
CREATE TABLE IF NOT EXISTS indy_progress (
  user_id     INT UNSIGNED NOT NULL,
  project_id  INT UNSIGNED NOT NULL,
  scene_id    INT UNSIGNED NULL,
  reached_end TINYINT(1) NOT NULL DEFAULT 0,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (user_id, project_id),
  KEY k_ipg_project (project_id),
  KEY k_ipg_scene (scene_id),
  CONSTRAINT fk_ipg_user    FOREIGN KEY (user_id)    REFERENCES users(id)         ON DELETE CASCADE,
  CONSTRAINT fk_ipg_project FOREIGN KEY (project_id) REFERENCES indy_projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_ipg_scene   FOREIGN KEY (scene_id)   REFERENCES indy_scenes(id)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO settings (k, v, updated_at) VALUES ('indy_max_upload_mb', '200', NOW());
