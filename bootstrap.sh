#!/usr/bin/env bash
set -euo pipefail
umask 077
if [[ ${EUID} -ne 0 ]]; then
  echo 'Run: sudo bash bootstrap.sh [--host example.org] [--public-ip IPv4]' >&2
  exit 1
fi
if ! command -v python3 >/dev/null; then
  echo 'Python 3 is required (included in Ubuntu Server 24.04).' >&2
  exit 1
fi
bundle_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
if [[ ! -f "$bundle_dir/lib/provision.py" || ! -f "$bundle_dir/payload/src/bootstrap.php" ]]; then
  echo 'Use the complete release bundle, not a standalone bootstrap.sh.' >&2
  exit 1
fi
if [[ -f "$bundle_dir/MANIFEST.sha256" ]]; then
  (cd -- "$bundle_dir" && sha256sum --check --strict --quiet MANIFEST.sha256)
  python3 -B "$bundle_dir/scripts/build.py" --verify-manifest "$bundle_dir"
fi
exec python3 -B "$bundle_dir/lib/provision.py" "$@"
