# Taxonomy sync: deleted categories / singers

## The bug

Categories, subcategories and singers deleted in the web admin stayed
visible in the mobile app forever. Re-syncing, force-quitting and
pulling to refresh all failed to clear them.

## Why it happened

The taxonomy sync was **additive only**. Every pull did:

```dart
await _db.upsertCategories(cats.data['items'] as List);
```

and `upsertCategories` was an insert-or-replace loop. It could create
rows and update rows — it had no code path that deleted one.

That is fine for a *delta* feed carrying tombstones, but
`/mezmur/categories` is not one: it returns the **complete canonical
list** every time. With a full-list endpoint the only evidence that a
row was deleted is its **absence** from the response, and an additive
upsert cannot see an absence. So the local row simply survived forever.

A second, nastier bug sat in the same function:

```dart
if (rows.isEmpty) return;
```

Delete *every* category server-side and the app would sync nothing at
all — the empty list was discarded before it could mean anything.

## The fix

`upsertCategories` / `upsertZemarians` are now **reconciling** syncs: the
server list is authoritative, and any local row absent from it is
deleted along with its hymn-join rows.

Because this deletes user-visible data, the decision lives in
`lib/services/taxonomy_reconcile.dart` as a pure function and is
unit-tested (`test/taxonomy_reconcile_test.dart`). Three guards:

| Guard | Why |
|---|---|
| **negative ids are never swept** | Offline-created rows use negative local ids. They are not "missing from the server" — they were never pushed. Sweeping them would destroy unsynced work. |
| **`protectIds` are never swept** | Ids with a queued edit in the outbox. The server has not seen them yet either. |
| **`authoritative` must be true** | Set only when the request genuinely succeeded and returned a list. A failed or offline call must never be read as "the server has no categories", or one flaky connection would wipe the local taxonomy. |

Cascades handled with the delete:

* `cached_hymn_categories` / `cached_hymn_zemarians` join rows — otherwise
  hymns stay filed under a phantom section.
* `parent_id` pointers into a deleted main category are nulled, so a sub
  never dangles off a parent that no longer exists.

## Verifying on a device

1. Delete a category in the web admin.
2. In the app pull-to-refresh the Hymn Library (or wait for a sync).
3. The category disappears from Categories, from the filter sheet, and
   from any hymn that referenced it.

To confirm the safety guards:

* Create a category **in airplane mode**, then sync. It must survive
  (it has a negative id until the push succeeds).
* Turn the network off and pull to refresh. Nothing may be deleted.

## Note for future endpoints

Any *full-list* endpoint synced into SQLite needs this same treatment.
Additive upserts silently accumulate deleted rows; if the endpoint does
not send tombstones, the client must reconcile against the whole list.
