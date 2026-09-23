# content/

The study definition: every question, instruction screen and trial a
participant sees. These are the sources the migration was generated from, kept
here as the readable record of what the study contains — `server/db/v1.0.0.sql`
embeds all of it as one large blob.

Nothing here is read at runtime.

## This folder describes the study; the CMS runs it

Once the migration has seeded the database, the database is the live system —
edit it in the CMS and the change takes effect immediately. A change made only
in the CMS is not reflected in these files, so port anything worth keeping back
into the matching file here, or it is lost on a fresh install.

Rebuilding the migration from these sources is the dev's job; ask for a new
build rather than hand-editing `v1.0.0.sql`.

## Files

| File | Contents |
|---|---|
| `kanji_part1.surveyjs.json` | part 1 — consent and the ID code |
| `kanji_demographics.surveyjs.json` | the demographics questionnaire |
| `kanji_pause{1,2,3}.surveyjs.json` | the vignette pauses between the memory blocks |
| `kanji_part2.surveyjs.json` | part 2 — device, closing code |
| `kanji_prizedraw.surveyjs.json` | the prize draw |
| `instructions.json` | the instruction, orientation and closing screens, all four languages |
| `items_learn.csv`, `items_recall.csv` | the trial item tables |
| `kanji_labjs.css` | the task stylesheet |
| `kanji_labjs.seg{1..4}.builder.json` | the four lab.js segments, one per task page |

The four `.builder.json` files are what Module LabJS holds, and what to open in
the lab.js Builder to preview a task. They are build output — a change to the
item CSVs or `instructions.json` means a rebuild, not an edit here.

**The segments are the full study.** `items_learn.csv` is 30 A + 30 B + 2
practice and `items_recall.csv` is 15 A + 15 B + 1 practice — 93 trials, all of
them in the segments. For counterbalancing each learning and recall loop holds
both lists, with both lists' images, and keeps one at runtime by the
participant's order (see Counterbalancing in the plugin README). Loop titles
still say A or B from before; the save names the block after the list shown.
The CSVs follow `Lists_Kanji_Adults.xlsx` from the
research team; only file names differ where the list spells an image
differently (`Dunkel`, `Gefaehlich_Kanji`, `Tickets`).

## Worth knowing

- **The correct image's side is fixed per item.** `correct_pos_original` in
  `items_recall.csv` (1 left, 2 right) is the side from the researchers' item
  list, and each recall row's `left_img`, `right_img` and `correct_side` must
  follow it. Only the trial order is shuffled, at runtime.
- **Image paths use `{{ASSET_BASE}}`.** The migration substitutes the real path,
  so write the placeholder, not a literal URL.
- **Kanji and instruction images are embedded.** lab.js carries them inside the
  segments as `embedded/<hash>` entries, so they need no upload. Only the
  questionnaire images and `kanji_labjs.css` go in `/assets`. Each is the
  original from `assets/` scaled to at most 800 px, flattened onto white and
  saved as JPEG quality 72 (GD `imagecopyresampled`); the hash is the SHA-256 of
  those bytes. Embed a new image the same way or it will not match the rest.
- **List A repeats an image and skips one.** `Gefaehrlich.jpg` is the distractor
  for `Vorsicht` and the target of its own trial, and `Fels` is learned but
  never tested. Both come from the research team's list.
- **The study registers no hooks.** Each component writes to its own table and
  matches its own row on `update_based_on`; there is no PHP in this study at
  all. The `extra_data_` prefix on the task columns is lab_js' own, not
  something this study applies.
- **Part 1 cannot dedupe.** `update_based_on` is set on it like everywhere else,
  but part 1 is the page where the code is typed and so carries no `url_params`
  — nothing in the route to match against. Every open writes a row, most of them
  abandoned. Only the `finished` rows hold a code.
- **Re-running the migration overwrites CMS edits.** Surveys and studies are
  matched on their title (surveys) or name (labjs) and rewritten from the
  migration, so their ids stay the same but a change made in the CMS since is
  lost. Renaming a survey in the CMS breaks the match, and the next migration
  then seeds a second copy alongside it.
- **`kanji_labjs.css` is delivered by a markdown section**, which renders a
  `<link>` to the copy served from `@asset_base`. It cannot be the labJS `css`
  field — that field is a class-name list — and a `<style>` inside the study is
  wiped by the next screen. Linking the served file rather than inlining it
  keeps one source and lets the browser cache it across the four task pages.
- **Presentation is Bootstrap utilities on each section's `css` field.** Inline
  CSS in a `text_md` field is for what no utility can reach: `.cms-edit`, which
  is a core element outside every section, and the `body` background, which
  `styles.min.css` paints `#fff!important`. Those two are the only rules the
  questionnaire pages carry inline.

The full picture is in the plugin README one level up.
