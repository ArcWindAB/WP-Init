#!/usr/bin/env bash
set -euo pipefail

wp_root="${ARCWIND_WP_TEST_ROOT:-/tmp/arcwind-wp}"
wp_cli="${ARCWIND_WP_CLI:-/tmp/wp}"
base_url="${ARCWIND_WP_TEST_URL:-http://127.0.0.1:18888}"
plugin_root="$wp_root/wp-content/plugins/arcwind-init"
wp_args=()
if [[ "${ARCWIND_WP_ALLOW_ROOT:-}" == '1' ]]; then wp_args+=(--allow-root); fi

restore_file() {
  if [[ -n "${disabled_file:-}" && -f "$disabled_file.disabled" ]]; then
    mv "$disabled_file.disabled" "$disabled_file"
  fi
}
trap restore_file EXIT

for module in class-arcwind-init-nexus-inventory.php class-arcwind-init-error-log.php class-arcwind-init-oneuptime.php class-arcwind-init-oneuptime-dashboard.php class-arcwind-init-feedback-trait.php class-arcwind-init-settings-trait.php class-arcwind-init-github-updater.php; do
  disabled_file="$plugin_root/includes/$module"
  mv "$disabled_file" "$disabled_file.disabled"
  php "$wp_cli" eval 'if (!class_exists("ArcWind_Init")) { throw new RuntimeException("Plugin bootstrap failed"); }' --path="$wp_root" "${wp_args[@]}"
  curl -fsS "$base_url/" -o /dev/null
  curl -fsS "$base_url/wp-login.php" -o /dev/null
  curl -fsS "$base_url/index.php?rest_route=/" -o /dev/null
  if [[ "$module" == class-arcwind-init-oneuptime-dashboard.php ]]; then
    php "$wp_cli" eval 'ArcWind_Init_OneUptime::uninstall();' --path="$wp_root" "${wp_args[@]}"
  fi
  restore_file
  disabled_file=''
done
echo 'WordPress optional module failure checks passed.'
