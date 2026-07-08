#!/usr/bin/env bash
# Unit test for the file-manager path jail. Sources the helpers from the aidipanel
# CLI (AIDIPANEL_LIB_ONLY=1 skips the dispatcher) and asserts containment + symlink
# rules against a temp fixture.
#
# Symlink assertions are skipped on platforms without real symlink support (e.g.
# Git Bash on Windows); they run on Linux (CI / droplet), which is authoritative.

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
CLI="$ROOT/aidipanel"
[[ -f "$CLI" ]] || { echo "cannot find aidipanel at: $CLI"; exit 2; }

# shellcheck disable=SC1090
AIDIPANEL_LIB_ONLY=1 source "$CLI" || { echo "Could not source CLI helpers from $CLI"; exit 2; }

# Sourcing turns on the CLI's strict mode + may set an ERR trap, and defines its
# own ok()/die(). Relax so the assertions below manage their own exit status, and
# use t_-prefixed helpers so the CLI's ok() cannot shadow them.
set +eEu +o pipefail
trap - ERR 2>/dev/null || true

PASS=0; FAIL=0; SKIP=0
t_ok()   { PASS=$((PASS+1)); printf '  ok    %s\n' "$1"; }
t_fail() { FAIL=$((FAIL+1)); printf '  FAIL  %s\n' "$1"; }
t_skip() { SKIP=$((SKIP+1)); printf '  skip  %s\n' "$1"; }
t_try()  { ( "$@" ) >/dev/null 2>&1; }   # subshell: a die/exit can't abort the test

ROOT="$(mktemp -d)"; trap 'rm -rf "$ROOT"' EXIT
export FILES_HOME="${ROOT}/home/u1"
mkdir -p "${FILES_HOME}/htdocs" "${FILES_HOME}/logs" "${ROOT}/home/u2"
echo hello > "${FILES_HOME}/htdocs/index.php"
ln -s /etc/passwd "${FILES_HOME}/htdocs/escape.link"  2>/dev/null || true  # target outside the jail
ln -s ../../u2    "${FILES_HOME}/htdocs/sibling.link" 2>/dev/null || true  # target a sibling user

# existing path inside the jail resolves to its absolute path
p=$(_files_resolve_existing "htdocs/index.php" 2>/dev/null)
[[ "$p" == "${FILES_HOME}/htdocs/index.php" ]] && t_ok "existing inside jail" || t_fail "existing inside jail (got '$p')"

# traversal attempts are rejected
for t in "../" "../../etc/passwd" "/etc/passwd" "htdocs/../../etc/passwd"; do
  t_try _files_resolve_existing "$t" && t_fail "traversal allowed: $t" || t_ok "traversal blocked: $t"
done

# new path: parent inside jail, basename valid
p=$(_files_resolve_new "htdocs/new.txt" 2>/dev/null)
[[ "$p" == "${FILES_HOME}/htdocs/new.txt" ]] && t_ok "new path inside jail" || t_fail "new path inside jail (got '$p')"

# new path with traversal in the rel is rejected
t_try _files_resolve_new "htdocs/../../x" && t_fail "new traversal allowed" || t_ok "new traversal blocked"

# basename-only validation
t_try _files_basename_only "a/b" && t_fail "slash basename allowed" || t_ok "slash basename blocked"
b=$(_files_basename_only "ok-name.txt" 2>/dev/null)
[[ "$b" == "ok-name.txt" ]] && t_ok "valid basename accepted" || t_fail "valid basename accepted (got '$b')"

# symlinks whose target escapes the jail are rejected (Linux only)
if [[ -L "${FILES_HOME}/htdocs/escape.link" ]]; then
  t_try _files_resolve_existing "htdocs/escape.link" && t_fail "escape symlink allowed" || t_ok "escape symlink blocked"
else
  t_skip "escape symlink (no symlink support on this platform)"
fi
if [[ -L "${FILES_HOME}/htdocs/sibling.link" ]]; then
  t_try _files_resolve_existing "htdocs/sibling.link" && t_fail "sibling symlink allowed" || t_ok "sibling symlink blocked"
else
  t_skip "sibling symlink (no symlink support on this platform)"
fi

printf '\n%d passed, %d failed, %d skipped\n' "$PASS" "$FAIL" "$SKIP"
[[ "$FAIL" -eq 0 ]]
