# SelfHelp Plugin — Kanji Adults

Page structure for the Kanji Adults parent–child dyad study: a paired-associate
Kanji memory task with questionnaires, ported from Qualtrics.

## Identity

| Layer | Value |
|---|---|
| Folder | `sh-shp-kanji-adults` |
| `plugins.name` | `kanji-adults` |

## What this plugin does

It creates the four pages that make up the study and wires them together.

It ships **no rendering code**. The questionnaires are rendered by the
**surveyjs** plugin's `surveyJS` style and the memory task by the **lab_js**
plugin's `labJS` style. This plugin only creates the page and section rows
that put those styles in the right order with the right redirects.

Scoring is not needed: the lab.js study records `correct` per trial at
runtime.

## Requirements

- SelfHelp v7.8.1+
- [sh-shp-survey_js](https://github.com/humdek-unibe-ch/sh-shp-survey_js)
- [sh-shp-lab_js](https://github.com/humdek-unibe-ch/sh-shp-lab_js)

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
with no site chrome.

## Installation

1. Install `sh-shp-survey_js` and `sh-shp-lab_js` (cloned as `server/plugins/lab`) and run their migrations.
2. Place this plugin under `server/plugins/kanji_adults`.
3. Upload the 169 study images to a served folder (default `/assets/kanji`).
   To use a different path, edit `@asset_base` at the top of `v1.0.0.sql`.
4. Execute `server/db/v1.0.0.sql`.
5. **Clear the CMS cache** (pages/sections).

That is the whole install. The single migration creates the pages, embeds and
publishes both surveys and the lab.js study, and binds them to their sections
— no CMS import or configuration is needed. It is idempotent.

The surveys ship with `de`, `en`, `fr`, `it` already populated.

### Content

`v1.0.1.sql` is generated from the files in `content/`. They are kept as the
editable source; the migration is the deployable form.

| Source | Becomes |
|---|---|
| `kanji_part1.surveyjs.json` | `surveys` row `SVJS_KANJIADULTS01`, bound to `kanji-survey-part1` |
| `kanji_part2.surveyjs.json` | `surveys` row `SVJS_KANJIADULTS02`, bound to `kanji-survey-part2` |
| `kanji_labjs.study.json` | `labjs` row `LABJS_KANJIADULT01`, bound to `kanji-task-labjs` |
| `kanji_labjs.css` | `css` field on `kanji-task-labjs` |

To change any of them, edit the file in `content/` and regenerate the
migration rather than editing the SQL by hand.

## Data

Two dataTables, created on first response by the respective plugins:

- the surveyjs tables, one row per response, one column per question
- the lab.js table, with per-block `extra_data_*` columns and full
  trial-level data in `_raw_data` as JSON

Join on the participant code (`ID_1` / `ID_2`).

Unpacking `_raw_data` into one row per trial is not implemented — it needs a
real response to write against. That is the natural content of `v1.1.0`.

## Migrations

| File | Contents |
|---|---|
| `v1.0.0.sql` | Everything: plugin registration, pages, sections, survey + study content, bindings |

After running: clear the CMS cache.
