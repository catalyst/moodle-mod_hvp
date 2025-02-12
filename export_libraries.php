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
function exportlibrary($library, $exporter, $tmppath, &$exportedlibraries) {
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

    // Export library.
    $exporter->h5pC->fs->exportLibrary($library, $tmppath, $exportfolder);
    $exportedlibraries[] = $library['libraryId'];

    // Now export the dependancies.
    $dependencies = [];
    $exporter->h5pC->findLibraryDependencies($dependencies, $library);

    foreach ($dependencies as $dependency) {
        exportlibrary($dependency['library'], $exporter, $tmppath, $exportedlibraries);
    }
}

/**
 * Populates an array with a list of files to zip up.
 *
 * @param string $dir
 * @param array $files
 * @param string $relative
 */
function populatefilelist($dir, &$files, $relative = '') {
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
