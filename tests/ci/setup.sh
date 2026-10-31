#!/usr/bin/env bash
# Builds the throw-away Joomla the test suites run against (used by .github/workflows/ci.yml, usable locally):
# installs Joomla from a full package, installs the extension package, creates the test users, starts a mail sink,
# the HTTPS webhook receiver and PHP's built-in web server.
#
# Required: JOOMLA_ZIP (full package), FG_PKG (pkg_fgloginasuser_vX.Y.Z.zip)
# Optional: JOOMLA_DIR PORT DB_NAME DB_HOST DB_USER DB_PASS   (defaults below)
set -euo pipefail

: "${JOOMLA_ZIP:?path of the Joomla full package}"
: "${FG_PKG:?path of the extension package}"
JOOMLA_DIR=${JOOMLA_DIR:-$PWD/joomla}
PORT=${PORT:-8080}
DB_NAME=${DB_NAME:-joomla}
DB_HOST=${DB_HOST:-127.0.0.1}
DB_USER=${DB_USER:-root}
DB_PASS=${DB_PASS:-root}
HERE="$(cd "$(dirname "$0")" && pwd)"
MYSQL=(mysql -h"$DB_HOST" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME")
PW='Passw0rd-12345!'

rm -rf "$JOOMLA_DIR"; mkdir -p "$JOOMLA_DIR"
unzip -q "$JOOMLA_ZIP" -d "$JOOMLA_DIR"
cd "$JOOMLA_DIR"

php installation/joomla.php install \
  --site-name=FGTest --admin-user="Admin Root" --admin-username=root --admin-password='Rootpass-12345!' \
  --admin-email=root@example.test --db-type=mysqli --db-host="$DB_HOST" --db-user="$DB_USER" --db-pass="$DB_PASS" \
  --db-name="$DB_NAME" --db-prefix=jos_ --db-encryption=0 --no-interaction
rm -rf installation

cfg() { sed -i "s#^\(\s*public \$$1 = \).*#\1$2;#" configuration.php; }
cfg sef false
cfg live_site "'http://127.0.0.1:$PORT'"
cfg mailer "'smtp'"
cfg smtphost "'127.0.0.1'"
cfg smtpport 1025
cfg smtpauth false
cfg smtpsecure "'none'"
cfg mailfrom "'root@example.test'"
cfg mailonline true
cfg error_reporting "'maximum'"
cfg session_handler "'database'"
cfg offset "'UTC'"
grep -q "public \$smtpport = 1025;" configuration.php

# Nothing may redirect or interrupt the first backend page.
"${MYSQL[@]}" -e "update jos_extensions set enabled=0 where element in ('stats','guidedtours') and type='plugin'"

php cli/joomla.php extension:install --path="$FG_PKG"

mkuser() { php cli/joomla.php user:add --username="$1" --name="$2" --password="$PW" --email="$1@example.test" --usergroup="$3" >/dev/null; }
mkuser alice "Alice Reg" Registered
mkuser bob "Bob Reg" Registered
mkuser carol "Carol Manager" Manager
mkuser mike "Mike Manager" Manager
mkuser dave "Dave Blocked" Registered
mkuser sue "Sue Super" "Super Users"
mkuser adm "Adm Administrator" Administrator
"${MYSQL[@]}" -e "update jos_users set block=1 where username='dave'"

# Webhook receiver with a self-signed certificate that Joomla's own CA bundle (and PHP) trusts - test environment only.
mkdir -p /tmp/hook
openssl req -x509 -newkey rsa:2048 -nodes -keyout /tmp/hook/key.pem -out /tmp/hook/cert.pem -days 30 \
  -subj "/CN=localhost" -addext "subjectAltName=DNS:localhost,IP:127.0.0.1" >/dev/null 2>&1
cat /tmp/hook/cert.pem >> libraries/vendor/composer/ca-bundle/res/cacert.pem
: > /tmp/hook/received.jsonl; rm -f /tmp/hook/received.jsonl
export FG_MAILS=${FG_MAILS:-/tmp/fg-mails.txt}
(setsid nohup python3 "$HERE/../webhook_receiver.py" >/tmp/hook/recv.log 2>&1 < /dev/null &)
(setsid nohup python3 "$HERE/smtp_sink.py" >/tmp/fg-smtp.log 2>&1 < /dev/null &)
(PHP_CLI_SERVER_WORKERS=12 setsid nohup php -d curl.cainfo=/tmp/hook/cert.pem -d openssl.cafile=/tmp/hook/cert.pem \
  -d display_errors=1 -d error_reporting=32767 -S "127.0.0.1:$PORT" -t "$JOOMLA_DIR" >/tmp/fg-php.log 2>&1 < /dev/null &)

for i in $(seq 1 30); do
  curl -s -o /dev/null --max-time 3 "http://127.0.0.1:$PORT/administrator/index.php" && break
  sleep 1
done
curl -s --max-time 5 "http://127.0.0.1:$PORT/administrator/index.php" | grep -q 'name="passwd"' || { echo "Joomla login page is not served" >&2; exit 1; }
echo "ready: $JOOMLA_DIR on port $PORT"
