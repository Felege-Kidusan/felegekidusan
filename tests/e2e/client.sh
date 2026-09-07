#!/usr/bin/env bash
# ============================================================
# SSMS edu-audit E2E client — login, CSRF, authenticated calls.
# Usage: source tests/e2e/client.sh   (exposes functions)
#        ssms_login <username>
#        ssms_get <path>            → prints body
#        ssms_post <path> k=v ...   → prints body (CSRF auto-added)
#        ssms_api <method> <route> [json]   → mobile API (JWT)
# Env: BASE (default http://127.0.0.1:8080), PASS (default AuditTest#2026)
# ============================================================
BASE="${BASE:-http://127.0.0.1:8080}"
PASS="${PASS:-AuditTest#2026}"
JAR="$(mktemp)"

ssms_login() {
  local user="$1"
  rm -f "$JAR"
  # 1) fetch login page for CSRF token + session cookie
  local html token
  html=$(curl -s -c "$JAR" "$BASE/admin/index.php")
  token=$(printf '%s' "$html" | grep -o 'name="csrf_token" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"$//')
  [ -z "$token" ] && { echo "NO_CSRF_TOKEN" >&2; return 1; }
  # 2) login
  curl -s -b "$JAR" -c "$JAR" -o /dev/null -w '%{http_code} %{redirect_url}\n' \
    -d "csrf_token=$token" -d "username=$user" -d "password=$PASS" \
    "$BASE/admin/backend/login.php"
}

ssms_csrf() {  # authenticated token; login rotates the anonymous token
  curl -s -L -b "$JAR" -c "$JAR" "$BASE/admin/dashboard.php" \
    | python3 -c 'import re,sys
s=sys.stdin.read()
patterns=[r"name=\"csrf-token\"\s+content=\"([a-f0-9]+)\"",r"name=\"csrf_token\"\s+value=\"([a-f0-9]+)\"",r"(?:CSRF_TOKEN|csrf_token|csrfToken|csrf)[\x27\"]?\s*[:=]\s*[\x27\"]([a-f0-9]{64})"]
for p in patterns:
 m=re.search(p,s)
 if m: print(m[1]);break
else: sys.exit("No authenticated dashboard CSRF token found")'
}

ssms_get() { curl -s -b "$JAR" -c "$JAR" "$BASE$1"; }

ssms_post() {
  local path="$1"; shift
  local token; token=$(ssms_csrf)
  local args=()
  while [ $# -gt 0 ]; do args+=(--data-urlencode "$1"); shift; done
  curl -s -b "$JAR" -c "$JAR" "${args[@]}" -d "csrf_token=$token" "$BASE$path"
}

# ---- mobile REST API (JWT login) ----
API_TOKEN=""
ssms_api_login() {
  local user="$1" body
  body=$(curl -s -H 'Content-Type: application/json' \
    -d "{\"username\":\"$user\",\"password\":\"$PASS\"}" \
    "$BASE/api/v1/index.php?_route=auth/login")
  API_TOKEN=$(printf '%s' "$body" | python3 -c 'import sys,json;d=json.load(sys.stdin);print(d.get("data",{}).get("token",""))' 2>/dev/null)
  [ -n "$API_TOKEN" ] && echo "API_LOGIN_OK" || echo "API_LOGIN_FAIL: $body"
}

ssms_api() {
  local method="$1" route="$2" data="$3"
  if [ -n "$data" ]; then
    curl -s -X "$method" -H "Authorization: Bearer $API_TOKEN" -H 'Content-Type: application/json' \
      -d "$data" "$BASE/api/v1/index.php?_route=$route"
  else
    curl -s -X "$method" -H "Authorization: Bearer $API_TOKEN" \
      "$BASE/api/v1/index.php?_route=$route"
  fi
}
