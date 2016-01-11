<?php

/**
 * Hvp specific lib functions and H5P Framework Interface implementation.
 *
 * @package    mod_hvp
 * @subpackage hvp
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_hvp;

/**
 * Class content_user_data handles user data operations.
 *
 * @package mod_hvp
 */
class Content_User_Data {

    public static function handle_ajax() {
        // Query String Parameters
        $content_id = required_param('content_id', PARAM_INT);
        $data_id = required_param('data_type', PARAM_ALPHA);
        $sub_content_id = required_param('sub_content_id', PARAM_INT);

        // Form Data
        $data = optional_param('data', NULL, PARAM_RAW);
        $pre_load = optional_param('preload', NULL, PARAM_INT);
        $invalidate = optional_param('invalidate', NULL, PARAM_INT);

        if ($content_id === NULL ||
            $data_id === NULL ||
            $sub_content_id === NULL ||
            $data === NULL ||
            $invalidate === NULL ||
            $pre_load === NULL) {
            return; // Missing parameters
        }

        $response = (object) array(
            'success' => TRUE
        );

        // Delete user data
        if ($data === '0') {
            Content_User_Data::delete_user_data($content_id, $sub_content_id, $data_id);
        }
        else {
            // Save user data
            Content_User_Data::save_user_data($content_id, $sub_content_id, $data_id, $pre_load, $invalidate, $data);
        }

        print json_encode($response);
        exit;
    }

    public static function save_user_data($content_id, $sub_content_id, $data_id, $pre_load, $invalidate, $data) {
        global $DB, $USER;

        // Determine if we should update or insert
        $update = $DB->get_record('hvp_content_user_data', array(
                'user_id' => $USER->id,
                'hvp_id' => $content_id,
                'sub_content_id' => $sub_content_id,
                'data_id' => $data_id
            )
        );

        // Wash values to ensure 0 or 1.
        $pre_load = $pre_load === '0' ? 0 : 1;
        $invalidate = $invalidate === '0' ? 0 : 1;

        // New data to be inserted
        $new_data = (object)array(
            'user_id' => $USER->id,
            'hvp_id' => $content_id,
            'sub_content_id' => $sub_content_id,
            'data_id' => $data_id,
            'data' => $data,
            'preloaded' => $pre_load,
            'delete_on_content_change' => $invalidate
        );

        // Does not exist
        if ($update === FALSE) {
            // Insert new data
            $DB->insert_record('hvp_content_user_data', $new_data);
        }
        else {
            // Get old data id
            $new_data->id = $update->id;

            // Update old data
            $DB->update_record('hvp_content_user_data', $new_data);
        }
    }

    public static function delete_user_data($content_id, $sub_content_id, $data_id) {
        global $DB, $USER;

        $DB->delete_records('hvp_content_user_data', array(
            'user_id' => $USER->id,
            'hvp_id' => $content_id,
            'sub_content_id' => $sub_content_id,
            'data_id' => $data_id
        ));
    }
}
