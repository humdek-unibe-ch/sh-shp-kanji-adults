<?php
/* This Source Code Form is subject to the terms of the Mozilla Public
 * License, v. 2.0. If a copy of the MPL was not distributed with this
 * file, You can obtain one at https://mozilla.org/MPL/2.0/. */
?>
<?php
require_once __DIR__ . "/../../../../component/BaseHooks.php";

/**
 * Hooks for the kanji-adults plugin.
 *
 * The questionnaires are rendered by the surveyjs plugin's `surveyJS` style
 * and the memory task by the lab_js plugin's `labJS` style, both configured
 * through the page/section rows created in server/db/v1.0.0.sql.
 *
 * Two things need a hook:
 *
 * - reporting the plugin version in the CMS plugin list;
 * - merging all five questionnaires into one row per participant, by pinning
 *   `response_id` before surveyjs saves (see save_survey below).
 */
class KanjiAdultsHooks extends BaseHooks
{
    /* Constructors ***********************************************************/

    /**
     * The constructor creates an instance of the hooks.
     *
     * @param object $services
     *  The service handler instance which holds all services.
     * @param object $params
     *  Various params.
     */
    public function __construct($services, $params = array())
    {
        parent::__construct($services, $params);
    }

    /* Public Methods *********************************************************/

    /**
     * Resolve plugins.version for this plugin (VersionModel calls with null).
     *
     * @param string|null $plugin_name
     * @return string
     */
    public function get_plugin_db_version($plugin_name = null)
    {
        return parent::get_plugin_db_version($plugin_name ?: KANJI_ADULTS_PLUGIN_NAME);
    }

    /**
     * Merge every questionnaire of this study into a single row per participant.
     *
     * All five surveys (part 1, the three vignette pauses, part 2) share one
     * `survey_generated_id`, so they already write into one data table. Rows
     * within it are matched by `response_id`, which surveyjs generates fresh per
     * survey — that would give five rows per participant.
     *
     * This hook replaces it with a value derived from the participant instead,
     * so each survey updates the same row. `save_data()` matches on it via
     * `updateBasedOn`, and the surveys share no question names, so the columns
     * simply accumulate.
     *
     * Only this study's survey is touched; every other survey on the
     * installation keeps surveyjs' own per-response id.
     *
     * @param array $args
     *  Hook args; $args['data'] is the payload surveyjs is about to save.
     * @return mixed
     *  Whatever the wrapped save_survey returns.
     */
    public function save_survey($args)
    {
        $data = isset($args['data']) ? $args['data'] : null;

        if (is_array($data)
            && isset($data['survey_generated_id'])
            && $data['survey_generated_id'] === KANJI_SURVEY_GENERATED_ID
        ) {
            // The id in force before this payload: if the code arrives now, the
            // row already opened under the session id has to be carried over
            // rather than left behind.
            $previous = $this->get_kanji_response_id();

            // The letter code identifies the participant, so remember it the
            // moment it arrives. ID_1 is asked on the first page, ID_2 on the
            // last; either pins the run.
            foreach (array('ID_1', 'ID_2') as $field) {
                if (!empty($data[$field]) && is_string($data[$field])) {
                    $_SESSION['kanji_code'] = trim($data[$field]);
                    break;
                }
            }

            // Entering the code switches the key, so move any row opened under
            // the old one across; otherwise the consent answer is stranded.
            $pinned = $this->get_kanji_response_id();
            if ($previous !== null && $pinned !== null && $previous !== $pinned
                && $this->kanji_row_exists($previous) && !$this->kanji_row_exists($pinned)
            ) {
                $this->kanji_rekey_row($previous, $pinned);
            }
            if ($pinned !== null) {
                $data['response_id'] = $pinned;
                // Pin the owner too. save_row() defaults id_users to whoever is
                // logged in *now*, while its update lookup is scoped to that same
                // value — so logging in mid-run leaves the existing row
                // unreachable and save_row() returns false, which surveyjs shows
                // as "Data not saved!". Reusing the owner already on the row
                // keeps it addressable whoever is logged in.
                $owner = $this->kanji_row_owner($pinned);
                if ($owner !== null) {
                    $data['id_users'] = $owner;
                }
                // lab_js matches rows on `labjs_response_id`, and that column
                // only exists if a survey writes it first. Stamp it here so the
                // task's updateBasedOn resolves to this same row.
                $data['labjs_response_id'] = $pinned;

                // surveyjs inserts unconditionally on `started`, without
                // checking for an existing row (lab_js does check). With a
                // pinned id every survey's first save would add a stray row
                // beside the participant's real one, so downgrade `started`
                // to `updated` once the row exists — that path goes through
                // updateBasedOn and merges.
                if (isset($data['trigger_type'])
                    && $data['trigger_type'] === actionTriggerTypes_started
                    && $this->kanji_row_exists($pinned)
                ) {
                    $data['trigger_type'] = actionTriggerTypes_updated;
                }

                $args['data'] = $data;
            }
        }

        return $this->execute_private_method($args);
    }

    /**
     * Fold the memory task into the participant's single row.
     *
     * The task writes into the same table as the questionnaires (both carry the
     * `Kanji_Data` generated id), but lab_js matches rows on `labjs_response_id`
     * while surveyjs matches on `response_id` — two different columns, so
     * without this the task would still land in a row of its own.
     *
     * Setting both to the same pinned value makes `save_data()` find the
     * participant's existing row whichever plugin is saving.
     *
     * @param array $args
     *  Hook args; $args['data'] is the payload lab_js is about to save.
     * @return mixed
     *  Whatever the wrapped save_lab returns.
     */
    public function save_lab($args)
    {
        // lab_js hands save_lab the raw POST body, so the identifying fields
        // sit under `metadata` — unlike surveyjs, which passes a flat array.
        $data = isset($args['data']) ? $args['data'] : null;

        if (is_array($data)
            && isset($data['metadata']['labjs_generated_id'])
            && $data['metadata']['labjs_generated_id'] === KANJI_SURVEY_GENERATED_ID
        ) {
            $pinned = $this->get_kanji_response_id();
            if ($pinned !== null) {
                $data['metadata']['labjs_response_id'] = $pinned;
                $args['data'] = $data;
            }
        }

        return $this->execute_private_method($args);
    }

    /**
     * Rename this study's columns to names a researcher can read.
     *
     * By the time the data reaches save_data() the column names are final:
     * lab_js has prefixed every key it does not recognise with `extra_data_`,
     * so the memory task would be stored as `extra_data_trials_recall_A`.
     * Renaming here — the last hookable point before the columns are created —
     * keeps the stored names aligned with what participants actually did, and
     * needs no change to lab_js itself.
     *
     * Scoped to this study's table; every other data table is untouched.
     *
     * @param array $args
     *  Hook args; $args['data'] is the row about to be written.
     * @return mixed
     *  Whatever the wrapped save_data returns.
     */
    public function save_data($args)
    {
        if (isset($args['table_name'])
            && $args['table_name'] === KANJI_SURVEY_GENERATED_ID
            && isset($args['data']) && is_array($args['data'])
        ) {
            $args['data'] = $this->rename_columns($args['data']);
        }
        return $this->execute_private_method($args);
    }

    /* Private Methods ********************************************************/

    /**
     * Map the raw column names onto researcher-facing ones.
     *
     * @param array $data
     *  The row about to be written.
     * @return array
     *  The row with its columns renamed.
     */
    private function rename_columns($data)
    {
        // Exact renames for the task's summary and bookkeeping columns.
        $exact = array(
            'extra_data_trials_learn_A'  => 'kanji_lernen_a',
            'extra_data_trials_learn_B'  => 'kanji_lernen_b',
            'extra_data_trials_recall_A' => 'kanji_a',
            'extra_data_trials_recall_B' => 'kanji_b',
        );

        // Internal plumbing: useful for debugging, but not study data.
        // The per-block totals are derivable from the trial JSON, so they are
        // dropped rather than stored twice.
        $drop = array('extra_data_block', 'extra_data_slice',
                      'extra_data_trials_practice',
                      'extra_data_redirect_at_end',
                      'extra_data_n_trials_practice',
                      'extra_data_n_trials_recall_A',
                      'extra_data_n_trials_recall_B',
                      'extra_data_n_correct_practice',
                      'extra_data_n_correct_recall_A',
                      'extra_data_n_correct_recall_B',
                      'extra_data_n_trials_learn_A',
                      'extra_data_n_trials_learn_B');

        $out = array();
        foreach ($data as $key => $value) {
            if (in_array($key, $drop, true)) {
                continue;
            }
            $out[isset($exact[$key]) ? $exact[$key] : $key] = $value;
        }
        return $out;
    }
    /**
     * Does the participant already have a row in the study's data table?
     *
     * @param string $response_id
     *  The pinned response id.
     * @return bool
     */
    private function kanji_row_exists($response_id)
    {
        $user_input = $this->services->get_user_input();
        $id_table = $user_input->get_dataTable_id(KANJI_SURVEY_GENERATED_ID);
        if (!$id_table) {
            return false;
        }
        // get_data() takes a filter string, not bound parameters. Safe here
        // because $response_id is always 'RJS_KANJI_' + 16 hex chars from
        // get_kanji_response_id(); never pass user input through this.
        $filter = ' AND response_id = "' . $response_id . '"';
        $record = $user_input->get_data($id_table, $filter, false, null, true);
        return !empty($record);
    }

    /**
     * Re-key a row onto the code-derived id.
     *
     * The consent page is answered before the code is entered, so that first
     * save opens a row under the session id. Rewriting both id columns moves it
     * onto the code, which is what every later save looks for.
     *
     * @param string $from
     *  The id the row currently carries.
     * @param string $to
     *  The code-derived id it should carry.
     * @return void
     */
    private function kanji_rekey_row($from, $to)
    {
        $db = $this->services->get_db();
        $sql = 'UPDATE dataCells c'
             . ' JOIN dataCols col ON col.id = c.id_dataCols'
             . ' JOIN dataRows r ON r.id = c.id_dataRows'
             . ' JOIN dataTables t ON t.id = r.id_dataTables'
             . ' SET c.value = :to'
             . ' WHERE t.name = :table'
             . '   AND col.name IN ("response_id", "labjs_response_id")'
             . '   AND c.value = :from';
        $db->execute_update_db($sql, array(
            ':to'    => $to,
            ':from'  => $from,
            ':table' => KANJI_SURVEY_GENERATED_ID,
        ));
    }

    /**
     * Which user owns the participant's row, if it exists.
     *
     * @param string $response_id
     *  The pinned response id.
     * @return int|null
     *  The owning user id, or null when there is no row yet.
     */
    private function kanji_row_owner($response_id)
    {
        $user_input = $this->services->get_user_input();
        $id_table = $user_input->get_dataTable_id(KANJI_SURVEY_GENERATED_ID);
        if (!$id_table) {
            return null;
        }
        // own_entries_only = false: the row may have been written as guest and
        // the participant may since have logged in (or the reverse), so this
        // lookup must not be scoped to the current user.
        $filter = ' AND response_id = "' . $response_id . '"';
        $record = $user_input->get_data($id_table, $filter, false, null, true);
        return empty($record['id_users']) ? null : (int) $record['id_users'];
    }

    /**
     * A response id that is stable for one participant.
     *
     * The letter code identifies the participant: it is printed on their letter,
     * entered on the first page and re-entered on the last. Keying on it means
     * the run survives whatever the browser does - a new tab, a dropped session,
     * a login part-way through, or returning later on another device. Entering
     * the same code again resumes the same row.
     *
     * Before the code is known (the consent question precedes it) the session id
     * stands in; the row is adopted as soon as the code is submitted.
     *
     * Two participants entering the same code share a row. That is intended: the
     * code is the identity, and the study would rather merge than refuse data.
     *
     * @return string|null
     *  The pinned id, or null if there is nothing to key on.
     */
    private function get_kanji_response_id()
    {
        if (!empty($_SESSION['kanji_code'])) {
            // Normalised so trivial differences in typing do not split one
            // participant across two rows.
            $code = strtoupper(preg_replace('/\s+/', '', $_SESSION['kanji_code']));
            return 'RJS_KANJI_' . substr(sha1('code|' . $code), 0, 16);
        }
        if (session_status() !== PHP_SESSION_ACTIVE || session_id() === '') {
            return null;
        }
        return 'RJS_KANJI_' . substr(sha1('sess|' . session_id()), 0, 16);
    }
}
?>
