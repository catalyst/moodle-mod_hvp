<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Upgrade all content to latest libraries script.
 *
 * @package   mod_hvp
 * @author    Rossco Hellmans <rosscohellmans@catalyst-au.net>
 * @copyright Catalyst IT, 2021
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once('locallib.php');

$confirm = optional_param('confirm', null, PARAM_INT);

$returnurl = new moodle_url('/mod/hvp/library_list.php');
$pageurl = new moodle_url('/mod/hvp/update_libraries.php');
$PAGE->set_url($pageurl);

admin_externalpage_setup('h5plibraries');

$PAGE->set_title("{$SITE->shortname}: " . get_string('libraries', 'hvp'));

if ($confirm && confirm_sesskey()) {

    // We may need extra execution time and memory.
    core_php_time_limit::raise(HOURSECS);
    raise_memory_limit(MEMORY_EXTRA);

    $editor = mod_hvp\framework::instance('editor');
    $ajax = $editor->ajax;
    $core = $ajax->core;

    // Grab all the libraries and grab any that can be upgraded.
    $settings = [
        'commonInfo' => [
            'error' => get_string('upgradeerror', 'hvp'),
            'errorData' => get_string('upgradeerrordata', 'hvp'),
            'errorScript' => get_string('upgradeerrorscript', 'hvp'),
            'errorContent' => get_string('upgradeerrorcontent', 'hvp'),
            'errorParamsBroken' => get_string('upgradeerrorparamsbroken', 'hvp'),
            'errorLibrary' => get_string('upgradeerrormissinglibrary', 'hvp'),
            'errorTooHighVersion' => get_string('upgradeerrortoohighversion', 'hvp'),
            'errorNotSupported' => get_string('upgradeerrornotsupported', 'hvp'),
            'libraryBaseUrl' => (new moodle_url('/mod/hvp/ajax.php',
                                ['action' => 'getlibrarydataforupgrade']))->out(false) . '&library=',
            'scriptBaseUrl' => (new moodle_url('/lib/javascript.php/' . get_jsrev() . '/mod/hvp/library/js'))->out(false),
            'buster' => '',
        ],
        'libraryInfo' => [],
    ];
    $libraries = $ajax->core->h5pF->loadLibraries();
    foreach ($libraries as $versions) {
        foreach ($versions as $library) {
            $restricted = isset($library->restricted) && $library->restricted == 1;
            if (!$restricted && $library->runnable) {
                $upgrades = $core->getUpgrades($library, $versions);
                $numcontents = $core->h5pF->getNumContent($library->id);
                if (!empty($upgrades) && $numcontents > 0) {
                    $fromver = $library->major_version . '.' . $library->minor_version . '.' . $library->patch_version;
                    $tover = end($upgrades);
                    $a = (object)[
                        'from' => $library->title . ' (' . $fromver . ')',
                        'to' => $library->title . ' (' . $tover . ')',
                        'count' => $numcontents,
                    ];
                    $settings['libraryInfo'][] = [
                        'message' => get_string('upgrademessage', 'hvp', $numcontents),
                        'inProgress' => get_string('upgradebulkinprogress', 'hvp', $a),
                        'done' => get_string('upgradebulkdone', 'hvp', $a),
                        'library' => [
                            'name' => $library->machine_name,
                            'version' => $library->major_version . '.' . $library->minor_version,
                        ],
                        'versions' => $upgrades,
                        'contents' => $numcontents,
                        'infoUrl' => (new moodle_url('/mod/hvp/ajax.php', ['action' => 'libraryupgradeprogress',
                                      'library_id' => $library->id]))->out(false),
                        'total' => $numcontents,
                        'token' => \H5PCore::createToken('contentupgrade'),
                        'upgradeTo' => array_key_last($upgrades),
                    ];
                }
            }
        }
    }

    // Add JavaScripts.
    hvp_admin_add_generic_css_and_js($PAGE, $settings);
    $PAGE->requires->js('/mod/hvp/library/js/h5p-version.js', true);
    $PAGE->requires->js('/mod/hvp/js/h5p-content-upgrade-bulk.js', true);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('upgradebulkcontent', 'hvp'));

    $returnbutton = $OUTPUT->single_button($returnurl, get_string('upgradereturn', 'hvp'));
    if (empty($settings['libraryInfo'])) {
        echo get_string('upgradenothingtodo', 'hvp');
        echo $OUTPUT->box_start();
        echo $returnbutton;
        echo $OUTPUT->box_end();
    } else {
        echo html_writer::tag('div', get_string('enablejavascript', 'hvp'), ['id' => 'h5p-admin-container']);
        echo html_writer::tag('div', $returnbutton, ['id' => 'h5p-admin-return-button', 'style' => 'display: none;']);
    }
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('confirmation', 'admin'));
    $params = [
        'confirm' => 1,
        'contextId' => context_course::instance(SITEID)->id,
    ];
    $formcontinue = new single_button(new moodle_url('/mod/hvp/upgrade_all_content.php', $params), get_string('yes'));
    $formcancel = new single_button($returnurl, get_string('no'));
    echo $OUTPUT->confirm(get_string('upgradebulkcontentconfirm', 'hvp'), $formcontinue, $formcancel);
}

echo $OUTPUT->footer();
