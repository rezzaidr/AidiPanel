#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
INSTALLER="${ROOT}/install.sh"
TMP_ROOT=$(mktemp -d)
trap 'rm -rf -- "$TMP_ROOT"' EXIT

fail() { printf 'FAIL: %s\n' "$1" >&2; exit 1; }
pass() { printf 'ok: %s\n' "$1"; }

assert_value() {
  local file="$1" expected="$2" label="$3" actual
  actual=$(<"$file")
  [[ "$actual" == "$expected" ]] || fail "${label} (expected ${expected}, got ${actual})"
  pass "$label"
}

assert_contains() {
  local file="$1" pattern="$2" label="$3"
  grep -Fq -- "$pattern" "$file" || fail "$label"
  pass "$label"
}

assert_not_contains() {
  local file="$1" pattern="$2" label="$3"
  if grep -Fq -- "$pattern" "$file"; then fail "$label"; fi
  pass "$label"
}

assert_absent() {
  local path="$1" label="$2"
  [[ ! -e "$path" ]] || fail "$label"
  pass "$label"
}

HELPER=$(awk '
  $0 == "_enable_redis_acl_or_die() {" { capture=1 }
  capture { print }
  capture && $0 == "}" { exit }
' "$INSTALLER")
[[ -n "$HELPER" ]] || fail 'Redis ACL installer helper is missing'
HELPER=${HELPER//\/usr\/local\/bin\/aidipanel cache:redis-acl/aidipanel_cli cache:redis-acl}

run_case() {
  local name="$1" install_redis="$2" dry_run="$3" acl_results="$4"
  local stubborn_redis="${5:-false}" case_dir="${TMP_ROOT}/${name}"
  mkdir -p "$case_dir"
  printf '0\n' > "$case_dir/acl_calls"
  printf '0\n' > "$case_dir/sleeps"
  printf 'true\n' > "$case_dir/redis_active"
  printf 'true\n' > "$case_dir/redis_enabled"
  : > "$case_dir/events"
  : > "$case_dir/error"
  : > "$case_dir/panel.log"

  (
    export INSTALL_REDIS="$install_redis" DRY_RUN="$dry_run" PANEL_LOG="$case_dir/panel.log"

    aidipanel_cli() {
      local calls result
      calls=$(<"$case_dir/acl_calls")
      calls=$((calls + 1))
      printf '%s\n' "$calls" > "$case_dir/acl_calls"
      result=$(awk -v field="$calls" '{ print $field }' <<< "$acl_results")
      printf 'acl attempt %s\n' "$calls"
      [[ "${result:-1}" == "0" ]]
    }

    systemctl() {
      printf 'systemctl %s\n' "$*" >> "$case_dir/events"
      case "$1" in
        disable)
          [[ "$stubborn_redis" != "true" ]] || return 1
          printf 'false\n' > "$case_dir/redis_enabled"
          if [[ "${2:-}" == "--now" ]]; then
            printf 'false\n' > "$case_dir/redis_active"
          fi
          ;;
        stop)
          [[ "$stubborn_redis" != "true" ]] || return 1
          printf 'false\n' > "$case_dir/redis_active"
          ;;
        is-active)
          [[ "$(<"$case_dir/redis_active")" == "true" ]]
          ;;
        is-enabled)
          [[ "$(<"$case_dir/redis_enabled")" == "true" ]]
          ;;
        *) return 1 ;;
      esac
    }

    sleep() {
      local sleeps
      sleeps=$(<"$case_dir/sleeps")
      printf '%s\n' "$((sleeps + 1))" > "$case_dir/sleeps"
    }

    rm() {
      local arg target=''
      for arg in "$@"; do target="$arg"; done
      if [[ "$target" == "/usr/local/bin/aidipanel" ]]; then
        : > "$case_dir/cli_removed"
        return 0
      fi
      command rm "$@"
    }

    warn() { printf 'warn %s\n' "$*" >> "$case_dir/events"; }
    ui_ok() { printf 'ok %s\n' "$*" >> "$case_dir/events"; }
    ui_warn() { printf 'ui_warn %s\n' "$*" >> "$case_dir/events"; }
    die() { printf '%s\n' "$*" > "$case_dir/error"; exit 1; }

    eval "$HELPER"
    set +e
    ( _enable_redis_acl_or_die )
    rc=$?
    set -e
    printf '%s\n' "$rc" > "$case_dir/rc"
  )

  printf '%s\n' "$case_dir"
}

success_first=$(run_case success_first true false '0')
assert_value "$success_first/rc" 0 'first-attempt success returns zero'
assert_value "$success_first/acl_calls" 1 'first-attempt success calls ACL once'
assert_value "$success_first/sleeps" 0 'first-attempt success does not sleep'
assert_absent "$success_first/cli_removed" 'first-attempt success preserves CLI'
assert_contains "$success_first/panel.log" 'acl attempt 1' 'ACL output is written to installer log'
assert_not_contains "$success_first/events" 'systemctl ' 'first-attempt success leaves Redis untouched'

success_third=$(run_case success_third true false '1 1 0')
assert_value "$success_third/rc" 0 'transient ACL failure eventually succeeds'
assert_value "$success_third/acl_calls" 3 'transient failure makes three ACL attempts'
assert_value "$success_third/sleeps" 2 'transient failure waits only between attempts'
assert_absent "$success_third/cli_removed" 'eventual success preserves CLI'
assert_not_contains "$success_third/events" 'systemctl ' 'eventual success leaves Redis untouched'

failure_contained=$(run_case failure_contained true false '1 1 1')
assert_value "$failure_contained/rc" 1 'terminal ACL failure aborts installer gate'
assert_value "$failure_contained/acl_calls" 3 'terminal failure stops after three attempts'
assert_value "$failure_contained/sleeps" 2 'terminal failure sleeps twice'
assert_value "$failure_contained/redis_active" false 'terminal failure stops Redis'
assert_value "$failure_contained/redis_enabled" false 'terminal failure disables Redis'
[[ -e "$failure_contained/cli_removed" ]] || fail 'terminal failure removes copied CLI'
pass 'terminal failure removes copied CLI'
assert_contains "$failure_contained/error" 'Redis was stopped and disabled' 'contained failure explains recovery state'

failure_stubborn=$(run_case failure_stubborn true false '1 1 1' true)
assert_value "$failure_stubborn/rc" 1 'uncontained Redis failure aborts installer gate'
[[ -e "$failure_stubborn/cli_removed" ]] || fail 'uncontained failure still removes copied CLI'
pass 'uncontained failure still removes copied CLI'
assert_contains "$failure_stubborn/error" 'could not be stopped and disabled' 'uncontained failure requires manual intervention'

skip_no_redis=$(run_case skip_no_redis false false '')
assert_value "$skip_no_redis/rc" 0 '--no-redis skips ACL gate'
assert_value "$skip_no_redis/acl_calls" 0 '--no-redis does not call ACL command'
assert_absent "$skip_no_redis/cli_removed" '--no-redis preserves CLI'
assert_not_contains "$skip_no_redis/events" 'systemctl ' '--no-redis leaves Redis untouched'

skip_dry_run=$(run_case skip_dry_run true true '')
assert_value "$skip_dry_run/rc" 0 'dry-run skips ACL gate'
assert_value "$skip_dry_run/acl_calls" 0 'dry-run does not call ACL command'
assert_absent "$skip_dry_run/cli_removed" 'dry-run preserves CLI'
assert_not_contains "$skip_dry_run/events" 'systemctl ' 'dry-run leaves Redis untouched'

install_line=$(grep -nF '  _install_cli;' "$INSTALLER" | cut -d: -f1)
acl_line=$(grep -nF '  _enable_redis_acl_or_die' "$INSTALLER" | cut -d: -f1)
deploy_line=$(grep -nF '  _deploy_panel_app;' "$INSTALLER" | cut -d: -f1)
[[ -n "$install_line" && -n "$acl_line" && -n "$deploy_line" \
   && "$install_line" -lt "$acl_line" && "$acl_line" -lt "$deploy_line" ]] \
  || fail 'Redis ACL gate must run after CLI install and before panel deploy'
pass 'Redis ACL gate runs before panel deployment'

if grep -Fq 'Redis ACL isolation not enabled' "$INSTALLER"; then
  fail 'installer still permits warning-only ACL failure'
fi
pass 'warning-only Redis ACL path is absent'

printf 'installer Redis ACL fail-closed contract passed\n'
