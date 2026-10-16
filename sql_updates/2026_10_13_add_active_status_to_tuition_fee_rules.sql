-- Add a status flag so fee rules can be retired without deleting financial history.
-- Existing fee rules remain active by default.
ALTER TABLE tuition_fee_rules
    ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER amount;
