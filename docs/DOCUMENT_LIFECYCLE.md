# Documentation lifecycle and retirement register

**Status:** maintained lifecycle reference. Review date: 2026-10-28. Baseline: `7209278`.

Maintained procedures describe the reviewed implementation and current owner policy. Dated reports preserve the outcome at the time they were written. Obsolete proposals/templates can leave the active tree after useful knowledge and references are accounted for; Git history remains intact.

## Retired material

| Retired path | Reason | Knowledge retained / replacement | Reference and safety review |
|---|---|---|---|
| `docs/ci_cd/ARCHITECTURE.md` | Superseded September proposal mixes conversational instructions with obsolete no-CI/test-count/version claims and unimplemented release proposals | [Development/testing](DEVELOPMENT_AND_TESTING.md), [test strategy](TEST_STRATEGY.md), [release policy](PRODUCTION_WORKFLOW.md), [mobile release operations](OPERATIONS.md#5-mobile-release), [Git reference](ci_cd/GIT_CHEATSHEET.md). Distinction between merge/release and stable signing custody retained. Proposed tag-triggered signing/config generation, percentage rollout and tag-only hosting pulls are not claimed as implemented or adopted | Complete 401-line candidate reviewed. Inbound index and release-policy references reviewed/updated. No executable include or runtime dependency found. Original narrative and old measurements remain in Git history |
| `docs/ci_cd/ci.yml.txt` | Nonexecuting workflow proposal pins obsolete Flutter 3.27/PHP 8.1 and describes gates/build/signing behavior different from actual workflows | Exact executing workflows and toolchain documented in [DEVELOPMENT_AND_TESTING.md](DEVELOPMENT_AND_TESTING.md). Secrets/artifact guards remain in actual CI; exposed credentials must still be rotated under [SECURITY.md](SECURITY.md) | Complete 183-line template reviewed. Only discovered active references were backend-workflow comments; replaced with current documentation pointers. Workflow executable YAML is unchanged |

The retired proposal's decision not to introduce a second over-the-air patch system does not authorize adding one now. Signing automation, staged rollout or hosting automation would each need a new scoped design and approval. The historical read-only SQLite-row crash motivates regression coverage, not an assertion that a passing build prevents all runtime errors.

## Retained or pending review

| Path | Disposition and reason |
|---|---|
| `docs/ci_cd/SEARCH_LOAD_AND_ERRORS.md` | **Retained and rewritten** as a source reference. Useful request/retry/cache knowledge survives; unverified company/performance claims are removed. Executable debounce is 160 ms despite a nearby 250 ms comment; no application behavior is changed |
| `docs/audits/DEPLOYMENT_RUNBOOK.md` | **Retained.** Migrations and security tests require its path and safeguards: 031 → 032 → 033 order, uniqueness preflight/DEPLOYMENT BLOCKER, validated restore script and foreign-key inventory, and the warning that SQL files are not a fresh-install sequence. A professional rewrite requires complete safeguard-by-safeguard review first |
| `Mobile/code_dump.txt` | **Pending retirement review.** Stale duplicated source with a workstation path; discovered reference is the Mobile folder README. Complete unique-knowledge review is not yet finished, so it is not deleted in this pass |
| Dated audit/incident/upload/CI reports | **Retained as evidence.** An intermediate failed run or incomplete status is not grounds for erasing history. Add a dated follow-up link when necessary; do not silently rewrite prior results |
| Legitimate optional AI feature/security/API material | **Retained.** The request is professional documentation cleanup, not removal of implemented functionality or its privacy/security obligations |

## Rules for future retirement

Follow [DOCUMENTATION_STANDARD.md](DOCUMENTATION_STANDARD.md#5-retiring-a-document). Check ordinary links, inline path references, comments, migrations, tests and dynamic dependencies. Move necessary knowledge before removing a file. Run documentation/include/boundary tests after staging new files so Git-based discovery includes them. Record incomplete reviews as pending rather than treating an inventory scan as proof of obsolescence.
