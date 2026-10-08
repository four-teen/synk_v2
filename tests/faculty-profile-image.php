<?php
// Run: php tests/faculty-profile-image.php [--http|--apache]
// Uses a random, unregistered account ID and removes only its own test files.
// The optional HTTP router works only in a token-protected, loopback CLI server.
if (PHP_SAPI === 'cli-server' || isset($synkImageTestApache)) {
    $token = (string)getenv('SYNK_IMAGE_TEST_TOKEN');
    if ($token === '' || !hash_equals($token, (string)($_SERVER['HTTP_X_SYNK_TEST_TOKEN'] ?? '')) || !in_array($_SERVER['REMOTE_ADDR'], ['127.0.0.1', '::1'], true)) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: application/json');
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        echo json_encode(['ready' => true, 'php_version' => PHP_VERSION, 'fileinfo' => class_exists('finfo'),
            'upload_max_filesize'=>ini_get('upload_max_filesize'), 'post_max_size'=>ini_get('post_max_size')]);
        exit;
    }
    require_once __DIR__ . '/../backend/faculty_profile_image_helper.php';
    $testUserId = (int)getenv('SYNK_IMAGE_TEST_USER_ID');
    $staged = null;
    try {
        $staged = synk_faculty_profile_stage_image($_FILES['profile_image'] ?? null, $testUserId);
        if ($staged !== null) {
            synk_faculty_profile_publish_image($staged, $testUserId);
        }
        $path = synk_faculty_profile_image_path($testUserId);
        $info = getimagesize($path);
        echo json_encode(['type' => $info[2], 'width' => $info[0], 'height' => $info[1], 'hash' => hash_file('sha256', $path), 'count' => count(glob(dirname($path) . '/profile_' . $testUserId . '.*'))]);
    } catch (InvalidArgumentException $exception) {
        http_response_code(422);
        echo json_encode(['error' => $exception->getMessage()]);
    } finally {
        if ($staged !== null && is_file($staged)) {
            unlink($staged);
        }
    }
    exit;
}
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../backend/faculty_profile_image_helper.php';
require_once __DIR__ . '/../backend/user_avatar_helper.php';

$checks = 0;
function image_check(bool $condition, string $description): void
{
    global $checks;
    if (!$condition) {
        throw new RuntimeException($description);
    }
    $checks++;
}
function image_rejected(callable $operation, string $description): void
{
    try {
        $operation();
    } catch (InvalidArgumentException $exception) {
        image_check(true, $description);
        return;
    }
    throw new RuntimeException($description);
}

$userId = random_int(2000000000, 2147483647);
$path = synk_faculty_profile_image_path($userId);
image_check(!file_exists($path), 'A test must never overwrite an existing account image.');
$fixtures = [];
$server = null;
try {
    image_check(synk_faculty_profile_stage_image(null, $userId) === null, 'No upload keeps the saved image.');
    image_check(synk_faculty_profile_stage_image(['error' => UPLOAD_ERR_NO_FILE], $userId) === null, 'An empty file input keeps the saved image.');
    image_rejected(static function () use ($userId) { return synk_faculty_profile_stage_image(['error' => UPLOAD_ERR_INI_SIZE], $userId); }, 'Reject oversized HTTP uploads.');
    image_rejected(static function () use ($userId) { return synk_faculty_profile_stage_image(['error' => UPLOAD_ERR_PARTIAL], $userId); }, 'Reject partial HTTP uploads.');
    image_rejected(static function () use ($userId) { return synk_faculty_profile_stage_image(['error' => UPLOAD_ERR_OK, 'tmp_name' => __FILE__], $userId); }, 'Reject a local path forged as an HTTP upload.');
    image_rejected(static function () { return synk_faculty_profile_image_info(__FILE__); }, 'Reject executable text disguised as an image.');
    $image = imagecreatetruecolor(48, 48);
    imagefill($image, 0, 0, imagecolorallocate($image, 32, 85, 150));
    foreach (['imagejpeg', 'imagepng', 'imagewebp'] as $encoder) {
        $fixture = tempnam(dirname($path), '.image-test-');
        $fixtures[] = $fixture;
        $encoder($image, $fixture);
        $info = synk_faculty_profile_image_info($fixture);
        image_check($info[0] === 48 && $info[1] === 48, 'Accept real ' . $encoder . ' data without trusting the extension.');
    }
    $first = $fixtures[0];
    $firstHash = hash_file('sha256', $first);
    $replacement = synk_faculty_profile_publish_image($first, $userId);
    image_check(hash_file('sha256', $path) === $firstHash && !file_exists($first), 'Publish exactly one fixed image file.');
    $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/professor/manage-profile.php';
    $_SERVER['SCRIPT_NAME'] = '/synk/professor/manage-profile.php';
    $urlBefore = synk_faculty_profile_image_url($userId);
    image_check(strpos($urlBefore, '/synk/uploads/profile/profile_' . $userId . '.jpg?v=') === 0, 'Build the local avatar URL under the app prefix.');
    $_SESSION['user_id'] = $userId;
    image_check(synk_resolve_user_avatar_url('fixture@example.test', 'https://example.test/avatar.jpg') === $urlBefore, 'Uploaded image takes priority over Google and Gravatar avatars.');
    $next = tempnam(dirname($path), '.image-test-');
    $fixtures[] = $next;
    imagefill($image, 0, 0, imagecolorallocate($image, 180, 70, 30));
    imagejpeg($image, $next);
    imagedestroy($image);
    $secondHash = hash_file('sha256', $next);
    $second = synk_faculty_profile_publish_image($next, $userId);
    image_check($firstHash !== $secondHash && hash_file('sha256', $path) === $secondHash, 'A second upload overwrites the previous file.');
    image_check(count(glob(dirname($path) . '/profile_' . $userId . '.*')) === 1, 'Replacement leaves one image for the account.');
    image_check(synk_faculty_profile_image_url($userId) !== $urlBefore, 'Replacement changes the cache version even within the same second.');
    synk_faculty_profile_restore_image($second);
    image_check(hash_file('sha256', $path) === $firstHash, 'Failed save restores the exact previous image bytes.');
    $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__) . '/administrator/students/index.php';
    $_SERVER['SCRIPT_NAME'] = '/synk/administrator/students/index.php';
    image_check(synk_faculty_profile_image_url($userId) === $urlBefore, 'Nested portals resolve the same image URL.');
    synk_faculty_profile_restore_image($replacement);
    image_check(!file_exists($path), 'A failed first save leaves no image.');
    $oversized = tempnam(dirname($path), '.image-test-');
    $fixtures[] = $oversized;
    file_put_contents($oversized, str_repeat('x', SYNK_PROFILE_IMAGE_MAX_BYTES + 1));
    image_rejected(static function () use ($oversized) { return synk_faculty_profile_image_info($oversized); }, 'Enforce the actual file size limit.');
    if (in_array('--http', $argv, true) || in_array('--apache', $argv, true)) {
        $port = random_int(19000, 25000);
        $token = bin2hex(random_bytes(24));
        if (in_array('--apache', $argv, true)) {
            $directory = dirname(__DIR__) . '/tmp';
            if (!is_dir($directory)) mkdir($directory, 0755, true);
            $router = $directory . '/image-upload-test-' . bin2hex(random_bytes(12)) . '.php';
            $fixtures[] = $router;
            $source = '<?php $synkImageTestApache = true; putenv(' . var_export('SYNK_IMAGE_TEST_TOKEN=' . $token, true) . '); putenv(' . var_export('SYNK_IMAGE_TEST_USER_ID=' . $userId, true) . '); require ' . var_export(__FILE__, true) . ';';
            image_check(file_put_contents($router, $source) !== false, 'Create a temporary token-protected Apache upload test.');
            $url = 'http://localhost/synk/tmp/' . basename($router);
        } else {
            $log = tempnam(sys_get_temp_dir(), 'synk-image-test-');
            $fixtures[] = $log;
            $environment = array_merge(getenv(), ['SYNK_IMAGE_TEST_TOKEN' => $token, 'SYNK_IMAGE_TEST_USER_ID' => (string)$userId]);
            $command = [PHP_BINARY, '-d', 'upload_tmp_dir=' . sys_get_temp_dir(), '-S', '127.0.0.1:' . $port, __FILE__];
            // Array commands were introduced after the production PHP version.
            if (PHP_VERSION_ID < 70400) $command = implode(' ', array_map('escapeshellarg', $command));
            $server = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, dirname(__DIR__), $environment);
            image_check(is_resource($server), 'Start an isolated loopback PHP upload server.');
            fclose($pipes[0]);
            $url = 'http://127.0.0.1:' . $port . '/';
        }
        $request = static function (?string $file = null) use ($url, $token): array {
            $curl = curl_init($url);
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_HTTPHEADER => ['X-Synk-Test-Token: ' . $token]]);
            if ($file !== null) {
                curl_setopt($curl, CURLOPT_POSTFIELDS, ['profile_image' => new CURLFile($file, 'image/png', 'photo.png')]);
            }
            $body = curl_exec($curl);
            $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            curl_close($curl);
            if ($status !== 0 && is_string($body) && !is_array(json_decode($body, true))) {
                throw new RuntimeException('Upload server response (' . $status . '): ' . substr($body, 0, 2000));
            }
            return [$status, is_string($body) ? json_decode($body, true) : null];
        };
        for ($attempt = 0; $attempt < 40; $attempt++) {
            [$status, $result] = $request();
            if ($status === 200) break;
            usleep(50000);
        }
        image_check($status === 200 && $result['ready'], 'The HTTP test server is ready.');
        echo 'HTTP upload runtime: PHP ', $result['php_version'], '; Fileinfo ', $result['fileinfo'] ? 'enabled' : 'disabled', PHP_EOL;
        echo 'Profile image limit: 5 MB; server upload_max_filesize=', $result['upload_max_filesize'], '; post_max_size=', $result['post_max_size'], PHP_EOL;
        $large = tempnam(dirname($path), '.image-test-');
        $fixtures[] = $large;
        $image = imagecreatetruecolor(1800, 1200);
        imagefill($image, 0, 0, imagecolorallocate($image, 40, 120, 90));
        imagejpeg($image, $large);
        imagedestroy($image);
        foreach ([$fixtures[1], $fixtures[2], $large] as $upload) {
            [$status, $result] = $request($upload);
            image_check($status === 200 && ($result['type'] ?? null) === IMAGETYPE_JPEG, 'A real multipart image upload becomes a JPEG. HTTP ' . $status . ': ' . json_encode($result));
            image_check(max($result['width'], $result['height']) <= 1200 && $result['count'] === 1, 'Real replacements are resized and keep one saved image.');
        }
        $savedHash = hash_file('sha256', $path);
        [$status, $result] = $request(__FILE__);
        image_check($status === 422 && isset($result['error']), 'Reject real HTTP uploads with forged names and MIME types.');
        image_check(hash_file('sha256', $path) === $savedHash, 'Rejected HTTP upload preserves the previous image.');
        [$status, $result] = $request($oversized);
        image_check($status === 422 && hash_file('sha256', $path) === $savedHash, 'Oversized HTTP upload preserves the previous image.');
    }
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    foreach (array_merge($fixtures, [$path]) as $fixture) {
        if (is_file($fixture)) {
            unlink($fixture);
        }
    }
}
echo 'Faculty profile images: ', $checks, ' checks passed.', PHP_EOL;
