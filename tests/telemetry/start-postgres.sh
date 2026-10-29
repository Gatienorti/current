#!/usr/bin/env bash
# Dedicated disposable server only; the named container must not already exist.
set -euo pipefail
out=${1:?Absolute TLS fixture directory}
image=${2:-postgres:17-alpine}
network=${3:-bridge}
[[ "$out" = /* ]] || exit 1
bash tests/telemetry/create-tls-fixtures.sh "$out"
# libpq refuses a world-readable client key. Tests run as the generating user.
chmod 600 "$out/client-key.pem"
cat > "$out/pg_hba.conf" <<'HBA'
local all all trust
hostssl all all all scram-sha-256 clientcert=verify-ca
HBA
docker run -d --name current-postgres-test --network "$network" -p 127.0.0.1:5432:5432 \
  -v "$out:/fixtures:ro" -e POSTGRES_PASSWORD=current-test-only -e POSTGRES_DB=current_async_test \
  --entrypoint sh "$image" -c 'mkdir -p /tmp/current-pgssl; for file in ca.pem server.pem server-key.pem pg_hba.conf; do cp /fixtures/$file /tmp/current-pgssl/; done; chown -R postgres:postgres /tmp/current-pgssl; chmod 600 /tmp/current-pgssl/server-key.pem; exec docker-entrypoint.sh postgres -c ssl=on -c ssl_ca_file=/tmp/current-pgssl/ca.pem -c ssl_cert_file=/tmp/current-pgssl/server.pem -c ssl_key_file=/tmp/current-pgssl/server-key.pem -c hba_file=/tmp/current-pgssl/pg_hba.conf'
for attempt in $(seq 1 40); do
  if docker exec current-postgres-test pg_isready -U postgres -d current_async_test >/dev/null 2>&1; then exit 0; fi
  sleep 1
done
docker logs current-postgres-test
exit 1
