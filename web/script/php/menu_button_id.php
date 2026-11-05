<?php
include 'conectbd.php';
//Получение списка устройств
$numb_devices = 0;
$sql_general_info = mysqli_query($connect, "SELECT * FROM `general_info` ORDER BY id");
while ($res_sql_general_info = mysqli_fetch_array($sql_general_info)) {
?>
<div class="button_id" id="button_id_<?php echo $res_sql_general_info[0]; ?>">
    <div class="button_id_text" id="button_id_text">
        <p>
            <span class="button_id_text_number"><?php echo $res_sql_general_info[1]; ?></span> </br>
            <span class="button_id_text_addres"><?php echo $res_sql_general_info[2]; ?></span>
        </p>
    </div>
    <div class="button_id_identifier_offlie">
        <span class="identifier_online">*</span>
    </div>
</div>

<?php } ?>