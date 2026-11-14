-- Install only in the separate LMS database.
CREATE TABLE IF NOT EXISTS tbl_lms_material_collections (
  collection_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  faculty_id INT UNSIGNED NOT NULL,
  offering_id INT UNSIGNED NOT NULL,
  ay_id INT UNSIGNED NOT NULL,
  semester TINYINT UNSIGNED NOT NULL,
  PRIMARY KEY (collection_id),
  UNIQUE KEY uq_lms_material_class (faculty_id,offering_id,ay_id,semester)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_lms_material_files (
  material_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  collection_id INT UNSIGNED NOT NULL,
  slot ENUM('1','2') NOT NULL,
  original_name VARCHAR(200) NOT NULL,
  mime_type VARCHAR(100) NOT NULL,
  size_bytes INT UNSIGNED NOT NULL,
  file_data LONGBLOB NOT NULL,
  uploaded_by_user_id INT UNSIGNED NOT NULL,
  uploaded_at DATETIME NOT NULL,
  PRIMARY KEY (material_id),
  UNIQUE KEY uq_lms_material_slot (collection_id,slot),
  CONSTRAINT fk_lms_material_collection FOREIGN KEY (collection_id)
    REFERENCES tbl_lms_material_collections (collection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
