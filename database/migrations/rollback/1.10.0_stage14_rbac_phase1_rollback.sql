-- Rollback Stage 14 Phase 1 — reverts schema label only.
-- Seeded admin_role_permissions rows are not removed (may include intentional custom grants).

UPDATE `settings` SET `setting_value` = '1.9.0-stage13-cms-1' WHERE `setting_key` = 'schema_version';
