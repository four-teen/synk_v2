-- Directory leadership is a college/program appointment for an academic term.
-- A slot has one holder; changing it does not grant or revoke portal access.
CREATE TABLE IF NOT EXISTS tbl_faculty_college_appointments (
  appointment_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  college_id INT UNSIGNED NOT NULL,
  ay_id INT UNSIGNED NOT NULL,
  semester TINYINT UNSIGNED NOT NULL,
  appointment_role ENUM('dean','chair') NOT NULL,
  program_id INT UNSIGNED NOT NULL DEFAULT 0,
  faculty_id INT UNSIGNED NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  revision INT UNSIGNED NOT NULL DEFAULT 1,
  record_source VARCHAR(24) NOT NULL DEFAULT 'manual',
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (appointment_id),
  UNIQUE KEY uq_college_appointment_slot (college_id,ay_id,semester,appointment_role,program_id),
  KEY idx_faculty_college_appointment (faculty_id,college_id,ay_id,semester,status),
  CONSTRAINT fk_college_appointment_faculty FOREIGN KEY (faculty_id)
    REFERENCES tbl_faculty (faculty_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Import only confirmed, unambiguous current assignments. Generic designation
-- text and teaching lists cannot establish the college of a leadership post.
-- Existing appointments, including ended appointments, are never overwritten.
INSERT INTO tbl_faculty_college_appointments
  (college_id,ay_id,semester,appointment_role,program_id,faculty_id,record_source)
SELECT u.college_dean_college_id,s.current_ay_id,s.current_semester,'dean',0,
       MIN(l.faculty_id),'dean_assignment'
FROM tbl_useraccount u
JOIN tbl_useraccount_faculty_links l ON l.user_id=u.user_id
JOIN tbl_faculty f ON f.faculty_id=l.faculty_id AND f.status IN ('active','on_leave')
JOIN tbl_college c ON c.college_id=u.college_dean_college_id AND c.status='active'
JOIN tbl_campus cp ON cp.campus_id=c.campus_id AND cp.status='active'
JOIN (SELECT current_ay_id,current_semester FROM tbl_academic_settings LIMIT 1) s
  ON s.current_ay_id>0 AND s.current_semester BETWEEN 1 AND 3
WHERE u.status='active' AND (
  EXISTS (SELECT 1 FROM tbl_useraccount_roles r WHERE r.user_id=u.user_id
          AND r.role='college_dean' AND r.status='active')
  OR (u.role='college_dean' AND NOT EXISTS
      (SELECT 1 FROM tbl_useraccount_roles r WHERE r.user_id=u.user_id)))
GROUP BY u.college_dean_college_id,s.current_ay_id,s.current_semester
HAVING COUNT(DISTINCT l.faculty_id)=1
ON DUPLICATE KEY UPDATE appointment_id=appointment_id;

INSERT INTO tbl_faculty_college_appointments
  (college_id,ay_id,semester,appointment_role,program_id,faculty_id,record_source)
SELECT pcs.college_id,s.current_ay_id,s.current_semester,'chair',pcs.program_id,
       pcs.faculty_id,'chair_assignment'
FROM tbl_program_chair_signatories pcs
JOIN tbl_program p ON p.program_id=pcs.program_id AND p.college_id=pcs.college_id AND p.status='active'
JOIN tbl_college c ON c.college_id=pcs.college_id AND c.status='active'
JOIN tbl_campus cp ON cp.campus_id=c.campus_id AND cp.status='active'
JOIN tbl_faculty f ON f.faculty_id=pcs.faculty_id AND f.status IN ('active','on_leave')
JOIN (SELECT current_ay_id,current_semester FROM tbl_academic_settings LIMIT 1) s
  ON s.current_ay_id>0 AND s.current_semester BETWEEN 1 AND 3
WHERE pcs.status='active'
ON DUPLICATE KEY UPDATE appointment_id=appointment_id;
