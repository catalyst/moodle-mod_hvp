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

namespace mod_hvp\local;

use context_module;
use context_system;
use external_util;
use js_writer;
use mod_hvp\view_assets;
use moodle_url;
use stored_file;

/**
 * Bundled mobile handler.
 * Bundles all assets together with bootstrapping script to allow it to be cached by the mobile app
 * to be able to run completely offline.
 *
 * @package    mod_hvp
 * @copyright  2024 Catalyst IT Australia
 * @author     Matthew Hilton <matthewhilton@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bundled_mobile_handler {
    use mobile_handler;

    /**
     * Construct
     * @param object $cm coursemodule
     */
    public function __construct(object $cm) {
        $this->construct($cm);
        $this->require_capabilities();
    }

    /**
     * Determines if the log drain functionality is enabled for this user
     * @return bool
     */
    private static function is_log_drain_enabled(): bool {
        global $USER;
        $enabledforusers = explode(',', get_config('mod_hvp', 'mobiledebugging'));
        return in_array($USER->id, $enabledforusers);
    }

    /**
     * Handle mobile app request
     * @return array response for mobile webservice
     */
    public function handle(): array {
        global $CFG, $OUTPUT;

        require_once($CFG->libdir . '/completionlib.php');
        require_once($CFG->libdir . '/externallib.php');

        // The core h5p font is not normally stored, but for the app to cache
        // it it must be stored, so we store it ourselves.
        $this->ensure_h5p_core_fonts_stored();

        require_once($CFG->dirroot . '/mod/hvp/locallib.php');
        $view = new \mod_hvp\view_assets($this->cm, $this->course);

        // Because the mobile app using the advanced handler never hits any normal hvp pages.
        // We need to manually log it as being viewed for the completion api.
        $view->logviewed();

        // Collate CSS and JS files for the h5p.
        $hvpcss = $this->get_view_css($view);
        $hvpjs = $this->get_core_h5p_js();
        $hvpjs .= $this->get_view_js($view);

        $corejs = $this->get_h5p_integration_var($view);
        $corejs .= $this->get_core_h5p_js();

        $middlewarejs = $this->get_middleware_js();
        $overloadjs = $this->get_overload_js();

        // Extract font files and turn them into external files so the app can cache them.
        $fontmap = $this->extract_fontfiles_from_css($hvpcss);
        $fontmap = array_map(fn($file) => self::file_to_externalfile($file), $fontmap);

        // Get file urls, so the plugin js bootstrapper can cache them itself.
        $files = array_values(array_merge($this->get_content_files(), $fontmap));
        $fileurls = array_map(fn($file) => $file['fileurl'], $files);

        // Let the JS know what file maps to what font family.
        $fontfamilymap = array_map(fn($externalfile) => $externalfile['fileurl'], $fontmap);

        // Generate a unique id to avoid race conditions from quick hvp->hvp page loads.
        $uniqueid = uniqid();

        // Start building the main JS that will be cached by the app.
        $js = '';

        // Set various variables required by the offline bootstrapper.
        // Put these on the window so it's easier to debug/inspect.
        $jsdata = [
            'corejs' => $corejs,
            'middlewarejs' => $middlewarejs,
            'hvpjs' => $hvpjs,
            'overloadjs' => $overloadjs,
            'id' => $this->cm->instance,
            'css' => $hvpcss,
            'contextid' => $this->context->id,
            'logdrainenabled' => self::is_log_drain_enabled(),
            'files' => $fileurls,
            'fontmap' => $fontfamilymap,
            'selectors' => [
                'iframe' => 'hvp-mobile-iframe-' . $uniqueid,
                'gradesyncnotification' => 'hvp-grade-sync-notification-' . $uniqueid,
                'loadingnotification' => 'hvp-loading-notification-' . $uniqueid,
            ]
        ];
        $js .= 'window.' . js_writer::set_variable('hvp', $jsdata, false);

        // Add MutationObserver polyfill for mobile (mobile doesn't support it, but browser simulator does).
        $js .= file_get_contents($CFG->dirroot . '/mod/hvp/MutationObserver.js');

        // Finally add the bootstrapping script, which will actually load everything properly.
        $js .= file_get_contents($CFG->dirroot . '/mod/hvp/hvpiframebootstrap.js');

        // Build the data to go into the template.
        // Note any arrays MUST be array_values, to make them ordered sequential keys, otherwise mustache explodes.
        $data = [];
        $data['h5pid'] = $this->cm->instance;
        $data['selectors'] = $jsdata['selectors'];

        return [
            'templates'  => [
                [
                    'id'   => 'main',
                    'html' => $OUTPUT->render_from_template('mod_hvp/bundled_mobile_view_page', $data),
                ],
            ],
            'javascript' => $js,
            'files' => $files,
        ];
    }

    /**
     * Returns the middleware js.
     * This is the JS that runs after H5P core has started, but the content type has not been executed yet.
     * @return string
     */
    private function get_middleware_js(): string {
        global $CFG;
        return file_get_contents($CFG->dirroot . '/mod/hvp/hvpofflinemiddleware.js');
    }

    /**
     * Returns the overload js
     * This is the JS that is placed at the very end, after the H5P js.
     * This allows us to overload certain H5P functions.
     * @return string
     */
    private function get_overload_js(): string {
        global $CFG;
        return file_get_contents($CFG->dirroot . '/mod/hvp/hvpoverload.js');
    }

    /**
     * Returns a string which contains jquery as well as the h5p core javascript.
     * @return string
     */
    private function get_core_h5p_js(): string {
        global $CFG;

        $h5pcorejs = '';

        // For some reason, the core jquery h5p needs to be included two times.
        // However to include it twice, we need to split it,
        // because it also has a tiny bit of H5P code that references jquery that depends on it.
        $jquery = file_get_contents($CFG->dirroot . '/mod/hvp/library/js/jquery.js');

        list($copyright, $jqlib, $hvpjquery) = explode("\n", $jquery, 3);

        // For some reason, the browser thinks that 'window' is undefined (it isn't),
        // and throws a syntax error without actually trying to run the code.
        // By wrapping these in a try/catch, it seems to trick the browser into not caring
        // and then it works (since window is actually defined, silly browser).
        $h5pcorejs .= "try { " . $jqlib . "} catch(e) { window.console.log(e); };";
        $h5pcorejs .= "try { " . $jqlib . "} catch(e) { window.console.log(e); };";
        $h5pcorejs .= "try { " . $hvpjquery . "} catch (e) { window.console.log(e); };";

        // Load each of the H5P core scripts.
        foreach (\H5PCore::$scripts as $script) {

            // Skip jquery libs, since we loaded them seperately above.
            if (strpos($script, "jquery") != false) {
                continue;
            }

            $contents = file_get_contents($CFG->dirroot . '/mod/hvp/library/' . $script);

            if ($contents != false) {
                $flag = "window.console.log('mod_hvp mobile: Loading script " . $script . "');";
                $h5pcorejs .= $flag . $contents;
            }
        }

        return $h5pcorejs;
    }

    /**
     * Returns a string containing the entire CSS for the given view
     * @param view_assets $view
     * @return string
     */
    public function get_view_css(view_assets $view): string {
        global $CFG;

        // Load the css for the content.
        $rawcss = '';

        // Core H5P styles - same for every H5P.
        foreach (\H5PCore::$styles as $style) {
            $contents = file_get_contents($CFG->dirroot . '/mod/hvp/library/' . $style);
            $rawcss .= $contents;
        }

        // Load H5P specific styles.
        $files = $view->getdependencyfiles();
        $scriptpaths = array_column($files['styles'], 'path');

        // Find the filenames.
        $filenames = array_map(function($scriptpath) {
            $exploded = explode("/", $scriptpath);
            return array_pop($exploded);
        }, $scriptpaths);

        // Load these files from the moodle fs.
        $fs = get_file_storage();

        foreach ($filenames as $filename) {
            $file = $fs->get_file(context_system::instance()->id, 'mod_hvp', 'cachedassets', 0, '/', $filename);

            if ($file) {
                $rawcss .= $file->get_content();
            }
        }

        // Add an additional block to ensure everything is rendered in a sans-serif font.
        // otherwise the default font may be used which is Roboto (sans-serif) for Android, and a NY (serif) for IOS.
        $rawcss .= "
            body {
                font-family: sans-serif;
            }
        ";

        // This is a hack/fix for images within a branching scenario activity on IOS.
        // For some reason, the height: 100% of the img on ios fights the resizer and
        // causes the image to expand height infinitely, distorting the image in the process.
        // Setting a max-height appears to fix it.
        $rawcss .= "
            img {
                max-width: 100%;
                max-height: 100%;
                height: inherit !important;
            }
        ";

        return $rawcss;
    }

    /**
     * Stores the core h5p fonts if it is not stored into the file system.
     */
    private function ensure_h5p_core_fonts_stored() {
        global $CFG;

        $corefonts = [
            'h5p-core-28.ttf',
            'h5p-core-29.ttf',
            'h5p-core-30.ttf',
        ];

        $fs = get_file_storage();

        foreach ($corefonts as $font) {
            $record = (object) [
                'contextid' => context_system::instance()->id,
                'component' => 'mod_hvp',
                'filearea' => 'libraries',
                'itemid' => 0,
                'filepath' => '/mobile_fonts/',
                'filename' => $font,
            ];

            if ($fs->file_exists($record->contextid, $record->component, $record->filearea, $record->itemid,
                $record->filepath, $record->filename)) {
                continue;
            }

            $filepath = $CFG->dirroot . '/mod/hvp/library/fonts/' . $font;

            // If file itself doesn't exist, skip (likely, the library has updated or renamed the font).
            if (!file_exists($filepath)) {
                continue;
            }

            // Else store file.
            $fs->create_file_from_pathname($record, $filepath);
        }
    }

    /**
     * Returns the H5PIntegration js var value for the given view
     * @param view_assets $view H5P view object
     * @return string JS script to set the variabe value
     */
    private function get_h5p_integration_var(view_assets $view): string {
        // H5P integration is just the view settings as a JS variable, the H5P JS can access it.
        // https://github.com/catalyst/moodle-mod_hvp/blob/bc6f5ea3ddf9de2dd993e9f47995c73dbf80091e/classes/view_assets.php#L318.
        $h5pintegration = (object) $view->settings;

        // Disable fullscreen - mobile does not support it (no way to exit).
        $h5pintegration->fullscreenDisabled = true;

        return js_writer::set_variable('H5PIntegration', $h5pintegration);
    }

    /**
     * Returns the entire JS for the given view.
     * @param view_assets $view
     * @return string
     */
    private function get_view_js(view_assets $view): string {
        $viewjs = '';

        $viewjs .= $this->get_h5p_integration_var($view);

        // ExternaEmbed must be set to false, so the resizer and other scripts can activate
        // (they do not normally activate when externally embedding, since they require cross frame scripting).
        $viewjs .= '
        var H5P = H5P || {};
        H5P.externalEmbed = false;
        ';

        $files = $view->getdependencyfiles();
        $scriptpaths = array_column($files['scripts'], 'path');

        // Find the filenames.
        $filenames = array_map(function($scriptpath) {
            $exploded = explode("/", $scriptpath);
            return array_pop($exploded);
        }, $scriptpaths);

        // Load these files from the moodle fs.
        $fs = get_file_storage();

        foreach ($filenames as $filename) {
            $file = $fs->get_file(context_system::instance()->id, 'mod_hvp', 'cachedassets', 0, '/', $filename);

            if ($file) {
                $viewjs .= "window.console.log('Loading content lib " . $filename . "');";
                $viewjs .= $file->get_content();
            }
        }

        return $viewjs;
    }

    /**
     * Returns all the 'content' files for the linked course module.
     * Generally these are the images, audio files, etc.. that are added in the H5P.
     * @return array array of external file objects
     */
    private function get_content_files() {
        $context = context_module::instance($this->cm->id);
        return external_util::get_area_files($context->id, 'mod_hvp', 'content');
    }

    /**
     * Converts a stored_file into an external file structure.
     * @param stored_file $storedfile
     * @return array external file structure containing the data from the stored file
     */
    private static function file_to_externalfile(stored_file $storedfile) {
        // Copied mostly from externallib.php external_files::get_area_files.
        $file = [];
        $file['filename'] = $storedfile->get_filename();
        $file['filepath'] = $storedfile->get_filepath();
        $file['mimetype'] = $storedfile->get_mimetype();
        $file['filesize'] = $storedfile->get_filesize();
        $file['timemodified'] = $storedfile->get_timemodified();
        $file['isexternalfile'] = $storedfile->is_external_file();
        if ($file['isexternalfile']) {
            $file['repositorytype'] = $storedfile->get_repository_type();
        }
        $fileitemid = null;
        $file['fileurl'] = moodle_url::make_webservice_pluginfile_url($storedfile->get_contextid(),
            $storedfile->get_component(), $storedfile->get_filearea(), $fileitemid, $storedfile->get_filepath(),
            $storedfile->get_filename())->out(false);

        return $file;
    }

    /**
     * Recursively extracts rules from css parser
     * @param array $accumulator
     * @param mixed $next next val, usually a list or a rule itself
     * @return array
     */
    private function recurse_through_rules($accumulator, $next): array {
        // Is a list, recurse into the list.
        if (get_class($next) == "Sabberworm\CSS\Value\RuleValueList") {
            foreach ($next->getListComponents() as $component) {
                $accumulator = $this->recurse_through_rules($accumulator, $component);
            }
            return $accumulator;
        }

        // Is a value (leaf) - append self and return.
        $accumulator[] = $next;
        return $accumulator;
    }

    /**
     * Extracts a list of font families and their linked sources from the given css
     * This will only return the first font for each family that is correctly found inside the file storage.
     * Note this ONLY returns ttf fonts, for maximum compatibility.
     *
     * @param string $css
     * @return array array of font family name => stored_file pairs.
     */
    public function extract_fontfiles_from_css($css) {
        $parser = new \Sabberworm\CSS\Parser($css);
        $result = $parser->parse();

        $rulesetfiles = array_map(function($ruleset) {
            // WE are looking for @font-face
            // So ignore any non-at rulenames (at == @).
            if (!method_exists($ruleset, 'atRuleName')) {
                return null;
            }

            if ($ruleset->atRuleName() != 'font-face') {
                return null;
            }

            // Extract all the sources.
            $sourcerules = array_filter($ruleset->getRules(), function($rule) {
                return $rule->getRule() == 'src';
            });

            // Find the font-family rule (should only be 1).
            $familyrules = array_filter($ruleset->getRules(), function($rule) {
                return $rule->getRule() == 'font-family';
            });

            $sourcerules = array_reduce($sourcerules, function($accumulator, $sourcerule) {
                $accumulator = array_merge($accumulator, $this->recurse_through_rules([], $sourcerule->getValue()));
                return $accumulator;
            }, []);

            // Filter any that are not urls.
            $sourcerules = array_filter($sourcerules, fn($val) => method_exists($val, 'getURL'));

            if (empty($sourcerules) || empty($familyrules)) {
                return null;
            }

            $familyrulevalue = current($familyrules)->getValue();
            $family = is_string($familyrulevalue) ? $familyrulevalue : $familyrulevalue->getString();
            $urls = array_map(fn($rule) => $rule->getURL()->getString(), $sourcerules);

            // Find these urls in the moodle fs.
            $files = array_map(function($fonturl) {
                // Naively evaluate any backwards directory traversals in the url.
                // E.g.
                // ../libraries/H5P.FontIcons/styles/../fonts/h5p.ttf
                // becomes
                // ../libraries/H5p.FontIcons/fonts/h5p.ttf.

                $split = explode('/', $fonturl);
                foreach ($split as $i => $val) {
                    if ($val == '..' && $i != 0) {
                        // Empty previous (dont remove to keep indexing the same).
                        $split[$i - 1] = '';
                    }
                }
                $split = array_filter($split, fn($v) => !empty($v));
                $fonturl = implode('/', $split);

                $fs = get_file_storage();

                $islibraryfont = strpos($fonturl, "../libraries/") === 0;
                $iscorefont = strpos($fonturl, "../fonts/") === 0;

                if ($islibraryfont) {
                    // Parse URL to remove any query strings on the end of the url.
                    // And use pathinfo to get the dir and filename.
                    $pathinfo = pathinfo(parse_url($fonturl)['path']);

                    // Remove the ../libraries from the path, and enclose in brackets to match
                    // how hvp core stores fonts.
                    $librarypath = str_replace('../libraries/', '', $pathinfo['dirname']);
                    $librarypath = '/' . $librarypath . '/';

                    $filename = $pathinfo['basename'];

                    return $fs->get_file(context_system::instance()->id, 'mod_hvp', 'libraries', 0, $librarypath, $filename);
                }

                if ($iscorefont) {
                    // Parse URL to remove any query strings on the end of the url.
                    // And use pathinfo to get the dir and filename.
                    $filename = pathinfo(parse_url($fonturl)['path'], PATHINFO_BASENAME);

                    // We put these in here ourselves, since they are not usually accessible via pluginfile.
                    $path = '/mobile_fonts/';
                    return $fs->get_file(context_system::instance()->id, 'mod_hvp', 'libraries', 0, $path, $filename);
                }

                // Not supported yet or does not map correctly to anything in stored_files.
                return null;
            }, $urls);

            // Filter out any broken files.
            $files = array_filter($files);

            // Filter out non .ttf files.
            $files = array_filter($files, fn($file) => $this->str_ends_with($file->get_filename(), '.ttf'));

            return [
                $family => $files,
            ];
        }, $result->getAllRuleSets());

        // Clear any ruleset files that did not produce any files (e.g. were not font rules).
        $rulesetfiles = array_filter($rulesetfiles);

        // Flatten them together to get all the font families => array <source> lists.
        $fontmap = array_merge([], ...array_values($rulesetfiles));

        // Remove any font families that had no mapping (i.e. no files found).
        $fontmap = array_filter($fontmap, fn($v) => !empty($v));

        // Pick only 1 source for each font. This avoid unnecessary caching.
        $fontmap = array_map(fn($sources) => current($sources), $fontmap);

        return $fontmap;
    }

    /**
     * Polyfill str_ends_with to allow php 7.4 to work
     * @param string $haystack
     * @param string $needle
     * @return bool
     */
    private function str_ends_with(string $haystack, string $needle) {
        return strlen($needle) === 0 || substr($haystack, -strlen($needle)) === $needle;
    }
}
