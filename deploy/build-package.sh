#!/bin/sh
# Builds dist/startseite-<version>.tar.gz (and a .zip) for installation on a normal web server.
set -eu

cd "$(dirname "$0")/.."
VERSION=$(sed -n "s/.*APP_VERSION', '\(.*\)').*/\1/p" src/bootstrap.php)
NAME="startseite-$VERSION"
STAGE=$(mktemp -d)
trap 'rm -rf "$STAGE"' EXIT

mkdir -p "$STAGE/$NAME" dist
cp -R src bin public config deploy README.md README.de.md LICENSE "$STAGE/$NAME/"
rm -f "$STAGE/$NAME/config/config.php" "$STAGE/$NAME/public/app_path.php" "$STAGE/$NAME/deploy/build-package.sh"
find "$STAGE" -name .DS_Store -delete
chmod +x "$STAGE/$NAME/deploy/install.sh"

COPYFILE_DISABLE=1 tar -czf "dist/$NAME.tar.gz" -C "$STAGE" "$NAME"
(cd "$STAGE" && zip -qr "$OLDPWD/dist/$NAME.zip" "$NAME")
echo "dist/$NAME.tar.gz"
echo "dist/$NAME.zip"
