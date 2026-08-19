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

/**
 * Tests for migrating the legacy passing grade completion rule.
 *
 * @package mod_hvp
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \mod_hvp\task\migrate_completionpass
 */
class migrate_completionpass_test extends \advanced_testcase {
    public function test_migration_preserves_core_settings_and_is_not_reapplied(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $legacy = $this->getDataGenerator()->create_module('hvp', ['course' => $course->id]);
        $core = $this->getDataGenerator()->create_module('hvp', ['course' => $course->id]);
        $unrelated = $this->getDataGenerator()->create_module('page', ['course' => $course->id]);
        $DB->set_field('hvp', 'completionpass', 1, ['id' => $legacy->id]);
        $DB->set_field('course_modules', 'completionpassgrade', 1, ['id' => $core->cmid]);

        \mod_hvp\task\migrate_completionpass::migrate();

        $this->assertEquals(1, $DB->get_field('course_modules', 'completionpassgrade', ['id' => $legacy->cmid]));
        $this->assertEquals(1, $DB->get_field('course_modules', 'completionpassgrade', ['id' => $core->cmid]));
        $this->assertEquals(0, $DB->get_field('course_modules', 'completionpassgrade', ['id' => $unrelated->cmid]));
        $this->assertEquals(0, $DB->get_field('hvp', 'completionpass', ['id' => $legacy->id]));

        $DB->set_field('course_modules', 'completionpassgrade', 0, ['id' => $legacy->cmid]);
        \mod_hvp\task\migrate_completionpass::migrate();
        $this->assertEquals(0, $DB->get_field('course_modules', 'completionpassgrade', ['id' => $legacy->cmid]));
    }
}
