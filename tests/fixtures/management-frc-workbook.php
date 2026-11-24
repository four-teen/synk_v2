<?php
function synk_test_write_frc_workbook(string $path, string $date, array $numbers): void
{
    $heading='FRC '.strtoupper((new DateTimeImmutable($date))->format('F j, Y'));
    $rows='<row r="1"><c r="A1" t="inlineStr"><is><t>'.$heading.'</t></is></c></row>';
    foreach ($numbers as $index=>$number) {
        $row=$index+2;
        $rows.='<row r="'.$row.'"><c r="A'.$row.'"><v>'.(string)$number.'</v></c></row>';
    }
    synk_test_write_attendance_workbook($path,'FRC source tab',$rows);
}

function synk_test_write_cud_workbook(string $path,array $studentRows): void
{
    $rows='';
    foreach (array_merge([['A'=>'NO.','B'=>'SURNAME','C'=>'FIRSTNAME','D'=>'IN','E'=>'OUT']],$studentRows) as $index=>$cells) {
        $row=$index+1;
        $rows.='<row r="'.$row.'">';
        foreach ($cells as $column=>$value) {
            $rows.='<c r="'.$column.$row.'" t="inlineStr"><is><t>'.htmlspecialchars($value,ENT_XML1,'UTF-8').'</t></is></c>';
        }
        $rows.='</row>';
    }
    synk_test_write_attendance_workbook($path,'CLEAN UP DRIVE',$rows);
}

function synk_test_write_attendance_workbook(string $path,string $sheet,string $rows): void
{
    $zip=new ZipArchive();
    if ($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('Unable to write the attendance workbook fixture.');
    $main='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $rel='http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $zip->addFromString('xl/workbook.xml','<workbook xmlns="'.$main.'" xmlns:r="'.$rel.'"><sheets><sheet name="'.$sheet.'" sheetId="1" r:id="sheet"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels','<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="sheet" Type="'.$rel.'/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml','<worksheet xmlns="'.$main.'"><sheetData>'.$rows.'</sheetData></worksheet>');
    if (!$zip->close()) throw new RuntimeException('Unable to close the attendance workbook fixture.');
}
