<?php
/**
 * Regenerate server/db/v1.0.0.sql for the kanji_adults plugin.
 *
 * The migration is generated rather than hand-written because it embeds three
 * large JSON documents (two SurveyJS surveys and the lab.js study). Edit the
 * files in this folder, then re-run:
 *
 *     php content/build_migration.php
 *
 * The generated migration is a single self-contained file: it creates the
 * pages and sections, imports and publishes the content, and binds it all
 * together. Idempotent.
 */

$here = __DIR__;
$out_path = $here . '/../server/db/v1.0.0.sql';

/** SQL string literal, safe for a longtext column. */
function lit($s) {
    return "'" . str_replace(
        ['\\', "'", "\r", "\n", "\x1a"],
        ['\\\\', "\\'", '\\r', '\\n', '\\Z'],
        $s
    ) . "'";
}

function loadJson($path) {
    $j = json_decode(file_get_contents($path), true);
    if ($j === null) { fwrite(STDERR, "INVALID JSON: $path\n"); exit(1); }
    // Re-encode compactly: smaller migration, and guarantees the JSON is
    // valid for the JSON_EXTRACT calls in view_surveys.
    return json_encode($j, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

$part1 = loadJson("$here/kanji_part1.surveyjs.json");
$part2 = loadJson("$here/kanji_part2.surveyjs.json");
$study = loadJson("$here/kanji_labjs.study.json");
$css   = file_get_contents("$here/kanji_labjs.css");

// Fixed generated ids. The CMS builds these from uniqid(); fixed values keep
// the migration idempotent and let the section bindings reference them.
$SID1 = 'SVJS_KANJIADULTS01';
$SID2 = 'SVJS_KANJIADULTS02';
$LID  = 'LABJS_KANJIADULT01';

$structure = file_get_contents("$here/_structure.sql.part");

$o  = <<<HEAD
-- Kanji Adults — full install (v1.0.0)
--
-- Single self-contained migration. Creates the four study pages, imports and
-- publishes both SurveyJS surveys and the lab.js study, and binds them to
-- their sections. After this runs the study is complete — no CMS import or
-- section configuration is required.
--
-- Requires sh-shp-survey_js and sh-shp-lab_js to be installed first: this
-- references the `surveyJS` and `labJS` styles by name.
--
-- Idempotent — safe to re-run.
-- After running: clear the CMS cache (pages/sections).
--
-- GENERATED FILE — do not edit by hand. Edit the sources in content/ and
-- re-run `php content/build_migration.php`.

-- -----------------------------------------------------------------------
-- Asset base URL. Every image path in the lab.js study is built from this.
-- BASE_PATH in globals_untracked.php is '/' . PROJECT_NAME, so the served
-- prefix must include it. Change @base_path if this install differs.
-- -----------------------------------------------------------------------
SET @base_path  = '/selfhelp';
SET @asset_base = CONCAT(@base_path, '/assets/kanji');


HEAD;

$o .= $structure;

$o .= "\n\n";
$o .= "-- -----------------------------------------------------------------------\n";
$o .= "-- Study content: SurveyJS surveys\n";
$o .= "-- `config` is the working copy and `published` the live one; both are set\n";
$o .= "-- so the surveys go live immediately. view_surveys derives survey_name\n";
$o .= "-- from config.title.default.\n";
$o .= "-- -----------------------------------------------------------------------\n\n";

$o .= "SET @part1_json = " . lit($part1) . ";\n";
$o .= "SET @part2_json = " . lit($part2) . ";\n\n";

$o .= "DELETE FROM `surveys` WHERE `survey_generated_id` IN ('$SID1','$SID2');\n\n";
$o .= "INSERT INTO `surveys` (`survey_generated_id`, `config`, `published`, `published_at`)\n";
$o .= "VALUES ('$SID1', @part1_json, @part1_json, NOW()),\n";
$o .= "       ('$SID2', @part2_json, @part2_json, NOW());\n\n";
$o .= "SET @survey_part1 = (SELECT id FROM `surveys` WHERE `survey_generated_id` = '$SID1');\n";
$o .= "SET @survey_part2 = (SELECT id FROM `surveys` WHERE `survey_generated_id` = '$SID2');\n\n";

$o .= "-- -----------------------------------------------------------------------\n";
$o .= "-- Study content: lab.js memory task\n";
$o .= "-- {{ASSET_BASE}} is replaced with @asset_base so image paths resolve.\n";
$o .= "-- -----------------------------------------------------------------------\n\n";

$o .= "SET @study_json = " . lit($study) . ";\n";
$o .= "SET @study_json = REPLACE(@study_json, '{{ASSET_BASE}}', @asset_base);\n\n";

$o .= "DELETE FROM `labjs` WHERE `labjs_generated_id` = '$LID';\n\n";
$o .= "INSERT INTO `labjs` (`labjs_generated_id`, `name`, `config`)\n";
$o .= "VALUES ('$LID', 'Kanji Adults', @study_json);\n\n";
$o .= "SET @labjs_study = (SELECT id FROM `labjs` WHERE `labjs_generated_id` = '$LID');\n\n";

$o .= "-- -----------------------------------------------------------------------\n";
$o .= "-- Bind the content to the sections created above\n";
$o .= "-- -----------------------------------------------------------------------\n\n";

$o .= "SET @sec_part1 = (SELECT id FROM sections WHERE name = 'kanji-survey-part1');\n";
$o .= "SET @sec_part2 = (SELECT id FROM sections WHERE name = 'kanji-survey-part2');\n";
$o .= "SET @sec_task  = (SELECT id FROM sections WHERE name = 'kanji-task-labjs');\n\n";

foreach ([
    ['@sec_part1', 'survey-js', '@survey_part1'],
    ['@sec_part2', 'survey-js', '@survey_part2'],
    ['@sec_task',  'lab-js',    '@labjs_study'],
] as [$sec, $field, $val]) {
    $o .= "INSERT INTO `sections_fields_translation` (`id_sections`, `id_fields`, `id_languages`, `id_genders`, `content`)\n";
    $o .= "VALUES ($sec, get_field_id('$field'), '0000000001', '0000000001', $val)\n";
    $o .= "ON DUPLICATE KEY UPDATE `content` = VALUES(`content`);\n\n";
}

$o .= "-- Task styling.\n";
$o .= "SET @task_css = " . lit($css) . ";\n";
$o .= "INSERT INTO `sections_fields_translation` (`id_sections`, `id_fields`, `id_languages`, `id_genders`, `content`)\n";
$o .= "VALUES (@sec_task, get_field_id('css'), '0000000001', '0000000001', @task_css)\n";
$o .= "ON DUPLICATE KEY UPDATE `content` = VALUES(`content`);\n";

file_put_contents($out_path, $o);

printf("wrote %s (%d bytes)\n", realpath($out_path), strlen($o));
printf("  structure  : %d bytes\n", strlen($structure));
printf("  part1 json : %d bytes\n", strlen($part1));
printf("  part2 json : %d bytes\n", strlen($part2));
printf("  study json : %d bytes\n", strlen($study));
printf("  css        : %d bytes\n", strlen($css));
