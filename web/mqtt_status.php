<?php
$id = (int)($_POST['param_id'] ?? 0);
include __DIR__ . '/conectbd.php';

$result = mysqli_query($connect, "SELECT status, last_seen FROM devices WHERE id = $id");
$row = $result ? mysqli_fetch_assoc($result) : null;

if (!$row || time() - strtotime((string)$row['last_seen']) > RELAY_OFFLINE_AFTER || (int)$row['status'] === 0) {
    echo '<div class="button_id_identifier_offlie">';
} elseif ((int)$row['status'] === 2) {
    echo '<div class="button_id_identifier_error">';
} else {
    echo '<div class="button_id_identifier_online">';
}
