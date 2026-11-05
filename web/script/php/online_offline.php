<?php
header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");

$id = (int)$_POST['param_id'];
include '../../conectbd.php';

$result = mysqli_query($connect, "SELECT status, last_seen FROM devices WHERE id = $id");

if (mysqli_num_rows($result) > 0) {
    $row = mysqli_fetch_assoc($result);
    $last_seen = strtotime($row['last_seen']);
    $now = time();
    $status = $row['status'];
    
    if ($status == 0) {
        echo '<span class="status_offline_text">off</span>';
    } elseif ($now - $last_seen > 350) {
        echo '<span class="status_offline_text">off</span>';
    } elseif ($status == 2) {
        echo '<span class="status_error_text">err</span>';
    } else {
        echo '<span class="status_online_text">on</span>';
    }
} else {
    echo '<span class="status_offline_text">off</span>';
}
?>