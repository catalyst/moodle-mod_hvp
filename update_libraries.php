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
 * Upgrade all libraries script.
 *
 * @package   mod_hvp
 * @author    Rossco Hellmans <rosscohellmans@catalyst-au.net>
 * @copyright Catalyst IT, 2021
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_OUTPUT_BUFFERING', true);

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once('locallib.php');

$confirm = optional_param('confirm', null, PARAM_INT);

$returnurl = new moodle_url('/mod/hvp/library_list.php');
$pageurl = new moodle_url('/mod/hvp/update_libraries.php');
$PAGE->set_url($pageurl);

admin_externalpage_setup('h5plibraries');

$PAGE->set_title("{$SITE->shortname}: " . get_string('libraries', 'hvp'));
echo $OUTPUT->header();

if ($confirm && confirm_sesskey()) {
    echo $OUTPUT->heading(get_string('updatealllibraries', 'hvp'));

    // We may need extra execution time and memory.
    core_php_time_limit::raise(HOURSECS);
    raise_memory_limit(MEMORY_EXTRA);

    $progressbar = new progress_bar();
    $progressbar->create();
    $progressbar->update(0, 1, 'Finding libraries with updates');

    // Update the hub cache first so we have the latest version info.
    $editor = mod_hvp\framework::instance('editor');
    $ajax = $editor->ajax;
    $token = \H5PCore::createToken('editorajax');
    $ajax->core->updateContentTypeCache();

    $sql = "SELECT DISTINCT lhc.machine_name, lhc.title, lhc.major_version, lhc.minor_version, lhc.patch_version
              FROM {hvp_libraries_hub_cache} lhc
              JOIN {hvp_libraries} l
                ON lhc.machine_name = l.machine_name
             WHERE l.restricted = ?";
    $libraries = $DB->get_records_sql($sql, [0]);
    $libraries = array_filter($libraries, function ($library) {
        global $DB;
        // Find local library with same major + minor.
        return !$DB->record_exists('hvp_libraries', [
            'machine_name' => $library->machine_name,
            'major_version' => $library->major_version,
            'minor_version' => $library->minor_version,
            'patch_version' => $library->patch_version,
        ]);
    });

    $total = count($libraries);
    $counter = 0;

    $queuedlibraries = [];

    foreach ($libraries as $library) {
        $machinename = $library->machine_name;
        $librarytitle = $library->title;

        $progressbar->update($counter, $total, "Creating update task for {$librarytitle}");
        $counter++;

        $updatelibrarytask = new mod_hvp\task\update_library_task();
        $updatelibrarytask->set_custom_data([
            'machinename' => $machinename,
            'librarytitle' => $librarytitle,
        ]);
        \core\task\manager::queue_adhoc_task($updatelibrarytask, true);

        $version = "{$library->major_version}.{$library->minor_version}.{$library->patch_version}";
        $queuedlibraries[$librarytitle] = $version;
    }

    if (!empty($queuedlibraries)) {
        $message = 'The following libraries have been queued for updating:';
        $message .= html_writer::start_tag('ul');
        foreach ($queuedlibraries as $librarytitle => $version) {
            $message .= html_writer::tag('li', "{$librarytitle} ({$version})");
        }
        $message .= html_writer::end_tag('ul');
    } else {
        $message = 'No libraries have been queued for updating.';
    }

    \core\notification::add($message, \core\notification::SUCCESS);

    $progressbar->update(1, 1, get_string('completed'));
    echo $OUTPUT->single_button($returnurl, get_string('upgradereturn', 'hvp'));
    $upgradeurl = new moodle_url('/mod/hvp/upgrade_all_content.php');
    echo $OUTPUT->single_button($upgradeurl, get_string('upgradebulkcontent', 'hvp'));
} else {
    echo $OUTPUT->heading(get_string('confirmation', 'admin'));
    $params = [
        'confirm' => 1,
        'contextId' => context_course::instance(SITEID)->id,
    ];
    $formcontinue = new single_button(new moodle_url('/mod/hvp/update_libraries.php', $params), get_string('yes'));
    $formcancel = new single_button($returnurl, get_string('no'));
    echo $OUTPUT->confirm(get_string('updatealllibrariesconfirm', 'hvp'), $formcontinue, $formcancel);
}

echo $OUTPUT->footer();
