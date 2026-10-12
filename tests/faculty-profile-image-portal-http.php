<?php
// Run: php tests/faculty-profile-image-portal-http.php
// Exercise the actual professor form on Apache with an isolated, temporary account.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/faculty_profile_helper.php';
require_once __DIR__ . '/../backend/faculty_profile_image_helper.php';
require_once __DIR__ . '/../backend/auth_useraccount.php';
$checks = 0;
function profile_image_portal_check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
$root = dirname(__DIR__);
$temporaryDirectory = $root . '/tmp';
if (!is_dir($temporaryDirectory)) mkdir($temporaryDirectory, 0755, true);
$tag = 'ProfileImageQA' . bin2hex(random_bytes(12));
$email = strtolower($tag) . '@example.test';
$token = bin2hex(random_bytes(24));
$route = $temporaryDirectory . '/profile-image-portal-' . bin2hex(random_bytes(12)) . '.php';
$cookieJar = tempnam($temporaryDirectory, 'profile-image-cookie-');
$fixtures = [$route, $cookieJar];
$userId = null;
$imagePath = null;
$ownsImagePath = false;
try {
    $stmt = $conn->prepare("INSERT INTO tbl_useraccount (username, email, password, role, status) VALUES (?, ?, 'unusable-test-password', 'admin', 'active')");
    $stmt->bind_param('ss', $tag, $email);
    $stmt->execute();
    $userId = (int)$conn->insert_id;
    $stmt->close();
    $conn->query("INSERT INTO tbl_useraccount_roles (user_id, role, is_primary, status) VALUES ({$userId}, 'professor', 1, 'active')");
    $imagePath = synk_faculty_profile_image_path($userId);
    profile_image_portal_check(!is_file($imagePath), 'A QA account must never overwrite an existing picture.');
    $ownsImagePath = true;
    $fixturePath = __DIR__ . '/fixtures/faculty-profile-image-portal-session.php';
    file_put_contents($route, '<?php define(\'SYNK_PROFILE_IMAGE_PORTAL_QA\', ' . var_export($token, true)
        . '); define(\'SYNK_PROFILE_IMAGE_PORTAL_USER\', ' . $userId . '); require ' . var_export($fixturePath, true) . ';');
    $request = static function (string $url, ?array $post = null) use ($cookieJar): array {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_TIMEOUT => 15,
            CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_FOLLOWLOCATION => false]);
        if ($post !== null) curl_setopt($curl, CURLOPT_POSTFIELDS, $post);
        $response = curl_exec($curl);
        if ($response === false) throw new RuntimeException('HTTP request failed: ' . curl_error($curl));
        $status = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
        curl_close($curl);
        return ['status' => $status, 'headers' => substr($response, 0, $headerSize), 'body' => substr($response, $headerSize)];
    };
    $bootstrapUrl = 'http://localhost/synk/tmp/' . basename($route);
    $guard = $request($bootstrapUrl . '?qa=invalid');
    profile_image_portal_check($guard['status'] === 404, 'Session fixture rejects requests without its random token.');
    $get = $request($bootstrapUrl . '?qa=' . rawurlencode($token));
    profile_image_portal_check($get['status'] === 200 && strpos($get['body'], 'id="faculty-profile-form"') !== false, 'The authenticated professor sees the real profile form.');
    profile_image_portal_check(strpos($get['body'], 'enctype="multipart/form-data"') !== false, 'The profile form submits actual multipart uploads.');
    profile_image_portal_check(strpos($get['body'], 'faculty-photo-crop-template') !== false && strpos($get['body'], 'assets/js/faculty-photo-crop.js') !== false
        && strpos($get['body'], 'name="profile_photo_crop"') !== false, 'The Professor profile editor loads the crop preview with its own upload field.');
    preg_match('/name="csrf_token" value="([a-f0-9]+)"/', $get['body'], $csrf);
    profile_image_portal_check(isset($csrf[1]), 'The profile form supplies its real CSRF token.');
    echo trim(preg_replace('/\r?\n/', '; ', preg_replace('/.*?(X-Synk-Image-QA-PHP:.*?X-Synk-Image-QA-Fileinfo:[^\r\n]*).*/s', '$1', $get['headers']))), PHP_EOL;
    $profileUrl = 'http://localhost/synk/professor/manage-profile.php';
    $basePost = array_fill_keys(array_keys(synk_faculty_profile_fields()), '');
    $basePost['full_name'] = $tag;
    $basePost['csrf_token'] = $csrf[1];
    preg_match('/name="profile_owner" value="([^"]+)"/', $get['body'], $owner);
    profile_image_portal_check(isset($owner[1]), 'The form identifies its current profile owner.');
    $basePost['profile_owner'] = $owner[1];
    $basePost['revision'] = '0';
    $image = imagecreatetruecolor(1800, 1200);
    $savedHashes = [];
    foreach (['imagepng' => 'image/png', 'imagejpeg' => 'image/jpeg', 'imagewebp' => 'image/webp'] as $encoder => $mime) {
        $fixture = tempnam($temporaryDirectory, 'profile-image-fixture-');
        $fixtures[] = $fixture;
        imagefill($image, 0, 0, imagecolorallocate($image, 40 + count($savedHashes) * 60, 95, 145));
        $encoder($image, $fixture);
        $post = $basePost;
        $post['revision'] = (string)count($savedHashes);
        $post['profile_image'] = new CURLFile($fixture, $mime, 'photo.' . substr($mime, 6));
        $save = $request($profileUrl, $post);
        profile_image_portal_check($save['status'] === 303 && strpos($save['headers'], 'Location: manage-profile.php') !== false,
            'The real form saves ' . $mime . ' successfully; received HTTP ' . $save['status'] . ': ' . substr(strip_tags($save['body']), 0, 500));
        $stored = synk_faculty_profile_fetch($conn, $userId);
        profile_image_portal_check($stored['full_name'] === $tag && (int)$stored['revision'] === count($savedHashes) + 1, 'Profile details and revision save with the picture.');
        $info = getimagesize($imagePath);
        profile_image_portal_check($info[2] === IMAGETYPE_JPEG && $info[0] === 1200 && $info[1] === 800, 'Uploaded pictures are re-encoded and resized to 1200 pixels.');
        $hash = hash_file('sha256', $imagePath);
        profile_image_portal_check(!in_array($hash, $savedHashes, true) && count(glob(dirname($imagePath) . '/profile_' . $userId . '.*')) === 1, 'Replacing a picture changes its bytes and keeps one image.');
        $savedHashes[] = $hash;
    }
    imagedestroy($image);
    $savedHash = end($savedHashes);
    $post = $basePost;
    $post['revision'] = '3';
    $post['profile_image'] = new CURLFile(__FILE__, 'image/jpeg', 'photo.jpg');
    $invalid = $request($profileUrl, $post);
    profile_image_portal_check($invalid['status'] === 422 && strpos($invalid['body'], 'Choose a valid JPG, PNG, or WebP profile image.') !== false, 'The real form rejects forged image files with a useful field error.');
    profile_image_portal_check(hash_file('sha256', $imagePath) === $savedHash && (int)synk_faculty_profile_fetch($conn, $userId)['revision'] === 3, 'An invalid image preserves the saved profile and previous picture.');
    $post['profile_image'] = new CURLFile($fixtures[2], 'image/png', 'photo.png');
    $post['csrf_token'] = 'invalid';
    $csrfFailure = $request($profileUrl, $post);
    profile_image_portal_check($csrfFailure['status'] === 403 && hash_file('sha256', $imagePath) === $savedHash, 'An invalid CSRF token cannot replace the picture.');
    $post['csrf_token'] = $csrf[1];
    $post['revision'] = '1';
    $stale = $request($profileUrl, $post);
    profile_image_portal_check($stale['status'] === 409 && hash_file('sha256', $imagePath) === $savedHash
        && (int)synk_faculty_profile_fetch($conn, $userId)['revision'] === 3, 'An outdated profile form cannot overwrite newer details or pictures.');
    foreach (['{', '{"x":2,"y":0,"width":0.6,"height":0.9}', '{"x":0,"y":0,"width":1,"height":1}'] as $badCrop) {
        $post = $basePost;
        $post['revision'] = '3';
        $post['profile_image'] = new CURLFile($fixtures[2], 'image/png', 'photo.png');
        $post['profile_photo_crop'] = $badCrop;
        $rejectedCrop = $request($profileUrl, $post);
        profile_image_portal_check($rejectedCrop['status'] === 422 && strpos($rejectedCrop['body'], 'The photo crop is invalid.') !== false, 'The Professor upload rejects invalid crop coordinates.');
        profile_image_portal_check(hash_file('sha256', $imagePath) === $savedHash && (int)synk_faculty_profile_fetch($conn, $userId)['revision'] === 3, 'Rejected cropping preserves the saved photo and profile.');
    }
    $post['profile_photo_crop'] = json_encode(['x'=>.1, 'y'=>0, 'width'=>.6, 'height'=>.9]);
    $croppedSave = $request($profileUrl, $post);
    $cropInfo = getimagesize($imagePath);
    profile_image_portal_check($croppedSave['status'] === 303 && $cropInfo[0] === 600 && $cropInfo[1] === 600 && $cropInfo[2] === IMAGETYPE_JPEG,
        'The actual Professor form saves the selected square JPEG crop.');
    profile_image_portal_check((int)synk_faculty_profile_fetch($conn, $userId)['revision'] === 4 && hash_file('sha256', $imagePath) !== $savedHash,
        'A successful crop updates the photo and profile together.');
    $view = $request($profileUrl);
    profile_image_portal_check($view['status'] === 200 && strpos($view['body'], '/uploads/profile/profile_' . $userId . '.jpg?v=') !== false
        && strpos($view['body'], 'Your profile image could not be prepared.') === false, 'The professor page displays the saved picture with its cache version.');
    $currentAccount = $conn->query('SELECT email FROM tbl_useraccount WHERE user_id = ' . $userId)->fetch_assoc();
    profile_image_portal_check($currentAccount['email'] === $email, 'Cleanup is limited to the exact temporary account.');
} finally {
    if ($userId !== null) {
        // Every deletion is restricted to the newly created QA identity.
        $conn->query('DELETE FROM tbl_faculty_profile_eligibilities WHERE user_id = ' . $userId);
        $conn->query('DELETE FROM tbl_faculty_profiles WHERE user_id = ' . $userId);
        $conn->query('DELETE FROM tbl_useraccount_roles WHERE user_id = ' . $userId);
        $stmt = $conn->prepare('DELETE FROM tbl_useraccount WHERE user_id = ? AND email = ?');
        $stmt->bind_param('is', $userId, $email);
        $stmt->execute();
        $stmt->close();
    }
    foreach (array_merge($fixtures, $ownsImagePath ? [$imagePath] : []) as $fixture) if (is_string($fixture) && is_file($fixture)) unlink($fixture);
}
profile_image_portal_check(synk_faculty_profile_fetch($conn, $userId) === null && !is_file($imagePath), 'QA profile and image were removed.');
echo 'Faculty profile image portal HTTP: ', $checks, ' checks passed. Temporary account and files removed.', PHP_EOL;
