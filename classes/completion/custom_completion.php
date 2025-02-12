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

declare(strict_types=1);

namespace mod_hvp\completion;

use core_completion\activity_custom_completion;
use grade_grade;
use grade_item;

/**
 * Activity custom completion subclass for hvp.
 *
 * Class for defining mod_hvp's custom completion rules and fetching the completion statuses
 * of the custom completion rules for a given hvp's instance and a user.
 *
 * @package    mod_hvp
 * @author     Rossco Hellmans <rosscohellmans@catalyst-au.net>
 * @copyright  2023 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {

    /**
     * Fetches the completion state for a given completion rule.
     *
     * @param string $rule The completion rule.
     * @return int The completion state.
     */
    public function get_state(string $rule): int {
        global $CFG, $DB;

        $this->validate_rule($rule);
        if ($rule == 'completionpass') {
            require_once($CFG->libdir . '/gradelib.php');
            $item = grade_item::fetch(array('courseid' => $this->cm->get_course()->id, 'itemtype' => 'mod',
                    'itemmodule' => 'hvp', 'iteminstance' => $this->cm->instance, 'outcomeid' => null));
            if ($item) {
                $grades = grade_grade::fetch_users_grades($item, array($this->userid), false);
                if (!empty($grades[$this->userid])) {
                    return $grades[$this->userid]->is_passed($item) ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
                }
            }
        }

        return COMPLETION_INCOMPLETE;
    }

    /**
     * Fetch the list of custom completion rules that this module defines.
     *
     * @return array
     */
    public static function get_defined_custom_rules(): array {
        return [
            'completionpass'
        ];
    }

    /**
     * Returns an associative array of the descriptions of custom completion rules.
     *
     * @return array
     */
    public function get_custom_rule_descriptions(): array {
        return [
            'completionpass' => get_string('completiondetail:completionpass', 'hvp'),
        ];
    }

    /**
     * Returns an array of all completion rules, in the order they should be displayed to users.
     *
     * @return array
     */
    public function get_sort_order(): array {
        return [
            'completionview',
            'completionusegrade',
            'completionpassgrade',
            'completionpass',
        ];
    }
}
