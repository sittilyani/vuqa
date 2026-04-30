SELECT CONCAT(
    'ALTER TABLE `', TABLE_NAME,
    '` CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;'
) AS sql_statements
FROM INFORMATION_SCHEMA.TABLES
WHERE TABLE_SCHEMA = 'transition';



-- Check for tables with stored generated colums

SELECT TABLE_NAME, COLUMN_NAME
FROM INFORMATION_SCHEMA.COLUMNS
WHERE TABLE_SCHEMA = 'thetjbib_transition_tracker'
AND EXTRA LIKE '%GENERATED%';