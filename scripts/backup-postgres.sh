#!/usr/bin/env sh
# Creates a consistent PostgreSQL custom-format backup outside the Docker DB volume.
set -eu

# Backups contain production data; do not allow group/other access even if host umask is permissive.
umask 077

# Production identity is deliberately literal and cannot be redirected through the environment.
CONTAINER="felio-db-1"
DATABASE="felio"
PROTECTED_VOLUME="felio_postgres_data"
PRODUCTION_PROJECT="felio"
EXTERNAL_BACKUP_ROOT="/opt/data/backups/felio"
BACKUP_DIR_INPUT="${FELIO_BACKUP_DIR:-$EXTERNAL_BACKUP_ROOT/postgresql}"
RETENTION_DAYS="${FELIO_BACKUP_RETENTION_DAYS:-30}"

if [ "${FELIO_DB_CONTAINER+x}" = x ] || [ "${FELIO_DB_NAME+x}" = x ] || [ "${FELIO_DB_VOLUME+x}" = x ]; then
  echo "Refusing production identity overrides; target is fixed to felio-db-1/felio/felio_postgres_data/felio" >&2
  exit 2
fi
case "$RETENTION_DAYS" in
  ''|*[!0-9]*) echo "FELIO_BACKUP_RETENTION_DAYS must be a non-negative integer" >&2; exit 2 ;;
esac

# Canonicalize and validate every caller-controlled destination before any write.
BACKUP_ROOT=$(realpath -e -- "$EXTERNAL_BACKUP_ROOT") || {
  echo "Configured external backup root is unavailable: $EXTERNAL_BACKUP_ROOT" >&2
  exit 2
}
BACKUP_DIR=$(realpath -m -- "$BACKUP_DIR_INPUT") || {
  echo "Invalid backup destination" >&2
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

printf '%s\n' "Backup target: project=$project container=$CONTAINER database=$DATABASE db_volume=$resolved_volume backup_dir=$BACKUP_DIR retention_days=$RETENTION_DAYS"

if [ "$resolved_volume" != "$PROTECTED_VOLUME" ] || [ "$project" != "$PRODUCTION_PROJECT" ]; then
  echo "Refusing unexpected production target; expected felio-db-1/felio/felio_postgres_data/felio" >&2
  exit 2
fi
if [ "$health" != "healthy" ]; then
  echo "Refusing backup because database health is $health" >&2
  exit 1
fi

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"

lock_file="$BACKUP_DIR/.backup.lock"
exec 9>"$lock_file"
if ! flock -n 9; then
  echo "Another Felio backup is already running" >&2
  exit 1
fi

stamp=$(date -u +%Y%m%dT%H%M%SZ)
final="$BACKUP_DIR/felio-$stamp.dump"
tmp="$BACKUP_DIR/.felio-$stamp.dump.tmp"
checksum_tmp="$BACKUP_DIR/.felio-$stamp.dump.sha256.tmp"
checksum="$final.sha256"
cleanup() { rm -f "$tmp" "$checksum_tmp"; }
trap cleanup EXIT HUP INT TERM

# pg_dump runs in the database container and streams directly to host storage.
docker exec "$CONTAINER" sh -ceu '
  test "$POSTGRES_DB" = "$1"
  pg_dump --username="$POSTGRES_USER" --dbname="$POSTGRES_DB" \
    --format=custom --compress=9 --no-owner --no-privileges
' sh "$DATABASE" > "$tmp"

test -s "$tmp"
# Validate the custom archive with the client supplied by the live PostgreSQL image.
docker exec -i "$CONTAINER" pg_restore --list < "$tmp" >/dev/null
checksum_value=$(sha256sum "$tmp" | cut -d ' ' -f1)
printf '%s  %s\n' "$checksum_value" "$final" > "$checksum_tmp"
mv "$tmp" "$final"
mv "$checksum_tmp" "$checksum"

# Retention deletes only complete Felio archive/checksum pairs in this backup directory.
find "$BACKUP_DIR" -maxdepth 1 -type f -name 'felio-*.dump' -mtime "+$RETENTION_DAYS" -print | while IFS= read -r expired; do
  if [ -f "$expired.sha256" ]; then
    rm -f "$expired" "$expired.sha256"
  fi
done

printf '%s\n' "Backup verified: archive=$(basename "$final") bytes=$(wc -c < "$final" | tr -d ' ') checksum=$(cut -d ' ' -f1 "$checksum")"
