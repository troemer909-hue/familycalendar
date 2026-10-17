-- Behebt MySQL-Fehler #1071 "Specified key was too long" (max. 767 Byte)
-- beim Importieren von schema.sql auf Hosts mit älterem
-- InnoDB-Zeilenformat (COMPACT/REDUNDANT statt DYNAMIC, kein
-- innodb_large_prefix): bei utf8mb4 (4 Byte/Zeichen) ist ein Index auf
-- VARCHAR(255) 1020 Byte lang, über dem 767-Byte-Limit. Betroffen waren
-- users.email (UNIQUE), events.external_uid (Teil einer zusammengesetzten
-- UNIQUE KEY), login_attempts.email (normaler INDEX) und
-- schema_migrations.migration (PRIMARY KEY) - jeweils auf 190/191 Zeichen
-- gekürzt, das bleibt unter 767 Byte, auch in der zusammengesetzten
-- UNIQUE KEY uniq_source_uid.
-- Für NEUE Installationen sind die kürzeren Spalten bereits in
-- database/schema.sql enthalten - dieses Skript ist nur für bereits
-- bestehende Datenbanken nötig (einmalig per phpMyAdmin/Konsole
-- ausführen, oder automatisch per Selbst-Update, siehe README).
ALTER TABLE users
  MODIFY email VARCHAR(191) NOT NULL;

ALTER TABLE events
  MODIFY external_uid VARCHAR(190) NULL;

ALTER TABLE login_attempts
  MODIFY email VARCHAR(191) NOT NULL;

ALTER TABLE schema_migrations
  MODIFY migration VARCHAR(191) NOT NULL;
