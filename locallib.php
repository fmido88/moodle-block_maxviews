<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

/**
 * Plugin internal classes, functions and constants are defined here.
 *
 * @package     block_maxviews
 * @copyright   2023 Mohammad Farouk <phun.for.physics@gmail.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/** Get the moudules in the given course with Max-Views condition enabled.
 *
 * @param int $userid user id
 * @param int $courseid course id
 *
 * @return array return the array with course modules with availability maxviews enabled.
 */
function get_modules_with_maxviews($userid, $courseid) {
    global $DB;

    $cms = get_fast_modinfo($courseid)->cms;
    $moduleswithmaxviews = array();
    foreach ($cms as $cm) {
        $getviewslimit = $DB->get_field_select('course_modules', 'availability' ,
        "`availability` LIKE '%maxviews%' AND `id` = '$cm->id'", ['id' => $cm->id], IGNORE_MISSING);
        if ($getviewslimit != null && $cm->uservisible) {
            $moduleswithmaxviews[] = $cm->id;
        }
    }

    if (empty($moduleswithmaxviews)) {
        return array();
    }

    return $moduleswithmaxviews;
}

/**
 * Getting the table of overrided students and link for ovverriding form.
 * @param int $courseid
 * @return string
 */
function get_index($courseid) {
    global $OUTPUT, $PAGE;

    $output = $PAGE->get_renderer('availability_maxviews');

    $index = new \availability_maxviews\output\index($courseid);

    ob_start();
    echo $output->render($index);
    $display = ob_get_clean();

    return $OUTPUT->box($display);
}
