<?php
/**
 * ============================================================
 * WBWS Communication Section — ONE shared partial (P73 Phase 2)
 * ============================================================
 * The dedicated Communication section, rendered identically on every
 * dashboard and by the two thin-shell pages:
 *
 *   Desktop (≥769px): drawer beside the sidebar (over the main area).
 *   Mobile  (≤768px): fills the content area ABOVE the bottom nav —
 *                     never a full-screen takeover.
 *   Page mode:        the two standalone pages render it statically
 *                     (set $NC_COMM_PAGE = true, $NC_COMM_VIEW = 'inbox'|'messages').
 *
 * Navigation contract (identical on every dashboard):
 *   Any element with  data-comm-open="inbox|messages|compose"  opens it
 *   (sidebar buttons, bottom-nav entries, bell popover links).
 *
 * Zero business logic here: permissions are gated client-side from the
 * summary API (can_announce / can_message — the server always enforces
 * via NotificationCenterService) unless the page passes
 * $NC_COMM_CTX = ['canAnnounce' => bool, 'canMessage' => bool, 'roleLabel' => str].
 * Styling: admin/css/comm.css · Behaviour: admin/js/comm.js.
 */

if (!defined('NC_COMM_SECTION_LOADED')) {
    define('NC_COMM_SECTION_LOADED', true);

    // Guarantee the shared assets (no-op when a bell already emitted them).
    require_once __DIR__ . '/../notification_center.php';
    echo renderNotificationCenterAssets();

    $ncCommPage  = !empty($NC_COMM_PAGE);
    $ncCommView  = in_array($NC_COMM_VIEW ?? '', ['inbox', 'messages'], true) ? $NC_COMM_VIEW : 'inbox';
    $ncCanAnn    = (is_array($NC_COMM_CTX ?? null) && !empty($NC_COMM_CTX['canAnnounce'])) ? '1' : '0';
    $ncCanMsg    = (is_array($NC_COMM_CTX ?? null) && !empty($NC_COMM_CTX['canMessage'])) ? '1' : '0';
    ?>
    <div class="nc-sec<?= $ncCommPage ? ' nc-sec--page' : '' ?>"
         data-nc-section<?= $ncCommPage ? '' : ' hidden' ?>
         data-initial="<?= $ncCommView ?>"
         data-can-announce="<?= $ncCanAnn ?>" data-can-message="<?= $ncCanMsg ?>"
         role="region" aria-label="Communication" tabindex="-1">
        <header class="nc-sec-head">
            <span class="nc-title"><i class="fa-solid fa-comments" aria-hidden="true"></i> Communication</span>
            <div class="nc-sec-tabs" role="tablist">
                <button type="button" class="nc-sec-tab is-active" data-nc-view="inbox" role="tab" aria-selected="true">
                    <i class="fa-solid fa-inbox" aria-hidden="true"></i> Inbox <span class="nc-count" data-count="inbox" hidden>0</span>
                </button>
                <button type="button" class="nc-sec-tab" data-nc-view="messages" role="tab" aria-selected="false">
                    <i class="fa-solid fa-comment-dots" aria-hidden="true"></i> Messages <span class="nc-count" data-count="messages" hidden>0</span>
                </button>
            </div>
            <button type="button" class="nc-x" data-nc-close aria-label="Close">&times;</button>
        </header>

        <div class="nc-sec-body">
            <!-- ══════════ INBOX ══════════ -->
            <section class="nc-sec-view" data-nc-viewpane="inbox">
                <div class="nc-sec-bar">
                    <div class="nc-tabs" role="tablist">
                        <button type="button" class="nc-tab is-active" data-tab="alerts" role="tab" aria-selected="true">Alerts <span class="nc-count" data-count="alerts" hidden>0</span></button>
                        <button type="button" class="nc-tab" data-tab="announcements" role="tab" aria-selected="false">Announcements <span class="nc-count" data-count="announcements" hidden>0</span></button>
                        <button type="button" class="nc-tab" data-tab="tasks" role="tab" aria-selected="false">Tasks <span class="nc-count" data-count="tasks" hidden>0</span></button>
                    </div>
                    <div class="nc-sec-bar-actions">
                        <button type="button" class="nc-link nc-announce" data-nc-announce hidden><i class="fa-solid fa-bullhorn" aria-hidden="true"></i> Announce</button>
                        <button type="button" class="nc-link nc-mark-all" hidden><i class="fa-solid fa-check-double" aria-hidden="true"></i> Mark all read</button>
                    </div>
                </div>
                <div class="nc-sec-scroll">
                    <div class="nc-list" data-list="alerts" role="tabpanel"><div class="nc-skeleton"><span></span><span></span><span></span></div></div>
                    <div class="nc-list" data-list="announcements" role="tabpanel" hidden><div class="nc-skeleton"><span></span><span></span><span></span></div></div>
                    <div class="nc-list" data-list="tasks" role="tabpanel" hidden><div class="nc-skeleton"><span></span><span></span><span></span></div></div>
                </div>
            </section>

            <!-- ══════════ MESSAGES ══════════ -->
            <section class="nc-sec-view" data-nc-viewpane="messages" hidden>
                <div class="nc-im">
                    <div class="nc-im-list">
                        <div class="nc-im-listhead">
                            <h3>Conversations</h3>
                            <button type="button" class="nc-btn nc-btn-done" data-nc-newthread hidden><i class="fa-solid fa-plus" aria-hidden="true"></i> New</button>
                        </div>
                        <div class="nc-im-threads" data-nc-threads><div class="nc-skeleton"><span></span><span></span><span></span></div></div>
                    </div>
                    <div class="nc-im-conv" data-nc-conv>
                        <div class="nc-empty nc-im-empty" data-nc-convempty>
                            <i class="fa-regular fa-comment-dots"></i>Select a conversation to read it.<br>Messages stay between their participants.
                        </div>
                        <div class="nc-im-convhead" data-nc-convhead hidden>
                            <button type="button" class="nc-x" data-nc-convback aria-label="Back to conversations">&larr;</button>
                            <div style="flex:1;min-width:0">
                                <h3 data-nc-convtitle></h3>
                                <div class="nc-im-who" data-nc-convwho></div>
                            </div>
                        </div>
                        <div class="nc-im-msgs" data-nc-msgs></div>
                        <form class="nc-im-form" data-nc-form hidden>
                            <textarea data-nc-reply placeholder="Write a reply…" maxlength="5000" aria-label="Reply"></textarea>
                            <button type="submit" class="nc-im-send" data-nc-send aria-label="Send"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button>
                        </form>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <!-- ══════════ New conversation sheet ══════════ -->
    <div class="nc-sheet" data-nc-newsheet hidden role="dialog" aria-modal="true" aria-label="New conversation">
        <div class="nc-sheet-card">
            <h2><i class="fa-solid fa-comment" aria-hidden="true"></i> New conversation</h2>
            <span class="nc-lbl">To</span>
            <div class="nc-picklist" data-nc-partners><div class="nc-skeleton"><span></span><span></span><span></span></div></div>
            <label class="nc-lbl" for="ncNewSubject">Subject</label>
            <input class="nc-inp" id="ncNewSubject" maxlength="200" placeholder="What is this about?">
            <label class="nc-lbl" for="ncNewBody">Message</label>
            <textarea class="nc-inp nc-inp--area" id="ncNewBody" maxlength="5000" placeholder="Write your message…"></textarea>
            <div class="nc-err" data-nc-newerr></div>
            <div class="nc-sheet-actions">
                <button type="button" class="nc-btn nc-btn-prog" data-nc-newcancel>Cancel</button>
                <button type="button" class="nc-btn nc-btn-done" data-nc-newsend><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Send</button>
            </div>
        </div>
    </div>

    <!-- ══════════ Announcement composer sheet ══════════ -->
    <div class="nc-sheet" data-nc-composer hidden role="dialog" aria-modal="true" aria-label="New announcement">
        <div class="nc-sheet-card">
            <h2><i class="fa-solid fa-bullhorn" aria-hidden="true"></i> New announcement</h2>
            <label class="nc-lbl" for="ncCmpTitle">Title</label>
            <input class="nc-inp" id="ncCmpTitle" maxlength="200" placeholder="e.g. Schedule change this Friday">
            <label class="nc-lbl" for="ncCmpBody">Message</label>
            <textarea class="nc-inp nc-inp--area" id="ncCmpBody" maxlength="5000" placeholder="Write the announcement…"></textarea>
            <div class="nc-row2">
                <div>
                    <label class="nc-lbl" for="ncCmpPriority">Priority</label>
                    <select class="nc-inp" id="ncCmpPriority">
                        <option value="normal">Normal</option>
                        <option value="high">High — important</option>
                        <option value="urgent">Urgent — needs attention now</option>
                    </select>
                </div>
                <div>
                    <span class="nc-lbl">Audience</span>
                    <div class="nc-pick" data-nc-audience>
                        <div class="nc-pick-p is-on" data-a="roles">Whole groups</div>
                        <div class="nc-pick-p" data-a="users">Selected people</div>
                    </div>
                </div>
            </div>
            <div data-nc-roleswrap>
                <span class="nc-lbl">Groups</span>
                <div class="nc-pick" data-nc-roles></div>
            </div>
            <div data-nc-userswrap hidden>
                <span class="nc-lbl">People</span>
                <div class="nc-picklist" data-nc-targetusers><div class="nc-skeleton"><span></span><span></span><span></span></div></div>
            </div>
            <div class="nc-err" data-nc-cmperr></div>
            <div class="nc-sheet-actions">
                <button type="button" class="nc-btn nc-btn-prog" data-nc-cmpcancel>Cancel</button>
                <button type="button" class="nc-btn nc-btn-done" data-nc-cmppublish><i class="fa-solid fa-paper-plane" aria-hidden="true"></i> Publish</button>
            </div>
        </div>
    </div>

    <div class="nc-scrim" data-nc-sheet-scrim hidden></div>
    <?php
}
