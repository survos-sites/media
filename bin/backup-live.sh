#!/usr/bin/env bash
#
# backup-live.sh — load a copy of the LIVE mediary database into the local
# shared postgres (survos_postgres :5434) so we can test migrations against
# real data.
#
#   bin/backup-live.sh                 # dump on prod + rsync down (default, resumable)
#   REMOTE_DUMP=0 bin/backup-live.sh   # stream pg_dump across the WAN instead
#
# Dumps prod with `pg_dump -Fc` (custom format), drops & recreates the LOCAL
# `mediary` database, and restores into it. STOPS after the restore — review
# and run migrations yourself afterwards.
#
# Transfer: by default step 1 dumps on the prod host and rsyncs the file down
# (rsync -P resumes a partial transfer; just re-run) — robust for large tables
# like asset (~1.8 GB). Set REMOTE_DUMP=0 to stream the dump across the WAN
# instead, but that COPY stream can stall on a flaky link with no way to resume.
# See the config block below for PROD_SSH / REMOTE_PGDUMP overrides.
#
# The prod connection string is read from .env.local (kept out of git) or the
# PROD_DATABASE_URL env var, so no password lives in this committed file.
# The transient queue tables (messenger_messages, processed_messages) are
# excluded — they're requeued after the upgrade.
#
# On indexes: there's no need to drop them first. `pg_restore` from a -Fc
# archive loads table DATA first, then builds indexes/constraints afterward
# (one bulk build each, not row-by-row). `-j` parallelizes those builds and
# maintenance_work_mem speeds them up.

set -uo pipefail
cd "$(dirname "$0")/.."   # project root

CONTAINER="${PG_CONTAINER:-survos_postgres}"   # shared local postgres (ships pg18 client tools)
LOCAL_DB="${LOCAL_DB:-mediary}"
LOCAL_USER="${LOCAL_USER:-postgres}"
JOBS="${JOBS:-4}"
DUMP="/tmp/${LOCAL_DB}_live.dump"   # path inside ${CONTAINER} that pg_restore reads

# --- transfer mode -----------------------------------------------------------
# Default (REMOTE_DUMP=1): run pg_dump ON the prod host and rsync the file down
# (resumable). Strongly preferred for large tables (e.g. asset ~1.8 GB): rsync -P
# picks up where it left off, whereas a streamed COPY stalls on a flaky link with
# no resume. Set REMOTE_DUMP=0 to stream across the WAN instead. Requires SSH.
#   REMOTE_DUMP=0 bin/backup-live.sh   # opt out, stream instead
# The DB has its own IP (in ${PROD_URL}); we SSH to the dokku app host, which
# reaches the DB over the fast datacenter network, dump there, then rsync down.
REMOTE_DUMP="${REMOTE_DUMP:-1}"
PROD_SSH="${PROD_SSH:-dokku_root}"                 # ssh alias (~/.ssh/config) for the dokku host
# Write to the big data volume (/mnt/volume-1, ~1.2T free), not / (only ~6G free).
REMOTE_DUMP_PATH="${REMOTE_DUMP_PATH:-/mnt/volume-1/backups/${LOCAL_DB}_live.dump}"  # dump path on that host
HOST_DUMP="${HOST_DUMP:-/tmp/${LOCAL_DB}_live.dump}"                # local staging path for rsync
# Server is Postgres 18 — use the matching client (a v17 pg_dump refuses a v18 server).
REMOTE_PGDUMP="${REMOTE_PGDUMP:-/usr/lib/postgresql/18/bin/pg_dump}"

# --- prod connection (never committed) ---------------------------------------
PROD_URL="${PROD_DATABASE_URL:-$(grep -m1 '178.156.199.185' .env.local 2>/dev/null | grep -oE 'postgresql://[^"?]+' || true)}"
if [[ -z "${PROD_URL}" ]]; then
  echo "ERROR: no prod connection string." >&2
  echo "       Set PROD_DATABASE_URL=postgresql://user:pass@host:5432/mediary" >&2
  echo "       or keep the live DATABASE_URL line in .env.local." >&2
  exit 1
fi

echo "==> 1/3  Dump LIVE mediary  ($(date +%T))  — custom format; messenger queue data excluded, asset INCLUDED"

# Shared exclude flags (word-split intentionally; no spaces within a token).
EXCLUDES="--exclude-table-data=public.messenger_messages --exclude-table-data=public.processed_messages"

if [[ "${REMOTE_DUMP}" == "1" ]]; then
  echo "    mode: dump on prod (${PROD_SSH}) then rsync down — resumable; best for the large asset table."
  echo "    1a) ${REMOTE_PGDUMP} on prod -> ${REMOTE_DUMP_PATH}"
  ssh "${PROD_SSH}" "mkdir -p '$(dirname "${REMOTE_DUMP_PATH}")' && ${REMOTE_PGDUMP} -Fc --no-owner --no-privileges ${EXCLUDES} '${PROD_URL}' -f '${REMOTE_DUMP_PATH}'" || {
    echo "ERROR: remote pg_dump failed. LOCAL ${LOCAL_DB} left untouched." >&2; exit 1; }
  echo "    1b) rsync ${PROD_SSH}:${REMOTE_DUMP_PATH} -> ${HOST_DUMP}   (-P resumes if interrupted; just re-run)"
  rsync -P "${PROD_SSH}:${REMOTE_DUMP_PATH}" "${HOST_DUMP}" || {
    echo "ERROR: rsync failed. Re-run to resume — the partial ${HOST_DUMP} is kept." >&2; exit 1; }
  echo "    1c) docker cp ${HOST_DUMP} -> ${CONTAINER}:${DUMP}"
  docker cp "${HOST_DUMP}" "${CONTAINER}:${DUMP}" || {
    echo "ERROR: docker cp into ${CONTAINER} failed." >&2; exit 1; }
else
  echo "         mode: stream pg_dump COPY across the WAN — fine for small tables, but the"
  echo "         ~1.8 GB asset table can stall on a flaky link (no resume). Prefer REMOTE_DUMP=1."
  echo "         watch from another shell: docker exec ${CONTAINER} ls -lh ${DUMP}"
  time docker exec "${CONTAINER}" pg_dump -Fc --no-owner --no-privileges \
    ${EXCLUDES} \
    "${PROD_URL}" -f "${DUMP}"
  dump_rc=$?
  if [[ ${dump_rc} -ne 0 ]]; then
    echo "ERROR: pg_dump failed (rc=${dump_rc}). LOCAL ${LOCAL_DB} left untouched." >&2
    exit 1
  fi
fi
docker exec "${CONTAINER}" ls -lh "${DUMP}"

echo "==> 2/3  Recreate LOCAL ${LOCAL_DB}  ($(date +%T))  — isolated; other project DBs untouched"
docker exec "${CONTAINER}" psql -U "${LOCAL_USER}" -v ON_ERROR_STOP=1 \
  -c "DROP DATABASE IF EXISTS ${LOCAL_DB} WITH (FORCE);" \
  -c "CREATE DATABASE ${LOCAL_DB} OWNER ${LOCAL_USER};" \
  -c "ALTER DATABASE ${LOCAL_DB} SET maintenance_work_mem='1GB';" || {
    echo "ERROR: could not recreate ${LOCAL_DB}." >&2; exit 1; }

echo "==> 3/3  Restore  ($(date +%T))  — data first, then indexes (parallel -j ${JOBS}); -v shows each object"
time docker exec "${CONTAINER}" pg_restore --no-owner --no-privileges \
  -j "${JOBS}" -v -U "${LOCAL_USER}" -d "${LOCAL_DB}" "${DUMP}"
echo "    pg_restore exit $? (non-zero is usually harmless warnings — extensions/comments)"

echo
echo "==> Local ${LOCAL_DB} size:  $(docker exec "${CONTAINER}" psql -U "${LOCAL_USER}" -d "${LOCAL_DB}" -tAc "SELECT pg_size_pretty(pg_database_size('${LOCAL_DB}'))")"
echo
echo "STOPPED after restore (no migrations run). When ready to test migrations:"
echo "  1) comment out the sqlite DATABASE_URL in .env.local so postgres:5434 is used"
echo "  2) php bin/console doctrine:migrations:up-to-date"
echo "  3) php bin/console doctrine:migrations:migrate --dry-run"
