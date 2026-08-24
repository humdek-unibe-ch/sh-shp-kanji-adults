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
`redirect_at_end`, and `update_based_on` on both styles.

## Install

1. Install and migrate the two plugins above.
2. Upload the study images (173 files) to a served folder. `@base_path` and
   `@asset_base` at the top of the migration set the URL prefix; `@base_path`
   must match `BASE_PATH` in `globals_untracked.php`. The default asset folder
   is `/assets/kanji`.
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
| `kanji-adults-survey` | Part 1: consent, code, demographics |
| `kanji-adults-task-1` | Instructions, practice, learn A |
| `kanji-adults-pause-1` | Vignette: Französische Vokabeln |
| `kanji-adults-task-2` | Recall A |
| `kanji-adults-pause-2` | Vignette: Geografie Quiz |
| `kanji-adults-task-3` | Learn B |
| `kanji-adults-pause-3` | Vignettes: Mathematik, Aufsatz |
| `kanji-adults-task-4` | Recall B, closing screen |
| `kanji-adults-questions` | Device, closing code |

The vignettes are interleaved with the memory task, as in the original: each
fills the retention interval between learning a list and recalling it.

Participants arrive from a letter without logging in, so every page grants
access to all groups and carries an `acl_users` row for the guest user. Every
page except the welcome page is headless, so the study runs without the site
header and footer.

## How a run holds together

The code is collected once, in part 1, and travels from page to page as a path
segment: part 1 redirects to `kanji-adults-task-1/{{ID_1}}`, and every component
after it has `url_params` on, so it saves the code it was opened with as
`extra_param_code` and passes it to the next page. It is a path segment rather
than a query parameter because only route parameters reach a style, which is
what lets the guard below filter a row by it.

All nine components write into one table, `Kanji_Data`, and set
`update_based_on` to `extra_param_code`, so each save updates the row already
carrying that code. One participant is **one row**.

Nothing is kept in the session, so a run survives a dropped session, a new tab,
a different device, and a login part-way through.

A resumed run writes into its existing row: a participant who stops and comes
back replaces their earlier answers where the attempts overlap, and keeps the
first attempt where the second did not reach.

## A code is used once

`kanji-adults-task-1` holds two conditional containers — one with the
experiment, one with an "already completed" message in all four languages. Both
read the row for the code in the URL and test `Finished_Study` from opposite
sides.

`Finished_Study` is a hidden question on the closing survey, so it is set only
when a run reaches the end. An abandoned run leaves it unset and can be resumed;
a completed one is refused. The check sits on the first page after the code is
typed, so no task data has been written when it runs.

This is CMS configuration — `condition` and `data_config` on the containers —
not code.

## Recorded data

One row per participant in `Kanji_Data`.

| Column | Contents |
|---|---|
| `EV`, `ID_1`, `Demo_*` | Consent, code, demographics |
| `P1_*`, `P2_*`, `P3_*` | Vignette ratings from each break |
| `Device`, `ID_2` | Device, closing code |
| `extra_data_trials_learn_A/_B` | Learning: item shown, on-screen duration |
| `extra_data_trials_recall_A/_B` | Recall: choice, confidence, reaction times, accuracy |
| `extra_data_n_*` | Per-block trial and correct counts |
| `extra_data_UserLanguage` | `DE` / `EN` / `FR` / `IT` |
| `Finished_Study` | `1` once the closing survey is done |

Questionnaire columns carry the Qualtrics names without the per-language suffix
(`Demo_2` here, `Demo_2_DE` there), so the two waves line up. The task blocks
stay as JSON, keeping the original `Auswahl_Q42` / `Reaktionszeit_Q42_ms` field
names inside. The practice round is not stored.

The export also carries SelfHelp's own columns — `_meta_*`, `_json`,
`_raw_data`, `response_id` and similar. `_raw_data` is the complete lab.js event
log and is by far the largest.

Because participants are not logged in, every write belongs to the guest user,
so the code is the only thing separating one participant from another. A code
given to two people would put them in one row.

## Editing the study

The database is the live system: the questionnaires are editable under **Module
SurveyJS** and the task under **Module LabJS**, and an edit takes effect
immediately. Renaming a questionnaire or task segment breaks the match the
migration uses, and the next run seeds a second copy alongside it.
