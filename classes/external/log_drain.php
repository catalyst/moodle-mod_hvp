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

// Note - external API has moved to namespaced classes in 4.2+ due to MDL-76583. Including externallib.php file
// aliases the classes to maintain compatibility with 4.0+.
use external_api;
use external_function_parameters;
use external_multiple_structure;
use external_single_structure;
use external_value;

/**
 * Mobile app javascript log capturer.
 *
 * @package    mod_hvp
 * @copyright  2024 Catalyst IT Australia
 * @author     Matthew Hilton <matthewhilton@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class log_drain extends external_api {
    /**
     * Defines parameter structure
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        $logstructure = new external_single_structure([
            'contextId' => new external_value(PARAM_INT, 'context id of hvp course module'),
            'message' => new external_value(PARAM_TEXT, 'log data'),
            'at' => new external_value(PARAM_FLOAT, 'The timestamp this log was generated on the client'),
        ]);

        return new external_function_parameters([
            'logs' => new external_multiple_structure($logstructure)
        ]);
    }

    /**
     * Execute
     * @param array $logs array of logs sent from the mobile app client
     */
    public static function execute($logs) {
        global $USER;

        // Just dump this directly to error_log.
        foreach ($logs as $log) {
            $log = (object) $log;
            debugging("mod_hvp mobile javascript log: User: " . $USER->id . " Context: " . $log->contextId . ' at: ' . $log->at .
                " : " . $log->message);
        }

        return ['success' => true];
    }

    /**
     * Defines return structure
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'success' => new external_value(PARAM_BOOL, 'if was successful'),
        ]);
    }
}
