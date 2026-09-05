# P35 — the real duplication bug, autoplay, and mini-player interaction

## 1. One tap created two categories / sub-categories / singers

**My previous diagnosis (P34) was wrong.** I blamed a double tap and
serialised `saveCategory`. The user reported it still duplicating on a
single tap — correctly, because the cause was not concurrency at all.

**Actual root cause.** In `saveCategory` (and identically in
`saveZemarian`) the local row and the outbox op disagreed about the id:

```dart
final localId = _localId(category);      // negative placeholder, e.g. -42
await _db.upsertCategoryLocal({
  'id': localId,                          // local row: -42
  ...
});
await _db.enqueueHymnOp('category_save', {
  'id': category['id'] ?? 0,              // op: 0   ← the bug
  ...
});
```

At push time the handler does:

```dart
final catLocalId = _asInt(payload['id']);   // reads 0, not -42
if (catLocalId < 0) {
  // repoint hymn joins, then delete the placeholder row
}
```

With `0` that branch **never ran**, so the `-42` placeholder was never
retired. The server's real row then arrived through the normal sync and
landed *beside* it. One tap, two rows — exactly what the screenshots
show: two `testets` singers, two `subb` and two `test` sub-categories,
each pair "Active" with count 0.

The hymn save path never had this bug — it does `opPayload['id'] =
localId` (L411-412). Only categories and singers were wrong.

**Fix.**

* Both ops now enqueue `'id': localId`, so the push handler sees the
  placeholder and retires it.
* The wire payload is normalised separately: `if (catLocalId < 0)
  body['id'] = 0`, so the server still sees a clean create and never a
  negative row id. (It would have coped — it branches on `$id > 0` — but
  sending a device-local id to the server is wrong on principle.)

The P34 serialisation is kept: it is still correct for the genuine
double-tap race, and `saveZemarian` has now been given the same
treatment plus an in-flight guard on its SAVE button.

## 2. Duplicate-name prevention (requested)

The old check compared `name.toLowerCase()` against the stored name, so
`test`, `Test `, ` test` and `te  st` were four different names and all
four could be created.

New `lib/services/taxonomy_names.dart`:

* `normalize()` — lowercases, trims, and collapses every whitespace run
  (including NBSP and other Unicode spaces) to a single space.
  Punctuation and Ethiopic marks are deliberately preserved, since they
  distinguish genuinely different hymn and singer names.
* `findDuplicate()` — returns the colliding row so the error can name it
  ("A sub-category named "subb" already exists here."), rather than a
  generic refusal.

Scoping rules, mirroring the server's unique key:

| Creating | Collides with |
| --- | --- |
| main category | other **main** categories |
| sub-category | subs **under the same parent** only |
| singer | all other singers (flat list) |

Editing a row never collides with itself, so changing `test` → `Test` is
allowed.

Covered by 24 tests in `test/taxonomy_names_test.dart`.

## 3. No autoplay on open

Opening a hymn now **prepares** the audio source but does not start it —
`_ensureSession()` passes `autoPlay: false`. Opening a hymn is usually
about reading the lyrics, so sound must not start unbidden. Loading
eagerly keeps the first tap on play instant.

Swiping between hymns carries the *current* intent
(`moveTo(i, autoPlay: _c.playing)`): if audio is playing it continues
across hymns, if the user is browsing lyrics it stays silent. Tapping a
row in the in-player queue is an explicit request and still plays.

## 4. Mini player did not open when tapped

**Root cause — a regression from P34.** Moving the bar into
`MaterialApp.builder` put it *above* the Navigator, which is what makes
it survive pushed routes. But that also means its `BuildContext` has no
Navigator ancestor, so the `Navigator.of(context)` inside
`openSession()` could not resolve and the tap silently did nothing.

**Fix.** New `lib/services/app_navigator.dart` holding a
`GlobalKey<NavigatorState>`, wired to `MaterialApp.navigatorKey`;
`openSession()` pushes through it. Note the app builds **two**
`MaterialApp`s (a bootstrap-failure one and the real one) — the key is
attached only to the real one, since two apps sharing one GlobalKey
would throw.

## 5. Mini player looked greyed out under a mouse

Not a bug in the disabled logic — `InkWell` renders hover and focus
overlays that a finger never triggers. The default grey overlay over the
light parchment fill read as "disabled". The overlays are now tinted
with the bronze accent (`hoverColor`, `focusColor`, `highlightColor`,
`splashColor`), so pointer input reads as interactive. The `Material`
fill was also made fully opaque (`0xF2` → `0xFF` alpha) so nothing
behind it can win a hit-test.

---

## Verification

`dart analyze` clean. **87 tests passing** (63 previous + 24 new).

| Suite | Tests |
| --- | --- |
| `taxonomy_names_test.dart` | 24 (new) |
| `mezmur_download_policy_test.dart` | 20 |
| `mezmur_playback_policy_test.dart` | 18 |
| `mezmur_transport_gate_test.dart` | 15 |
| `taxonomy_reconcile_test.dart` | 10 |

Widget files are parse-checked only (no Flutter SDK here). Device checks:

1. Create a sub-category named `test` — **one** row appears, not two.
2. Try `Test` / ` test ` again — refused, naming the existing row.
3. Same for a main category and a singer.
4. Open a hymn — audio must **not** start; tap play to start it.
5. Tap the mini bar — the full player must open.
6. Existing duplicate rows from before this fix are still on the device;
   delete them once, they will not come back.
