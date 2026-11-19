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

use mod_hvp\grades\gradeitems;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');

/**
 * Tests for migrating the legacy passing grade completion rule.
 *
 * @package mod_hvp
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_hvp\task\migrate_completionpass
 */
class migrate_completionpass_test extends \advanced_testcase {
    public function test_migration_preserves_core_settings_and_is_not_reapplied(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/hvp/db/upgrade.php');
        $course = $this->getDataGenerator()->create_course();
        $legacy = $this->getDataGenerator()->create_module('hvp', ['course' => $course->id]);
        $core = $this->getDataGenerator()->create_module('hvp', ['course' => $course->id]);
        $unrelated = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $DB->set_field('hvp', 'completionpass', 1, ['id' => $legacy->id]);
        $DB->set_field('course_modules', 'completionpassgrade', 1, ['id' => $core->cmid]);

        \hvp_upgrade_2026090102();

        $this->assertEquals(1, $DB->get_field('course_modules', 'completionpassgrade', ['id' => $legacy->cmid]));
        $this->assertEquals(1, $DB->get_field('course_modules', 'completionpassgrade', ['id' => $core->cmid]));
        $this->assertEquals(0, $DB->get_field('course_modules', 'completionpassgrade', ['id' => $unrelated->cmid]));
        $this->assertEquals(0, $DB->get_field('hvp', 'completionpass', ['id' => $legacy->id]));

        $DB->set_field('course_modules', 'completionpassgrade', 0, ['id' => $legacy->cmid]);
        \mod_hvp\task\migrate_completionpass::migrate();
        $this->assertEquals(0, $DB->get_field('course_modules', 'completionpassgrade', ['id' => $legacy->cmid]));
    }

    /**
     * @dataProvider activity_settings_provider
     * @param int $completionpass Legacy HVP passing grade rule.
     * @param int $completionpassgrade Core passing grade rule.
     */
    public function test_migration_of_activity_settings(
        int $completionpass,
        int $completionpassgrade,
        int $completion,
        ?int $gradeitem,
        int $expectedcompletionpassgrade,
        ?int $expectedgradeitem
    ): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $hvp = $this->getDataGenerator()->create_module('hvp', ['course' => $course->id]);
        $DB->set_field('hvp', 'completionpass', $completionpass, ['id' => $hvp->id]);
        $DB->update_record('course_modules', (object) [
            'id' => $hvp->cmid,
            'completion' => $completion,
            'completionpassgrade' => $completionpassgrade,
            'completiongradeitemnumber' => $gradeitem,
        ]);

        \mod_hvp\task\migrate_completionpass::migrate();

        $cm = $DB->get_record('course_modules', ['id' => $hvp->cmid], '*', MUST_EXIST);
        $this->assertEquals($expectedcompletionpassgrade, $cm->completionpassgrade);
        if ($expectedgradeitem === null) {
            $this->assertNull($cm->completiongradeitemnumber);
        } else {
            $this->assertEquals($expectedgradeitem, $cm->completiongradeitemnumber);
        }
        $this->assertEquals(0, $DB->get_field('hvp', 'completionpass', ['id' => $hvp->id]));
    }

    public static function activity_settings_provider(): array {
        return [
            'neither rule' => [
                'completionpass' => 0,
                'completionpassgrade' => 0,
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'gradeitem' => null,
                'expectedcompletionpassgrade' => 0,
                'expectedgradeitem' => null,
            ],
            'core only' => [
                'completionpass' => 0,
                'completionpassgrade' => 1,
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'gradeitem' => null,
                'expectedcompletionpassgrade' => 1,
                'expectedgradeitem' => null,
            ],
            'legacy with no grade item' => [
                'completionpass' => 1,
                'completionpassgrade' => 0,
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'gradeitem' => null,
                'expectedcompletionpassgrade' => 1,
                'expectedgradeitem' => gradeitems::GRADE_ITEM_NUMBER,
            ],
            'both rules with no grade item' => [
                'completionpass' => 1,
                'completionpassgrade' => 1,
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'gradeitem' => null,
                'expectedcompletionpassgrade' => 1,
                'expectedgradeitem' => gradeitems::GRADE_ITEM_NUMBER,
            ],
            'legacy with grade item' => [
                'completionpass' => 1,
                'completionpassgrade' => 0,
                'completion' => COMPLETION_TRACKING_AUTOMATIC,
                'gradeitem' => gradeitems::GRADE_ITEM_NUMBER,
                'expectedcompletionpassgrade' => 1,
                'expectedgradeitem' => gradeitems::GRADE_ITEM_NUMBER,
            ],
            'manual completion' => [
                'completionpass' => 1,
                'completionpassgrade' => 0,
                'completion' => COMPLETION_TRACKING_MANUAL,
                'gradeitem' => null,
                'expectedcompletionpassgrade' => 1,
                'expectedgradeitem' => null,
            ],
            'disabled completion' => [
                'completionpass' => 1,
                'completionpassgrade' => 0,
                'completion' => COMPLETION_TRACKING_NONE,
                'gradeitem' => null,
                'expectedcompletionpassgrade' => 1,
                'expectedgradeitem' => null,
            ],
        ];
    }

    public function test_migrated_rule_requires_a_passing_grade(): void {
        global $CFG, $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $hvp = $this->getDataGenerator()->create_module('hvp', ['course' => $course->id, 'maximumgrade' => 100]);
        $DB->set_field('hvp', 'completionpass', 1, ['id' => $hvp->id]);
        $DB->update_record('course_modules', (object) [
            'id' => $hvp->cmid,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completiongradeitemnumber' => null,
        ]);
        \mod_hvp\task\migrate_completionpass::migrate();
        rebuild_course_cache($course->id, true);

        require_once($CFG->libdir . '/gradelib.php');
        $item = \grade_item::fetch([
            'courseid' => $course->id,
            'itemtype' => 'mod',
            'itemmodule' => 'hvp',
            'iteminstance' => $hvp->id,
            'itemnumber' => gradeitems::GRADE_ITEM_NUMBER,
        ]);
        $item->gradepass = 50;
        $item->update();
        $item->update_final_grade($user->id, 40, 'test');
        $cm = get_fast_modinfo($course)->get_cm($hvp->cmid);
        $completion = new \completion_info($course);
        $this->assertEquals(COMPLETION_COMPLETE_FAIL,
                $completion->internal_get_state($cm, $user->id, (object) []));

        $item->update_final_grade($user->id, 60, 'test');
        $this->assertEquals(COMPLETION_COMPLETE_PASS,
                $completion->internal_get_state($cm, $user->id, (object) []));
    }

    public function test_migration_of_course_and_site_defaults(): void {
        global $CFG, $DB, $SITE;

        $this->resetAfterTest();
        require_once($CFG->dirroot . '/mod/hvp/db/upgrade.php');
        $moduleid = $DB->get_field('modules', 'id', ['name' => 'hvp'], MUST_EXIST);
        $pageid = $DB->get_field('modules', 'id', ['name' => 'page'], MUST_EXIST);
        $course = $this->getDataGenerator()->create_course();
        $othercourse = $this->getDataGenerator()->create_course();
        $siteid = $DB->insert_record('course_completion_defaults', (object) [
            'course' => $SITE->id,
            'module' => $moduleid,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 1,
            'customrules' => json_encode(['completionpass' => 1, 'other' => 42, 'nested' => (object) []]),
        ]);
        $courseid = $DB->insert_record('course_completion_defaults', (object) [
            'course' => $course->id,
            'module' => $moduleid,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionusegrade' => 0,
            'customrules' => json_encode(['completionpass' => 1]),
        ]);
        $disabledid = $DB->insert_record('course_completion_defaults', (object) [
            'course' => $othercourse->id,
            'module' => $moduleid,
            'completionusegrade' => 1,
            'completionpassgrade' => 1,
            'customrules' => json_encode(['completionpass' => 0]),
        ]);
        $unrelatedid = $DB->insert_record('course_completion_defaults', (object) [
            'course' => $course->id,
            'module' => $pageid,
            'customrules' => json_encode(['completionpass' => 1]),
        ]);

        \mod_hvp\task\migrate_completionpass::migrate();
        \hvp_upgrade_2026090102();

        $site = $DB->get_record('course_completion_defaults', ['id' => $siteid], '*', MUST_EXIST);
        $this->assertEquals(1, $site->completionusegrade);
        $this->assertEquals(1, $site->completionpassgrade);
        $this->assertEquals((object) ['other' => 42, 'nested' => (object) []], json_decode($site->customrules));
        $course = $DB->get_record('course_completion_defaults', ['id' => $courseid], '*', MUST_EXIST);
        $this->assertEquals(1, $course->completionusegrade);
        $this->assertEquals(1, $course->completionpassgrade);
        $this->assertNull($course->customrules);
        $courseinfo = get_course($course->course);
        $module = $DB->get_record('modules', ['id' => $moduleid], '*', MUST_EXIST);
        $defaults = \core_completion\manager::get_default_completion($courseinfo, $module);
        $this->assertEquals(1, $defaults->completionusegrade);
        $this->assertEquals(1, $defaults->completionpassgrade);
        $newcourse = $this->getDataGenerator()->create_course();
        if ($CFG->branch >= 403) {
            // Courses without their own defaults use site-level defaults from Moodle 4.3.
            $defaults = \core_completion\manager::get_default_completion($newcourse, $module);
            $this->assertEquals(1, $defaults->completionpassgrade);
            $this->assertEquals(42, $defaults->other);
        }
        $disabled = $DB->get_record('course_completion_defaults', ['id' => $disabledid], '*', MUST_EXIST);
        $this->assertEquals(1, $disabled->completionpassgrade);
        $this->assertNull($disabled->customrules);
        $unrelated = $DB->get_record('course_completion_defaults', ['id' => $unrelatedid], '*', MUST_EXIST);
        $this->assertEquals(['completionpass' => 1], json_decode($unrelated->customrules, true));
    }

    public function test_migration_skips_invalid_course_defaults(): void {
        global $DB;

        $this->resetAfterTest();
        $moduleid = $DB->get_field('modules', 'id', ['name' => 'hvp'], MUST_EXIST);
        $invalidrules = [
            'malformed' => '{"completionpass": 1',
            'not an object' => '["completionpass"]',
        ];
        $invalidids = [];
        foreach ($invalidrules as $name => $customrules) {
            $invalidids[$name] = $DB->insert_record('course_completion_defaults', (object) [
                'course' => $this->getDataGenerator()->create_course()->id,
                'module' => $moduleid,
                'completionusegrade' => 0,
                'completionpassgrade' => 0,
                'customrules' => $customrules,
            ]);
        }
        $validid = $DB->insert_record('course_completion_defaults', (object) [
            'course' => $this->getDataGenerator()->create_course()->id,
            'module' => $moduleid,
            'customrules' => json_encode(['completionpass' => 1]),
        ]);

        $this->expectOutputRegex('/^(?=.*Skipping HVP completion defaults record ' . $invalidids['malformed'] . ':)' .
                '(?=.*Skipping HVP completion defaults record ' . $invalidids['not an object'] . ':)/s');
        \mod_hvp\task\migrate_completionpass::migrate();

        foreach ($invalidrules as $name => $customrules) {
            $invalid = $DB->get_record('course_completion_defaults', ['id' => $invalidids[$name]], '*', MUST_EXIST);
            $this->assertSame($customrules, $invalid->customrules, $name);
            $this->assertEquals(0, $invalid->completionusegrade, $name);
            $this->assertEquals(0, $invalid->completionpassgrade, $name);
        }
        $valid = $DB->get_record('course_completion_defaults', ['id' => $validid], '*', MUST_EXIST);
        $this->assertEquals(1, $valid->completionpassgrade);
        $this->assertNull($valid->customrules);
    }

    /**
     * @dataProvider restore_settings_provider
     * @param int $completionpass Legacy HVP passing grade rule.
     * @param int $completionpassgrade Core passing grade rule.
     */
    public function test_restore_respects_source_completion_settings(
        int $completionpass,
        int $completionpassgrade,
        ?int $gradeitem,
        int $expectedcompletionpassgrade,
        ?int $expectedgradeitem
    ): void {
        global $CFG, $DB, $USER;

        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

        $course = $this->getDataGenerator()->create_course();
        $hvp = $this->getDataGenerator()->create_module('hvp', ['course' => $course->id]);
        $DB->set_field('hvp', 'completionpass', $completionpass, ['id' => $hvp->id]);
        $DB->update_record('course_modules', (object) [
            'id' => $hvp->cmid,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionpassgrade' => $completionpassgrade,
            'completiongradeitemnumber' => $gradeitem,
        ]);

        $backup = new \backup_controller(\backup::TYPE_1ACTIVITY, $hvp->cmid, \backup::FORMAT_MOODLE,
                \backup::INTERACTIVE_NO, \backup::MODE_IMPORT, $USER->id);
        $backupid = $backup->get_backupid();
        $backup->execute_plan();
        $backup->destroy();

        $restore = new \restore_controller($backupid, $course->id, \backup::INTERACTIVE_NO,
                \backup::MODE_IMPORT, $USER->id, \backup::TARGET_CURRENT_ADDING);
        $this->assertTrue($restore->execute_precheck());
        $restore->execute_plan();
        $restoredcmid = null;
        foreach ($restore->get_plan()->get_tasks() as $task) {
            if ($task instanceof \restore_activity_task) {
                $restoredcmid = $task->get_moduleid();
                break;
            }
        }
        $restore->destroy();
        $this->assertNotNull($restoredcmid);
        $cm = $DB->get_record('course_modules', ['id' => $restoredcmid], '*', MUST_EXIST);
        $this->assertEquals($expectedcompletionpassgrade, $cm->completionpassgrade);
        if ($expectedgradeitem === null) {
            $this->assertNull($cm->completiongradeitemnumber);
        } else {
            $this->assertEquals($expectedgradeitem, $cm->completiongradeitemnumber);
        }
        $this->assertEquals(0, $DB->get_field('hvp', 'completionpass', ['id' => $cm->instance]));
    }

    public static function restore_settings_provider(): array {
        return [
            'legacy rule without grade item' => [
                'completionpass' => 1,
                'completionpassgrade' => 0,
                'gradeitem' => null,
                'expectedcompletionpassgrade' => 1,
                'expectedgradeitem' => gradeitems::GRADE_ITEM_NUMBER,
            ],
            'legacy rule with grade item' => [
                'completionpass' => 1,
                'completionpassgrade' => 0,
                'gradeitem' => gradeitems::GRADE_ITEM_NUMBER,
                'expectedcompletionpassgrade' => 1,
                'expectedgradeitem' => gradeitems::GRADE_ITEM_NUMBER,
            ],
            'core rule enabled' => [
                'completionpass' => 0,
                'completionpassgrade' => 1,
                'gradeitem' => gradeitems::GRADE_ITEM_NUMBER,
                'expectedcompletionpassgrade' => 1,
                'expectedgradeitem' => gradeitems::GRADE_ITEM_NUMBER,
            ],
            'core rule disabled' => [
                'completionpass' => 0,
                'completionpassgrade' => 0,
                'gradeitem' => gradeitems::GRADE_ITEM_NUMBER,
                'expectedcompletionpassgrade' => 0,
                'expectedgradeitem' => gradeitems::GRADE_ITEM_NUMBER,
            ],
        ];
    }
}
