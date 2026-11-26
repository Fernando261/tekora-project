-- SQL import may temporarily disable FK checks. Check every referenced key explicitly.
SET SESSION group_concat_max_len=1048576;
SELECT GROUP_CONCAT(CONCAT(
    'SELECT ', QUOTE(CONSTRAINT_NAME), ' AS fk FROM `', TABLE_NAME, '` c WHERE ',
    non_null, ' AND NOT EXISTS (SELECT 1 FROM `', REFERENCED_TABLE_NAME, '` p WHERE ', joined, ') LIMIT 1'
) SEPARATOR ') UNION ALL (') INTO @fk_checks
FROM (
    SELECT CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME,
        GROUP_CONCAT(CONCAT('c.`', COLUMN_NAME, '` IS NOT NULL') ORDER BY ORDINAL_POSITION SEPARATOR ' AND ') AS non_null,
        GROUP_CONCAT(CONCAT('p.`', REFERENCED_COLUMN_NAME, '` = c.`', COLUMN_NAME, '`') ORDER BY ORDINAL_POSITION SEPARATOR ' AND ') AS joined
    FROM information_schema.key_column_usage
    WHERE CONSTRAINT_SCHEMA=DATABASE() AND REFERENCED_TABLE_SCHEMA=DATABASE()
    GROUP BY CONSTRAINT_NAME, TABLE_NAME, REFERENCED_TABLE_NAME
) AS fk_list;
SET @fk_sql=CONCAT('INSERT INTO tekora_restore_fk_check (fk) (', @fk_checks, ')');
CREATE TEMPORARY TABLE tekora_restore_fk_check (fk VARCHAR(64) NOT NULL CHECK (fk=''));
PREPARE validate_fks FROM @fk_sql;
EXECUTE validate_fks;
DEALLOCATE PREPARE validate_fks;
