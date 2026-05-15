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
 * Export all libraries script.
 *
 * @package   mod_hvp
 * @author    Rossco Hellmans <rosscohellmans@catalyst-au.net>
 * @copyright Catalyst IT, 2021
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once('locallib.php');

admin_externalpage_setup('h5plibraries');

\core_php_time_limit::raise();

$COURSE = $SITE;

$interface = mod_hvp\framework::instance('interface');
$core = mod_hvp\framework::instance('core');
$exporter = new H5PExport($interface, $core);

$libraries = $core->h5pF->loadLibraries();
$tmppath = make_request_directory();

$exportedlibraries = [];

// Add libraries to h5p.
foreach ($libraries as $versions) {
    try {
        // We only want to export the latest version.
        // H5P sorts libraries by title before version, this is normally fine but in some cases a
        // library may have a beta version with (beta) in the library title. This naming then throws
        // off the ordering, so we need to sort the array by version to ensure we get the latest version.
        usort($versions, function ($a, $b) {
            if ($a->major_version === $b->major_version) {
                // Major version is the same, sort by minor version.
                if ($a->minor_version === $b->minor_version) {
                    // Minor version is also the same, sort by patch version.
                    return $a->patch_version <=> $b->patch_version;
                }
                return $a->minor_version <=> $b->minor_version;
            }
            // Major version is different, sort by major version.
            return $a->major_version <=> $b->major_version;
        });

        $libraryinfo = array_pop($versions);
        $library = $interface->loadLibrary(
            $libraryinfo->machine_name,
            $libraryinfo->major_version,
            $libraryinfo->minor_version
        );

        exportlibrary($library, $exporter, $tmppath, $exportedlibraries);
    } catch (Exception $e) {
        throw new moodle_exception('exportlibrarieserror', 'hvp', '', null, $e->getMessage());
    }
}

$files = [];
populatefilelist($tmppath, $files);

// Get path to temporary export target file.
$tmpfile = tempnam(get_request_storage_directory(), 'hvplibs');

// Create new zip instance.
$zip = new ZipArchive();
$zip->open($tmpfile, ZipArchive::CREATE | ZipArchive::OVERWRITE);

// Add all the files from the tmp dir.
foreach ($files as $file) {
    // Please note that the zip format has no concept of folders, we must
    // use forward slashes to separate our directories.
    if (file_exists(realpath($file->absolutePath))) {
        $zip->addFile(realpath($file->absolutePath), $file->relativePath);
    }
}

// Close zip.
$zip->close();

$filename = $SITE->shortname . '_site_libraries.h5p';
try {
    // Save export.
    $exporter->h5pC->fs->saveExport($tmpfile, $filename);
} catch (Exception $e) {
    throw new moodle_exception('exportlibrarieserror', 'hvp', '', null, $e->getMessage());
}

// Now send the stored export to user.
$context = \context_course::instance($COURSE->id);
$fs = get_file_storage();
$file = $fs->get_file($context->id, 'mod_hvp', 'exports', 0, '/', $filename);
send_stored_file($file);

/**
 * Exports a library.
 *
 * @param array $library
 * @param H5PExport $exporter
 * @param string $tmppath
 * @param array $exportedlibraries
 */
function exportlibrary(array $library, H5PExport $exporter, string $tmppath, array &$exportedlibraries) {
    if (in_array($library['libraryId'], $exportedlibraries)) {
        // Already exported.
        return;
    }

    $exportfolder = null;

    // Determine path of export library.
    if (isset($exporter->h5pC) && isset($exporter->h5pC->h5pD)) {
        // Tries to find library in development folder.
        $isdevlibrary = $exporter->h5pC->h5pD->getLibrary(
            $library['machineName'],
            $library['majorVersion'],
            $library['minorVersion']
        );

        if ($isdevlibrary !== null && isset($library['path'])) {
            $exportfolder = "/" . $library['path'];
        }
    }

    // Combine this library and it's dependencies to export, no need to get dependancy
    // library's dependancies as findlibrarydependencies() is recursive.
    $dependencies = [];
    findlibrarydependencies($exporter->h5pC, $dependencies, $library);
    $libraries = array_merge(
        [$library],
        array_column($dependencies, 'library')
    );

    // Export all of the libraries.
    foreach ($libraries as $library) {
        if (in_array($library['libraryId'], $exportedlibraries)) {
            // Exact library has already been exported, move on.
            continue;
        }

        // Determine path of export library.
        $exportfolder = null;
        if (isset($exporter->h5pC) && isset($exporter->h5pC->h5pD)) {
            // Tries to find library in development folder.
            $isdevlibrary = $exporter->h5pC->h5pD->getLibrary(
                $library['machineName'],
                $library['majorVersion'],
                $library['minorVersion']
            );

            if ($isdevlibrary !== null && isset($library['path'])) {
                $exportfolder = "/" . $library['path'];
            }
        }

        // Export library.
        $exporter->h5pC->fs->exportLibrary($library, $tmppath, $exportfolder);
        $exportedlibraries[] = $library['libraryId'];
    }
}

/**
 * Populates an array with a list of files to zip up.
 *
 * @param string $dir
 * @param array $files
 * @param string $relative
 */
function populatefilelist(string $dir, array &$files, string $relative = '') {
    $strip = strlen($dir) + 1;
    $contents = glob($dir . '/' . '*');
    if (!empty($contents)) {
        foreach ($contents as $file) {
            $rel = $relative . substr($file, $strip);
            if (is_dir($file)) {
                populatefilelist($file, $files, $rel . '/');
            } else {
                $files[] = (object)[
                    'absolutePath' => $file,
                    'relativePath' => $rel,
                ];
            }
        }
    }
}

/**
 * This is an edited copy of H5PCore::findLibraryDependencies.
 * We need this edited function to ensure we get ALL version dependencies and not just
 * the first version we need as a dependancy like in H5PCore::findLibraryDependencies.
 *
 * Example: both Library1 and Library2 have a dependancy for Library3 but different versions of Library3.
 * In H5PCore we only get the first version we come acorss and miss the other version dependancy.
 * Library1-1.0
 *    - Library2-1.0
 *       -- Library3-1.2
 *    - Library3-1.0
 *
 * Recursive. Goes through the dependency tree for the given library and
 * adds all the dependencies to the given array in a flat format.
 *
 * @param $dependencies
 * @param array $library To find all dependencies for.
 * @param int $nextweight An integer determining the order of the libraries
 *  when they are loaded
 * @param bool $editor Used internally to force all preloaded sub dependencies
 *  of an editor dependency to be editor dependencies.
 * @return int
 * @see \H5PCore::findLibraryDependencies
 */
function findlibrarydependencies(\H5PCore $h5pcore, array &$dependencies, array $library, int $nextweight = 1, bool $editor = false) {
    foreach (['dynamic', 'preloaded', 'editor'] as $type) {
        $property = $type . 'Dependencies';
        if (!isset($library[$property])) {
            continue; // Skip, no such dependencies.
        }

        if ($type === 'preloaded' && $editor === true) {
            // All preloaded dependencies of an editor library is set to editor.
            $type = 'editor';
        }

        foreach ($library[$property] as $dependency) {
            // Include the major and minor version in the key so we also get the correct versions of the dependant library.
            $dependencykey = $type . '-' . $dependency['machineName'] . '-' . $dependency['majorVersion'] . '.' . $dependency['minorVersion'];
            if (isset($dependencies[$dependencykey]) === true) {
                continue; // Skip, already have this.
            }

            $dependencylibrary = $h5pcore->loadLibrary($dependency['machineName'], $dependency['majorVersion'], $dependency['minorVersion']);
            if ($dependencylibrary) {
                $dependencies[$dependencykey] = [
                    'library' => $dependencylibrary,
                    'type' => $type,
                ];
                $nextweight = findlibrarydependencies($h5pcore, $dependencies, $dependencylibrary, $nextweight, $type === 'editor');
                $dependencies[$dependencykey]['weight'] = $nextweight++;
            } else {
                // This site is missing a dependency!
                $replacements = [
                    '@dep' => \H5PCore::libraryToString($dependency),
                    '@lib' => \H5PCore::libraryToString($library),
                ];
                $h5pcore->h5pF->setErrorMessage(
                    $h5pcore->h5pF->t('Missing dependency @dep required by @lib.', $replacements),
                    'missing-library-dependency'
                );
            }
        }
    }
    return $nextweight;
}
