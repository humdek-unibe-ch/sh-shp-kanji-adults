<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
    // Folder: sh-shp-kanji-adults → plugins.name = kanji-adults.
    if (!defined('KANJI_ADULTS_PLUGIN_NAME')) {
        define('KANJI_ADULTS_PLUGIN_NAME', 'kanji-adults');
    }

    // Page keywords created by server/db/v1.0.0.sql.
    if (!defined('KANJI_PAGE_WELCOME')) {
        define('KANJI_PAGE_WELCOME', 'kanji-adults');
    }
    if (!defined('KANJI_PAGE_SURVEY')) {
        define('KANJI_PAGE_SURVEY', 'kanji-adults-survey');
    }
    if (!defined('KANJI_PAGE_TASK')) {
        define('KANJI_PAGE_TASK', 'kanji-adults-task');
    }
    if (!defined('KANJI_PAGE_QUESTIONS')) {
        define('KANJI_PAGE_QUESTIONS', 'kanji-adults-questions');
    }

    // Section names from v1.0.0.sql: part 1 opens a run, part 2 closes it.
    if (!defined('KANJI_SECTION_PART1')) {
        define('KANJI_SECTION_PART1', 'kanji-survey-part1');
    }
    if (!defined('KANJI_SECTION_PART2')) {
        define('KANJI_SECTION_PART2', 'kanji-survey-part2');
    }

    // Every questionnaire in the study shares this generated id, so they all
    // write into one data table; KanjiAdultsHooks::save_survey then pins
    // response_id so they merge into one row per participant.
    if (!defined('KANJI_SURVEY_GENERATED_ID')) {
        define('KANJI_SURVEY_GENERATED_ID', 'Kanji_Data');
    }

    // Run identity for the visit: array('code' => ..., 'id' => ...).
    if (!defined('KANJI_RUN_SESSION_KEY')) {
        define('KANJI_RUN_SESSION_KEY', 'kanji_run');
    }

    // Prefix of every pinned response id, so a kanji row is recognisable.
    if (!defined('KANJI_RESPONSE_ID_PREFIX')) {
        define('KANJI_RESPONSE_ID_PREFIX', 'RJS_KANJI_');
    }

    // Columns the hooks add. Not Qualtrics' bare `Finished`: the export also
    // carries core's `triggerType`, which reads `finished` for a run that
    // stopped half way.
    if (!defined('KANJI_FINISHED_COLUMN')) {
        define('KANJI_FINISHED_COLUMN', 'Finished_Study');
    }
    if (!defined('KANJI_LANGUAGE_COLUMN')) {
        define('KANJI_LANGUAGE_COLUMN', 'UserLanguage');
    }

    // Finished runs one code may accumulate before it stops getting fresh rows.
    if (!defined('KANJI_MAX_RUNS')) {
        define('KANJI_MAX_RUNS', 20);
    }

    if (!defined('transactionBy_by_kanji_adults')) {
        define('transactionBy_by_kanji_adults', 'by_kanji_adults');
    }
?>
