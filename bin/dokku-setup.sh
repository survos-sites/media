#!/usr/bin/env bash
# One-time Dokku server configuration for fast deploys.
# Run from the project root: bin/dokku-setup.sh
set -euo pipefail

# Skip health checks entirely (no 10s wait) - still zero-downtime, just no checks
dokku checks:skip

# Drop wait-to-retire from 60s to 5s (fine for stateless/async apps)
dokku checks:set wait-to-retire 5

echo "Done. Fast deploys enabled."
