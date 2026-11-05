<?php
header("Cache-Control: no-cache, must-revalidate");
header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
?>
<script type="text/javascript" src="https://ajax.googleapis.com/ajax/libs/jquery/1.6.1/jquery.min.js"></script>
<?php 
    $id = (int)($_GET["id"] ?? 0);
    include '../../conectbd.php';
    $sql_general_info = mysqli_query($connect, "SELECT * FROM `general_info` WHERE id= '$id'");
    while ($res_sql_general_info = mysqli_fetch_array($sql_general_info)) {
        $nom_ob = $res_sql_general_info[1];
        $adres = $res_sql_general_info[2];
        $koordin = $res_sql_general_info[3];
        $name_reley_1 = $res_sql_general_info[4];
        $name_reley_2 = $res_sql_general_info[5];
        $name_reley_3 = $res_sql_general_info[6];
        $name_reley_4 = $res_sql_general_info[7];
    }
    $result = mysqli_query($connect, "SELECT relay_num, state FROM relay_states WHERE device_id = $id");
    $reley_1_stat = 0;
    $reley_2_stat = 0;
    $reley_3_stat = 0;
    $reley_4_stat = 0;
    while ($row = mysqli_fetch_assoc($result)) {
        if ($row['relay_num'] == 1) $reley_1_stat = $row['state'];
        if ($row['relay_num'] == 2) $reley_2_stat = $row['state'];
        if ($row['relay_num'] == 3) $reley_3_stat = $row['state'];
        if ($row['relay_num'] == 4) $reley_4_stat = $row['state'];
    }
    $result_alarm = mysqli_query($connect, "SELECT alarm_type, last_alarm FROM devices WHERE id = $id");
    $alarm_type = 0;
    $last_alarm = null;
    if ($row_alarm = mysqli_fetch_assoc($result_alarm)) {
        $alarm_type = $row_alarm['alarm_type'];
        $last_alarm = $row_alarm['last_alarm'];
    }
    
    echo '<script>window.controlPageIntervals = window.controlPageIntervals || [];</script>';
?>
<div class="content_top">
    <div class="content_top_name_and_stat">
        <div class="content_top_name" oneclick="show_on_offline()">
            <?php echo $nom_ob; ?>
        </div>
        <div class="content_top_stat" id="content_top_stat">
            <script>
                var interval1;
                function show_on_offline() {
                    $.ajax({
                        url: 'script/php/content_click_id_reload/online_offline.php',
                        type: 'POST',
                        data: { id: <?php echo $id;?> },
                        success: function(data) {
                            if (data && data.trim() !== '') {
                                $('#content_top_stat').html(data);
                            }
                        }
                    });
                }
                $(document).ready(function(){
                    show_on_offline();
                    interval1 = setInterval(show_on_offline, 3000);
                    window.controlPageIntervals.push(interval1);
                });
            </script>
        </div>
    </div>
    <div class="content_top_address_and_coordinates">
        <div class="content_top_address_and_dataBD_adrdess">
            <div class="content_top_address">№ КТО</div>
            <div class="content_top_dataBD_adrdess"><?php echo $adres; ?></div>
        </div>
        <div class="content_top_coordinates_and_dataBD_coordinates" style="display: none;">
            <div class="content_top_coordinates">Кординаты</div>
            <div class="content_top_dataBD_coordinates"><?php echo $koordin; ?></div>
        </div>
    </div>
</div>

<div>
    <span class="identifier_action" id="identifier_execution_action" style="display: none;">Action performing...</span>
    <span class="identifier_action_real" id="identifier_execution_action_real" style="display: none;"></span>
</div>

<div class="content_middle_management_reley_spiner" id="content_middle_management_reley_spiner">
    <div class="spin-wrapper">
        <div class="spinner"></div>
    </div>
</div>

<div class="content_middle" id="content_middle">
<?php if ($name_reley_1 != 'not_used'){ ?>
    <div class="content_rele_1">
        <div class="content_rele_top">
            <div class="content_middle_name_reley_and_stat_reley">
                <div class="content_middle_name_reley"><?php echo $name_reley_1; ?></div>
                <div class="content_middle_stat_reley" id="content_middle_stat_reley_1">
                    <script>
                        var interval2;
                        function show_on_stat_reley_1() {
                            $.ajax({
                                url: 'script/php/content_click_id_reload/stat_reley_1.php',
                                type: 'POST',
                                data: { id: <?php echo $id;?> },
                                success: function(data) {
                                    if (data && data.trim() !== '') {
                                        $('#content_middle_stat_reley_1').html(data);
                                    }
                                }
                            });
                        }
                        $(document).ready(function(){
                            show_on_stat_reley_1();
                            interval2 = setInterval(show_on_stat_reley_1, 3000);
                            window.controlPageIntervals.push(interval2);
                        });
                    </script>
                </div>
            </div>
        </div>
        <div class="content_rele_bottom">
            <div class="content_middle_management_reley_1" id="content_middle_management_reley_1">
                <div class="content_middle_managment_reley_input" id="management_reley_1_1" onclick="show_button_reley_1_1()" <?php 
                    if ($reley_1_stat == 0){ echo 'style="display: none;"'; } 
                    if ($reley_1_stat == 1) { echo 'style="display: none;"'; } 
                    if ($reley_1_stat == 2) { echo 'style="display: block;"'; } ?> >
                    <input id="reley_1_1" type="radio" name="reley_1" value="1_<?php echo $id; ?>" href="#" onclick="show_button_reley_1_1()"><label for="reley_1_1">Включить</label>
                </div>
                <div class="content_middle_managment_reley_input" id="management_reley_1_2" onclick="show_button_reley_1_2()" <?php 
                    if ($reley_1_stat == 0 || $reley_1_stat == 1){ echo 'style="display: block;"'; } 
                    if ($reley_1_stat == 2) { echo 'style="display: none;"'; } ?> >
                    <input id="reley_1_2" type="radio" name="reley_1" value="2_<?php echo $id; ?>" href="#" onclick="show_button_reley_1_2()"><label for="reley_1_2">Выключить</label>
                </div>
                <div class="content_middle_managment_reley_input" id="management_reley_1_3" onclick="show_button_reley_1_3()" <?php 
                    if ($reley_1_stat == 0){ echo 'style="display: none;"'; } 
                    if ($reley_1_stat == 1) { echo 'style="display: block;"'; } 
                    if ($reley_1_stat == 2) { echo 'style="display: none;"'; } ?> >
                    <input id="reley_1_3" type="radio" name="reley_1" value="3_<?php echo $id; ?>" href="#" onclick="show_button_reley_1_3()"><label for="reley_1_3">Перезагрузить</label>
                </div>
                <div class="content_middle_managment_reley_button">
                    <input class="button_reley" id="button_reley_1" type="button" value="Отправить" style="display: none;" onclick="send_reley_1()">
                    <input class="button_reley_none" id="button_reley_1_none" type="button" value="Выберите действие" style="display: none;">
                </div>
            </div>
        </div>
    </div>
<?php } ?>

<?php if ($name_reley_2 != 'not_used'){ ?>
    <div class="content_rele_2">
        <div class="content_rele_top">
            <div class="content_middle_name_reley_and_stat_reley">
                <div class="content_middle_name_reley"><?php echo $name_reley_2; ?></div>
                <div class="content_middle_stat_reley" id="content_middle_stat_reley_2">
                    <script>
                        var interval3;
                        function show_on_stat_reley_2() {
                            $.ajax({
                                url: 'script/php/content_click_id_reload/stat_reley_2.php',
                                type: 'POST',
                                data: { id: <?php echo $id;?> },
                                success: function(data) {
                                    if (data && data.trim() !== '') {
                                        $('#content_middle_stat_reley_2').html(data);
                                    }
                                }
                            });
                        }
                        $(document).ready(function(){
                            show_on_stat_reley_2();
                            interval3 = setInterval(show_on_stat_reley_2, 3000);
                            window.controlPageIntervals.push(interval3);
                        });
                    </script>
                </div>
            </div>
        </div>
        <div class="content_rele_bottom">
            <div class="content_middle_management_reley_2" id="content_middle_management_reley_2">
                <div class="content_middle_managment_reley_input" id="management_reley_2_1" onclick="show_button_reley_2_1()" <?php 
                    if ($reley_2_stat == 0){ echo 'style="display: none;"'; } 
                    if ($reley_2_stat == 1) { echo 'style="display: none;"'; } 
                    if ($reley_2_stat == 2) { echo 'style="display: block;"'; } ?> >
                    <input id="reley_2_1" type="radio" name="reley_2" value="1_<?php echo $id; ?>" onclick="show_button_reley_2_1()"><label for="reley_2_1">Включить</label>
                </div>
                <div class="content_middle_managment_reley_input" id="management_reley_2_2" onclick="show_button_reley_2_2()" <?php 
                    if ($reley_2_stat == 0 || $reley_2_stat == 1){ echo 'style="display: block;"'; } 
                    if ($reley_2_stat == 2) { echo 'style="display: none;"'; } ?> >
                    <input id="reley_2_2" type="radio" name="reley_2" value="2_<?php echo $id; ?>" onclick="show_button_reley_2_2()"><label for="reley_2_2">Выключить</label>
                </div>
                <div class="content_middle_managment_reley_input" id="management_reley_2_3" onclick="show_button_reley_2_3()" <?php 
                    if ($reley_2_stat == 0){ echo 'style="display: none;"'; } 
                    if ($reley_2_stat == 1) { echo 'style="display: block;"'; } 
                    if ($reley_2_stat == 2) { echo 'style="display: none;"'; } ?> >
                    <input id="reley_2_3" type="radio" name="reley_2" value="3_<?php echo $id; ?>" onclick="show_button_reley_2_3()"><label for="reley_2_3">Перезагрузить</label>
                </div>
                <div class="content_middle_managment_reley_button">
                    <input class="button_reley" id="button_reley_2" type="button" value="Отправить" style="display: none;" onclick="send_reley_2()">
                    <input class="button_reley_none" id="button_reley_2_none" type="button" value="Выберите действие" style="display: none;">
                </div>
            </div>
        </div>
    </div>
<?php } ?>

<?php if ($name_reley_3 != 'not_used'){ ?>
    <div class="content_rele_3">
        <div class="content_rele_top">
            <div class="content_middle_name_reley_and_stat_reley">
                <div class="content_middle_name_reley"><?php echo $name_reley_3; ?></div>
                <div class="content_middle_stat_reley" id="content_middle_stat_reley_3">
                    <script>
                        var interval4;
                        function show_on_stat_reley_3() {
                            $.ajax({
                                url: 'script/php/content_click_id_reload/stat_reley_3.php',
                                type: 'POST',
                                data: { id: <?php echo $id;?> },
                                success: function(data) {
                                    if (data && data.trim() !== '') {
                                        $('#content_middle_stat_reley_3').html(data);
                                    }
                                }
                            });
                        }
                        $(document).ready(function(){
                            show_on_stat_reley_3();
                            interval4 = setInterval(show_on_stat_reley_3, 3000);
                            window.controlPageIntervals.push(interval4);
                        });
                    </script>
                </div>
            </div>
        </div>
        <div class="content_rele_bottom">
            <div class="content_middle_management_reley_3" id="content_middle_management_reley_3">
                <div class="content_middle_managment_reley_input" id="management_reley_3_1" onclick="show_button_reley_3_1()" <?php 
                    if ($reley_3_stat == 0){ echo 'style="display: none;"'; } 
                    if ($reley_3_stat == 1) { echo 'style="display: none;"'; } 
                    if ($reley_3_stat == 2) { echo 'style="display: block;"'; } ?> >
                    <input id="reley_3_1" type="radio" name="reley_3" value="1_<?php echo $id; ?>" onclick="show_button_reley_3_1()"><label for="reley_3_1">Включить</label>
                </div>
                <div class="content_middle_managment_reley_input" id="management_reley_3_2" onclick="show_button_reley_3_2()" <?php 
                    if ($reley_3_stat == 0 || $reley_3_stat == 1){ echo 'style="display: block;"'; } 
                    if ($reley_3_stat == 2) { echo 'style="display: none;"'; } ?> >
                    <input id="reley_3_2" type="radio" name="reley_3" value="2_<?php echo $id; ?>" onclick="show_button_reley_3_2()"><label for="reley_3_2">Выключить</label>
                </div>
                <div class="content_middle_managment_reley_input" id="management_reley_3_3" onclick="show_button_reley_3_3()" <?php 
                    if ($reley_3_stat == 0){ echo 'style="display: none;"'; } 
                    if ($reley_3_stat == 1) { echo 'style="display: block;"'; } 
                    if ($reley_3_stat == 2) { echo 'style="display: none;"'; } ?> >
                    <input id="reley_3_3" type="radio" name="reley_3" value="3_<?php echo $id; ?>" onclick="show_button_reley_3_3()"><label for="reley_3_3">Перезагрузить</label>
                </div>
                <div class="content_middle_managment_reley_button">
                    <input class="button_reley" id="button_reley_3" type="button" value="Отправить" style="display: none;" onclick="send_reley_3()">
                    <input class="button_reley_none" id="button_reley_3_none" type="button" value="Выберите действие" style="display: none;">
                </div>
            </div>
        </div>
    </div>
<?php } ?>

<?php if ($name_reley_4 != 'not_used'){ ?>
    <div class="content_rele_4">
        <div class="content_rele_top">
            <div class="content_middle_name_reley_and_stat_reley">
                <div class="content_middle_name_reley"><?php echo $name_reley_4; ?></div>
                <div class="content_middle_stat_reley" id="content_middle_stat_reley_4">
                    <script>
                        var interval5;
                        function show_on_stat_reley_4() {
                            $.ajax({
                                url: 'script/php/content_click_id_reload/stat_reley_4.php',
                                type: 'POST',
                                data: { id: <?php echo $id;?> },
                                success: function(data) {
                                    if (data && data.trim() !== '') {
                                        $('#content_middle_stat_reley_4').html(data);
                                    }
                                }
                            });
                        }
                        $(document).ready(function(){
                            show_on_stat_reley_4();
                            interval5 = setInterval(show_on_stat_reley_4, 3000);
                            window.controlPageIntervals.push(interval5);
                        });
                    </script>
                </div>
            </div>
        </div>
        <div class="content_rele_bottom">
            <div class="content_middle_management_reley_4" id="content_middle_management_reley_4">
                <div class="content_middle_managment_reley_input" id="management_reley_4_1" onclick="show_button_reley_4_1()" <?php 
                    if ($reley_4_stat == 0){ echo 'style="display: none;"'; } 
                    if ($reley_4_stat == 1) { echo 'style="display: none;"'; } 
                    if ($reley_4_stat == 2) { echo 'style="display: block;"'; } ?> >
                    <input id="reley_4_1" type="radio" name="reley_4" value="1_<?php echo $id; ?>" onclick="show_button_reley_4_1()"><label for="reley_4_1">Включить</label>
                </div>
                <div class="content_middle_managment_reley_input" id="management_reley_4_2" onclick="show_button_reley_4_2()" <?php 
                    if ($reley_4_stat == 0 || $reley_4_stat == 1){ echo 'style="display: block;"'; } 
                    if ($reley_4_stat == 2) { echo 'style="display: none;"'; } ?> >
                    <input id="reley_4_2" type="radio" name="reley_4" value="2_<?php echo $id; ?>" onclick="show_button_reley_4_2()"><label for="reley_4_2">Выключить</label>
                </div>
                <div class="content_middle_managment_reley_input" id="management_reley_4_3" onclick="show_button_reley_4_3()" <?php 
                    if ($reley_4_stat == 0){ echo 'style="display: none;"'; } 
                    if ($reley_4_stat == 1) { echo 'style="display: block;"'; } 
                    if ($reley_4_stat == 2) { echo 'style="display: none;"'; } ?> >
                    <input id="reley_4_3" type="radio" name="reley_4" value="3_<?php echo $id; ?>" onclick="show_button_reley_4_3()"><label for="reley_4_3">Перезагрузить</label>
                </div>
                <div class="content_middle_managment_reley_button">
                    <input class="button_reley" id="button_reley_4" type="button" value="Отправить" style="display: none;" onclick="send_reley_4()">
                    <input class="button_reley_none" id="button_reley_4_none" type="button" value="Выберите действие" style="display: none;">
                </div>
            </div>
        </div>
    </div>
<?php } ?>

</div>

<div>
    Последнее изменение: <span id="last_change_date_<?php echo $id; ?>"></span>
</div>
    <div class="content_last_change_date" id="last_change_date_<?php echo $id; ?>"></div>
</div>

<div class="content_bottom"></div>
<script>
function send_check_command() {
    var deviceId = <?php echo $id; ?>;
    
    var checkBtn = document.getElementById('button_check');
    if (checkBtn) {
        checkBtn.classList.add('loading');
        checkBtn.innerHTML = '⟳';
    }
    
    $.ajax({
        url: '/mqtt_publish.php',
        type: 'POST',
        data: {
            param: '4_' + deviceId,
            param2: '0' 
        },
        success: function(response) {
            console.log("Check command sent, response:", response);
            
            setTimeout(function() {
                if (checkBtn) {
                    checkBtn.classList.remove('loading');
                    checkBtn.innerHTML = '⟳';
                }
                show_on_offline();
                show_on_stat_reley_1();
                show_on_stat_reley_2();
                show_on_stat_reley_3();
                show_on_stat_reley_4();
            }, 1000);
        },
        error: function(xhr, status, error) {
            console.error("Error sending check command:", error);
            if (checkBtn) {
                checkBtn.classList.remove('loading');
                checkBtn.innerHTML = '⟳';
            }
        }
    });
}

function send_reley_1() {
    const radioButtons = document.querySelectorAll('input[name="reley_1"]');
    let selectedSize;
    for (const radioButton of radioButtons) {
        if (radioButton.checked) {
            selectedSize = radioButton.value;
            break;
        }
    }
    
    $.ajax({
        url: '/mqtt_publish.php',
        type: 'POST',
        data: {
            param: selectedSize,
            param2: "1"
        },
        success: function(response) {}
    });
    
    var contentMiddle = document.getElementById('content_middle');
    if (contentMiddle) contentMiddle.style.display = 'none';
    var spinner = document.getElementById('content_middle_management_reley_spiner');
    if (spinner) spinner.style.display = 'block';
    var action = document.getElementById('identifier_execution_action');
    if (action) action.style.display = 'block';
    var actionReal = document.getElementById('identifier_execution_action_real');
    if (actionReal) actionReal.style.display = 'block';
    
    if (window.modeInterval1) clearInterval(window.modeInterval1);
    
    function mode() {
        $.ajax({
            url: '/script/php/identifier_action_real.php',
            type: 'POST',
            data: {
                param: selectedSize,
                param2: "1"
            },
            success: function(data) {
                var actionRealElem = document.getElementById('identifier_execution_action_real');
                if (actionRealElem) actionRealElem.innerHTML = data;
                
                if (!data.includes("ожидание")) {
                    if (window.modeInterval1) {
                        clearInterval(window.modeInterval1);
                        window.modeInterval1 = null;
                    }
                    
                    setTimeout(function() {
                        var contentMid = document.getElementById('content_middle');
                        var spinnerElem = document.getElementById('content_middle_management_reley_spiner');
                        var actionElem = document.getElementById('identifier_execution_action');
                        var actionRealElem2 = document.getElementById('identifier_execution_action_real');
                        
                        if (contentMid) contentMid.style.display = 'block';
                        if (spinnerElem) spinnerElem.style.display = 'none';
                        if (actionElem) actionElem.style.display = 'none';
                        if (actionRealElem2) actionRealElem2.style.display = 'none';
                        
                        var radio1 = document.getElementById("reley_1_1");
                        var radio2 = document.getElementById("reley_1_2");
                        var radio3 = document.getElementById("reley_1_3");
                        var btn = document.getElementById('button_reley_1');
                        
                        if (radio1) radio1.checked = false;
                        if (radio2) radio2.checked = false;
                        if (radio3) radio3.checked = false;
                        if (btn) btn.style.display = 'none';
                        
                        if (typeof updateSendButtons === 'function') {
                            updateSendButtons();
                        }
                    }, 500);
                }
            }
        });
    }
    
    window.modeInterval1 = setInterval(mode, 500);
    
    setTimeout(function() {
        if (window.modeInterval1) {
            clearInterval(window.modeInterval1);
            window.modeInterval1 = null;
            var contentMid = document.getElementById('content_middle');
            var spinnerElem = document.getElementById('content_middle_management_reley_spiner');
            var actionElem = document.getElementById('identifier_execution_action');
            var actionRealElem = document.getElementById('identifier_execution_action_real');
            
            if (contentMid) contentMid.style.display = 'block';
            if (spinnerElem) spinnerElem.style.display = 'none';
            if (actionElem) actionElem.style.display = 'none';
            if (actionRealElem) actionRealElem.style.display = 'none';
        }
    }, 30000);
}

function send_reley_2() {
    const radioButtons = document.querySelectorAll('input[name="reley_2"]');
    let selectedSize;
    for (const radioButton of radioButtons) {
        if (radioButton.checked) {
            selectedSize = radioButton.value;
            break;
        }
    }
    
    $.ajax({
        url: '/mqtt_publish.php',
        type: 'POST',
        data: {
            param: selectedSize,
            param2: "2"
        },
        success: function(response) {}
    });
    
    var contentMiddle = document.getElementById('content_middle');
    if (contentMiddle) contentMiddle.style.display = 'none';
    var spinner = document.getElementById('content_middle_management_reley_spiner');
    if (spinner) spinner.style.display = 'block';
    var action = document.getElementById('identifier_execution_action');
    if (action) action.style.display = 'block';
    var actionReal = document.getElementById('identifier_execution_action_real');
    if (actionReal) actionReal.style.display = 'block';
    
    if (window.modeInterval2) clearInterval(window.modeInterval2);
    
    function mode() {
        $.ajax({
            url: '/script/php/identifier_action_real.php',
            type: 'POST',
            data: {
                param: selectedSize,
                param2: "2"
            },
            success: function(data) {
                var actionRealElem = document.getElementById('identifier_execution_action_real');
                if (actionRealElem) actionRealElem.innerHTML = data;
                
                if (!data.includes("ожидание")) {
                    if (window.modeInterval2) {
                        clearInterval(window.modeInterval2);
                        window.modeInterval2 = null;
                    }
                    
                    setTimeout(function() {
                        var contentMid = document.getElementById('content_middle');
                        var spinnerElem = document.getElementById('content_middle_management_reley_spiner');
                        var actionElem = document.getElementById('identifier_execution_action');
                        var actionRealElem2 = document.getElementById('identifier_execution_action_real');
                        
                        if (contentMid) contentMid.style.display = 'block';
                        if (spinnerElem) spinnerElem.style.display = 'none';
                        if (actionElem) actionElem.style.display = 'none';
                        if (actionRealElem2) actionRealElem2.style.display = 'none';
                        
                        var radio1 = document.getElementById("reley_2_1");
                        var radio2 = document.getElementById("reley_2_2");
                        var radio3 = document.getElementById("reley_2_3");
                        var btn = document.getElementById('button_reley_2');
                        
                        if (radio1) radio1.checked = false;
                        if (radio2) radio2.checked = false;
                        if (radio3) radio3.checked = false;
                        if (btn) btn.style.display = 'none';
                        
                        if (typeof updateSendButtons === 'function') {
                            updateSendButtons();
                        }
                    }, 500);
                }
            }
        });
    }
    
    window.modeInterval2 = setInterval(mode, 500);
    
    setTimeout(function() {
        if (window.modeInterval2) {
            clearInterval(window.modeInterval2);
            window.modeInterval2 = null;
            var contentMid = document.getElementById('content_middle');
            var spinnerElem = document.getElementById('content_middle_management_reley_spiner');
            var actionElem = document.getElementById('identifier_execution_action');
            var actionRealElem = document.getElementById('identifier_execution_action_real');
            
            if (contentMid) contentMid.style.display = 'block';
            if (spinnerElem) spinnerElem.style.display = 'none';
            if (actionElem) actionElem.style.display = 'none';
            if (actionRealElem) actionRealElem.style.display = 'none';
        }
    }, 30000);
}

function send_reley_3() {
    const radioButtons = document.querySelectorAll('input[name="reley_3"]');
    let selectedSize;
    for (const radioButton of radioButtons) {
        if (radioButton.checked) {
            selectedSize = radioButton.value;
            break;
        }
    }
    
    $.ajax({
        url: '/mqtt_publish.php',
        type: 'POST',
        data: {
            param: selectedSize,
            param2: "3"
        },
        success: function(response) {}
    });
    
    var contentMiddle = document.getElementById('content_middle');
    if (contentMiddle) contentMiddle.style.display = 'none';
    var spinner = document.getElementById('content_middle_management_reley_spiner');
    if (spinner) spinner.style.display = 'block';
    var action = document.getElementById('identifier_execution_action');
    if (action) action.style.display = 'block';
    var actionReal = document.getElementById('identifier_execution_action_real');
    if (actionReal) actionReal.style.display = 'block';
    
    if (window.modeInterval3) clearInterval(window.modeInterval3);
    
    function mode() {
        $.ajax({
            url: '/script/php/identifier_action_real.php',
            type: 'POST',
            data: {
                param: selectedSize,
                param2: "3"
            },
            success: function(data) {
                var actionRealElem = document.getElementById('identifier_execution_action_real');
                if (actionRealElem) actionRealElem.innerHTML = data;
                
                if (!data.includes("ожидание")) {
                    if (window.modeInterval3) {
                        clearInterval(window.modeInterval3);
                        window.modeInterval3 = null;
                    }
                    
                    setTimeout(function() {
                        var contentMid = document.getElementById('content_middle');
                        var spinnerElem = document.getElementById('content_middle_management_reley_spiner');
                        var actionElem = document.getElementById('identifier_execution_action');
                        var actionRealElem2 = document.getElementById('identifier_execution_action_real');
                        
                        if (contentMid) contentMid.style.display = 'block';
                        if (spinnerElem) spinnerElem.style.display = 'none';
                        if (actionElem) actionElem.style.display = 'none';
                        if (actionRealElem2) actionRealElem2.style.display = 'none';
                        
                        var radio1 = document.getElementById("reley_3_1");
                        var radio2 = document.getElementById("reley_3_2");
                        var radio3 = document.getElementById("reley_3_3");
                        var btn = document.getElementById('button_reley_3');
                        
                        if (radio1) radio1.checked = false;
                        if (radio2) radio2.checked = false;
                        if (radio3) radio3.checked = false;
                        if (btn) btn.style.display = 'none';
                        
                        if (typeof updateSendButtons === 'function') {
                            updateSendButtons();
                        }
                    }, 500);
                }
            }
        });
    }
    
    window.modeInterval3 = setInterval(mode, 500);
    
    setTimeout(function() {
        if (window.modeInterval3) {
            clearInterval(window.modeInterval3);
            window.modeInterval3 = null;
            var contentMid = document.getElementById('content_middle');
            var spinnerElem = document.getElementById('content_middle_management_reley_spiner');
            var actionElem = document.getElementById('identifier_execution_action');
            var actionRealElem = document.getElementById('identifier_execution_action_real');
            
            if (contentMid) contentMid.style.display = 'block';
            if (spinnerElem) spinnerElem.style.display = 'none';
            if (actionElem) actionElem.style.display = 'none';
            if (actionRealElem) actionRealElem.style.display = 'none';
        }
    }, 30000);
}

function send_reley_4() {
    const radioButtons = document.querySelectorAll('input[name="reley_4"]');
    let selectedSize;
    for (const radioButton of radioButtons) {
        if (radioButton.checked) {
            selectedSize = radioButton.value;
            break;
        }
    }
    
    $.ajax({
        url: '/mqtt_publish.php',
        type: 'POST',
        data: {
            param: selectedSize,
            param2: "4"
        },
        success: function(response) {}
    });
    
    var contentMiddle = document.getElementById('content_middle');
    if (contentMiddle) contentMiddle.style.display = 'none';
    var spinner = document.getElementById('content_middle_management_reley_spiner');
    if (spinner) spinner.style.display = 'block';
    var action = document.getElementById('identifier_execution_action');
    if (action) action.style.display = 'block';
    var actionReal = document.getElementById('identifier_execution_action_real');
    if (actionReal) actionReal.style.display = 'block';
    
    if (window.modeInterval4) clearInterval(window.modeInterval4);
    
    function mode() {
        $.ajax({
            url: '/script/php/identifier_action_real.php',
            type: 'POST',
            data: {
                param: selectedSize,
                param2: "4"
            },
            success: function(data) {
                var actionRealElem = document.getElementById('identifier_execution_action_real');
                if (actionRealElem) actionRealElem.innerHTML = data;
                
                if (!data.includes("ожидание")) {
                    if (window.modeInterval4) {
                        clearInterval(window.modeInterval4);
                        window.modeInterval4 = null;
                    }
                    
                    setTimeout(function() {
                        var contentMid = document.getElementById('content_middle');
                        var spinnerElem = document.getElementById('content_middle_management_reley_spiner');
                        var actionElem = document.getElementById('identifier_execution_action');
                        var actionRealElem2 = document.getElementById('identifier_execution_action_real');
                        
                        if (contentMid) contentMid.style.display = 'block';
                        if (spinnerElem) spinnerElem.style.display = 'none';
                        if (actionElem) actionElem.style.display = 'none';
                        if (actionRealElem2) actionRealElem2.style.display = 'none';
                        
                        var radio1 = document.getElementById("reley_4_1");
                        var radio2 = document.getElementById("reley_4_2");
                        var radio3 = document.getElementById("reley_4_3");
                        var btn = document.getElementById('button_reley_4');
                        
                        if (radio1) radio1.checked = false;
                        if (radio2) radio2.checked = false;
                        if (radio3) radio3.checked = false;
                        if (btn) btn.style.display = 'none';
                        
                        if (typeof updateSendButtons === 'function') {
                            updateSendButtons();
                        }
                    }, 500);
                }
            }
        });
    }
    
    window.modeInterval4 = setInterval(mode, 500);
    
    setTimeout(function() {
        if (window.modeInterval4) {
            clearInterval(window.modeInterval4);
            window.modeInterval4 = null;
            var contentMid = document.getElementById('content_middle');
            var spinnerElem = document.getElementById('content_middle_management_reley_spiner');
            var actionElem = document.getElementById('identifier_execution_action');
            var actionRealElem = document.getElementById('identifier_execution_action_real');
            
            if (contentMid) contentMid.style.display = 'block';
            if (spinnerElem) spinnerElem.style.display = 'none';
            if (actionElem) actionElem.style.display = 'none';
            if (actionRealElem) actionRealElem.style.display = 'none';
        }
    }, 30000);
}

function loadLastChange(deviceId) {
    fetch('/api/get_last_change.php?id=' + deviceId)
        .then(response => response.json())
        .then(data => {
            const dateElem = document.getElementById('last_change_date_' + deviceId);
            if (!dateElem) return;

            if (data.success && data.data && data.data.last_change) {
                const row = data.data;
                const lastChangeDate = new Date(row.last_change);

                // Форматирование даты и времени до минуты (дд.мм.гггг чч:мм)
                const day = String(lastChangeDate.getDate()).padStart(2, '0');
                const month = String(lastChangeDate.getMonth() + 1).padStart(2, '0');
                const year = lastChangeDate.getFullYear();
                const hours = String(lastChangeDate.getHours()).padStart(2, '0');
                const minutes = String(lastChangeDate.getMinutes()).padStart(2, '0');

                const formattedDateTime = `${day}.${month}.${year} ${hours}:${minutes}`;

                // Определяем понятный текст изменения
                let changeText = '';
                const changeType = row.change_type || 'none';

                if (changeType === 'relay') {
                    // Извлекаем номер реле из changed_fields (например "relay_4")
                    const relayMatch = row.changed_fields ? row.changed_fields.match(/relay_(\d+)/) : null;
                    const relayNum = relayMatch ? relayMatch[1] : '?';

                    // Переводим 0/1/2 в понятный текст
                    const prevState = getRelayStateText(row.previous_value);
                    const newState  = getRelayStateText(row.new_value);

                    changeText = `Реле №${relayNum} → ${newState} (было: ${prevState})`;

                } else if (changeType === 'status') {
                    const prevStatus = getDeviceStatusText(row.previous_value);
                    const newStatus  = getDeviceStatusText(row.new_value);
                    changeText = `Статус → ${newStatus} (было: ${prevStatus})`;

                } else if (changeType === 'connection') {
                    changeText = `Подключение → ${row.new_value}`;
                } else {
                    // fallback
                    changeText = `${row.change_type || 'изменение'}: ${row.changed_fields || ''} ${row.previous_value} → ${row.new_value}`;
                }

                // Итоговый вывод (можно оформить в две строки или HTML)
                dateElem.innerHTML = `
                    ${formattedDateTime}<br>
                    ${changeText}
                `;

            } else {
                dateElem.textContent = 'нет данных';
            }
        })
        .catch(() => {
            const dateElem = document.getElementById('last_change_date_' + deviceId);
            if (dateElem) dateElem.textContent = 'ошибка загрузки';
        });
}

// Вспомогательные функции для понятного текста
function getRelayStateText(value) {
    const v = parseInt(value);
    switch (v) {
        case 0: return 'выключено';
        case 1: return 'включено';
        case 2: return 'ошибка';
        default: return `неизвестно (${v})`;
    }
}

function getDeviceStatusText(value) {
    const v = parseInt(value);
    switch (v) {
        case 0: return 'оффлайн';
        case 1: return 'онлайн';
        case 2: return 'ошибка';
        default: return `неизвестно (${v})`;
    }
}

$(document).ready(function() {
    const deviceId = <?php echo $id; ?>;
    loadLastChange(deviceId);
    
    var lastChangeInterval = setInterval(function() {
        loadLastChange(deviceId);
    }, 1000);
    
    if (window.controlPageIntervals) {
        window.controlPageIntervals.push(lastChangeInterval);
    }
});
</script>