<?php require 'aut/verificat.php'; ?>
<?php
header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
?>
<?php
include 'script/php/conectbd.php';
?>
<!DOCTYPE html>
<html lang="ru">

<head>
    <meta charset="UTF-8" />
    <title>device management off-on servise</title>
    <link rel="stylesheet" href="design/css.css" rel="stylesheet" type="text/css" />
    <link rel="stylesheet" href="design/spiner.css" rel="stylesheet" type="text/css" />
    <link rel="stylesheet" href="design/preload.css" rel="stylesheet" type="text/css" />
    <script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.6.1/jquery.min.js"></script>
    <link rel="stylesheet" href="design/jquery_flick.css" type="text/css" />
    <meta http-equiv='cache-control' content='no-cache'>
    <meta http-equiv='expires' content='0'>
    <meta http-equiv='pragma' content='no-cache'>
</head>

<body id="body">

    <div class="preloader">
        <div class="preloader__row">
            <div class="preloader__item"></div>
            <div class="preloader__item"></div>
        </div>
    </div>

    <div class="page">
        <div class="menu_content">
            <div class="menu">
                <div class="menu_stat">
                    <div class="ava_devic">
                        <div class="ava_devic_title">
                            Доступные устройства
                        </div>
                        <div class="ava_devic_numbr">
                            <?php
                                $numb_devices = 0;
                                $sql_general_info = mysqli_query($connect, "SELECT * FROM `general_info` ORDER BY id");
                                while ($res_sql_general_info = mysqli_fetch_array($sql_general_info)) {
                                    $numb_devices++;
                                }
                                echo $numb_devices;
                            ?>
                        </div>
                    </div>
                    <div class="search" style="display: none;">
                        <input type="text" placeholder="search" id="myInput" class="search_input" onkeyup="myFunction()" onclick="myFunction()">
                    </div>
                </div>
                <div class="menu_scroll" id="menu_scroll">
                    <?php
                        $sql_general_info = mysqli_query($connect, "SELECT * FROM `general_info` ORDER BY id");
                        $quantity_button_id = 0;
                        while ($res_sql_general_info = mysqli_fetch_array($sql_general_info)) {
                            $id = $res_sql_general_info[0];
                            $device_name = $res_sql_general_info[1];
                            $device_address = $res_sql_general_info[2];
                    ?>
                    <div class="button_id button_id_selector <?php echo $quantity_button_id; ?> <?php echo $id; ?> <?php echo $device_name; ?> " id="button_id_<?php echo $id; ?>" style="display: block;">
                        <div class="button_id_text" id="button_id_text">
                            <p>
                                <span class="button_id_text_number"><?php echo $device_name; ?></span><br>
                                <span class="button_id_text_addres"><?php echo $device_address; ?></span>
                            </p>
                        </div>
                        <div class="device_status" id="onoffline<?php echo $id;?>"></div>
                    </div>
                    <?php 
                $quantity_button_id++;
                } ?>
                </div>
                <div class="icon_bottom" style="display: none;">
                    <img src="design/img/map.png" class="img_icon_hat" title="Карта объектов">
                </div>
                <div class="icon_bottom" style="display: none;">
                    <img src="design/img/news.png" class="img_icon_hat" title="Статистика / информация">
                </div>
            </div>
            <div class="content">
                <div class="hat_content">
                    <div class="icon_hat">
                        <div class="dropdown">
                            <div class="dropbtn"><img src="design/img/user.png" class="img_icon_hat" title="user info"></div>
                            <div class="dropdown-content">
                                <p class="user_drop">User: <?php echo htmlspecialchars($login); ?></p>
                                <a href="/index.php">Новая панель</a>
                                <a href="/monitor.php">MQTT монитор</a>
                                <a href="aut/verificat.php?aut=finish">Выход</a>
                            </div>
                        </div>
                    </div>
                </div>
                <div id="reload_content">
                </div>
            </div>
        </div>
    </div>

    <script src="script/jquery/jquery-1.7.min.js"></script>
    <script src="script/jquery/jquery-ui-1.8.16.custom.min.js"></script>
    <script src="script/script.js"></script>

    <script>
    var mainPageIntervals = [];

    function updateDeviceStatus(deviceId) {
        $.ajax({
            url: 'script/php/online_offline.php',
            type: 'POST',
            data: { param_id: deviceId },
            success: function(data) {
                if (data && data.trim() !== '') {
                    $('#onoffline' + deviceId).html(data);
                }
            },
            error: function(xhr, status, error) {
                console.error("Error updating status for device " + deviceId + ":", error);
            }
        });
    }
    function clearMainPageIntervals() {
        if (window.controlPageIntervals) {
            for (var i = 0; i < window.controlPageIntervals.length; i++) {
                clearInterval(window.controlPageIntervals[i]);
            }
            window.controlPageIntervals = [];
        }
    }
    function startMainPageUpdates() {
        clearMainPageIntervals();

        <?php
        $sql_general_info = mysqli_query($connect, "SELECT * FROM `general_info` ORDER BY id");
        $device_ids = array();
        while ($res_sql_general_info = mysqli_fetch_array($sql_general_info)) {
            $device_id = $res_sql_general_info[0];
            $device_ids[] = $device_id;
        ?>
        updateDeviceStatus(<?php echo $device_id; ?>);
        var interval<?php echo $device_id; ?> = setInterval(function() { 
            updateDeviceStatus(<?php echo $device_id; ?>); 
        }, 3000);
        mainPageIntervals.push(interval<?php echo $device_id; ?>);
        <?php } ?>

        console.log("Status updates started for devices: <?php echo implode(', ', $device_ids); ?>");
        console.log("Intervals count: " + mainPageIntervals.length);
    }

    function setupDeviceClicks() {
        <?php
        $sql_general_info = mysqli_query($connect, "SELECT * FROM `general_info` ORDER BY id");
        while ($res_sql_general_info = mysqli_fetch_array($sql_general_info)) {
            $device_id = $res_sql_general_info[0];
        ?>
        $('#button_id_<?php echo $device_id; ?>').off('click').on('click', function() {
            clearMainPageIntervals();
            $('#reload_content').html('<div style="text-align:center; padding:50px;">Загрузка...</div>');
            $.ajax({
                url: "script/php/content_click_id.php?id=<?php echo $device_id; ?>",
                cache: false,
                success: function(html) {
                    $("#reload_content").html(html);
                }
            });
        });
        <?php } ?>
    }

    $(document).ready(function() {
        console.log("Document ready - starting main page updates");
        startMainPageUpdates();
        setupDeviceClicks();
    });

    if (document.readyState === 'complete') {
        console.log("Page already loaded - starting main page updates");
        startMainPageUpdates();
        setupDeviceClicks();
    }

    window.addEventListener('pageshow', function(event) {
        if (event.persisted || performance.getEntriesByType("navigation")[0].type === 'back_forward') {
            console.log("Page restored from cache - restarting status updates");
            startMainPageUpdates();
            setupDeviceClicks();
        }
    });
    </script>
</body>

</html>