#!/usr/bin/env bash
# Nightly backup: database dump + uploaded media + private storage (narration audio).
# Secrets (.env.local / Symfony vault keys) are NOT included: back them up separately and securely.
#
# Usage: BACKUP_DIR=/srv/backups/katha APP_DIR=/var/www/katha ./backup.sh
# Restore: mysql katha < db.sql ; tar -xzf media.tgz -C $APP_DIR ; tar -xzf storage.tgz -C $APP_DIR
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/katha}"
BACKUP_DIR="${BACKUP_DIR:-/srv/backups/katha}"
KEEP_DAYS="${KEEP_DAYS:-14}"
STAMP="$(date +%Y-%m-%d_%H%M)"
TARGET="$BACKUP_DIR/$STAMP"

mkdir -p "$TARGET"
chmod 700 "$BACKUP_DIR"

# Database credentials are read from ~/.my.cnf of the backup user (never put passwords on the command line):
#   [mysqldump]
#   user=katha_backup
#   password=...
DB_NAME="${DB_NAME:-katha}"
mysqldump --single-transaction --quick --routines --triggers --default-character-set=utf8mb4 "$DB_NAME" | gzip > "$TARGET/db.sql.gz"

# Media (covers, QR codes, audiograms) and private storage (narration MP3s). Temp files are skipped.
tar -czf "$TARGET/media.tgz" -C "$APP_DIR" public/media
tar -czf "$TARGET/storage.tgz" -C "$APP_DIR" --exclude='var/storage/temp' var/storage

# Retention
find "$BACKUP_DIR" -mindepth 1 -maxdepth 1 -type d -mtime "+$KEEP_DAYS" -exec rm -rf {} +

echo "[$(date -Is)] backup written to $TARGET"
