-- READ-ONLY deployment checks. This is NOT a migration or a data repair.
-- Select the intended staging database yourself. Never use the synthetic test
-- provisioner/seeder against production. Review table existence before running
-- module-specific queries below; a missing table will stop those queries.

SELECT VERSION() AS database_version, @@sql_mode AS sql_mode, DATABASE() AS selected_database;

SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE()
  AND TABLE_NAME IN ('users','members','class_enrollments','finance_categories',
      'finance_transactions','finance_member_fees','material_items',
      'material_transactions','material_requests','staff_positions',
      'security_rate_limits','activity_logs')
ORDER BY TABLE_NAME;
-- All tables participating in an atomic operation must use a transactional
-- engine (the repository's migrations specify InnoDB).

SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA=DATABASE()
  AND ((TABLE_NAME='staff_positions' AND COLUMN_NAME IN ('department_id','legacy_flag'))
    OR (TABLE_NAME='members' AND COLUMN_NAME IN ('status','current_class_id','date_of_birth','dob_ec_year'))
    OR (TABLE_NAME='class_enrollments' AND COLUMN_NAME IN ('academic_year_id','status')))
ORDER BY TABLE_NAME, ORDINAL_POSITION;

SELECT COUNT(*) AS active_super_admins FROM users WHERE role='super_admin' AND is_active=1;

-- Aggregate-only evidence of historic corruption. Do not infer the correct
-- dates/units/flags from these counts and do not automatically UPDATE them.
SELECT COUNT(*) AS zero_finance_dates
FROM finance_transactions WHERE CAST(transaction_date AS CHAR)='0000-00-00';

SELECT COUNT(*) AS nonpositive_finance_amounts
FROM finance_transactions WHERE amount<=0;

SELECT COUNT(*) AS suspicious_material_units
FROM material_items WHERE unit IN ('0','') OR unit IS NULL;

SELECT COUNT(*) AS negative_material_quantities
FROM material_items WHERE quantity<0 OR min_quantity<0;

SELECT COUNT(*) AS orphan_material_movements
FROM material_transactions t LEFT JOIN material_items i ON i.id=t.item_id
WHERE i.id IS NULL;

SELECT COUNT(*) AS unsupported_legacy_flags
FROM staff_positions
WHERE legacy_flag IS NOT NULL
  AND legacy_flag NOT IN ('is_teacher','is_staff','is_committee','is_volunteer');

SELECT COUNT(*) AS member_years_with_multiple_active_classes
FROM (
    SELECT member_id, academic_year_id
    FROM class_enrollments WHERE status='active'
    GROUP BY member_id, academic_year_id HAVING COUNT(*)>1
) AS duplicate_enrollment_groups;

SELECT COUNT(*) AS orphan_fee_members
FROM finance_member_fees f LEFT JOIN members m ON m.id=f.member_id WHERE m.id IS NULL;

-- The current fee schema has no durable fee-to-ledger foreign key. Do NOT
-- attempt an automatic reconciliation using date/amount alone; unrelated
-- payments can have identical values. Reconcile receipts with Finance staff.
