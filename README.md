# SelfHelp Plugin — Kanji Adults

Page structure and study content for the Kanji Adults parent–child dyad study:
a paired-associate Kanji memory task with questionnaires, ported from
Qualtrics.

## Identity

| Layer | Value |
|---|---|
| Folder | `sh-shp-kanji-adults` |
| `plugins.name` | `kanji-adults` |

## What this plugin does

It creates the four pages that make up the study, wires them together, and
ships the study content itself.

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
| `kanji-adults-task` | `/kanji-adults-task` | The lab.js memory task (headless) |
| `kanji-adults-questions` | `/kanji-adults-questions` | Part 2: vignettes, device, closing code |

Flow, via each style's `redirect_at_end`:

```
/kanji-adults → /kanji-adults-survey → /kanji-adults-task → /kanji-adults-questions
```

The task page is `is_headless = 1` so the experiment gets the full viewport
with no site chrome. The welcome page is the only one added to the site
header; linking the others directly would let a participant skip into the
task without consenting or entering their code.

Participants arrive from a letter without logging in, so the migration grants
read access to all groups **and** adds a direct `acl_users` row for the guest
user. The guest belongs to no group and core checks ACL against the session
user, so group grants alone are not enough — without the user row every page
renders "Kein Zugriff".

## Installation

1. Install `sh-shp-survey_js` and `sh-shp-lab_js` and run their migrations.
2. Place this plugin under `server/plugins/kanji_adults`.
3. Upload the 169 study images to a served folder (default `/assets/kanji`).
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

`server/db/v1.0.0.sql` is **generated** — roughly 100 KB of it is embedded
JSON. Do not edit it by hand. The editable sources live in `content/`:

| Source | Becomes |
|---|---|
| `kanji_part1.surveyjs.json` | `surveys` row `SVJS_KANJIADULTS01`, bound to `kanji-survey-part1` |
| `kanji_part2.surveyjs.json` | `surveys` row `SVJS_KANJIADULTS02`, bound to `kanji-survey-part2` |
| `kanji_labjs.study.json` | `labjs` row `LABJS_KANJIADULT01`, bound to `kanji-task-labjs` |
| `kanji_labjs.css` | `css` field on `kanji-task-labjs` |
| `_structure.sql.part` | the pages, sections and ACL half of the migration |
| `items_learn.csv`, `items_recall.csv` | the trial item tables |

Two generators sit above those:

| Script | Reads | Writes |
|---|---|---|
| `content/build_labjs.php` | `items_learn.csv`, `items_recall.csv` | `kanji_labjs.study.json` |
| `content/build_migration.php` | everything in `content/` | `server/db/v1.0.0.sql` |

To change the trial items, edit the CSVs and run both:

```
php content/build_labjs.php
php content/build_migration.php
```

To change survey wording or the page structure, edit the relevant file in
`content/` and run `build_migration.php` alone.

### Study format

`kanji_labjs.study.json` is in lab.js **Builder** format, not a runtime
component tree. The plugin's loader calls
`makeComponentTree(exp.components, 'root')`, so the file needs a flat
`components` map keyed by id with the entry node keyed exactly `root`,
`children` as arrays of ids, `templateParameters` as a typed grid, and
`files` as `{localPath, poolPath}` pairs. A nested tree loads to a white
screen with `Cannot read properties of undefined (reading 'root')`.

### Task structure

```
practice learn (2)  → practice recall (1)
learn A (30)        → recall A (15)
learn B (30)        → recall B (15)
```

93 trials over 60 kanji, split 30/30 into lists A and B, with 15 of each list
tested. Timings taken from the Qualtrics `QuestionJS`:

| Screen | Duration |
|---|---|
| Fixation cross | 500 ms, auto-advance |
| Learn stimulus (kanji + meaning) | 5000 ms, auto-advance |
| Recall 2AFC | self-paced |
| Confidence (3-point) | self-paced |

Trial order is shuffled within each block, matching Qualtrics'
`Randomization: Subset`. Practice blocks are not shuffled.

The confidence scale is localised by the `window.KANJI_LANG` global
(`de`/`en`/`fr`/`it`), which picks the matching `CJ_*` images. It falls back
to German when unset.

## Data

Two dataTables, created on first response by the respective plugins:

- the surveyjs tables, one row per response, one column per question
- the lab.js table, with per-block `extra_data_*` columns and full
  trial-level data in `_raw_data` as JSON

Join on the participant code (`ID_1` / `ID_2`).

`saveDataToSelfHelp()` fires after each block — `updated` for practice,
`learn_A`, `recall_A` and `learn_B`, then `finished` after `recall_B`.

Per recall trial:

| Field | Meaning |
|---|---|
| `list`, `trial`, `concept` | item identity |
| `correct_side` | which side held the correct answer |
| `chosen_side`, `chosen_img` | what the participant clicked |
| `correct` | 1 / 0 |
| `confidence` | 1 = unsicher, 2 = mittel, 3 = sicher |
| `duration` | lab.js response time, ms |
| `orig_pos` | the fixed position Qualtrics used, for cross-wave comparison |

Unpacking `_raw_data` into one row per trial is not implemented — it needs a
real response to write against. That is the natural content of `v1.1.0`.

## Deviations from the Qualtrics original

1. **Correct-answer side is randomised per trial.** The original fixed it per
   trial and blocked it (list A: trials 1–7 left, 8–15 right), which is
   exploitable. `orig_pos` is retained so the old wave stays comparable.
2. **`correct` is recorded at runtime** instead of being reconstructed in
   analysis by joining against the learning list.
3. **Demographic gating.** The original showed all 26 questions to everyone
   with no display logic, so participants who said "no other children" were
   still required to answer six household-children matrices. Gating now
   follows the question wording. *Confirm with the study owner.*
4. **Demo_7–12 → one dynamic panel**, sized from Demo_6. Same data.
5. **Input sanitising → validators.** The Qualtrics keydown/paste JS became
   regex validators with localised messages.
6. **Two scale typos fixed** in `P3_Vignette_Deut` (EN "Verly unlikely",
   IT lowercase "improbabile"), normalised to the other three vignettes.
7. **Four language branches → one survey** with `de`/`en`/`fr`/`it` locales.

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
