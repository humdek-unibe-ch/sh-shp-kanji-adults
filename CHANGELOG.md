# v1.0.0
### New feature
 - initial release: the complete Kanji Adults study in a single migration
 - four pages (welcome, part 1 survey, lab.js task, part 2 survey) chained by `redirect_at_end`; the task page is headless
 - both SurveyJS surveys and the lab.js study embedded, published and bound to their sections — no CMS import needed
 - page access for unauthenticated participants: group grants plus a direct `acl_users` row for the guest user, which core requires because the guest belongs to no group
 - welcome page added to the site header
 - `@base_path` / `@asset_base` at the top of the migration set the study image path
 - `content/build_labjs.php` generates the lab.js study from the item tables; `content/build_migration.php` embeds everything into `v1.0.0.sql`
 - requires `sh-shp-survey_js` and `sh-shp-lab_js`

### Ported from Qualtrics
 - four duplicated language branches collapsed into one survey with `de`/`en`/`fr`/`it` locales
 - `Demo_7`–`Demo_12` (six near-identical side-by-side matrices) replaced by one dynamic panel sized from `Demo_6`
 - correct-answer side randomised per recall trial; the original blocked it 7-then-8, which is exploitable. `orig_pos` retained for cross-wave comparison
 - `correct` recorded per trial at runtime instead of reconstructed during analysis
 - Qualtrics keydown/paste sanitising replaced by regex validators with localised messages
 - demographic questions gated to follow their wording; the original showed all 26 to everyone with no display logic (**confirm with the study owner**)
 - two scale typos fixed in `P3_Vignette_Deut` (EN "Verly unlikely", IT lowercase "improbabile")

### Notes
 - the migration must be loaded with `--default-character-set=utf8mb4`; the MySQL client on Windows often defaults to `cp850` and corrupts umlauts on import
 - `kanji_labjs.study.json` is in lab.js **Builder** format (flat `components` map keyed by id, entry node keyed `root`). A runtime component tree loads to a white screen with `Cannot read properties of undefined (reading 'root')`
 - unpacking `_raw_data` into one row per trial is not implemented; it needs a real response to write against
