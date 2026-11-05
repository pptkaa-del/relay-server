<?php 
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;    
    include '../../../conectbd.php';
    
    if ($id > 0) {
        $result = mysqli_query($connect, "SELECT last_seen, status, battery_power FROM devices WHERE id = $id");
        if ($row = mysqli_fetch_assoc($result)) {
            $last_seen = strtotime($row['last_seen']);
            $status = $row['status'];
            $battery = $row['battery_power'];
            $now = time();
            
            if ($status == 0) {
                echo '<span class="identifier_offline">offline</span>';
            } elseif ($now - $last_seen > 350) {
                echo '<span class="identifier_offline">offline</span>';
            } elseif ($status == 2) {
                echo '<span class="identifier_error">ERROR</span>';
            } else {
                echo '<span class="loader"></span><span class="identifier_online spiner_online">online</span>';
            }
            if ($battery == 1) {
                echo '<span class="battery_icon">питание от АКБ</span>';
            }
        } else {
            echo '<span class="identifier_offline">нет данных</span>';
        }
    } else {
        echo '<span class="identifier_offline">нет данных</span>';
    }
?>