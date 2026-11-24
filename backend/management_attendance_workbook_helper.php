<?php
require_once __DIR__.'/student_identity_helper.php';

function synk_management_attendance_workbook_xml(ZipArchive $zip, string $part): SimpleXMLElement
{
    $entry=$zip->statName($part);
    if (!$entry || $entry['size']>4*1024*1024) throw new DomainException('The workbook has a missing or oversized Excel component.');
    $content=$zip->getFromIndex($entry['index']);
    if (!is_string($content) || strpos($content,"\0")!==false || stripos($content,'<!DOCTYPE')!==false) {
        throw new DomainException('The workbook contains unsupported XML.');
    }
    $xml=simplexml_load_string($content,SimpleXMLElement::class,LIBXML_NONET|LIBXML_NOERROR|LIBXML_NOWARNING);
    if ($xml===false) throw new DomainException('Excel could not supply valid worksheet data.');
    return $xml;
}

function synk_management_parse_attendance_workbook(string $path, string $filename, string $template): array
{
    if ($template==='CUD') $template='EVENT';
    if (!in_array($template,['FRC','EVENT'],true)) throw new DomainException('Choose an attendance template.');
    if (strtolower(pathinfo($filename,PATHINFO_EXTENSION))!=='xlsx') throw new DomainException('Choose an Excel .xlsx workbook.');
    if (!class_exists('ZipArchive') || !function_exists('simplexml_load_string')) {
        throw new RuntimeException('Attendance import requires PHP ZIP and SimpleXML.');
    }
    $size=is_file($path) ? filesize($path) : false;
    if (!$size || $size>5*1024*1024) throw new DomainException('Choose an Excel workbook up to 5 MB.');

    $zip=new ZipArchive();
    if ($zip->open($path)!==true) throw new DomainException('Choose a valid Excel .xlsx workbook.');
    try {
        $main='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $relationships='http://schemas.openxmlformats.org/officeDocument/2006/relationships';
        $workbook=synk_management_attendance_workbook_xml($zip,'xl/workbook.xml');
        $workbook->registerXPathNamespace('m',$main);
        $sheet=$workbook->xpath('/m:workbook/m:sheets/m:sheet[1]')[0] ?? null;
        if (!$sheet) throw new DomainException('The workbook needs an attendance worksheet.');
        $sheetName=(string)$sheet['name'];
        $relationshipId=(string)($sheet->attributes($relationships)['id'] ?? '');
        $rels=synk_management_attendance_workbook_xml($zip,'xl/_rels/workbook.xml.rels');
        $rels->registerXPathNamespace('r','http://schemas.openxmlformats.org/package/2006/relationships');
        $target='';
        foreach ($rels->xpath('/r:Relationships/r:Relationship') ?: [] as $rel) {
            if ((string)$rel['Id']===$relationshipId && (string)$rel['Type']===$relationships.'/worksheet' && (string)$rel['TargetMode']!=='External') {
                $target=ltrim((string)$rel['Target'],'/');
                break;
            }
        }
        if (strpos($target,'xl/')!==0) $target='xl/'.$target;
        if (!preg_match('~^xl/worksheets/[a-z0-9_.-]+\.xml$~iD',$target)) throw new DomainException('The first sheet must be an Excel worksheet.');

        $strings=[];
        if ($zip->locateName('xl/sharedStrings.xml')!==false) {
            $shared=synk_management_attendance_workbook_xml($zip,'xl/sharedStrings.xml');
            $shared->registerXPathNamespace('m',$main);
            foreach ($shared->xpath('/m:sst/m:si') ?: [] as $item) {
                $item->registerXPathNamespace('m',$main);
                $strings[]=implode('',array_map('strval',$item->xpath('./m:t | ./m:r/m:t') ?: []));
            }
        }
        $worksheet=synk_management_attendance_workbook_xml($zip,$target);
        $worksheet->registerXPathNamespace('m',$main);
        $rows=[];
        $columns=$template==='EVENT' ? '[A-E]' : 'A';
        foreach ($worksheet->xpath('/m:worksheet/m:sheetData/m:row/m:c') ?: [] as $cell) {
            $address=(string)$cell['r'];
            if (!preg_match('/^('.$columns.')([1-9][0-9]*)$/D',$address,$position)) continue;
            if (isset($cell->f) && ($template==='FRC' || in_array($position[1],['D','E'],true))) {
                throw new DomainException('Use a value in '.$address.' for the '.$template.' import.');
            }
            $value=(string)$cell->v;
            $type=(string)$cell['t'];
            if ($type==='s' && $value!=='') {
                if (!isset($strings[$value])) throw new DomainException('Excel could not supply the value in '.$address.'.');
                $value=$strings[$value];
            } elseif ($type==='inlineStr') {
                $cell->registerXPathNamespace('m',$main);
                $value=implode('',array_map('strval',$cell->xpath('./m:is/m:t | ./m:is/m:r/m:t') ?: []));
            }
            if (trim($value)!=='') $rows[(int)$position[2]][$position[1]]=$value;
        }
    } finally {
        $zip->close();
    }

    $attendance=$template==='EVENT' ? synk_management_parse_event_attendance_rows($rows) : synk_management_parse_frc_rows($rows);
    return $attendance+['source'=>[
        'template'=>$template,
        'filename'=>basename(str_replace('\\','/',$filename)),
        'worksheet'=>$sheetName,
        'heading'=>$attendance['heading'],
        'sha256'=>hash_file('sha256',$path),
    ]];
}

function synk_management_parse_frc_rows(array $rows): array
{
    $heading=$rows[1]['A'] ?? '';
    if (!preg_match('/^FRC\s+([a-z]+)\s+([0-9]{1,2}),\s*([0-9]{4})$/iD',trim($heading),$parts)) {
        throw new DomainException('Enter the FRC date in A1 using a heading like FRC OCTOBER 10, 2026.');
    }
    $date=DateTimeImmutable::createFromFormat('!F j Y',$parts[1].' '.$parts[2].' '.$parts[3]);
    $errors=DateTimeImmutable::getLastErrors();
    if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) throw new DomainException('Review the FRC date in A1.');
    $entries=[];
    foreach ($rows as $row=>$values) {
        if ($row===1) continue;
        try {
            $entries[]=['student_number'=>synk_student_identity_number($values['A']),'cell'=>'A'.$row];
        } catch (DomainException $error) {
            throw new DomainException('Enter a valid student ID in A'.$row.' of the FRC worksheet.');
        }
    }
    if (!$entries) throw new DomainException('Enter student IDs in column A below the FRC heading.');
    return ['event_date'=>$date->format('Y-m-d'),'heading'=>$heading,'entries'=>$entries];
}

function synk_management_parse_event_attendance_rows(array $rows): array
{
    $hasHeading=false;
    $entries=[];
    $problems=[];
    $counts=['complete'=>0,'one_entry'=>0,'blank'=>0];
    foreach ($rows as $row=>$values) {
        $in=trim($values['D'] ?? '');
        $out=trim($values['E'] ?? '');
        if (strtoupper($in)==='IN' && strtoupper($out)==='OUT') {
            $hasHeading=true;
            continue;
        }
        if (!$hasHeading || preg_match('/^NO\.?$/iD',trim($values['A'] ?? ''))) continue;
        if ($in==='' && $out==='') {
            if (isset($values['B'],$values['C'])) $counts['blank']++;
            continue;
        }
        $cells=[];
        if ($in!=='') $cells[]='D'.$row;
        if ($out!=='') $cells[]='E'.$row;
        try {
            if ($in!=='') $in=synk_student_identity_number($in);
            if ($out!=='') $out=synk_student_identity_number($out);
        } catch (DomainException $error) {
            $problems[]=['cells'=>$cells,'reason'=>'Enter a student ID in the attendance cell.'];
            continue;
        }
        $complete=$in!=='' && $out!=='';
        if ($complete && $in!==$out) {
            $problems[]=['cells'=>$cells,'reason'=>'IN and OUT contain different student IDs.'];
            continue;
        }
        $counts[$complete ? 'complete' : 'one_entry']++;
        $entries[]=['student_number'=>$in!=='' ? $in : $out,'cells'=>$cells,'complete'=>$complete];
    }
    if (!$hasHeading) throw new DomainException('Use the EVENT template with IN in column D and OUT in column E.');
    if (!$entries && !$counts['blank']) throw new DomainException('The EVENT worksheet needs student rows below the IN and OUT headings.');
    return ['event_date'=>null,'heading'=>'IN / OUT','entries'=>$entries,'problems'=>$problems,'counts'=>$counts];
}

function synk_management_read_attendance_upload($file, string $template): array
{
    if (!is_array($file) || ($file['error'] ?? null)!==UPLOAD_ERR_OK || !is_string($file['tmp_name'] ?? null)
        || !is_string($file['name'] ?? null) || !is_uploaded_file($file['tmp_name'])) {
        throw new DomainException('Choose an Excel workbook to upload. The file limit is 5 MB.');
    }
    return synk_management_parse_attendance_workbook($file['tmp_name'],$file['name'],$template);
}
