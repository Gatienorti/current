#!/usr/bin/env sh
# Run inside golang:1.25-alpine with build-base installed. Native builds only.
set -eu
version=${1:?Pass the exact release tag or commit SHA}
mkdir -p dist
arch=$(go env GOARCH)
case "$arch" in amd64|arm64) ;; *) echo 'Unsupported release architecture' >&2; exit 1;; esac
CGO_ENABLED=1 go build -trimpath -ldflags "-s -w -linkmode external -extldflags '-static' -X main.version=$version" -o "dist/current-collector-linux-$arch" .
"dist/current-collector-linux-$arch" --version
(cd dist && sha256sum "current-collector-linux-$arch" > "SHA256SUMS-linux-$arch")
