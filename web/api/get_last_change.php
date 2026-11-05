<?php
require_once '../script/php/conectbd.php';
header('Content-Type: application/json');

$device_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($device_id > 0) {
    $query = "SELECT device_id, device_name, last_update, last_change, change_type, changed_fields, previous_value, new_value 
              FROM device_last_update 
              WHERE device_id = $device_id";
    $result = mysqli_query($connect, $query);
    
    if ($row = mysqli_fetch_assoc($result)) {
        echo json_encode(['success' => true, 'data' => $row]);
    } else {
        echo json_encode(['success' => false, 'error' => 'No data found']);
    }
} else {
    echo json_encode(['success' => false, 'error' => 'No device id']);
}
?>
