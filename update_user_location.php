<?php
// update_user_location.php
header('Content-Type: application/json');
include('db.php');

if (session_status() === PHP_SESSION_NONE) { session_start(); }

$user_id = $_SESSION['user_id'] ?? 0;
$input   = json_decode(file_get_contents('php://input'), true);

$lat = filter_var($input['latitude'] ?? $_POST['latitude'] ?? null, FILTER_VALIDATE_FLOAT);
$lng = filter_var($input['longitude'] ?? $_POST['longitude'] ?? null, FILTER_VALIDATE_FLOAT);

if ($user_id > 0 && $lat !== false && $lng !== false) {
    $sql = "UPDATE tbl_users 
            SET last_latitude = $1, last_longitude = $2, last_location_updated = NOW() 
            WHERE user_id = $3";
    $res = pg_query_params($db, $sql, [$lat, $lng, $user_id]);

    if ($res) {
        echo json_encode(['success' => true]);
        exit();
    }
}
echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
