# Git history — sensitive data cleanup plan (Phase 1)

**Status:** Prepared for team approval — **do not run automatically.**

This plan addresses secrets and operational data that were committed historically. Untracking files in the working tree (Phase 1) does **not** remove them from past commits.

---

## Sensitive paths

| Path | Risk | Notes |
|------|------|--------|
| `includes/config.local.php` | **Critical** — database credentials, encryption paths, possibly API keys | Still tracked until `git rm --cached`; present in history |
| `storage/backups/phase3_2_source_20261103150845.sql` | **High** — full DB dump (customers, orders, settings) | Keep file on disk; store future backups outside Git |

---

## Commits touching `includes/config.local.php` (sample)

| Commit | Subject |
|--------|---------|
| `7be7c7e` | config local |
| `463096e` | VA-Apni tokri address & invoice |
| `4ff8288` | VA-Apni tokri address & invoice |
| `bec1496` | VA-Brand Name change |

Run `git log --all --oneline -- includes/config.local.php` before rewrite for the authoritative list.

`storage/backups/phase3_2_source_20261103150845.sql` appears at least in `463096e` (same era as invoice work).

---

## Credential rotation (hosting / ops)

After history rewrite **or** if the repo was ever public or shared broadly:

1. **MySQL** — rotate password for every user whose credentials appeared in `config.local.php` (local `root` / app users / production DSN).
2. **Production app DB user** — new password in server-side `config.local.php` only (not in Git).
3. **Encryption master key** — if key material or paths were exposed, plan re-encryption of settings secrets per runbook.
4. **Payment / SMTP / SMS** — rotate any secrets that lived in the leaked SQL backup or config.
5. **Admin accounts** — if backup contained production `admin_users`, force password reset + `auth_epoch` bump for affected admins.

---

## Team coordination

1. **Announce maintenance window** — all developers stop pushing to `master` during rewrite.
2. **Backup remotes** — tag current `origin/master` (e.g. `pre-sensitive-purge-YYYYMMDD`) before filter.
3. **Choose tool** — `git filter-repo` (recommended) or BFG; **avoid** casual `filter-branch` without a checklist.
4. **Re-clone policy** — everyone deletes local clones or hard-resets to new history after force-push.
5. **CI / deploy** — refresh deploy keys; verify pipelines use env/config on server, not repo copies of `config.local.php`.
6. **Legal / DPO** — if backup SQL included customer PII, record incident scope per policy.

---

## Suggested rewrite commands (approval required)

```bash
# 1. Install git-filter-repo (once per machine)
# pip install git-filter-repo   # or package manager

# 2. Clone mirror (safe lab copy)
git clone --mirror <origin-url> apni-tokri-mirror.git
cd apni-tokri-mirror.git

# 3. Purge paths from all commits
git filter-repo --path includes/config.local.php --path storage/backups/phase3_2_source_20261103150845.sql --invert-paths

# 4. Force-push (ONLY after team sign-off)
git push --force --all
git push --force --tags
```

Alternatives: replace file contents with redacted blobs only (keeps commit topology but still requires force-push).

---

## Remote force-push implications

- **All open PRs** may need rebase onto new history.
- **Forks** retain old history until deleted or rebased.
- **Blame / bisect** across the rewrite boundary breaks; keep the pre-purge tag for archaeology.
- **GitHub/GitLab secret scanning** may still flag old default-branch history until purge completes.

---

## Post-rewrite verification

1. `git log -p -- includes/config.local.php` → should return nothing.
2. `git grep -i password includes/config.local.php` → no paths in tree.
3. Confirm `.gitignore` blocks `includes/config.local.php` and `storage/backups/*`.
4. Run disposable-DB security suites (no production DSN).

---

## Secure backup storage (ongoing)

- Database dumps: encrypted object storage or host backup panel — **not** the application repository.
- Add `storage/backups/` to operational runbooks; optional server cron writing outside `htdocs`.
- Retention + access control documented with hosting provider.
