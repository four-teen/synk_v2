<?php
const SYNK_HR_PDS_FILE_MAX_BYTES=2097152;
const SYNK_HR_PDS_UPLOAD_MAX_BYTES=6291456;

function synk_hr_pds_attachment_slots(): array
{
    // Photographs, signatures and thumbmarks are attached to the printed PDS.
    return [];
}

// Inspect file contents, then re-encode images to discard embedded payloads and metadata.
function synk_hr_pds_attachment_content(string $slot, string $path, string $name): array
{
    if (!in_array($slot,synk_hr_pds_attachment_slots(),true)) throw new InvalidArgumentException('Unsupported PDS attachment.');
    $size=is_file($path)?filesize($path):0;
    if ($size<=0 || $size>SYNK_HR_PDS_FILE_MAX_BYTES) throw new InvalidArgumentException('Choose a file up to 2 MB.');
    $bytes=file_get_contents($path); $mime='';
    $info=@getimagesize($path);
    if ($info && in_array($info[2],[IMAGETYPE_JPEG,IMAGETYPE_PNG,IMAGETYPE_WEBP],true)) {
        if ($info[0]>6000 || $info[1]>6000 || $info[0]*$info[1]>16000000) throw new InvalidArgumentException('Choose an image up to 6,000 pixels per side and 16 megapixels.');
        if (!function_exists('imagecreatefromstring')) throw new RuntimeException('Image processing is unavailable.');
        $image=@imagecreatefromstring($bytes);
        if (!$image) throw new InvalidArgumentException('This image could not be read. Choose a JPG or PNG.');
        ob_start();
        try {
            $png=$info[2]===IMAGETYPE_PNG;
            if ($png) { imagesavealpha($image,true); $ok=imagepng($image); }
            else $ok=imagejpeg($image,null,90);
            $bytes=ob_get_contents(); $mime=$png?'image/png':'image/jpeg';
        } finally { ob_end_clean(); imagedestroy($image); }
        if (!$ok || !$bytes || strlen($bytes)>SYNK_HR_PDS_FILE_MAX_BYTES) throw new InvalidArgumentException('Choose a smaller image, up to 2 MB after processing.');
    } elseif (!in_array($slot,['photo','thumbmark'],true) && substr($bytes,0,5)==='%PDF-' && strpos(substr($bytes,-2048),'%%EOF')!==false) {
        $mime='application/pdf';
    } elseif (!in_array($slot,['photo','thumbmark'],true) && strtolower(pathinfo($name,PATHINFO_EXTENSION))==='p7s') {
        $der=$bytes;
        if (strpos($bytes,'-----BEGIN PKCS7-----')===0) $der=base64_decode(preg_replace('/-----[^\r\n]+-----|\s/','',$bytes),true);
        if (!is_string($der) || substr($der,0,1)!=="\x30" || strpos(substr($der,0,40),"\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x07\x02")===false) throw new InvalidArgumentException('Choose a signed-data P7S file, a PDF, or an image.');
        $mime='application/pkcs7-signature';
    } else throw new InvalidArgumentException(in_array($slot,['photo','thumbmark'],true)?'Choose a valid JPG, PNG, or WebP image.':'Choose a valid image, PDF, or P7S file.');
    $name=basename(str_replace('\\','/',$name));
    $name=preg_replace('/[\x00-\x1f\x7f]/','',$name);
    if (!preg_match('//u',$name)) $name='attachment';
    $name=function_exists('mb_strcut')?mb_strcut($name,0,200,'UTF-8'):substr(preg_replace('/[^\x20-\x7e]/','_',$name),0,200);
    $extension=['image/png'=>'png','image/jpeg'=>'jpg','application/pdf'=>'pdf','application/pkcs7-signature'=>'p7s'][$mime];
    $name=pathinfo($name,PATHINFO_FILENAME).'.'.$extension;
    return ['name'=>$name,'mime'=>$mime,'size'=>strlen($bytes),'sha256'=>hash('sha256',$bytes),'content'=>$bytes];
}

function synk_hr_pds_prepare_attachments(array $uploads, $removed): array
{
    if ($uploads || !is_array($removed) || $removed) return [[],[],['_attachments'=>'Photographs, signatures and thumbmarks belong on the printed PDS. No file upload is needed.']];
    $errors=[]; $prepared=[]; $slots=synk_hr_pds_attachment_slots(); $total=0;
    if (!is_array($removed) || count($removed)>count($slots) || ($removed && array_keys($removed)!==range(0,count($removed)-1))) return [[],[],['_attachments'=>'Reload before changing attachments.']];
    foreach ($removed as $slot) if (!is_string($slot) || !in_array($slot,$slots,true)) return [[],[],['_attachments'=>'An attachment slot is invalid.']];
    foreach ($uploads as $slot=>$upload) {
        if (!in_array($slot,$slots,true)) { $errors['_attachments']='An uploaded file is not a supported PDS attachment.'; continue; }
        if (!is_array($upload) || !is_int($upload['error'] ?? null)) { $errors[$slot]='The file could not be read. Choose it again.'; continue; }
        if ($upload['error']===UPLOAD_ERR_NO_FILE) continue;
        if ($upload['error']!==UPLOAD_ERR_OK || !is_string($upload['tmp_name'] ?? null) || !is_uploaded_file($upload['tmp_name'])) { $errors[$slot]='The upload failed. Choose a file up to 2 MB and retry.'; continue; }
        if (in_array($slot,$removed,true)) { $errors[$slot]='Choose either a replacement file or removal, then save again.'; continue; }
        try {
            $prepared[$slot]=synk_hr_pds_attachment_content($slot,$upload['tmp_name'],(string)($upload['name'] ?? 'attachment'));
            $total+=max(filesize($upload['tmp_name']),$prepared[$slot]['size']);
        } catch (InvalidArgumentException $error) { $errors[$slot]=$error->getMessage(); }
    }
    if ($total>SYNK_HR_PDS_UPLOAD_MAX_BYTES) $errors['_attachments']='Upload up to 6 MB at a time. Save a few files, then add the rest.';
    return [$prepared,array_values(array_unique($removed)),$errors];
}

function synk_hr_pds_attachments(mysqli $conn, int $profileId): array
{
    if (!synk_hr_pds_attachment_slots()) return [];
    $statement=$conn->prepare('SELECT slot,original_name,mime_type,byte_size,sha256,updated_at FROM tbl_hr_faculty_pds_attachments WHERE hr_profile_id=? ORDER BY slot');
    $statement->bind_param('i',$profileId); $statement->execute(); $rows=$statement->get_result(); $files=[];
    while ($row=$rows->fetch_assoc()) {
        if (!in_array($row['slot'],synk_hr_pds_attachment_slots(),true)) continue;
        $files[$row['slot']]=['name'=>$row['original_name'],'mime'=>$row['mime_type'],'size'=>(int)$row['byte_size'],'sha256'=>$row['sha256'],'updated_at'=>$row['updated_at']];
    }
    $statement->close(); return $files;
}

// Called only after ownership and the Certification revision were checked, in its transaction.
function synk_hr_pds_save_attachments(mysqli $conn, int $profileId, int $userId, array $files, array $removed): void
{
    foreach (array_merge(array_keys($files),$removed) as $slot) {
        if (!in_array($slot,synk_hr_pds_attachment_slots(),true)) throw new InvalidArgumentException('Unsupported PDS attachment.');
    }
    foreach ($removed as $slot) {
        $statement=$conn->prepare('DELETE FROM tbl_hr_faculty_pds_attachments WHERE hr_profile_id=? AND slot=?');
        $statement->bind_param('is',$profileId,$slot); $statement->execute(); $statement->close();
    }
    foreach ($files as $slot=>$file) {
        $statement=$conn->prepare('INSERT INTO tbl_hr_faculty_pds_attachments (hr_profile_id,slot,original_name,mime_type,byte_size,sha256,content,updated_by) VALUES (?,?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE original_name=VALUES(original_name),mime_type=VALUES(mime_type),byte_size=VALUES(byte_size),sha256=VALUES(sha256),content=VALUES(content),updated_at=CURRENT_TIMESTAMP,updated_by=VALUES(updated_by)');
        $statement->bind_param('isssissi',$profileId,$slot,$file['name'],$file['mime'],$file['size'],$file['sha256'],$file['content'],$userId);
        $statement->execute(); $statement->close();
    }
}
