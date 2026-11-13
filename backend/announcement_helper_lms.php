<?php
require_once __DIR__.'/assessment_helper_lms.php';
require_once __DIR__.'/assessment_delivery_helper_lms.php';

function synk_announcement_ready_lms(mysqli $lms): bool
{
    return count(synk_assessment_query_lms($lms, "SELECT TABLE_NAME FROM information_schema.TABLES
        WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('tbl_lms_announcements','tbl_lms_announcement_reads')")) === 2;
}

function synk_announcement_list_lms(mysqli $lms, array $context): array
{
    return synk_assessment_query_lms($lms, "SELECT announcement_id,title,body,version,created_at,updated_at
        FROM tbl_lms_announcements WHERE faculty_id=? AND offering_id=? AND ay_id=? AND semester=? AND status='published'
        ORDER BY updated_at DESC,announcement_id DESC", 'iiii', synk_assessment_scope_params_lms($context));
}

// The caller starts a transaction; scope and version checks also protect other tabs.
function synk_announcement_write_lms(mysqli $lms, array $context, string $action, array $payload, int $actor): array
{
    $id = synk_assessment_id_lms($payload['announcement_id'] ?? null, true);
    $scope = synk_assessment_scope_params_lms($context);
    if ($id) {
        $rows = synk_assessment_query_lms($lms, "SELECT version FROM tbl_lms_announcements WHERE announcement_id=?
            AND faculty_id=? AND offering_id=? AND ay_id=? AND semester=? AND status='published' FOR UPDATE", 'iiiii', array_merge([$id], $scope));
        if (!$rows) throw new DomainException('This announcement is unavailable in this class.', 404);
        if (synk_assessment_id_lms($payload['version'] ?? null) !== (int)$rows[0]['version']) throw new DomainException('This announcement changed in another tab. Refresh before continuing.', 409);
    } elseif ($action === 'archive') throw new DomainException('Choose an announcement to remove.', 422);
    $now = synk_assessment_now_lms();
    if ($action === 'archive') {
        synk_assessment_query_lms($lms, "UPDATE tbl_lms_announcements SET status='archived',version=version+1,updated_by_user_id=?,updated_at=? WHERE announcement_id=?", 'isi', [$actor,$now,$id]);
    } else {
        $title = synk_assessment_text_lms($payload['title'] ?? null, 'Announcement title', 200, true);
        $body = synk_assessment_text_lms($payload['body'] ?? null, 'Announcement message', 10000, true);
        if ($id) {
            synk_assessment_query_lms($lms, 'UPDATE tbl_lms_announcements SET title=?,body=?,version=version+1,updated_by_user_id=?,updated_at=? WHERE announcement_id=?', 'ssisi', [$title,$body,$actor,$now,$id]);
        } else {
            synk_assessment_query_lms($lms, 'INSERT INTO tbl_lms_announcements (faculty_id,offering_id,ay_id,semester,title,body,created_by_user_id,updated_by_user_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)', 'iiiissiiss', array_merge($scope,[$title,$body,$actor,$actor,$now,$now]));
        }
    }
    return ['announcements'=>synk_announcement_list_lms($lms,$context)];
}

function synk_student_announcement_feed_lms(mysqli $core, mysqli $lms, int $studentId): array
{
    $term = synk_fetch_current_academic_term($core);
    $params = [(int)$term['ay_id'],(int)$term['semester']];
    $rows = [];
    if (synk_announcement_ready_lms($lms)) {
        $rows = synk_assessment_query_lms($lms, "SELECT faculty_id,offering_id,ay_id,semester,title,body,updated_at AS event_at,
            CONCAT('announcement:',announcement_id,':',version) AS event_key,'announcement' AS kind,NULL AS assessment_id,NULL AS opens_at,NULL AS due_at,'published' AS status
            FROM tbl_lms_announcements WHERE ay_id=? AND semester=? AND status='published'", 'ii', $params);
    }
    if (synk_assessment_ready_lms($lms)) {
        // A publication log is the event: ordinary edits never create duplicate notices.
        // Reopening replaces the previous notice. No answer keys or questions are read.
        $rows = array_merge($rows, synk_assessment_query_lms($lms, "SELECT a.faculty_id,a.offering_id,a.ay_id,a.semester,a.title,
            '' AS body,p.event_at,CONCAT('activity:',a.assessment_id,':',p.log_id) AS event_key,
            a.assessment_type AS kind,a.assessment_id,a.opens_at,a.due_at,a.status
            FROM tbl_lms_assessments a JOIN (SELECT assessment_id,MAX(log_id) AS log_id,MAX(recorded_at) AS event_at
              FROM tbl_lms_assessment_logs WHERE action IN ('published','reopened') GROUP BY assessment_id) p ON p.assessment_id=a.assessment_id
            WHERE a.ay_id=? AND a.semester=? AND a.status IN ('published','closed')", 'ii', $params));
    }
    $classes = []; $members = []; $names = []; $feed = [];
    foreach ($rows as $row) {
        $faculty = (int)$row['faculty_id']; $offering = (int)$row['offering_id'];
        if (!isset($classes[$faculty])) {
            $active = synk_assessment_query_lms($core, "SELECT faculty_id,TRIM(CONCAT_WS(' ',first_name,last_name)) AS faculty_name FROM tbl_faculty WHERE faculty_id=? AND status='active'", 'i', [$faculty]);
            $names[$faculty] = $active[0]['faculty_name'] ?? 'Your professor';
            $assigned = $active ? synk_professor_fetch_subject_rows_by_academic_year($core,$faculty,$params[0],$params[1]) : [];
            $members[$faculty] = synk_professor_workload_student_memberships($core,$assigned,$params[0],$params[1]);
            $classes[$faculty] = array_column($assigned,null,'offering_id');
        }
        if (!isset($classes[$faculty][$offering],$members[$faculty][$offering][$studentId])) continue;
        $class = $classes[$faculty][$offering];
        $feed[] = array_intersect_key($row,array_flip(['event_key','event_at','title','body','kind','assessment_id','opens_at','due_at','status'])) + [
            'subject_label'=>$class['subject_code'].': '.$class['descriptive_title'],
            'faculty_name'=>$names[$faculty],
            'url'=>$row['assessment_id'] === null ? null : 'assessments_lms.php?assessment_id='.(int)$row['assessment_id']
        ];
    }
    usort($feed,static function(array $a,array $b): int { return strcmp($b['event_at'],$a['event_at']) ?: strcmp($b['event_key'],$a['event_key']); });
    $feed = array_slice($feed,0,100);
    $read = [];
    if ($feed && synk_announcement_ready_lms($lms)) {
        $keys = array_column($feed,'event_key');
        $reads = synk_assessment_query_lms($lms,'SELECT event_key FROM tbl_lms_announcement_reads WHERE student_id=? AND event_key IN ('.implode(',',array_fill(0,count($keys),'?')).')', 'i'.str_repeat('s',count($keys)), array_merge([$studentId],$keys));
        $read = array_fill_keys(array_column($reads,'event_key'),true);
    }
    foreach ($feed as &$item) $item['unread'] = !isset($read[$item['event_key']]);
    unset($item);
    return $feed;
}
