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
 * Module test generator.
 *
 * @package    mod_hvp
 * @author     Andrew Madden <andrewmadden@catalyst-au.net>
 * @copyright  2022 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_hvp_generator extends testing_module_generator {

    /**
     * Creates an instance of the module for testing purposes.
     *
     * @param array|stdClass $record Data for module being generated. Requires 'course' key
     *     (an id or the full object). Also can have any fields from add module form.
     * @param null|array $options General options for course module. Since 2.6 it is
     *     possible to omit this argument by merging options into $record.
     * @return stdClass Record from module-defined table with additional field
     *     cmid (corresponding id in course_modules table).
     */
    public function create_instance($record = null, array $options = null) {
        global $DB;
        $record = (object)(array)$record;

        $defaultsettings = array(
            'name' => 'Test activity',
            'json_content' => '',
            'embed_type' => 'div',
            'contenttype' => null,
            'authors' => '[]',
            'source' => null,
            'year_from' => null,
            'year_to' => null,
            'license' => 'U',
            'license_version' => null,
            'changes' => '[]',
            'license_extras' => null,
            'author_comments' => null,
            'default_language' => 'en',
            'filtered' => '',
            'slug' => '',
            'synced' => null,
            'hub_id' => null,
            'a11y_title' => null,
            'h5paction' => 'create',
            'metadata' => '',
            'params' => '',
            'main_library_id' => 0,
        );

        foreach ($defaultsettings as $name => $value) {
            if (!isset($record->{$name})) {
                $record->{$name} = $value;
            }
        }

        // Check that the library exists.
        $library = $DB->get_record('hvp_libraries', ['id' => $record->main_library_id]);
        if ($library === false) {
            // Create a new test library to use.
            $library = $this->create_test_library(['machine_name' => 'test_library_' . rand(1, 9999)]);
        }
        $record->h5plibrary = $library->machine_name . ' ' . $library->major_version . '.' . $library->minor_version;

        return parent::create_instance($record, (array)$options);
    }

    /**
     * Create a test library.
     *
     * @param array $options Use to override default test library data.
     * @return \stdClass
     */
    public function create_test_library(array $options = []): \stdClass {
        global $DB;
        $defaults = [
            'machine_name' => 'test_library',
            'title' => 'Test library',
            'major_version' => '1',
            'minor_version' => '2',
            'patch_version' => '3',
            'runnable' => '1',
            'preloaded_js' => '',
            'preloaded_css' => '',
            'drop_library_css' => '',
            'semantics' => '',
            'tutorial_url' => 'https://tutorialurl.example.com',
            'add_to' => '',
            'metadata_settings' => '',
        ];

        // If the library exists matching id, just return the existing library.
        if (!empty($options['id'])) {
            $library = $DB->get_record('hvp_libraries', ['id' => $options['id']]);
            if (!empty($library)) {
                return $library;
            }
        }

        $params = array_merge($defaults, $options);
        $libraryid = $DB->insert_record('hvp_libraries', (object) $params);
        return $DB->get_record('hvp_libraries', ['id' => $libraryid]);
    }
}
