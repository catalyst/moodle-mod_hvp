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

namespace mod_hvp;

use mod_hvp\framework;
use mod_hvp\helper;

/**
 * This is a Moodle file.
 *
 * @package    mod_hvp
 * @author     Andrew Madden <andrewmadden@catalyst-au.net>
 * @copyright  2022 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_hvp\helper
 */
class helper_test extends \advanced_testcase {

    /** @var \component_generator_base|\default_block_generator $generator Plugin test generator. */
    private $generator;

    /**
     * Runs before every test.
     */
    public function setUp(): void {
        global $CFG;
        require "$CFG->dirroot/version.php";
        if (!empty($TOTARA)) {
            $this->markTestSkipped("mod_hvp unit tests not supported in Totara");
            return;
        }
        $this->resetAfterTest();
        $this->generator = $this->getDataGenerator()->get_plugin_generator('mod_hvp');
    }

    /**
     * Tear down after every test.
     */
    protected function tearDown(): void {
        $this->generator = null;
    }

    public function test_get_library_empty_options() {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('No existing libraries were found matching id or title provided.');
        \mod_hvp\helper::get_library([]);
    }

    public function test_get_library_no_library_found() {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('No existing libraries were found matching id or title provided.');
        \mod_hvp\helper::get_library(['id' => 99]);
    }

    public function test_get_library_with_id_and_library_found() {
        $library = $this->generator->create_test_library();
        $actuallibrary = \mod_hvp\helper::get_library(['id' => $library->id]);
        $this->assertEquals($library->id, $actuallibrary->id);
        $this->assertEquals($library->machine_name, $actuallibrary->machine_name);
        $this->assertEquals($library->title, $actuallibrary->title);
        $this->assertEquals($library->major_version, $actuallibrary->major_version);
        $this->assertEquals($library->minor_version, $actuallibrary->minor_version);
        $this->assertEquals($library->patch_version, $actuallibrary->patch_version);
    }

    public function test_get_library_with_title_and_invalid_version_format() {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('Version format is not valid. Must be \'major.minor.patches\'. E.g. \'2.4.1\'');
        \mod_hvp\helper::get_library(['title' => 'Test title', 'version' => '1-2-3']);
    }

    public function test_get_library_with_title_and_multiple_libraries_found() {
        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage('Multiple libraries were found using provided filters. Try using an id.');
        $library1 = $this->generator->create_test_library([
            'machine_name' => 'test_library_one',
        ]);
        $library2 = $this->generator->create_test_library([
            'machine_name' => 'test_library_two',
        ]);
        \mod_hvp\helper::get_library(['title' => 'Test library', 'version' => '1.2.3']);
    }

    public function test_get_library_with_title_and_library_found() {
        $library = $this->generator->create_test_library([
            'title' => 'Test library',
            'major_version' => '1',
            'minor_version' => '2',
            'patch_version' => '3',
        ]);
        $actuallibrary = \mod_hvp\helper::get_library([
            'title' => 'Test library',
            'version' => '1.2.3',
        ]);
        $this->assertEquals($library->id, $actuallibrary->id);
        $this->assertEquals($library->machine_name, $actuallibrary->machine_name);
        $this->assertEquals($library->title, $actuallibrary->title);
        $this->assertEquals($library->major_version, $actuallibrary->major_version);
        $this->assertEquals($library->minor_version, $actuallibrary->minor_version);
        $this->assertEquals($library->patch_version, $actuallibrary->patch_version);
    }

    public function test_get_dependant_libraries_with_invalid_library() {
        $titles = \mod_hvp\helper::get_dependant_libraries((object) ['id' => 99]);
        $this->assertEmpty($titles);
    }

    public function test_get_dependant_libraries_with_no_results() {
        $library = $this->generator->create_test_library();
        $titles = \mod_hvp\helper::get_dependant_libraries((object) ['id' => $library->id]);
        $this->assertEmpty($titles);
    }

    public function test_get_dependant_libraries_with_results() {
        $library = $this->generator->create_test_library();
        $dependents = [
            $this->generator->create_test_library(['machine_name' => 'preloaded_dependant_one']),
            $this->generator->create_test_library(['machine_name' => 'preloaded_dependant_two']),
            $this->generator->create_test_library(['machine_name' => 'preloaded_dependant_three']),
        ];
        $librarydependency = $this->convert_record_to_dependency_format($library);
        foreach ($dependents as $dependent) {
            $this->create_test_library_dependencies($dependent->id, ['preloadedDependencies' => [$librarydependency]]);
        }
        $titles = \mod_hvp\helper::get_dependant_libraries((object) ['id' => $library->id]);
        $this->assertCount(3, $titles);
    }

    public function test_remove_dependant_activities_with_no_activities() {
        $library = $this->generator->create_test_library();
        $this->assertEquals(0, helper::remove_dependant_activities($library));
    }

    public function test_remove_dependant_activities_with_activities_and_dryrun() {
        $library = $this->generator->create_test_library();
        $course = $this->getDataGenerator()->create_course();
        $hvp1 = $this->getDataGenerator()->get_plugin_generator('mod_hvp')->create_instance([
            'course' => $course->id,
            'main_library_id' => $library->id,
        ]);
        $hvp2 = $this->getDataGenerator()->get_plugin_generator('mod_hvp')->create_instance([
            'course' => $course->id,
            'main_library_id' => $library->id,
        ]);
        $this->assertEquals(2, helper::remove_dependant_activities($library));
    }

    public function test_remove_dependant_activities_with_activities() {
        $library = $this->generator->create_test_library();
        $course = $this->getDataGenerator()->create_course();
        $hvp1 = $this->getDataGenerator()->get_plugin_generator('mod_hvp')->create_instance([
            'course' => $course->id,
            'main_library_id' => $library->id,
        ]);
        $hvp2 = $this->getDataGenerator()->get_plugin_generator('mod_hvp')->create_instance([
            'course' => $course->id,
            'main_library_id' => $library->id,
        ]);
        $this->assertEquals(2, helper::remove_dependant_activities($library, false));
    }

    /**
     * Get a test library.
     *
     * @param array $options Use to override default test library data.
     * @return \stdClass
     */
    private function get_test_library(array $options = []): \stdClass {
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
        $params = array_merge($defaults, $options);
        $libraryid = $DB->insert_record('hvp_libraries', (object) $params);
        return $DB->get_record('hvp_libraries', ['id' => $libraryid]);
    }

    /**
     * Create test library dependencies. Expects list of dependencies sorted into preloadedDependencies,
     * dynamicDependencies or editorDependencies depending on the dependency type.
     *
     * @param int $libraryid Library ID.
     * @param array $dependencies
     */
    private function create_test_library_dependencies(int $libraryid, array $dependencies = []) {
        $framework = framework::instance('interface');
        if (isset($dependencies['preloadedDependencies'])) {
            $framework->saveLibraryDependencies($libraryid, $dependencies['preloadedDependencies'], 'preloaded');
        }
        if (isset($dependencies['dynamicDependencies'])) {
            $framework->saveLibraryDependencies($libraryid, $dependencies['dynamicDependencies'], 'dynamic');
        }
        if (isset($dependencies['editorDependencies'])) {
            $framework->saveLibraryDependencies($libraryid, $dependencies['editorDependencies'], 'editor');
        }
    }

    /**
     * Convert a test library object into format expected by $h5pframework->saveLibraryDependencies().
     *
     * @param \stdClass $library Library record.
     * @return array
     */
    private function convert_record_to_dependency_format(\stdClass $library): array {
        $mappings = [
            'machine_name' => 'machineName',
            'major_version' => 'majorVersion',
            'minor_version' => 'minorVersion',
        ];
        $dependency = [];
        foreach ($library as $key => $value) {
            if (array_key_exists($key, $mappings)) {
                $dependency[$mappings[$key]] = $value;
            } else {
                $dependency[$key] = $value;
            }
        }
        return $dependency;
    }
}
