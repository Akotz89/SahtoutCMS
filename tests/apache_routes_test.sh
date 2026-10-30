#!/usr/bin/env bash
set -eu
root=$(cd "$(dirname "$0")/.." && pwd)
tmp=$(mktemp -d "${TMPDIR:-/tmp}/cms-apache.XXXXXXXX")
name="cms-apache-test-$$"
engine=${CMS_CONTAINER_ENGINE:-docker}
engine_opts=()
if [ "$engine" = podman ]; then engine_opts=(--cgroup-manager=cgroupfs); fi
cleanup() {
  "$engine" "${engine_opts[@]}" rm -f "$name" >/dev/null 2>&1 || true
  rm -f "$tmp/docroot/.htaccess" "$tmp/docroot/install/.htaccess" "$tmp/docroot/install/index.php" "$tmp/docroot/install/step3_db.php" "$tmp/docroot/pages/forgot_password.php" "$tmp/docroot/index.php" "$tmp/httpd.conf"
  rmdir "$tmp/docroot/install" "$tmp/docroot/pages" "$tmp/docroot" "$tmp" 2>/dev/null || true
}
trap cleanup EXIT
mkdir -p "$tmp/docroot/install" "$tmp/docroot/pages"
cp "$root/.htaccess" "$tmp/docroot/.htaccess"
cp "$root/install/.htaccess" "$tmp/docroot/install/.htaccess"
for file in install/index.php install/step3_db.php pages/forgot_password.php index.php; do printf 'fixture\n' > "$tmp/docroot/$file"; done
cat > "$tmp/httpd.conf" <<'CONFIG'
ServerRoot "/usr/local/apache2"
Listen 8080
ServerName localhost
LoadModule mpm_event_module modules/mod_mpm_event.so
LoadModule authz_core_module modules/mod_authz_core.so
LoadModule unixd_module modules/mod_unixd.so
LoadModule dir_module modules/mod_dir.so
LoadModule mime_module modules/mod_mime.so
LoadModule rewrite_module modules/mod_rewrite.so
TypesConfig conf/mime.types
DirectoryIndex index.php
DocumentRoot "/app"
<Directory "/app">
  AllowOverride FileInfo Options
  Options FollowSymLinks
  Require all granted
</Directory>
ErrorLog /proc/self/fd/2
CONFIG
"$engine" "${engine_opts[@]}" run -d --name "$name" -p 127.0.0.1::8080 -v "$tmp/docroot":/app:ro -v "$tmp/httpd.conf":/usr/local/apache2/conf/httpd.conf:ro httpd:2.4 >/dev/null
address=$("$engine" "${engine_opts[@]}" port "$name" 8080/tcp | head -n 1)
curl -s --retry 10 --retry-connrefused --retry-delay 1 -o /dev/null "http://$address/index.php"
for route in /install/ /install/index.php /install/step3_db.php; do
  [ "$(curl --fail-with-body -s -o /dev/null -w '%{http_code}' "http://$address$route" || true)" = 404 ] || { printf 'Installer exposed: %s\n' "$route" >&2; exit 1; }
done
for route in /forgot_password /pages/forgot_password.php; do
  [ "$(curl -s -o /dev/null -w '%{http_code}' "http://$address$route")" = 200 ] || { printf 'Recovery route unavailable: %s\n' "$route" >&2; exit 1; }
done
printf 'Apache route checks passed\n'
