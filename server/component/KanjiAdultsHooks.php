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
 * The study itself needs no hooks: the questionnaires are rendered by the
 * surveyjs plugin's `surveyJS` style and the memory task by the lab_js
 * plugin's `labJS` style, both configured entirely through the page/section
 * rows created in server/db/v1.0.0.sql.
 *
 * This class exists so the plugin reports its version in the CMS plugin list
 * (VersionModel calls get_plugin_db_version with null).
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
}
?>
