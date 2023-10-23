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

namespace mod_hvp\local;

use coding_exception;
use context;
use context_module;
use moodle_exception;
use stdClass;

/**
 * Common functions for hvp mobile handlers.
 *
 * @package    mod_hvp
 * @copyright  2024 Catalyst IT Australia
 * @author     Matthew Hilton <matthewhilton@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait mobile_handler {
    /**
     * @var stdClass course module
     */
    private stdClass $cm;

    /**
     * @var stdClass course
     */
    private stdClass $course;

    /**
     * @var conmtext course module context
     */
    private context $context;

    /**
     * Construct, gathering the course and context and setting them.
     * @param mixed $cm course module
     */
    private function construct($cm) {
        global $DB;
        $this->cm = (object) $cm;
        $this->course = $DB->get_record('course', ['id' => $this->cm->course], '*', MUST_EXIST);
        $this->context = context_module::instance($cm->id);
    }

    /**
     * Ensures the cmid and course exists
     * and the user can login to course and has the view capability on the course module.
     * @throws moodle_exception
     */
    private function require_capabilities() {
        if (empty($this->cm) || empty($this->course) || empty($this->context)) {
            throw new coding_exception("No course, course module, or context defined");
        }

        require_capability('mod/hvp:view', $this->context);
    }

    /**
     * Handles a mobile web request, returning the array required for the mobile app.
     * @return array
     */
    abstract public function handle(): array;
}
