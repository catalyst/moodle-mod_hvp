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
 * Tests for library release artifact resolution.
 *
 * @package    mod_hvp
 * @copyright  2026 Catalyst IT
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class library_release_resolver_test extends \advanced_testcase {

    /**
     * Set up tests.
     */
    public function setUp(): void {
        global $CFG;
        require "$CFG->dirroot/version.php";
        if (!empty($TOTARA)) {
            $this->markTestSkipped('mod_hvp unit tests not supported in Totara');
        }
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * A release selection resolves the patch artifact assigned to that release.
     */
    public function test_release_resolver_keeps_patch_selection_request_scoped(): void {
        $firstrelease = new library_release_resolver(11, [
            [
                'artifactid' => 101,
                'machine_name' => 'H5P.Test',
                'major_version' => 1,
                'minor_version' => 0,
                'patch_version' => 1,
            ],
        ]);
        $secondrelease = new library_release_resolver(12, [
            [
                'artifactid' => 102,
                'machine_name' => 'H5P.Test',
                'major_version' => 1,
                'minor_version' => 0,
                'patch_version' => 2,
            ],
        ]);

        $firstartifact = $firstrelease->resolve('H5P.Test', 1, 0);
        $secondartifact = $secondrelease->resolve('H5P.Test', 1, 0);

        $this->assertSame(11, $firstrelease->get_release_id());
        $this->assertSame(101, $firstartifact['artifactId']);
        $this->assertSame(1, $firstartifact['patchVersion']);
        $this->assertSame(
            library_release_artifact_storage::get_artifact_path(101),
            $firstartifact['path']
        );
        $this->assertSame(12, $secondrelease->get_release_id());
        $this->assertSame(102, $secondartifact['artifactId']);
        $this->assertSame(2, $secondartifact['patchVersion']);
        $this->assertSame(
            library_release_artifact_storage::get_artifact_path(102),
            $secondartifact['path']
        );
        $this->assertSame(101, $firstrelease->resolve('H5P.Test', 1, 0)['artifactId']);
    }

    /**
     * A release cannot contain two patches for the same legacy library identity.
     */
    public function test_release_resolver_rejects_duplicate_legacy_identity(): void {
        $this->expectException(\coding_exception::class);

        new library_release_resolver(11, [
            [
                'artifactid' => 101,
                'machine_name' => 'H5P.Test',
                'major_version' => 1,
                'minor_version' => 0,
                'patch_version' => 1,
            ],
            [
                'artifactid' => 102,
                'machine_name' => 'H5P.Test',
                'major_version' => 1,
                'minor_version' => 0,
                'patch_version' => 2,
            ],
        ]);
    }

    /**
     * Patch artifacts have isolated files and public immutable URLs.
     */
    public function test_patch_artifact_files_use_distinct_immutable_namespaces(): void {
        $storage = new library_release_artifact_storage();
        $storage->save_file(101, 'dist/library.js', 'patch one');
        $storage->save_file(102, 'dist/library.js', 'patch two');

        $firstfile = $storage->get_file(101, 'dist/library.js');
        $secondfile = $storage->get_file(102, 'dist/library.js');
        $firsturl = $storage->get_file_url(101, 'dist/library.js');
        $secondurl = $storage->get_file_url(102, 'dist/library.js');

        $this->assertSame('patch one', $firstfile->get_content());
        $this->assertSame('patch two', $secondfile->get_content());
        $this->assertSame('library_release_artifacts/101', library_release_artifact_storage::get_artifact_path(101));
        $this->assertSame('library_release_artifacts/102', library_release_artifact_storage::get_artifact_path(102));
        $this->assertStringContainsString('/mod_hvp/library_release_artifacts/101/dist/library.js', $firsturl);
        $this->assertStringContainsString('/mod_hvp/library_release_artifacts/102/dist/library.js', $secondurl);
    }

    /**
     * An artifact without published release membership cannot be served.
     */
    public function test_artifact_cannot_be_served_without_published_release_membership(): void {
        $this->assertFalse(library_release_artifact_storage::is_available_for_serving(101));
    }

    /**
     * H5P preserves a release artifact path when generating dependency assets.
     */
    public function test_h5p_core_uses_release_artifact_paths_for_dependency_assets(): void {
        $core = new \H5PCore(
            $this->createMock(\H5PFrameworkInterface::class),
            $this->createMock(\H5PFileStorage::class),
            '/pluginfile.php/1/mod_hvp',
            'en',
            false
        );
        $core->aggregateAssets = false;

        $files = $core->getDependenciesFiles([
            [
                'machineName' => 'H5P.Test',
                'majorVersion' => 1,
                'minorVersion' => 0,
                'patchVersion' => 2,
                'path' => library_release_artifact_storage::get_artifact_path(102),
                'preloadedJs' => ['dist/library.js'],
                'preloadedCss' => [],
            ],
        ]);

        $this->assertSame('/library_release_artifacts/102/dist/library.js', $files['scripts'][0]->path);
        $this->assertSame('?ver=1.0.2', $files['scripts'][0]->version);
    }

    /**
     * An artifact payload cannot be replaced in place.
     */
    public function test_artifact_file_cannot_be_overwritten(): void {
        $storage = new library_release_artifact_storage();
        $storage->save_file(101, 'dist/library.js', 'patch one');

        try {
            $storage->save_file(101, 'dist/library.js', 'replacement');
            $this->fail('Expected an exception when replacing an artifact file.');
        } catch (\coding_exception $exception) {
            $this->assertSame('patch one', $storage->get_file(101, 'dist/library.js')->get_content());
        }
    }

    /**
     * Artifact file paths must remain within their artifact namespace.
     *
     * @dataProvider invalid_artifact_path_provider
     * @param string $relativepath Invalid artifact-relative path.
     */
    public function test_artifact_file_rejects_invalid_path(string $relativepath): void {
        $this->expectException(\coding_exception::class);

        (new library_release_artifact_storage())->save_file(101, $relativepath, 'payload');
    }

    /**
     * Provides unsafe artifact-relative paths.
     *
     * @return array
     */
    public static function invalid_artifact_path_provider(): array {
        return [
            'absolute path' => ['/dist/library.js'],
            'parent traversal' => ['../library.js'],
            'nested parent traversal' => ['dist/../library.js'],
            'current directory' => ['./dist/library.js'],
            'multiple separators' => ['dist//library.js'],
            'null byte' => ["dist/\0library.js"],
        ];
    }
}
