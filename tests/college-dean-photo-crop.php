<?php
// Run: php tests/college-dean-photo-crop.php (GD enabled). No database changes.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../college-dean/faculty_photo_crop_helper.php';
$checks = 0;
function dean_crop_check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
$fixture = static function (): string {
    $path = tempnam(sys_get_temp_dir(), 'dean-crop-');
    $image = imagecreatetruecolor(300, 150);
    imagefilledrectangle($image, 0, 0, 149, 149, imagecolorallocate($image, 230, 20, 20));
    imagefilledrectangle($image, 150, 0, 299, 149, imagecolorallocate($image, 20, 20, 230));
    imagejpeg($image, $path, 95);
    imagedestroy($image);
    return $path;
};
$full = $fixture();
try {
    dean_crop_check(synk_dean_profile_crop_staged_image($full, '') === $full && getimagesize($full)[0] === 300, 'Keeping the full photo preserves its dimensions.');
} finally { if (is_file($full)) unlink($full); }
$staged = $fixture();
$cropped = null;
try {
    $cropped = synk_dean_profile_crop_staged_image($staged, json_encode(['x'=>.5, 'y'=>0, 'width'=>.5, 'height'=>1]));
    $info = getimagesize($cropped);
    dean_crop_check($info[0] === 150 && $info[1] === 150 && $info[2] === IMAGETYPE_JPEG, 'The chosen area is saved as a square JPEG without upscaling.');
    $image = imagecreatefromjpeg($cropped);
    $color = imagecolorat($image, 75, 75);
    imagedestroy($image);
    dean_crop_check(($color & 255) > 180 && (($color >> 16) & 255) < 70, 'The crop uses the selected blue region, not the default center.');
    dean_crop_check(!is_file($staged), 'Successful cropping removes the intermediate upload.');
} finally { foreach ([$staged, $cropped] as $path) if (is_string($path) && is_file($path)) unlink($path); }
$bad = [null, [], '{', '{}', str_repeat('x', 513),
    '{"x":-0.1,"y":0,"width":0.5,"height":1}', '{"x":0.8,"y":0,"width":0.5,"height":1}',
    '{"x":0,"y":0,"width":0,"height":0}', '{"x":"0","y":0,"width":0.5,"height":1}',
    '{"x":0,"y":0,"width":1,"height":1}', '{"x":0,"y":0,"width":1e309,"height":1}',
    '{"x":0,"y":0,"width":0.0001,"height":0.0002}'];
foreach ($bad as $json) {
    $path = $fixture();
    try {
        synk_dean_profile_crop_staged_image($path, $json);
        throw new RuntimeException('Invalid crop was accepted.');
    } catch (InvalidArgumentException $exception) {
        dean_crop_check(!is_file($path), 'Invalid crops are rejected and intermediate files are removed.');
    } finally { if (is_file($path)) unlink($path); }
}
dean_crop_check(synk_dean_profile_stage_image(null, 0, 123, '') === null, 'Saving without a new photo remains supported.');
try {
    synk_dean_profile_stage_image(null, 0, 123, '{"x":0}');
    throw new RuntimeException('Crop without an upload was accepted.');
} catch (InvalidArgumentException $exception) { $checks++; }
$rounded = synk_dean_profile_crop_rectangle('{"x":0.25,"y":0,"width":0.5,"height":1}', 1200, 599);
dean_crop_check($rounded['size'] === 599, 'Crop coordinates tolerate shared image resize rounding.');
echo 'Dean photo crop: ', $checks, ' checks passed.', PHP_EOL;
