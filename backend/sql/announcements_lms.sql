-- Install only in the separate LMS database. Core and Management data stay unchanged.
CREATE TABLE IF NOT EXISTS tbl_lms_announcements (
  announcement_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  faculty_id INT UNSIGNED NOT NULL,
  offering_id INT UNSIGNED NOT NULL,
  ay_id INT UNSIGNED NOT NULL,
  semester TINYINT UNSIGNED NOT NULL,
  title VARCHAR(200) NOT NULL,
  body TEXT NOT NULL,
  status ENUM('published','archived') NOT NULL DEFAULT 'published',
  version INT UNSIGNED NOT NULL DEFAULT 1,
  created_by_user_id INT UNSIGNED NOT NULL,
  updated_by_user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (announcement_id),
  KEY idx_lms_announcement_class (faculty_id,offering_id,ay_id,semester,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_lms_announcement_reads (
  student_id INT UNSIGNED NOT NULL,
  event_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  read_at DATETIME NOT NULL,
  PRIMARY KEY (student_id,event_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
