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
 * Add or edit a recertification schedule.
 *
 * @package    local_recertify
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/adminlib.php');
require_once($CFG->dirroot . '/local/recertify/lib.php');

require_login();
admin_externalpage_setup('local_recertify_manage');

$context = context_system::instance();
require_capability('local/recertify:manage', $context);

$id = optional_param('id', 0, PARAM_INT);

$returnurl = new moodle_url('/local/recertify/index.php');
$PAGE->set_url(new moodle_url('/local/recertify/edit.php', $id ? ['id' => $id] : []));

$customdata = [];
if ($id) {
    $schedule = $DB->get_record('local_recertify_schedule', ['id' => $id], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $schedule->courseid], 'id, fullname');
    $customdata['id'] = $id;
    $customdata['coursename'] = $course ? format_string($course->fullname) : (string)$schedule->courseid;
    $customdata['activities'] = local_recertify_activity_options((int)$schedule->courseid);
    $heading = get_string('editschedule', 'local_recertify');

    // Pre-tick the activities already selected.
    $schedule->cmids = $DB->get_fieldset_select(
        'local_recertify_schedule_cm',
        'cmid',
        'scheduleid = :sid',
        ['sid' => $schedule->id]
    );
} else {
    // Seed a new schedule from the site-wide defaults so the settings page is meaningful.
    $schedule = local_recertify_get_defaults();
    $schedule->id = 0;
    $schedule->courseid = 0;
    $heading = get_string('addschedule', 'local_recertify');
}

$PAGE->set_title($heading);
$PAGE->set_heading($heading);

$mform = new \local_recertify\form\schedule_form(null, $customdata);
$mform->set_data($schedule);

if ($mform->is_cancelled()) {
    redirect($returnurl);
}

if ($data = $mform->get_data()) {
    $record = (object)[
        'courseid' => (int)$data->courseid,
        'enabled' => (int)$data->enabled,
        'scheduletype' => in_array($data->scheduletype, ['relative', 'fixed', 'completion'], true)
            ? $data->scheduletype
            : 'relative',
        'intervalmonths' => max(1, (int)$data->intervalmonths),
        'fixeddate' => trim((string)$data->fixeddate),
        'warningdays' => max(0, (int)$data->warningdays),
        'resetdepth' => in_array($data->resetdepth, ['completion', 'grades', 'full'], true)
            ? $data->resetdepth
            : 'completion',
        'wipeassignments' => (int)$data->wipeassignments,
        'wipequizattempts' => (int)$data->wipequizattempts,
        'wipescormtracks' => (int)$data->wipescormtracks,
        'notifyonreset' => (int)$data->notifyonreset,
        'activityscope' => (($data->activityscope ?? 'all') === 'selected') ? 'selected' : 'all',
        'timemodified' => time(),
    ];

    // The wipe options only mean anything at full depth.
    if ($record->resetdepth !== 'full') {
        $record->wipeassignments = 0;
        $record->wipequizattempts = 0;
        $record->wipescormtracks = 0;
    }

    // Keep only ids that are really activities in this schedule's course, so a tampered
    // form cannot attach another course's modules to the schedule.
    $selectedcmids = [];
    if ($record->activityscope === 'selected' && !empty($data->cmids)) {
        $courseidforcms = !empty($data->id)
            ? (int)$DB->get_field('local_recertify_schedule', 'courseid', ['id' => $data->id])
            : (int)$record->courseid;
        foreach ((array)$data->cmids as $cmid) {
            if ($DB->record_exists('course_modules', ['id' => (int)$cmid, 'course' => $courseidforcms])) {
                $selectedcmids[] = (int)$cmid;
            }
        }
    }
    if (!$selectedcmids) {
        $record->activityscope = 'all';
    }

    if (!empty($data->id)) {
        $existing = $DB->get_record('local_recertify_schedule', ['id' => $data->id], '*', MUST_EXIST);
        $record->id = $existing->id;
        // The course is fixed once the schedule exists.
        $record->courseid = (int)$existing->courseid;
        $DB->update_record('local_recertify_schedule', $record);
        $message = get_string('scheduleupdated', 'local_recertify');
    } else {
        // Guard against a course being deleted between form load and submit.
        if (!$DB->record_exists('course', ['id' => $record->courseid])) {
            throw new moodle_exception('invalidcourseid', 'error');
        }
        $record->timecreated = time();
        $record->id = $DB->insert_record('local_recertify_schedule', $record);
        $message = get_string('schedulecreated', 'local_recertify');
    }

    // Replace the activity selection wholesale - simpler than diffing, and the table is
    // tiny. Rows are always cleared, so switching back to whole-course scope leaves no
    // stale selection behind to surprise whoever edits the schedule next.
    $DB->delete_records('local_recertify_schedule_cm', ['scheduleid' => $record->id]);
    foreach ($selectedcmids as $cmid) {
        $DB->insert_record('local_recertify_schedule_cm', (object)[
            'scheduleid' => $record->id,
            'cmid' => $cmid,
        ]);
    }

    redirect($returnurl, $message, null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->heading($heading);
$mform->display();
echo $OUTPUT->footer();
