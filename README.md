# Kanji Adults

Parent–child paired-associate Kanji memory study with questionnaires, ported
from Qualtrics. One SQL migration building the study from stock SelfHelp
components.

## Requirements

- SelfHelp v7.8.1+
- [sh-shp-survey_js](https://github.com/humdek-unibe-ch/sh-shp-survey_js) **v1.7.0+**
- [sh-shp-lab_js](https://github.com/humdek-unibe-ch/sh-shp-lab_js) **v1.3.0+**

## Install

1. Copy `content/kanji_labjs.css` and these into the served `/assets`:

   ```
   ID_Brief.png  Logo_Universitaet_Bern.png  aufmerksamkeit_2c_ausrufezeichen.png
   Vignette_Franz.jpg  Vignette_Geo.jpg  Vignette_Math.jpg  Vignette_Deut.jpg
   ```

   `@asset_base` is `@base_path` + `/assets`; `@base_path` must match
   `BASE_PATH` in `globals_untracked.php`. Kanji and instruction images are
   embedded in the lab.js study — `assets/` holds the 165 originals a rebuild
   embeds from.

2. Run the migration. **Without the charset flag every umlaut corrupts:**

   ```
   mysql --default-character-set=utf8mb4 -u root <database> < server/db/v1.0.0.sql
   ```

3. Clear the CMS cache.

Safe to re-run: pages use `INSERT IGNORE`, surveys and task segments match on
title or name and update in place.

> **Shipped as a test build — 11 trials** (1 practice, 3 learn A, 2 recall A,
> 3 learn B, 2 recall B). The full study is 93; ask the dev responsible for a
> production build.

## Pages

One component per page, each writing to its own table, in this order. The
vignettes fill the retention interval between learning a list and recalling it.

| Keyword | CMS name | Table |
|---|---|---|
| `home` | — | — |
| `kanji-adults-survey` | Kanji – Teil 1: Einverständnis und Code | `Kanji_Part1` |
| `kanji-adults-demographics` | Kanji – Teil 2: Angaben | `Kanji_Demographics` |
| `kanji-adults-task-1` | Kanji Aufgabe 1: Instruktion, Übung, Lernen Liste A | `Kanji_Task1` |
| `kanji-adults-pause-1` | Kanji – Pause 1: Französische Vokabeln | `Kanji_Pause1` |
| `kanji-adults-task-2` | Kanji Aufgabe 2: Abfrage Liste A | `Kanji_Task2` |
| `kanji-adults-pause-2` | Kanji – Pause 2: Geografie Quiz | `Kanji_Pause2` |
| `kanji-adults-task-3` | Kanji Aufgabe 3: Lernen Liste B | `Kanji_Task3` |
| `kanji-adults-pause-3` | Kanji – Pause 3: Mathematik und Aufsatz | `Kanji_Pause3` |
| `kanji-adults-task-4` | Kanji Aufgabe 4: Abfrage Liste B, Abschluss | `Kanji_Task4` |
| `kanji-adults-questions` | Kanji – Teil 2: Gerät und Abschlusscode | `Kanji_Part2` |
| `kanji-adults-prize-draw` | Kanji – Verlosung | `Kanji_PrizeDraw` |

The four lab.js entries are one study split across four pages; changing trial
items or instruction screens is a rebuild, which the dev responsible runs.

Participants arrive from a letter without logging in, so every page grants
access to all groups, carries an `acl_users` row for the guest user, and is
headless.

## How a run holds together

Part 1 collects the code and redirects to
`kanji-adults-demographics/{{ID_1}}`. Every later component has `url_params` on,
so it stores the code it was opened with as `extra_param_code` and passes it
along — a path segment, not a query parameter, because only route parameters
reach a style.

Each component owns its table and sets `update_based_on` to `extra_param_code`,
so running it twice updates its row rather than opening a second. One
participant is one row per component, joined on the code.

**Part 1 is the exception.** It is where the code is typed, so it has no
`url_params` and nothing to match against. Every visit opens a new row, most
abandoned before submit. Only `finished` rows carry a code — filter part 1 on
`triggerType` before joining it to anything.

Nothing is kept in the session, so a run survives a dropped session, another
device, or a login part-way through. An unfinished page resumes into its
existing row: `UserInput::update_data` upserts only the columns in the new
submission. An experiment cannot resume mid-way, so the task pages set
`warning_on_reload`.

## A finished page is not repeated

Every page carrying the code holds two conditional containers — the component,
and an "already completed" message. Both read that page's own table, filter by
`{{__code__}}`, and test `triggerType` from opposite sides. A component writes
`finished` only on submit, so a half-finished page still opens and resumes.

Containers decide what renders, not what is written: a save POSTs straight to
the controller. Each component owns its own row, so nothing depends on stopping
that.

## Recorded data

Ten tables, one row per participant, joined on `extra_param_code`.

| Table | Contents |
|---|---|
| `Kanji_Part1` | `EV`, `ID_1` — consent and code |
| `Kanji_Demographics` | `Demo_*` |
| `Kanji_Task1` | `extra_data_trials_practice`, `extra_data_trials_learn_A` |
| `Kanji_Pause1` | `P1_*` ratings |
| `Kanji_Task2` | `extra_data_trials_recall_A` |
| `Kanji_Pause2` | `P2_*` ratings |
| `Kanji_Task3` | `extra_data_trials_learn_B` |
| `Kanji_Pause3` | `P3_*` ratings |
| `Kanji_Task4` | `extra_data_trials_recall_B` |
| `Kanji_Part2` | `Device`, `ID_2`, `Finished_Study` |

Recall blocks record choice, confidence, reaction times and accuracy; learning
blocks record item and on-screen duration. Task tables also carry
`extra_data_n_*` counts and `extra_data_UserLanguage`.

Questionnaire columns keep the Qualtrics names without the language suffix
(`Demo_2`, not `Demo_2_DE`); the task JSON keeps the original field numbers —
`Q22`/`Q23` practice, `Q42`/`Q43` recall A, `Q2`/`Q3` recall B.

Every table also has `_meta_*`, `response_id`, `_json` and `_raw_data`. The R
export drops the last two, so they are reachable only through the CMS Data page
or the API.

Participants are not logged in, so every write belongs to the guest user and the
code is the only thing separating them. A code given to two people merges them
into one row in every table.

`Kanji_PrizeDraw` has an e-mail address and no participant code, so a draw entry
cannot be tied back to anyone's answers. The export keeps it in its own file for
the same reason.

## Editing the study

The database is the live system — questionnaires under **Module SurveyJS**, the
task under **Module LabJS**, edits take effect immediately. Renaming either
breaks the match the migration uses, and the next run seeds a second copy.

Demographic answer values are sequential `1..n` in display order, and
`visibleIf` / `defaultValueExpression` reference them. Renumbering an option
means updating those expressions in the same edit, or questions silently stop
appearing.
