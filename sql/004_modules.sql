-- 004 — บทเรียนชนิดใหม่จากโมดูล (indy / doc / playground) + ห้องเรียนเสมือนต่อคอร์ส
SET NAMES utf8mb4;
ALTER TABLE lessons MODIFY type ENUM('video','text','indy','doc','playground') NOT NULL DEFAULT 'video',
  ADD COLUMN ref_id INT UNSIGNED NULL AFTER content,          -- indy_projects.id / docs_documents.id
  ADD COLUMN ref_key VARCHAR(64) NOT NULL DEFAULT '' AFTER ref_id; -- playground assignment key
ALTER TABLE courses ADD COLUMN vc_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER price,
  ADD COLUMN vc_room VARCHAR(64) NOT NULL DEFAULT '' AFTER vc_enabled;
INSERT INTO settings (k, v, updated_at) VALUES
 ('mod_indy','1',NOW()), ('mod_docs','1',NOW()), ('mod_playground','0',NOW()), ('mod_vc','0',NOW()), ('mod_certificates','1',NOW()),
 ('playground_url','',NOW()), ('playground_secret','',NOW()),
 ('vc_base_url','',NOW()), ('vc_jwt_secret','',NOW()), ('vc_tenant_id','cloud',NOW()),
 ('help_url','',NOW())
ON DUPLICATE KEY UPDATE k = k;
