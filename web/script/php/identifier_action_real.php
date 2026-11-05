<?php
include '../../conectbd.php';

$reley_and_id = isset($_POST['param']) ? $_POST['param'] : '';
$number_reley = isset($_POST['param2']) ? (int)$_POST['param2'] : 0;

$reley_and_id_array = explode("_", $reley_and_id);
$reley_action = isset($reley_and_id_array[0]) ? $reley_and_id_array[0] : 0;
$id = isset($reley_and_id_array[1]) ? (int)$reley_and_id_array[1] : 0;

$sql_command = mysqli_query($connect, "SELECT * FROM commands WHERE device_id = $id ORDER BY created_at DESC LIMIT 1");

if ($row = mysqli_fetch_assoc($sql_command)) {
    $status = $row['status'];
    
    if ($status == 'pending') {
        echo "ожидание передачи команды";
    } elseif ($status == 'sent') {
        echo "ожидание ответа устройства";
        
        $created_at = strtotime($row['created_at']);
        $now = time();
        $diff = $now - $created_at;
        
        if ($diff > 10) {
            mysqli_query($connect, "UPDATE commands SET status = 'error', confirmed_at = NOW() WHERE cmd_id = " . $row['cmd_id']);
            $status = 'error';
        }
    }
    
    if ($status == 'success') {
        echo "выполнено";
        ?>
        <script>
            (function() {
                var relayNum = <?php echo $number_reley; ?>;
                
                var contentMiddle = document.getElementById('content_middle');
                var spinner = document.getElementById('content_middle_management_reley_spiner');
                var actionSpan = document.getElementById('identifier_execution_action');
                var actionRealSpan = document.getElementById('identifier_execution_action_real');
                
                if (contentMiddle) contentMiddle.style.display = 'block';
                if (spinner) spinner.style.display = 'none';
                if (actionSpan) actionSpan.style.display = 'none';
                if (actionRealSpan) actionRealSpan.style.display = 'none';
                
                var radio1 = document.getElementById("reley_" + relayNum + "_1");
                var radio2 = document.getElementById("reley_" + relayNum + "_2");
                var radio3 = document.getElementById("reley_" + relayNum + "_3");
                
                if (radio1) radio1.checked = false;
                if (radio2) radio2.checked = false;
                if (radio3) radio3.checked = false;
                
                var btn = document.getElementById('button_reley_' + relayNum);
                if (btn) btn.style.display = 'none';
                
                if (typeof updateSendButtons === 'function') {
                    updateSendButtons();
                }
            })();
        </script>
        <?php
    } elseif ($status == 'error') {
        echo "Ошибка выполнения";
        ?>
        <script>
            (function() {
                var relayNum = <?php echo $number_reley; ?>;
                
                var contentMiddle = document.getElementById('content_middle');
                var spinner = document.getElementById('content_middle_management_reley_spiner');
                var actionSpan = document.getElementById('identifier_execution_action');
                var actionRealSpan = document.getElementById('identifier_execution_action_real');
                
                if (contentMiddle) contentMiddle.style.display = 'block';
                if (spinner) spinner.style.display = 'none';
                if (actionSpan) actionSpan.style.display = 'none';
                if (actionRealSpan) actionRealSpan.style.display = 'none';
                
                var radio1 = document.getElementById("reley_" + relayNum + "_1");
                var radio2 = document.getElementById("reley_" + relayNum + "_2");
                var radio3 = document.getElementById("reley_" + relayNum + "_3");
                
                if (radio1) radio1.checked = false;
                if (radio2) radio2.checked = false;
                if (radio3) radio3.checked = false;
                
                var btn = document.getElementById('button_reley_' + relayNum);
                if (btn) btn.style.display = 'none';
                
                if (typeof updateSendButtons === 'function') {
                    updateSendButtons();
                }
            })();
        </script>
        <?php
    } else {
        echo "нет команды";
    }
} else {
    echo "нет команды";
}
?>