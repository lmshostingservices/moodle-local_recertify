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
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle. If not, see <https://www.gnu.org/licenses/>.

/**
 * Admin index page for local_recertify.
 *
 * @package    local_recertify
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once('../../config.php');
require_once($CFG->dirroot . '/local/recertify/lib.php');

require_login();
$context = context_system::instance();
require_capability('local/recertify:manage', $context);

$action   = optional_param('action', 'list', PARAM_ALPHA);
$courseid = optional_param('courseid', 0, PARAM_INT);

$PAGE->set_url('/local/recertify/index.php');
$PAGE->set_context($context);
$PAGE->set_title(get_string('managerecertify', 'local_recertify'));
$PAGE->set_heading(get_string('managerecertify', 'local_recertify'));
$PAGE->set_pagelayout('admin');

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managerecertify', 'local_recertify'));

// Add schedule button.
$addurl = new moodle_url('/local/recertify/edit.php', ['action' => 'add']);
echo html_writer::link($addurl, get_string('addschedule', 'local_recertify'), ['class' => 'btn btn-primary mb-3']);

// List active schedules.
$schedules = $DB->get_records('local_recertify_schedule', null, 'courseid ASC');

if (!$schedules) {
    echo $OUTPUT->notification(get_string('thereareno', 'moodle', get_string('schedules', 'local_recertify')), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('course'),
        get_string('scheduletype', 'local_recertify'),
        get_string('intervalmonths', 'local_recertify'),
        get_string('resetdepth', 'local_recertify'),
        get_string('enabled'),
        get_string('actions'),
    ];
    $table->align = ['left', 'left', 'center', 'left', 'center', 'center'];

    foreach ($schedules as $s) {
        $course  = $DB->get_record('course', ['id' => $s->courseid]);
        $editurl = new moodle_url('/local/recertify/edit.php', ['id' => $s->id]);
        $delurl  = new moodle_url('/local/recertify/index.php', ['action' => 'delete', 'id' => $s->id, 'sesskey' => sesskey()]);

        $actions = html_writer::link($editurl, get_string('edit')) . ' | '
                 . html_writer::link($delurl, get_string('delete'),
                     ['onclick' => 'return confirm("' . get_string('deleteconfirm', 'admin') . '")']);

        $table->data[] = [
            $course ? format_string($course->fullname) : $s->courseid,
            get_string('scheduletype_' . $s->scheduletype, 'local_recertify'),
            $s->intervalmonths,
            get_string('resetdepth_' . $s->resetdepth, 'local_recertify'),
            $s->enabled ? get_string('yes') : get_string('no'),
            $actions,
        ];
    }
    echo html_writer::table($table);
}

// Audit log section.
echo $OUTPUT->heading(get_string('auditlog', 'local_recertify'), 4);

$logs = $DB->get_records('local_recertify_log', null, 'timecreated DESC', '*', 0, 50);
if ($logs) {
    $logtable = new html_table();
    $logtable->head = [get_string('user'), get_string('course'), get_string('resetdepth', 'local_recertify'), get_string('time')];
    $logtable->align = ['left', 'left', 'left', 'left'];
    foreach ($logs as $log) {
        $u = $DB->get_record('user', ['id' => $log->userid]);
        $c = $DB->get_record('course', ['id' => $log->courseid]);
        $logtable->data[] = [
            $u ? fullname($u) : $log->userid,
            $c ? format_string($c->shortname) : $log->courseid,
            $log->depth,
            userdate($log->timecreated),
        ];
    }
    echo html_writer::table($logtable);

    $exporturl = new moodle_url('/local/recertify/export.php', ['format' => 'csv', 'sesskey' => sesskey()]);
    echo html_writer::link($exporturl, get_string('exportauditlog', 'local_recertify'), ['class' => 'btn btn-secondary btn-sm mt-2']);
}

echo $OUTPUT->footer();
