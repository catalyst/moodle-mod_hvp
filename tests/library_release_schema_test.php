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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/hvp/db/upgrade.php');

/**
 * Tests for library release schema definitions.
 *
 * @package    mod_hvp
 * @copyright  2026 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class library_release_schema_test extends \advanced_testcase {

    /**
     * Set up tests.
     */
    public function setUp(): void {
        global $CFG;
        require "$CFG->dirroot/version.php";
        if (!empty($TOTARA)) {
            $this->markTestSkipped('mod_hvp unit tests not supported in Totara');
        }
        $this->resetAfterTest();
    }

    /**
     * Library releases use companion tables with the required indexes.
     */
    public function test_install_schema_defines_library_release_tables(): void {
        global $CFG, $DB;

        $DB->get_manager();
        $file = new \xmldb_file($CFG->dirroot . '/mod/hvp/db/install.xml');
        $this->assertTrue($file->loadXMLStructure());
        $structure = $file->getStructure();

        $tables = [
            'hvp_library_releases' => [
                'fields' => ['status', 'parent_release_id', 'manifest_hash', 'failure_details'],
                'indexes' => ['manifest_hash' => true, 'status_timecreated' => false],
            ],
            'hvp_library_release_state' => [
                'fields' => ['current_release_id', 'migrationstatus', 'migrationcursor', 'migrationdetails'],
                'indexes' => [],
            ],
            'hvp_library_artifacts' => [
                'fields' => ['machine_name', 'patch_version', 'metadata', 'file_tree_hash', 'legacy_library_id'],
                'indexes' => ['identity' => true, 'file_tree_hash' => false, 'legacy_library_id' => true],
            ],
            'hvp_library_release_libraries' => [
                'fields' => ['release_id', 'artifact_id'],
                'indexes' => ['release_artifact' => true, 'artifact_id' => false],
            ],
            'hvp_library_artifact_dependencies' => [
                'fields' => ['artifact_id', 'required_artifact_id', 'dependency_type'],
                'indexes' => ['artifact_required_type' => true, 'required_artifact_id' => false],
            ],
            'hvp_library_artifact_languages' => [
                'fields' => ['artifact_id', 'language_code', 'language_json'],
                'indexes' => ['artifact_language' => true],
            ],
            'hvp_library_release_activity_pins' => [
                'fields' => ['hvp_id', 'release_id', 'main_artifact_id'],
                'indexes' => ['hvp_id' => true, 'release_id' => false],
            ],
            'hvp_library_release_content_libraries' => [
                'fields' => ['hvp_id', 'artifact_id', 'dependency_type', 'drop_css', 'weight'],
                'indexes' => ['content_artifact_type' => true, 'hvp_id' => false],
            ],
            'hvp_library_release_builds' => [
                'fields' => ['release_id', 'status', 'input_hash', 'failure_details'],
                'indexes' => ['release_id' => true, 'status_timecreated' => false],
            ],
            'hvp_library_release_build_items' => [
                'fields' => ['build_id', 'source_key', 'status', 'checkpoint', 'failure_details'],
                'indexes' => ['build_source' => true, 'build_status' => false],
            ],
        ];

        foreach ($tables as $tablename => $definition) {
            $table = $structure->getTable($tablename);
            $this->assertNotNull($table, "Missing {$tablename}.");

            foreach ($definition['fields'] as $fieldname) {
                $this->assertNotNull($table->getField($fieldname), "Missing {$tablename}.{$fieldname}.");
            }

            foreach ($definition['indexes'] as $indexname => $unique) {
                $index = $table->getIndex($indexname);
                $this->assertNotNull($index, "Missing {$tablename}.{$indexname} index.");
                $this->assertSame($unique, $index->getUnique(), "Incorrect {$tablename}.{$indexname} uniqueness.");
            }
        }

        $this->assertNull($structure->getTable('hvp_libraries')->getField('staged'));
    }

    /**
     * The schema upgrade creates one resumable legacy migration state record.
     */
    public function test_upgrade_initialises_legacy_migration_state(): void {
        global $DB;

        $DB->delete_records('hvp_library_release_state');
        hvp_upgrade_2026082600();
        hvp_upgrade_2026082600();

        $state = $DB->get_record('hvp_library_release_state', ['id' => 1], '*', MUST_EXIST);
        $this->assertSame(\HVP_LIBRARY_RELEASE_STATUS_PENDING, $state->migrationstatus);
        $this->assertEmpty($state->current_release_id);
        $this->assertCount(1, $DB->get_records('hvp_library_release_state'));

        $state->migrationstatus = 'running';
        $state->migrationcursor = 'hvp:12';
        $DB->update_record('hvp_library_release_state', $state);
        hvp_upgrade_2026082600();

        $state = $DB->get_record('hvp_library_release_state', ['id' => 1], '*', MUST_EXIST);
        $this->assertSame('running', $state->migrationstatus);
        $this->assertSame('hvp:12', $state->migrationcursor);
    }
}
