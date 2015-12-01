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
 * The library file for badge enrolment plugin.
 *
 * @package enrol_badgeenrol
 * @author Matthias Schwabe <mail@matthiasschwabe.de>
 * @copyright 2015 Matthias Schwabe
 * @license http://www.gnu.org/copyleft/gpl.html GNU Public License
 */

require_once("$CFG->libdir/formslib.php");

class enrol_badgeenrol_plugin extends enrol_plugin {

    /**
     * Returns optional enrolment information icons.
     *
     * This is used in course list for quick overview of enrolment options.
     *
     * We are not using single instance parameter because sometimes
     * we might want to prevent icon repetition when multiple instances
     * of one type exist. One instance may also produce several icons.
     *
     * @param array $instances all enrol instances of this type in one course
     * @return array of pix_icon
     */
    public function get_info_icons(array $instances) {
        $key = false;
        $nokey = false;
        foreach ($instances as $instance) {
            if ($this->can_self_enrol($instance, false) !== true) {
                // User can not enrol himself.
                // Note that we do not check here if user is already enrolled for performance reasons -
                // such check would execute extra queries for each course in the list of courses and
                // would hide self-enrolment icons from guests.
                continue;
            }
            if ($instance->password or $instance->customint1) {
                $key = true;
            } else {
                $nokey = true;
            }
        }
        $icons = array();
        $icons[] = new pix_icon('badge', get_string('pluginname', 'enrol_badgeenrol'), 'enrol_badgeenrol');

        return $icons;
    }

    public function allow_unenrol(stdClass $instance) {
        // Users with unenrol cap may unenrol other users manually.
        return true;
    }

    public function allow_manage(stdClass $instance) {
        // Users with manage cap may tweak period and status.
        return true;
    }

    public function add_course_navigation($instancesnode, stdClass $instance) {
        if ($instance->enrol !== 'badgeenrol') {
             throw new coding_exception('Invalid enrol instance type!');
        }

        $context = context_course::instance($instance->courseid);
        if (has_capability('enrol/badgeenrol:config', $context)) {
            $managelink = new moodle_url('/enrol/badgeenrol/edit.php',
                array('courseid' => $instance->courseid, 'id' => $instance->id));
            $instancesnode->add($this->get_instance_name($instance), $managelink, navigation_node::TYPE_SETTING);
        }
    }

    /**
     * Is it possible to hide/show enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     */
    public function can_hide_show_instance($instance) {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/badgeenrol:config', $context);
    }

    /**
     * Returns link to page which may be used to add new instance of enrolment plugin in course.
     * @param int $courseid
     * @return moodle_url page url
     */
    public function get_newinstance_link($courseid) {
        $context = context_course::instance($courseid, MUST_EXIST);

        if (!has_capability('moodle/course:enrolconfig', $context) or !has_capability('enrol/badgeenrol:config', $context)) {
            return null;
        }
        // Multiple instances supported - different roles with different password.
        return new moodle_url('/enrol/badgeenrol/edit.php', array('courseid' => $courseid));
    }

    /**
     * Is it possible to delete enrol instance via standard UI?
     *
     * @param stdClass $instance
     * @return bool
     */
    public function can_delete_instance($instance) {
        $context = context_course::instance($instance->courseid);
        return has_capability('enrol/badgeenrol:config', $context);
    }

    public function enrol_page_hook(stdClass $instance) {
        global $OUTPUT, $USER, $DB;

        ob_start();

        if ($DB->record_exists('user_enrolments', array('userid' => $USER->id, 'enrolid' => $instance->id))) {
            return ob_get_clean();
        }

        $context = context_system::instance();

        // Can not enrol guest.
        if (isguestuser()) {
            return null;
        }

        $configbadges = explode('#', $instance->customtext1);

        if (empty($configbadges[0])) {
            return $OUTPUT->box(get_string('nobadgesconfigured', 'enrol_badgeenrol'), 'generalbox');
        }

        $access = $this->check_required_badges($USER->id, $configbadges);

        if ($access) {
            $form = new enrol_badgeenrol_form(null, $instance);
            $instanceid = optional_param('instance', 0, PARAM_INT);
            if ($instance->id == $instanceid) {
                if ($data = $form->get_data()) {
                    $this->enrol_badgeenrol($instance, $data);
                }
            }

            $form->display();
            $output = ob_get_clean();
            return $OUTPUT->box($output);
        } else {

            $out = $OUTPUT->box(get_string('enrolinfo', 'enrol_badgeenrol'), 'generalbox');

            foreach ($configbadges as $badgeid) {

                $badge = $DB->get_record('badge', array('id' => $badgeid), '*', MUST_EXIST);

                $imageurl = moodle_url::make_pluginfile_url($context->id, 'badges', 'badgeimage', $badge->id, '/', 'f2', false);
                // Appending a random parameter to image link to forse browser reload the image.
                $imageurl->param('refresh', rand(1, 10000));
                $attributes = array('src' => $imageurl, 'alt' => s($badge->name), 'class' => 'activatebadge');

                $name = html_writer::tag('span', $badge->name, array('class' => 'badge-name'));
                $image = html_writer::empty_tag('img', $attributes);
                $url = new moodle_url('/badges/view.php', array('type' => 1));
                $badgeout = html_writer::link($url, $image.$name, array('title' => $badge->name, 'class' => 'requiredbadge'));
                $out .= $OUTPUT->box($badgeout, 'generalbox');
            }

        }

        return $out;
    }

    public function check_required_badges($userid, $badges) {
        global $DB;
        $access = false;

        foreach ($badges as $badgeid) {
            if ($record = $DB->get_record('badge_issued', array('badgeid' => $badgeid, 'userid' => $userid))) {
                if (!$record->dateexpire or $record->dateexpire >= time()) {
                    $access = true;
                } else {
                    $access = false;
                    break;
                }
            } else {
                $access = false;
                break;
            }
        }

        return $access;
    }

    /**
     * Enrol user to course
     *
     * @param stdClass $instance enrolment instance
     * @param stdClass $data data needed for enrolment.
     * @return bool|array true if enroled else eddor code and messege
     */
    public function enrol_badgeenrol(stdClass $instance, $data = null) {
        global $DB, $USER, $CFG;

        $this->enrol_user($instance, $USER->id, $instance->roleid, time(), 0);
    }

    /**
     * Gets an array of the user enrolment actions.
     *
     * @param course_enrolment_manager $manager
     * @param stdClass $ue A user enrolment object
     * @return array An array of user_enrolment_actions
     */
    public function get_user_enrolment_actions(course_enrolment_manager $manager, $ue) {
        $actions = array();
        $context = $manager->get_context();
        $instance = $ue->enrolmentinstance;
        $params = $manager->get_moodlepage()->url->params();
        $params['ue'] = $ue->id;
        if ($this->allow_unenrol_user($instance, $ue) && has_capability("enrol/badgeenrol:unenrol", $context)) {
            $url = new moodle_url('/enrol/unenroluser.php', $params);
            $actions[] = new user_enrolment_action(new pix_icon('t/delete', ''), get_string('unenrol', 'enrol'), $url,
                array('class' => 'unenrollink', 'rel' => $ue->id));
        }
        return $actions;
    }

    public function get_action_icons(stdClass $instance) {
        global $OUTPUT;

        if ($instance->enrol !== 'badgeenrol') {
            throw new coding_exception('invalid enrol instance!');
        }
        $context = context_course::instance($instance->courseid);

        $icons = array();

        if (has_capability('enrol/badgeenrol:manage', $context)) {
            $editlink = new moodle_url('/enrol/badgeenrol/edit.php',
                array('courseid' => $instance->courseid, 'id' => $instance->id));
            $icons[] = $OUTPUT->action_icon($editlink, new pix_icon('t/edit', get_string('edit'), 'core',
                array('class' => 'iconsmall')));
        }

        return $icons;
    }
}

class enrol_badgeenrol_form extends moodleform {
    protected $instance;

    /**
     * Overriding this function to get unique form id for multiple self enrolments.
     *
     * @return string form identifier
     */
    protected function get_form_identifier() {
        $formid = $this->_customdata->id.'_'.get_class($this);
        return $formid;
    }

    public function definition() {
        global $USER, $OUTPUT, $CFG;

        $mform = $this->_form;
        $instance = $this->_customdata;
        $this->instance = $instance;
        $plugin = enrol_get_plugin('badgeenrol');

        $heading = $plugin->get_instance_name($instance);
        $mform->addElement('header', 'badgeenrol_header', $heading);

        $mform->addElement('static', 'access', '', get_string('accessgranted', 'enrol_badgeenrol'));

        $this->add_action_buttons(false, get_string('enrolme', 'enrol_badgeenrol'));

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->setDefault('id', $instance->courseid);

        $mform->addElement('hidden', 'instance');
        $mform->setType('instance', PARAM_INT);
        $mform->setDefault('instance', $instance->id);
    }

    public function validation($data, $files) {

        $errors = parent::validation($data, $files);
        $instance = $this->instance;

        return $errors;
    }
}
