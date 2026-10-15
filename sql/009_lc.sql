-- Aleanor LC — ประวัติการนำเข้าเทมเพลตจากคลังบทเรียนกลาง (aleanor_lc) เป็นคอร์สของผู้สอน
CREATE TABLE IF NOT EXISTS lc_imports (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  template_uid    VARCHAR(50)  NOT NULL,
  template_code   VARCHAR(50)  NOT NULL DEFAULT '',
  template_title  VARCHAR(255) NOT NULL DEFAULT '',
  version         INT NOT NULL DEFAULT 1,
  course_id       INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NOT NULL,
  created_at      DATETIME NOT NULL,
  KEY k_lci_tpl (template_uid),
  KEY k_lci_user (user_id),
  CONSTRAINT fk_lci_course FOREIGN KEY (course_id) REFERENCES courses(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
