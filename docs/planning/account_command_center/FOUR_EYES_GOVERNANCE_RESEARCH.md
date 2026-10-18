# Four-Eyes for the Account Command Center — How It Works, Who Must Be Involved, and How Not to Overwhelm HR / School Admin

**Type:** research + recommendation (feeds D1 — Requirements & Governance). **Date:** 2026-10-18.
**Your question:** "IT handles super_admin today; why not let IT handle the new role too? Why do HR and the school admin need to be part of it, and how do we keep them out of technical decisions?"
Labels: **FACT** (cited / repo) · **INFERENCE** · **UNKNOWN** (your decision).

---

## 0. Short answer

- **You are mostly right.** The IT team should *operate* the command center (the `accounts_office` role) and should also be the *technical* second pair of eyes (second IT person or super_admin). HR and the school admin should **never** be asked a technical question.
- **But two things cannot be left to IT alone**, and the reason is not technical:
  1. **"Is this person really the guardian of this child / a member of this family?"** — that is a *fact about people*, owned by HR, and HR already answers it today in Family Link. Nothing new is added; the command center simply reuses HR's existing decision instead of letting IT re-decide it.
  2. **Independent oversight of the people who hold the keys.** Someone outside IT must look, once a month, at a one-page summary of what the account administrators did. That is the school admin's only recurring duty, and it is the standard compensating control for small teams where IT must both configure and approve (ISO 27001 5.3 / 5.18, PCAOB small-company guidance).
- **Four-eyes is risk-based, not everywhere.** It applies to a handful of *grant/unlock* actions that are hard to reverse. All *protective* actions (revoke a stolen phone, lock an account, log a device out) are single-operator and immediate — never gated.
- Expected burden (INFERENCE, for FKSS size): HR — zero new work (same Family Link queue); school admin — a few yes/no confirmations per month plus one 10-minute monthly sign-off and one 15-minute quarterly review.

---

## 1. What four-eyes actually is (FACT)

- One person prepares/initiates an action, a *different* person approves it; the system enforces the difference rather than assuming it; the audit evidence shows both the maker's action and the checker's decision [1](https://blog.xtrm.com/posts/maker-checker-process) [3](https://chequedb.com/resources/blog/four-eyes-principle-foundations).
- Three conditions make it real: the two actors are provably distinct, the approval is a deliberate act by a named person, and the reason is captured — otherwise it is a rubber stamp [4](https://www.makerchecker.ai/insights/the-four-eyes-principle-for-ai-workflows/).
- It is **not** "always better". It costs time and needs an available second reviewer; the rule is *match the control to the risk*: use it for changes that are hard to reverse, easy to abuse or wide in blast radius; leave routine low-risk actions single-operator [2](https://pmap.io/four-eyes-principle-explained/) [6](https://www.opcito.com/blogs/maker-checker-implementation-guide-for-secure-fintech-systems).
- Over-use is itself a failure mode: "dual control everywhere creates friction, then bypass roles"; enforce it server-side for every path (UI, API, import), never UI-only; break-glass must be time-bound and reviewable [9](https://sgsystemsglobal.com/glossary/dual-control-manufacturing-operations/).
- Known breakdowns: holidays/illness without a named fallback; small teams with no clean separation — the honest answer is to *bound* the principle (thresholds) rather than pretend [11](https://commitly.com/en/blog/four-eyes-principle/); bottlenecks when no escalation exists [13](https://sutradhar.co.in/blog/maker-checker-explained.html).

---

## 2. Two different "second pairs of eyes" — don't confuse them

| Kind | Question being answered | Who can answer it | Examples in FKSS |
|---|---|---|---|
| **Technical dual control** | "Is this administrative change correct and intended?" | Another IT person / super_admin | Grant someone the `accounts_office` role; change lockout or token policy; bulk-suspend; export audit data; enable "view as" |
| **Business authorization** | "Should this person have access to this child's/family's information at all?" | The *owner* of that information — not IT | Link a new guardian to a child; approve a second guardian; move a child to a different parent account; remove a guardian's access for legal/custody reasons |

Standards are explicit that the second kind belongs to the data owner: *"The approver should be the owner of the system or the data, not IT. IT knows how to grant access; only the business owner knows whether somebody should have it. When IT both authorises and configures, you have lost the check entirely"* [1](https://iseoblue.com/iso-27001/annex-a/control-5-18/). SOX ITGC tests the same thing: provisioning is approved by an authorised manager or data owner before access is granted, and the reviewer must be independent of the requester/provisioner [4](https://cybersigmacs.com/knowledge-center/sox-itgc/) [6](https://www.zluri.com/blog/sox-itgc). The split is simple: *business owners decide whether access is justified; IT owns the control framework that makes those decisions safe — workflows, logging, review, revocation* [16](https://nhimg.org/faq/who-should-own-day-to-day-authorization-decisions-when-access-needs-change-frequ/).

**Mapping to FKSS (INFERENCE):**
- Owner of "who is a member / who is a guardian of which child" = **HR** (FACT: HR already owns registration, promotion and Family Link per the project rules).
- Owner of "may this person be refused or stripped of access to a child" (custody disputes, complaints, legal restrictions) = **school administration** — the PowerSchool rule from the earlier research ("verify no legal restriction before issuing access") is a school-leadership responsibility, not an IT one.
- Owner of the technical control framework = **IT** (super_admin + `accounts_office`).

---

## 3. Why not IT-only? (the honest risk list)

1. **Self-approval.** If IT both issues and approves access, the control does not exist; this is the single most common audit finding on access rights [1](https://iseoblue.com/iso-27001/annex-a/control-5-18/) [5](https://www.tcsa.in/frameworks/iso-27001/controls/5-18-access-rights).
2. **Insider risk that is invisible from inside IT.** An administrator can link *any* phone to *any* child's data and, if they also own the logs, erase the trace. "Administrator plus log reviewer" is a named conflict; someone else should review admin activity, even if only monthly [12](https://www.tommasomariaricci.com/blog/segregation-of-duties-matrix-guide).
3. **IT cannot know the facts.** Whether Ato X is really the father of a grade-3 student, or whether a court order exists, is not visible in a database — HR and the school office know.
4. **It protects IT.** A recorded business confirmation means that when a wrong link or a disputed removal is challenged, IT executed a documented decision rather than made one.
5. **Family data is sensitive personal data about children.** The reputational cost of one wrong parent link is far higher than the cost of one yes/no click.

Small-team reality is recognised by every framework: where separation is impossible, document it and add **compensating controls** — enhanced logging of privileged actions stored where the privileged user cannot alter them, mandatory management review on a fixed schedule, dual authorisation only for high-risk changes [7](https://www.upguard.com/compliance/iso-27001/5-3) [3](https://iseoblue.com/iso-27001/annex-a/control-5-3/). A compensating review works only when it is performed by someone outside the conflict, is evidence-based (a system report, not a summary written by the person being reviewed) and has a fixed rhythm [12](https://www.tommasomariaricci.com/blog/segregation-of-duties-matrix-guide). That is exactly the school-admin monthly sign-off proposed below.

---

## 4. Proposed model: three tiers, designed around *not* bothering people

### Tier 0 — single operator, immediate, fully logged (≈ 95 % of daily work)
Performed by `accounts_office` (IT) alone. Rule: **anything that protects or reduces access never needs approval.**
- Look up accounts, devices, timelines
- Activate an account **after** the in-person proofing record is completed — *provided the guardian/member link already exists in HR's Family Link / member registry*
- Reset credentials in person (new one-time code, forced new secret, all sessions revoked, parent notified)
- Revoke a device (stolen/lost/broken), log out all sessions, temporarily lock an account
- Help a parent rename a device, change language, re-consent
- Open a support case, add notes

### Tier 1 — technical dual control (IT ↔ IT), rare
Maker = any `accounts_office`; checker = **a different** IT person holding super_admin (or a second designated `accounts_office`). Server-enforced "not the same user". Reason mandatory.
- Grant/revoke `accounts_office` or super_admin
- Change security policy (lockout thresholds, token lifetimes, code expiry, retention)
- Bulk actions (suspend/close > N accounts, mass device revocation)
- Export of audit data / personal data outside the system
- Start a "view as parent/member" support session (checker approval **or** post-hoc review within 24 h — UNKNOWN which you prefer)
- Permanently close an account that was never requested by the user

### Tier 2 — business confirmation of a *fact*, not a technical decision (rare, non-technical)
These appear as plain-language cards in an **Approvals inbox** on the HR or school-admin dashboard. No settings, no technical vocabulary; one question, Yes/No, optional reason.

| Situation | Who confirms | What the card says (example) | Expected volume (INFERENCE) |
|---|---|---|---|
| New guardian ↔ child link that is **not** already in Family Link; second guardian; moving a child to another parent account | **HR** — this is the *existing* Family Link review queue, not a new duty | "Ato Tesfaye Alemu (ID FK-M-…) asks to be linked as *father* of student Eden Tesfaye (Grade 3). HR record shows: no guardian on file. Confirm link?" | A few per week during registration season, near zero otherwise |
| Removing or refusing a guardian's access to a child for legal/custody/complaint reasons; reinstating after such a removal | **School admin** | "Request to remove W/ro X's access to student Y. Reason given by office: court order presented on 12 Oct. Approve removal?" | A handful per year |
| Monthly oversight | **School admin** | One-page digest: activations, resets, device revocations, locks, view-as sessions, Tier-1 approvals, anomalies (failed-login bursts, after-hours admin actions) — with a single "Reviewed" button + optional comment | 1 × month, ~10 minutes |
| Quarterly access review | **School admin** (super_admin prepares) | "These 3 people hold account-administration rights. Still correct?" per name Yes/No | 1 × quarter, ~15 minutes |

Note what is **not** on HR's or the school admin's plate: nothing about devices, tokens, codes, policies, logs or security settings. Their questions are always "is this person who the office says they are / may they see this child". Those are decisions they already make on paper today.

### Guard-rails that make the tiers work (from the sources)
- **Named fallback approver** for every Tier-1/2 step (e.g., school admin → deputy; HR head → HR officer), and **escalation after 48 h** — never automatic approval on timeout [3](https://timelinedigi.com/blog/approval-workflow-design-guide) [1](https://www.threesixtyvue.com/blog/how-to-build-an-approval-workflow-people-will-actually-use/).
- **Delegation with dates and scope**, recorded "on behalf of" in the audit trail [3](https://timelinedigi.com/blog/approval-workflow-design-guide).
- **Break-glass:** if a Tier-1/2 approver is unavailable and the matter is urgent, super_admin may proceed with a mandatory reason; the action is flagged and **must** be reviewed by the school admin within 7 days. Time-bound, reviewable, rare [9](https://sgsystemsglobal.com/glossary/dual-control-manufacturing-operations/).
- **Server-side enforcement**: maker ≠ checker checked in the backend for every path (UI, API); approvals bound to one specific request and single-use; the approver sees the real data, not the maker's summary [15](https://firelinkx.com/insights/maker-checker-controls-for-sensitive-workflows) [7](https://dev.to/brennhill/maker-checker-four-eyes-for-ai-agents-40an).
- **No approval via e-mail link**; approve inside the signed-in dashboard, channel recorded [3](https://timelinedigi.com/blog/approval-workflow-design-guide).
- **Audit record per approval**: who, on behalf of whom, what, when, request id/version, before/after, reason (mandatory on rejection), rule version applied [3](https://timelinedigi.com/blog/approval-workflow-design-guide).

---

## 5. UX principles so non-technical approvers are never overwhelmed (INFERENCE, grounded in §4 sources)

1. **One inbox, one card, one question.** Request, who asked, why, what the office already verified, Yes / No, optional reason. Nothing else [1](https://www.threesixtyvue.com/blog/how-to-build-an-approval-workflow-people-will-actually-use/) [5](https://ones.com/blog/ones-solutions/approval-notifications-explained-workflow-benefits-best-practices/).
2. **Pre-filled context from SSMS** (member record, child record, existing links, proofing record) — the approver never searches for anything.
3. **Plain language, English console, Amharic names as stored** — no words like token, session, device id, policy.
4. **Badge count + in-dashboard notification only**; weekly digest for low-priority items, individual alerts only for Tier-2 removals.
5. **Protective actions never wait for them.** A stolen phone is revoked by IT on the spot; the approver only sees it in the monthly digest.
6. **Defaults that reduce asks:** activations whose links already exist in Family Link raise no card at all — the earlier HR decision *is* the business authorization, carried forward and referenced in the audit record.
7. **The monthly page is a report, not a task list**: counts, trends, exceptions highlighted, one "Reviewed" button. The value is that someone outside IT demonstrably looked [3](https://iseoblue.com/iso-27001/annex-a/control-5-3/).

---

## 6. Decisions for you (UNKNOWN)
1. Agree that **IT operates** `accounts_office` and provides the **technical checker** (second IT person / super_admin)? → then the role is staffed as you proposed.
2. Agree that **HR's existing Family Link queue** is the only HR involvement (no new HR duty)?
3. Agree that the **school admin's** involvement is limited to: legal/custody removals (rare), the monthly one-page review, the quarterly "who holds admin rights" review? Who is the named fallback?
4. "View as" — pre-approval by a second IT person, or post-hoc review within 24 h?
5. Break-glass window for school-admin review: 7 days acceptable?

With these answered, D1 (Requirements & Governance) can be written with a final roles/SoD matrix, state machines and policy parameters.

---

## Sources
- Four-eyes / maker-checker: https://blog.xtrm.com/posts/maker-checker-process · https://pmap.io/four-eyes-principle-explained/ · https://chequedb.com/resources/blog/four-eyes-principle-foundations · https://www.makerchecker.ai/insights/the-four-eyes-principle-for-ai-workflows/ · https://www.opcito.com/blogs/maker-checker-implementation-guide-for-secure-fintech-systems · https://sgsystemsglobal.com/glossary/dual-control-manufacturing-operations/ · https://commitly.com/en/blog/four-eyes-principle/ · https://sutradhar.co.in/blog/maker-checker-explained.html · https://firelinkx.com/insights/maker-checker-controls-for-sensitive-workflows · https://dev.to/brennhill/maker-checker-four-eyes-for-ai-agents-40an
- Data owner approves, IT configures (ISO 27001 5.18 / 5.3, SOX ITGC): https://iseoblue.com/iso-27001/annex-a/control-5-18/ · https://www.tcsa.in/frameworks/iso-27001/controls/5-18-access-rights · https://cybersigmacs.com/knowledge-center/sox-itgc/ · https://www.zluri.com/blog/sox-itgc · https://nhimg.org/faq/who-should-own-day-to-day-authorization-decisions-when-access-needs-change-frequ/
- Small teams & compensating controls: https://iseoblue.com/iso-27001/annex-a/control-5-3/ · https://www.upguard.com/compliance/iso-27001/5-3 · https://www.tommasomariaricci.com/blog/segregation-of-duties-matrix-guide · https://www.sikich.com/insight/why-segregation-of-duties-is-a-key-internal-control-and-how-to-implement-it/
- Approval workflow UX: https://www.threesixtyvue.com/blog/how-to-build-an-approval-workflow-people-will-actually-use/ · https://timelinedigi.com/blog/approval-workflow-design-guide · https://ones.com/blog/ones-solutions/approval-notifications-explained-workflow-benefits-best-practices/

---

## Decisions recorded (2026-10-18, owner)

| # | Question | Decision |
|---|---|---|
| 1 | Who operates / checks `accounts_office` | **IT team operates; a different IT person or super_admin is the technical checker** (maker ≠ checker enforced server-side) |
| 2 | HR involvement | **Family Link review queue (existing) + read-only view of account status**; no account actions |
| 3 | School admin involvement | **All three duties** — legal/custody removals, monthly one-page review, quarterly admin-rights review; **fallback = super_admin via break-glass, reviewed afterwards** |
| 4 | "View as" support sessions | **Start immediately with mandatory reason; reviewed by a second person within 24 h**; user notified; read-only |
| 5 | Break-glass review deadline | **3 days** |
