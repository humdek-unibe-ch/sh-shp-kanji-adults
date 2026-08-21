# SelfHelp Plugin — Kanji Adults

The Kanji Adults parent–child study: a paired-associate Kanji memory task
with questionnaires, ported from Qualtrics.

| Layer | Value |
|---|---|
| Repository | `sh-shp-kanji-adults` |
| Local folder | `kanji_adults` (any name works; globals load from the real folder) |
| `plugins.name` | `kanji-adults` |

The plugin contains **no rendering code**. It creates the ten pages of the
study, chains them with `redirect_at_end`, and loads the questionnaires and the
memory task into the tables the surveyjs and lab_js plugins read.

## Requirements

- SelfHelp v7.8.1+
- [sh-shp-survey_js](https://github.com/humdek-unibe-ch/sh-shp-survey_js)
- [sh-shp-lab_js](https://github.com/humdek-unibe-ch/sh-shp-lab_js)

Both must be installed **before** this plugin's migration runs — it references
the `surveyJS` and `labJS` styles by name.

## Installation

1. Install `sh-shp-survey_js` and `sh-shp-lab_js` and run their migrations.
2. Clone this plugin into `server/plugins`.
3. Upload the study images (173 files) to a served folder (default
   `/assets/kanji`). `@base_path` and `@asset_base` at the top of the migration
   set the URL prefix; `@base_path` must match `BASE_PATH` in
   `globals_untracked.php`.
4. Execute `server/db/v1.0.0.sql` — **with an explicit UTF-8 charset**:

   ```
   mysql --default-character-set=utf8mb4 -u root <database> < server/db/v1.0.0.sql
   ```

   The MySQL client on Windows often defaults to `cp850`, which silently
   corrupts every umlaut and accent on import (`für` becomes `f─╝r`).

5. **Clear the CMS cache** (pages/sections).

That is the whole install: the single migration creates the pages, embeds and
publishes the five questionnaires and the four task segments, binds them to
their sections and grants access. No CMS import or configuration is needed.

Re-running it is safe. Pages and sections use `INSERT IGNORE`; questionnaires
and task segments are matched on their title or name and updated in place, so
ids stay stable and participants already mid-study are unaffected.

> **The shipped `v1.0.0.sql` is a reduced test build: 5 trials per block, 23 in
> total.** It is meant for walking the chain end to end, not for data
> collection. The full study is 93 trials — ask the dev responsible for a
> production build when ready.

## Pages

| Keyword | Contents |
|---|---|
| `home` / `kanji-adults` | Welcome / landing text — one section, two URLs |
| `kanji-adults-survey` | Part 1: consent, code, demographics |
| `kanji-adults-task-1` | Instructions, practice, learn A |
| `kanji-adults-pause-1` | Vignette: Französische Vokabeln |
| `kanji-adults-task-2` | Recall A |
| `kanji-adults-pause-2` | Vignette: Geografie Quiz |
| `kanji-adults-task-3` | Learn B |
| `kanji-adults-pause-3` | Vignettes: Mathematik, Aufsatz |
| `kanji-adults-task-4` | Recall B, closing screen |
| `kanji-adults-questions` | Device, closing code |

The vignettes are interleaved with the memory task, as in the Qualtrics
original: each one fills the retention interval between learning a list and
recalling it.

Participants arrive from a letter without logging in, so every page grants
access to all groups **and** carries a direct `acl_users` row for the guest
user. The guest belongs to no group and core checks ACL against the session
user, so group grants alone would render "Kein Zugriff" on every page.

The site's start page **is** the welcome page: the same container section is
attached to both `home` and `kanji-adults`, so there is one piece of content
behind two URLs — `/` for anyone arriving at the site, `/kanji-adults` so a link
already printed on a letter keeps working. `kanji-adults` is not in the header,
which leaves a single entry named after the study. This is the one place the
plugin touches a core page, and re-running the migration re-applies it.

Every page except the welcome page is `is_headless = 1`, so the study runs
without the site header and footer, as the Qualtrics original did: once a run
starts there is nothing to navigate to, and the chrome invites participants to
wander off mid-task. The welcome page keeps both, and with them the language
picker. Only the welcome page is in the site header — linking the others
directly would let a participant skip into the task without consenting.

## Editing the study

The database is the live system: the questionnaires are editable under **Module
SurveyJS** and the task under **Module LabJS**, and an edit takes effect
immediately. Re-running the migration will not undo it.

Renaming a questionnaire or task segment in the CMS breaks the match the
migration uses, and the next run then seeds a second copy alongside it.

The migration was generated from a set of source files — the Qualtrics-derived
survey JSON, the trial item tables and two build scripts — kept outside this
repository. Ask the responsible dev for them if the study has to be rebuilt from
scratch rather than edited in the CMS.

## Recorded data

Everything one participant produces lands in **one row** of the `Kanji_Data`
table.

| Column | Contents |
|---|---|
| `EV`, `ID_1`, `Demo_*` | Consent, personal code, demographics |
| `P1_*`, `P2_*`, `P3_*` | The vignette ratings from each break |
| `Device`, `ID_2` | Device, closing code |
| `kanji_lernen_a`, `kanji_lernen_b` | Learning phase: item shown, actual on-screen duration |
| `kanji_a`, `kanji_b` | Recall: choice, confidence, reaction times, accuracy |
| `UserLanguage`, `Finished` | Language answered in, and whether the run reached the end |

The questionnaire columns carry the same names as the Qualtrics export, minus
its per-language suffix (`Demo_2` here, `Demo_2_DE` there), so the two waves line
up. The two memory-task blocks stay as JSON — one object per trial, keeping the
original `Auswahl_Q42` / `Reaktionszeit_Q42_ms` field names inside.

The practice round is not stored, and per-block totals are derivable from the
trial data rather than stored separately.

`DATA_FIELDS.md`, shipped with the study sources, describes every field for the
research team.

The row is keyed on the letter code, so a run survives a dropped session, a new
tab or a return the next day, and a login part-way through does not strand it.
Entering a code whose run is already finished opens a second row rather than
overwriting the first — both carry the code in `ID_1`.

Three hooks in `KanjiAdultsHooks` make this work: two pin a per-participant
`response_id` so all nine components write to the same row, and one renames the
task columns and keeps every write reaching that row. See
[INTERNALS.md](INTERNALS.md) for why each is needed.

## Migrations

| File | Contents |
|---|---|
| `v1.0.0.sql` | Everything: plugin registration, pages, sections, ACL, questionnaire and task content, bindings |

After running: clear the CMS cache.

---

[INTERNALS.md](INTERNALS.md) documents the task implementation, the deviations
from the Qualtrics original, and the failure modes worth knowing before
changing anything.
