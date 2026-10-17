#!/bin/bash
#
# Package source/ as the ulldpd plugin txz (archive/ulldpd-<VERSION>-x86_64-1.txz)
# and write its .md5 / .sha256 files.
#
# Usage: build/build-plugin.sh <VERSION e.g. 2026.10.17>
#
set -euo pipefail
VERSION="${1:?usage: $0 <version, e.g. 2026.10.17>}"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SRC="$HERE/../source"
OUT="$HERE/../archive"
PKG="ulldpd-${VERSION}-x86_64-1.txz"

mkdir -p "$OUT"
# root-owned, sorted, fixed mtime => reproducible archive
tar --owner=0 --group=0 --numeric-owner --sort=name --mode='go-w' \
    --mtime="$(date -u -d "${VERSION//./-}" '+%Y-%m-%d 00:00:00 UTC')" \
    -C "$SRC" -cJf "$OUT/$PKG" .
cd "$OUT"
md5sum "$PKG" > "$PKG.md5"
sha256sum "$PKG" > "$PKG.sha256"
echo "Built $OUT/$PKG"
cat "$PKG.md5" "$PKG.sha256"
