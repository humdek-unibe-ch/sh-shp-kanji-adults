# v1.0.0
### New feature
 - initial release: complete Kanji Adults study in a single migration
 - four pages (welcome, part 1 survey, lab.js task, part 2 survey) chained by `redirect_at_end`
 - both SurveyJS surveys and the lab.js study embedded, published and bound to their sections; no CMS import needed
 - `@asset_base` at the top of the migration sets the study image path
 - requires `sh-shp-survey_js` and `sh-shp-lab_js`
