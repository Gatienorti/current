#!/bin/sh
set -eu
version=${1:-development}
case "$version" in *[!A-Za-z0-9._-]*) echo 'Invalid artifact version' >&2; exit 1;; esac
arch=$(go env GOARCH)
[ "$(go env GOOS)" = linux ] || { echo 'Linux build required' >&2; exit 1; }
go test -race -count=1 ./...
mkdir -p dist
# Docker builds may run under a different owner than the mounted checkout.
# Provenance is recorded explicitly in VERSION rather than automatic Git stamping.
CGO_ENABLED=0 go build -buildvcs=false -trimpath -o "dist/current-supervisor-linux-$arch" .
cp ../LICENSE dist/current-supervisor-LICENSE
cp "$(go env GOROOT)/LICENSE" dist/current-supervisor-LICENSE-GO
printf '%s\n' "$version" > "dist/current-supervisor-VERSION-linux-$arch"
(cd dist && sha256sum "current-supervisor-linux-$arch" > "current-supervisor-SHA256SUMS-linux-$arch")
