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
 * Library functions for local_recertify.
 *
 * @package    local_recertify
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Return the site-wide defaults used to seed a new schedule.
 *
 * @return stdClass
 */
function local_recertify_get_defaults(): stdClass {
    return (object)[
        'enabled' => 1,
        'scheduletype' => get_config('local_recertify', 'scheduletype') ?: 'relative',
        'intervalmonths' => max(1, (int)get_config('local_recertify', 'intervalmonths') ?: 12),
        'fixeddate' => get_config('local_recertify', 'fixeddate') ?: '01-01',
        'warningdays' => max(0, (int)get_config('local_recertify', 'warningdays')),
        'resetdepth' => get_config('local_recertify', 'resetdepth') ?: 'completion',
        'wipeassignments' => (int)get_config('local_recertify', 'wipeassignments'),
        'wipequizattempts' => (int)get_config('local_recertify', 'wipequizattempts'),
        'wipescormtracks' => (int)get_config('local_recertify', 'wipescormtracks'),
        'notifyonreset' => (int)get_config('local_recertify', 'notifyonreset'),
    ];
}

/**
 * Parse a MM-DD fixed date into month and day, falling back to 1 January.
 *
 * @param string|null $fixeddate
 * @return array{0: int, 1: int} Month and day.
 */
function local_recertify_parse_fixeddate(?string $fixeddate): array {
    $month = 1;
    $day = 1;

    if (!empty($fixeddate) && preg_match('/^(\d{1,2})-(\d{1,2})$/', trim($fixeddate), $m)) {
        $month = min(12, max(1, (int)$m[1]));
        $day = min(31, max(1, (int)$m[2]));
    }

    return [$month, $day];
}

/**
 * Return the interval in months for a schedule, never less than one.
 *
 * A zero interval would make the date walk below loop forever, so it is clamped here
 * as well as validated in the schedule form and the site settings.
 *
 * @param stdClass $schedule
 * @return int
 */
function local_recertify_interval_months(stdClass $schedule): int {
    return max(1, (int)$schedule->intervalmonths);
}

/**
 * Return the next reset timestamp in the future for a learner under a schedule.
 *
 * This is the date an advance warning is sent about. It is always in the future, so it
 * must never be used to decide whether a reset is due; use
 * local_recertify_last_due_reset_time() for that.
 *
 * @param stdClass $schedule The schedule record.
 * @param int      $timestart The learner's enrolment start timestamp.
 * @param int|null $now Current time, for testing.
 * @param int      $timecompleted The learner's course completion timestamp, 0 if not completed.
 * @return int Unix timestamp of the next reset, or 0 if none is scheduled.
 */
function local_recertify_next_reset_time(
    stdClass $schedule,
    int $timestart,
    ?int $now = null,
    int $timecompleted = 0
): int {
    $now = $now ?? time();

    if ($schedule->scheduletype === 'completion') {
        // The clock only starts once the learner has completed the course, so a learner
        // who has never completed it has no upcoming reset at all.
        if ($timecompleted <= 0) {
            return 0;
        }

        $months = local_recertify_interval_months($schedule);
        $due = strtotime("+{$months} months", $timecompleted);
        if ($due === false) {
            return 0;
        }

        // Unlike the other two types this boundary does not repeat on its own: once it
        // has passed the reset is due, and the next one is not known until the learner
        // completes the course again.
        return $due > $now ? $due : 0;
    }

    if ($schedule->scheduletype === 'fixed') {
        [$month, $day] = local_recertify_parse_fixeddate($schedule->fixeddate);
        $year = (int)date('Y', $now);
        $ts = make_timestamp($year, $month, $day, 1, 0, 0, 99, true);
        if ($ts <= $now) {
            $ts = make_timestamp($year + 1, $month, $day, 1, 0, 0, 99, true);
        }
        return $ts;
    }

    // Relative schedule: anniversary of the enrolment start date.
    $months = local_recertify_interval_months($schedule);
    $base = $timestart > 0 ? $timestart : $now;
    $iterations = 0;

    while ($base <= $now && $iterations < 1000) {
        $base = strtotime("+{$months} months", $base);
        $iterations++;
    }

    return $base;
}

/**
 * Return the most recent reset boundary that has already passed for a learner.
 *
 * This is the value the task compares against to decide whether a reset is due, and it
 * doubles as the cycle identifier stored in the audit log so a learner cannot be reset
 * twice for the same cycle.
 *
 * @param stdClass $schedule The schedule record.
 * @param int      $timestart The learner's enrolment start timestamp.
 * @param int|null $now Current time, for testing.
 * @param int      $timecompleted The learner's course completion timestamp, 0 if not completed.
 * @return int Unix timestamp of the last due reset, or 0 if none is due yet.
 */
function local_recertify_last_due_reset_time(
    stdClass $schedule,
    int $timestart,
    ?int $now = null,
    int $timecompleted = 0
): int {
    $now = $now ?? time();

    if ($schedule->scheduletype === 'completion') {
        // Measured from the learner's own course completion date. A learner who has not
        // completed the course has no history to wipe and is left alone.
        if ($timecompleted <= 0) {
            return 0;
        }

        $months = local_recertify_interval_months($schedule);
        $due = strtotime("+{$months} months", $timecompleted);
        if ($due === false || $due > $now) {
            return 0;
        }

        // The reset clears the completion record, which is the anchor for this schedule
        // type, so the learner drops out of scope until they complete the course again.
        // That is what makes the cycle repeat without a date walk.
        return $due;
    }

    if ($schedule->scheduletype === 'fixed') {
        [$month, $day] = local_recertify_parse_fixeddate($schedule->fixeddate);
        $year = (int)date('Y', $now);
        $ts = make_timestamp($year, $month, $day, 1, 0, 0, 99, true);
        if ($ts > $now) {
            $ts = make_timestamp($year - 1, $month, $day, 1, 0, 0, 99, true);
        }
        // A learner who enrolled after the boundary has not completed a cycle yet.
        if ($timestart > 0 && $ts <= $timestart) {
            return 0;
        }
        return $ts;
    }

    // Relative schedule.
    if ($timestart <= 0) {
        // Without an enrolment start date there is no anniversary to measure from.
        return 0;
    }

    $months = local_recertify_interval_months($schedule);
    $last = 0;
    $base = $timestart;
    $iterations = 0;

    while ($iterations < 1000) {
        $next = strtotime("+{$months} months", $base);
        if ($next > $now) {
            break;
        }
        $last = $next;
        $base = $next;
        $iterations++;
    }

    return $last;
}

/**
 * Reset a learner's progress in a course, according to the schedule's reset depth.
 *
 * Depths are cumulative:
 *  - completion: activity and course completion state
 *  - grades: the above, plus the learner's grades in the course
 *  - full: the above, plus attempts and submissions for the selected activity types
 *
 * Activity data is removed through each module's own API so that grades, question
 * attempt steps and submitted files are cleaned up with it.
 *
 * @param int      $userid The target user.
 * @param int      $courseid The target course.
 * @param stdClass $schedule The schedule record (depth and wipe options).
 */
function local_recertify_reset_user(int $userid, int $courseid, stdClass $schedule): void {
    global $DB, $CFG;

    require_once($CFG->libdir . '/completionlib.php');
    require_once($CFG->libdir . '/gradelib.php');

    $course = $DB->get_record('course', ['id' => $courseid], '*', MUST_EXIST);
    $user = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);

    // A full wipe must happen before completion is cleared, because deleting attempts
    // can itself write completion state back.
    if ($schedule->resetdepth === 'full') {
        local_recertify_wipe_activity_data($user, $course, $schedule);
    }

    // Completion state. Handled directly because core has no per-user course
    // completion reset, then the completion cache is invalidated so the learner does
    // not keep seeing stale ticks.
    $DB->delete_records_select(
        'course_modules_completion',
        'userid = :uid AND coursemoduleid IN (SELECT id FROM {course_modules} WHERE course = :cid)',
        ['uid' => $userid, 'cid' => $courseid]
    );

    if ($DB->get_manager()->table_exists('course_modules_viewed')) {
        $DB->delete_records_select(
            'course_modules_viewed',
            'userid = :uid AND coursemoduleid IN (SELECT id FROM {course_modules} WHERE course = :cid)',
            ['uid' => $userid, 'cid' => $courseid]
        );
    }

    // Grades. Remove the learner's grades for every grade item in this course.
    if ($schedule->resetdepth !== 'completion') {
        $DB->delete_records_select(
            'grade_grades',
            'userid = :uid AND itemid IN (SELECT id FROM {grade_items} WHERE courseid = :cid)',
            ['uid' => $userid, 'cid' => $courseid]
        );

        // The gradebook is flagged for recalculation rather than regraded inline.
        //
        // grade_regrade_final_grades($courseid, $userid) was called here previously, and
        // core rejects that: the third argument, the single grade item whose raw grade
        // changed, is mandatory whenever a user id is given, so the call threw
        // "updated_item cannot be null!" on every grades-depth and full-depth reset.
        // There is no single such item to name here in any case, because the delete
        // above spans every grade item in the course.
        //
        // Flagging is also the cheaper half of the fix. The learner's rows are gone from
        // the category and course totals as well as the leaf items, so nothing of theirs
        // is left to recompute, and a course with a thousand learners falling due on the
        // same day no longer means a thousand inline full-course regrades.
        $courseitem = grade_item::fetch_course_item($courseid);
        if ($courseitem) {
            $courseitem->force_regrading();
        }
    }

    // The course completion records are deleted last, deliberately, for two reasons.
    //
    // course_completions is the anchor a 'completion' schedule measures from, and the
    // task's failure path deletes its cycle marker so the learner is retried on the next
    // run. Removing this row before the grade work above would break that: a throw from
    // the regrade would leave the learner with no completion record, hence no anchor, so
    // the retry would find nothing due and the partial reset would never be finished.
    //
    // The regrade also fires grading events, and the criteria review those events
    // trigger can write completion rows back. Clearing both tables afterwards means the
    // learner does not come out of the reset still marked complete.
    $DB->delete_records('course_completion_crit_compl', ['userid' => $userid, 'course' => $courseid]);
    $DB->delete_records('course_completions', ['userid' => $userid, 'course' => $courseid]);

    // Both completion caches are keyed on "{userid}_{courseid}", so the learner's own
    // entries can be dropped without purging the cache for the whole site.
    $cachekey = $userid . '_' . $courseid;
    cache::make('core', 'completion')->delete($cachekey);
    cache::make('core', 'coursecompletion')->delete($cachekey);
}

/**
 * Remove a learner's attempts and submissions for the activity types selected on a schedule.
 *
 * @param stdClass $user The user record.
 * @param stdClass $course The course record.
 * @param stdClass $schedule The schedule record.
 */
function local_recertify_wipe_activity_data(stdClass $user, stdClass $course, stdClass $schedule): void {
    global $DB, $CFG;

    if (!empty($schedule->wipequizattempts)) {
        require_once($CFG->dirroot . '/mod/quiz/lib.php');
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');

        $quizids = $DB->get_fieldset_select('quiz', 'id', 'course = :cid', ['cid' => $course->id]);
        foreach ($quizids as $quizid) {
            try {
                $quizobj = \mod_quiz\quiz_settings::create((int)$quizid);
                // Removes attempts, quiz grades and the question usages behind them.
                quiz_delete_user_attempts($quizobj, $user);
                quiz_update_grades($quizobj->get_quiz(), $user->id);
            } catch (Throwable $e) {
                debugging('local_recertify: could not reset quiz ' . $quizid . ' for user '
                    . $user->id . ': ' . $e->getMessage(), DEBUG_NORMAL);
            }
        }
    }

    if (!empty($schedule->wipeassignments)) {
        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        $assigns = $DB->get_records('assign', ['course' => $course->id], '', 'id');
        foreach (array_keys($assigns) as $assignid) {
            try {
                $cm = get_coursemodule_from_instance('assign', $assignid, $course->id, false, MUST_EXIST);
                $assigncontext = context_module::instance($cm->id);
                $assign = new assign($assigncontext, $cm, $course);

                // Ask each submission and feedback subplugin to remove its own rows and
                // files, using the same per-user callbacks mod_assign's privacy provider
                // uses. Deleting the assign_* rows alone would orphan submitted files.
                $submissions = $DB->get_records(
                    'assign_submission',
                    ['assignment' => $assignid, 'userid' => $user->id]
                );
                foreach ($submissions as $submission) {
                    $requestdata = new \mod_assign\privacy\assign_plugin_request_data(
                        $assigncontext,
                        $assign,
                        $submission,
                        [],
                        $user
                    );
                    \core_privacy\manager::plugintype_class_callback(
                        'assignsubmission',
                        \mod_assign\privacy\assignsubmission_provider::class,
                        'delete_submission_for_userid',
                        [$requestdata]
                    );
                }

                $grades = $DB->get_records('assign_grades', ['assignment' => $assignid, 'userid' => $user->id]);
                foreach ($grades as $grade) {
                    $requestdata = new \mod_assign\privacy\assign_plugin_request_data(
                        $assigncontext,
                        $assign,
                        $grade,
                        [],
                        $user
                    );
                    \core_privacy\manager::plugintype_class_callback(
                        'assignfeedback',
                        \mod_assign\privacy\assignfeedback_provider::class,
                        'delete_feedback_for_grade',
                        [$requestdata]
                    );
                }

                $DB->delete_records('assign_user_flags', ['assignment' => $assignid, 'userid' => $user->id]);
                $DB->delete_records('assign_user_mapping', ['assignment' => $assignid, 'userid' => $user->id]);
                $DB->delete_records('assign_grades', ['assignment' => $assignid, 'userid' => $user->id]);
                $DB->delete_records('assign_submission', ['assignment' => $assignid, 'userid' => $user->id]);

                assign_update_grades($assign->get_instance(), $user->id);
            } catch (Throwable $e) {
                debugging('local_recertify: could not reset assignment ' . $assignid . ' for user '
                    . $user->id . ': ' . $e->getMessage(), DEBUG_NORMAL);
            }
        }
    }

    if (!empty($schedule->wipescormtracks)) {
        require_once($CFG->dirroot . '/mod/scorm/lib.php');
        require_once($CFG->dirroot . '/mod/scorm/locallib.php');

        // The API is used rather than deleting rows directly, because the SCORM tracking
        // schema changed in Moodle 5.0: scorm_scoes_track was replaced by scorm_attempt
        // and scorm_scoes_value. scorm_delete_tracks() handles both layouts.
        $scorms = $DB->get_records('scorm', ['course' => $course->id], '', 'id');
        foreach (array_keys($scorms) as $scormid) {
            try {
                scorm_delete_tracks((int)$scormid, null, (int)$user->id);
                $scorm = $DB->get_record('scorm', ['id' => $scormid], '*', MUST_EXIST);
                scorm_update_grades($scorm, $user->id);
            } catch (Throwable $e) {
                debugging('local_recertify: could not reset SCORM ' . $scormid . ' for user '
                    . $user->id . ': ' . $e->getMessage(), DEBUG_NORMAL);
            }
        }
    }
}

/**
 * Log a recertification action to the audit table.
 *
 * Idempotency comes from the lookup below, not from a database constraint: the index on
 * (userid, courseid, action, resettime) is deliberately not unique, because rows written
 * before that column existed all carry resettime 0 and a unique index could fail to build
 * on an existing site. A duplicate for the same cycle is reported by the return value
 * rather than raising an error. Concurrent runs are prevented by the scheduled task lock.
 *
 * @param int    $scheduleid The schedule id.
 * @param int    $courseid The course id.
 * @param int    $userid The user id.
 * @param string $depth Reset depth label.
 * @param string $action 'reset' or 'warned'.
 * @param int    $resettime The cycle boundary this row belongs to.
 * @param string $triggertype 'scheduled' or 'manual'.
 * @return bool True if a row was written, false if one already existed for this cycle.
 */
function local_recertify_log(
    int $scheduleid,
    int $courseid,
    int $userid,
    string $depth,
    string $action = 'reset',
    int $resettime = 0,
    string $triggertype = 'scheduled'
): bool {
    global $DB;

    if (!get_config('local_recertify', 'enableauditlog') && $action === 'reset') {
        // The audit log can be switched off, but cycle bookkeeping still needs the row,
        // so only the optional detail is dropped, never the row itself.
        $depth = '';
    }

    $existing = $DB->record_exists('local_recertify_log', [
        'userid' => $userid,
        'courseid' => $courseid,
        'action' => $action,
        'resettime' => $resettime,
    ]);
    if ($existing) {
        return false;
    }

    $DB->insert_record('local_recertify_log', (object)[
        'scheduleid' => $scheduleid,
        'courseid' => $courseid,
        'userid' => $userid,
        'action' => $action,
        'depth' => $depth,
        'triggertype' => $triggertype,
        'resettime' => $resettime,
        'timecreated' => time(),
    ]);

    return true;
}
