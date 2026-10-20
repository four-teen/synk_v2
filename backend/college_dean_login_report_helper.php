<?php
require_once __DIR__.'/college_dean_student_login_helper.php';

function synk_dean_login_report_defaults(): array
{
    $today=new DateTimeImmutable('today',new DateTimeZone('Asia/Manila'));
    return ['date_from'=>$today->modify('-4 days')->format('Y-m-d'),'date_to'=>$today->format('Y-m-d')];
}

function synk_dean_login_report_filters(array $input): array
{
    $defaults=synk_dean_login_report_defaults(); $dates=[];
    foreach (['date_from','date_to'] as $field) {
        $value=trim((string)($input[$field] ?? $defaults[$field]));
        if (!preg_match('/^[1-9][0-9]{3}-[0-9]{2}-[0-9]{2}$/D',$value)) throw new DomainException('Choose valid start and end dates.');
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$value,new DateTimeZone('Asia/Manila'));
        if (!$date || $date->format('Y-m-d')!==$value) {
            throw new DomainException('Choose valid start and end dates.');
        }
        $dates[$field]=$date;
    }
    if ($dates['date_from']>$dates['date_to']) throw new DomainException('The start date must be on or before the end date.');
    if ($dates['date_to']->format('Y-m-d')>$defaults['date_to']) throw new DomainException('Choose an end date no later than today.');
    if ($dates['date_from']->diff($dates['date_to'])->days>=366) throw new DomainException('Choose a date range of up to 366 days.');
    $filters=[];
    foreach (['program_id'=>0,'student_id'=>0,'page'=>1,'page_size'=>25,'snapshot'=>0] as $field=>$default) {
        $value=filter_var($input[$field] ?? $default,FILTER_VALIDATE_INT,['options'=>['min_range'=>in_array($field,['page','page_size'],true)?1:0]]);
        if ($value===false || ($field==='page' && $value>1000000)) throw new DomainException('Choose valid login activity filters.');
        $filters[$field]=$value;
    }
    if (!in_array($filters['page_size'],[25,50,100],true)) throw new DomainException('Choose 25, 50 or 100 attempts per page.');
    $filters['outcome']=(string)($input['outcome'] ?? 'all');
    if (!in_array($filters['outcome'],['all','success','failure'],true)) throw new DomainException('Choose a valid sign-in outcome.');
    $filters['search']=trim(synk_student_identity_utf8((string)($input['search'] ?? '')));
    if (preg_match_all('/./us',$filters['search'],$unused)>150) throw new DomainException('Search using up to 150 characters.');
    foreach ($dates as $field=>$date) $filters[$field]=$date->format('Y-m-d');
    $utc=new DateTimeZone('UTC');
    $filters['utc_from']=$dates['date_from']->setTimezone($utc)->format('Y-m-d H:i:s');
    $filters['utc_until']=$dates['date_to']->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s');
    return $filters;
}

function synk_dean_login_report(mysqli $conn, array $scope, array $filters): array
{
    if (!synk_student_login_activity_ready($conn)) throw new DomainException('Student login activity tracking is not available yet.');
    // DISTINCT membership prevents multiplying events for students with several subjects/programs.
    $source=synk_dean_students_source($scope,['program_id'=>$filters['program_id']],$conn);
    $members='SELECT DISTINCT s.student_id '.$source['sql'];
    $join="FROM tbl_student_login_activity a JOIN ({$members}) members ON members.student_id=a.student_id
        JOIN tbl_student_management s ON s.student_id=a.student_id";
    $latest=synk_dean_students_query($conn,'SELECT COALESCE(MAX(a.activity_id),0) AS latest '.$join,$source['types'],$source['params'])[0];
    $snapshot=$filters['snapshot']>0?min($filters['snapshot'],(int)$latest['latest']):(int)$latest['latest'];
    $base=$join.' WHERE a.occurred_at>=? AND a.occurred_at<? AND a.activity_id<=?';
    $types=$source['types'].'ssi'; $params=array_merge($source['params'],[$filters['utc_from'],$filters['utc_until'],$snapshot]);
    if ($filters['student_id']>0) { $base.=' AND a.student_id=?'; $types.='i'; $params[]=$filters['student_id']; }
    if ($filters['search']!=='') {
        foreach (preg_split('/\s+/u',$filters['search']) as $word) {
            $pattern='%'.str_replace(['!','%','_'],['!!','!%','!_'],$word).'%';
            $base.=" AND (CONVERT(s.student_number USING utf8mb4) LIKE ? ESCAPE '!'
                OR CONCAT_WS(' ',s.last_name,s.first_name,s.middle_name,s.suffix_name) LIKE ? ESCAPE '!'
                OR s.email_address LIKE ? ESCAPE '!' OR a.submitted_email LIKE ? ESCAPE '!' OR CONVERT(a.ip_address USING utf8mb4) LIKE ? ESCAPE '!')";
            $types.='sssss'; for ($i=0;$i<5;$i++) $params[]=$pattern;
        }
    }
    $summary=synk_dean_students_query($conn,"SELECT COUNT(*) AS total_attempts,
        COALESCE(SUM(a.outcome='success'),0) AS successful_logins,COALESCE(SUM(a.outcome='failure'),0) AS failed_attempts,
        COUNT(DISTINCT CASE WHEN a.outcome='success' THEN a.student_id END) AS students_logged_in,
        COUNT(DISTINCT CASE WHEN a.outcome='failure' THEN a.student_id END) AS students_with_failures,
        MAX(a.occurred_at) AS last_attempt_at {$base}",$types,$params)[0];
    foreach (['total_attempts','successful_logins','failed_attempts','students_logged_in','students_with_failures'] as $key) $summary[$key]=(int)$summary[$key];
    $summary['success_rate']=$summary['total_attempts']?round(100*$summary['successful_logins']/$summary['total_attempts'],1):0;
    $summary['last_attempt_at']=synk_student_login_activity_iso($summary['last_attempt_at']);
    // UTC storage is grouped into Philippine calendar dates without relying on MySQL timezone tables.
    $dailyRows=synk_dean_students_query($conn,"SELECT DATE(DATE_ADD(a.occurred_at,INTERVAL 8 HOUR)) AS day,
        SUM(a.outcome='success') AS successful_logins,SUM(a.outcome='failure') AS failed_attempts
        {$base} GROUP BY day ORDER BY day",$types,$params);
    $byDay=array_column($dailyRows,null,'day'); $daily=[];
    $day=new DateTimeImmutable($filters['date_from'],new DateTimeZone('Asia/Manila'));
    while ($day->format('Y-m-d')<=$filters['date_to']) {
        $key=$day->format('Y-m-d'); $row=$byDay[$key] ?? [];
        $daily[]=['day'=>$key,'successful_logins'=>(int)($row['successful_logins'] ?? 0),'failed_attempts'=>(int)($row['failed_attempts'] ?? 0)];
        $day=$day->modify('+1 day');
    }
    $identity='s.student_id,s.student_number,s.first_name,s.last_name,s.middle_name,s.suffix_name';
    $attention=synk_dean_students_query($conn,"SELECT {$identity},SUM(a.outcome='failure') AS failed_attempts,
        SUM(a.outcome='success') AS successful_logins,MAX(CASE WHEN a.outcome='failure' THEN a.occurred_at END) AS last_failure_at
        {$base} GROUP BY {$identity} HAVING failed_attempts>0
        ORDER BY failed_attempts DESC,last_failure_at DESC,s.student_id LIMIT 5",$types,$params);
    foreach ($attention as &$row) {
        foreach (['student_id','failed_attempts','successful_logins'] as $key) $row[$key]=(int)$row[$key];
        $row['student_name']=synk_dean_students_name($row); $row['last_failure_at']=synk_student_login_activity_iso($row['last_failure_at']);
        foreach (['first_name','last_name','middle_name','suffix_name'] as $key) unset($row[$key]);
    }
    unset($row);
    $reasons=synk_dean_students_query($conn,"SELECT a.reason_code,COUNT(*) AS attempts {$base} AND a.outcome='failure'
        GROUP BY a.reason_code ORDER BY attempts DESC,a.reason_code LIMIT 5",$types,$params);
    foreach ($reasons as &$row) { $row['attempts']=(int)$row['attempts']; $row['reason']=synk_student_login_activity_reason($row['reason_code']); }
    unset($row);
    $historyBase=$base; $historyTypes=$types; $historyParams=$params;
    if ($filters['outcome']!=='all') { $historyBase.=' AND a.outcome=?'; $historyTypes.='s'; $historyParams[]=$filters['outcome']; }
    $total=$filters['outcome']==='all'?$summary['total_attempts']:($filters['outcome']==='success'?$summary['successful_logins']:$summary['failed_attempts']);
    $pages=max(1,(int)ceil($total/$filters['page_size'])); $page=min($filters['page'],$pages); $offset=($page-1)*$filters['page_size'];
    $rows=synk_dean_students_query($conn,"SELECT {$identity},a.activity_id,a.submitted_email,a.outcome,a.reason_code,a.identity_verified,
        a.auth_method,a.ip_address,a.browser,a.operating_system,a.device_type,a.user_agent,a.occurred_at {$historyBase}
        ORDER BY a.occurred_at DESC,a.activity_id DESC LIMIT ? OFFSET ?",$historyTypes.'ii',array_merge($historyParams,[$filters['page_size'],$offset]));
    foreach ($rows as &$row) {
        $row['student_id']=(int)$row['student_id']; $row['activity_id']=(string)$row['activity_id'];
        $row['student_name']=synk_dean_students_name($row); $row['identity_verified']=(bool)$row['identity_verified'];
        $row['occurred_at']=synk_student_login_activity_iso($row['occurred_at']); $row['reason']=synk_student_login_activity_reason($row['reason_code']);
        foreach (['first_name','last_name','middle_name','suffix_name'] as $key) unset($row[$key]);
    }
    unset($row);
    $allSource=synk_dean_students_source($scope,[],$conn);
    $programs=synk_dean_students_query($conn,"SELECT DISTINCT p.program_id,p.program_code,p.program_name,p.major {$allSource['sql']} ORDER BY p.program_code,p.major,p.program_id",$allSource['types'],$allSource['params']);
    foreach ($programs as &$program) {
        $program=['program_id'=>(int)$program['program_id'],'label'=>(trim((string)$program['program_code']) ?: $program['program_name']).(trim((string)$program['major'])!==''?' - '.$program['major']:'')];
    }
    unset($program);
    unset($filters['utc_from'],$filters['utc_until'],$filters['snapshot'],$filters['page']);
    return ['summary'=>$summary,'daily'=>$daily,'attention'=>$attention,'reasons'=>$reasons,'rows'=>$rows,'programs'=>$programs,
        'filters'=>$filters,'snapshot'=>$snapshot,'timezone'=>'Asia/Manila','generated_at'=>gmdate('Y-m-d\TH:i:s\Z'),
        'pagination'=>['page'=>$page,'pages'=>$pages,'page_size'=>$filters['page_size'],'total'=>$total,'from'=>$total?$offset+1:0,'to'=>$offset+count($rows)]];
}
