#!/bin/bash
#
# Build the lldpd Slackware package (txz) used by the ulldpd plugin.
#
# Usage:  build/build-lldpd.sh [OUTDIR]
#   Env:  WORKDIR   scratch directory (default: mktemp, never inside the repo)
#         DOCKER=1  re-run this script inside a Slackware 15.0 container
#                   (recommended: links against the Slackware glibc that
#                   Unraid is based on)
#
# Choices made here:
#   * privilege separation ENABLED (user/group _lldpd, chroot /run/lldpd)
#   * libevent is the copy embedded in the lldpd tarball (statically linked)
#   * libxml2 (XML output) and readline are DISABLED: Unraid 7.x ships
#     libxml2.so.16, not .so.2, and the plugin only needs JSON/plain output
#   * pid file /run/lldpd.pid, control socket /run/lldpd.socket (upstream layout)
#
set -euo pipefail

LLDPD_VERSION="1.0.22"
LLDPD_SHA256="552baa55118bccbcd1e61c51db438930a36ce04e0abd84c4c0b7b20633ca2b5a"
LLDPD_URL="https://github.com/lldpd/lldpd/releases/download/${LLDPD_VERSION}/lldpd-${LLDPD_VERSION}.tar.gz"
BUILD="${BUILD:-1}"
ARCH="x86_64"
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
OUTDIR="$(realpath -m "${1:-$HERE/../packages}")"

if [ "${DOCKER:-0}" = "1" ] && [ ! -f /.dockerenv ]; then
    mkdir -p "$OUTDIR"
    exec docker run --rm -v "$HERE:/build:ro" -v "$OUTDIR:/out" -e BUILD="$BUILD" \
        vbatts/slackware:15.0 bash -c \
        'slackpkg -batch=on -default_answer=y install gcc gcc-g++ make binutils glibc kernel-headers libmpc autoconf automake libtool m4 pkg-config curl xz tar >/dev/null 2>&1; /build/build-lldpd.sh /out'
fi

WORKDIR="${WORKDIR:-$(mktemp -d)}"
case "$WORKDIR" in "$HERE"/*|"$HERE/..""/"*) echo "WORKDIR must be outside the repo" >&2; exit 1;; esac
mkdir -p "$WORKDIR" "$OUTDIR"
cd "$WORKDIR"

if [ ! -f "lldpd-${LLDPD_VERSION}.tar.gz" ]; then
    curl -fsSLo "lldpd-${LLDPD_VERSION}.tar.gz" "$LLDPD_URL"
fi
echo "${LLDPD_SHA256}  lldpd-${LLDPD_VERSION}.tar.gz" | sha256sum -c -

rm -rf "src" "pkg"
mkdir src pkg
tar xzf "lldpd-${LLDPD_VERSION}.tar.gz" -C src
cd "src/lldpd-${LLDPD_VERSION}"

# Keep the glibc symbol floor low (<= 2.37, Unraid >= 6.12): do not let
# configure pick up newer glibc-only functions, and avoid the C23 strto* aliases.
export ac_cv_func_strlcpy=no ac_cv_func_strlcat=no   # glibc 2.38+ only; lldpd ships compat versions
# glibc >= 2.38 headers redirect strtol/sscanf & co to __isoc23_* (GLIBC_2.38).
# Switch that redirection off so the binaries also run on older glibc.
cat > "$WORKDIR/glibc-compat.h" <<'HDR'
#include <features.h>
#ifdef __GLIBC_USE_C2X_STRTOL
# undef __GLIBC_USE_C2X_STRTOL
# define __GLIBC_USE_C2X_STRTOL 0
#endif
HDR
export CFLAGS="${CFLAGS:--O2} -include $WORKDIR/glibc-compat.h"

./configure \
    --prefix=/usr --sbindir=/usr/sbin --libdir=/usr/lib64 \
    --sysconfdir=/etc --localstatedir=/var --runstatedir=/run \
    --mandir=/usr/man --docdir="/usr/doc/lldpd-${LLDPD_VERSION}" \
    --enable-privsep --enable-pie --enable-hardening \
    --with-privsep-user=_lldpd --with-privsep-group=_lldpd \
    --with-privsep-chroot=/run/lldpd \
    --with-lldpd-pid-file=/run/lldpd.pid \
    --with-lldpd-ctl-socket=/run/lldpd.socket \
    --with-embedded-libevent \
    --without-xml --without-readline --without-seccomp --without-snmp \
    --disable-static --with-systemdsystemunitdir=no
make -j"$(nproc)"
make install DESTDIR="$WORKDIR/pkg"

cd "$WORKDIR/pkg"
find . -name '*.la' -delete
rm -rf usr/lib64/pkgconfig usr/include usr/share/bash-completion usr/share/zsh \
       usr/lib64/liblldpctl.so usr/lib64/*.a
find usr/sbin usr/lib64 -type f \( -name 'lldp*' -o -name '*.so*' \) -exec strip --strip-unneeded {} + 2>/dev/null || true
mkdir -p usr/doc/lldpd-${LLDPD_VERSION} install etc/lldpd.d
cp "$WORKDIR/src/lldpd-${LLDPD_VERSION}/"{README.md,NEWS,LICENSE,ChangeLog} usr/doc/lldpd-${LLDPD_VERSION}/ 2>/dev/null || true
cat > etc/lldpd.d/README.conf <<'CONF'
# You can put lldpd configuration snippets into this directory.
# Upon start, lldpd will read each file in this directory and execute its content
# as if it were passed as arguments to lldpcli. See lldpcli(8) for details.
CONF
find usr/man -type f ! -name '*.gz' -exec gzip -9 {} +
chmod 755 usr/sbin/*

cat > install/slack-desc <<DESC
     |-----handy-ruler------------------------------------------------------|
lldpd: lldpd (an implementation of IEEE 802.1ab (LLDP))
lldpd:
lldpd: LLDP allows you to know exactly on which port is a server (and
lldpd: reciprocally). It is an industry standard protocol designed to
lldpd: supplant proprietary Link-Layer protocols such as EDP or CDP.
lldpd:
lldpd: Built with privilege separation (user _lldpd, chroot /run/lldpd),
lldpd: embedded libevent, without libxml2/readline. Version ${LLDPD_VERSION}.
lldpd: Packaged for the ulldpd Unraid plugin (davidcoulson fork)
lldpd: WITHOUT ANY WARRANTIES.
lldpd:
DESC

cat > install/doinst.sh <<'DOINST'
#!/bin/bash
# lldpd privilege separation service account and chroot directory
getent group _lldpd >/dev/null 2>&1 || groupadd -r _lldpd 2>/dev/null || groupadd _lldpd
getent passwd _lldpd >/dev/null 2>&1 || \
    useradd -r -g _lldpd -d /run/lldpd -s /bin/false -c "lldpd privilege separation" _lldpd 2>/dev/null || \
    useradd -g _lldpd -d /run/lldpd -s /bin/false -c "lldpd privilege separation" _lldpd
mkdir -p /run/lldpd
chown root:root /run/lldpd
chmod 755 /run/lldpd
( cd usr/lib64 ; rm -rf liblldpctl.so.4 ; ln -sf liblldpctl.so.4.* liblldpctl.so.4 )
( cd usr/sbin ; rm -rf lldpctl ; ln -sf lldpcli lldpctl )
DOINST
chmod 755 install/doinst.sh

# normalise timestamps/ownership for reproducibility
PKG="lldpd-${LLDPD_VERSION}-${ARCH}-${BUILD}.txz"
if command -v makepkg >/dev/null 2>&1; then
    makepkg -l y -c n "$OUTDIR/$PKG"
else
    tar --owner=0 --group=0 --numeric-owner --sort=name --mode='go-w' --mtime='UTC 2026-01-01' \
        -cJf "$OUTDIR/$PKG" .
fi
# Fail if the binaries need a newer glibc than Unraid 6.12 (2.37) provides, or any non-base library
for b in usr/sbin/lldpd usr/sbin/lldpcli usr/lib64/liblldpctl.so.4.*; do
    need=$(objdump -T "$b" | grep -o 'GLIBC_[0-9.]*' | sort -uV | tail -1)
    echo "$b needs $need; NEEDED: $(readelf -d "$b" | grep -o 'Shared library: \[[^]]*\]' | tr '\n' ' ')"
    if [ "$(printf '%s\nGLIBC_2.37\n' "$need" | sort -V | tail -1)" != "GLIBC_2.37" ]; then
        echo "ERROR: $b requires $need (> GLIBC_2.37)" >&2; exit 1
    fi
    if readelf -d "$b" | grep NEEDED | grep -qvE 'libc\.so|liblldpctl'; then
        echo "ERROR: $b links an unexpected shared library" >&2; exit 1
    fi
done
cd "$OUTDIR"
md5sum "$PKG" > "$PKG.md5"
sha256sum "$PKG" > "$PKG.sha256"
echo "Built $OUTDIR/$PKG"
echo "Check runtime deps with: readelf -d usr/sbin/lldpd | grep NEEDED ; objdump -T <bin> | grep -o 'GLIBC_[0-9.]*' | sort -uV | tail -1"
