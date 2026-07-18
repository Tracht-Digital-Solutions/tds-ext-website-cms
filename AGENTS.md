# AGENTS.md — tds-ext-website-cms

Website-CMS extension, ported from `tds-content-api`'s content-block model. Read
`tds-panel-contract` + `tds-core-panel-api` AGENTS first.

## Model

- **Build-time content**, not runtime: `cms_block` rows (one per site × section ×
  language, `value_json`) are read by the static sites at build time and merged
  over defaults; a missing row falls back. Never fetch this from the client at
  runtime (same rule as content-api).
- **1:n sites:** the `cms_site` registry scopes blocks. `cms_block.site_id` FK →
  `cms_site` (CASCADE). Unique `(site_id, section_key, lang)`.
- **Auth via the core `UserContext`** — `website:read`/`website:write` (admins
  bypass). Blocks are upserted (PUT, `ON DUPLICATE KEY`).
- Denormalised JSON on purpose (small, read once per build, shapes differ per
  section) — the API validator owns shape correctness.

## Gotchas

- Migration class names are **module-prefixed** (`WebsiteCms*`).
- Routes are closures resolving `UserContext`/`CmsRepository` from the container
  at request time (UserContext is rebound per request by the core AuthMiddleware).
- DB-backed tests skip without `TDS_TEST_DB_DSN`; the committed test covers
  routes + RBAC + payload validation without a DB.

## Checkpoint status

- **CP1:** `cms_site` + `cms_block` schema, `Domain\CmsRepository`, site + block
  CRUD (`/cms/*`) with RBAC, the sites widget + list/add-site UI.
- **CP2:** the per-site **block editor UI** (`SiteEditor` in `islands/SitesList.tsx`)
  — list a site's blocks, open one (section-key + lang → GET), edit its JSON in a
  textarea with parse + object validation, save via PUT.
- **CP3:** save-triggered **static-site rebuild**. `Service\RebuildTrigger` (plain
  ext-curl, best-effort, never throws) fires a GitHub `workflow_dispatch` after a
  block save/delete. Per-site target lives on `cms_site` (`rebuild_repo` "owner/name"
  + `rebuild_workflow`, defaulting `dev.yml`), edited via `PUT /cms/sites/{site}/
  rebuild-config`; the shared PAT comes from `WEBSITE_REBUILD_TOKEN` (one PAT
  dispatches every site repo; unset ⇒ no-op). `POST /cms/sites/{site}/rebuild` is a
  manual "Jetzt neu bauen" (503 no token / 422 no repo). Sends `ref` only — the
  dispatches endpoint 422s on inputs a workflow doesn't declare. UI: a
  Rebuild-Konfiguration block in the SiteEditor.
- **TODO (next):** per-section structured forms (over the raw JSON); DeepL block
  translation; move the rebuild token into the runtime settings store.

## After a change

Bump `version` in `package.json` + `composer.json` (lockstep), update docs,
commit together.
