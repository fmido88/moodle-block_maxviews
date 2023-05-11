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
 * Block maxviews is defined here.
 *
 * @package     block_maxviews
 * @copyright   2023 Mohammad Farouk <phun.for.physics@gmail.com>
 * @license     https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_maxviews extends block_base {

    /**
     * Initializes class member variables.
     */
    public function init() {
        // Needed by Moodle to differentiate between blocks.
        $this->title = get_string('pluginname', 'block_maxviews');
    }

    /** Are you going to allow multiple instances of each block?
     * If yes, then it is assumed that the block WILL USE per-instance configuration
     *
     * @return bool
     */
    public function instance_allow_multiple() {
        return false;
    }

    /**
     * Returns the block contents.
     *
     * @return \stdClass The block contents.
     */
    public function get_content() {
        global $CFG, $COURSE, $DB, $USER, $OUTPUT;

        require_once($CFG->dirroot.'/blocks/maxviews/locallib.php');

        $this->content = new \stdClass;
        $ctx = context_system::instance();
        if (!has_capability('block/maxviews:view', $this->context) &&
            !has_capability('moodle/course:manageactivities', $ctx)) {
            return $this->content;
        }
        $this->content->icons = array();
        $this->content->footer = '';
        $this->content->items = array();

        $id = optional_param('id', 0, PARAM_INT); // Course id.
        $course = $DB->record_exists('course', array('id' => $id));
        if ($course == false) {
            return $this->content;
        } else {
            $this->content->text = get_index($id);
            return $this->content;
        }
    }

    /**
     * Defines configuration data.
     *
     * The function is called immediately after init().
     */
    public function specialization() {

        // Load user defined title and make sure it's never empty.
        if (empty($this->config->title)) {
            $this->title = get_string('pluginname', 'block_maxviews');
        } else {
            $this->title = $this->config->title;
        }
    }

    /**
     * Enables global configuration of the block in settings.php.
     *
     * @return bool True if the global configuration is enabled.
     */
    public function has_config() {
        return false;
    }

    /**
     * Sets the applicable formats for the block.
     *
     * @return string[] Array of pages and permissions.
     */
    public function applicable_formats() {
        return array(
            'course-view' => true,
        );
    }

    /**
     * We use this function here to display a discription for
     * the student showing the limits of views even if he isn't
     * restricted.
     *
     * {@inheritDoc}
     * @see block_base::get_required_javascript()
     */
    public function get_required_javascript() {

        global $USER, $COURSE, $DB;
        require_once(__DIR__ . '/locallib.php');

        $cms = get_modules_with_maxviews($USER->id, $COURSE->id);
        // Get the views of current user and get string from availability max-views.
        $logmanager = get_log_manager();
        if (!$readers = $logmanager->get_readers('core\log\sql_reader')) {
            // Should be using 2.8, use old class.
            $readers = $logmanager->get_readers('core\log\sql_select_reader');
        }
        $reader = array_pop($readers);
        $modinfo = get_fast_modinfo($COURSE->id);
        // This loop for each course module in the current course.
        foreach ($cms as $cmid) {
            $cm = $modinfo->get_cm($cmid);
            $info = new core_availability\info_module($cm);
            $context = $info->get_context();

            // Get the views limits for this module from availability_maxviews.
            $tree = $info->get_availability_tree();
            $reflectiontree = new \ReflectionClass($tree);
            $reflectionchildren = $reflectiontree->getProperty('children');
            $reflectionchildren->setAccessible(true);

            $children = $reflectionchildren->getValue($tree);

            $viewslimit = PHP_INT_MAX;

            foreach ($children as $child) {
                if ($child instanceof \availability_maxviews\condition) {
                    // This is a max views condition.

                    // Viewslimit is protected so again use reflection.
                    $reflectionmaxviews = new \ReflectionClass($child);
                    $reflectionviewslimit = $reflectionmaxviews->getProperty('viewslimit');
                    $reflectionviewslimit->setAccessible(true);

                    $newviewslimit = (int)$reflectionviewslimit->getValue($child);

                    // It's unlikely but its possible to add more than one max views condition.
                    // So use the smallest value.
                    if ($newviewslimit < $viewslimit) {
                        $viewslimit = $newviewslimit;
                    }

                }
            }
            $where = 'contextid = :context AND userid = :userid AND crud = :crud';
            $params = ['context' => $context->id, 'userid' => $USER->id, 'crud' => 'r'];
            $conditions = ['cmid' => $info->get_course_module()->id, 'userid' => $USER->id];
            if ($override = $DB->get_record('availability_maxviews', $conditions)) {
                if (!empty($override->lastreset) || $override->lastreset != 0) {
                    $where .= ' AND timecreated >= :lastreset';
                    $params['lastreset'] = $override->lastreset;
                }
                if (!empty($override->maxviews)) {
                    $viewslimit += $override->maxviews;
                }
            }
            $viewscount = $reader->get_events_select_count($where, $params);

            // Prepare for the output.
            $a = new \stdclass();
            $a->viewscount = $viewscount;
            $a->viewslimit = $viewslimit;
            $a->viewsremain = ($viewslimit - $viewscount);

            global $OUTPUT;
            $templatecontext = new stdClass();
            if ($a->viewslimit > $a->viewscount) {
                $templatecontext->viewcount = get_string('haveremainedviews', 'block_maxviews', $a);
            } else {
                $templatecontext->viewcount = get_string('outofviews', 'block_maxviews', $a);
            }
            $render = $OUTPUT->render_from_template('block_maxviews/views', $templatecontext);

            // JavaScript code to show the views of certain user even if he didn't restricted.
            // For Tiles course format.
            $code = 'require(["jquery", "core/ajax"], function($, ajax) {
                    // The following listener is needed for the Tiles course format, where sections are loaded on demand.
                $(document).ajaxComplete(function(event, xhr, settings) {
                    if (typeof (settings.data) !== \'undefined\') {
                        var data = JSON.parse(settings.data);
                        if (data.length > 0 && typeof (data[0].methodname) !== \'undefined\') {
                            if (data[0].methodname == \'format_tiles_get_single_section_page_html\' // Tile load.
                                // || data[0].methodname == \'format_tiles_log_tile_click\'
                                ) { // Tile load, cached.
                                    $(document).ready(function() {
                                        var module = $("#module-'.$cmid.' .description");
                                        module.append("'.addslashes_js($render).'");
                                        });
                                }
                            }
                        }
                    });
                });';
            $this->page->requires->js_init_code($code);
            // JavaScript code to show the views of certain user even if he didn't restricted.
            // For any other course format.
            $code = 'require(["jquery"], function($) {
                $(document).ready(function() {
                    var module = $("#module-'.$cmid.' .description");
                    module.append("'.addslashes_js($render).'");
                    // Your code to update the view count goes here
                });
                });';
            $this->page->requires->js_init_code($code);
        }

    }
}

