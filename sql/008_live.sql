-- ============================================================
-- Aleanor Streaming — ผู้สอนถ่ายทอดสดจากเบราว์เซอร์ของตัวเอง (พอร์ตจาก aleanor_ai migration 50)
-- ส่งตรงจากเครื่องผู้สอน (WebRTC p2p) หรือผ่าน Cloudflare Stream (WHIP/WHEP) ถ้าตั้งค่าไว้
-- รันซ้ำได้ (CREATE TABLE IF NOT EXISTS)
-- ============================================================
CREATE TABLE IF NOT EXISTS live_sessions (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_key  CHAR(32) NOT NULL,
  course_id    INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,                    -- ผู้สอนที่ออกอากาศ
  mode         VARCHAR(10) NOT NULL DEFAULT 'p2p',       -- p2p / cf
  title        VARCHAR(255) NOT NULL DEFAULT '',
  status       TINYINT(1) NOT NULL DEFAULT 1,            -- 1 ออกอากาศ / 0 จบแล้ว
  started_at   DATETIME NOT NULL,
  ended_at     DATETIME NULL,
  heartbeat_at DATETIME NOT NULL,                        -- ผู้สอนเงียบนานเกิน = ปิดให้อัตโนมัติ
  cf_uid       VARCHAR(64) NOT NULL DEFAULT '',
  cf_whip      VARCHAR(500) NOT NULL DEFAULT '',         -- ลิงก์ส่งภาพ (ความลับของผู้สอน ล้างทิ้งตอนจบ)
  cf_whep      VARCHAR(500) NOT NULL DEFAULT '',
  peak         INT NOT NULL DEFAULT 0,
  notified     TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_live_key (session_key),
  KEY k_live_course (course_id, status),
  CONSTRAINT fk_live_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS live_peers (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  peer_key     CHAR(32) NOT NULL,
  session_key  CHAR(32) NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  offer        MEDIUMTEXT,
  answer       MEDIUMTEXT,
  state        VARCHAR(12) NOT NULL DEFAULT 'offer',     -- offer / answered / closed / full
  created_at   DATETIME NOT NULL,
  updated_at   DATETIME NOT NULL,
  UNIQUE KEY uq_peer_key (peer_key),
  KEY k_peer_session (session_key, state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS live_viewers (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_key  CHAR(32) NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  first_at     DATETIME NOT NULL,
  last_at      DATETIME NOT NULL,
  seconds      INT NOT NULL DEFAULT 0,                   -- เวลาที่ดูจริงสะสม
  UNIQUE KEY uq_viewer (session_key, user_id),
  KEY k_viewer_last (session_key, last_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS live_messages (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_key  CHAR(32) NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  body         VARCHAR(1000) NOT NULL,
  created_at   DATETIME NOT NULL,
  KEY k_msg_session (session_key, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
