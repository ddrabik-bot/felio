#!/usr/bin/env sh
# Regression tests for fail-closed backup destination validation.
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/../.." && pwd -P)
SCRIPT="$ROOT/scripts/backup-postgres.sh"
tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT HUP INT TERM
bin="$tmp/bin"
log="$tmp/write.log"
mkdir -p "$bin"

cat > "$bin/docker" <<'EOF'
#!/usr/bin/env sh
set -eu
if [ "$1" = "volume" ] && [ "$2" = "inspect" ]; then
  printf '%s\n' "$FELIO_TEST_PROTECTED_VOLUME"
  exit 0
fi
if [ "$1" = "inspect" ]; then
  case "$4" in
    *com.docker.compose.project*) printf '%s\n' felio ;;
    *State.Health*) printf '%s\n' healthy ;;
    *) printf '%s\n' felio_postgres_data ;;
  esac
  exit 0
fi
printf '%s\n' "unexpected docker invocation: $*" >&2
exit 99
EOF
cat > "$bin/mkdir" <<'EOF'
#!/usr/bin/env sh
printf 'mkdir %s\n' "$*" >> "$FELIO_TEST_WRITE_LOG"
exec /bin/mkdir "$@"
EOF
cat > "$bin/chmod" <<'EOF'
#!/usr/bin/env sh
printf 'chmod %s\n' "$*" >> "$FELIO_TEST_WRITE_LOG"
exec /bin/chmod "$@"
EOF
chmod 755 "$bin/docker" "$bin/mkdir" "$bin/chmod"

assert_rejected_without_write() {
  label=$1
  destination=$2
  shift 2
  : > "$log"
  if PATH="$bin:$PATH" FELIO_TEST_PROTECTED_VOLUME="$protected" FELIO_TEST_WRITE_LOG="$log" "$@" FELIO_BACKUP_DIR="$destination" "$SCRIPT" >"$tmp/$label.out" 2>"$tmp/$label.err"; then
    echo "$label: expected refusal" >&2
    exit 1
  fi
  if [ -s "$log" ]; then
    echo "$label: destination validation wrote before refusing: $(cat "$log")" >&2
    exit 1
  fi
}

protected="$tmp/felio_postgres_data"
external_root="/opt/data/backups/felio"
assert_rejected_without_write protected-volume "$protected/attempted-backup" env
assert_rejected_without_write outside-allowlist "$tmp/outside-backups" env
assert_rejected_without_write overridden-volume "$external_root/test-backups" env FELIO_DB_VOLUME=attacker_controlled_volume

printf '%s\n' 'backup destination fail-closed tests passed'
