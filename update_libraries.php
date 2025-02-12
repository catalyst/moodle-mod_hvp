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

    $editor = mod_hvp\framework::instance('editor');
    $ajax = $editor->ajax;
    $token = \H5PCore::createToken('editorajax');

    // Update the hub cache first so we have the latest version info.
    $ajax->core->updateContentTypeCache();

    $sql = "SELECT DISTINCT lhc.machine_name, lhc.title, lhc.major_version, lhc.minor_version
              FROM {hvp_libraries_hub_cache} lhc
              JOIN {hvp_libraries} l
                ON lhc.machine_name = l.machine_name
             WHERE l.restricted = ?";
    $libraries = $DB->get_records_sql($sql, [0]);
    $libraries = array_filter($libraries, function($library) {
        global $DB;
        // Find local library with same major + minor.
        return !$DB->record_exists('hvp_libraries', [
            'machine_name' => $library->machine_name,
            'major_version' => $library->major_version,
            'minor_version' => $library->minor_version,
        ]);
    });

    $total = count($libraries);
    $counter = 0;

    foreach ($libraries as $library) {
        $progressbar->update($counter, $total, "Updating {$library->title}");
        $counter++;

        $machinename = $library->machine_name;

        // Look up content type to ensure it's valid(and to check permissions).
        $contenttype = $editor->ajaxInterface->getContentTypeCache($machinename);
        if (!$contenttype) {
            echo $OUTPUT->notification("Unable to update {$library->title}: INVALID_CONTENT_TYPE", 'error');
            break;
        }

        // Override core permission check.
        $ajax->core->mayUpdateLibraries(true);

        // Retrieve content type from hub endpoint.
        $endpoint = H5PHubEndpoints::CONTENT_TYPES . $machinename;
        $url = H5PHubEndpoints::createURL($endpoint);
        $path = $ajax->core->h5pF->getUploadedH5pPath();
        $response = $ajax->core->h5pF->fetchExternalData($url, null, true, empty($path) ? true : $path);
        if (!$response) {
            echo $OUTPUT->notification("Unable to update {$library->title}: DOWNLOAD_FAILED", 'error');
            break;
        };

        // Validate package.
        $validator = new H5PValidator($ajax->core->h5pF, $ajax->core);
        if (!$validator->isValidPackage(true, true)) {
            $ajax->storage->removeTemporarilySavedFiles($path);
            echo $OUTPUT->notification("Unable to update {$library->title}: VALIDATION_FAILED", 'error');
            break;
        }

        // Save H5P.
        $storage = new H5PStorage($ajax->core->h5pF, $ajax->core);
        $storage->savePackage(null, null, true);

        // Clean up.
        $ajax->storage->removeTemporarilySavedFiles($path);
    }

    // Refresh content types.
    $librariescache = $ajax->editor->getLatestGlobalLibrariesData();

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
