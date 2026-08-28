#!/usr/bin/env bash
#
# Bee control-panel smoke test.
#
# Run from the plugin-testing site root, outside the container:
#
#     bash /Users/jholt/Sites/craft-bee/tests/integration/cp-smoke.sh
#
# Every CP screen Bee adds, loaded with a real admin session. A 200 is not enough — a Craft template
# error renders as a 200 with an exception page — so each response is also checked for the marker
# text that only appears when the template actually rendered.

set -u

BASE="${BEE_BASE:-https://plugin-testing.ddev.site}"
JAR="$(mktemp)"
PASS=0
FAIL=0

cleanup() { rm -f "$JAR"; }
trap cleanup EXIT

curlq() { curl -sk -b "$JAR" -c "$JAR" "$@"; }

login() {
  local token
  # Craft 5 emits the token inside a JSON config blob, as csrfTokenValue":"…"
  token=$(curlq "$BASE/admin/login" | grep -o 'csrfTokenValue"[[:space:]]*:[[:space:]]*"[^"]*"' | head -1 | sed 's/.*"\([^"]*\)"$/\1/')

  if [ -z "$token" ]; then
    echo "  ✗ could not read a CSRF token from the login screen"
    exit 1
  fi

  curlq -X POST "$BASE/index.php?p=admin/actions/users/login" \
    -H 'Accept: application/json' \
    -H 'X-Requested-With: XMLHttpRequest' \
    --data-urlencode "CRAFT_CSRF_TOKEN=$token" \
    --data-urlencode "loginName=${BEE_CP_USER:-admin}" \
    --data-urlencode "password=${BEE_CP_PASS:-claudepassword}" >/dev/null
}

screen() {
  local label="$1" path="$2" marker="$3"
  local body code

  body=$(curlq -w '\n%{http_code}' "$BASE/admin/$path")
  code=$(printf '%s' "$body" | tail -1)
  body=$(printf '%s' "$body" | sed '$d')

  if [ "$code" != "200" ]; then
    echo "  ✗ $label — HTTP $code"
    FAIL=$((FAIL + 1))
    return
  fi

  # `grep -q` closes the pipe early, which makes the feeding printf report a broken pipe. Count
  # instead of short-circuiting.
  if [ "$(printf '%s' "$body" | grep -ci 'yii\\base\\\|call-stack-item' || true)" != "0" ]; then
    echo "  ✗ $label — the template threw"
    printf '%s' "$body" | grep -o '<h2>[^<]*</h2>' | head -2 | sed 's/^/      /' || true
    FAIL=$((FAIL + 1))
    return
  fi

  if [ "$(printf '%s' "$body" | grep -cF "$marker" || true)" = "0" ]; then
    echo "  ✗ $label — rendered without “$marker”"
    FAIL=$((FAIL + 1))
    return
  fi

  echo "  ✓ $label"
  PASS=$((PASS + 1))
}

echo
echo "Signing in"
login
if [ "$(curlq -o /dev/null -w '%{http_code}' "$BASE/admin/dashboard")" = "200" ]; then
  echo "  ✓ signed in"
  PASS=$((PASS + 1))
else
  echo "  ✗ could not sign in"
  exit 1
fi

echo
echo "Screens"
screen 'Catalog'            'bee/catalog'      'Preview a payload'
screen 'New source'         'bee/catalog/new'  'Built-in mappers'
screen 'Log'                'bee/log'          'Connection log'
screen 'Log, failures only' 'bee/log?filter=failures' 'Connection log'
screen 'Diagnostics'        'bee/diagnostics'  'Diagnostics'
screen 'Settings'           'settings/plugins/bee' 'Private token'

echo
echo "Front end"
CODE=$(curl -sk -o /dev/null -w '%{http_code}' -X POST "$BASE/bee/track" \
  -H 'Content-Type: application/json' \
  --data '{"kind":"detailview","itemId":"e-does-not-exist"}')

if [ "$CODE" = "200" ]; then
  echo "  ✓ the tracking endpoint answers without a CSRF token"
  PASS=$((PASS + 1))
else
  echo "  ✗ the tracking endpoint returned $CODE"
  FAIL=$((FAIL + 1))
fi

BODY=$(curl -sk -X POST "$BASE/bee/track" -H 'Content-Type: application/json' \
  --data '{"kind":"detailview","itemId":"e-does-not-exist"}')

if [ "$(printf '%s' "$BODY" | grep -c 'unknown-item\|disabled\|no-identity' || true)" != "0" ]; then
  echo "  ✓ an item that was never synced is refused"
  PASS=$((PASS + 1))
else
  echo "  ✗ an unsynced item was not refused: $BODY"
  FAIL=$((FAIL + 1))
fi

BODY=$(curl -sk -X POST "$BASE/bee/track" -H 'Content-Type: application/json' \
  --data '{"kind":"deleteEverything","itemId":"e1"}')

if [ "$(printf '%s' "$BODY" | grep -c 'unknown-kind\|disabled' || true)" != "0" ]; then
  echo "  ✓ an unknown interaction kind is refused"
  PASS=$((PASS + 1))
else
  echo "  ✗ an unknown kind was not refused: $BODY"
  FAIL=$((FAIL + 1))
fi

# The runtime is only injected once Bee is connected, so this is a conditional check rather than a
# failure on an unconfigured harness.
HOME_BODY=$(curl -sk "$BASE/")

if [ "$(printf '%s' "$HOME_BODY" | grep -c 'bee.js' || true)" != "0" ]; then
  if [ "$(printf '%s' "$HOME_BODY" | grep -c 'Bee.start' || true)" != "0" ]; then
    echo "  ✓ the front-end runtime is injected and started"
    PASS=$((PASS + 1))
  else
    echo "  ✗ bee.js is on the page but never started"
    FAIL=$((FAIL + 1))
  fi
else
  echo "  · runtime injection not checked — this install is not connected to a Recombee database"
fi

echo
if [ "$FAIL" -eq 0 ]; then
  printf '\033[32mAll %d CP checks passed.\033[0m\n' "$PASS"
else
  printf '\033[31m%d of %d CP checks failed.\033[0m\n' "$FAIL" "$((PASS + FAIL))"
  exit 1
fi
