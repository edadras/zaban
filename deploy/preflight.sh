#!/usr/bin/env bash
#
# Zaban preflight — looks, reports, changes nothing.
#
# Run this on the server FIRST. It writes no files, starts no services and
# installs no packages. Its only job is to tell you whether installing Zaban
# would collide with anything already running, because this box hosts other
# sites and a deploy that assumes an empty machine is how those get broken.
#
#   bash preflight.sh
#
# Read the REPORT at the end. Anything marked BLOCK must be resolved before
# install.sh will run.

set -uo pipefail

DOMAIN="${DOMAIN:-learn.edadras.com}"
APP_DIR="${APP_DIR:-/var/www/zaban}"
DB_NAME="${DB_NAME:-zaban}"
DB_USER="${DB_USER:-zaban}"

blocks=(); warns=(); notes=()

say()   { printf '\n\033[1m== %s\033[0m\n' "$1"; }
ok()    { printf '   \033[32m✓\033[0m %s\n' "$1"; }
info()  { printf '     %s\n' "$1"; }
block() { printf '   \033[31m✗\033[0m %s\n' "$1"; blocks+=("$1"); }
warn()  { printf '   \033[33m!\033[0m %s\n' "$1"; warns+=("$1"); }

say "Host"
info "$(. /etc/os-release 2>/dev/null && echo "$PRETTY_NAME") · kernel $(uname -r) · $(nproc) cpu · $(free -g | awk '/^Mem:/{print $2}')GB ram"
info "disk /: $(df -h / | awk 'NR==2{print $4" free of "$2}')"
avail_gb=$(df -BG --output=avail / | tail -1 | tr -dc '0-9')
# The repo carries ~900MB of book audio and images before anything is generated.
if [ "${avail_gb:-0}" -lt 15 ]; then
  block "only ${avail_gb}GB free on / — the content alone needs ~10GB, plus generated media"
else
  ok "${avail_gb}GB free"
fi

say "What already serves the web"
for s in caddy nginx apache2 httpd; do
  if systemctl is-active --quiet "$s" 2>/dev/null; then
    ok "$s is running"
    notes+=("web:$s")
  fi
done
[ ${#notes[@]} -eq 0 ] && warn "no recognised web server is running"

if command -v caddy >/dev/null 2>&1; then
  info "caddy $(caddy version 2>/dev/null | head -1)"
  for f in /etc/caddy/Caddyfile /etc/caddy/conf.d; do
    [ -e "$f" ] && info "config: $f"
  done
  # The edge already answers for this name, so a duplicate site block would
  # be ambiguous rather than additive.
  if grep -rqs "$DOMAIN" /etc/caddy/ 2>/dev/null; then
    block "$DOMAIN already appears in the Caddy config — remove or rename it first"
  else
    ok "$DOMAIN is not yet configured in Caddy"
  fi
  info "sites currently defined:"
  grep -rhoE '^[a-z0-9.*-]+\.[a-z]{2,}' /etc/caddy/ 2>/dev/null | sort -u | sed 's/^/       /' | head -20
fi

say "Ports"
for p in 80 443 3306 6379 9000; do
  who=$(ss -lntp 2>/dev/null | awk -v p=":$p\$" '$4 ~ p {print $NF; exit}')
  [ -n "$who" ] && info "$p → $who" || info "$p → free"
done

say "PHP"
if command -v php >/dev/null 2>&1; then
  v=$(php -r 'echo PHP_VERSION;')
  info "cli: $v"
  if php -r 'exit(version_compare(PHP_VERSION,"8.3",">=")?0:1);'; then
    ok "meets the 8.3 floor"
  else
    block "PHP $v is below the 8.3 floor — install php8.4 alongside, do not replace what other sites use"
  fi
  missing=""
  for e in pdo_mysql mbstring openssl tokenizer xml ctype json curl fileinfo gd pcntl redis intl zip bcmath; do
    php -m 2>/dev/null | grep -qix "$e" || missing="$missing $e"
  done
  [ -n "$missing" ] && block "missing PHP extensions:$missing" || ok "all required extensions present"
  ls -d /etc/php/*/fpm 2>/dev/null | sed 's/^/     fpm pool: /'
else
  block "no PHP on PATH"
fi

say "Database"
if command -v mysql >/dev/null 2>&1 || command -v mariadb >/dev/null 2>&1; then
  info "$(mysql --version 2>/dev/null || mariadb --version)"
  if mysql -e 'select 1' >/dev/null 2>&1; then
    ok "reachable as the current user"
    if mysql -N -e "show databases like '$DB_NAME'" 2>/dev/null | grep -q .; then
      block "database '$DB_NAME' already exists — installing would write into someone else's data"
    else
      ok "database '$DB_NAME' is free"
    fi
    if mysql -N -e "select user from mysql.user where user='$DB_USER'" 2>/dev/null | grep -q .; then
      warn "db user '$DB_USER' already exists — install will reuse it, not reset its password"
    fi
    info "databases already here: $(mysql -N -e 'show databases' 2>/dev/null | grep -vE '^(information_schema|performance_schema|mysql|sys)$' | tr '\n' ' ')"
  else
    warn "cannot connect without credentials — check manually that '$DB_NAME' is free"
  fi
else
  block "no MySQL/MariaDB client found"
fi

say "Services Zaban needs"
for s in redis-server redis supervisor; do
  systemctl is-active --quiet "$s" 2>/dev/null && ok "$s running" || warn "$s not running (install.sh can install it)"
done
if [ -d /etc/supervisor/conf.d ]; then
  info "existing supervisor programs: $(ls /etc/supervisor/conf.d/ 2>/dev/null | tr '\n' ' ')"
  grep -rqs "zaban" /etc/supervisor/conf.d/ && block "supervisor already has zaban programs — remove them first"
fi

say "Target directory"
if [ -e "$APP_DIR" ]; then
  block "$APP_DIR already exists — move it aside or set APP_DIR to something else"
else
  ok "$APP_DIR is free"
fi
info "what else lives in /var/www: $(ls /var/www 2>/dev/null | tr '\n' ' ')"

say "Binaries the media and speech pipelines want"
for b in git composer ffmpeg pdftotext pdfimages espeak-ng; do
  command -v "$b" >/dev/null 2>&1 && ok "$b" || warn "$b missing (degrades a feature, does not block install)"
done

say "DNS"
resolved=$(getent hosts "$DOMAIN" 2>/dev/null | awk '{print $1; exit}')
myip=$(curl -s --max-time 8 https://api.ipify.org 2>/dev/null)
info "$DOMAIN → ${resolved:-unresolved} · this host → ${myip:-unknown}"
if [ -n "$resolved" ] && [ "$resolved" = "$myip" ]; then
  ok "DNS points here, so Caddy can issue a certificate on first request"
else
  warn "DNS does not point at this host — Caddy will fail to get a certificate until it does"
fi

# ---- report --------------------------------------------------------------
printf '\n\033[1m== REPORT ==\033[0m\n'
if [ ${#blocks[@]} -eq 0 ]; then
  printf '   \033[32mNo blockers.\033[0m Safe to run install.sh.\n'
else
  printf '   \033[31m%d blocker(s) — install.sh will refuse to run:\033[0m\n' "${#blocks[@]}"
  printf '     • %s\n' "${blocks[@]}"
fi
[ ${#warns[@]} -gt 0 ] && { printf '   \033[33m%d warning(s):\033[0m\n' "${#warns[@]}"; printf '     • %s\n' "${warns[@]}"; }
printf '\n'
