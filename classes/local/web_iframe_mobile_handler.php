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

use mod_hvp\mobile_auth;

/**
 * Default mobile handler.
 * Uses iframe that simply embeds the embed.php page acting as a web wrapper.
 *
 * @package    mod_hvp
 * @copyright  2024 Catalyst IT Australia
 * @author     Matthew Hilton <matthewhilton@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class web_iframe_mobile_handler {
    use mobile_handler;

    /**
     * Construct
     * @param array $cm course module
     */
    public function __construct($cm) {
        $this->construct($cm);
        $this->require_capabilities();
    }

    /**
     * Handles mobile request
     * @return array
     */
    public function handle(): array {
        global $DB, $CFG, $OUTPUT, $USER;

        if (empty($CFG->allowframembedding) && !\core_useragent::is_moodle_app()) {
            $context = \context_system::instance();
            if (has_capability('moodle/site:config', $context)) {
                $template = 'mod_hvp/iframe_embedding_disabled';
            } else {
                $template = 'mod_hvp/contact_site_administrator';
            }
            return [
                'templates' => [
                        'id' => 'noiframeembedding',
                        'html' => $OUTPUT->render_from_template($template, []),
                    ],
                ];
        }

        list($token, $secret) = mobile_auth::create_embed_auth_token();

        // Store secret in database.
        $auth = $DB->get_record('hvp_auth', ['user_id' => $USER->id]);
        $currenttimestamp = time();
        if ($auth) {
            $DB->update_record('hvp_auth', [
                'id'         => $auth->id,
                'secret'     => $token,
                'created_at' => $currenttimestamp,
            ]);
        } else {
            $DB->insert_record('hvp_auth', [
                'user_id'    => $USER->id,
                'secret'     => $token,
                'created_at' => $currenttimestamp,
            ]);
        }

        $data = [
            'cmid'    => $this->cm->id,
            'wwwroot' => $CFG->wwwroot,
            'user_id' => $USER->id,
            'secret'  => urlencode($secret),
        ];

        return [
            'templates'  => [
                [
                    'id'   => 'main',
                    'html' => $OUTPUT->render_from_template('mod_hvp/web_iframe_mobile_view_page', $data),
                ],
            ],
            'javascript' => file_get_contents($CFG->dirroot . '/mod/hvp/library/js/h5p-resizer.js'),
        ];
    }
}
