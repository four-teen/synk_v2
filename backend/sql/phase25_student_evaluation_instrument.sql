-- SKSU-INS-EFP-01 Revision 01, effective January 14, 2026.
-- Preserve existing evaluations under their original instrument.
-- The application also adds this column automatically when it is absent.
ALTER TABLE tbl_student_faculty_evaluations
    ADD COLUMN instrument_version VARCHAR(64) NOT NULL DEFAULT 'legacy-20'
    AFTER comment_text;
