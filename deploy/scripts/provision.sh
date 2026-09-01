#!/usr/bin/env bash
# CNCMS — one-time server provisioning for Ubuntu 24.04 LTS.
#   sudo bash provision.sh
# Idempotent-ish: safe to re-run, but review output. Installs PHP 8.4,
# PostgreSQL 18, Nginx, Composer, Node 20, certbot; creates the `cncms`
# user, database and role; sets sane opcache/PHP-FPM defaults; opens the
# firewall. Does NOT deploy code or write .env — see DEPLOYMENT.md steps 2+.
set -euo pipefail

if [[ $EUID -ne 0 ]]; then echo "run with sudo"; exit 1; fi

APP_USER=cncms
APP_DIR=/var/www/cncms
PHP=8.4

echo "==> apt base"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y ca-certificates curl gnupg lsb-release software-properties-common \
    unzip git ufw acl

echo "==> PHP ${PHP} (ondrej/php)"
add-apt-repository -y ppa:ondrej/php
apt-get update -y
apt-get install -y \
    php${PHP}-fpm php${PHP}-cli php${PHP}-pgsql php${PHP}-mbstring php${PHP}-xml \
    php${PHP}-curl php${PHP}-zip php${PHP}-gd php${PHP}-bcmath php${PHP}-intl \
    php${PHP}-opcache

echo "==> PHP production tuning"
PHPINI=/etc/php/${PHP}/fpm/conf.d/99-cncms.ini
cat > "$PHPINI" <<'EOF'
; CNCMS production overrides
memory_limit = 256M
upload_max_filesize = 30M
post_max_size = 32M
max_execution_time = 120
expose_php = Off

opcache.enable = 1
opcache.validate_timestamps = 0   ; deploy.sh runs `optimize` + reloads FPM
opcache.memory_consumption = 192
opcache.max_accelerated_files = 20000
opcache.interned_strings_buffer = 16
EOF
# Same limits for the CLI (artisan) so migrations/exports match.
cp "$PHPINI" "/etc/php/${PHP}/cli/conf.d/99-cncms.ini"
systemctl restart php${PHP}-fpm

echo "==> PostgreSQL 18 (PGDG)"
install -d /usr/share/postgresql-common/pgdg
curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
    -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.asc
echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.asc] https://apt.postgresql.org/pub/repos/apt $(lsb_release -cs)-pgdg main" \
    > /etc/apt/sources.list.d/pgdg.list
apt-get update -y
apt-get install -y postgresql-18
systemctl enable --now postgresql

echo "==> Nginx + certbot"
apt-get install -y nginx certbot python3-certbot-nginx
systemctl enable --now nginx

echo "==> Composer"
curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
php /tmp/composer-setup.php --install-dir=/usr/local/bin --filename=composer
rm -f /tmp/composer-setup.php

echo "==> Node 20 LTS"
curl -fsSL https://deb.nodesource.com/setup_20.x | bash -
apt-get install -y nodejs

echo "==> app user + directories"
id -u "$APP_USER" &>/dev/null || adduser --system --group --home "$APP_DIR" --shell /bin/bash "$APP_USER"
usermod -a -G www-data "$APP_USER" || true
install -d -o "$APP_USER" -g www-data "$APP_DIR"
install -d -o "$APP_USER" -g "$APP_USER" /var/backups/cncms

echo "==> database + role"
DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 28)"
sudo -u postgres psql -v ON_ERROR_STOP=1 <<SQL
DO \$\$ BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = 'cncms') THEN
    CREATE ROLE cncms LOGIN PASSWORD '${DB_PASS}';
  ELSE
    ALTER ROLE cncms PASSWORD '${DB_PASS}';
  END IF;
END \$\$;
SQL
sudo -u postgres psql -tc "SELECT 1 FROM pg_database WHERE datname='cncms'" | grep -q 1 \
  || sudo -u postgres createdb -O cncms cncms
# Stancl creates a Postgres schema per tenant — the role needs to.
sudo -u postgres psql -d cncms -c "GRANT CREATE ON DATABASE cncms TO cncms;"
sudo -u postgres psql -d cncms -c "GRANT ALL ON SCHEMA public TO cncms;"

echo "==> sudoers drop-in for deploys"
SUDOERS_SRC="$(dirname "$0")/../sudoers.d/cncms"
if [[ -f "$SUDOERS_SRC" ]]; then
  install -m 440 -o root -g root "$SUDOERS_SRC" /etc/sudoers.d/cncms
  visudo -cf /etc/sudoers.d/cncms
fi

echo "==> firewall"
ufw allow OpenSSH
ufw allow 'Nginx Full'
ufw --force enable

cat <<DONE

============================================================
 Provisioning done.

   DB name : cncms
   DB user : cncms
   DB pass : ${DB_PASS}
             ^^^ put this in .env as DB_PASSWORD — it is not stored anywhere else

 Next: DEPLOYMENT.md step 2 (clone the code).
============================================================
DONE
