#!/usr/bin/env sh
# Restores one archive only into a fresh, isolated disposable PostgreSQL Compose project.
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd -P)
EXTERNAL_BACKUP_ROOT="/opt/data/backups/felio"
BACKUP_DIR_INPUT="${FELIO_BACKUP_DIR:-$EXTERNAL_BACKUP_ROOT/postgresql}"
BACKUP_ROOT=$(realpath -e -- "$EXTERNAL_BACKUP_ROOT") || {
  echo "Configured external backup root is unavailable: $EXTERNAL_BACKUP_ROOT" >&2
  exit 2
}
BACKUP_DIR=$(realpath -e -- "$BACKUP_DIR_INPUT") || {
  echo "Backup destination must be an existing directory" >&2
  exit 2
}
case "$BACKUP_DIR" in
  "$BACKUP_ROOT"|"$BACKUP_ROOT"/*) ;;
  *) echo "Refusing backup destination outside approved external root: $BACKUP_ROOT" >&2; exit 2 ;;
esac
archive_input="${1:-}"

if [ -n "$archive_input" ]; then
  case "$archive_input" in
    /*) archive="$archive_input" ;;
    *) archive="$BACKUP_DIR/$archive_input" ;;
  esac
else
  archive=$(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'felio-*.dump' -printf '%T@ %p\n' | sort -nr | head -n 1 | cut -d ' ' -f2-)
fi

if [ -z "${archive:-}" ] || [ ! -f "$archive" ]; then
  echo "A readable Felio archive is required" >&2
  exit 2
fi
archive=$(CDPATH= cd -- "$(dirname -- "$archive")" && pwd -P)/$(basename -- "$archive")
case "$archive" in
  "$BACKUP_DIR"/felio-*.dump) ;;
  *) echo "Archive must be a felio-*.dump file inside $BACKUP_DIR" >&2; exit 2 ;;
esac
if [ ! -f "$archive.sha256" ]; then
  echo "Missing checksum for $(basename "$archive")" >&2
  exit 2
fi

project="felio-backup-verify-$(date -u +%Y%m%d%H%M%S)-$$"
compose="docker compose -f $ROOT/docker-compose.backup-verify.yml"
volume="${project}_postgres_data"
network="${project}_net"

printf '%s\n' "Disposable restore target: project=$project database=felio_backup_verify volume=$volume network=$network archive=$(basename "$archive") backup_dir=$BACKUP_DIR"
case "$project" in felio-backup-verify-*) ;; *) echo "Invalid disposable project name" >&2; exit 2;; esac

cleanup() {
  status=$?
  FELIO_BACKUP_VERIFY_PROJECT="$project" $compose down -v --remove-orphans >/dev/null 2>&1 || cleanup_status=$?
  cleanup_status=${cleanup_status:-0}
  if [ "$status" -ne 0 ]; then exit "$status"; fi
  exit "$cleanup_status"
}
trap cleanup EXIT HUP INT TERM

sha256sum -c "$archive.sha256" >/dev/null
FELIO_BACKUP_VERIFY_PROJECT="$project" $compose config >/dev/null
FELIO_BACKUP_VERIFY_PROJECT="$project" $compose up -d --wait db
# Stream the archive over stdin: no host bind mount or production network is shared.
cat "$archive" | FELIO_BACKUP_VERIFY_PROJECT="$project" $compose exec -T db sh -ceu '
  umask 077
  cat > /tmp/felio-restore.dump
  pg_restore --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" --no-owner --no-privileges /tmp/felio-restore.dump
  rm -f /tmp/felio-restore.dump
  psql --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" --tuples-only --no-align --command "SELECT count(*) FROM pg_tables WHERE schemaname NOT IN ('\''pg_catalog'\'', '\''information_schema'\'');"
' > /tmp/felio-backup-restore-count-$$
table_count=$(tr -d '[:space:]' < /tmp/felio-backup-restore-count-$$)
rm -f /tmp/felio-backup-restore-count-$$
case "$table_count" in ''|*[!0-9]*) echo "Restore verification did not return a table count" >&2; exit 1;; esac
printf '%s\n' "Disposable restore verification passed: archive=$(basename "$archive") restored_table_count=$table_count"
