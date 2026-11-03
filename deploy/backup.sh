#!/usr/bin/env bash
# Nightly MySQL backup of Finovo, compressed, the last 14 kept. Reads the database settings from backend/.env.
# Copy the backups to another place too (another server or cloud storage): a backup on the same disk is lost with it.
set -euo pipefail

APP_DIR="$(cd "$(dirname "$0")/.." && pwd)"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/finovo}"
KEEP_DAYS="${KEEP_DAYS:-14}"

env_value() { grep -E "^$1=" "$APP_DIR/backend/.env" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
file="$BACKUP_DIR/finovo-$(date +%Y%m%d-%H%M%S).sql.gz"

MYSQL_PWD="$(env_value DB_PASSWORD)" mysqldump \
    --single-transaction --quick --routines --triggers --no-tablespaces \
    -h "$(env_value DB_HOST)" -P "$(env_value DB_PORT)" -u "$(env_value DB_USERNAME)" "$(env_value DB_DATABASE)" \
    | gzip > "$file"
chmod 600 "$file"

find "$BACKUP_DIR" -name 'finovo-*.sql.gz' -mtime +"$KEEP_DAYS" -delete
echo "$(date '+%F %T') backup written: $file"
