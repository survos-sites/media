#!/usr/bin/env bash
#
# backup-live.sh — load a copy of the LIVE mediary database into the local
# shared postgres (survos_postgres :5434) so we can test migrations against
# real data.
#
#   bin/backup-live.sh
#
# Dumps prod with `pg_dump -Fc` (custom format), drops & recreates the LOCAL
# `mediary` database, and restores into it. STOPS after the restore — review
# and run migrations yourself afterwards.
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
DUMP="/tmp/${LOCAL_DB}_live.dump"

# --- prod connection (never committed) ---------------------------------------
PROD_URL="${PROD_DATABASE_URL:-$(grep -m1 '178.156.199.185' .env.local 2>/dev/null | grep -oE 'postgresql://[^"?]+' || true)}"
if [[ -z "${PROD_URL}" ]]; then
  echo "ERROR: no prod connection string." >&2
  echo "       Set PROD_DATABASE_URL=postgresql://user:pass@host:5432/mediary" >&2
  echo "       or keep the live DATABASE_URL line in .env.local." >&2
  exit 1
fi

echo "==> 1/3  Dump LIVE mediary  ($(date +%T))  — custom format, queue data excluded"
echo "         (silent while it pulls ~2-3 GB; watch it grow from another shell:"
echo "          docker exec ${CONTAINER} ls -lh ${DUMP} )"
time docker exec "${CONTAINER}" pg_dump -Fc --no-owner --no-privileges \
  --exclude-table-data='public.messenger_messages' \
  --exclude-table-data='public.processed_messages' \
  "${PROD_URL}" -f "${DUMP}"
dump_rc=$?
if [[ ${dump_rc} -ne 0 ]]; then
  echo "ERROR: pg_dump failed (rc=${dump_rc}). LOCAL ${LOCAL_DB} left untouched." >&2
  exit 1
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
