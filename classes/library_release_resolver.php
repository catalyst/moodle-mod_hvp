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

/**
 * Resolves legacy H5P library identities within one library release.
 *
 * @package    mod_hvp
 * @copyright  2026 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class library_release_resolver {

    /** @var int Library release ID. */
    private $releaseid;

    /** @var array Artifact data keyed by legacy H5P identity. */
    private $artifacts = [];

    /**
     * Creates a resolver for one release.
     *
     * @param int $releaseid Library release ID.
     * @param array $memberships Artifact memberships for the release.
     */
    public function __construct(int $releaseid, array $memberships) {
        if ($releaseid < 1) {
            throw new \coding_exception('Library release IDs must be positive integers.');
        }

        $this->releaseid = $releaseid;
        foreach ($memberships as $membership) {
            $artifact = $this->normalise_membership($membership);
            $key = $this->get_identity_key(
                $artifact['machineName'],
                $artifact['majorVersion'],
                $artifact['minorVersion']
            );

            if (isset($this->artifacts[$key])) {
                throw new \coding_exception('A library release cannot contain duplicate library identities.');
            }

            $this->artifacts[$key] = $artifact;
        }
    }

    /**
     * Gets the release selected by this resolver.
     *
     * @return int
     */
    public function get_release_id(): int {
        return $this->releaseid;
    }

    /**
     * Resolves an exact legacy H5P library identity.
     *
     * @param string $machinename H5P machine name.
     * @param int $majorversion H5P major version.
     * @param int $minorversion H5P minor version.
     * @return array|null Artifact details, or null when not a member of this release.
     */
    public function resolve(string $machinename, int $majorversion, int $minorversion): ?array {
        return $this->artifacts[$this->get_identity_key($machinename, $majorversion, $minorversion)] ?? null;
    }

    /**
     * Converts a release membership record into H5P-compatible artifact details.
     *
     * @param array|\stdClass $membership Release membership.
     * @return array
     */
    private function normalise_membership($membership): array {
        if ($membership instanceof \stdClass) {
            $membership = (array)$membership;
        }

        if (!is_array($membership)) {
            throw new \coding_exception('Library release memberships must be arrays or records.');
        }

        $artifactid = $membership['artifactid'] ?? $membership['artifact_id'] ?? null;
        $machinename = $membership['machine_name'] ?? $membership['machineName'] ?? null;
        $majorversion = $membership['major_version'] ?? $membership['majorVersion'] ?? null;
        $minorversion = $membership['minor_version'] ?? $membership['minorVersion'] ?? null;
        $patchversion = $membership['patch_version'] ?? $membership['patchVersion'] ?? null;

        if (!is_string($machinename) || $machinename === '') {
            throw new \coding_exception('Library release memberships must have a machine name.');
        }

        $artifactid = $this->normalise_integer($artifactid, 'artifact ID', 1);

        return [
            'artifactId' => $artifactid,
            'machineName' => $machinename,
            'majorVersion' => $this->normalise_integer($majorversion, 'major version', 0),
            'minorVersion' => $this->normalise_integer($minorversion, 'minor version', 0),
            'patchVersion' => $this->normalise_integer($patchversion, 'patch version', 0),
            'path' => library_release_artifact_storage::get_artifact_path($artifactid),
        ];
    }

    /**
     * Gets a stable key for a legacy H5P library identity.
     *
     * @param string $machinename H5P machine name.
     * @param int $majorversion H5P major version.
     * @param int $minorversion H5P minor version.
     * @return string
     */
    private function get_identity_key(string $machinename, int $majorversion, int $minorversion): string {
        return implode(':', [strtolower($machinename), $majorversion, $minorversion]);
    }

    /**
     * Validates an integer value with a minimum.
     *
     * @param mixed $value Value to validate.
     * @param string $name Field name.
     * @param int $minimum Minimum permitted value.
     * @return int
     */
    private function normalise_integer($value, string $name, int $minimum): int {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int)$value < $minimum) {
            throw new \coding_exception(
                "Library release membership {$name} must be an integer greater than or equal to {$minimum}."
            );
        }

        return (int)$value;
    }
}
