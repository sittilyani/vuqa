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



--- changing dsepreciation percentage

SELECT amr.asset_id, amr.asset_category, amr.depreciation_percentage AS current_value, ac.depreciation_percentage AS new_value
FROM asset_master_register amr
JOIN asset_categories ac ON amr.asset_category = ac.category_name;
-- to see changes that will happen

-- Implement the changes

UPDATE asset_master_register amr
JOIN asset_categories ac ON amr.asset_category = ac.category_name
SET amr.depreciation_percentage = ac.depreciation_percentage;

