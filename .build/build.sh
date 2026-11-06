#!/bin/sh
# Builds Bia as one executable, PHP inside, into dist/bia. The release
# workflow runs it on each platform; run it here the same way.
#
#   .build/build.sh            build dist/bia.phar and dist/bia
#   .build/build.sh phar       only dist/bia.phar
#   .build/build.sh binary     only dist/bia, from a dist/bia.phar built before
#
# Needs php (8.4, with phar.readonly off for the build) and composer for the
# PHAR; for the binary, spc (static-php-cli) on PATH or in $SPC, and the
# tools `spc doctor` asks for. PHP is compiled once into .build/spc/ and
# kept: delete that folder, or change craft.yml, to build it again.
#
# On Linux, build the binary in Alpine, as a release does, so it links musl
# and runs on any Linux:
#   docker run --rm -v "$PWD:$PWD" -w "$PWD" -e SPC=$PWD/spc alpine:3.22 \
#     sh -c 'apk add --no-cache curl && .build/build.sh binary'
set -eu

root=$(cd "$(dirname "$0")/.." && pwd)
dist="$root/dist"
spc_dir="$root/.build/spc"
spc="${SPC:-spc}"

phar() {
    rm -rf "$dist/install" && mkdir -p "$dist/install"
    cp -R "$root/bin" "$root/src" "$root/composer.json" "$dist/install/"
    cd "$dist/install"
    # The framework from Packagist, at the version composer.json asks for, not a local checkout.
    composer config --unset repositories
    composer update --no-dev --prefer-dist --classmap-authoritative --no-interaction --no-progress --quiet
    composer show crabphp/framework | grep -E '^versions'
    cd "$root"
    php -d phar.readonly=0 .build/build.php "$dist/install" "$dist/bia.phar"
}

micro() {
    mkdir -p "$spc_dir"
    # spc craft keeps its work beside craft.yml; a new craft.yml means a new PHP.
    if [ ! -f "$spc_dir/buildroot/bin/micro.sfx" ] || ! cmp -s "$root/.build/craft.yml" "$spc_dir/craft.yml"; then
        cp "$root/.build/craft.yml" "$spc_dir/craft.yml"
        (cd "$spc_dir" && "$spc" craft craft.yml)
    fi
}

binary() {
    micro
    (cd "$spc_dir" && "$spc" micro:combine "$dist/bia.phar" --with-micro=buildroot/bin/micro.sfx --output="$dist/bia")
    chmod +x "$dist/bia"
    "$dist/bia" --version
    ls -l "$dist/bia"
}

case "${1:-all}" in
    phar) phar ;;
    binary) binary ;;
    all) phar && binary ;;
    *) echo "usage: $0 [phar|binary]" >&2; exit 2 ;;
esac
