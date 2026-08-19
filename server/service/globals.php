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

    // Every questionnaire in the study shares this generated id, so they all
    // write into one data table; KanjiAdultsHooks::save_survey then pins
    // response_id so they merge into one row per participant.
    if (!defined('KANJI_SURVEY_GENERATED_ID')) {
        define('KANJI_SURVEY_GENERATED_ID', 'Kanji_Data');
    }

    if (!defined('transactionBy_by_kanji_adults')) {
        define('transactionBy_by_kanji_adults', 'by_kanji_adults');
    }
?>
