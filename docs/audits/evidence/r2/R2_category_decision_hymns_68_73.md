# R-2 — category decision brief: hymns 68 and 73

**Date:** 2026-10-02 · **Scope:** hymns 68 and 73 only · **Nothing was modified.**

Method: parsed the production export (`uploads/production_database.md`,
9,797 lines) directly as a file. **No database was created, restored or
copied; no DELETE, UPDATE or migration was written or run.** Row counts
reproduced from the file match the database-derived counts from the earlier
cycle exactly (11 hymns, 38 categories, 110 junction rows, 9 zemarian links,
0 word rows), so the file parse is a sound basis for this brief.

---

## Why these two hymns need a decision

Both are `status = active`. Each has **exactly one** category assignment, and
in both cases it points at a category id that no longer exists. They are the
only two rows in the 102-row orphan set that concern a live hymn, so they are
the only ones where cleanup would lose something a user can currently see.

**The dangling category ids cannot be retained, and their names are not
recoverable.** Categories 30 and 32 are absent from `mezmur_categories` and
there is no archive, backup or audit table holding former category names. The
surviving evidence of intended categorisation is the legacy denormalised
`mezmur_hymns.category` **text** column, which still holds the category name
as free text on the hymn row itself.

---

## Hymn 68 — የራማው ልዑል

### 1. Current metadata

| field | value |
|---|---|
| id / title | 68 · የራማው ልዑል |
| **`category` (legacy text column)** | **የገብርኤል መዝሙራት** |
| status | `active` |
| language / length | amharic / long |
| audio | none (`audio_status = none`, no `audio_key`) |
| lyrics | present |
| lyrics_synced | present, timed LRC, synced `2026-09-22 19:38:43` by user 16 |
| created / updated | `2026-09-02 08:35:24` / `2026-09-22 17:40:00`, revision 7 |
| artwork | none |

### 2. Subject evidence from lyrics

- `የራማው ልዑል ገብርኤል` — names **Gabriel** in the opening line.
- `ቅዱስ ገብርኤል ጠባቂያችን` — "Saint Gabriel our protector".
- `የምስራች ነጋሪ ድንቅ ልደት አብሣሪ` — "herald of good news, announcer of the
  wondrous birth": the Annunciation, Gabriel's defining role.
- `የአናንያ የአዛርያ የሚሳኤል ከለላቸው / ከእሳት ነበልባል ያዳንካቸው` — Ananias, Azarias and
  Misael delivered from the furnace (Daniel 3), traditionally attributed to
  Gabriel.

The subject is unambiguous and is corroborated by the legacy `category` text.

### 3–4. Candidate valid categories

| id | category | verdict |
|---|---|---|
| **85** | **የገብርኤል መዝሙራት** [under የመላዕክት ዝማሬዎች], `is_active=1` | **Appropriate.** Exact string match to the legacy `category` column. Direct precedent: hymn 78 `ገብርኤል ኃያል` carries the identical legacy string and is assigned to 85. |
| 108 | አጠቃላይ [under የመላዕክት ዝማሬዎች] | Right family (Angels) but the generic bucket; 85 is the specific, exactly-matching node. Strictly worse. |
| 75 | የገብርኤል [top level] | Same subject, but **`is_active=0`** (retired) and nothing is assigned to it. |
| 115 | አጠቃላይ [under የገብርኤል] | **`is_active=0`**, and its parent is also inactive. |
| 86 / 93 / 94 | የሚካኤል / የዑራኤል / የሩፋኤል መዝሙራት | Wrong archangel. |
| 116 | አጠቃላይ [under የጌታ ዝማሬዎች] | The hymn addresses Gabriel, not the Lord. |

### 5. Dangling id that cannot be retained
**`category_id = 32`** (absent from `mezmur_categories`; name unrecoverable).

### 6. Recommendation — **category 85 (የገብርኤል መዝሙራት)** · *not applied*
Confidence: **high.** Exact name match to the surviving legacy column, lyrics
independently confirm the subject, and an existing hymn with the identical
legacy string is already assigned there.

---

## Hymn 73 — የሚጠብቀኝ አይተኛም

### 1. Current metadata

| field | value |
|---|---|
| id / title | 73 · የሚጠብቀኝ አይተኛም |
| **`category` (legacy text column)** | **አጠቃላይ** |
| status | `active` |
| language / length | amharic / long |
| audio | **present** — `mz/audio/73/3b92…m4a`, 294 s, 910,467 bytes, m4a, `ready`, uploaded by 16 on `2026-09-04 13:50:08` |
| lyrics | present; `lyrics_synced` NULL |
| created / updated | `2026-09-04 11:49:18` / `2026-09-04 11:50:10`, revision 4 |
| artwork | none |

### 2. Subject evidence from lyrics

- `የሚጠብቀኝ አይተኛም / አያንቀላፋም` — Psalm 121:4, "he who keeps me neither
  slumbers nor sleeps".
- `ፀሐይም በቀን አይተኩሰኝም` — Psalm 121:6, "the sun shall not strike by day".
- `ጌታዬ መራኝ በጽድቅ መንገድ` — "**my Lord** led me in the path of righteousness"
  (Psalm 23). The hymn addresses the Lord directly.
- `ነፍሴን ጠበቃት ከጠላት ወጥመድ` — deliverance from the enemy's snare.
- **No reference to Mary, any angel, or any named saint.**

Theme: divine protection and trust, addressed to the Lord.

### 3–4. Candidate valid categories

The legacy column says `አጠቃላይ` ("general"), but **twelve** valid categories
carry that exact name — one per parent. So unlike hymn 68, the legacy string
alone does **not** determine the answer; it fixes the *leaf type* (the general
bucket) and leaves the *parent family* to be decided on content.

| id | general bucket under… | verdict |
|---|---|---|
| **116** | **የጌታ ዝማሬዎች** (Hymns of the Lord), `is_active=1` | **Appropriate.** Matches the lyrical subject (addressed to ጌታዬ). It is also the **only** `አጠቃላይ` bucket currently in use — 6 of the 9 surviving valid assignments point here, and all 6 are Lord/Christ-themed (75 ላመስግንህ የኔ ጌታ, 79 በንፁሕ ደሙ, 81 የማይሸረሸር ዓለቴ, 82 ድል አለ በስምህ, 80, 69). |
| 113 | የንስሓ ዝማሬዎች (Repentance) | Nearest rival. Rejected: the text is about protection and trust, with no confession or penitence. |
| 114 / 87 | የእመቤታችን ዝማሬዎች (Our Lady) | No Marian content. |
| 108 | የመላዕክት ዝማሬዎች (Angels) | No angelic content. |
| 110 / 111 | የቅዱሳት / የቅዱሳን ዝማሬዎች (Saints) | No saint named. |
| 112 | የበገና መዝሙራት (Begena) | Instrument-based grouping; audio is a plain m4a with nothing indicating begena. |
| 106 / 107 / 117 | ህጻናት / ማዕከላዊያን / ወጣቶች | Age-group divisions, not subject categories; no evidence of age targeting. |
| 109 / 115 | የሚካኤል / የገብርኤል | **`is_active=0`**, and wrong subject. |

### 5. Dangling id that cannot be retained
**`category_id = 30`** (absent from `mezmur_categories`; name unrecoverable).

### 6. Recommendation — **category 116 (አጠቃላይ, under የጌታ ዝማሬዎች)** · *not applied*
Confidence: **moderate.** The leaf name matches the legacy column exactly, the
lyrics address the Lord, and 116 is the only `አጠቃላይ` bucket in active use with
six thematically consistent siblings. But because `አጠቃላይ` is ambiguous across
twelve parents, this is a judgement from content and usage rather than a
determinate match like hymn 68. **Worth an owner's eye before it is applied.**

---

## The other 100 orphaned junction rows

### No recoverable business payload — confirmed

Both junction tables consist of **nothing but the foreign-key pair**:

```
mezmur_hymn_categories : ['hymn_id', 'category_id']   -- 2 columns
mezmur_hymn_zemarians  : ['hymn_id', 'zemarian_id']   -- 2 columns
```

The primary key *is* those two columns. There is no timestamp, author, note,
ordering, or soft-delete column. An orphan row therefore asserts exactly one
thing — "hymn X belongs to category Y" — and holds nothing else.
`mezmur_hymn_words` is empty (0 rows), as are `mezmur_play_stats` and
`mezmur_user_favorites`, so no lyric index or engagement data references them.

### Referenced parents no longer exist — confirmed

| set | count | detail |
|---|---|---|
| orphans in `mezmur_hymn_categories` | 101 | of which **2** concern live hymns 68 and 73 (handled above) |
| rows referencing a **non-existent hymn** | **99** | verified: every one of the 99 points at a hymn id absent from `mezmur_hymns` |
| orphans in `mezmur_hymn_zemarians` | **1** | `(hymn_id=76, zemarian_id=9)` — hymn 76 absent; zemarian 9 still exists |
| **the "other 100" set** | **100** | 99 + 1 |

- Absent hymn ids referenced (68 distinct): 2, 3, 6–67, 70, 71, 72, 76.
- Surviving hymn ids: 68, 69, 73, 74, 75, 77, 78, 79, 80, 81, 82.
- Cross-check: **none** of the 68 absent ids appears anywhere in
  `mezmur_hymns`. They are gone without trace — no title, lyric, audio key or
  artwork survives for any of them.
- Additionally absent category ids referenced: 23, 26, 29, 30, 31, 32, 33,
  34, 35, 76.

**Nothing was deleted.** These 100 rows remain exactly as found.

---

## What the owner decides

1. **Hymn 68 → category 85** (recommended, high confidence).
2. **Hymn 73 → category 116** (recommended, moderate confidence — please
   confirm the parent family).
3. **The other 100 rows** — carry no recoverable information and reference
   parents that no longer exist anywhere in the database. Removing them is
   what allows `fk_mhc_hymn`, `fk_mhc_category` and `fk_mhz_hymn` to be
   validated, taking the restore from 39/42 to 42/42.

No migration has been written. Nothing will be applied without your word.
