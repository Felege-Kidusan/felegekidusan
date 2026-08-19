# Teacher phone app — weakness report

Date: 19 August 2026  
Based on the six screenshots you sent, plus the live code in `Mobile/wbws_flutter_app`.

The student list itself is now working (the two children appear on Attendance and on Test). The remaining problems are why a teacher still cannot finish the job with confidence.

---

## 1. What your screenshots prove

| Time | Screen | What it shows |
|------|--------|----------------|
| 11:09 | Attendance | Phone has 4G, but the app says Offline. Class dropdown is empty. Red error: “Server is taking too long.” No students. No Save. |
| 11:56 | Grades | Class `1ኛ ክፍል` is selected. Two pale bars. Looks almost blank. No subjects yet. |
| 11:58 | Test (loading) | Six grey bars. No title context except “Test”. No way to cancel. |
| 11:58 | Test (ready) | Two students. Score `8` typed. Header still says **0 graded**. **No Save / Submit anywhere.** |
| 11:59 | Attendance | Class loaded. Two students. P / A / L tapped. **Still no Save / Submit.** |
| 12:00 | Home | Cached data. 1 class, 2 students. “Pending” on the class. Today’s attendance 0 / 0 / 0 / 0 and **0%**. Labels under the zeros are almost invisible. |

So: the data can load, but the teacher cannot see how to save it, cannot trust the numbers, and is often told they are offline while 4G is on.

---

## 2. Critical — the teacher cannot finish the work

### 2.1 Save is in the code, but it is invisible

There **is** a Save button on Attendance and on the grade Test screen. It sits in the dark maroon top bar.

The theme paints every text button **maroon**. The top bar is also **maroon**. Maroon on maroon = the button is there and a teacher cannot see it.

That is why you asked “there is no save and submit buttons.” You are right from the teacher’s eyes.

### 2.2 There is no Submit at all

Sunday-school teachers expect two steps:

1. **Save** — keep what I typed, even if the network dies.
2. **Submit** — send it to Education as finished.

Today there is only one hidden Save. It writes straight into the live records. The website has a “teacher submissions / review” path. The phone **skips that path**. Education cannot approve or send back a mark list from the phone.

### 2.3 Tapping P / A / L does not save

Changing Present / Absent / Late only changes the screen. Nothing is written until Save is pressed. If the teacher leaves the tab, locks the phone, or the app is killed, the marks can vanish.

There is no “You have unsaved attendance” warning.

### 2.4 Grades work the same way

Typing `8` does not save. There is no big gold button at the bottom (where the thumb is). The only Save is the invisible one in the top corner.

If they type a number bigger than Max (10), Save quietly ignores that row and can say “No valid grades to save” — with no red mark on the bad score.

### 2.5 “0 graded” is a lie

You typed 8. The header still says **0 graded**. That counter only updates after a successful reload from the server, not when the teacher types. It tells them their work did not count.

---

## 3. Confusing or unreadable (from the photos)

### 3.1 White text on a white card (Home)

On Home, several labels use a faded white colour meant for a dark background, but they sit on a white card:

- “1 total” next to My Classes
- Student count on the class row
- “Present / Absent / Late / Total” under today’s zeros

On your photo those words are ghosts. A teacher cannot read the dashboard.

### 3.2 Amharic greeting is grey on dark red

`እንደምን ዋሉ` is grey on maroon. Hard to read.

### 3.3 “Pending” on the class card

It only means “attendance not taken today.” A teacher reads it as “something is stuck / waiting to send.” Wrong word.

### 3.4 Today’s attendance shows 0%

Nothing has been recorded, so the rate should be “Not taken” — not 0%. 0% looks like every child was absent.

### 3.5 Two Attendance screens

Tapping the class card on Home opens a **second** Attendance page on top of the Attendance tab. You have said before that two screens for the same job is confusing. This is that problem again.

### 3.6 False “Offline”

The first photo shows 4G and still “Offline mode.” The app marks itself offline whenever it paints from the phone’s memory first, even if the radio is fine. Teachers stop trusting the yellow bar.

### 3.7 Loading screens look broken

The grey bars on Grades / Test are so pale they look like a white or frozen page. No “Loading subjects…” sentence. No timeout with Retry on that skeleton.

### 3.8 Grade Test screen is incomplete

- Remark field exists in code and is never shown.
- No “ / 10” next to the score box.
- Max: 10 is a tiny grey line on the maroon bar (also hard to read).
- Keyboard has no Next / Done to jump to the next child.

### 3.9 Attendance row is incomplete

- No Excused (the server already allows it).
- No note per child.
- Only English P / A / L. No Amharic.

### 3.10 First Attendance open can be empty

11:09: class list not filled, “Select a class to begin,” server timeout. Home already knew the class later. Attendance did not reuse that knowledge, and a timeout was labelled like a dead class.

---

## 4. Slow or fragile on Ethiopian 4G (TECNO)

### 4.1 30-second wait, then one red line

If the server is slow, the phone waits 30 seconds and then “Server is taking too long.” No spinner after the first moment. No automatic second try. No “using last week’s class list” message when the class list actually is on the phone.

### 4.2 Grades needs several steps after login

Home → Grades tab → wait for class → wait for subjects → tap subject → tap Test → wait for students → type → find Save. On a slow radio each wait can be 10–30 seconds. That is why you still see long grey bars.

### 4.3 Opening Grades reloads everything

Switching to the Grades tab calls refresh every time. The teacher pays the network cost again.

### 4.4 Cache and live data fight

Home says cached. Attendance says offline. Then students appear. Then Home still says cached. The teacher cannot tell what is live.

### 4.5 Timeout vs “no class assigned”

If the class list request fails, Attendance can say “No classes assigned.” That is the wrong sentence. The teacher **is** assigned. The phone just did not finish the download.

---

## 5. Workflow gaps (how the school actually works)

1. **No draft.** Cannot leave attendance half-done and come back safely.
2. **No submit-for-review.** Phone grades go live. Website review is unused.
3. **No “mark list sent” status** for Education.
4. **Creating a new test on the phone** is allowed while online, with no check that weights stay at 100% the way the website tries to enforce.
5. **No search** on the student list. Fine for 2 children. Painful for 40–80.
6. **No gender / late-comer hint** on the row.
7. **Logout on Home can drop unsaved P/A/L** because those marks were never written locally.
8. **Profile warns about unsaved sync** only if Save was actually pressed earlier. Hidden Save means this warning almost never appears.
9. **Change password** is English-only and easy to miss.
10. **Server address is shown on Profile** (`/api/v1`). Teachers do not need that. It looks like a developer screen.

---

## 6. What is actually working (so we do not break it)

- Teacher login reaches Home, Attendance, Grades, Profile.
- The two enrolled students now appear (መስፍን ታደሰ 32779, መስፍን መስፍን 69711).
- P / A / L taps respond.
- Score box accepts a number.
- Child phones / address are not shown (PII lock is holding).
- Education on the website is still the purple page. The phone did not invent a second Education home.
- Offline database exists. It just is not hooked to the taps.

---

## 7. Recommended fix order

Do these in this order. Small, visible wins first.

### Fix now (teachers can finish Sunday)

1. **Visible Save** — gold / white button on the maroon bar, **and** a wide gold **Save attendance / Save grades** bar above the bottom menu (thumb reach).
2. **Write locally on every P / A / L tap** and on every score change, then Save / Submit only sends.
3. **Live “1 of 2 graded”** as they type.
4. **Readable Home** — stop using white-on-white. Change Pending → “Not taken today”. Change 0% → “Not taken”.
5. **Stop lying about Offline** when 4G is on. Yellow bar only when the radio is really down, or say “Saved on this phone — not sent yet.”

### Fix next (workflow)

6. **Submit** after Save: “Send to Education.” Until Submit, Education sees it as draft. Matches the website review idea without a new page.
7. One Attendance place — class card on Home should open the Attendance **tab**, not a second screen.
8. Grade row: show `/ 10`, highlight scores over max, show a short remark box.
9. Attendance: Excused + optional note.
10. Timeout: 12s first try, one automatic retry, then Retry button. Always keep the last class list on screen.

### Fix later (polish / scale)

11. Amharic labels on Attendance / Grades.
12. Search on long class lists.
13. Stronger loading sentence instead of pale bars.
14. Do not reload Grades every time the tab is tapped if data is fresh.

No new website sidebar. No Education rewrite. No database column rename. No extra SQL unless Submit-as-draft needs a small status flag — we can first reuse what the website already has.

---

## 8. What I need from you

Reply with which block to build:

- **A** — Fix now only (visible Save + auto-keep taps + honest numbers / offline).
- **B** — A plus Submit to Education.
- **C** — A + B + the “next” list.

I will not change Education’s purple page or invent a second teacher website.
