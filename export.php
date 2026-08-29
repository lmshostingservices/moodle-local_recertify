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
 * CSV export of the recertification audit log.
 *
 * @package    local_recertify
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/csvlib.class.php');

require_login();
$context = context_system::instance();
require_capability('local/recertify:viewlog', $context);
require_sesskey();

$PAGE->set_context($context);
$PAGE->set_url(new moodle_url('/local/recertify/export.php'));

$filename = 'recertify-auditlog-' . userdate(time(), '%Y%m%d-%H%M');
$csv = new csv_export_writer();
$csv->set_filename($filename);

$header = [
    get_string('time'),
    get_string('course'),
    get_string('courseid', 'local_recertify'),
    get_string('user'),
    get_string('userid', 'local_recertify'),
    get_string('email'),
    get_string('action', 'local_recertify'),
    get_string('resetdepth', 'local_recertify'),
    get_string('trigger', 'local_recertify'),
    get_string('cycledate', 'local_recertify'),
];
$csv->add_data($header);

// Every field fullname() needs. None of these names clash with the log columns.
$namefields = \core_user\fields::get_name_fields();
$nameselects = '';
foreach ($namefields as $field) {
    $nameselects .= ', u.' . $field;
}

// Streamed so a large log does not exhaust memory.
$sql = "SELECT l.*,
               u.email,
               c.shortname
               {$nameselects}
          FROM {local_recertify_log} l
     LEFT JOIN {user} u ON u.id = l.userid
     LEFT JOIN {course} c ON c.id = l.courseid
      ORDER BY l.timecreated DESC";
$rs = $DB->get_recordset_sql($sql);

foreach ($rs as $log) {
    $row = [
        userdate($log->timecreated),
        $log->shortname !== null ? format_string($log->shortname) : '',
        $log->courseid,
        $log->firstname !== null ? fullname($log) : '',
        $log->userid,
        $log->email ?? '',
        get_string('log_' . $log->action, 'local_recertify'),
        $log->depth !== '' ? get_string('resetdepth_' . $log->depth, 'local_recertify') : '',
        $log->triggertype,
        $log->resettime ? userdate($log->resettime) : '',
    ];
    $csv->add_data($row);
}
$rs->close();

$csv->download_file();
