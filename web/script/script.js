var login_input = null;
var password_input = null;
var summa = null;
function safeGetElement(id) {
    var elem = document.getElementById(id);
    if (!elem) {
        return null;
    }
    return elem;
}

function show_hide_password(target) {
    var input = document.getElementById('password_input');
    if (input.getAttribute('type') == 'password') {
        target.classList.add('view');
        input.setAttribute('type', 'text');
    } else {
        target.classList.remove('view');
        input.setAttribute('type', 'password');
    }
    return false;
}

function login() {
    var a = login_input.value;
    var b = password_input.value;
    var data = "a=" + encodeURIComponent(a) + "&b=" + encodeURIComponent(b);
    var xhr = new XMLHttpRequest();
    xhr.open("POST", "index.php", true);
    xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
    xhr.send(data);
    xhr.onreadystatechange = function() {
        if (xhr.readyState == 4) {
            if (xhr.status == 200) {
                summa.innerHTML = xhr.responseText;
            }
        }
    }
}

var button_id = document.getElementsByClassName('button_id');
var cnt = button_id.length;
for (var i = 0; i < cnt; i++) {
    button_id[i].onclick = function() {
        for (var j = 0; j < cnt; j++) {
            button_id[j].style.border = "2px solid #b3b0b0";
        };
        this.style.border = "2px solid #ff8d02";
    };
};

window.onload = function() {
    document.body.classList.add('loaded_hiding');
    window.setTimeout(function() {
        document.body.classList.add('loaded');
        document.body.classList.remove('loaded_hiding');
    }, 500);
}

function show_button_reley_1_1() {
    var reley = safeGetElement("reley_1_1");
    if (!reley) return;
    
    if (reley.checked) {
        reley.checked = false;
        var btn = safeGetElement('button_reley_1');
        if (btn) btn.style.display = 'none';
        var btnNone = safeGetElement('button_reley_1_none');
        if (btnNone) btnNone.style.display = 'none';
    } else {
        reley.checked = true;
        var btn = safeGetElement('button_reley_1');
        if (btn) btn.style.display = 'block';
        var btnNone = safeGetElement('button_reley_1_none');
        if (btnNone) btnNone.style.display = 'none';
        var r2 = safeGetElement("reley_1_2");
        if (r2) r2.checked = false;
        var r3 = safeGetElement("reley_1_3");
        if (r3) r3.checked = false;
    }
    updateSendButtons();
}

function show_button_reley_1_2() {
    var reley = safeGetElement("reley_1_2");
    if (!reley) return;
    
    if (reley.checked) {
        reley.checked = false;
        var btn = safeGetElement('button_reley_1');
        if (btn) btn.style.display = 'none';
        var btnNone = safeGetElement('button_reley_1_none');
        if (btnNone) btnNone.style.display = 'none';
    } else {
        reley.checked = true;
        var btn = safeGetElement('button_reley_1');
        if (btn) btn.style.display = 'block';
        var btnNone = safeGetElement('button_reley_1_none');
        if (btnNone) btnNone.style.display = 'none';
        var r1 = safeGetElement("reley_1_1");
        if (r1) r1.checked = false;
        var r3 = safeGetElement("reley_1_3");
        if (r3) r3.checked = false;
    }
    updateSendButtons();
}

function show_button_reley_1_3() {
    var reley = safeGetElement("reley_1_3");
    if (!reley) return;
    
    if (reley.checked) {
        reley.checked = false;
        var btn = safeGetElement('button_reley_1');
        if (btn) btn.style.display = 'none';
        var btnNone = safeGetElement('button_reley_1_none');
        if (btnNone) btnNone.style.display = 'none';
    } else {
        reley.checked = true;
        var btn = safeGetElement('button_reley_1');
        if (btn) btn.style.display = 'block';
        var btnNone = safeGetElement('button_reley_1_none');
        if (btnNone) btnNone.style.display = 'none';
        var r1 = safeGetElement("reley_1_1");
        if (r1) r1.checked = false;
        var r2 = safeGetElement("reley_1_2");
        if (r2) r2.checked = false;
    }
    updateSendButtons();
}

function show_button_reley_2_1() {
    var reley = safeGetElement("reley_2_1");
    if (!reley) return;
    
    if (reley.checked) {
        reley.checked = false;
        var btn = safeGetElement('button_reley_2');
        if (btn) btn.style.display = 'none';
        var btnNone = safeGetElement('button_reley_2_none');
        if (btnNone) btnNone.style.display = 'none';
    } else {
        reley.checked = true;
        var btn = safeGetElement('button_reley_2');
        if (btn) btn.style.display = 'block';
        var btnNone = safeGetElement('button_reley_2_none');
        if (btnNone) btnNone.style.display = 'none';
        var r2 = safeGetElement("reley_2_2");
        if (r2) r2.checked = false;
        var r3 = safeGetElement("reley_2_3");
        if (r3) r3.checked = false;
    }
    updateSendButtons();
}

function show_button_reley_2_2() {
    var reley = safeGetElement("reley_2_2");
    if (!reley) return;
    
    if (reley.checked) {
        reley.checked = false;
        var btn = safeGetElement('button_reley_2');
        if (btn) btn.style.display = 'none';
        var btnNone = safeGetElement('button_reley_2_none');
        if (btnNone) btnNone.style.display = 'none';
    } else {
        reley.checked = true;
        var btn = safeGetElement('button_reley_2');
        if (btn) btn.style.display = 'block';
        var btnNone = safeGetElement('button_reley_2_none');
        if (btnNone) btnNone.style.display = 'none';
        var r1 = safeGetElement("reley_2_1");
        if (r1) r1.checked = false;
        var r3 = safeGetElement("reley_2_3");
        if (r3) r3.checked = false;
    }
    updateSendButtons();
}

function show_button_reley_2_3() {
    var reley = safeGetElement("reley_2_3");
    if (!reley) return;
    
    if (reley.checked) {
        reley.checked = false;
        var btn = safeGetElement('button_reley_2');
        if (btn) btn.style.display = 'none';
        var btnNone = safeGetElement('button_reley_2_none');
        if (btnNone) btnNone.style.display = 'none';
    } else {
        reley.checked = true;
        var btn = safeGetElement('button_reley_2');
        if (btn) btn.style.display = 'block';
        var btnNone = safeGetElement('button_reley_2_none');
        if (btnNone) btnNone.style.display = 'none';
        var r1 = safeGetElement("reley_2_1");
        if (r1) r1.checked = false;
        var r2 = safeGetElement("reley_2_2");
        if (r2) r2.checked = false;
    }
    updateSendButtons();
}

function show_button_reley_3_1() {
    var reley = safeGetElement("reley_3_1");
    if (!reley) return;
    
    if (reley.checked) {
        reley.checked = false;
        var btn = safeGetElement('button_reley_3');
        if (btn) btn.style.display = 'none';
        var btnNone = safeGetElement('button_reley_3_none');
        if (btnNone) btnNone.style.display = 'none';
    } else {
        reley.checked = true;
        var btn = safeGetElement('button_reley_3');
        if (btn) btn.style.display = 'block';
        var btnNone = safeGetElement('button_reley_3_none');
        if (btnNone) btnNone.style.display = 'none';
        var r2 = safeGetElement("reley_3_2");
        if (r2) r2.checked = false;
        var r3 = safeGetElement("reley_3_3");
        if (r3) r3.checked = false;
    }
    updateSendButtons();
}

function show_button_reley_3_2() {
    var reley = safeGetElement("reley_3_2");
    if (!reley) return;
    
    if (reley.checked) {
        reley.checked = false;
        var btn = safeGetElement('button_reley_3');
        if (btn) btn.style.display = 'none';
        var btnNone = safeGetElement('button_reley_3_none');
        if (btnNone) btnNone.style.display = 'none';
    } else {
        reley.checked = true;
        var btn = safeGetElement('button_reley_3');
        if (btn) btn.style.display = 'block';
        var btnNone = safeGetElement('button_reley_3_none');
        if (btnNone) btnNone.style.display = 'none';
        var r1 = safeGetElement("reley_3_1");
        if (r1) r1.checked = false;
        var r3 = safeGetElement("reley_3_3");
        if (r3) r3.checked = false;
    }
    updateSendButtons();
}

function show_button_reley_3_3() {
    var reley = safeGetElement("reley_3_3");
    if (!reley) return;
    
    if (reley.checked) {
        reley.checked = false;
        var btn = safeGetElement('button_reley_3');
        if (btn) btn.style.display = 'none';
        var btnNone = safeGetElement('button_reley_3_none');
        if (btnNone) btnNone.style.display = 'none';
    } else {
        reley.checked = true;
        var btn = safeGetElement('button_reley_3');
        if (btn) btn.style.display = 'block';
        var btnNone = safeGetElement('button_reley_3_none');
        if (btnNone) btnNone.style.display = 'none';
        var r1 = safeGetElement("reley_3_1");
        if (r1) r1.checked = false;
        var r2 = safeGetElement("reley_3_2");
        if (r2) r2.checked = false;
    }
    updateSendButtons();
}

function show_button_reley_4_1() {
    var reley = safeGetElement("reley_4_1");
    if (!reley) return;
    
    if (reley.checked) {
        reley.checked = false;
        var btn = safeGetElement('button_reley_4');
        if (btn) btn.style.display = 'none';
        var btnNone = safeGetElement('button_reley_4_none');
        if (btnNone) btnNone.style.display = 'none';
    } else {
        reley.checked = true;
        var btn = safeGetElement('button_reley_4');
        if (btn) btn.style.display = 'block';
        var btnNone = safeGetElement('button_reley_4_none');
        if (btnNone) btnNone.style.display = 'none';
        var r2 = safeGetElement("reley_4_2");
        if (r2) r2.checked = false;
        var r3 = safeGetElement("reley_4_3");
        if (r3) r3.checked = false;
    }
    updateSendButtons();
}

function show_button_reley_4_2() {
    var reley = safeGetElement("reley_4_2");
    if (!reley) return;
    
    if (reley.checked) {
        reley.checked = false;
        var btn = safeGetElement('button_reley_4');
        if (btn) btn.style.display = 'none';
        var btnNone = safeGetElement('button_reley_4_none');
        if (btnNone) btnNone.style.display = 'none';
    } else {
        reley.checked = true;
        var btn = safeGetElement('button_reley_4');
        if (btn) btn.style.display = 'block';
        var btnNone = safeGetElement('button_reley_4_none');
        if (btnNone) btnNone.style.display = 'none';
        var r1 = safeGetElement("reley_4_1");
        if (r1) r1.checked = false;
        var r3 = safeGetElement("reley_4_3");
        if (r3) r3.checked = false;
    }
    updateSendButtons();
}

function show_button_reley_4_3() {
    var reley = safeGetElement("reley_4_3");
    if (!reley) return;
    
    if (reley.checked) {
        reley.checked = false;
        var btn = safeGetElement('button_reley_4');
        if (btn) btn.style.display = 'none';
        var btnNone = safeGetElement('button_reley_4_none');
        if (btnNone) btnNone.style.display = 'none';
    } else {
        reley.checked = true;
        var btn = safeGetElement('button_reley_4');
        if (btn) btn.style.display = 'block';
        var btnNone = safeGetElement('button_reley_4_none');
        if (btnNone) btnNone.style.display = 'none';
        var r1 = safeGetElement("reley_4_1");
        if (r1) r1.checked = false;
        var r2 = safeGetElement("reley_4_2");
        if (r2) r2.checked = false;
    }
    updateSendButtons();
}

function updateSendButtons() {
    var bis = document.querySelectorAll('input[type="radio"]:checked');
    for (var i = 1; i <= 4; i++) {
        var btn = safeGetElement('button_reley_' + i);
        if (!btn) continue;
        var hasChecked = false;
        for (var j = 0; j < bis.length; j++) {
            if (bis[j].name == 'reley_' + i) {
                hasChecked = true;
                break;
            }
        }
        if (hasChecked) {
            btn.style.display = 'block';
            btn.style.backgroundColor = '#ff8d02';
        } else {
            btn.style.display = 'none';
            btn.style.backgroundColor = '#ccc';
        }
    }
}
window.modeInterval1 = null;
window.modeInterval2 = null;
window.modeInterval3 = null;
window.modeInterval4 = null;

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
    var contentMiddle = safeGetElement('content_middle');
    if (contentMiddle) contentMiddle.style.display = 'none';
    var spinner = safeGetElement('content_middle_management_reley_spiner');
    if (spinner) spinner.style.display = 'block';
    var action = safeGetElement('identifier_execution_action');
    if (action) action.style.display = 'block';
    var actionReal = safeGetElement('identifier_execution_action_real');
    if (actionReal) actionReal.style.display = 'block';
    
    function mode() {
    $.ajax({
        url: '/script/php/identifier_action_real.php',
        type: 'POST',
        data: {
            param: selectedSize,
            param2: "1"
        },
        success: function(data) {
            console.log("Status check response:", data);
            var actionRealElem = document.getElementById('identifier_execution_action_real');
            if (actionRealElem) actionRealElem.innerHTML = data;
            
            if (!data.includes("ожидание")) {
                console.log("Command completed (success or error), stopping polling");
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
                    
                    console.log("UI reset complete");
                }, 500);
            }
        },
        error: function(xhr, status, error) {
            console.error("Error checking status:", error);
        }
    });
}
    window.modeInterval1 = setInterval(mode, 100);
    setTimeout(function() {
        if (window.modeInterval1) {
            clearInterval(window.modeInterval1);
            window.modeInterval1 = null;
            var contentMid = safeGetElement('content_middle');
            if (contentMid) contentMid.style.display = 'block';
            var spinnerElem = safeGetElement('content_middle_management_reley_spiner');
            if (spinnerElem) spinnerElem.style.display = 'none';
            var actionElem = safeGetElement('identifier_execution_action');
            if (actionElem) actionElem.style.display = 'none';
            var actionRealElem = safeGetElement('identifier_execution_action_real');
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
    var contentMiddle = safeGetElement('content_middle');
    if (contentMiddle) contentMiddle.style.display = 'none';
    var spinner = safeGetElement('content_middle_management_reley_spiner');
    if (spinner) spinner.style.display = 'block';
    var action = safeGetElement('identifier_execution_action');
    if (action) action.style.display = 'block';
    var actionReal = safeGetElement('identifier_execution_action_real');
    if (actionReal) actionReal.style.display = 'block';
    
    function mode() {
        $.ajax({
            url: '/script/php/identifier_action_real.php',
            type: 'POST',
            data: {
                param: selectedSize,
                param2: "2"
            },
            success: function(data) {
                var actionRealElem = safeGetElement('identifier_execution_action_real');
                if (actionRealElem) actionRealElem.innerHTML = data;
                if (!data.includes("ожидание")) {
                    if (window.modeInterval2) {
                        clearInterval(window.modeInterval2);
                        window.modeInterval2 = null;
                        setTimeout(function() {
                            var contentMid = safeGetElement('content_middle');
                            if (contentMid) contentMid.style.display = 'block';
                            var spinnerElem = safeGetElement('content_middle_management_reley_spiner');
                            if (spinnerElem) spinnerElem.style.display = 'none';
                            var actionElem = safeGetElement('identifier_execution_action');
                            if (actionElem) actionElem.style.display = 'none';
                            var actionRealElem2 = safeGetElement('identifier_execution_action_real');
                            if (actionRealElem2) actionRealElem2.style.display = 'none';
                        }, 2000);
                    }
                }
            }
        });
    };
    window.modeInterval2 = setInterval(mode, 100);
    setTimeout(function() {
        if (window.modeInterval2) {
            clearInterval(window.modeInterval2);
            window.modeInterval2 = null;
            var contentMid = safeGetElement('content_middle');
            if (contentMid) contentMid.style.display = 'block';
            var spinnerElem = safeGetElement('content_middle_management_reley_spiner');
            if (spinnerElem) spinnerElem.style.display = 'none';
            var actionElem = safeGetElement('identifier_execution_action');
            if (actionElem) actionElem.style.display = 'none';
            var actionRealElem = safeGetElement('identifier_execution_action_real');
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
    var contentMiddle = safeGetElement('content_middle');
    if (contentMiddle) contentMiddle.style.display = 'none';
    var spinner = safeGetElement('content_middle_management_reley_spiner');
    if (spinner) spinner.style.display = 'block';
    var action = safeGetElement('identifier_execution_action');
    if (action) action.style.display = 'block';
    var actionReal = safeGetElement('identifier_execution_action_real');
    if (actionReal) actionReal.style.display = 'block';
    
    function mode() {
        $.ajax({
            url: '/script/php/identifier_action_real.php',
            type: 'POST',
            data: {
                param: selectedSize,
                param2: "3"
            },
            success: function(data) {
                var actionRealElem = safeGetElement('identifier_execution_action_real');
                if (actionRealElem) actionRealElem.innerHTML = data;
                if (!data.includes("ожидание")) {
                    if (window.modeInterval3) {
                        clearInterval(window.modeInterval3);
                        window.modeInterval3 = null;
                        setTimeout(function() {
                            var contentMid = safeGetElement('content_middle');
                            if (contentMid) contentMid.style.display = 'block';
                            var spinnerElem = safeGetElement('content_middle_management_reley_spiner');
                            if (spinnerElem) spinnerElem.style.display = 'none';
                            var actionElem = safeGetElement('identifier_execution_action');
                            if (actionElem) actionElem.style.display = 'none';
                            var actionRealElem2 = safeGetElement('identifier_execution_action_real');
                            if (actionRealElem2) actionRealElem2.style.display = 'none';
                        }, 2000);
                    }
                }
            }
        });
    };
    window.modeInterval3 = setInterval(mode, 100);
    setTimeout(function() {
        if (window.modeInterval3) {
            clearInterval(window.modeInterval3);
            window.modeInterval3 = null;
            var contentMid = safeGetElement('content_middle');
            if (contentMid) contentMid.style.display = 'block';
            var spinnerElem = safeGetElement('content_middle_management_reley_spiner');
            if (spinnerElem) spinnerElem.style.display = 'none';
            var actionElem = safeGetElement('identifier_execution_action');
            if (actionElem) actionElem.style.display = 'none';
            var actionRealElem = safeGetElement('identifier_execution_action_real');
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
    var contentMiddle = safeGetElement('content_middle');
    if (contentMiddle) contentMiddle.style.display = 'none';
    var spinner = safeGetElement('content_middle_management_reley_spiner');
    if (spinner) spinner.style.display = 'block';
    var action = safeGetElement('identifier_execution_action');
    if (action) action.style.display = 'block';
    var actionReal = safeGetElement('identifier_execution_action_real');
    if (actionReal) actionReal.style.display = 'block';
    
    function mode() {
        $.ajax({
            url: '/script/php/identifier_action_real.php',
            type: 'POST',
            data: {
                param: selectedSize,
                param2: "4"
            },
            success: function(data) {
                var actionRealElem = safeGetElement('identifier_execution_action_real');
                if (actionRealElem) actionRealElem.innerHTML = data;
                if (!data.includes("ожидание")) {
                    if (window.modeInterval4) {
                        clearInterval(window.modeInterval4);
                        window.modeInterval4 = null;
                        setTimeout(function() {
                            var contentMid = safeGetElement('content_middle');
                            if (contentMid) contentMid.style.display = 'block';
                            var spinnerElem = safeGetElement('content_middle_management_reley_spiner');
                            if (spinnerElem) spinnerElem.style.display = 'none';
                            var actionElem = safeGetElement('identifier_execution_action');
                            if (actionElem) actionElem.style.display = 'none';
                            var actionRealElem2 = safeGetElement('identifier_execution_action_real');
                            if (actionRealElem2) actionRealElem2.style.display = 'none';
                        }, 2000);
                    }
                }
            }
        });
    };
    window.modeInterval4 = setInterval(mode, 100);
    setTimeout(function() {
        if (window.modeInterval4) {
            clearInterval(window.modeInterval4);
            window.modeInterval4 = null;
            var contentMid = safeGetElement('content_middle');
            if (contentMid) contentMid.style.display = 'block';
            var spinnerElem = safeGetElement('content_middle_management_reley_spiner');
            if (spinnerElem) spinnerElem.style.display = 'none';
            var actionElem = safeGetElement('identifier_execution_action');
            if (actionElem) actionElem.style.display = 'none';
            var actionRealElem = safeGetElement('identifier_execution_action_real');
            if (actionRealElem) actionRealElem.style.display = 'none';
        }
    }, 30000);
}