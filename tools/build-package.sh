#!/bin/sh
# build-package.sh - create the distributable extension tarball.
#
# Usage: tools/build-package.sh [--version <ver>] [--with-vendor]
#
# Output: dist/vmmngr-<version>.tar.gz (+ .sha256)
#
# By default no third-party binaries are shipped (see THIRD-PARTY.md).
# --with-vendor bundles vendor/ for systems without packages - then you are
# redistributing those binaries and must honour their licences.

set -eu

ROOT=$(cd "$(dirname "$0")/.." && pwd -P)
cd "${ROOT}"

VERSION=$(cat version.txt 2>/dev/null | tr -d '\n')
WITH_VENDOR=0

while [ $# -gt 0 ]; do
	case "$1" in
		--version) VERSION="$2"; shift 2 ;;
		--with-vendor) WITH_VENDOR=1; shift ;;
		*) echo "unknown option: $1" >&2; exit 2 ;;
	esac
done

[ -n "${VERSION}" ] || { echo "version.txt missing or empty" >&2; exit 1; }

NAME="vmmngr-${VERSION}"
STAGE="dist/${NAME}"

rm -rf "${STAGE}"

mkdir -p "${STAGE}" dist

# our code and documentation
cp -R bin gui templates "${STAGE}/"
cp vmm-init version.txt README.md CHANGELOG.md THIRD-PARTY.md LICENSE "${STAGE}/"

if [ "${WITH_VENDOR}" -eq 1 ] && [ -d vendor ]; then
	mkdir -p "${STAGE}/vendor"
	for d in vendor/*/; do
		[ -d "$d" ] || continue
		cp -R "$d" "${STAGE}/vendor/"
		# ship the licence text next to the binary it covers
		for lic in "$d"LICENSE* "$d"COPYING* "$d"license*; do
			[ -e "${lic}" ] && cp "${lic}" "${STAGE}/vendor/" 2>/dev/null || true
		done
	done
	find "${STAGE}/vendor" -name '.gitkeep' -delete
	echo "NOTE: vendor/ bundled - third-party licences apply (see THIRD-PARTY.md)"
fi

find "${STAGE}" -name '.gitignore' -delete
find "${STAGE}" -name '.DS_Store' -delete

chmod 0755 "${STAGE}" 2>/dev/null || true
chmod 0555 "${STAGE}/vmm-init" 2>/dev/null || true
find "${STAGE}/bin" -type f -exec chmod 0555 {} \; 2>/dev/null || true

tar -czf "dist/${NAME}.tar.gz" -C dist "${NAME}"
rm -rf "${STAGE}"

if command -v sha256sum >/dev/null 2>&1; then
	(cd dist && sha256sum "${NAME}.tar.gz" > "${NAME}.tar.gz.sha256")
elif command -v shasum >/dev/null 2>&1; then
	(cd dist && shasum -a 256 "${NAME}.tar.gz" > "${NAME}.tar.gz.sha256")
fi

echo "built dist/${NAME}.tar.gz"
ls -l "dist/${NAME}.tar.gz"
