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
 * Three things need a hook:
 *
 * - reporting the plugin version in the CMS plugin list;
 * - merging all five questionnaires and all four task segments into one row
 *   per participant, keyed on the letter code (see save_survey / save_lab);
 * - making every write to the study's data table reach that row and land in
 *   researcher-readable columns (see save_data).
 */
class KanjiAdultsHooks extends BaseHooks
{
    /* Private Properties *****************************************************/

    /**
     * Rows already looked up during this hook call, keyed by response id.
     *
     * Hooks are instantiated per call (Hooks.php), so this lives exactly as
     * long as one save and cannot serve a stale answer to the next one.
     */
    private $row_cache = array();

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
     * This hook replaces it with an id derived from the letter code, so each
     * survey updates the same row. `save_data()` matches on it via
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
            $section = $this->hooked_section_name($args);
            $trigger = isset($data['trigger_type']) ? $data['trigger_type'] : '';

            // Opening part 1 is what starts a run, so the browser forgets
            // whoever used it before. Without this a second participant on the
            // same machine inherits the first one's identity and their consent
            // answer is written into the first one's row.
            if ($section === KANJI_SECTION_PART1 && $trigger === actionTriggerTypes_started) {
                unset($_SESSION[KANJI_RUN_SESSION_KEY]);
            }

            // The letter code identifies the participant, so the run is keyed
            // on it the moment it arrives. Only ID_1 is read: ID_2 is the same
            // code re-entered at the end so the two can be compared during
            // analysis, and a typo there must not be able to move a finished
            // run onto a code nobody was ever given.
            if (!$this->kanji_run_started() && !empty($data['ID_1']) && is_string($data['ID_1'])) {
                $this->kanji_start_run($data['ID_1']);
            }

            $pinned = $this->get_kanji_response_id();
            if ($pinned !== null) {
                $data['response_id'] = $pinned;
                // lab_js matches rows on `labjs_response_id`, and that column
                // only exists if a survey writes it first. Stamp it here so the
                // task's updateBasedOn resolves to this same row.
                $data['labjs_response_id'] = $pinned;

                // Qualtrics ran one block per language and put the language in
                // every column name (`Demo_2_DE`). One set of columns serves all
                // four here, so the language has to be recorded explicitly or it
                // is not in the data at all.
                $language = $this->kanji_user_language();
                if ($language !== null) {
                    $data[KANJI_LANGUAGE_COLUMN] = $language;
                }

                // Every save rewrites the row's trigger type, so it cannot say
                // whether a run reached the end — a participant who stops after
                // the first vignette also leaves the row on `finished`.
                //
                // Written as an explicit 0/1 rather than only on completion: a
                // column that exists only for finished runs cannot be told apart
                // from one that was never asked, which is the ambiguity this is
                // meant to resolve. Every run that got as far as answering
                // carries the column, and its value says which.
                //
                // Not stamped on `started`: that save is the row's INSERT, and
                // save_row() writes `$col_val ? $col_val : ''` (UserInput.php),
                // so a falsy 0 would be flattened to an empty string. Updates
                // pass the value through untouched, so the flag goes on from the
                // first save that carries an answer.
                if ($section === KANJI_SECTION_PART2 && $trigger === actionTriggerTypes_finished) {
                    $data[KANJI_FINISHED_COLUMN] = 1;
                } else if ($trigger !== actionTriggerTypes_started
                    && !$this->kanji_row_finished($pinned)
                ) {
                    $data[KANJI_FINISHED_COLUMN] = '0';
                }

                // surveyjs inserts unconditionally on `started`, without
                // checking for an existing row (lab_js does check). With a
                // pinned id every survey's first save would add a stray row
                // beside the participant's real one, so downgrade `started`
                // to `updated` once the row exists — that path goes through
                // updateBasedOn and merges.
                if ($trigger === actionTriggerTypes_started && $this->kanji_row_exists($pinned)) {
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
     * Make every write to this study's table reach the participant's row, and
     * rename the task's columns on the way.
     *
     * This is the last hookable point before the columns are created, and the
     * only one both plugins pass through, which makes it the place to settle
     * two things they each get wrong on their own:
     *
     * - **Ownership.** `save_row()` defaults `id_users` to whoever is logged in
     *   now and scopes its `updateBasedOn` lookup to that same value. A run that
     *   starts as guest and continues after a login therefore points at a row
     *   the lookup can no longer see: surveyjs reports "Data not saved!" and
     *   lab_js quietly starts a second row. The row belongs to the participant,
     *   not to the session, so the lookup is widened and the owner already on
     *   the row is kept.
     * - **Column names.** lab_js prefixes every key it does not recognise with
     *   `extra_data_` inside a private method, so the memory task would be
     *   stored as `extra_data_trials_recall_A`.
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
            && !isset($args['data'][0]) // a single row, not a list of them
        ) {
            $args['data'] = $this->rename_columns($args['data']);

            $pinned = $this->get_kanji_response_id();
            if ($pinned !== null) {
                $owner = $this->kanji_row_owner($pinned);
                if ($owner !== null) {
                    $args['data']['id_users'] = $owner;
                }

                // LabJSModel drops updateBasedOn on its `started` save when its
                // own user-scoped lookup comes up empty, which inserts a task
                // row of its own. Restore it whenever the participant's row is
                // already there.
                $updateBasedOn = isset($args['updateBasedOn']) ? $args['updateBasedOn'] : null;
                if (empty($updateBasedOn) && $this->kanji_row_exists($pinned)) {
                    $updateBasedOn = array('response_id' => $pinned);
                }

                // Both keys are set together on purpose. Hooks::args carries
                // only the parameters the caller actually passed, and
                // execute_private_method() re-expands them positionally, so
                // adding `own_entries_only` to a three-argument call while
                // leaving `updateBasedOn` out would hand the boolean to
                // save_data() as its updateBasedOn.
                $args['updateBasedOn'] = $updateBasedOn;
                $args['own_entries_only'] = false;
            }
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
     * Which section is saving, so the five questionnaires can be told apart.
     *
     * @param array $args
     *  Hook args; $args['hookedClassInstance'] is the surveyJS style model.
     * @return string
     *  The section name, or '' when it cannot be determined.
     */
    private function hooked_section_name($args)
    {
        if (!isset($args['hookedClassInstance'])
            || !method_exists($args['hookedClassInstance'], 'get_section_name')
        ) {
            return '';
        }
        return (string) $args['hookedClassInstance']->get_section_name();
    }

    /**
     * Has the letter code for this run already been picked up?
     *
     * @return bool
     */
    private function kanji_run_started()
    {
        return !empty($_SESSION[KANJI_RUN_SESSION_KEY]['code']);
    }

    /**
     * Key the run on the participant's letter code.
     *
     * Called once per run, on the save that first carries ID_1. The consent
     * question precedes it, so that first answer is already in a row under the
     * session-derived id — it is carried across rather than left stranded.
     *
     * The re-key can only ever move a session-keyed row, because this runs only
     * while no code is held. That is what keeps a mistyped or reused code from
     * dragging somebody else's run along with it.
     *
     * @param string $raw_code
     *  The code exactly as the participant typed it.
     * @return void
     */
    private function kanji_start_run($raw_code)
    {
        // Normalised so trivial differences in typing do not split one
        // participant across two rows.
        $code = strtoupper(preg_replace('/\s+/', '', $raw_code));
        if ($code === '') {
            return;
        }

        $previous = $this->get_kanji_response_id();
        $id = $this->kanji_resolve_run_id($code);
        if ($previous !== null && $previous !== $id && $this->kanji_row_exists($previous)) {
            if (!$this->kanji_row_exists($id)) {
                $this->kanji_rekey_row($previous, $id);
            } else {
                // Resuming: the code already has a row, so the session-keyed one
                // cannot be moved onto it without leaving two rows answering to
                // the same id. It holds nothing of its own — surveyjs posts the
                // whole survey on every save, so everything answered before the
                // code page is in the payload carrying the code — and it is
                // dropped rather than left behind as an unattributable row.
                $this->kanji_discard_row($previous);
            }
            $this->row_cache = array();
        }

        $_SESSION[KANJI_RUN_SESSION_KEY] = array('code' => $code, 'id' => $id);
    }

    /**
     * Which row this run should write to, given the code.
     *
     * A code identifies a participant, so entering it again resumes the row it
     * already opened — that is what makes a run survive a dropped session, a
     * new tab or a return the next day.
     *
     * It does not follow that a *finished* run should be reopened. The code is
     * printed on a letter addressed to a child, so two adults in one household
     * can hold the same one, and a second run would then overwrite the first
     * cell by cell. Once a run is marked finished the next one is given a row
     * of its own instead. Both rows carry the code in `ID_1`, so they group
     * during analysis; nothing is refused and nothing is overwritten.
     *
     * @param string $code
     *  The normalised letter code.
     * @return string
     *  The response id to write under.
     */
    private function kanji_resolve_run_id($code)
    {
        for ($run = 1; $run <= KANJI_MAX_RUNS; $run++) {
            $id = $this->kanji_run_id($code, $run);
            if (!$this->kanji_row_exists($id) || !$this->kanji_row_finished($id)) {
                return $id;
            }
        }
        // That many finished runs means the code is circulating rather than
        // identifying anybody. Fall back to the session, which still gives this
        // run a row of its own rather than overwriting one of the twenty.
        $session_id = $this->kanji_session_id();
        return $session_id === null ? $this->kanji_run_id($code, KANJI_MAX_RUNS) : $session_id;
    }

    /**
     * The response id for one run of one code.
     *
     * Run 1 hashes the bare code, so the id of a participant's first run does
     * not depend on this scheme existing.
     *
     * @param string $code
     *  The normalised letter code.
     * @param int $run
     *  1 for the first run under this code, 2 for the next, and so on.
     * @return string
     */
    private function kanji_run_id($code, $run)
    {
        $key = 'code|' . $code . ($run > 1 ? '#' . $run : '');
        return KANJI_RESPONSE_ID_PREFIX . substr(sha1($key), 0, 16);
    }

    /**
     * The id a run is written under before the code is known.
     *
     * @return string|null
     *  Null when there is no session to key on.
     */
    private function kanji_session_id()
    {
        if (session_status() !== PHP_SESSION_ACTIVE || session_id() === '') {
            return null;
        }
        return KANJI_RESPONSE_ID_PREFIX . substr(sha1('sess|' . session_id()), 0, 16);
    }

    /**
     * The response id every write of this run must carry.
     *
     * @return string|null
     *  The pinned id, or null if there is nothing to key on.
     */
    private function get_kanji_response_id()
    {
        if (!empty($_SESSION[KANJI_RUN_SESSION_KEY]['id'])) {
            return $_SESSION[KANJI_RUN_SESSION_KEY]['id'];
        }
        return $this->kanji_session_id();
    }

    /**
     * The participant's row, or null when they do not have one yet.
     *
     * @param string $response_id
     *  The pinned response id.
     * @return array|null
     */
    private function kanji_row($response_id)
    {
        if (array_key_exists($response_id, $this->row_cache)) {
            return $this->row_cache[$response_id];
        }

        $record = null;
        $user_input = $this->services->get_user_input();
        $id_table = $user_input->get_dataTable_id(KANJI_SURVEY_GENERATED_ID);
        if ($id_table) {
            // get_data() takes a filter string, not bound parameters. Safe here
            // because $response_id is always the prefix plus 16 hex characters
            // from kanji_run_id()/kanji_session_id(); never pass user input
            // through this.
            //
            // own_entries_only = false: the row may have been written as guest
            // and the participant may since have logged in (or the reverse), so
            // this lookup must not be scoped to the current user.
            $filter = ' AND response_id = "' . $response_id . '"';
            $record = $user_input->get_data($id_table, $filter, false, null, true);
        }

        $this->row_cache[$response_id] = empty($record) ? null : $record;
        return $this->row_cache[$response_id];
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
        return $this->kanji_row($response_id) !== null;
    }

    /**
     * Did the run in this row reach the end of part 2?
     *
     * @param string $response_id
     *  The pinned response id.
     * @return bool
     */
    private function kanji_row_finished($response_id)
    {
        $record = $this->kanji_row($response_id);
        return $record !== null && !empty($record[KANJI_FINISHED_COLUMN]);
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
        $record = $this->kanji_row($response_id);
        return empty($record['id_users']) ? null : (int) $record['id_users'];
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
     * Drop the row a run opened before its code was known.
     *
     * Only ever called on a row keyed on the current session id, and only once
     * the same run has been shown to have a row under its code. Anything the
     * discarded row held was posted again with the code, so nothing that was
     * answered is lost.
     *
     * @param string $response_id
     *  The session-derived id the row was opened under.
     * @return void
     */
    private function kanji_discard_row($response_id)
    {
        $db = $this->services->get_db();
        $sql = 'SELECT r.id FROM dataRows r'
             . ' JOIN dataTables t ON t.id = r.id_dataTables'
             . ' JOIN dataCells c ON c.id_dataRows = r.id'
             . ' JOIN dataCols col ON col.id = c.id_dataCols'
             . ' WHERE t.name = :table AND col.name = "response_id" AND c.value = :id';
        $rows = $db->query_db($sql, array(
            ':table' => KANJI_SURVEY_GENERATED_ID,
            ':id'    => $response_id,
        ));
        foreach ($rows as $row) {
            $db->execute_update_db('DELETE FROM dataCells WHERE id_dataRows = :row', array(':row' => $row['id']));
            $db->execute_update_db('DELETE FROM dataRows WHERE id = :row', array(':row' => $row['id']));
        }
    }

    /**
     * The language the participant is answering in.
     *
     * @return string|null
     *  Two-letter upper-case code, as Qualtrics wrote it (`DE`), or null when
     *  the session does not name a language.
     */
    private function kanji_user_language()
    {
        if (empty($_SESSION['language'])) {
            return null;
        }
        $language = $this->services->get_db()->fetch_language($_SESSION['language']);
        if (empty($language['locale'])) {
            return null;
        }
        return strtoupper(substr($language['locale'], 0, 2));
    }
}
?>
