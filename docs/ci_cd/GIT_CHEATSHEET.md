# Git, day to day

For someone who has used `git pull` and `git push` without knowing what
they do. Read once, then keep it open for a week.

---

## The mental model

Git stores **save points** (commits). A **branch** is a line of save
points. `main` is the official line — the version that ships.

The whole discipline is one sentence:

> Do your work on a branch. Merge into `main` only when the tests pass.

---

## Starting a change

```bash
git checkout main          # go to the official line
git pull                   # get everyone else's latest work
git checkout -b fix/search-crash
```

`checkout -b` makes a new branch and switches to it. Name it after the
work: `fix/…`, `feature/…`, `docs/…`.

**Always branch from an up-to-date `main`.** Skipping `git pull` is the
usual cause of painful merges later.

---

## While working

```bash
git status                 # what changed — use this constantly
git diff                   # show the actual edits
git add -A                 # stage everything
git commit -m "fix: reject unranked server rows"
```

Commit often. Small commits are easy to undo; one giant commit is not.

---

## Publishing

```bash
git push -u origin fix/search-crash
```

`-u origin <branch>` is only needed the first time; afterwards just
`git push`.

Then open a **Pull Request** on GitHub. CI runs automatically. Green
means safe to merge; red means read the log and push a fix to the same
branch — the PR updates itself.

---

## After it is merged

```bash
git checkout main
git pull
git branch -d fix/search-crash   # delete the finished branch
```

---

## The commands you will actually need

| Command | What it does |
|---|---|
| `git status` | What has changed. Run it whenever unsure. |
| `git log --oneline -10` | The last ten save points. |
| `git diff` | Unstaged edits. |
| `git diff --staged` | What is about to be committed. |
| `git checkout -b <name>` | New branch. |
| `git switch main` | Move back to `main`. |
| `git pull` | Download the latest. |
| `git push` | Upload your commits. |

---

## Getting out of trouble

**Undo edits to a file (not yet committed)**
```bash
git restore path/to/file
```

**Unstage something added by mistake**
```bash
git restore --staged path/to/file
```

**Fix the last commit message**
```bash
git commit --amend -m "better message"
```
Only if you have not pushed it yet.

**Committed to `main` by accident**
```bash
git branch my-work        # save the work on a branch
git reset --hard origin/main   # put main back
git checkout my-work      # carry on there
```

> ⚠️ `git reset --hard` **deletes uncommitted changes permanently**. Run
> `git status` first and be sure.

**Save half-finished work to deal with something urgent**
```bash
git stash          # put changes aside
git stash pop      # bring them back
```

---

## Rules for this repo

1. **Never commit to `main` directly.** Branch protection will refuse it.
2. **Never commit secrets** — passwords, API keys, `.env`, `*_env.php`,
   keystores. Git history is permanent; the CI hygiene job blocks these.
3. **Never commit APKs or files over 20 MB.** They bloat every clone
   forever.
4. **Never force-push a shared branch** (`git push --force`). It deletes
   other people's work.
5. **Pull before you branch.**

---

## If a token or password is ever committed

Rotate it immediately — assume it is compromised. Removing it from
history does **not** help: it already exists in every clone, fork and
cache. Rotation is the only real fix.
