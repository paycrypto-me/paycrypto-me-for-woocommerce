#!/usr/bin/env bash
set -euo pipefail

# Keep the canonical release script and the generated ZIP on the host.
# Run PHP and Composer in the same image as the WordPress QA lab.
repo_root="$(cd "$(dirname "$0")/.." && pwd)"
export RELEASE_PODMAN_IMAGE="${RELEASE_PODMAN_IMAGE:-localhost/paycrypto-plugin-release-tools:0.4.3}"

for arg in "$@"; do
    case "$arg" in
        --svn-prepare|--svn-publish)
            echo "Use the SVN tool container for $arg. See docs/GUIDE-RELEASE.md." >&2
            exit 2
            ;;
    esac
done

tool_dir="$(mktemp -d)"
cleanup() {
    for tool in php composer run; do
        if [[ -e "$tool_dir/$tool" || -L "$tool_dir/$tool" ]]; then
            unlink "$tool_dir/$tool"
        fi
    done
    rmdir "$tool_dir"
}
trap cleanup EXIT
cat > "$tool_dir/run" <<'TOOL'
#!/usr/bin/env bash
set -euo pipefail
exec podman run --rm -v "$PWD:$PWD" -w "$PWD" --entrypoint "${0##*/}" \
    "$RELEASE_PODMAN_IMAGE" "$@"
TOOL
chmod +x "$tool_dir/run"
ln -s run "$tool_dir/php"
ln -s run "$tool_dir/composer"
export PATH="$tool_dir:$PATH"

"$repo_root/scripts/release.sh" --no-docker "$@"
