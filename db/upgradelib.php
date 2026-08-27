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
 * Upgrade helper definitions for the hvp module.
 *
 * @package    mod_hvp
 * @copyright  2026 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

const HVP_LIBRARY_RELEASE_STATUS_PENDING = 'pending';

/**
 * Defines the library release companion tables.
 *
 * @return xmldb_table[]
 */
function hvp_library_release_schema_tables(): array {
    $tables = [];

    $table = new xmldb_table('hvp_library_releases');
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('status', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'candidate');
    $table->add_field('parent_release_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
    $table->add_field('manifest_hash', XMLDB_TYPE_CHAR, '64', null, null, null, null);
    $table->add_field('source', XMLDB_TYPE_TEXT, null, null, null, null, null);
    $table->add_field('created_by', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('timevalidated', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
    $table->add_field('timepublished', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
    $table->add_field('failure_details', XMLDB_TYPE_TEXT, null, null, null, null, null);
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('manifest_hash', XMLDB_INDEX_UNIQUE, ['manifest_hash']);
    $table->add_index('status_timecreated', XMLDB_INDEX_NOTUNIQUE, ['status', 'timecreated']);
    $table->add_index('parent_release_id', XMLDB_INDEX_NOTUNIQUE, ['parent_release_id']);
    $tables[] = $table;

    $table = new xmldb_table('hvp_library_release_state');
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('current_release_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
    $table->add_field(
        'migrationstatus',
        XMLDB_TYPE_CHAR,
        '20',
        null,
        XMLDB_NOTNULL,
        null,
        HVP_LIBRARY_RELEASE_STATUS_PENDING
    );
    $table->add_field('migrationcursor', XMLDB_TYPE_TEXT, null, null, null, null, null);
    $table->add_field('migrationdetails', XMLDB_TYPE_TEXT, null, null, null, null, null);
    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $tables[] = $table;

    $table = new xmldb_table('hvp_library_artifacts');
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('machine_name', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null);
    $table->add_field('major_version', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, null);
    $table->add_field('minor_version', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, null);
    $table->add_field('patch_version', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, null);
    $table->add_field('metadata', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
    $table->add_field('file_tree_hash', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
    $table->add_field('legacy_library_id', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('identity', XMLDB_INDEX_UNIQUE, [
        'machine_name',
        'major_version',
        'minor_version',
        'patch_version',
    ]);
    $table->add_index('file_tree_hash', XMLDB_INDEX_NOTUNIQUE, ['file_tree_hash']);
    $table->add_index('legacy_library_id', XMLDB_INDEX_UNIQUE, ['legacy_library_id']);
    $tables[] = $table;

    $table = new xmldb_table('hvp_library_release_libraries');
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('release_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('artifact_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('release_artifact', XMLDB_INDEX_UNIQUE, ['release_id', 'artifact_id']);
    $table->add_index('artifact_id', XMLDB_INDEX_NOTUNIQUE, ['artifact_id']);
    $tables[] = $table;

    $table = new xmldb_table('hvp_library_artifact_dependencies');
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('artifact_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('required_artifact_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('dependency_type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('artifact_required_type', XMLDB_INDEX_UNIQUE, [
        'artifact_id',
        'required_artifact_id',
        'dependency_type',
    ]);
    $table->add_index('required_artifact_id', XMLDB_INDEX_NOTUNIQUE, ['required_artifact_id']);
    $tables[] = $table;

    $table = new xmldb_table('hvp_library_artifact_languages');
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('artifact_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('language_code', XMLDB_TYPE_CHAR, '31', null, XMLDB_NOTNULL, null, null);
    $table->add_field('language_json', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null);
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('artifact_language', XMLDB_INDEX_UNIQUE, ['artifact_id', 'language_code']);
    $tables[] = $table;

    $table = new xmldb_table('hvp_library_release_activity_pins');
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('hvp_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('release_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('main_artifact_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('hvp_id', XMLDB_INDEX_UNIQUE, ['hvp_id']);
    $table->add_index('release_id', XMLDB_INDEX_NOTUNIQUE, ['release_id']);
    $tables[] = $table;

    $table = new xmldb_table('hvp_library_release_content_libraries');
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('hvp_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('artifact_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('dependency_type', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, null);
    $table->add_field('drop_css', XMLDB_TYPE_INTEGER, '1', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('weight', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('content_artifact_type', XMLDB_INDEX_UNIQUE, [
        'hvp_id',
        'artifact_id',
        'dependency_type',
    ]);
    $table->add_index('hvp_id', XMLDB_INDEX_NOTUNIQUE, ['hvp_id']);
    $tables[] = $table;

    $table = new xmldb_table('hvp_library_release_builds');
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('release_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field(
        'status',
        XMLDB_TYPE_CHAR,
        '20',
        null,
        XMLDB_NOTNULL,
        null,
        HVP_LIBRARY_RELEASE_STATUS_PENDING
    );
    $table->add_field('input_source', XMLDB_TYPE_TEXT, null, null, null, null, null);
    $table->add_field('input_hash', XMLDB_TYPE_CHAR, '64', null, null, null, null);
    $table->add_field('retry_count', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('failure_details', XMLDB_TYPE_TEXT, null, null, null, null, null);
    $table->add_field('requested_by', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('release_id', XMLDB_INDEX_UNIQUE, ['release_id']);
    $table->add_index('status_timecreated', XMLDB_INDEX_NOTUNIQUE, ['status', 'timecreated']);
    $tables[] = $table;

    $table = new xmldb_table('hvp_library_release_build_items');
    $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
    $table->add_field('build_id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
    $table->add_field('source_key', XMLDB_TYPE_CHAR, '64', null, XMLDB_NOTNULL, null, null);
    $table->add_field('source_hash', XMLDB_TYPE_CHAR, '64', null, null, null, null);
    $table->add_field(
        'status',
        XMLDB_TYPE_CHAR,
        '20',
        null,
        XMLDB_NOTNULL,
        null,
        HVP_LIBRARY_RELEASE_STATUS_PENDING
    );
    $table->add_field('attempts', XMLDB_TYPE_INTEGER, '4', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('checkpoint', XMLDB_TYPE_TEXT, null, null, null, null, null);
    $table->add_field('failure_details', XMLDB_TYPE_TEXT, null, null, null, null, null);
    $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
    $table->add_field('timecompleted', XMLDB_TYPE_INTEGER, '10', null, null, null, null);
    $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
    $table->add_index('build_source', XMLDB_INDEX_UNIQUE, ['build_id', 'source_key']);
    $table->add_index('build_status', XMLDB_INDEX_NOTUNIQUE, ['build_id', 'status']);
    $tables[] = $table;

    return $tables;
}
