#!/usr/bin/env sh
# Checks live database health and the newest on-host Felio backup without reading table data.
set -eu

# Production identity is deliberately literal and cannot be redirected through the environment.
CONTAINER="felio-db-1"
DATABASE="felio"
PROTECTED_VOLUME="felio_postgres_data"
PRODUCTION_PROJECT="felio"
EXTERNAL_BACKUP_ROOT="/opt/data/backups/felio"
BACKUP_DIR_INPUT="${FELIO_BACKUP_DIR:-$EXTERNAL_BACKUP_ROOT/postgresql}"
MAX_AGE_HOURS="${FELIO_BACKUP_MAX_AGE_HOURS:-26}"

if [ "${FELIO_DB_CONTAINER+x}" = x ] || [ "${FELIO_DB_NAME+x}" = x ] || [ "${FELIO_DB_VOLUME+x}" = x ]; then
  echo "Refusing production identity overrides; target is fixed to felio-db-1/felio/felio_postgres_data/felio" >&2
  exit 2
fi
case "$MAX_AGE_HOURS" in
  ''|*[!0-9]*) echo "FELIO_BACKUP_MAX_AGE_HOURS must be a non-negative integer" >&2; exit 2 ;;
esac

BACKUP_ROOT=$(realpath -e -- "$EXTERNAL_BACKUP_ROOT") || {
  echo "Configured external backup root is unavailable: $EXTERNAL_BACKUP_ROOT" >&2
  exit 2
}
BACKUP_DIR=$(realpath -e -- "$BACKUP_DIR_INPUT") || {
  echo "Backup destination must be an existing directory" >&2
  exit 2
}
volume_mountpoint=$(docker volume inspect "$PROTECTED_VOLUME" --format '{{.Mountpoint}}') || {
  echo "Protected database volume is unavailable: $PROTECTED_VOLUME" >&2
  exit 2
}
volume_mountpoint=$(realpath -m -- "$volume_mountpoint") || {
  echo "Invalid protected database volume mountpoint" >&2
  exit 2
}
case "$BACKUP_DIR" in
  "$volume_mountpoint"|"$volume_mountpoint"/*)
    echo "Refusing a backup destination inside the protected database volume" >&2
    exit 2
    ;;
esac
case "$BACKUP_DIR" in
  "$BACKUP_ROOT"|"$BACKUP_ROOT"/*) ;;
  *)
    echo "Refusing backup destination outside approved external root: $BACKUP_ROOT" >&2
    exit 2
    ;;
esac
resolved_volume=$(docker inspect "$CONTAINER" --format '{{range .Mounts}}{{if eq .Destination "/var/lib/postgresql/data"}}{{.Name}}{{end}}{{end}}')
project=$(docker inspect "$CONTAINER" --format '{{index .Config.Labels "com.docker.compose.project"}}')
health=$(docker inspect "$CONTAINER" --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}none{{end}}')

printf '%s\n' "Backup check target: project=$project container=$CONTAINER database=$DATABASE db_volume=$resolved_volume backup_dir=$BACKUP_DIR max_age_hours=$MAX_AGE_HOURS"
if [ "$resolved_volume" != "$PROTECTED_VOLUME" ] || [ "$project" != "$PRODUCTION_PROJECT" ]; then
  echo "Refusing unexpected production target" >&2
  exit 2
fi
if [ "$health" != "healthy" ]; then
  echo "Database health check failed: $health" >&2
  exit 1
fi

latest=$(find "$BACKUP_DIR" -maxdepth 1 -type f -name 'felio-*.dump' -printf '%T@ %p\n' | sort -nr | head -n 1 | cut -d ' ' -f2-)
if [ -z "$latest" ] || [ ! -f "$latest.sha256" ]; then
  echo "No complete Felio backup artifact found" >&2
  exit 1
fi

file_mode=$(stat -c '%a' "$latest")
checksum_mode=$(stat -c '%a' "$latest.sha256")
if [ "$file_mode" != "600" ] || [ "$checksum_mode" != "600" ]; then
  echo "Backup artifact permissions must be 0600" >&2
  exit 1
fi

sha256sum -c "$latest.sha256" >/dev/null
docker exec -i "$CONTAINER" pg_restore --list < "$latest" >/dev/null
now=$(date +%s)
modified=$(stat -c '%Y' "$latest")
age_seconds=$((now - modified))
max_age_seconds=$((MAX_AGE_HOURS * 3600))
if [ "$age_seconds" -gt "$max_age_seconds" ]; then
  echo "Newest backup is older than $MAX_AGE_HOURS hours" >&2
  exit 1
fi
printf '%s\n' "Backup check passed: archive=$(basename "$latest") age_seconds=$age_seconds bytes=$(wc -c < "$latest" | tr -d ' ')"
