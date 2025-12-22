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

namespace mod_hvp\task;

use core\task\adhoc_task;
use mod_hvp\framework;

/**
 * Update H5P library task.
 *
 * @package     mod_hvp
 * @author      Rossco Hellmans <rosscohellmans@catalyst-au.net>
 * @copyright   2025 Catalyst IT Australia Pty Ltd
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class update_library_task extends adhoc_task {
    /**
     * Get task name
     *
     * @return string
     */
    public function get_name() {
        return get_string('updatelibrarytask', 'mod_hvp');
    }

    /**
     * Execute task
     */
    public function execute() {
        global $DB;

        $data = $this->get_custom_data();
        if (empty($data->machinename)) {
            mtrace('No library set to update, ending task.');
            return;
        }

        $machinename = $data->machinename;
        $librarytitle = $data->librarytitle;
        $editor = framework::instance('editor');
        $ajax = $editor->ajax;
        \H5PCore::createToken('editorajax');

        // Look up content type to ensure it's valid(and to check permissions).
        $contenttype = $editor->ajaxInterface->getContentTypeCache($machinename);
        if (!$contenttype) {
            throw new \moodle_exception('updateinvalidcontenttype', 'mod_hvp', '', $librarytitle);
        }

        // Override core permission check.
        $ajax->core->mayUpdateLibraries(true);

        // Retrieve content type from hub endpoint.
        $endpoint = \H5PHubEndpoints::CONTENT_TYPES . $machinename;
        $url = \H5PHubEndpoints::createURL($endpoint);
        $response = $ajax->core->h5pF->fetchExternalData($url, null, true, true);
        if (!$response) {
            throw new \moodle_exception('updatedownloadfailed', 'mod_hvp', '', $librarytitle);
        };
        $path = $ajax->core->h5pF->getUploadedH5pPath();

        // Validate package.
        $validator = new \H5PValidator($ajax->core->h5pF, $ajax->core);
        if (!$validator->isValidPackage(true, true)) {
            $ajax->storage->removeTemporarilySavedFiles($path);
            throw new \moodle_exception('updatevalidationfailed', 'mod_hvp', '', $librarytitle);
        }

        // Save H5P.
        $storage = new \H5PStorage($ajax->core->h5pF, $ajax->core);
        $storage->savePackage(null, null, true);

        // Clean up.
        $ajax->storage->removeTemporarilySavedFiles($path);

        // Refresh content types.
        // Unfortunately we have to do this or else H5P will blow up.
        $context = \context_course::instance(SITEID);
        $_GET['contextId'] = $context->id;
        $librariescache = $ajax->editor->getLatestGlobalLibrariesData();

        $library = $DB->get_record('hvp_libraries_hub_cache', ['machine_name' => $machinename]);
        $version = "{$library->major_version}.{$library->minor_version}.{$library->patch_version}";
        mtrace("Successfully updated {$librarytitle} to version {$version}");
    }
}
