-- 007 — กันเดารหัสผ่าน: นับครั้งที่ล็อกอินพลาดต่ออีเมล/ต่อ IP (ไม่พึ่ง session ที่ผู้โจมตีล้างได้)
SET NAMES utf8mb4;
CREATE TABLE IF NOT EXISTS login_attempts (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email_hash  CHAR(64) NOT NULL,
  ip          VARCHAR(45) NOT NULL,
  created_at  DATETIME NOT NULL,
  KEY k_la_email (email_hash, created_at),
  KEY k_la_ip (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
