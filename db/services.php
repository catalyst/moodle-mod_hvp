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
 * Webservice definitions for mod_hvp
 *
 * @package   mod_hvp
 * @author    Matthew Hilton <matthewhilton@catalyst-au.net>
 * @copyright Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
defined('MOODLE_INTERNAL') || die;

$functions = [
    'mod_hvp_submit_mobile_finished' => [
        'classname' => 'mod_hvp\external\submit_mobile_finished',
        'methodname' => 'execute',
        'description' => 'Used by mobile app to submit HVP completions',
        'type' => 'write',
        'ajax' => true,
        'services' => [
            MOODLE_OFFICIAL_MOBILE_SERVICE,
        ],
    ],
    'mod_hvp_log_drain' => [
        'classname' => 'mod_hvp\external\log_drain',
        'methodname' => 'execute',
        'description' => 'Used by mobile app to pass through Javascript logs',
        'type' => 'write',
        'ajax' => true,
        'services' => [
            MOODLE_OFFICIAL_MOBILE_SERVICE,
        ],
    ],
];

