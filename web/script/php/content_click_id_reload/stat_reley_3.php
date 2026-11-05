<?php 
    $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;    
    include '../../../conectbd.php';
    
    if ($id > 0) {
        $device_result = mysqli_query($connect, "SELECT status FROM devices WHERE id = $id");
        $device_status = 1;
        if ($device_row = mysqli_fetch_assoc($device_result)) {
            $device_status = $device_row['status'];
        }
        
        if ($device_status == 0) {
            ?>
            <span class="identifier_error" style="display: block;">информация недоступна</span>
            <script>
            var elem1 = document.getElementById('management_reley_3_1');
            var elem2 = document.getElementById('management_reley_3_2');
            var elem3 = document.getElementById('management_reley_3_3');
            if (elem1) elem1.style.display = 'none';
            if (elem2) elem2.style.display = 'none';
            if (elem3) elem3.style.display = 'none';
            </script>
            <?php
            return;
        }
        
        $result = mysqli_query($connect, "SELECT state, updated_at FROM relay_states WHERE device_id = $id AND relay_num = 3 ORDER BY updated_at DESC LIMIT 1");
        if ($row = mysqli_fetch_assoc($result)) {
            $state = $row['state'];
            $updated_at = strtotime($row['updated_at']);
            $now = time();
            $diff = $now - $updated_at;
            
            if ($diff > 350) {
                ?>
                <span class="identifier_error" style="display: block;">информация недоступна</span>
                <script>
                var elem1 = document.getElementById('management_reley_3_1');
                var elem2 = document.getElementById('management_reley_3_2');
                var elem3 = document.getElementById('management_reley_3_3');
                if (elem1) elem1.style.display = 'none';
                if (elem2) elem2.style.display = 'none';
                if (elem3) elem3.style.display = 'none';
                </script>
                <?php
            } else {
                if($state == 1){
                    ?>
                    <span class="identifier_online">Питание - ОК</span>
                    <script>
                    var elem1 = document.getElementById('management_reley_3_1');
                    var elem2 = document.getElementById('management_reley_3_2');
                    var elem3 = document.getElementById('management_reley_3_3');
                    if (elem1) elem1.style.display = 'none';
                    if (elem2) elem2.style.display = 'block';
                    if (elem3) elem3.style.display = 'block';
                    </script>
                    <?php  
                } else {
                    ?>
                    <span class="identifier_offline">Отсутствует питание</span>
                    <script>
                    var elem1 = document.getElementById('management_reley_3_1');
                    var elem2 = document.getElementById('management_reley_3_2');
                    var elem3 = document.getElementById('management_reley_3_3');
                    if (elem1) elem1.style.display = 'block';
                    if (elem2) elem2.style.display = 'none';
                    if (elem3) elem3.style.display = 'none';
                    </script>
                    <?php
                }
            }
        } else {
            echo '<span class="identifier_error">нет данных</span>';
        }
    } else {
        echo '<span class="identifier_error">нет данных</span>';
    }
?>