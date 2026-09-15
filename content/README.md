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

**The CSVs hold the full study; the committed segments do not.** `items_learn.csv`
is 30 A + 30 B + 2 practice and `items_recall.csv` is 15 A + 15 B + 1 practice —
93 trials. The shipped segments are a reduced test build of 11. Everything a
production build needs is here: the CSVs, the 165 originals in the plugin's
`assets/`, and these segments as the output shape to match.

## Worth knowing

- **A rebuild reshuffles the recall trials.** Which side holds the correct
  answer is drawn at build time and stored per row, so the assignment changes
  each time the segments are regenerated. The committed segments are the fixed
  assignment.
- **Image paths use `{{ASSET_BASE}}`.** The migration substitutes the real path,
  so write the placeholder, not a literal URL.
- **Kanji and instruction images are embedded.** lab.js carries them inside the
  segments as `embedded/<hash>` entries, so they need no upload. Only the
  questionnaire images and `kanji_labjs.css` go in `/assets`.
- **The study registers no hooks.** Each component writes to its own table and
  matches its own row on `update_based_on`; there is no PHP in this study at
  all. The `extra_data_` prefix on the task columns is lab_js' own, not
  something this study applies.
- **Part 1 cannot dedupe.** `update_based_on` is set on it like everywhere else,
  but part 1 is the page where the code is typed and so carries no `url_params`
  — nothing in the route to match against. Every open writes a row, most of them
  abandoned. Only the `finished` rows hold a code.
- **Re-running the migration is safe.** Surveys and studies are seeded only
  when absent, matched on their title (surveys) or name (labjs), so a re-run
  keeps the existing ids. Renaming a survey in the CMS breaks the match, and the
  next migration then seeds a second copy alongside it.
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
