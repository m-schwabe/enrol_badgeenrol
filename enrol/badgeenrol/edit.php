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
 * Adds a new instance of enrol_badgeenrol to specified course
 * or edits current instance.
 *
 * @package enrol_badgeenrol
 * @author Matthias Schwabe <mail@matthiasschwabe.de>
 * @copyright 2015 Matthias Schwabe
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 */

require_once('../../config.php');
require_once('edit_form.php');

$courseid   = required_param('courseid', PARAM_INT);
$instanceid = optional_param('id', 0, PARAM_INT);

$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);
$context = context_course::instance($course->id);

require_login($course);
require_capability('enrol/badgeenrol:config', $context);

$PAGE->set_url('/enrol/badgeenrol/edit.php', array('courseid' => $course->id, 'id' => $instanceid));
$PAGE->set_pagelayout('admin');

$return = new moodle_url('/enrol/instances.php', array('id' => $course->id));
if (!enrol_is_enabled('badgeenrol')) {
    redirect($return);
}

$plugin = enrol_get_plugin('badgeenrol');

if ($instanceid) {
    $instance = $DB->get_record('enrol',
        array('courseid' => $course->id, 'enrol' => 'badgeenrol', 'id' => $instanceid), '*', MUST_EXIST);
} else {
    require_capability('moodle/course:enrolconfig', $context);

    // No instance yet, we have to add a new instance.
    navigation_node::override_active_url(new moodle_url('/enrol/instances.php', array('id' => $course->id)));
    $instance = new stdClass();
    $instance->id = null;
    $instance->courseid = $course->id;
    $instance->status = ENROL_INSTANCE_ENABLED;
}

$mform = new enrol_badgeenrol_edit_form(null, array($instance, $plugin, $context));

if ($mform->is_cancelled()) {
    redirect($return);

} else if ($data = $mform->get_data()) {
    if ($instance->id) {

        $instance->name = $data->name;
        $instance->courseid = $data->courseid;
        $instance->roleid = $data->roleid;
        if (!empty($data->badges)) {
            $instance->customtext1 = implode('#', $data->badges);
        } else {
            $instance->customtext1 = null;
        }
        $DB->update_record('enrol', $instance);

    } else {
        if (!empty($data->badges)) {
            $badges = implode('#', $data->badges);
        } else {
            $badges = null;
        }
        $fields = array('name' => $data->name, 'courseid' => $data->courseid, 'roleid' => $data->roleid, 'customtext1' => $badges);
        $plugin->add_instance($course, $fields);
    }

    redirect($return);
}

$PAGE->set_heading($course->fullname);
$PAGE->set_title(get_string('pluginname', 'enrol_badgeenrol'));

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('pluginname', 'enrol_badgeenrol'));
$mform->display();
echo $OUTPUT->footer();
