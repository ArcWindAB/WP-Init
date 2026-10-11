#!/usr/bin/env bash
set -euo pipefail

base_url="${ARCWIND_WP_TEST_URL:-http://127.0.0.1:18888}"
cookie_file="$(mktemp)"
response_file="$(mktemp)"
trap 'rm -f "$cookie_file" "$response_file"' EXIT

curl -fsS -c "$cookie_file" -b "$cookie_file" "$base_url/wp-login.php" > /dev/null
curl -fsS -c "$cookie_file" -b "$cookie_file" \
  --data-urlencode 'log=admin' --data-urlencode 'pwd=example-password' \
  --data-urlencode 'wp-submit=Log In' --data-urlencode "redirect_to=$base_url/wp-admin/" \
  --data-urlencode 'testcookie=1' "$base_url/wp-login.php" > /dev/null

for path in 'wp-admin/index.php' 'wp-admin/plugins.php' 'wp-admin/options-general.php?page=arcwind_init'; do
  curl -fsSL -c "$cookie_file" -b "$cookie_file" "$base_url/$path" -o "$response_file"
  if ! grep -q '<body' "$response_file" || grep -q 'There has been a critical error' "$response_file"; then
    echo "Admin page failed: $path" >&2
    exit 1
  fi
  if [[ "$path" == wp-admin/options-general.php* ]] && grep -q 'wp-view-transitions-admin' "$response_file"; then
    echo 'The settings page still enables browser navigation transitions.' >&2
    exit 1
  fi
done
curl -fsS "$base_url/wp-content/plugins/arcwind-init/assets/js/arcwind-init-tracker.js" -o "$response_file"
grep -q 'window.ArcWindInit.track' "$response_file"
curl -fsS "$base_url/wp-login.php" -o "$response_file"
grep -q 'user_login' "$response_file"
curl -fsS "$base_url/index.php?rest_route=/" -o "$response_file"
grep -q '"namespaces"' "$response_file"
curl -fsS "$base_url/wp-cron.php?doing_wp_cron" -o "$response_file"
echo 'WordPress admin HTTP pages passed.'
