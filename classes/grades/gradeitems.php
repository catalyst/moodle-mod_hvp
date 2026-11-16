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

namespace mod_hvp\grades;

use core_grades\local\gradeitem\itemnumber_mapping;

/**
 * Grade item mappings for the activity.
 *
 * @package    mod_hvp
 * @copyright  2026 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class gradeitems implements itemnumber_mapping {
    /** @var int HVP activities have a single grade item. */
    public const GRADE_ITEM_NUMBER = 0;

    /**
     * Return the list of grade item mappings for HVP.
     *
     * @return array
     */
    public static function get_itemname_mapping_for_component(): array {
        return [
            self::GRADE_ITEM_NUMBER => '',
        ];
    }
}
