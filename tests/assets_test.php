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
require_once($CFG->dirroot . '/mod/hvp/locallib.php');

/**
 * mod_hvp assets tests
 *
 * @package    mod_hvp
 * @author     Matthew Hilton <matthewhilton@catalyst-au.net>
 * @copyright  2022 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assets_test extends \advanced_testcase {

    /**
     * Set up tests.
     */
    public function setUp(): void {
        global $CFG;
        require "$CFG->dirroot/version.php";
        if (!empty($TOTARA)) {
            $this->markTestSkipped("mod_hvp unit tests not supported in Totara");
            return;
        }
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Tests getting hvp core assets with various wwwroots
     *
     * @covers \hvp_get_core_assets
     */
    public function test_hvp_get_core_assets() {
        global $CFG;

        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        $urls = [
            'https://example.com',
            'https://example.com/moodle',
            'https://example.com/moodle/',
            'http://example.com',
            'http://example.com/moodle',
            'http://example.com/moodle/'
        ];

        // None of these URLS should throw any kind of exception.
        foreach ($urls as $url) {
            $CFG->wwwroot = $url;
            $settings = hvp_get_core_assets($context);
            $this->assertNotEmpty($settings);
        }
    }
}
