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

use core\url as core_url;

defined('MOODLE_INTERNAL') || die();

/**
 * Stores immutable library release artifacts.
 *
 * @package    mod_hvp
 * @copyright  2026 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class library_release_artifact_storage {

    /** Moodle file area for immutable library release artifacts. */
    const FILEAREA = 'library_release_artifacts';

    /**
     * Gets the H5P-relative path for an artifact.
     *
     * @param int $artifactid Artifact ID.
     * @return string
     */
    public static function get_artifact_path(int $artifactid): string {
        self::validate_artifact_id($artifactid);

        return self::FILEAREA . '/' . $artifactid;
    }

    /**
     * Determines whether an artifact belongs to a published release.
     *
     * @param int $artifactid Artifact ID.
     * @return bool
     */
    public static function is_available_for_serving(int $artifactid): bool {
        global $DB;

        self::validate_artifact_id($artifactid);
        try {
            return $DB->record_exists_sql(
                'SELECT 1
                   FROM {hvp_library_release_libraries} rll
                   JOIN {hvp_library_releases} r ON r.id = rll.release_id
                  WHERE rll.artifact_id = ?
                    AND r.status = ?',
                [$artifactid, 'published']
            );
        } catch (\dml_exception $exception) {
            return false;
        }
    }

    /**
     * Saves a new file for an immutable artifact.
     *
     * @param int $artifactid Artifact ID.
     * @param string $relativepath File path relative to the artifact root.
     * @param string $content File contents.
     * @return \stored_file
     */
    public function save_file(int $artifactid, string $relativepath, string $content): \stored_file {
        list($filepath, $filename) = self::split_path($artifactid, $relativepath);
        $context = \context_system::instance();
        $fs = get_file_storage();

        if ($fs->file_exists($context->id, 'mod_hvp', self::FILEAREA, $artifactid, $filepath, $filename)) {
            throw new \coding_exception('Library release artifact files cannot be replaced.');
        }

        return $fs->create_file_from_string([
            'contextid' => $context->id,
            'component' => 'mod_hvp',
            'filearea' => self::FILEAREA,
            'itemid' => $artifactid,
            'filepath' => $filepath,
            'filename' => $filename,
        ], $content);
    }

    /**
     * Gets an artifact file.
     *
     * @param int $artifactid Artifact ID.
     * @param string $relativepath File path relative to the artifact root.
     * @return \stored_file|false
     */
    public function get_file(int $artifactid, string $relativepath) {
        list($filepath, $filename) = self::split_path($artifactid, $relativepath);
        $context = \context_system::instance();

        return get_file_storage()->get_file(
            $context->id,
            'mod_hvp',
            self::FILEAREA,
            $artifactid,
            $filepath,
            $filename
        );
    }

    /**
     * Gets the pluginfile URL for an artifact file.
     *
     * @param int $artifactid Artifact ID.
     * @param string $relativepath File path relative to the artifact root.
     * @return string
     */
    public function get_file_url(int $artifactid, string $relativepath): string {
        list($filepath, $filename) = self::split_path($artifactid, $relativepath);
        $context = \context_system::instance();

        return core_url::make_pluginfile_url(
            $context->id,
            'mod_hvp',
            self::FILEAREA,
            $artifactid,
            $filepath,
            $filename
        )->out(false);
    }

    /**
     * Validates and splits an artifact-relative path.
     *
     * @param int $artifactid Artifact ID.
     * @param string $relativepath File path relative to the artifact root.
     * @return array
     */
    private static function split_path(int $artifactid, string $relativepath): array {
        self::validate_artifact_id($artifactid);
        $cleanpath = clean_param($relativepath, PARAM_PATH);

        if ($relativepath === '' || $relativepath !== $cleanpath || $relativepath[0] === '/' ||
                substr($relativepath, 0, 2) === './' || substr($relativepath, -1) === '/') {
            throw new \coding_exception('Library release artifact file paths must be relative file paths.');
        }

        $dirname = dirname($relativepath);
        $filepath = $dirname === '.' ? '/' : '/' . $dirname . '/';

        return [$filepath, basename($relativepath)];
    }

    /**
     * Validates an artifact ID.
     *
     * @param int $artifactid Artifact ID.
     */
    private static function validate_artifact_id(int $artifactid): void {
        if ($artifactid < 1) {
            throw new \coding_exception('Library release artifact IDs must be positive integers.');
        }
    }
}
