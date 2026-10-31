<?php
// Transaction-only fixture; callers must roll back their enclosing transaction.
function sef_qa_workload(mysqli $conn,int $program,int $ay,int $semester,int $faculty,int $user,int $units=3): array
{
    $tag='S'.bin2hex(random_bytes(4));
    $conn->query("INSERT INTO tbl_prospectus_header(program_id,cmo_no,effective_sy) VALUES ($program,'QA','QA')");$header=(int)$conn->insert_id;
    $conn->query("INSERT INTO tbl_prospectus_year_sem(prospectus_id,year_level,semester) VALUES ($header,1,$semester)");$pys=(int)$conn->insert_id;
    $conn->query("INSERT INTO tbl_subject_masterlist(sub_code,sub_description) VALUES ('$tag','Supervisor workload QA')");$subject=(int)$conn->insert_id;
    $conn->query("INSERT INTO tbl_prospectus_subjects(pys_id,sub_id,lec_units,lab_units,total_units) VALUES ($pys,$subject,$units,0,$units)");$ps=(int)$conn->insert_id;
    $conn->query("INSERT INTO tbl_sections(program_id,ay_id,semester,year_level,section_name,full_section,status) VALUES ($program,$ay,$semester,1,'$tag','$tag','active')");$section=(int)$conn->insert_id;
    $conn->query("INSERT INTO tbl_section_curriculum(section_id,prospectus_id,assigned_by) VALUES ($section,$header,$user)");
    $conn->query("INSERT INTO tbl_prospectus_offering(program_id,prospectus_id,ps_id,year_level,semester,ay_id,section_id,status) VALUES ($program,$header,$ps,1,$semester,$ay,$section,'active')");$offering=(int)$conn->insert_id;
    $conn->query("INSERT INTO tbl_class_schedule(offering_id,schedule_type,schedule_status,days_json,created_by) VALUES ($offering,'LEC','tba','[]',$user)");$schedule=(int)$conn->insert_id;
    $conn->query("INSERT INTO tbl_faculty_workload_sched(schedule_id,faculty_id,ay_id,semester) VALUES ($schedule,$faculty,$ay,$semester)");
    return ['offering_id'=>$offering,'schedule_id'=>$schedule,'ps_id'=>$ps];
}
