<?php
session_start();
if (!isset($_SESSION['user_id'])) { http_response_code(401); exit; }

require 'config.php'; // Sets APP_TIMEZONE + MySQL time_zone in one place

$user_id  = (int)$_SESSION['user_id'];
$plant_id = intval($_POST['plant_id'] ?? 0);

if (!$plant_id) {
    echo json_encode(['success' => false, 'error' => 'No plant specified.']); exit;
}

// Verify the plant belongs to this user
$check = $pdo->prepare("SELECT * FROM plants WHERE plant_id = ? AND user_id = ?");
$check->execute([$plant_id, $user_id]);
$plant = $check->fetch();
if (!$plant) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized.']); exit;
}

$api_key  = '10e1cd7f9a2dc254e99c16980370adbf';
$city     = urlencode($plant['city']);
$url      = "https://api.openweathermap.org/data/2.5/weather?q={$city}&appid={$api_key}&units=metric";
$response = @file_get_contents($url);

if (!$response) {
    echo json_encode(['success' => false,
        'error' => 'Cannot reach OpenWeatherMap. Check that your hosting allows outbound HTTP (allow_url_fopen).']);
    exit;
}

$weather = json_decode($response, true);

if (($weather['cod'] ?? '') == 401) {
    echo json_encode(['success' => false, 'error' => 'Invalid OpenWeatherMap API key.']); exit;
}
if (($weather['cod'] ?? 200) != 200) {
    echo json_encode(['success' => false,
        'error' => 'City not found: ' . ($weather['message'] ?? 'unknown error')]); exit;
}
if (!isset($weather['main']['humidity'])) {
    echo json_encode(['success' => false, 'error' => 'Humidity data unavailable. Try again shortly.']); exit;
}

$humidity    = (float)$weather['main']['humidity'];
$city_name   = $weather['name'] ?? $plant['city'];
$country     = $weather['sys']['country'] ?? '';
$description = ucfirst($weather['weather'][0]['description'] ?? '');

// Classify for succulents
if ($humidity < 20)      $status = 'Dry';
elseif ($humidity <= 60) $status = 'Ideal';
else                     $status = 'Humid';

// FIX: recorded_at is stored by MySQL NOW() which now respects the session
// timezone set in config.php (+08:00), so stored timestamps are local PH time.
$stmt = $pdo->prepare("INSERT INTO humidity (plant_id, humidity_percent, status, recorded_at) VALUES (?, ?, ?, NOW())");
$stmt->execute([$plant_id, $humidity, $status]);
$humidity_id = (int)$pdo->lastInsertId();

$stmt2 = $pdo->prepare("INSERT INTO user_logs (user_id, humidity_id) VALUES (?, ?)");
$stmt2->execute([$user_id, $humidity_id]);

// Aggregate stats for this plant / user
$s1 = $pdo->prepare("SELECT COUNT(*) FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=? AND h.plant_id=?");
$s1->execute([$user_id, $plant_id]); $total = (int)$s1->fetchColumn();

$s2 = $pdo->prepare("SELECT COUNT(*) FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=? AND h.plant_id=? AND h.status='Dry'");
$s2->execute([$user_id, $plant_id]); $dry = (int)$s2->fetchColumn();

$s3 = $pdo->prepare("SELECT COUNT(*) FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=? AND h.plant_id=? AND h.status='Ideal'");
$s3->execute([$user_id, $plant_id]); $ideal = (int)$s3->fetchColumn();

$s4 = $pdo->prepare("SELECT COUNT(*) FROM user_logs ul JOIN humidity h ON ul.humidity_id=h.humidity_id WHERE ul.user_id=? AND h.plant_id=? AND h.status='Humid'");
$s4->execute([$user_id, $plant_id]); $humid = (int)$s4->fetchColumn();

// FIX: Return detected_at using PHP date() which now reflects Asia/Manila.
// This keeps the JS status text consistent with what is stored and displayed.
echo json_encode([
    'success'     => true,
    'humidity'    => $humidity,
    'status'      => $status,
    'city'        => "$city_name, $country",
    'description' => $description,
    'detected_at' => date('Y-m-d H:i:s'),   // Asia/Manila local time
    'total'       => $total,
    'dry'         => $dry,
    'ideal'       => $ideal,
    'humid'       => $humid,
]);
