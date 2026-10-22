-- Recover legacy events from the period for which the current term is known.
-- Academic-settings saves use database-local NOW(); login events use UTC_TIMESTAMP().
-- Convert the settings boundary to UTC before comparing. A later settings save
-- makes recovery conservative. Never infer terms from student profiles.
UPDATE tbl_student_login_activity a
JOIN (
    SELECT s.current_ay_id AS ay_id, s.current_semester AS semester,
        TIMESTAMPADD(SECOND, TIMESTAMPDIFF(SECOND, NOW(), UTC_TIMESTAMP()), s.date_updated) AS known_since_utc
    FROM tbl_academic_settings s
    JOIN tbl_academic_years ay ON ay.ay_id=s.current_ay_id
    WHERE s.status='active' AND s.current_semester BETWEEN 1 AND 3
        AND s.date_updated>'1970-01-01 00:00:00'
    ORDER BY s.setting_id
    LIMIT 1
) term ON a.occurred_at>=term.known_since_utc AND a.occurred_at<=UTC_TIMESTAMP()
SET a.ay_id=term.ay_id, a.semester=term.semester
WHERE a.ay_id IS NULL AND a.semester IS NULL;
