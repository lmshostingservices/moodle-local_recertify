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
 * Admin index page for local_recertify.
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

$action = optional_param('action', 'list', PARAM_ALPHA);
$id = optional_param('id', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$page = optional_param('page', 0, PARAM_INT);

$perpage = 50;
$baseurl = new moodle_url('/local/recertify/index.php');

// Delete a schedule.
if ($action === 'delete' && $id) {
    require_capability('local/recertify:manage', $context);

    $schedule = $DB->get_record('local_recertify_schedule', ['id' => $id], '*', MUST_EXIST);
    $course = $DB->get_record('course', ['id' => $schedule->courseid], 'id, fullname');
    $coursename = $course ? format_string($course->fullname) : $schedule->courseid;

    if ($confirm && confirm_sesskey()) {
        $DB->delete_records('local_recertify_schedule', ['id' => $id]);
        redirect(
            $baseurl,
            get_string('scheduledeleted', 'local_recertify', $coursename),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }

    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('deleteconfirm', 'local_recertify', $coursename),
        new moodle_url($baseurl, ['action' => 'delete', 'id' => $id, 'confirm' => 1, 'sesskey' => sesskey()]),
        $baseurl
    );
    echo $OUTPUT->footer();
    exit;
}

// Toggle a schedule on or off.
if ($action === 'toggle' && $id && confirm_sesskey()) {
    require_capability('local/recertify:manage', $context);

    $schedule = $DB->get_record('local_recertify_schedule', ['id' => $id], '*', MUST_EXIST);
    $DB->set_field('local_recertify_schedule', 'enabled', $schedule->enabled ? 0 : 1, ['id' => $id]);
    $DB->set_field('local_recertify_schedule', 'timemodified', time(), ['id' => $id]);
    redirect($baseurl);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managerecertify', 'local_recertify'));

// Add schedule button.
$addurl = new moodle_url('/local/recertify/edit.php');
echo html_writer::link(
    $addurl,
    get_string('addschedule', 'local_recertify'),
    ['class' => 'btn btn-primary mb-3']
);

$schedules = $DB->get_records('local_recertify_schedule', null, 'courseid ASC');

if (!$schedules) {
    echo $OUTPUT->notification(get_string('noschedules', 'local_recertify'), 'info');
} else {
    $table = new html_table();
    $table->head = [
        get_string('course'),
        get_string('scheduletype', 'local_recertify'),
        get_string('interval', 'local_recertify'),
        get_string('resetdepth', 'local_recertify'),
        get_string('enabled', 'admin'),
        get_string('actions'),
    ];
    $table->align = ['left', 'left', 'center', 'left', 'center', 'center'];
    $table->attributes['class'] = 'generaltable';

    foreach ($schedules as $s) {
        $course = $DB->get_record('course', ['id' => $s->courseid], 'id, fullname');

        $months = local_recertify_interval_months($s);
        if ($s->scheduletype === 'fixed') {
            $interval = s($s->fixeddate);
        } else if ($s->scheduletype === 'completion') {
            $interval = $months === 1
                ? get_string('monthaftercompletion', 'local_recertify')
                : get_string('monthsaftercompletion', 'local_recertify', $months);
        } else {
            $interval = get_string('everynmonths', 'local_recertify', $months);
        }

        $editurl = new moodle_url('/local/recertify/edit.php', ['id' => $s->id]);
        $delurl = new moodle_url($baseurl, ['action' => 'delete', 'id' => $s->id, 'sesskey' => sesskey()]);
        $toggleurl = new moodle_url($baseurl, ['action' => 'toggle', 'id' => $s->id, 'sesskey' => sesskey()]);

        $actions = html_writer::link($editurl, get_string('edit'), ['class' => 'btn btn-sm btn-outline-primary'])
            . ' '
            . html_writer::link(
                $toggleurl,
                $s->enabled ? get_string('disable') : get_string('enable'),
                ['class' => 'btn btn-sm btn-outline-secondary']
            )
            . ' '
            . html_writer::link($delurl, get_string('delete'), ['class' => 'btn btn-sm btn-outline-danger']);

        $table->data[] = [
            $course
                ? html_writer::link(
                    new moodle_url('/course/view.php', ['id' => $course->id]),
                    format_string($course->fullname)
                )
                : $s->courseid,
            get_string('scheduletype_' . $s->scheduletype, 'local_recertify'),
            $interval,
            get_string('resetdepth_' . $s->resetdepth, 'local_recertify'),
            $s->enabled ? $OUTPUT->pix_icon('i/checked', get_string('yes')) : '',
            $actions,
        ];
    }
    echo html_writer::table($table);
}

// Audit log.
if (has_capability('local/recertify:viewlog', $context)) {
    echo $OUTPUT->heading(get_string('auditlog', 'local_recertify'), 3);

    $total = $DB->count_records('local_recertify_log');
    $logs = $DB->get_records(
        'local_recertify_log',
        null,
        'timecreated DESC',
        '*',
        $page * $perpage,
        $perpage
    );

    if (!$logs) {
        echo $OUTPUT->notification(get_string('nologentries', 'local_recertify'), 'info');
    } else {
        $logtable = new html_table();
        $logtable->head = [
            get_string('user'),
            get_string('course'),
            get_string('action', 'local_recertify'),
            get_string('resetdepth', 'local_recertify'),
            get_string('time'),
        ];
        $logtable->align = ['left', 'left', 'left', 'left', 'left'];
        $logtable->attributes['class'] = 'generaltable';

        foreach ($logs as $log) {
            $u = $DB->get_record('user', ['id' => $log->userid]);
            $c = $DB->get_record('course', ['id' => $log->courseid], 'id, shortname');

            $logtable->data[] = [
                $u ? fullname($u) : $log->userid,
                $c ? format_string($c->shortname) : $log->courseid,
                get_string('log_' . $log->action, 'local_recertify'),
                $log->depth !== '' ? get_string('resetdepth_' . $log->depth, 'local_recertify') : '-',
                userdate($log->timecreated),
            ];
        }
        echo html_writer::table($logtable);
        echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);

        $exporturl = new moodle_url('/local/recertify/export.php', ['sesskey' => sesskey()]);
        echo html_writer::link(
            $exporturl,
            get_string('exportauditlog', 'local_recertify'),
            ['class' => 'btn btn-secondary btn-sm mt-2']
        );
    }
}

echo $OUTPUT->footer();
