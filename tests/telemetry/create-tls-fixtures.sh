#!/usr/bin/env bash
# Disposable test certificates only. Never install these in a real application.
set -euo pipefail
out=${1:?Usage: create-tls-fixtures.sh NEW_DIRECTORY}
mkdir "$out"
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$out/ca-key.pem" -out "$out/ca.pem" -days 2 -subj '/CN=Current disposable test CA' >/dev/null 2>&1
openssl req -x509 -newkey rsa:2048 -nodes -keyout "$out/wrong-ca-key.pem" -out "$out/wrong-ca.pem" -days 2 -subj '/CN=Current untrusted test CA' >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -keyout "$out/server-key.pem" -out "$out/server.csr" -subj '/CN=localhost' >/dev/null 2>&1
cat > "$out/server.ext" <<'EXT'
subjectAltName=DNS:localhost,DNS:current-async-mysql-test,DNS:current-async-maria-test,DNS:current-postgres-test,IP:127.0.0.1
extendedKeyUsage=serverAuth
EXT
openssl x509 -req -in "$out/server.csr" -CA "$out/ca.pem" -CAkey "$out/ca-key.pem" -CAcreateserial -out "$out/server.pem" -days 2 -extfile "$out/server.ext" >/dev/null 2>&1
openssl req -newkey rsa:2048 -nodes -keyout "$out/client-key.pem" -out "$out/client.csr" -subj '/CN=Current test client' >/dev/null 2>&1
printf 'extendedKeyUsage=clientAuth\n' > "$out/client.ext"
openssl x509 -req -in "$out/client.csr" -CA "$out/ca.pem" -CAkey "$out/ca-key.pem" -CAcreateserial -out "$out/client.pem" -days 2 -extfile "$out/client.ext" >/dev/null 2>&1
# Cross-container test UIDs must read these short-lived test-only keys.
chmod 644 "$out"/*.pem
