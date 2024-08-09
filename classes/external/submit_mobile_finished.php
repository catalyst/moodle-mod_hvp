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

namespace mod_hvp\external;

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/lib/externallib.php');

// Note - context -> \core\context in 4.2+ due to MDL-74936. However, it is aliased so keep it this way
// to maintain 4.0+ compatibility.
use context;

// Note - external API has moved to namespaced classes in 4.2+ due to MDL-76583. Including externallib.php file
// aliases the classes to maintain compatibility with 4.0+.
use external_api;
use external_function_parameters;
use external_single_structure;
use external_value;

/**
 * Mobile app finish submit handler.
 *
 * @package    mod_hvp
 * @copyright  2024 Catalyst IT Australia
 * @author     Matthew Hilton <matthewhilton@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class submit_mobile_finished extends external_api {
    /**
     * Defines parameter structure
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'contextId' => new external_value(PARAM_INT, 'context id of hvp course module'),
            'score' => new external_value(PARAM_FLOAT, 'score received for hvp'),
            'maxScore' => new external_value(PARAM_FLOAT, 'max score for the hvp'),
        ]);
    }

    /**
     * Execute
     * @param int $contextid
     * @param int $score
     * @param int $maxscore
     */
    public static function execute($contextid, $score, $maxscore) {
        global $DB, $USER;

        // This is very similar to what is done via \mod_hvp\user_grades::handle_ajax();
        // But using a proper moodle WS which is properly supported inside the mobile app.

        // Check permissions.
        $context = context::instance_by_id($contextid);
        self::validate_context($context);
        require_capability('mod/hvp:saveresults', $context);

        // Find the H5P.
        $cm = get_coursemodule_from_id('hvp', $context->instanceid, 0, false, MUST_EXIST);
        $hvp = $DB->get_record('hvp', ['id' => $cm->instance], '*', MUST_EXIST);

        // Update gradebook.
        $hvp->cmidnumber = $cm->idnumber;
        $hvp->name = $cm->name;
        $hvp->rawgrade = $score;
        $hvp->rawgrademax = $maxscore;

        $grade = (object) [
            'userid' => $USER->id,
        ];

        hvp_grade_item_update($hvp, $grade);

        // Update completion.
        $course = $DB->get_record('course', ['id' => $cm->course], '*', MUST_EXIST);
        $completion = new \completion_info($course);

        if ($completion->is_enabled($cm)) {
            $completion->update_state($cm, COMPLETION_COMPLETE);
        }

        // Trigger Moodle event.
        $event = \mod_hvp\event\attempt_submitted::create([
            'context' => $context,
        ]);
        $event->trigger();

        return ['success' => true];
    }

    /**
     * Defines return structure
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'if was successful'),
        ]);
    }
}
