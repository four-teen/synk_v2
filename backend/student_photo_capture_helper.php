<?php
// Camera workflow checks, not proof of identity or liveness. Face detection runs
// in the browser; its report can be forged by a custom client. PHP independently
// validates the session challenge, uploaded bytes, framing and pixel quality.
const SYNK_STUDENT_CAPTURE_TTL = 600;

function synk_student_photo_begin_capture(int $studentId): array
{
    $token = bin2hex(random_bytes(32));
    $_SESSION['student_photo_capture'] = ['student_id'=>$studentId, 'hash'=>hash('sha256',$token), 'started_at'=>time()];
    return ['capture_token'=>$token, 'expires_in'=>SYNK_STUDENT_CAPTURE_TTL];
}

function synk_student_photo_capture_report(int $studentId, ?array $upload, array $capture): array
{
    $session = $_SESSION['student_photo_capture'] ?? [];
    $token = $capture['capture_token'] ?? null;
    if (!is_string($token) || strlen($token)!==64 || (int)($session['student_id'] ?? 0)!==$studentId
        || time()-(int)($session['started_at'] ?? 0)>SYNK_STUDENT_CAPTURE_TTL
        || !hash_equals((string)($session['hash'] ?? ''),hash('sha256',$token))) {
        throw new InvalidArgumentException('Open the camera and take a new photo before saving. Your capture may have expired.');
    }
    $json = $capture['capture_report'] ?? null;
    $report = is_string($json) && strlen($json)<8192 ? json_decode($json,true) : null;
    if (!is_array($report) || ($report['face_count'] ?? null)!==1 || !is_array($report['face'] ?? null)) {
        throw new InvalidArgumentException('Take a new photo with exactly one clearly visible face.');
    }
    $face = $report['face'];
    foreach (['x','y','width','height'] as $key) {
        if (!isset($face[$key]) || !is_numeric($face[$key]) || !is_finite((float)$face[$key])) {
            throw new InvalidArgumentException('Center your face in the guide and take a new photo.');
        }
        $face[$key] = (float)$face[$key];
    }
    $x=$face['x']; $y=$face['y']; $w=$face['width']; $h=$face['height'];
    if ($w<.20 || $w>.65 || $h<.20 || $h>.68 || $x<.05 || $y<.05 || $x+$w>.95 || $y+$h>.85
        || abs($x+$w/2-.5)>.18) {
        throw new InvalidArgumentException('Center your whole face in the guide, leaving space above your head.');
    }
    $points = $face['keypoints'] ?? [];
    if (!is_array($points) || !isset($points[0],$points[1],$points[2])) throw new InvalidArgumentException('Look straight at the camera and take a new photo.');
    foreach (array_slice($points,0,3) as $point) {
        if (!is_array($point) || !is_numeric($point['x'] ?? null) || !is_numeric($point['y'] ?? null)
            || !is_finite((float)$point['x']) || !is_finite((float)$point['y'])
            || $point['x']<$x || $point['x']>$x+$w || $point['y']<$y || $point['y']>$y+$h) {
            throw new InvalidArgumentException('Keep both eyes and your nose visible, facing the camera.');
        }
    }
    $eyeSpan=abs($points[0]['x']-$points[1]['x']);
    if ($eyeSpan<$w*.18 || abs($points[0]['y']-$points[1]['y'])>$w*.18
        || abs($points[2]['x']-($points[0]['x']+$points[1]['x'])/2)>$eyeSpan*.45) {
        throw new InvalidArgumentException('Look straight at the camera and keep your head level.');
    }
    $path=$upload['tmp_name'] ?? '';
    if (($upload['error'] ?? null)!==UPLOAD_ERR_OK || !is_string($path) || !is_uploaded_file($path)) {
        throw new InvalidArgumentException('Your captured photo did not arrive. Please retake it and try again.');
    }
    $hash=$report['sha256'] ?? '';
    if (!is_string($hash) || !preg_match('/^[a-f0-9]{64}$/D',$hash) || !hash_equals(hash_file('sha256',$path),$hash)) {
        throw new InvalidArgumentException('The photo changed after its checks. Please capture it again.');
    }
    return $face;
}

function synk_student_photo_check_quality(string $path, array $face): void
{
    $source=@imagecreatefromjpeg($path);
    if (!$source) throw new InvalidArgumentException('Your captured photo could not be read. Please retake it.');
    $sample=null;
    try {
        if (imagesx($source)<480 || imagesy($source)<480 || imagesx($source)!==imagesy($source)) {
            throw new InvalidArgumentException('Use a camera with at least 480 by 480 pixels and keep the square framing.');
        }
        $sample=imagecreatetruecolor(160,160);
        imagecopyresampled($sample,$source,0,0,0,0,160,160,imagesx($source),imagesy($source));
        // Judge visibility inside the detected face only. A student's
        // surroundings, wall color or background shadows do not affect saving.
        $grey=[]; $faceLight=[]; $laplacians=[];
        for ($y=0;$y<160;$y++) {
            $grey[$y]=[];
            for ($x=0;$x<160;$x++) {
                $rgb=imagecolorat($sample,$x,$y);
                $grey[$y][$x]=.299*(($rgb>>16)&255)+.587*(($rgb>>8)&255)+.114*($rgb&255);
            }
        }
        $left=max(1,(int)(($face['x']+$face['width']*.12)*160));
        $right=min(158,(int)(($face['x']+$face['width']*.88)*160));
        $top=max(1,(int)(($face['y']+$face['height']*.12)*160));
        $bottom=min(158,(int)(($face['y']+$face['height']*.88)*160));
        for ($y=$top;$y<=$bottom;$y++) for ($x=$left;$x<=$right;$x++) {
            $faceLight[]=$grey[$y][$x];
            $laplacians[]=$grey[$y-1][$x]+$grey[$y+1][$x]+$grey[$y][$x-1]+$grey[$y][$x+1]-4*$grey[$y][$x];
        }
        $mean=static function(array $values):float { return array_sum($values)/max(1,count($values)); };
        $variance=static function(array $values)use($mean):float { $average=$mean($values); $sum=0; foreach($values as$value)$sum+=($value-$average)**2; return $sum/max(1,count($values)); };
        $light=$mean($faceLight);
        if ($light<45 || $light>235) throw new InvalidArgumentException('Use even lighting so your face is clearly visible, then retake the photo.');
        if ($variance($laplacians)<22) throw new InvalidArgumentException('Your photo looks blurred. Clean the camera, hold still and retake it.');
    } finally {
        imagedestroy($source);
        if ($sample) imagedestroy($sample);
    }
}
