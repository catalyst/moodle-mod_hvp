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

namespace mod_hvp\output;

use coding_exception;
use mod_hvp\local\bundled_mobile_handler;
use mod_hvp\local\web_iframe_mobile_handler;

/**
 * Mobile output handler
 *
 * @package    mod_hvp
 * @copyright  2024 Catalyst IT Australia
 * @author     Matthew Hilton <matthewhilton@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mobile {

    /**
     * @var Default compatibility render method
     */
    public const RENDER_METHOD_WEB_IFRAME = 1;

    /**
     * @var Advanced offline render method
     */
    public const RENDER_METHOD_BUNDLED = 2;

    /**
     * @var Unset - uses the method configured at site level
     */
    public const RENDER_METHOD_UNSET = 0;

    /**
     * Is bundled mode enabled site-wide?
     * @return int one of RENDER_METHOD_*
     */
    private static function get_site_default_handler(): int {
        $val = get_config('mod_hvp', 'mobilehandler');

        // If unset, or false, use the default iframe method.
        if ($val === self::RENDER_METHOD_UNSET || $val == false) {
            return self::RENDER_METHOD_WEB_IFRAME;
        }

        return $val;
    }

    /**
     * Gets the handler for this course module and handles the request for this course module.
     * @param stdClass $cm
     * @return array
     */
    private static function handle($cm): array {
        global $DB;
        $method = (int) $DB->get_field('hvp', 'mobilerendermethod', ['id' => $cm->instance]);;

        // Unset, use site config to pick one.
        if ($method == self::RENDER_METHOD_UNSET) {
            $method = self::get_site_default_handler();
        }

        switch($method) {
            case self::RENDER_METHOD_BUNDLED:
                return (new bundled_mobile_handler($cm))->handle();
            case self::RENDER_METHOD_WEB_IFRAME:
                return (new web_iframe_mobile_handler($cm))->handle();
        }

        throw new coding_exception("No render handler specified for " . $method);
    }

    /**
     * Mobile course view handler function.
     * This function is called by webservices when the mobile app loads a module.
     * @param array $args args from the mobile app. Usually only contains 1 'cmid' key
     * @return array containing the data to send back to the app to render
     */
    public static function mobile_course_view($args): array {
        $cm = get_coursemodule_from_id('hvp', $args['cmid'], 0, false, MUST_EXIST);
        return self::handle($cm);
    }
}
