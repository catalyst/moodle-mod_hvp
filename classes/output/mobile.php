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

defined('MOODLE_INTERNAL') || die();

use context_module;
use context_system;
use external_util;
use mod_hvp;

class mobile {

    public static function mobile_course_view($args) {
        global $DB, $CFG, $OUTPUT, $USER;

        $cmid = $args['cmid'];
        if (empty($CFG->allowframembedding) && !\core_useragent::is_moodle_app()) {
            $context = \context_system::instance();
            if (has_capability('moodle/site:config', $context)) {
                $template = 'mod_hvp/iframe_embedding_disabled';
            } else {
                $template = 'mod_hvp/contact_site_administrator';
            }
            return array(
                'templates' => array(
                    array(
                        'id' => 'noiframeembedding',
                        'html' => $OUTPUT->render_from_template($template, [])
                    )
                )
            );
        }

        // Verify course context.
        $cm = get_coursemodule_from_id('hvp', $cmid);
        if (!$cm) {
            print_error('invalidcoursemodule');
        }
        $course = $DB->get_record('course', array('id' => $cm->course));
        if (!$course) {
            print_error('coursemisconf');
        }
        require_course_login($course, false, $cm, true, true);
        $context = context_module::instance($cm->id);
        require_capability('mod/hvp:view', $context);

        list($token, $secret) = mod_hvp\mobile_auth::create_embed_auth_token();

        // Store secret in database.
        $auth             = $DB->get_record('hvp_auth', array(
            'user_id' => $USER->id,
        ));
        $currenttimestamp = time();
        if ($auth) {
            $DB->update_record('hvp_auth', array(
                'id'         => $auth->id,
                'secret'     => $token,
                'created_at' => $currenttimestamp,
            ));
        } else {
            $DB->insert_record('hvp_auth', array(
                'user_id'    => $USER->id,
                'secret'     => $token,
                'created_at' => $currenttimestamp
            ));
        }

        $data = [
            'cmid'    => $cmid,
            'wwwroot' => $CFG->wwwroot,
            'user_id' => $USER->id,
            'secret'  => urlencode($secret),
            'assetlink' => 'https://mdl41-defence-app.localhost/mod/hvp/testfile.js'
        ];
        $viewassets = new mod_hvp\view_assets($cm, $course);
        $assets = $viewassets->get_assets_for_mobile_view();
        $data = array_merge($data, $assets);

        //$fs = get_file_storage();
        //$files = $fs->get_area_files(context_system::instance()->id, 'mod_hvp', 'libraries');
        // $files = external_util::get_area_files(context_system::instance()->id, 'mod_hvp', 'libraries');

        $jscontents = '';
       
        // file_get_contents($CFG->dirroot . '/mod/hvp/testfile.js');
        // foreach (\H5PCore::$scripts as $script) {
        //     $scriptpath = $CFG->dirroot . '/mod/hvp/library/' . $script;
        //     $jscontents .= file_get_contents($scriptpath);

        //     break; // Testing just to get the first one.
        // }

        // debugging($jscontents);

        return array(
            'templates'  => array(
                array(
                    'id'   => 'main',
                    'html' => $OUTPUT->render_from_template('mod_hvp/mobile_view_page', $data),
                ),
            ),
            // 'javascript' => $jscontents,
            //`document.getElementById("test1234").innerHTML = '<script src="https://mdl41-defence-app.localhost/mod/hvp/testfile.js" core-external-content></script>'`,
            //'javascript' => "window.console.log('')", // file_get_contents($CFG->dirroot . '/mod/hvp/library/js/h5p-resizer.js'),
            // 'files' => [current($files)],
        );
    }
}
