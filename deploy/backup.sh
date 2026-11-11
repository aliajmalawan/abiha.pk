#!/usr/bin/env bash
#
# ABIHA.PK backup: database + uploaded images + a copy of .env, compressed, verified, with old backups rotated.
#
#   bash deploy/backup.sh                       # run by hand
#   0 2 * * * /bin/bash /home/CPANELUSER/abiha/deploy/backup.sh >/dev/null 2>&1      # cPanel → Cron Jobs: every night at 02:00
#
# Optional environment variables:
#   APP_DIR      the application folder (default: the folder above this script)
#   BACKUP_DIR   where backups go (default: $HOME/backups/abiha), keep it OUTSIDE public_html
#   KEEP_DAYS    how many days of backups to keep (default 14)
#   MYSQLDUMP    path to mysqldump if it is not on PATH
#
# The database password is read from .env and handed to mysqldump through a private temporary file, never on the command line
# (where other users of the server could see it in the process list). Backup files contain customer data and secrets:
# they are created readable by you only (umask 077). Download them to somewhere safe: a backup on the same server is not a
# backup if the server is lost.

set -euo pipefail
umask 077

APP_DIR="${APP_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)}"
BACKUP_DIR="${BACKUP_DIR:-$HOME/backups/abiha}"
KEEP_DAYS="${KEEP_DAYS:-14}"
MYSQLDUMP="${MYSQLDUMP:-mysqldump}"
STAMP="$(date +%Y%m%d-%H%M%S)"
ENV_FILE="$APP_DIR/.env"

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
LOG="$BACKUP_DIR/backup.log"
log() { printf '%s  %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" | tee -a "$LOG"; }
fail() { log "FAILED: $*"; exit 1; }

[ -f "$ENV_FILE" ] || fail ".env not found at $ENV_FILE (set APP_DIR)"

# Read one value from .env without executing it (values may contain $, spaces or #). Strips optional quotes.
env_get() {
  local line
  line="$(grep -E "^$1=" "$ENV_FILE" | tail -n 1 || true)"
  line="${line#*=}"
  line="${line%$'\r'}"
  if [[ "$line" == \"*\" ]]; then line="${line:1:${#line}-2}"; elif [[ "$line" == \'*\' ]]; then line="${line:1:${#line}-2}"; fi
  printf '%s' "$line"
}

DB_CONNECTION="$(env_get DB_CONNECTION)"
log "Backup started (app: $APP_DIR)"

# ---------- 1. database ----------
if [ "$DB_CONNECTION" = "sqlite" ]; then
  DB_FILE="$(env_get DB_DATABASE)"
  [ -f "$DB_FILE" ] || fail "SQLite file $DB_FILE not found"
  DB_OUT="$BACKUP_DIR/db-$STAMP.sqlite.gz"
  gzip -c "$DB_FILE" > "$DB_OUT"
else
  DB_HOST="$(env_get DB_HOST)"; DB_PORT="$(env_get DB_PORT)"; DB_NAME="$(env_get DB_DATABASE)"; DB_USER="$(env_get DB_USERNAME)"; DB_PASS="$(env_get DB_PASSWORD)"
  [ -n "$DB_NAME" ] && [ -n "$DB_USER" ] || fail "DB_DATABASE / DB_USERNAME are empty in .env"
  command -v "$MYSQLDUMP" >/dev/null 2>&1 || [ -x "$MYSQLDUMP" ] || fail "mysqldump not found (set MYSQLDUMP=/path/to/mysqldump)"
  CNF="$(mktemp)"
  trap 'rm -f "$CNF"' EXIT
  {
    echo "[client]"
    echo "user=$DB_USER"
    echo "password=\"${DB_PASS//\\/\\\\}\""
    echo "host=${DB_HOST:-localhost}"
    [ -n "$DB_PORT" ] && echo "port=$DB_PORT"
  } > "$CNF"
  DB_OUT="$BACKUP_DIR/db-$STAMP.sql.gz"
  # --single-transaction: a consistent snapshot without locking the shop while it runs.
  "$MYSQLDUMP" --defaults-extra-file="$CNF" --single-transaction --quick --routines --no-tablespaces --default-character-set=utf8mb4 "$DB_NAME" | gzip > "$DB_OUT" \
    || fail "mysqldump failed"
fi
gzip -t "$DB_OUT" || fail "database backup is corrupt"
[ "$(wc -c < "$DB_OUT")" -gt 200 ] || fail "database backup is suspiciously small"
log "Database  -> $(basename "$DB_OUT") ($(du -h "$DB_OUT" | cut -f1))"

# ---------- 2. uploaded images ----------
UPLOADS="$(env_get PUBLIC_DISK_ROOT)"
[ -n "$UPLOADS" ] || UPLOADS="$APP_DIR/storage/app/public"
if [ -d "$UPLOADS" ]; then
  FILES_OUT="$BACKUP_DIR/uploads-$STAMP.tar.gz"
  tar -czf "$FILES_OUT" -C "$UPLOADS" . || fail "could not archive uploads"
  gzip -t "$FILES_OUT" || fail "uploads archive is corrupt"
  log "Uploads   -> $(basename "$FILES_OUT") ($(du -h "$FILES_OUT" | cut -f1))"
else
  log "Uploads folder $UPLOADS not found: skipped"
fi

# ---------- 3. .env (holds APP_KEY: without it encrypted sessions and cookies cannot be read after a restore) ----------
cp "$ENV_FILE" "$BACKUP_DIR/env-$STAMP.txt"
log "Settings  -> env-$STAMP.txt"

# ---------- 4. rotate ----------
if [ "$KEEP_DAYS" -gt 0 ]; then
  find "$BACKUP_DIR" -maxdepth 1 -type f \( -name 'db-*' -o -name 'uploads-*' -o -name 'env-*' \) -mtime +"$KEEP_DAYS" -print -delete | sed 's/^/  removed old backup: /' | tee -a "$LOG" || true
fi

log "Backup finished OK"
