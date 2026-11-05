<?php
include '../../conectbd.php';
$id = (int)($_POST['id'] ?? 0);

$sql_command = mysqli_query($connect, "SELECT * FROM commands WHERE device_id = $id ORDER BY created_at DESC LIMIT 1");

if ($row = mysqli_fetch_assoc($sql_command)) {
    $status = $row['status'];
    
    if ($status == 'pending') {
        echo "ожидание передачи команды";
    } elseif ($status == 'sent') {
        echo "ожидание ответа устройства";
    } elseif ($status == 'success') {
        echo "выполненно";
        ?>
        <script>
            document.getElementById('content_middle').style.display = 'block';
            document.getElementById('content_middle_management_reley_spiner').style.display = 'none';
            document.getElementById('identifier_execution_action').style.display = 'none';
            document.getElementById('identifier_execution_action_real').style.display = 'none';
            
            document.getElementById("reley_1_1").checked = false;
            document.getElementById("reley_1_2").checked = false;
            document.getElementById("reley_1_3").checked = false;
            document.getElementById("reley_2_1").checked = false;
            document.getElementById("reley_2_2").checked = false;
            document.getElementById("reley_2_3").checked = false;
            document.getElementById("reley_3_1").checked = false;
            document.getElementById("reley_3_2").checked = false;
            document.getElementById("reley_3_3").checked = false;
            document.getElementById("reley_4_1").checked = false;
            document.getElementById("reley_4_2").checked = false;
            document.getElementById("reley_4_3").checked = false;
            
            document.getElementById('button_reley_4').style.display = 'none';
            document.getElementById('button_reley_all').style.display = 'none';
        </script>
        <?php
    } elseif ($status == 'error') {
        echo "Ошибка выполнения";
        ?>
        <script>
            document.getElementById('content_middle').style.display = 'block';
            document.getElementById('content_middle_management_reley_spiner').style.display = 'none';
            document.getElementById('identifier_execution_action').style.display = 'none';
            document.getElementById('identifier_execution_action_real').style.display = 'none';
        </script>
        <?php
    } else {
        echo "нет команды";
    }
} else {
    echo "нет команды";
}
?>