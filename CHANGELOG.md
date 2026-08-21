# v1.0.0

### Fixed: "No Entries" instead of the household-children questions
 - the panel used `panelCountExpression`, which does not exist in the bundled survey-core: unknown properties are dropped silently when the JSON is parsed, so the panel kept its default count of 0 and rendered its title above an empty box
 - this version drives `panelCount` through `bindings`, which takes a question name rather than an expression, so a hidden `Demo_6_count` converts Qualtrics' answer id (`9` means "3 children") into a real count first
 - verified 1→1, 2→2, 9→3, 10→4, 11→5, 12→6 panels against the JSON the live page serves

### Fixed: validation only complained after the participant had moved on
 - the Qualtrics original blocked bad characters on keydown/paste, so a bad value never survived the field. Porting that to validators moved enforcement to the Next button, and the field sat there accepting `1` as a year with no reaction
 - `checkErrorsMode: onValueChanged` on all five questionnaires restores immediate feedback, for every validated field in the study rather than just the years
 - `maxLength: 4` on both birth-year questions

### Fixed: the child birth-year dropdown had gone stale
 - the list was copied literally from Qualtrics and ended at 2024, so a child born in 2025 or later could not be entered at all, while 2007 and 2008 were still offered for children who are now adults
 - the question scopes itself to children aged 0–17, so the range is now generated at build time from the current year back seventeen

### Changed: the site's start page is the study's welcome page
 - `home` was an empty core page sitting in the header beside `Kanji Learning Task`, which gave a participant arriving from a letter a choice that meant nothing
 - the same container section is attached to both pages, so one piece of content serves `/` and `/kanji-adults`; the latter stays reachable so a link already printed on a letter keeps working, but leaves the header
 - the welcome page itself was rewritten: it now says what the task actually is, and lifts the three conditions that decide whether a run is usable — 30 minutes, one sitting, code to hand — out of the prose into their own cards
 - `kanji-adults` had labels only in German and English, so French and Italian participants read an English header

### Changed: `Finished_Study` is an explicit 0/1
 - stamped only on completion, the column could not be told apart from one that was never asked — the ambiguity it exists to resolve
 - `0` from the first save that carries an answer, `1` when part 2 completes. Not stamped on `started`: that save is the row's INSERT, and `save_row()` writes `$col_val ? $col_val : ''`, so a falsy `0` would be flattened to an empty string

### Fixed: the fixation cross was a speck
 - the asset was an 80x80 cross centred in a 1280x720 white frame, so only 6% of the width was ink and every CSS width was effectively divided by sixteen — two rounds of enlarging the frame moved the visible cross from 4px to 9px
 - cropped square to the cross and upscaled, so the CSS width is now the cross

### Fixed: a run could be filed under the wrong code, or overwrite another one
 - `ID_2`, the code re-entered on the closing page, was allowed to set the key as well as `ID_1`; a typo there re-keyed the whole finished run onto a code nobody had been given. Only `ID_1` keys a run now, and `ID_2` is kept as an ordinary column so the two can be compared
 - the code stayed in the session after a run ended, so a second participant on the same browser wrote their consent into the first one's row and then dragged that row onto their own code. Opening part 1 now starts a new run
 - the re-key can only ever move a row that is still keyed on the session, which is what makes both of the above structurally impossible rather than merely guarded against
 - reusing a code no longer overwrites: the letter is addressed to a child, so two adults in one household can hold the same one. A code whose run is already `Finished_Study` opens a second row, and both carry the code in `ID_1`
 - resuming a run from a new session no longer leaves the pre-code row behind as an unattributable stray

### Fixed: the memory task could still open a row of its own
 - the ownership fix reached only the surveyjs path. `LabJSModel::save_lab` scopes its own lookup to the session user and drops `updateBasedOn` when it misses, so a login part-way through a run made the task insert a second row
 - ownership now sits in the `UserInput::save_data` hook, the one call both plugins pass through: the lookup is widened (`own_entries_only = false`) and the user id already on the row is kept
 - `updateBasedOn` and `own_entries_only` have to be set together — `Hooks.php` packs only the arguments the caller passed and `BaseHooks` re-expands them positionally, so setting one without the other hands the boolean to `save_data()` as its `updateBasedOn`

### New: the language and the completion state are recorded
 - `UserLanguage` (`DE`/`EN`/`FR`/`IT`) is written on every questionnaire save. Qualtrics carried the language in every column name (`Demo_2_DE`); collapsing the four branches into one set of columns had dropped it out of the data entirely
 - `Finished_Study` is stamped when part 2 completes. The row's own trigger type cannot answer that question — `UserInput::update_data()` rewrites it on every save, so a participant who stops after the first vignette also leaves the row on `finished`

### Changed: no site chrome from the first questionnaire onwards
 - the five questionnaire pages join the four task pages as `is_headless = 1`, which suppresses both header and footer, as the Qualtrics original had it: once a run starts there is nothing to navigate to, and the chrome invites participants to wander off mid-task
 - the welcome page keeps both, and with them the language picker — participants choose their language before starting
 - the floating CMS box is hidden by CSS on the study pages: `SectionPage` renders `.cms-edit` on every page and guards only its contents, leaving participants an empty bordered box that overlaps the stimuli during a timed trial

### Fixed: the task ran in German whatever the participant chose
 - the migration wrote `<script>window.KANJI_LANG = "fr"</script>` into the per-language section, and the browser refused to run it: core pins a hash for its own `BASE_PATH` snippet, and per the CSP spec a hash makes `unsafe-inline` be ignored, so every other inline script is blocked
 - the stylesheet in the same section still applied — `style-src` carries no hash — which made it look like a task bug rather than a CSP one
 - the task now reads the two-letter code off the `selfHelp-locale-*` class core stamps on every section, from inside a handler, which lab.js compiles with `new Function` and `unsafe-eval` permits
 - the welcome page was seeded only in German; it now has one row per language

### Fixed: the memory task never reached the participant's row
 - `save_lab()` receives the raw POST body, so its fields sit under `metadata`; the hook read `labjs_generated_id` at the top level, never matched, and the task saved under lab.js' own `R_LABJS_…` id
 - `updateBasedOn` then filtered on `labjs_response_id`, a column no survey had ever created, and `save_row()` returned `false` — after the transaction was already logged, so the failure was invisible
 - the survey hook now stamps `labjs_response_id` as well, so the column exists by the time the task saves

### Fixed: the learning phase was not recorded at all
 - the save point collected trials only when a Qualtrics question id was mapped, and the learning blocks had none, so each save transmitted its block label and nothing else
 - `kanji_lernen_a` / `kanji_lernen_b` now record the item shown and its actual on-screen duration, taken from lab.js' `time_show` / `time_end`; a `commit` or `end` handler cannot supply this, because the row is assembled from `this.data` before either fires
 - the practice loop shared the recall screens' title, so its items leaked into list A's column; each learning loop now has its own screen title to filter on
 - Qualtrics recorded per-block timing here, so the per-trial form is a superset

### Changed: one row per participant, researcher-readable columns
 - all five questionnaires and all four task segments write into `Kanji_Data` and merge into a single row, keyed on a pinned `response_id`
 - questionnaire answers stay as one column per question, carrying the Qualtrics names minus the language suffix (`Demo_2` here, `Demo_2_DE` there), so the two waves line up column for column. An interim build collapsed them into one JSON column per questionnaire; that traded away both the readability and the comparison, and was reverted
 - task columns renamed via a `UserInput::save_data` hook — the last hookable point before the columns are created, and the only way to drop lab_js' `extra_data_` prefix without touching core
 - practice trials, per-block totals and internal plumbing columns are no longer stored
 - a field reference for the research team (`DATA_FIELDS.md`) ships with the study sources

### Changed: re-running the migration no longer breaks running sessions
 - surveys and studies were `DELETE`d and re-`INSERT`ed on every deploy, which changed their ids; pages already open in a participant's browser kept the old ids and every later save was silently rejected
 - both are now matched on title (surveys) or name (labjs), inserted only when absent and otherwise updated in place, so ids stay stable and content changes still deploy
 - renaming one in the CMS breaks the match, and the next migration seeds a second copy alongside it

### Fixed: survey validators were silently dropped
 - validators were declared as `"regex"`, which is a property name rather than a registered type; survey-core registers `regexvalidator` / `textvalidator` / `numericvalidator`, so all twelve were discarded and a birth year of `2` was accepted

### Changed: study assets
 - `Vignette_Deut.jpg` reduced from 5310×2528 (1265 KB) to 640×305 (48 KB); it is displayed at 320px, and the original is kept as `Vignette_Deut.orig.jpg`
 - the remaining stimulus set is ~26 MB and is preloaded before the task starts — worth revisiting, but re-encoding changes what participants see
### New feature
 - initial release: the complete Kanji Adults study in a single migration
 - four pages (welcome, part 1 survey, lab.js task, part 2 survey) chained by `redirect_at_end`; the task page is headless
 - both SurveyJS surveys and the lab.js study embedded, published and bound to their sections — no CMS import needed
 - page access for unauthenticated participants: group grants plus a direct `acl_users` row for the guest user, which core requires because the guest belongs to no group
 - welcome page added to the site header
 - `@base_path` / `@asset_base` at the top of the migration set the study image path
 - the migration is generated from the Qualtrics-derived sources by two build scripts kept outside this repository, if you need access to them ask the developer of the plugin.
 - requires `sh-shp-survey_js` and `sh-shp-lab_js`

### Ported from Qualtrics
 - four duplicated language branches collapsed into one survey with `de`/`en`/`fr`/`it` locales
 - `Demo_7`–`Demo_12` (six near-identical side-by-side matrices) replaced by one dynamic panel sized from `Demo_6`
 - correct-answer side randomised per recall trial; the original blocked it 7-then-8, which is exploitable. `orig_pos` retained for cross-wave comparison
 - `correct` recorded per trial at runtime instead of reconstructed during analysis
 - Qualtrics keydown/paste sanitising replaced by regex validators with localised messages
 - demographic questions gated to follow their wording; the original showed all 26 to everyone with no display logic (**confirm with the study owner**)
 - two scale typos fixed in `P3_Vignette_Deut` (EN "Verly unlikely", IT lowercase "improbabile")

### Fixed: vignette image overlapped the rating scale
 - the illustration was floated right, and a float escapes the question title, so it ran down over the matrix header
 - the title is now a flex row — text and image are siblings, so the image cannot overlap the scale, and it wraps below the text on narrow screens
 - `Vignette_Deut` (a 2.10:1 banner, unlike the three square images) is given 320px rather than 200px so it is legible

### Fixed: "thank you for completing the survey" shown mid-study
 - the three vignette pauses had no `completedHtml`, so SurveyJS fell back to its built-in completion text while the save finished and `redirect_at_end` fired — telling participants the study was over when it had not started the next block
 - each pause now says the task continues shortly; only part 2, which really is the end, thanks the participant and says they can close the window
 - all four messages localised de/en/fr/it
### Fixed: no survey validator was ever running
 - survey-core registers its validators as `regexvalidator`, `textvalidator` and `numericvalidator`; the short names that appear in the registration (`regex`, `text`) are property names, not aliases
 - every validator in this study was declared with the short type, so survey-core silently dropped all of them — including the `ID_1` and occupation-field validators that predate this work
 - all 12 now use the registered class names and are served correctly
 - this is why a birth year of `2` was still accepted after the validators were added
### Validation on the numeric demographic questions
 - `Demo_25` / `Demo_26` (birth year) now reject anything outside 1930–2012; a single digit such as `2` was previously accepted
 - `Demo_3_other` (household size) now requires 7–99, matching the branch that shows it
 - `Demo_4_other`, `Demo_17_other`, `Demo_24_other` (other language) reject digits, matching the occupation fields
 - implemented as `regex` validators: SurveyJS passes `min`/`max` to the HTML input, where the browser applies them only to the spinner arrows, so they never rejected typed input
 - those fields are now `text` + `inputMode: numeric` instead of `inputType: number`, so the validator sees the raw string; mobile still shows a numeric keypad
 - all messages localised de/en/fr/it
 - the original had `Validation.Type: "None"` on these questions, so this is stricter than the Qualtrics wave
### Fixed: a stray row per survey
 - surveyjs inserts unconditionally on `trigger_type: started` without checking for an existing row (lab_js does check), so with a pinned response id every survey's first save added an empty row beside the participant's real one
 - the hook now downgrades `started` to `updated` once the participant's row exists, so that save merges instead of inserting
### One data table, one row per participant
 - all five questionnaires and all four task segments now carry the generated id `Kanji_Data`, so everything the study collects lands in a single data table of that name
 - two hooks in `KanjiAdultsHooks` pin the response id per participant so each save updates one row instead of inserting its own: `save_survey` sets `response_id`, `save_lab` sets both `labjs_response_id` and `response_id` (the two plugins match on different columns)
 - the pinned id derives from the session, which is what identifies an unauthenticated participant across the chain of task and pause pages
 - only this study is affected; other surveys keep surveyjs' own per-response ids
 - the migration removes the previous per-instrument survey and labjs rows, so upgraded installs do not keep stale copies

### Task data written as JSON per block
 - each block's trials are stored as one JSON column (`extra_data_trials_recall_A` and friends) rather than one column per trial per measure, which would have been ~316 columns per participant
 - every trial object keeps the Qualtrics field names (`Auswahl_Q42`, `Reaktionszeit_Q43_ab_Q42_ms`, …) so the two waves still pool; the mapping lives inside the JSON instead of across column headers
 - per-block `n_trials_*` / `n_correct_*` stay as plain columns, since those are what a researcher filters on
 - each object also carries `korrekt`, `korrekt_seite`, `gewaehlt` and `orig_pos`, none of which the original could store
 - `_raw_data` is unchanged and still holds the full lab.js records
### Fixed: every recall trial showed the same two answers
 - the option images were resolved through `this.state`, which in lab.js is the experiment-wide datastore rather than per-trial scratch space. lab.js prepares components ahead of running them, so all 15 trials in a loop wrote the same two keys and every screen rendered the last pair written
 - the correct/distractor sides are now drawn per row when the study is generated and carried as `left_img` / `right_img` / `correct_side` template parameters
 - the confidence images, which depend on the run-time language, are applied to the DOM in the `run` handler instead of through `state`
 - verified in the served config: 15 trials, 15 distinct option pairs, `correct_side` consistent with `correct_img` on every row, sides balanced 15/16 across the 31 recall trials

### Full-bleed task layout
 - the task now uses the whole viewport: the labJS `.container.fullscreen` margin, border, rounded corner, width cap and `main` padding are overridden from the task stylesheet rather than by patching the lab plugin
 - stimulus caps raised accordingly (learn pair 68vh, recall cue 24vh, options 36vh)
 - the option tiles keep their border: it is the select/lock affordance and matches the original
### Vignette pauses interleaved with the memory task
 - the task is split across four pages with a vignette pause between each, restoring the original block order: task-1 → pause-1 → task-2 → pause-2 → task-3 → pause-3 → task-4
 - the delay between learning a list and recalling it is filled by a vignette again, as in the Qualtrics wave; previously all four vignettes came after the whole task
 - **still one study and one result row**: the four task pages bind `labjs` rows sharing `LABJS_KANJIADULT01`, and a `Resume` screen pins `labjs_response_id` in `sessionStorage` so every segment updates the same row via `save_lab()`'s `updateBasedOn`
 - falls back to per-page rows if `sessionStorage` is unavailable, rather than failing
 - vignettes moved out of `kanji_part2.surveyjs.json` into `kanji_pause{1..3}.surveyjs.json`; part 2 keeps the device question and closing code
 - the pre-split `kanji-adults-task` page and its sections are removed by the migration, so upgraded installs do not keep a stale page alive
 - ACL grants extended to all seven new pages
### Instruction and orientation screens
 - the 12 instruction / orientation / closing pages from the original are now in the study: 4 before practice learning, 3 before practice recall (including the confidence scale), one before each of the four learn/recall blocks, and the closing screen
 - block order follows the `.qsf` survey flow exactly
 - wording and images for all four languages are embedded in the study; the right one is chosen at run time from `window.KANJI_LANG`
 - each page is self-paced with a Weiter / Next / Suivant / Avanti button
 - the 23 previously unreferenced assets are now used: the bear kanji example, the recall worked example, the 12 localised confidence-scale images, and the three Start / closing illustrations
 - instruction screens carry only the images they name, which keeps the study JSON at ~200 KB rather than ~340 KB
 - button gets a visible focus ring, and `prefers-reduced-motion` is respected
### Recall trial rebuilt to match the original
 - the 2AFC choice and the confidence scale now share one screen, as in Qualtrics (`QID42` + `QID43` on the same page, page break only after the fixation cross)
 - **select-then-confirm**: first click selects (blue `#2563eb`), second click on the same option locks it (brown `#8b5e3c`) and freezes the group. The previous single-click implementation did not let participants revise an answer, which the original instruction explicitly offers
 - the confidence row stays hidden until the answer is locked, then a 300 ms delay before advancing — both taken from the original
 - three reaction times recorded per trial (`rt_choice`, `rt_confidence`, `rt_conf_from_choice`), mirroring `Reaktionszeit_Q42_ms`, `Reaktionszeit_Q43_ms` and `Reaktionszeit_Q43_ab_Q42_ms`. lab.js's own `duration` matches none of them and should not be used as a response time
 - interaction runs in a `run` message handler, since lab.js `responses` cannot express a two-stage click
### Task styling
 - task CSS is served by a new `kanji-task-style` markdown section at position 0 on the task page; the labJS section moves to position 10
 - it is not written to the labJS `css` field: that field is a class-name list rendered inside `class="…"`, so a stylesheet there never applied. The migration clears it
 - it also cannot live inside the study — every `lab.html.Screen` does `el.innerHTML = content` on run, so a `<style>` in a screen is wiped by the next one
 - `text_md` is written for every row in `languages`; it is a translatable field, so the language-independent row alone renders an empty section
 - stimulus images (2048x2048) are bounded by viewport height (`max-height` in `vh`) rather than width alone, so a learn pair, a recall cue with both options, and the confidence row each fit on one screen without scrolling
### Notes
 - the migration must be loaded with `--default-character-set=utf8mb4`; the MySQL client on Windows often defaults to `cp850` and corrupts umlauts on import
 - `kanji_labjs.study.json` is in lab.js **Builder** format (flat `components` map keyed by id, entry node keyed `root`). A runtime component tree loads to a white screen with `Cannot read properties of undefined (reading 'root')`
 - unpacking `_raw_data` into one row per trial is not implemented; it needs a real response to write against
