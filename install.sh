#!/bin/bash
# =============================================================================
#  Автоматическое развёртывание сервера управления релейными платами
#  MQTT-брокер Mosquitto + MariaDB + веб-панель (Apache/PHP) + MQTT-подписчик
#
#  Поддерживаемые ОС: Ubuntu 22.04 / 24.04 / 26.04, Debian 12 / 13.
#  Скрипт можно запускать повторно: он обновит файлы панели и сохранит пароли и данные.
#
#  Использование:
#     git clone https://github.com/pptkaa-del/relay-server.git /opt/relay-server
#     sudo bash /opt/relay-server/install.sh [параметры]
#  Обновление:
#     sudo relay-update        (git pull + повторная установка)
#
#  Параметры (все необязательные):
#     --admin-user ЛОГИН     логин веб-панели                (по умолчанию admin)
#     --admin-pass ПАРОЛЬ    пароль веб-панели               (по умолчанию генерируется)
#     --domain ИМЯ           доменное имя сервера: включает HTTPS (Let's Encrypt)
#                            для панели и MQTT over TLS на порту 8883
#     --email ПОЧТА          e-mail для Let's Encrypt (обязателен вместе с --domain)
#     --tz ЗОНА              часовой пояс                    (по умолчанию Europe/Moscow)
#     --mqtt-port ПОРТ       порт MQTT без TLS                (по умолчанию 1883 — как в прошивке)
#     --db-name ИМЯ          имя базы данных                 (по умолчанию u2702672_service_reley_v1)
#     --retention ДНЕЙ       сколько дней хранить журнал MQTT-сообщений (по умолчанию 14)
#     --events-retention ДНЕЙ  сколько дней хранить журнал событий плат (по умолчанию 180)
#     --devices "1 2 3"      сразу добавить платы с этими ID
#     --no-firewall          не настраивать ufw
#     --replace-xampp        остановить и отключить старую установку XAMPP (/opt/lampp)
# =============================================================================
set -euo pipefail

SRC_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WEB_ROOT=/var/www/relay
CONF_DIR=/etc/relay-server
ENV_FILE=$CONF_DIR/install.env
CRED_FILE=/root/relay-server-credentials.txt

ADMIN_USER=admin; ADMIN_PASS=""; DOMAIN=""; EMAIL=""; TZ_NAME=Europe/Moscow
MQTT_PORT=1883; DB_NAME_ARG=""; RETENTION=14; EV_RETENTION=180; DEVICES=""; FIREWALL=1; REPLACE_XAMPP=0

log()  { printf '\n\033[1;34m→ %s\033[0m\n' "$*"; }
ok()   { printf '  \033[32m✔\033[0m %s\n' "$*"; }
warn() { printf '  \033[33m⚠ %s\033[0m\n' "$*"; }
die()  { printf '\n\033[1;31m✖ %s\033[0m\n' "$*" >&2; exit 1; }
gen_pass() { openssl rand -base64 32 | tr -dc 'A-Za-z0-9' | head -c "${1:-24}"; }
trap 'die "Установка прервана на строке $LINENO (команда: $BASH_COMMAND)"' ERR

while [ $# -gt 0 ]; do
    case "$1" in
        --admin-user)    ADMIN_USER=$2; shift 2 ;;
        --admin-pass)    ADMIN_PASS=$2; shift 2 ;;
        --domain)        DOMAIN=$2; shift 2 ;;
        --email)         EMAIL=$2; shift 2 ;;
        --tz)            TZ_NAME=$2; shift 2 ;;
        --mqtt-port)     MQTT_PORT=$2; shift 2 ;;
        --db-name)       DB_NAME_ARG=$2; shift 2 ;;
        --retention)     RETENTION=$2; shift 2 ;;
        --events-retention) EV_RETENTION=$2; shift 2 ;;
        --devices)       DEVICES=$2; shift 2 ;;
        --no-firewall)   FIREWALL=0; shift ;;
        --replace-xampp) REPLACE_XAMPP=1; shift ;;
        -h|--help)       sed -n '2,/^# =\{10,\}$/p' "$0" | sed '1d;$d;s/^# \{0,1\}//'; exit 0 ;;
        *) die "Неизвестный параметр: $1 (см. --help)" ;;
    esac
done

# ----------------------------------------------------------------------------
log "Проверка системы"
[ "$(id -u)" -eq 0 ] || die "Запускайте через sudo: sudo bash install.sh"
[ -r /etc/os-release ] && . /etc/os-release
case "${ID:-}" in
    ubuntu|debian) ok "ОС: ${PRETTY_NAME:-$ID}" ;;
    *) die "Поддерживаются только Ubuntu и Debian (обнаружено: ${PRETTY_NAME:-неизвестно})" ;;
esac
[ -d /run/systemd/system ] || die "Нужен systemd"
for f in web/lib/bootstrap.php sql/schema.sql bin/relay-device systemd/relay-mqtt-subscriber.service; do
    [ -e "$SRC_DIR/$f" ] || die "Не найден $SRC_DIR/$f — распакуйте архив целиком"
done
[[ "$MQTT_PORT" =~ ^[0-9]+$ ]] || die "--mqtt-port: нужно число"
[[ "$RETENTION" =~ ^[0-9]+$ ]] || die "--retention: нужно число"
[[ "$EV_RETENTION" =~ ^[0-9]+$ ]] || die "--events-retention: нужно число"
[ -e "/usr/share/zoneinfo/$TZ_NAME" ] || die "Неизвестный часовой пояс: $TZ_NAME"
[[ "$ADMIN_USER" =~ ^[A-Za-z0-9_.@-]{1,64}$ ]] || die "--admin-user: латиница, цифры, _ . @ -"
if [ -n "$DOMAIN" ] && [ -z "$EMAIL" ]; then die "Вместе с --domain укажите --email"; fi
if [ -n "$DOMAIN" ] && ! [[ "$DOMAIN" =~ ^[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?$ ]]; then die "--domain: неверное имя"; fi

if [ -d /opt/lampp ]; then
    if [ "$REPLACE_XAMPP" -eq 1 ]; then
        warn "Останавливаю старую установку XAMPP и её сервисы"
        systemctl disable --now mqtt-subscriber lampp 2>/dev/null || true
        /opt/lampp/lampp stop >/dev/null 2>&1 || true
        rm -f /etc/systemd/system/mqtt-subscriber.service /etc/systemd/system/lampp.service
        # старый установщик подменял штатный mosquitto.service (запуск от root)
        if grep -qs 'User=root' /etc/systemd/system/mosquitto.service; then
            rm -f /etc/systemd/system/mosquitto.service
        fi
        systemctl daemon-reload
        ok "XAMPP отключён (файлы /opt/lampp не удалены; данные старой БД можно перенести дампом)"
    else
        die "Найдена старая установка XAMPP (/opt/lampp), она занимает порты 80 и 3306.
   Перезапустите с параметром --replace-xampp, чтобы отключить её."
    fi
fi

# ----------------------------------------------------------------------------
log "Пароли и настройки"
mkdir -p "$CONF_DIR"; chmod 750 "$CONF_DIR"
# из прошлой установки берём только пароли и имя БД; остальное — из параметров
env_get() { [ -r "$ENV_FILE" ] && sed -n "s/^$1=//p" "$ENV_FILE" | tail -n1 || true; }
DB_PASS=$(env_get DB_PASS); MQTT_PASS=$(env_get MQTT_PASS); DB_NAME=$(env_get DB_NAME)
[ -n "$DB_PASS" ] && ok "Использую сохранённые пароли из $ENV_FILE"
DB_NAME=${DB_NAME_ARG:-${DB_NAME:-u2702672_service_reley_v1}}
[[ "$DB_NAME" =~ ^[A-Za-z0-9_]{1,64}$ ]] || die "--db-name: латиница, цифры и _"
DB_USER=relay
MQTT_USER=php-server
NEW_ADMIN=0
if [ -z "$ADMIN_PASS" ] && [ ! -s "$CONF_DIR/users.php" ]; then ADMIN_PASS=$(gen_pass 12); NEW_ADMIN=1; fi
[ -n "$ADMIN_PASS" ] && NEW_ADMIN=1

# ----------------------------------------------------------------------------
log "Установка пакетов"
export DEBIAN_FRONTEND=noninteractive
PKGS="mosquitto mosquitto-clients mariadb-server apache2 libapache2-mod-php php-cli php-mysql php-mbstring openssl ca-certificates curl sudo"
[ -n "$DOMAIN" ] && PKGS="$PKGS certbot python3-certbot-apache"
apt-get update -q
# shellcheck disable=SC2086
apt-get install -y -q $PKGS
ok "Пакеты установлены"

[ -n "$DB_PASS" ]   || DB_PASS=$(gen_pass 24)
[ -n "$MQTT_PASS" ] || MQTT_PASS=$(gen_pass 24)
umask 077
cat > "$ENV_FILE" <<EOF
# Создано install.sh $(date '+%F %T'). Используется relay-device и повторной установкой.
DB_NAME=$DB_NAME
DB_USER=$DB_USER
DB_PASS=$DB_PASS
MQTT_USER=$MQTT_USER
MQTT_PASS=$MQTT_PASS
MQTT_PORT=$MQTT_PORT
DOMAIN=$DOMAIN
TZ_NAME=$TZ_NAME
EOF
umask 022

PHP_VER=$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')
php -r 'exit(PHP_VERSION_ID >= 80100 ? 0 : 1);' || die "Нужен PHP 8.1+, установлен $PHP_VER"
ok "PHP $PHP_VER"

# ----------------------------------------------------------------------------
log "Часовой пояс PHP: $TZ_NAME"
for sapi in apache2 cli; do
    if [ -d "/etc/php/$PHP_VER/$sapi/conf.d" ]; then
        printf 'date.timezone = %s\nexpose_php = Off\n' "$TZ_NAME" > "/etc/php/$PHP_VER/$sapi/conf.d/99-relay-server.ini"
    fi
done
ok "Готово"

# ----------------------------------------------------------------------------
log "База данных MariaDB"
systemctl enable --now mariadb >/dev/null
mysql --default-character-set=utf8mb4 <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASS';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
mysql --default-character-set=utf8mb4 "$DB_NAME" < "$SRC_DIR/sql/schema.sql"
ok "База $DB_NAME, пользователь $DB_USER (доступ только с localhost)"

# ----------------------------------------------------------------------------
log "Конфигурация панели"
cat > "$CONF_DIR/config.php" <<EOF
<?php
// Создано install.sh. Пароли также лежат в $ENV_FILE.
return [
    'timezone' => '$TZ_NAME',
    'db' => [
        'host' => 'localhost',
        'port' => 3306,
        'name' => '$DB_NAME',
        'user' => '$DB_USER',
        'pass' => '$DB_PASS',
    ],
    'mqtt' => [
        'host' => '127.0.0.1',
        'port' => $MQTT_PORT,
        'user' => '$MQTT_USER',
        'pass' => '$MQTT_PASS',
    ],
    'log_retention_days' => $RETENTION,
    'events_retention_days' => $EV_RETENTION,
];
EOF
[ -f "$CONF_DIR/users.php" ] || printf '<?php\nreturn [];\n' > "$CONF_DIR/users.php"
chown root:www-data "$CONF_DIR" "$CONF_DIR/config.php" "$CONF_DIR/users.php"
chmod 640 "$CONF_DIR/config.php" "$CONF_DIR/users.php"
install -m 755 "$SRC_DIR/bin/relay-device" /usr/local/sbin/relay-device
# Кнопки «Добавить плату» / «Удалить плату» в панели: веб-серверу (www-data) разрешено запускать
# от root только обёртку relay-device-web (она пропускает лишь add/del и проверяет аргументы).
# Правило без шаблонов в аргументах и без «Defaults!…» — так его понимает и sudo-rs (Ubuntu 25.10+).
install -m 755 "$SRC_DIR/bin/relay-device-web" /usr/local/sbin/relay-device-web
SUDOERS_TMP=$(mktemp)
cat > "$SUDOERS_TMP" <<'SUDOEOF'
# relay-server: регистрация плат из веб-панели (создано install.sh)
www-data ALL=(root) NOPASSWD: /usr/local/sbin/relay-device-web
SUDOEOF
chmod 440 "$SUDOERS_TMP"
if ! command -v visudo >/dev/null || visudo -cf "$SUDOERS_TMP" >/dev/null 2>&1; then
    install -m 440 -o root -g root "$SUDOERS_TMP" /etc/sudoers.d/relay-server
else
    visudo -cf "$SUDOERS_TMP" || true
    warn "Правило sudo не прошло проверку — кнопка «Добавить плату» работать не будет (используйте relay-device add)"
fi
rm -f "$SUDOERS_TMP"
# Основной способ для панели — очередь запросов, которую обрабатывает служба systemd от root
# (не зависит от sudo); sudo-правило выше — запасной вариант.
install -m 755 "$SRC_DIR/bin/relay-device-queue" /usr/local/sbin/relay-device-queue
install -d -m 770 -o root -g www-data /var/lib/relay-server /var/lib/relay-server/queue
chmod 750 /var/lib/relay-server
install -m 644 "$SRC_DIR/systemd/relay-device-queue.service" /etc/systemd/system/relay-device-queue.service
install -m 644 "$SRC_DIR/systemd/relay-device-queue.path" /etc/systemd/system/relay-device-queue.path
systemctl daemon-reload
systemctl enable relay-device-queue.path >/dev/null 2>&1 || true
systemctl restart relay-device-queue.path || true
# самопроверка: запрос «check» от имени www-data должен вернуть код 2 («разрешены только add и del»)
QOK=0
REQ=$(openssl rand -hex 16)
if sudo -u www-data sh -c "printf 'check\n' > /var/lib/relay-server/queue/.$REQ.tmp && mv /var/lib/relay-server/queue/.$REQ.tmp /var/lib/relay-server/queue/$REQ.req"; then
    for _ in $(seq 1 25); do
        if [ -f "/var/lib/relay-server/queue/$REQ.res" ]; then
            [ "$(head -n1 "/var/lib/relay-server/queue/$REQ.res")" = 2 ] && QOK=1
            rm -f "/var/lib/relay-server/queue/$REQ.res"; break
        fi
        sleep 0.2
    done
fi
rm -f "/var/lib/relay-server/queue/$REQ.req"
if [ $QOK -eq 1 ]; then
    ok "Кнопка «Добавить плату»: служба relay-device-queue работает"
elif sudo -u www-data sudo -n /usr/local/sbin/relay-device-web check >/dev/null 2>&1; [ $? -eq 2 ]; then
    ok "Кнопка «Добавить плату»: работает через sudo (служба relay-device-queue не ответила)"
else
    warn "Кнопка «Добавить плату» не сможет зарегистрировать плату — используйте sudo relay-device add <ID>"
fi
# команда обновления из git-репозитория, из которого запущена установка
if [ -d "$SRC_DIR/.git" ]; then
    sed "s|@SRC_DIR@|$SRC_DIR|" "$SRC_DIR/bin/relay-update" > /usr/local/sbin/relay-update
    chmod 755 /usr/local/sbin/relay-update
fi
if [ "$NEW_ADMIN" -eq 1 ]; then
    relay-device user-add "$ADMIN_USER" "$ADMIN_PASS" >/dev/null
    ok "Пользователь панели: $ADMIN_USER"
else
    ok "Пользователи панели сохранены без изменений"
fi

# ----------------------------------------------------------------------------
log "Файлы веб-панели → $WEB_ROOT"
NEW_ROOT="$WEB_ROOT.new.$$"
rm -rf "$NEW_ROOT"; cp -a "$SRC_DIR/web" "$NEW_ROOT"
chown -R root:root "$NEW_ROOT"
find "$NEW_ROOT" -type d -exec chmod 755 {} +
find "$NEW_ROOT" -type f -exec chmod 644 {} +
if [ -d "$WEB_ROOT" ]; then
    rm -rf "$WEB_ROOT.old"; mv "$WEB_ROOT" "$WEB_ROOT.old"
fi
mv "$NEW_ROOT" "$WEB_ROOT"
rm -rf "$WEB_ROOT.old"
ok "Скопировано"

# ----------------------------------------------------------------------------
log "Apache"
SERVER_NAME_LINE=""; [ -n "$DOMAIN" ] && SERVER_NAME_LINE="ServerName $DOMAIN"
cat > /etc/apache2/sites-available/relay-server.conf <<EOF
<VirtualHost *:80>
    $SERVER_NAME_LINE
    DocumentRoot $WEB_ROOT
    DirectoryIndex index.php

    <Directory $WEB_ROOT>
        Options -Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>

    # служебные файлы недоступны из браузера
    <DirectoryMatch "^$WEB_ROOT/(lib|vendor)">
        Require all denied
    </DirectoryMatch>
    <FilesMatch "^(mqtt_subscriber\.php|composer\.(json|lock|phar))$">
        Require all denied
    </FilesMatch>

    ErrorLog \${APACHE_LOG_DIR}/relay-error.log
    CustomLog \${APACHE_LOG_DIR}/relay-access.log combined
</VirtualHost>
EOF
a2enmod -q php"$PHP_VER" >/dev/null 2>&1 || true
a2dissite -q 000-default >/dev/null 2>&1 || true
a2ensite -q relay-server >/dev/null
apache2ctl configtest >/dev/null 2>&1 || { apache2ctl configtest; die "Ошибка в конфигурации Apache"; }
systemctl enable apache2 >/dev/null
systemctl reload apache2 2>/dev/null || systemctl restart apache2
ok "Сайт relay-server включён"

# ----------------------------------------------------------------------------
log "Mosquitto"
PASSWD=/etc/mosquitto/passwd
touch "$PASSWD"
mosquitto_passwd -b "$PASSWD" "$MQTT_USER" "$MQTT_PASS" 2>/dev/null || die "Не удалось записать $PASSWD"
chown mosquitto:mosquitto "$PASSWD"; chmod 600 "$PASSWD"

TLS_BLOCK=""
if [ -n "$DOMAIN" ]; then
    TLS_BLOCK="
# MQTT over TLS (сертификат Let's Encrypt, обновляется автоматически)
listener 8883
certfile /etc/mosquitto/certs/fullchain.pem
keyfile  /etc/mosquitto/certs/privkey.pem"
fi
cat > /etc/mosquitto/conf.d/relay-server.conf <<EOF
# Создано install.sh (сервер управления релейными платами)
allow_anonymous false
password_file $PASSWD
acl_file /etc/mosquitto/relay.acl

# Платы подключаются по MQTT без TLS (см. прошивку)
listener $MQTT_PORT 0.0.0.0
$TLS_BLOCK

connection_messages true
log_timestamp true
log_timestamp_format %Y-%m-%d %H:%M:%S
max_queued_messages 1000
EOF
# Основной конфиг пишем сами: старая установка (v2.1) клала сюда свой listener 1883,
# и вместе с conf.d/relay-server.conf брокер не стартовал («Address already in use»).
MAIN_CONF=/etc/mosquitto/mosquitto.conf
if [ -f "$MAIN_CONF" ] && ! grep -q 'relay-server' "$MAIN_CONF"; then
    cp -a "$MAIN_CONF" "$MAIN_CONF.bak.$(date +%Y%m%d%H%M%S)"
    warn "Прежний $MAIN_CONF сохранён в $MAIN_CONF.bak.*"
fi
cat > "$MAIN_CONF" <<'MQEOF'
# relay-server: основной конфиг Mosquitto (настройки сервера — в conf.d/relay-server.conf)
persistence true
persistence_location /var/lib/mosquitto/
log_dest file /var/log/mosquitto/mosquitto.log
include_dir /etc/mosquitto/conf.d
MQEOF
install -d -o mosquitto -g mosquitto /var/lib/mosquitto /var/log/mosquitto
# чужие файлы в conf.d с listener/паролями тоже дают конфликт — отключаем их
for f in /etc/mosquitto/conf.d/*.conf; do
    [ -e "$f" ] && [ "$f" != /etc/mosquitto/conf.d/relay-server.conf ] || continue
    if grep -qE '^[[:space:]]*(listener|port|password_file|acl_file|allow_anonymous)' "$f"; then
        mv "$f" "$f.disabled"; warn "Отключён конфликтующий $f (переименован в .disabled)"
    fi
done
# старая установка подменяла штатный mosquitto.service (запуск от root)
if grep -qs 'User=root' /etc/systemd/system/mosquitto.service; then
    mv /etc/systemd/system/mosquitto.service /etc/systemd/system/mosquitto.service.old-relay
    systemctl daemon-reload
    warn "Удалён старый /etc/systemd/system/mosquitto.service (сохранён как .old-relay)"
fi
# порт не должен быть занят другим процессом (например, mosquitto, запущенным вручную)
systemctl stop mosquitto 2>/dev/null || true
sleep 1
if command -v ss >/dev/null && ss -ltnH "sport = :$MQTT_PORT" | grep -q .; then
    ss -ltnp "sport = :$MQTT_PORT"
    die "Порт $MQTT_PORT занят другим процессом (см. выше). Остановите его и запустите install.sh снова."
fi
relay-device sync-acl >/dev/null || die "Не удалось создать /etc/mosquitto/relay.acl"
[ -s /etc/mosquitto/relay.acl ] || die "Файл /etc/mosquitto/relay.acl не создан"

# ----------------------------------------------------------------------------
if [ -n "$DOMAIN" ]; then
    log "HTTPS и TLS-сертификат для $DOMAIN"
    certbot --apache -d "$DOMAIN" -m "$EMAIL" --agree-tos --non-interactive --redirect --keep-until-expiring
    mkdir -p /etc/letsencrypt/renewal-hooks/deploy
    cat > /etc/letsencrypt/renewal-hooks/deploy/relay-mosquitto.sh <<EOF
#!/bin/sh
# Копирует сертификат $DOMAIN для Mosquitto после каждого обновления
set -e
install -d -m 750 -o root -g mosquitto /etc/mosquitto/certs
for f in fullchain chain privkey; do
    install -m 640 -o root -g mosquitto /etc/letsencrypt/live/$DOMAIN/\$f.pem /etc/mosquitto/certs/\$f.pem
done
systemctl restart mosquitto
EOF
    chmod 755 /etc/letsencrypt/renewal-hooks/deploy/relay-mosquitto.sh
    /etc/letsencrypt/renewal-hooks/deploy/relay-mosquitto.sh || true   # итог проверяется ниже
    ok "Панель: https://$DOMAIN, MQTT TLS: $DOMAIN:8883"
fi

systemctl enable mosquitto >/dev/null 2>&1
# Каталог для pid-файла: в части версий пакета (2.0.2x) он задаётся в unit/конфиге,
# но не создаётся, и брокер падает с «Unable to write pid file». Создаём его при каждом старте.
mkdir -p /etc/systemd/system/mosquitto.service.d
cat > /etc/systemd/system/mosquitto.service.d/relay-server.conf <<'MQEOF'
[Service]
ExecStartPre=+/usr/bin/install -d -m 755 -o mosquitto -g mosquitto /run/mosquitto /var/log/mosquitto
MQEOF
install -d -m 755 -o mosquitto -g mosquitto /run/mosquitto
systemctl daemon-reload
systemctl reset-failed mosquitto 2>/dev/null || true
systemctl restart mosquitto || true
sleep 1
if ! systemctl is-active --quiet mosquitto; then
    echo "----- journalctl -u mosquitto -----"
    journalctl -u mosquitto -n 20 --no-pager || true
    echo "----- /var/log/mosquitto/mosquitto.log -----"
    tail -n 20 /var/log/mosquitto/mosquitto.log 2>/dev/null || true
    echo "----- pid_file / listener в конфигурации -----"
    grep -rnE '^[[:space:]]*(pid_file|listener|port|include_dir)' /etc/mosquitto/ 2>/dev/null | grep -v '\.bak\.' || true
    echo "----- systemctl cat mosquitto -----"
    systemctl cat mosquitto --no-pager 2>/dev/null | grep -vE '^\s*(#|$)' || true
    die "Mosquitto не запустился (причина — в строках выше)"
fi
ok "Брокер слушает порт $MQTT_PORT"

# ----------------------------------------------------------------------------
log "MQTT-подписчик"
install -m 644 "$SRC_DIR/systemd/relay-mqtt-subscriber.service" /etc/systemd/system/relay-mqtt-subscriber.service
systemctl daemon-reload
systemctl enable relay-mqtt-subscriber >/dev/null
systemctl restart relay-mqtt-subscriber
ok "Сервис relay-mqtt-subscriber запущен"

# ----------------------------------------------------------------------------
if [ -n "$DEVICES" ]; then
    log "Добавление плат: $DEVICES"
    for id in $DEVICES; do relay-device add "$id" | sed 's/^/  /'; done
fi

# ----------------------------------------------------------------------------
if [ "$FIREWALL" -eq 1 ] && command -v ufw >/dev/null && ufw status | grep -q 'Status: active'; then
    log "Firewall (ufw)"
    ufw allow 80/tcp >/dev/null; ufw allow "$MQTT_PORT"/tcp >/dev/null
    [ -n "$DOMAIN" ] && { ufw allow 443/tcp >/dev/null; ufw allow 8883/tcp >/dev/null; }
    ok "Открыты порты 80, $MQTT_PORT${DOMAIN:+, 443, 8883}"
fi

# ----------------------------------------------------------------------------
log "Проверка"
sleep 2
FAIL=0
for s in mariadb mosquitto apache2 relay-mqtt-subscriber; do
    if systemctl is-active --quiet "$s"; then ok "$s работает"; else warn "$s НЕ работает"; FAIL=1; fi
done
if mosquitto_pub -h 127.0.0.1 -p "$MQTT_PORT" -u "$MQTT_USER" -P "$MQTT_PASS" -t relay-server/selftest -m "install $(date +%T)" -q 1; then
    ok "Публикация в брокер работает"
else
    warn "Не удалось опубликовать сообщение в брокер"; FAIL=1
fi
if mosquitto_pub -h 127.0.0.1 -p "$MQTT_PORT" -t test -m x 2>/dev/null; then
    warn "Брокер принимает анонимные подключения!"; FAIL=1
else
    ok "Анонимный доступ к брокеру запрещён"
fi
CODE=$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1/login.php || true)
[ "$CODE" = 200 ] && ok "Веб-панель отвечает" || { warn "Веб-панель вернула код $CODE"; FAIL=1; }

IP=$(hostname -I 2>/dev/null | awk '{print $1}')
URL=${DOMAIN:+https://$DOMAIN}; URL=${URL:-http://${IP:-IP_СЕРВЕРА}}
{
    echo "Сервер управления релейными платами — $(date '+%F %T')"
    echo
    echo "Веб-панель:      $URL/"
    echo "MQTT монитор:    $URL/monitor.php"
    echo "Логин панели:    $ADMIN_USER"
    [ "$NEW_ADMIN" -eq 1 ] && echo "Пароль панели:   $ADMIN_PASS"
    echo
    echo "MQTT брокер:     ${DOMAIN:-${IP:-IP_СЕРВЕРА}}:$MQTT_PORT${DOMAIN:+ (TLS: $DOMAIN:8883)}"
    echo "MQTT сервер:     $MQTT_USER / $MQTT_PASS"
    echo "БД:              $DB_NAME, пользователь $DB_USER / $DB_PASS"
} > "$CRED_FILE"
chmod 600 "$CRED_FILE"

cat <<EOF

============================================================================
 $( [ $FAIL -eq 0 ] && echo 'УСТАНОВКА ЗАВЕРШЕНА УСПЕШНО' || echo 'УСТАНОВКА ЗАВЕРШЕНА С ПРЕДУПРЕЖДЕНИЯМИ (см. выше)' )
============================================================================
 Веб-панель:     $URL/
 MQTT монитор:   $URL/monitor.php
 Логин:          $ADMIN_USER
$( [ "$NEW_ADMIN" -eq 1 ] && echo " Пароль:         $ADMIN_PASS" || echo " Пароль:         прежний (сменить: sudo relay-device user-add $ADMIN_USER)" )

 Все пароли сохранены в $CRED_FILE (доступ только root).

 Добавить плату:         sudo relay-device add <ID> "Название" "Адрес"
 Список плат:            sudo relay-device list
 Обновить сервер:        sudo relay-update   (если установлено из git-репозитория)
 Логи подписчика:        journalctl -u relay-mqtt-subscriber -f
 Логи брокера:           tail -f /var/log/mosquitto/mosquitto.log
 Следить за MQTT:        mosquitto_sub -h 127.0.0.1 -p $MQTT_PORT -u $MQTT_USER -P '<пароль>' -t '#' -v
============================================================================
EOF
exit $FAIL
