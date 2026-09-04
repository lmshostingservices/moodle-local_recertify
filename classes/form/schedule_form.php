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
 * Schedule editing form for local_recertify.
 *
 * @package    local_recertify
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_recertify\form;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Add or edit a per-course recertification schedule.
 */
class schedule_form extends \moodleform {
    /**
     * Build the form.
     */
    protected function definition(): void {
        $mform = $this->_form;
        $editing = !empty($this->_customdata['id']);

        $mform->addElement('hidden', 'id', 0);
        $mform->setType('id', PARAM_INT);

        // Course.
        if ($editing) {
            // The course a schedule applies to is fixed once created, because the audit
            // log and the unique index are both keyed on it.
            $mform->addElement(
                'static',
                'coursename',
                get_string('course'),
                $this->_customdata['coursename'] ?? ''
            );
            $mform->addElement('hidden', 'courseid', 0);
            $mform->setType('courseid', PARAM_INT);
        } else {
            $options = [
                'multiple' => false,
                'includefrontpage' => false,
                'requiredcapabilities' => ['moodle/course:update'],
            ];
            $mform->addElement('course', 'courseid', get_string('course'), $options);
            $mform->addRule('courseid', get_string('required'), 'required', null, 'client');
        }

        $mform->addElement('advcheckbox', 'enabled', get_string('enabled', 'admin'));
        $mform->setDefault('enabled', 1);

        // Schedule.
        $mform->addElement('header', 'schedulehdr', get_string('schedulesection', 'local_recertify'));
        $mform->setExpanded('schedulehdr', true);

        $mform->addElement('select', 'scheduletype', get_string('scheduletype', 'local_recertify'), [
            'relative' => get_string('scheduletype_relative', 'local_recertify'),
            'fixed' => get_string('scheduletype_fixed', 'local_recertify'),
            'completion' => get_string('scheduletype_completion', 'local_recertify'),
        ]);
        $mform->addHelpButton('scheduletype', 'scheduletype', 'local_recertify');

        $mform->addElement('text', 'intervalmonths', get_string('intervalmonths', 'local_recertify'), ['size' => 5]);
        $mform->setType('intervalmonths', PARAM_INT);
        $mform->addHelpButton('intervalmonths', 'intervalmonths', 'local_recertify');
        // The interval applies to both the relative and the completion types, so this is
        // written as a single "hide on fixed" condition. Two "neq" conditions would not
        // work: multiple hideIf rules on one element are OR-ed, so the element would be
        // hidden for every schedule type.
        $mform->hideIf('intervalmonths', 'scheduletype', 'eq', 'fixed');

        $mform->addElement('text', 'fixeddate', get_string('fixeddate', 'local_recertify'), ['size' => 8]);
        $mform->setType('fixeddate', PARAM_TEXT);
        $mform->addHelpButton('fixeddate', 'fixeddate', 'local_recertify');
        $mform->hideIf('fixeddate', 'scheduletype', 'neq', 'fixed');

        $mform->addElement('text', 'warningdays', get_string('warningdays', 'local_recertify'), ['size' => 5]);
        $mform->setType('warningdays', PARAM_INT);
        $mform->addHelpButton('warningdays', 'warningdays', 'local_recertify');

        // What gets reset.
        $mform->addElement('header', 'depthhdr', get_string('depthsection', 'local_recertify'));
        $mform->setExpanded('depthhdr', true);

        $mform->addElement('select', 'resetdepth', get_string('resetdepth', 'local_recertify'), [
            'completion' => get_string('resetdepth_completion', 'local_recertify'),
            'grades' => get_string('resetdepth_grades', 'local_recertify'),
            'full' => get_string('resetdepth_full', 'local_recertify'),
        ]);
        $mform->addHelpButton('resetdepth', 'resetdepth', 'local_recertify');

        // The permanence warning lives in the reset depth help and on each checkbox,
        // rather than as a standalone 'static' element: hideIf() does not suppress a
        // bare static, so such a warning stays on screen at completion-only depth where
        // it is simply wrong.
        $mform->addElement('advcheckbox', 'wipequizattempts', get_string('wipequizattempts', 'local_recertify'));
        $mform->addHelpButton('wipequizattempts', 'wipequizattempts', 'local_recertify');
        $mform->hideIf('wipequizattempts', 'resetdepth', 'neq', 'full');

        $mform->addElement('advcheckbox', 'wipeassignments', get_string('wipeassignments', 'local_recertify'));
        $mform->addHelpButton('wipeassignments', 'wipeassignments', 'local_recertify');
        $mform->hideIf('wipeassignments', 'resetdepth', 'neq', 'full');

        $mform->addElement('advcheckbox', 'wipescormtracks', get_string('wipescormtracks', 'local_recertify'));
        $mform->addHelpButton('wipescormtracks', 'wipescormtracks', 'local_recertify');
        $mform->hideIf('wipescormtracks', 'resetdepth', 'neq', 'full');

        // Notifications.
        $mform->addElement('header', 'notifyhdr', get_string('notifysection', 'local_recertify'));
        $mform->setExpanded('notifyhdr', true);

        $mform->addElement('advcheckbox', 'notifyonreset', get_string('notifyonreset', 'local_recertify'));
        $mform->setDefault('notifyonreset', 1);

        $this->add_action_buttons();
    }

    /**
     * Server-side validation.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files): array {
        global $DB;

        $errors = parent::validation($data, $files);

        if (in_array($data['scheduletype'], ['relative', 'completion'], true)) {
            // A zero or negative interval would mean "reset every no months", which is
            // meaningless and previously caused the scheduler to loop forever.
            if ((int)$data['intervalmonths'] < 1) {
                $errors['intervalmonths'] = get_string('errorintervalmonths', 'local_recertify');
            } else if ((int)$data['intervalmonths'] > 600) {
                $errors['intervalmonths'] = get_string('errorintervaltoolarge', 'local_recertify');
            }
        }

        if ($data['scheduletype'] === 'fixed') {
            if (!preg_match('/^(\d{1,2})-(\d{1,2})$/', trim((string)$data['fixeddate']), $m)) {
                $errors['fixeddate'] = get_string('errorfixeddate', 'local_recertify');
            } else {
                $month = (int)$m[1];
                $day = (int)$m[2];
                if (
                    $month < 1 || $month > 12 || $day < 1 || $day > 31
                        || !checkdate($month, $day, 2024)
                ) {
                    $errors['fixeddate'] = get_string('errorfixeddate', 'local_recertify');
                }
            }
        }

        if ((int)$data['warningdays'] < 0 || (int)$data['warningdays'] > 365) {
            $errors['warningdays'] = get_string('errorwarningdays', 'local_recertify');
        }

        // One schedule per course.
        if (empty($data['id'])) {
            if ($DB->record_exists('local_recertify_schedule', ['courseid' => $data['courseid']])) {
                $errors['courseid'] = get_string('errorduplicatecourse', 'local_recertify');
            }
        }

        return $errors;
    }
}
