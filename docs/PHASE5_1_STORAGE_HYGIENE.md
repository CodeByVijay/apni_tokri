# Phase 5.1 — Storage hygiene

**Date:** 2026-11-03  
**Policy:** Recommendations only — **no `.gitignore` edits applied** in this phase.

**Final status:** **ORGANIZATION PLAN READY**

---

## 1. `storage/` inventory

| Path | Size (approx.) | Class | Regeneratable? | Keep for compliance / cleanup? |
|------|----------------|-------|--------------|------------------------------|
| `storage/audit-demo-test-data.json` | ~5 KB | Audit snapshot | **Yes** — `php scripts/audit-demo-test-data-readonly.php` | **Yes** — Phase 2.3 / 3 evidence |
| `storage/phase3-cleanup-manifest.json` | ~69 KB | Cleanup manifest | **Yes** — `php scripts/preview-phase3-cleanup.php` | **Yes** — Tier A/B approval |
| `storage/phase3-1-safety-review.json` | ~large | Safety review | **Yes** — `php scripts/phase3-1-tier-a-safety-review.php` | **Yes** — pre-execution sign-off |
| `storage/invoices/*.pdf` | varies | Runtime / dev artifact | N/A (generated per invoice) | **No** in git — already ignored |
| `storage/invoices/.htaccess` | — | Deployment security | No | **Yes** — required |
| `storage/invoices/index.html` | — | Directory guard | No | **Yes** |
| `storage/invoices/.gitignore` | — | VCS rule (`*.pdf`) | — | **Yes** |
| `storage/logs/*` | — | Runtime | Yes | Ignored (`.gitkeep` only) |
| `storage/temporary/*` | — | Runtime | Yes | Ignored |
| `storage/secrets/` (if present) | — | **Secrets** | — | **Never commit**; not in repo by default |

**Not present in repo but referenced by config:** `storage/secrets/master.key` (dev default for invoices).

---

## 2. Classification

| Category | Files |
|----------|--------|
| **Regeneratable reports** | All three `*.json` audit/manifest files |
| **Historical audit evidence** | Same JSON + root `DEMO_TEST_DATA_AUDIT.md` (outside `storage/`) |
| **Runtime application data** | Invoice PDFs, logs, temporary files |
| **Required deployment artifacts** | `storage/invoices/.htaccess`, `index.html`, nested `.gitignore` |

---

## 3. Git policy recommendation

### 3.1 Do **not** ignore (preserve cleanup / deployment approval)

- `storage/phase3-cleanup-manifest.json`
- `storage/phase3-1-safety-review.json`
- `storage/audit-demo-test-data.json` *(optional team choice — see below)*
- `storage/invoices/.htaccess`, `index.html`, `.gitignore`

**Rationale:** Phase 3 / 3.1 explicitly reference these paths for human approval; ignoring them hides evidence in PRs.

### 3.2 Already correct

`storage/invoices/.gitignore` contains:

```
*.pdf
```

Committed PDF under `storage/invoices/` (if any) should be **removed from git history** in a separate hygiene commit — do not delete local files needed for dev invoice tests without backup.

### 3.3 Optional regeneratable snapshots (team choice)

If the team prefers **not** to commit JSON snapshots, regenerate before cleanup reviews and attach to tickets instead. **Do not** ignore manifests **without** updating Phase 3 docs to point to CI artifacts.

---

## 4. Exact proposed `.gitignore` changes

**Apply only after team confirms.** No changes made in Phase 5.1.

### Option A — Minimal (recommended)

Add at repo root `.gitignore`:

```gitignore
# Dev invoice PDFs (storage/invoices/.gitignore already covers this directory)
/storage/secrets/
!/storage/secrets/.gitkeep

# Optional: local audit snapshot exports (NOT phase3 manifest — see Option B)
# /storage/audit-demo-test-data.json
```

Create `storage/secrets/.gitkeep` when introducing Option A.

### Option B — Stricter local-only snapshots

```gitignore
/storage/secrets/
!/storage/secrets/.gitkeep

# Regeneratable; keep phase3 manifests tracked unless using CI artifacts
/storage/audit-demo-test-data.json

# DO NOT add:
# storage/phase3-cleanup-manifest.json
# storage/phase3-1-safety-review.json
```

### Option C — No change

Current `.gitignore` is acceptable if committed JSON snapshots are intentional audit evidence.

---

## 5. Regeneration commands

```bash
php scripts/audit-demo-test-data-readonly.php > storage/audit-demo-test-data.json
php scripts/preview-phase3-cleanup.php
php scripts/phase3-1-tier-a-safety-review.php > storage/phase3-1-safety-review.json
```

---

## 6. Risks

| Risk | Mitigation |
|------|------------|
| Ignoring phase3 JSON | Breaks documented approval workflow — use Option B partial only |
| Committing invoice PDFs | Rely on `storage/invoices/.gitignore`; audit `git ls-files storage/invoices/` |
| Stale JSON vs live DB | Regenerate before Tier A execution |
| Secrets in `storage/secrets/` | Root gitignore + permissions 0600 |

---

## 7. Related deliverable

Test layout and migration: [PHASE5_1_TEST_ORGANIZATION.md](./PHASE5_1_TEST_ORGANIZATION.md)
