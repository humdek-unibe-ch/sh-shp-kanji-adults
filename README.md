# Kanji Adults

The Kanji Adults parent–child study: a paired-associate Kanji memory task with
questionnaires, ported from Qualtrics.

This is **not a plugin**. It is one SQL migration that builds the study out of
components SelfHelp already has — the questionnaires are `surveyJS` sections,
the memory task is `labJS` sections, the pages are ordinary CMS pages. There is
no PHP and there are no hooks.

## Requirements

- SelfHelp v7.8.1+
- [sh-shp-survey_js](https://github.com/humdek-unibe-ch/sh-shp-survey_js) **v1.7.0+**
- [sh-shp-lab_js](https://github.com/humdek-unibe-ch/sh-shp-lab_js) **v1.3.0+**

Both must be installed and migrated **before** this one runs. The versions
matter: the study needs `url_params`, `{{name}}` templating in
`redirect_at_end` and `update_based_on` on both styles.

## Install

1. Install and migrate the two plugins above.
2. Upload the study images (173 files) to a served folder. `@base_path` and
   `@asset_base` at the top of the migration set the URL prefix; `@base_path`
   must match `BASE_PATH` in `globals_untracked.php`. The default asset folder
   is `/assets/kanji`.

   Put `kanji_labjs.css` in `/assets` while you are there. The migration already
   seeds this stylesheet into the task pages, so the study is styled without it;
   the copy in `/assets` is what a researcher loads into the lab.js Builder to
   preview a task the way participants see it.
3. Run the migration **with an explicit UTF-8 charset**:

   ```
   mysql --default-character-set=utf8mb4 -u root <database> < server/db/v1.0.0.sql
   ```

   The MySQL client on Windows often defaults to `cp850`, which silently
   corrupts every umlaut on import (`für` becomes `f─╝r`).

4. Clear the CMS cache.

Re-running is safe. Pages and sections use `INSERT IGNORE`; questionnaires and
task segments are matched on their title or name and updated in place, so ids
stay stable and anyone mid-study is unaffected.

> **The shipped migration is a reduced test build: 5 trials per block, 23 in
> total.** It is for walking the study end to end, not for data collection. The
> full study is 93 trials — ask the dev responsible for a production build.

## Pages

| Keyword | Contents |
|---|---|
| `home` / `kanji-adults` | Welcome — one section, two URLs |
| `kanji-adults-survey` | Part 1: consent and code |
| `kanji-adults-demographics` | Part 2: demographics |
| `kanji-adults-task-1` | Instructions, practice, learn A |
| `kanji-adults-pause-1` | Vignette: Französische Vokabeln |
| `kanji-adults-task-2` | Recall A |
| `kanji-adults-pause-2` | Vignette: Geografie Quiz |
| `kanji-adults-task-3` | Learn B |
| `kanji-adults-pause-3` | Vignettes: Mathematik, Aufsatz |
| `kanji-adults-task-4` | Recall B, closing screen |
| `kanji-adults-questions` | Part 3: device, closing code |

The vignettes are interleaved with the memory task, as in the original: each
fills the retention interval between learning a list and recalling it.

Participants arrive from a letter without logging in, so every page grants
access to all groups and carries an `acl_users` row for the guest user. Every
page except the welcome page and the first task page is headless, so the study
runs without the site header and footer. The first task page keeps the chrome
because an already-finished task can stop a participant there, and a headless
page would leave no way out; the task CSS hides it again while the experiment
is running.

## How a run holds together

The code is collected once, in part 1, and travels from page to page as a path
segment: part 1 redirects to `kanji-adults-demographics/{{ID_1}}`, and every component
after it has `url_params` on, so it saves the code it was opened with as
`extra_param_code` and passes it to the next page. It is a path segment rather
than a query parameter because only route parameters reach a style, which is
what lets the guard below filter a row by it.

Each component keeps its own data table, the one its style names by default,
and sets `update_based_on` to `extra_param_code`, so a component run twice
updates its own row rather than opening a second. One participant is **one row
per component**, and the code is what joins them at analysis time.

Part 1 is the exception: `update_based_on` is empty there because it is the
page where the code is typed, so there is nothing to key on until it has been
answered.

Nothing is kept in the session, so a run survives a dropped session, a new tab,
a different device, and a login part-way through.

An unfinished run can be resumed and writes into its existing row: a participant
who stops and comes back replaces their earlier answers where the attempts
overlap, and keeps the first attempt where the second did not reach.

## A finished page is not repeated

Every page that carries the code holds two conditional containers — one with
the component, one with an "already completed" message in all four languages.
Both read **that page's own data table**, filter it by the code in the URL, and
test the row's `triggerType` from opposite sides.

A component writes `finished` only when it is submitted, so a page shows its
done message once its own survey or experiment has been completed, and a page
left part way through still opens and can be resumed. Going back to a finished
task therefore says so, while the next task opens normally.

This is CMS configuration — `condition` and `data_config` on the containers —
not code. The filter takes the code straight from the URL as `{{__code__}}`,
which every style exposes for its route parameters.

Part 1 has no container: it is where the code is typed, so there is no route
parameter to filter on yet. It is deliberately short — consent and the code,
seven questions — so a finished page is caught on the very next page rather
than after the demographics.

Containers decide what renders, not what is written. A save is a POST straight
to the component's controller and never passes through them, so a participant
who reposts by hand can still write. Nothing in the study depends on stopping
that: each component owns its own row, so a repeat overwrites only its own
data.

## Recorded data

Ten tables, one per component, each with one row per participant. Every row
carries `extra_param_code`, which is what joins them.

| Table | Contents |
|---|---|
| `Kanji_Part1` | `EV`, `ID_1` — consent and code |
| `Kanji_Demographics` | `Demo_*` |
| `Kanji_Task1` | `extra_data_trials_learn_A` — item shown, on-screen duration |
| `Kanji_Pause1` | `P1_*` — Französische Vokabeln ratings |
| `Kanji_Task2` | `extra_data_trials_recall_A` — choice, confidence, reaction times, accuracy |
| `Kanji_Pause2` | `P2_*` — Geografie Quiz ratings |
| `Kanji_Task3` | `extra_data_trials_learn_B` |
| `Kanji_Pause3` | `P3_*` — Mathematik and Aufsatz ratings |
| `Kanji_Task4` | `extra_data_trials_recall_B` |
| `Kanji_Part2` | `Device`, `ID_2`, `Finished_Study` — set to `1` once the closing survey is done |

The task tables also carry `extra_data_n_*` (per-block trial and correct
counts) and `extra_data_UserLanguage` (`DE` / `EN` / `FR` / `IT`).

Questionnaire columns carry the Qualtrics names without the per-language suffix
(`Demo_2` here, `Demo_2_DE` there), so the two waves line up. The task blocks
stay as JSON, keeping the original `Auswahl_Q42` / `Reaktionszeit_Q42_ms` field
names inside. The practice round is not stored.

The export also carries SelfHelp's own columns — `_meta_*`, `_json`,
`_raw_data`, `response_id` and similar. `_raw_data` is the complete lab.js event
log and is by far the largest.

Because participants are not logged in, every write belongs to the guest user,
so the code is the only thing separating one participant from another. A code
given to two people would merge them into the same row in every table.

## Editing the study

The database is the live system: the questionnaires are editable under **Module
SurveyJS** and the task under **Module LabJS**, and an edit takes effect
immediately. Renaming a questionnaire or task segment breaks the match the
migration uses, and the next run seeds a second copy alongside it.
