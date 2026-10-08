#!/usr/bin/env bash
# =============================================================================
# promote_to_production.sh — promote an approved development commit to the
# production repository through the sync-PR flow (docs/PRODUCTION_WORKFLOW.md)
# =============================================================================
# Flow implemented here:
#   1. resolve the source commit (a branch, tag or SHA in this checkout)
#   2. push it to Felege-Kidusan/felegekidusan as refs/heads/sync/from-development
#   3. open a pull request into main (CI runs on the PR: backend checks +
#      production integrity)
#   4. with --merge: wait until every check has completed, require them
#      green, merge the PR, delete the sync branch
#
# The token is read from the FKSS_GH_PAT environment variable and is used
# only in-process (inline credential helper / Authorization header). It is
# never written to any file, git config or log.
#
# USAGE
#   export FKSS_GH_PAT='<your PAT>'     # repo + workflow scopes (never commit it)
#   scripts/promote_to_production.sh [source_ref] [--merge] [--dry-run]
#     source_ref  branch/tag/SHA to promote (default: HEAD)
#     --merge     after CI is green, merge the PR and delete the branch
#     --dry-run   show what would happen, change nothing
#
# EXAMPLES
#   scripts/promote_to_production.sh main                # open the sync PR
#   scripts/promote_to_production.sh main --merge        # ... and merge it
#   scripts/promote_to_production.sh 08e9af1 --merge     # promote one commit
# =============================================================================
set -euo pipefail

DEV_REPO="suraman21/SSMS"
PROD_REPO="Felege-Kidusan/felegekidusan"
SYNC_BRANCH="sync/from-development"
API="https://api.github.com"
PUSH_URL="https://github.com/${PROD_REPO}.git"

REF="HEAD"
MERGE=0
DRY_RUN=0
for arg in "$@"; do
  case "$arg" in
    --merge) MERGE=1 ;;
    --dry-run) DRY_RUN=1 ;;
    -h|--help) sed -n '2,30p' "$0"; exit 0 ;;
    *) REF="$arg" ;;
  esac
done

die() { echo "ERROR: $*" >&2; exit 1; }
say() { printf '\n== %s\n' "$*"; }

[ -n "${FKSS_GH_PAT:-}" ] || die "FKSS_GH_PAT is not set (repo + workflow scopes)."

SHA="$(git rev-parse --verify --quiet "${REF}^{commit}" \
  || die "cannot resolve '${REF}' to a commit in this checkout")"
SHA="${SHA%% *}"
SUBJECT="$(git log -1 --format='%s' "$SHA")"
echo "Promoting : ${SHA:0:10}  (${SUBJECT})"
echo "From      : ${DEV_REPO} (development)"
echo "To        : ${PROD_REPO} (production) via PR ${SYNC_BRANCH} -> main"

if [ "$DRY_RUN" -eq 1 ]; then
  echo "DRY RUN: would push ${SHA:0:10} to ${PROD_REPO}:${SYNC_BRANCH} and open a PR."; exit 0
fi

# ── 1. push the commit to the production sync branch ────────────────────────
say "Pushing ${SHA:0:10} to ${PROD_REPO}:${SYNC_BRANCH}"
helper='!f() { printf "username=x-access-token\npassword=%s\n" "$FKSS_GH_PAT"; }; f'
git -c credential.helper="$helper" push "$PUSH_URL" \
  "+${SHA}:refs/heads/${SYNC_BRANCH}" \
  || die "push failed (check the token's repo/workflow scopes and org access)"

# ── 2. open (or reuse) the pull request ─────────────────────────────────────
say "Opening the pull request"
PR_JSON="$(curl -s -X POST "${API}/repos/${PROD_REPO}/pulls" \
  -H "Authorization: token ${FKSS_GH_PAT}" \
  -H "Accept: application/vnd.github+json" \
  -d "{\"title\":\"Sync from development: ${SUBJECT}\",
       \"head\":\"${SYNC_BRANCH}\",\"base\":\"main\",
       \"body\":\"Promoted from ${DEV_REPO} commit ${SHA:0:10}. CI must be green before merge (see docs/PRODUCTION_WORKFLOW.md).\"}")"
PR_URL="$(printf '%s' "$PR_JSON" | python3 -c 'import json,sys; print(json.load(sys.stdin).get("html_url",""))' 2>/dev/null || true)"
if [ -z "$PR_URL" ]; then
  # A PR for this branch may already be open — reuse it.
  PR_URL="$(curl -s "${API}/repos/${PROD_REPO}/pulls?head=${PROD_REPO%%/*}:${SYNC_BRANCH}&state=open" \
    -H "Authorization: token ${FKSS_GH_PAT}" \
    | python3 -c 'import json,sys; d=json.load(sys.stdin); print(d[0]["html_url"] if d else "")' 2>/dev/null || true)"
  [ -n "$PR_URL" ] || die "could not open or find the PR; response: ${PR_JSON:0:300}"
  echo "Reusing existing PR: ${PR_URL}"
else
  echo "PR opened: ${PR_URL}"
fi
PR_NUM="${PR_URL##*/}"
[ "$MERGE" -eq 1 ] || { echo "Done. Review, wait for CI, then merge: ${PR_URL}"; exit 0; }

# ── 3. wait for every check on the PR head to complete ──────────────────────
say "Waiting for CI on the PR (timeout 30 min)"
DEADLINE=$(( $(date +%s) + 1800 ))
while :; do
  CHECKS="$(curl -s "${API}/repos/${PROD_REPO}/commits/${SHA}/check-runs?per_page=100" \
    -H "Authorization: token ${FKSS_GH_PAT}" \
    -H "Accept: application/vnd.github+json")"
  READOUT="$(printf '%s' "$CHECKS" | python3 -c '
import json,sys
try: d=json.load(sys.stdin)
except Exception: print("PARSE_ERROR"); raise SystemExit
runs=d.get("total_count",0)
if runs==0: print("NO_CHECKS_YET"); raise SystemExit
done=sum(1 for r in d["check_runs"] if r["status"]=="completed")
ok=sum(1 for r in d["check_runs"] if r["status"]=="completed" and r["conclusion"]=="success")
bad=[r["name"] for r in d["check_runs"] if r["status"]=="completed" and r["conclusion"] not in ("success","skipped","neutral")]
if done<runs: print(f"RUNNING {done}/{runs} done"); raise SystemExit
print("ALL_GREEN" if not bad else "FAILED "+",".join(bad))' 2>/dev/null || echo PARSE_ERROR)"
  echo "  $(date +%H:%M:%S)  ${READOUT}"
  case "$READOUT" in
    ALL_GREEN)  break ;;
    FAILED*)    die "CI failed on the PR: ${READOUT#FAILED } — fix on the development side and re-run this script" ;;
    NO_CHECKS_YET|RUNNING*|PARSE_ERROR) : ;;
    *) die "unexpected CI state: ${READOUT}" ;;
  esac
  [ "$(date +%s)" -lt "$DEADLINE" ] || die "timed out waiting for CI"
  sleep 30
done

# ── 4. merge and clean up ───────────────────────────────────────────────────
say "Merging PR #${PR_NUM}"
MERGE_RESP="$(curl -s -X PUT "${API}/repos/${PROD_REPO}/pulls/${PR_NUM}/merge" \
  -H "Authorization: token ${FKSS_GH_PAT}" \
  -H "Accept: application/vnd.github+json" \
  -d "{\"merge_method\":\"merge\"}")"
printf '%s' "$MERGE_RESP" | grep -q '"merged": *true' \
  || die "merge failed: ${MERGE_RESP:0:300}"
echo "Merged."

curl -s -X DELETE "${API}/repos/${PROD_REPO}/git/refs/heads/${SYNC_BRANCH}" \
  -H "Authorization: token ${FKSS_GH_PAT}" -o /dev/null || true
echo "Sync branch deleted."

MAIN_SHA="$(curl -s "${API}/repos/${PROD_REPO}/branches/main" \
  -H "Authorization: token ${FKSS_GH_PAT}" \
  | python3 -c 'import json,sys; print(json.load(sys.stdin)["commit"]["sha"])' 2>/dev/null || echo '?')"
say "DONE — production main is now at ${MAIN_SHA:0:10} (promoted commit: ${SHA:0:10})"
