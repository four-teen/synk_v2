-- Additive Management payment corrections. Run after management_payments.sql.
CREATE TABLE IF NOT EXISTS tbl_management_payment_reversals (
    reversal_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    college_id INT UNSIGNED NOT NULL,
    organization_id INT UNSIGNED NOT NULL,
    student_id INT UNSIGNED NOT NULL,
    ay_id INT UNSIGNED NOT NULL,
    semester TINYINT UNSIGNED NOT NULL,
    exam_term ENUM('MIDTERM','FINAL') NOT NULL,
    reversal_type ENUM('VOID','REFUND') NOT NULL,
    selection_mode ENUM('RECEIPT','SELECTED','ALL') NOT NULL,
    reason VARCHAR(500) NOT NULL,
    refund_reference VARCHAR(120) NULL,
    amount DECIMAL(16,2) NOT NULL,
    balance_before DECIMAL(16,2) NOT NULL,
    balance_after DECIMAL(16,2) NOT NULL,
    recorded_by INT NOT NULL,
    recorded_by_name VARCHAR(255) NOT NULL,
    recorded_at DATETIME NOT NULL,
    PRIMARY KEY (reversal_id),
    UNIQUE KEY uq_management_reversal_request (organization_id,request_key),
    KEY ix_management_reversal_period (college_id,organization_id,ay_id,semester,exam_term,recorded_at),
    KEY ix_management_reversal_student (student_id,organization_id,ay_id,semester,exam_term,reversal_id),
    CONSTRAINT fk_management_reversal_org FOREIGN KEY (college_id,organization_id)
        REFERENCES tbl_management_organizations (college_id,organization_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_reversal_student FOREIGN KEY (student_id)
        REFERENCES tbl_student_management (student_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_management_payment_reversal_items (
    reversal_id BIGINT UNSIGNED NOT NULL,
    payment_item_id BIGINT UNSIGNED NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    PRIMARY KEY (reversal_id,payment_item_id),
    KEY ix_management_reversed_item (payment_item_id),
    CONSTRAINT fk_management_reversal_item_header FOREIGN KEY (reversal_id)
        REFERENCES tbl_management_payment_reversals (reversal_id) ON DELETE RESTRICT,
    CONSTRAINT fk_management_reversal_item_original FOREIGN KEY (payment_item_id)
        REFERENCES tbl_management_payment_items (payment_item_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
