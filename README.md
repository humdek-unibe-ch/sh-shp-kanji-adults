# SelfHelp Plugin — Kanji Adults

Page structure and study content for the Kanji Adults parent–child dyad study:
a paired-associate Kanji memory task with questionnaires, ported from
Qualtrics.

## Identity

| Layer | Value |
|---|---|
| Repository | `sh-shp-kanji-adults` |
| Local folder | `kanji_adults` (any name works; globals load from the real folder) |
| `plugins.name` | `kanji-adults` |

## What this plugin does

It creates the ten pages that make up the study, wires them together with
`redirect_at_end`, and ships the study content itself.

It contains **no rendering code**. The questionnaires are rendered by the
**surveyjs** plugin's `surveyJS` style and the memory task by the **lab_js**
plugin's `labJS` style. This plugin creates the page and section rows that put
those styles in the right order with the right redirects, and loads the
surveys and the task into the tables those plugins read.

Scoring is not needed: the lab.js study records `correct` per trial at
runtime.

## Requirements

- SelfHelp v7.8.1+
- [sh-shp-survey_js](https://github.com/humdek-unibe-ch/sh-shp-survey_js)
- [sh-shp-lab_js](https://github.com/humdek-unibe-ch/sh-shp-lab_js) (cloned as `server/plugins/lab`)

Both must be installed **before** this plugin's migration runs — it
references the `surveyJS` and `labJS` styles by name.

## Pages

| Keyword | URL | Contents |
|---|---|---|
| `kanji-adults` | `/kanji-adults` | Welcome / landing text |
| `kanji-adults-survey` | `/kanji-adults-survey` | Part 1: consent, code, demographics |
| `kanji-adults-task-1` | `/kanji-adults-task-1` | Instructions, practice, learn A |
| `kanji-adults-pause-1` | `/kanji-adults-pause-1` | Vignette: Französische Vokabeln |
| `kanji-adults-task-2` | `/kanji-adults-task-2` | Recall A |
| `kanji-adults-pause-2` | `/kanji-adults-pause-2` | Vignette: Geografie Quiz |
| `kanji-adults-task-3` | `/kanji-adults-task-3` | Learn B |
| `kanji-adults-pause-3` | `/kanji-adults-pause-3` | Vignettes: Mathematik, Aufsatz |
| `kanji-adults-task-4` | `/kanji-adults-task-4` | Recall B, closing screen |
| `kanji-adults-questions` | `/kanji-adults-questions` | Device, closing code |

Flow, via each style's `redirect_at_end`:

```
/kanji-adults → /kanji-adults-survey
  → task-1 → pause-1 → task-2 → pause-2
  → task-3 → pause-3 → task-4 → /kanji-adults-questions
```

The task pages are `is_headless = 1` so the experiment gets the full viewport
with no site chrome. The welcome page is the only one added to the site header;
linking the others directly would let a participant skip into the task without
consenting or entering their code.

### Why the task is split across four pages

The Qualtrics original interleaves the vignette pauses with the memory task —
`Pause 1` after learning list A, `Pause 2` after recalling it, `Pause 3` after
learning list B. The vignettes are SurveyJS Likert matrices and the task is
lab.js, so they cannot share a page.

Splitting keeps the original's retention interval: the delay between learning a
list and recalling it is filled by a vignette, as it was in the original wave.

**It is still one study and one result row.** All four task pages bind `labjs`
rows carrying the same `labjs_generated_id`, so they write to one data table.
The loader assigns a fresh `labjs_response_id` on every page load, which would
produce four rows, so each segment opens with a `Resume` screen that pins the
id in `sessionStorage`; `save_lab()` uses it as `updateBasedOn`, so every
segment updates the same row.

If `sessionStorage` is unavailable (private mode), the handler fails quietly and
the segments save as separate rows — recoverable by joining on the participant
code.

Participants arrive from a letter without logging in, so the migration grants
read access to all groups **and** adds a direct `acl_users` row for the guest
user, for every page in the chain. The guest belongs to no group and core checks
ACL against the session user, so group grants alone are not enough — without the
user row every page renders "Kein Zugriff".
## Installation

1. Install `sh-shp-survey_js` and `sh-shp-lab_js` and run their migrations.
2. Place this plugin under `server/plugins/kanji_adults`.
3. Upload the study images (173 files) to a served folder (default
   `/assets/kanji`).
   `@base_path` and `@asset_base` at the top of the migration set the URL
   prefix; `@base_path` must match `BASE_PATH` in `globals_untracked.php`.
4. Execute `server/db/v1.0.0.sql` — **with an explicit UTF-8 charset**:

   ```
   mysql --default-character-set=utf8mb4 -u root <database> < server/db/v1.0.0.sql
   ```

   The MySQL client on Windows often defaults to `cp850`, which silently
   corrupts every umlaut and accent on import (`für` becomes `f─╝r`). The
   source files are correct UTF-8; only the import needs the flag.
5. **Clear the CMS cache** (pages/sections).

That is the whole install. The single migration creates the pages, embeds and
publishes both surveys and the lab.js study, and binds them to their sections
— no CMS import or configuration is needed. It is idempotent.

The surveys ship with `de`, `en`, `fr`, `it` already populated.

> `INSERT IGNORE` means re-running the migration will **not** overwrite rows
> that already exist. That is safe, but when iterating on content you may need
> to delete the affected row first for a change to take effect.

## Content

`server/db/v1.0.0.sql` carries the whole study: the pages, the five SurveyJS
questionnaires, the four lab.js task segments, the task stylesheet and the
instruction screens for all four languages. Most of its ~350 KB is embedded
JSON.

All five questionnaires and all four task segments share the generated id
`Kanji_Data`, so they write into one data table; the hooks below merge them
into one row per participant.

Everyday changes belong in the CMS, not in the SQL: the questionnaires are
editable under Module SurveyJS and the task under Module LabJS, and an edit
there takes effect immediately. Re-running the migration will not undo it —
surveys and studies are matched on their title or name, inserted only when
absent and otherwise updated in place, so ids stay stable and sessions already
holding them keep working. Renaming one in the CMS breaks that match, and the
next migration then seeds a second copy alongside it.

The migration was produced from a set of source files — the Qualtrics-derived
survey JSON, the trial item tables and two generator scripts — which are kept
outside this repository. Ask the study owner for them if the study ever has to
be rebuilt from scratch rather than edited in the CMS.

### Recorded data

One row per participant in `Kanji_Data`. Questionnaire answers are collapsed
into one JSON column each (`survey_teil1`, `survey_pause{1..3}`,
`survey_teil2`); the task writes `kanji_lernen_{a,b}` for the learning phase
and `kanji_{a,b}` for recall. The practice round is not stored. The columns are
described in full under **Data** below.
### Study format

The `labjs.config` the plugin stores is in lab.js **Builder** format, not a
runtime component tree. The plugin's loader calls
`makeComponentTree(exp.components, 'root')`, so the file needs a flat
`components` map keyed by id with the entry node keyed exactly `root`,
`children` as arrays of ids, `templateParameters` as a typed grid, and
`files` as `{localPath, poolPath}` pairs. A nested tree loads to a white
screen with `Cannot read properties of undefined (reading 'root')`.

### Task styling

`kanji_labjs.css` is served by the `kanji-task-style-{1..4}` sections — a `markdown`
style at position 0 on the task page, holding the file wrapped in a `<style>`
tag. The labJS section sits after it at position 10.

It has to live on the page, outside the experiment, because:

- the section's **`css` field is a class-name list**, not a stylesheet. Core
  appends `selfHelp-locale-*` and `style-section-*` and prints it inside
  `class="…"`, so a stylesheet there is parsed as garbage class tokens. The
  migration clears that field.
- **a `<style>` inside the study does not survive.** Every `lab.html.Screen`
  does `el.innerHTML = content` when it runs, so a style tag placed in one
  screen is wiped by the next.

`text_md` is a **translatable** field (`fields.display = 1`), so it is read for
the page's active language, not the language-independent row `0000000001`.
The migration writes it for every row in `languages`; writing only the
independent row renders an empty section.

Because the images are 2048x2048 squares, every rule bounds them by viewport
*height* first (`max-height` in `vh`). A width-only cap lets a square image
grow as tall as it is wide and push the recall options below the fold.
### Task structure

```
page task-1  instructions: learning (4 pages)
             practice learn (2)
             instructions: recall + confidence scale (3 pages)
             practice recall (1)
             start learn A  → learn A (30)
page pause-1 vignette: Französische Vokabeln
page task-2  start recall A → recall A (15)
page pause-2 vignette: Geografie Quiz
page task-3  start learn B  → learn B (30)
page pause-3 vignettes: Mathematik, Aufsatz
page task-4  start recall B → recall B (15)
             closing screen
```

Block order taken from the `.qsf` survey flow (German branch; the other three
are structurally identical).

93 trials over 60 kanji, split 30/30 into lists A and B, with 15 of each list
tested. Timings taken from the Qualtrics `QuestionJS`:

| Screen | Duration |
|---|---|
| Fixation cross | 500 ms, auto-advance |
| Learn stimulus (kanji + meaning) | 5000 ms, auto-advance |
| Recall trial (choice + confidence) | self-paced |

Trial order is shuffled within each block, matching Qualtrics'
`Randomization: Subset`. Practice blocks are not shuffled.

The confidence scale is localised by the `window.KANJI_LANG` global
(`de`/`en`/`fr`/`it`), which picks the matching `CJ_*` images. It falls back
to German when unset.

### Instruction screens

The 12 instruction, orientation and closing pages come from the `.qsf`, one
entry per language, and are stored as self-paced `lab.html.Screen` components
with a Weiter / Next / Suivant / Avanti button.

Text and images are chosen at run time from `window.KANJI_LANG`, the same global
the confidence scale uses, so one build serves all four languages. Images are
written as `{{IMG:filename}}` placeholders and resolved against the screen's
file pool.

Each instruction screen carries only the images its own page names — attaching
the full 153-file pool to all twelve doubled the study JSON. The confidence
instruction page is the exception: it carries all 12 localised
`Instruktion_CJ_*` variants, because the language is not known until run time.

Unlike the trial screens these scroll if the content is long. There is no timing
constraint on them, and the original was an ordinary scrollable survey page.

### `state` is not per-trial scratch space

In lab.js `this.state` proxies the experiment-wide **datastore**, and the
template context is frozen when a component prepares. lab.js prepares
components ahead of running them, so a `before:prepare` handler that writes
`this.state.left_img` is overwritten by every later trial in the loop, and each
screen renders whichever value happened to be written last.

Anything that varies per trial therefore belongs in `templateParameters`,
computed when the study is generated. Anything that varies at run time (the
confidence images, which depend on `window.KANJI_LANG`) must be applied to the
DOM in the `run` handler, not through `state`.

`this.files` is safe in both templates and handlers — it is a proxy over the
component's aggregated file pool, not shared mutable state.

### The recall trial

One screen holds both the 2AFC choice and the confidence scale, matching the
Qualtrics original where `QID42` and `QID43` sat on the same page with a page
break only after the fixation cross.

Both groups use **select-then-confirm**: the first click selects an option
(blue, `#2563eb`), a second click on the same option locks it (brown,
`#8b5e3c`) and freezes the group. The confidence row is `hidden` until the
answer locks. After the confidence lock the screen waits 300 ms, then advances
— the same delay the original used.

This is deliberate, not decorative: the original instruction tells participants
they may change their answer before confirming it. A single-click implementation
removes that option and measures a different event.

Because `responses` cannot express a two-stage click, the interaction runs in a
`run` message handler that calls `respond()` itself.

Three reaction times are recorded per trial, mirroring the original's
`Reaktionszeit_*` embedded data:

| Field | Original | Measures |
|---|---|---|
| `rt_choice` | `Reaktionszeit_Q42_ms` | screen shown → answer locked |
| `rt_confidence` | `Reaktionszeit_Q43_ms` | screen shown → confidence locked |
| `rt_conf_from_choice` | `Reaktionszeit_Q43_ab_Q42_ms` | answer locked → confidence locked |

lab.js also records its own `duration` for the screen; it is not equivalent to
any of the three and should not be used as a response time.
## Data

Everything the study collects lands in **one data table, `Kanji_Data`, as one
row per participant**.

That works because the table is named after the `*_generated_id` of whatever
writes to it, so all five questionnaires and all four task segments carry the
same id. Three hooks in `KanjiAdultsHooks` then shape what is written:

| Hook | Wraps | Does |
|---|---|---|
| `kanji-adults-merge-survey-row` | `SurveyJSModel::save_survey` | pins `response_id` (and `labjs_response_id`), collapses answers to one JSON column |
| `kanji-adults-merge-task-row` | `LabJSModel::save_lab` | pins `labjs_response_id` inside `metadata` |
| `kanji-adults-rename-columns` | `UserInput::save_data` | renames the task columns and drops internal plumbing |

The two save paths do **not** receive the same shape: surveyjs passes a flat
array, lab_js passes the raw POST body with its fields under `metadata`. A
hook reading `labjs_generated_id` at the top level silently never matches.
The survey hook also stamps `labjs_response_id` because lab_js matches rows
on that column, and `save_row()` returns `false` — after logging the
transaction — when `updateBasedOn` names a column that does not exist yet.

Both plugins locate an existing row through `save_data()`'s `updateBasedOn`, so
with the id pinned each save updates the participant's row instead of inserting
its own. lab_js matches on `labjs_response_id` and surveyjs on `response_id`,
which is why the task hook writes both.

The pinned id is derived from the session (`RJS_KANJI_<sha1 of session id>`);
participants are not logged in, so the session is what identifies a run, and it
survives the whole chain of task and pause pages.

Only this study is affected — other surveys on the installation keep surveyjs'
own per-response ids.

### Columns

**Questionnaires.** One JSON column per questionnaire, not one per question.
surveyjs writes a column per question and explodes every matrix into one column
per row, which came to ~55 columns across the five questionnaires — and SelfHelp
stores each as a `dataCells` row with a `dataCols` entry per name.

| Column | Contents |
|---|---|
| `survey_teil1` | consent, personal code, demographics |
| `survey_pause{1,2,3}` | the vignette ratings from each break |
| `survey_teil2` | device, closing code |

**Memory task.** One JSON column per block, for the same reason.

| Column | Contents |
|---|---|
| `kanji_lernen_a`, `kanji_lernen_b` | learning phase: item shown and actual on-screen duration |
| `kanji_a`, `kanji_b` | recall: choice, confidence, reaction times, accuracy |

The practice round is not stored. Per-block totals are not stored either — they
are derivable from the JSON.

Each recall trial keeps the Qualtrics field names, so the two waves still line
up — the mapping just lives inside the JSON instead of across column headers:

```json
{
  "trial": 1,
  "item": "Herbst",
  "Auswahl_Q42": "Herbst.jpg",
  "Auswahl_Q43": 3,
  "Reaktionszeit_Q42_ms": 2412,
  "Reaktionszeit_Q43_ms": 3980,
  "Reaktionszeit_Q43_ab_Q42_ms": 1568,
  "korrekt": 1,
  "korrekt_seite": "left",
  "gewaehlt": "left",
  "orig_pos": "1"
}
```

Recall A uses `Q42` / `Q43` and recall B `Q2` / `Q3` — the same numbering the
original wave used. `orig_pos` is `1` throughout in the source CSV and carries
no information; the side actually shown is `korrekt_seite`.

A learning trial is just the item and how long it was really on screen:

```json
{ "trial": 1, "item": "Herbst", "dauer_ms": 4983 }
```

The duration comes from lab.js' own `time_show` / `time_end` on the committed
row. A message handler cannot supply it: the row is assembled from `this.data`
*before* `commit` is triggered, so anything written in a `commit` or `end`
handler is discarded. Reaction times have no upper bound — a participant who
walks away mid-trial produces a genuinely large value, which is an analysis
concern rather than a bug.

`_raw_data` still carries the untouched lab.js records — every screen, including
fixation, with lab.js' own timing fields — for anything the columns do not cover.

A field reference written for researchers (`DATA_FIELDS.md`) ships with the
study sources; ask the study owner for a copy.
## Deviations from the Qualtrics original

1. **Correct-answer side is randomised per trial.** The original fixed it per
   trial and blocked it (list A: trials 1–7 left, 8–15 right), which is
   exploitable. `orig_pos` is retained so the old wave stays comparable.
   The side is drawn when the study is generated and stored per row as
   `left_img` / `right_img` / `correct_side`, so rebuilding the study
   reshuffles it. The assignment in the shipped migration is fixed; keep it
   if a constant assignment across participants is wanted.
2. **`correct` is recorded at runtime** instead of being reconstructed in
   analysis by joining against the learning list.
3. **Demographic gating.** The original showed all 26 questions to everyone
   with no display logic, so participants who said "no other children" were
   still required to answer six household-children matrices. Gating now
   follows the question wording. *Confirm with the study owner.*
4. **Demo_7–12 → one dynamic panel**, sized from Demo_6. Same data.
5. **Input sanitising → validators.** The Qualtrics keydown/paste JS became
   regex validators with localised messages. The numeric questions gained
   validators the original did not have: `Demo_25` and `Demo_26` (birth
   year) and `Demo_3_other` (household size) carried `Validation.Type:
   "None"` in the `.qsf`, so a birth year of `2` was accepted. They are now
   constrained to 1930–2012 and to 7–99 respectively.

   Validators must use survey-core's registered class names —
   `regexvalidator`, `textvalidator`, `numericvalidator`. The short forms
   (`regex`, `text`) are property names in the registration, not aliases, and
   a validator declared with one is silently dropped.

   These are `regexvalidator` rather than `min`/`max`, because SurveyJS
   passes `min`/`max` through as HTML input attributes — the browser applies
   them to the spinner arrows but not to typed input, so they never rejected
   anything. For the same reason those fields are `text` with
   `inputMode: numeric` rather than `inputType: number`: a number input
   coerces its value before the validator sees it.
6. **Two scale typos fixed** in `P3_Vignette_Deut` (EN "Verly unlikely",
   IT lowercase "improbabile"), normalised to the other three vignettes.
7. **Four language branches → one survey** with `de`/`en`/`fr`/`it` locales.

## Coverage

All 20 blocks of the original per-language flow are ported, in the original
order, including the three vignette pauses interleaved with the memory task.

`Anfang` and `Demographisch` are deliberately in the part 1 survey rather than
the lab.js study, since SurveyJS handles consent, the ID code and the 28
demographic questions better than a lab.js screen would. The order a participant
sees is unchanged.

**Awaiting the study owner's confirmation:**

1. **Demographic gating.** The original showed all 26 questions to everyone with
   no display logic; gating now follows the question wording.
2. **Interleaved vignettes.** Restoring the original's block order changes the
   retention interval relative to the previous SelfHelp build (where all four
   vignettes came after the whole task). This now matches Qualtrics, but any
   data already collected with the old order is not comparable.
## Notes on the source data

- `Gefaehrlich.jpg` was uploaded to Qualtrics twice under two ids
  (`IM_TpOca69dnI3wkNW`, `IM_wSaZtQLsw0TVITe`). Recall A trial 11 taught one
  and offered the other — the same picture, so the trial was valid. Matching
  on filename here avoids the ambiguity.
- **Choice ids are not comparable across languages** in the old exports:
  - `P1_Vignette_Franz` EN recodes the dictionary item as `7` (deleted and
    re-added), so EN ids 2–5 sit one position below their DE counterparts.
  - `Demo_4`/`17`/`24` are alphabetised per language, so position 25 is
    `Ungarisch` in DE but `Ukrainien` in FR.

  The new surveys store the German source id as the value in every language,
  so this cannot recur. Anyone merging the Qualtrics wave needs to handle it.

## Migrations

| File | Contents |
|---|---|
| `v1.0.0.sql` | Everything: plugin registration, pages, sections, ACL, survey + study content, bindings |

After running: clear the CMS cache.
