<?php
require_once __DIR__.'/faculty_profile_image_helper.php';
require_once __DIR__.'/student_photo_capture_helper.php';

function synk_student_profile_image_path(int $studentId): string
{
    if ($studentId<1) throw new InvalidArgumentException('Invalid student profile.');
    return dirname(__DIR__).'/uploads/student-profile/student_'.$studentId.'.jpg';
}

function synk_student_profile_image_url(int $studentId): string
{
    if ($studentId<1) return '';
    $path=synk_student_profile_image_path($studentId);
    clearstatcache(true,$path);
    if (!is_file($path) || !is_readable($path) || filesize($path)===0) return '';
    $info=@getimagesize($path);
    if (!$info || $info[2]!==IMAGETYPE_JPEG) return '';
    return '../uploads/student-profile/'.basename($path).'?v='.substr(hash_file('sha256',$path),0,16);
}

// The caller supplies the authenticated directory ID, never a posted student ID.
function synk_student_profile_save_image(mysqli $conn,int $studentId,?array $upload,array $capture=[]): void
{
    $face=synk_student_photo_capture_report($studentId,$upload,$capture);
    $path=synk_student_profile_image_path($studentId);
    $lockName='synk_student_photo_'.$studentId;
    $stmt=$conn->prepare('SELECT GET_LOCK(?,5) AS acquired');
    $stmt->bind_param('s',$lockName);$stmt->execute();
    $locked=(int)$stmt->get_result()->fetch_assoc()['acquired']===1;$stmt->close();
    if (!$locked) throw new RuntimeException('Your photo is being updated. Please try again.');
    $staged=null;
    try {
        // Reuse the existing upload validation, orientation correction and JPEG
        // encoder. Staging never writes a faculty/account image or database row.
        $staged=synk_faculty_profile_stage_image($upload,$studentId);
        if ($staged===null) throw new InvalidArgumentException('Choose a profile photo to continue.');
        synk_student_photo_check_quality($staged,$face);
        $directory=dirname($path);
        if (!is_dir($directory) && !mkdir($directory,0755,true) && !is_dir($directory)) throw new RuntimeException('Student photo storage is unavailable.');
        // tempnam() creates an owner-only file (0600). The web server must be
        // able to serve the validated JPEG after it replaces the public photo.
        // Prepare permissions first so a failed change preserves the old image.
        if (!chmod($staged,0644)) throw new RuntimeException('Student photo permissions could not be prepared.');
        // Replace only after the new photo has been decoded and encoded safely.
        // One fixed JPEG path prevents an accumulation of previous photos.
        if (!rename($staged,$path)) throw new RuntimeException('Your photo could not be replaced. Please try again.');
        $staged=null;clearstatcache(true,$path);
        unset($_SESSION['student_photo_capture']);
    } finally {
        if (is_string($staged) && is_file($staged)) unlink($staged);
        $stmt=$conn->prepare('SELECT RELEASE_LOCK(?)');$stmt->bind_param('s',$lockName);$stmt->execute();$stmt->close();
    }
}
