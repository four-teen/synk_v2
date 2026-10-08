-- ============================================================================
-- Phase 3: Room Term Sharing (AY + Semester scoped room access)
-- ============================================================================
-- Purpose:
-- 1) Keep tbl_rooms as the master room record (owner college).
-- 2) Control room visibility/usage per college, academic year, and semester.
-- 3) Support shared rooms without duplicating room master rows per college.
-- ============================================================================

CREATE TABLE IF NOT EXISTS tbl_room_college_access (
    access_id INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    room_id INT(10) UNSIGNED NOT NULL,
    college_id INT(10) UNSIGNED NOT NULL,
    ay_id INT(10) UNSIGNED NOT NULL,
    semester TINYINT(3) UNSIGNED NOT NULL,
    access_type ENUM('owner','shared') NOT NULL DEFAULT 'shared',
    date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (access_id),
    UNIQUE KEY uk_room_college_term (room_id, college_id, ay_id, semester),
    KEY idx_college_term (college_id, ay_id, semester),
    KEY idx_room_term (room_id, ay_id, semester),
    CONSTRAINT fk_room_access_room
        FOREIGN KEY (room_id) REFERENCES tbl_rooms(room_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_room_access_college
        FOREIGN KEY (college_id) REFERENCES tbl_college(college_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,
    CONSTRAINT fk_room_access_ay
        FOREIGN KEY (ay_id) REFERENCES tbl_academic_years(ay_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
);

-- Optional one-time bootstrap (manual review before use):
-- Insert owner access rows for selected AY/Semester.
-- Example:
-- INSERT INTO tbl_room_college_access (room_id, college_id, ay_id, semester, access_type)
-- SELECT r.room_id, r.college_id, 1 AS ay_id, 1 AS semester, 'owner'
-- FROM tbl_rooms r
-- ON DUPLICATE KEY UPDATE access_type = VALUES(access_type);
