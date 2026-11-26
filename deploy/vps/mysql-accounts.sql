CREATE DATABASE tekora CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'tekora_migrator'@'localhost' IDENTIFIED BY RANDOM PASSWORD;
CREATE USER 'tekora_app'@'localhost' IDENTIFIED BY RANDOM PASSWORD;
CREATE USER 'tekora_backup'@'localhost' IDENTIFIED BY RANDOM PASSWORD;
CREATE USER 'tekora_definer'@'localhost' ACCOUNT LOCK;
GRANT SELECT, INSERT, UPDATE, CREATE, ALTER, DROP, INDEX, REFERENCES, CREATE VIEW, SHOW VIEW, CREATE ROUTINE, ALTER ROUTINE, EXECUTE, TRIGGER, EVENT ON tekora.* TO 'tekora_migrator'@'localhost';
GRANT SHOW_ROUTINE ON *.* TO 'tekora_migrator'@'localhost', 'tekora_backup'@'localhost';
GRANT SELECT, TRIGGER, EXECUTE, LOCK TABLES ON tekora.* TO 'tekora_definer'@'localhost';
GRANT SELECT, SHOW VIEW, TRIGGER, EVENT ON tekora.* TO 'tekora_backup'@'localhost';
