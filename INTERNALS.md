# Internals — Kanji Adults

Background for anyone changing the study. None of this is needed to install it;
see [README.md](README.md) for that.

Most of what follows is a failure mode that cost real debugging time. It is
written down so it is not rediscovered.

## Why the task is split across four pages


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

That `Resume` screen no longer decides anything on its own: `save_lab()` is
hooked and overwrites `labjs_response_id` with the id derived from the letter
code before lab_js sees it, so the segments merge whether or not
`sessionStorage` is available. It is left in place as the client-side half of
the same answer.

## Study format

The `labjs.config` the plugin stores is in lab.js **Builder** format, not a
runtime component tree. The plugin's loader calls
`makeComponentTree(exp.components, 'root')`, so the file needs a flat
`components` map keyed by id with the entry node keyed exactly `root`,
`children` as arrays of ids, `templateParameters` as a typed grid, and
`files` as `{localPath, poolPath}` pairs. A nested tree loads to a white
screen with `Cannot read properties of undefined (reading 'root')`.


## Task structure

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

> **The shipped `v1.0.0.sql` is a reduced test build: 5 trials per block, 23
> in total.** It is meant for walking the chain end to end, not for data
> collection. Restoring the full counts above needs a rebuild from the study
> sources — ask the study owner.

| Screen | Duration |
|---|---|
| Fixation cross | 500 ms, auto-advance |
| Learn stimulus (kanji + meaning) | 5000 ms, auto-advance |
| Recall trial (choice + confidence) | self-paced |

Trial order is shuffled within each block, matching Qualtrics'
`Randomization: Subset`. Practice blocks are not shuffled.

The confidence scale is localised by the participant's language, which picks
the matching `CJ_*` images. It falls back to German when unset. See
[How the task learns the language](#how-the-task-learns-the-language).


## The recall trial

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

## Instruction screens

The 12 instruction, orientation and closing pages come from the `.qsf`, one
entry per language, and are stored as self-paced `lab.html.Screen` components
with a Weiter / Next / Suivant / Avanti button.

Text and images are chosen at run time from the participant's language, the
same source the confidence scale uses, so one build serves all four languages.
Images are written as `{{IMG:filename}}` placeholders and resolved against the
screen's file pool.

Each instruction screen carries only the images its own page names — attaching
the full 153-file pool to all twelve doubled the study JSON. The confidence
instruction page is the exception: it carries all 12 localised
`Instruktion_CJ_*` variants, because the language is not known until run time.

Unlike the trial screens these scroll if the content is long. There is no timing
constraint on them, and the original was an ordinary scrollable survey page.


## How the task learns the language

SelfHelp keeps the chosen language in the session, and core stamps it onto every
section it renders as a CSS class:

```html
<div class=" selfHelp-locale-fr-CH style-section-689">
```

The task reads the two-letter code straight off that class:

```js
const lang = (window.KANJI_LANG ||
  (((document.querySelector('[class*=selfHelp-locale-]') || {}).className || '')
    .match(/selfHelp-locale-([a-z]{2})/) || [])[1] || 'de');
```

The surveys need no equivalent: the surveyjs plugin reads the same class and sets
`survey.locale` itself, and SurveyJS falls back from `fr-CH` to `fr` on its own.

### Why not an inline script

The obvious approach — having the migration write
`<script>window.KANJI_LANG = "fr";</script>` into the per-language section — looks
right and silently does nothing. Core builds a CSP that pins a hash for its own
`BASE_PATH` snippet:

```
script-src 'self' 'unsafe-inline' 'unsafe-eval' 'sha256-KbpLpBgfBK+...'
```

Once a hash or nonce is present, the CSP spec has the browser **ignore
`unsafe-inline`** — so every inline `<script>` except that one hash is blocked. The
stylesheet in the same section still applies, because `style-src` carries no hash;
that combination makes the failure look like a task bug rather than a CSP one.

Handler code inside the study is unaffected: lab.js compiles it with
`new Function`, which `unsafe-eval` permits. Reading the DOM class from there is
therefore the one route that works without touching core or the page CSP.

`window.KANJI_LANG` is still honoured if something sets it, which is what makes the
language overridable in a console when testing.


## Task styling

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

## `state` is not per-trial scratch space

In lab.js `this.state` proxies the experiment-wide **datastore**, and the
template context is frozen when a component prepares. lab.js prepares
components ahead of running them, so a `before:prepare` handler that writes
`this.state.left_img` is overwritten by every later trial in the loop, and each
screen renders whichever value happened to be written last.

Anything that varies per trial therefore belongs in `templateParameters`,
computed when the study is generated. Anything that varies at run time (the
confidence images, which depend on the participant's language) must be applied
to the DOM in the `run` handler, not through `state`.

`this.files` is safe in both templates and handlers — it is a proxy over the
component's aggregated file pool, not shared mutable state.


## Data

Everything the study collects lands in **one data table, `Kanji_Data`, as one
row per participant**.

That works because the table is named after the `*_generated_id` of whatever
writes to it, so all five questionnaires and all four task segments carry the
same id. Three hooks in `KanjiAdultsHooks` then shape what is written:

| Hook | Wraps | Does |
|---|---|---|
| `kanji-adults-merge-survey-row` | `SurveyJSModel::save_survey` | keys the run on the letter code, pins `response_id` and `labjs_response_id`, records the language, marks a finished run |
| `kanji-adults-merge-task-row` | `LabJSModel::save_lab` | pins `labjs_response_id` inside `metadata` |
| `kanji-adults-rename-columns` | `UserInput::save_data` | renames the task columns, drops internal plumbing, and makes every write reach the participant's row whoever is logged in |

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

Only this study is affected — other surveys on the installation keep surveyjs'
own per-response ids.

### What the row is keyed on

The pinned id comes from the **letter code**, not from the session:
`RJS_KANJI_<sha1 of "code|" + code>`, the code upper-cased with whitespace
stripped. It is printed on the participant's letter, entered on the first page
as `ID_1` and re-entered at the end as `ID_2`. Keying on it means a run survives
whatever the browser does — a new tab, a dropped session, a login part-way
through, a return the next day on another device. Entering the same code again
resumes the same row.

The consent question comes before the code page, so the first save has nothing
to key on yet and opens a row under the session id instead. When the code
arrives, that row is carried over: re-keyed onto the code if the code has no row
yet, and otherwise discarded — surveyjs posts the whole survey on every save, so
everything answered before the code page is in the payload that carries it.

Only `ID_1` sets the key. `ID_2` is stored as an ordinary column so the two can
be compared during analysis. If it also set the key, a typo on the closing page
would move a finished run onto a code nobody was ever given.

**A new run is declared by part 1 opening**, not by the browser being new. That
matters on a shared machine: without it the next participant inherits the
previous one's identity and their consent answer is written into that row. It is
read from the section name, so renaming `kanji-survey-part1` or
`kanji-survey-part2` in the CMS silently switches this off along with the
`Finished` stamp below.

### Reusing a code

A code identifies a participant, so entering it again resumes their row. It does
not follow that a *finished* run should be reopened: the code is printed on a
letter addressed to a child ("um Ihre Antworten anonym Ihrem Kind zuordnen zu
können"), so two adults in one household can hold the same one, and a second run
would otherwise overwrite the first cell by cell.

Once a run is marked `Finished`, the next one under that code is given a row of
its own — run 1 hashes `code|CODE`, run 2 `code|CODE#2`, and so on up to
`KANJI_MAX_RUNS`. Both rows carry the code in `ID_1`, so they still group during
analysis. Nothing is refused and nothing is overwritten.

### Ownership

`save_row()` defaults `id_users` to whoever is logged in *now*, and scopes its
`updateBasedOn` lookup to that same value. A run that starts as guest and
continues after a login therefore points at a row the lookup can no longer see:
surveyjs surfaces a bare "Data not saved!" modal, and lab_js quietly opens a
second row instead.

The row belongs to the participant, not to the session, so the `save_data` hook
widens the lookup (`own_entries_only = false`) and keeps whichever user id is
already on the row. It sits on `UserInput::save_data` because that is the one
call both plugins pass through; fixing it in either plugin alone leaves the
other one broken.

Two things to know before changing that hook:

- **`updateBasedOn` and `own_entries_only` have to be set together.** `Hooks.php`
  packs only the arguments the caller actually passed, and
  `BaseHooks::execute_private_method` re-expands them positionally — so adding
  `own_entries_only` to a three-argument call hands the boolean to `save_data()`
  as its `updateBasedOn`.
- **`LabJSModel::save_lab` drops `updateBasedOn`** on its `started` save when its
  own user-scoped lookup comes up empty, which inserts a task row of its own.
  The hook restores it whenever the participant's row is already there.


## Columns

**Questionnaires.** One column per question, carrying the same names the
Qualtrics `.qsf` used — `EV`, `ID_1`, `Demo_2`, `P1_Vignette_Franz_1` … — so the two
waves line up column for column. That is surveyjs' own output: it writes a
column per question and explodes each matrix into one column per row
(`prepare_data`), ~55 columns across the five questionnaires.

The one difference from the Qualtrics export is the language suffix. Qualtrics
carried a separate block per language (`Demo_2_DE`, `Demo_2_EN`, …), so about
two thirds of its 1037 columns sat empty for any given participant. Here one
set of columns serves all four languages — which is why `UserLanguage` is
written explicitly on every questionnaire save. Dropping the suffix would
otherwise drop the language out of the data altogether.

**Dates that go stale.** The `jahrgang` dropdown lists the birth years of other
children in the household, and its question scopes itself to children aged 0–17.
A literal list rots every January — the ported one still ended at 2024, so a
child born in 2025 could not be entered while 2007 was still offered. It is
generated by `build_migration.php` from the year the migration is built, current
year back seventeen. Rebuild if the study is still recruiting a year on.

`Demo_25` / `Demo_26` (the two adult birth years) are still a hardcoded
1930–2012, which accepts a parent born in 2012. Qualtrics validated neither, so
there is no original to match; worth a study-owner decision.

**Run state.** Two more columns carry their Qualtrics names:

| Column | Contents |
|---|---|
| `UserLanguage` | `DE` / `EN` / `FR` / `IT`, from the session language at save time |
| `Finished` | `1` once part 2 has been completed, `0` while a run is still in progress |

`Finished` exists because the row's own trigger type cannot answer the question:
`UserInput::update_data()` rewrites `id_actionTriggerTypes` on *every* save, so a
participant who stops after the first vignette also leaves the row on
`finished`. The column is stamped only by part 2, which is the last thing
anybody does, and is what decides whether a reused code resumes or opens a new
row.

**Memory task.** One JSON column per block: flattening the recall trials the
way Qualtrics did (`Auswahl_Q42_L1` … `_L15`) would add 155 columns for data
that is read as a set anyway.

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

