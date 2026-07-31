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
 * Library functions for local_recertify.
 *
 * @package    local_recertify
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Return the next reset timestamp for a given enrolment under a schedule.
 *
 * @param stdClass $schedule  The schedule record.
 * @param int      $timestart The learner's enrolment start timestamp.
 * @return int Unix timestamp of next reset, or 0 if not yet due.
 */
function local_recertify_next_reset_time(stdClass $schedule, int $timestart): int {
    if ($schedule->scheduletype === 'fixed') {
        [$month, $day] = explode('-', $schedule->fixeddate);
        $year = (int)date('Y');
        $ts   = mktime(1, 0, 0, (int)$month, (int)$day, $year);
        if ($ts <= time()) {
            $ts = mktime(1, 0, 0, (int)$month, (int)$day, $year + 1);
        }
        return $ts;
    }
    // Relative schedule: anniversary from enrolment start.
    $months = (int)$schedule->intervalmonths;
    $base   = $timestart;
    while ($base <= time()) {
        $base = strtotime("+{$months} months", $base);
    }
    return $base;
}

/**
 * Reset completion records for a user in a course, according to depth.
 *
 * @param int      $userid   The target user.
 * @param int      $courseid The target course.
 * @param stdClass $schedule The schedule record (depth + wipe options).
 */
function local_recertify_reset_user(int $userid, int $courseid, stdClass $schedule): void {
    global $DB, $CFG;
    require_once($CFG->libdir . '/completionlib.php');

    $course     = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $completion = new completion_info($course);

    // Always reset activity completion marks.
    $cms = $DB->get_records('course_modules', ['course' => $courseid]);
    foreach ($cms as $cm) {
        $DB->delete_records('course_modules_completion', ['coursemoduleid' => $cm->id, 'userid' => $userid]);
    }
    // Reset the course completion record.
    $completion->delete_course_completion_data_for_user($userid);

    if ($schedule->resetdepth === 'completion') {
        return;
    }

    // Completion + grades: remove gradebook entries.
    $DB->delete_records_select('grade_grades',
        'userid = :uid AND itemid IN (SELECT id FROM {grade_items} WHERE courseid = :cid)',
        ['uid' => $userid, 'cid' => $courseid]);

    if ($schedule->resetdepth !== 'full') {
        return;
    }

    // Full wipe.
    if (!empty($schedule->wipequizattempts)) {
        $quizids = $DB->get_fieldset_select('quiz', 'id', 'course = :cid', ['cid' => $courseid]);
        if ($quizids) {
            [$sql, $params] = $DB->get_in_or_equal($quizids, SQL_PARAMS_NAMED, 'qid');
            $params['uid'] = $userid;
            $DB->delete_records_select('quiz_attempts', "userid = :uid AND quiz {$sql}", $params);
        }
    }
    if (!empty($schedule->wipeassignments)) {
        $assignids = $DB->get_fieldset_select('assign', 'id', 'course = :cid', ['cid' => $courseid]);
        if ($assignids) {
            [$sql, $params] = $DB->get_in_or_equal($assignids, SQL_PARAMS_NAMED, 'aid');
            $params['uid'] = $userid;
            $DB->delete_records_select('assign_submission', "userid = :uid AND assignment {$sql}", $params);
        }
    }
    if (!empty($schedule->wipescormtracks)) {
        $scormids = $DB->get_fieldset_select('scorm', 'id', 'course = :cid', ['cid' => $courseid]);
        if ($scormids) {
            [$sql, $params] = $DB->get_in_or_equal($scormids, SQL_PARAMS_NAMED, 'sid');
            $params['uid'] = $userid;
            $DB->delete_records_select('scorm_scoes_track', "userid = :uid AND scormid {$sql}", $params);
        }
    }
}

/**
 * Log a recertification reset to the audit table.
 *
 * @param int      $scheduleid The schedule ID.
 * @param int      $courseid   The course ID.
 * @param int      $userid     The user ID.
 * @param string   $depth      Reset depth label.
 * @param string   $trigger    'scheduled' or 'manual'.
 */
function local_recertify_log(int $scheduleid, int $courseid, int $userid, string $depth, string $trigger = 'scheduled'): void {
    global $DB;
    $DB->insert_record('local_recertify_log', (object)[
        'scheduleid'  => $scheduleid,
        'courseid'    => $courseid,
        'userid'      => $userid,
        'depth'       => $depth,
        'trigger'     => $trigger,
        'timecreated' => time(),
    ]);
}
