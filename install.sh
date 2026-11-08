#!/bin/sh
# Installs the latest Bia release:
#
#   curl -fsSL https://raw.githubusercontent.com/crabphp/bia/main/install.sh | sh
#
# BIA_INSTALL_DIR sets where it goes (default ~/.local/bin).
set -eu

main() {
    repo="https://github.com/crabphp/bia/releases/latest/download"
    dir="${BIA_INSTALL_DIR:-$HOME/.local/bin}"

    case "$(uname -s)" in
        Linux) os=linux ;;
        Darwin) os=macos ;;
        *) fail "Bia runs on Linux and macOS; on Windows, run this in WSL." ;;
    esac
    case "$(uname -m)" in
        x86_64 | amd64) arch=x86_64 ;;
        aarch64 | arm64) arch=aarch64 ;;
        *) fail "no Bia build for $(uname -m)." ;;
    esac
    file="bia-$os-$arch.tar.gz"

    command -v curl > /dev/null || fail "curl is needed."
    tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' EXIT

    echo "Downloading $file..."
    # GitHub's downloads fail now and then (a 504): try again before giving up.
    curl -fsSL --retry 3 --retry-delay 2 -o "$tmp/$file" "$repo/$file" || fail "could not download $repo/$file"
    curl -fsSL --retry 3 --retry-delay 2 -o "$tmp/SHA256SUMS" "$repo/SHA256SUMS" || fail "could not download SHA256SUMS"

    want="$(awk -v f="$file" '$2 == f { print $1 }' "$tmp/SHA256SUMS")"
    if command -v sha256sum > /dev/null; then
        got="$(sha256sum "$tmp/$file" | awk '{ print $1 }')"
    else
        got="$(shasum -a 256 "$tmp/$file" | awk '{ print $1 }')"
    fi
    [ -n "$want" ] && [ "$want" = "$got" ] || fail "checksum mismatch for $file."

    tar xzf "$tmp/$file" -C "$tmp"
    mkdir -p "$dir"
    mv "$tmp/bia" "$dir/bia"
    chmod +x "$dir/bia"

    echo "Installed bia $("$dir/bia" --version) to $dir/bia"
    case ":$PATH:" in
        *":$dir:"*) ;;
        *)
            echo
            echo "$dir is not on your PATH. Add this to your shell's startup file:"
            echo "  export PATH=\"$dir:\$PATH\""
            ;;
    esac
    echo
    echo "Next: bia doctor"
}

fail() {
    echo "bia install: $1" >&2
    exit 1
}

main "$@"
