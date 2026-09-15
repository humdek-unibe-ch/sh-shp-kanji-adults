# Kanji Adults

Parent–child paired-associate Kanji memory study with questionnaires, ported
from Qualtrics.

**Not a plugin.** One SQL migration that builds the study from components
SelfHelp already has: questionnaires are `surveyJS` sections, the memory task
is `labJS` sections, the pages are ordinary CMS pages. No PHP, no hooks.

## Requirements

- SelfHelp v7.8.1+
- [sh-shp-survey_js](https://github.com/humdek-unibe-ch/sh-shp-survey_js) **v1.7.0+**
- [sh-shp-lab_js](https://github.com/humdek-unibe-ch/sh-shp-lab_js) **v1.3.0+**

Install and migrate both first. The study needs `url_params`, `{{name}}`
templating in `redirect_at_end`, `update_based_on`, and `warning_on_reload`.

## Install

1. Copy `content/kanji_labjs.css` and these into the served `/assets` folder —
   `@asset_base` in the migration is `@base_path` + `/assets`, and `@base_path`
   must match `BASE_PATH` in `globals_untracked.php`:

   ```
   ID_Brief.png  Logo_Universitaet_Bern.png  aufmerksamkeit_2c_ausrufezeichen.png
   Vignette_Franz.jpg  Vignette_Geo.jpg  Vignette_Math.jpg  Vignette_Deut.jpg
   ```

   Without the CSS the task renders unstyled. Kanji and instruction images need
   no upload — lab.js embeds them in the study. This plugin's `assets/` holds
   those 165 originals as the source a rebuild embeds from.

2. Run the migration **with an explicit UTF-8 charset**, or every umlaut
   corrupts on import:

   ```
   mysql --default-character-set=utf8mb4 -u root <database> < server/db/v1.0.0.sql
   ```

3. Clear the CMS cache.

Re-running is safe: `INSERT IGNORE` for pages and sections, and questionnaires
and task segments are matched on title or name and updated in place, so ids
stay stable and anyone mid-study is unaffected.

> **The shipped migration is a reduced test build — 11 trials** (1 practice,
> 3 learn A, 2 recall A, 3 learn B, 2 recall B), for walking the study end to
> end, not for collecting data. The full study is 93 trials (2 + 1 practice,
> 30 + 15 per list); ask the dev responsible for a production build.

## Pages

Each page carries one surveyJS or labJS component writing to its own table.
Participants move through in table order.

| Keyword | Contents | CMS name | Table |
|---|---|---|---|
| `home` | Welcome, language picker | — | — |
| `kanji-adults-survey` | Consent and code | Kanji – Teil 1: Einverständnis und Code | `Kanji_Part1` |
| `kanji-adults-demographics` | Demographics | Kanji – Teil 2: Angaben | `Kanji_Demographics` |
| `kanji-adults-task-1` | Instructions, practice, learn A | Kanji Aufgabe 1: Instruktion, Übung, Lernen Liste A | `Kanji_Task1` |
| `kanji-adults-pause-1` | Vignette | Kanji – Pause 1: Französische Vokabeln | `Kanji_Pause1` |
| `kanji-adults-task-2` | Recall A | Kanji Aufgabe 2: Abfrage Liste A | `Kanji_Task2` |
| `kanji-adults-pause-2` | Vignette | Kanji – Pause 2: Geografie Quiz | `Kanji_Pause2` |
| `kanji-adults-task-3` | Learn B | Kanji Aufgabe 3: Lernen Liste B | `Kanji_Task3` |
| `kanji-adults-pause-3` | Vignettes | Kanji – Pause 3: Mathematik und Aufsatz | `Kanji_Pause3` |
| `kanji-adults-task-4` | Recall B, closing | Kanji Aufgabe 4: Abfrage Liste B, Abschluss | `Kanji_Task4` |
| `kanji-adults-questions` | Device, closing code | Kanji – Teil 2: Gerät und Abschlusscode | `Kanji_Part2` |
| `kanji-adults-prize-draw` | Prize draw, e-mail only | Kanji – Verlosung | `Kanji_PrizeDraw` |

The vignettes fill the retention interval between learning a list and recalling
it, as in the original. The four lab.js entries are one study split across four
pages; a change to the trial items or instruction screens is a rebuild, which
the dev responsible runs.

Participants arrive from a letter without logging in, so every page grants
access to all groups and carries an `acl_users` row for the guest user, and
every page is headless — once the run starts there is nothing to navigate to.

## How a run holds together

The code is typed once in part 1, which redirects to
`kanji-adults-demographics/{{ID_1}}`. Every later component has `url_params` on,
so it stores the code it was opened with as `extra_param_code` and passes it
along. It is a path segment, not a query parameter, because only route
parameters reach a style — which is what lets the guards below filter by it.

Each component owns its table and sets `update_based_on` to `extra_param_code`,
so running it twice updates its row rather than opening a second. One
participant is **one row per component**, joined on the code.

**Part 1 is the exception.** It sets `update_based_on` like the rest, but it is
where the code is typed, so it is the one component without `url_params` —
nothing in the route to match against. Every visit opens a new row, most
abandoned before submit. Only `finished` rows carry a code, so filter part 1 on
`triggerType` before joining it to anything.

Nothing is kept in the session, so a run survives a dropped session, a new tab,
a different device, or a login part-way through. An unfinished page resumes into
its existing row: `UserInput::update_data` upserts only the columns present in
the new submission, so a second attempt replaces what it carries and leaves
earlier answers untouched.

Surveys have `restart_on_refresh` off, so reloading reopens the response in
progress. An experiment cannot resume mid-way — a reload restarts it from the
first trial — so the task pages set `warning_on_reload`.

## A finished page is not repeated

Every page carrying the code holds two conditional containers: one with the
component, one with an "already completed" message in four languages. Both read
that page's own table, filter by `{{__code__}}` from the URL, and test
`triggerType` from opposite sides. A component writes `finished` only on submit,
so a half-finished page still opens and resumes. This is CMS configuration —
`condition` and `data_config` — not code.

Part 1 has no container: no route parameter to filter on yet. It is deliberately
short — consent and the code — so a finished page is caught on the next page
rather than after the demographics.

Containers decide what renders, not what is written: a save POSTs straight to
the controller. Nothing depends on stopping that, since each component owns its
own row.

## Recorded data

Ten tables, one per component, one row per participant, joined on
`extra_param_code`.

| Table | Contents |
|---|---|
| `Kanji_Part1` | `EV`, `ID_1` — consent and code |
| `Kanji_Demographics` | `Demo_*` |
| `Kanji_Task1` | `extra_data_trials_practice` (choice, confidence, accuracy) and `extra_data_trials_learn_A` (item, duration) |
| `Kanji_Pause1` | `P1_*` ratings |
| `Kanji_Task2` | `extra_data_trials_recall_A` — choice, confidence, reaction times, accuracy |
| `Kanji_Pause2` | `P2_*` ratings |
| `Kanji_Task3` | `extra_data_trials_learn_B` |
| `Kanji_Pause3` | `P3_*` ratings |
| `Kanji_Task4` | `extra_data_trials_recall_B` |
| `Kanji_Part2` | `Device`, `ID_2`, `Finished_Study` — `1` once the closing survey is done |

Task tables also carry `extra_data_n_*` (trial and correct counts) and
`extra_data_UserLanguage`. Questionnaire columns keep the Qualtrics names
without the language suffix (`Demo_2`, not `Demo_2_DE`) so the two waves line
up; the task blocks stay as JSON with the original field numbers inside —
`Q22`/`Q23` practice, `Q42`/`Q43` recall A, `Q2`/`Q3` recall B.

Each table also carries `_meta_*`, `response_id`, `_json` and `_raw_data`.
`_raw_data` is the full lab.js event log and is by far the largest; `_json`
repeats the flat columns as one nested blob. The R export drops both, so they
are reachable only through the CMS Data page or the API.

Participants are not logged in, so every write belongs to the guest user and the
code is the only thing separating them. A code given to two people merges them
into the same row in every table.

`Kanji_PrizeDraw` sits outside that scheme: an e-mail address and nothing else,
no participant code, so a draw entry cannot be tied back to anyone's answers.
The export keeps it in its own file for the same reason.

## Editing the study

The database is the live system: questionnaires under **Module SurveyJS**, the
task under **Module LabJS**, edits take effect immediately. Renaming a
questionnaire or task segment breaks the match the migration uses, and the next
run seeds a second copy alongside it.

Demographic answer values are sequential `1..n` in display order, and
`visibleIf` / `defaultValueExpression` reference those values — renumbering an
option means updating the expressions in the same edit, or questions silently
stop appearing.
