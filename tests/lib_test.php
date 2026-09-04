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
 * Unit tests for local_recertify scheduling and logging.
 *
 * @package    local_recertify
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_recertify;

/**
 * Tests for the recertification date logic and the audit log.
 *
 * @covers ::local_recertify_next_reset_time
 * @covers ::local_recertify_last_due_reset_time
 * @covers ::local_recertify_log
 */
final class lib_test extends \advanced_testcase {
    /**
     * Load the plugin library before each test.
     */
    public function setUp(): void {
        global $CFG;
        parent::setUp();
        require_once($CFG->dirroot . '/local/recertify/lib.php');
        $this->resetAfterTest(true);
    }

    /**
     * Build a schedule record for testing.
     *
     * @param array $overrides Field overrides.
     * @return \stdClass
     */
    protected function schedule(array $overrides = []): \stdClass {
        $defaults = [
            'id' => 1,
            'courseid' => 2,
            'enabled' => 1,
            'scheduletype' => 'relative',
            'intervalmonths' => 12,
            'fixeddate' => '01-01',
            'warningdays' => 14,
            'resetdepth' => 'completion',
            'wipeassignments' => 0,
            'wipequizattempts' => 0,
            'wipescormtracks' => 0,
            'notifyonreset' => 1,
        ];

        return (object)array_merge($defaults, $overrides);
    }

    /**
     * A learner three years into a 12-month cycle has a reset due.
     *
     * This is the regression test for the release in which the due-date comparison could
     * never be true, so no learner was ever reset.
     */
    public function test_relative_reset_becomes_due(): void {
        $now = mktime(12, 0, 0, 8, 29, 2026);
        $timestart = mktime(12, 0, 0, 8, 29, 2023);

        $schedule = $this->schedule(['intervalmonths' => 12]);

        $due = local_recertify_last_due_reset_time($schedule, $timestart, $now);
        $this->assertGreaterThan(0, $due, 'A reset should be due after three annual cycles');
        $this->assertLessThanOrEqual($now, $due, 'The due boundary must be in the past');

        // The most recent boundary is the third anniversary.
        $this->assertEquals(mktime(12, 0, 0, 8, 29, 2026), $due);

        // And the next one is a year later, in the future.
        $next = local_recertify_next_reset_time($schedule, $timestart, $now);
        $this->assertGreaterThan($now, $next);
    }

    /**
     * A learner who enrolled yesterday is not due for a reset.
     */
    public function test_new_learner_is_not_due(): void {
        $now = time();
        $schedule = $this->schedule(['intervalmonths' => 12]);

        $due = local_recertify_last_due_reset_time($schedule, $now - DAYSECS, $now);
        $this->assertSame(0, $due);
    }

    /**
     * A relative schedule with no enrolment start date has no anniversary to measure from.
     */
    public function test_relative_without_timestart_is_not_due(): void {
        $schedule = $this->schedule(['intervalmonths' => 12]);
        $this->assertSame(0, local_recertify_last_due_reset_time($schedule, 0, time()));
    }

    /**
     * A zero interval is clamped rather than looping forever.
     *
     * The previous release looped indefinitely on strtotime('+0 months'), hanging cron.
     */
    public function test_zero_interval_does_not_hang(): void {
        $now = mktime(12, 0, 0, 8, 29, 2026);
        $timestart = mktime(12, 0, 0, 1, 1, 2020);

        $schedule = $this->schedule(['intervalmonths' => 0]);

        // The interval is clamped to a minimum of one month.
        $this->assertSame(1, local_recertify_interval_months($schedule));

        $due = local_recertify_last_due_reset_time($schedule, $timestart, $now);
        $this->assertGreaterThan(0, $due);

        $next = local_recertify_next_reset_time($schedule, $timestart, $now);
        $this->assertGreaterThan($now, $next);
    }

    /**
     * A fixed schedule returns the most recent occurrence of the calendar date.
     */
    public function test_fixed_schedule_due_and_next(): void {
        $now = mktime(12, 0, 0, 8, 29, 2026);
        $timestart = mktime(12, 0, 0, 3, 1, 2020);

        $schedule = $this->schedule(['scheduletype' => 'fixed', 'fixeddate' => '07-01']);

        $due = local_recertify_last_due_reset_time($schedule, $timestart, $now);
        $this->assertLessThanOrEqual($now, $due);
        $this->assertSame('2026-07-01', date('Y-m-d', $due));

        $next = local_recertify_next_reset_time($schedule, $timestart, $now);
        $this->assertGreaterThan($now, $next);
        $this->assertSame('2027-07-01', date('Y-m-d', $next));
    }

    /**
     * A learner who enrolled after the last fixed occurrence is not reset by it.
     */
    public function test_fixed_schedule_skips_recent_enrolment(): void {
        $now = mktime(12, 0, 0, 8, 29, 2026);
        $timestart = mktime(12, 0, 0, 8, 1, 2026);

        $schedule = $this->schedule(['scheduletype' => 'fixed', 'fixeddate' => '07-01']);

        $this->assertSame(0, local_recertify_last_due_reset_time($schedule, $timestart, $now));
    }

    /**
     * A completion schedule wipes the record once the retention period has elapsed.
     */
    public function test_completion_schedule_becomes_due(): void {
        $now = mktime(12, 0, 0, 8, 29, 2026);
        $timestart = mktime(12, 0, 0, 1, 15, 2026);
        $timecompleted = mktime(12, 0, 0, 5, 1, 2026);

        $schedule = $this->schedule(['scheduletype' => 'completion', 'intervalmonths' => 3]);

        $due = local_recertify_last_due_reset_time($schedule, $timestart, $now, $timecompleted);
        $this->assertSame('2026-08-01', date('Y-m-d', $due));

        // The boundary has passed, so there is no upcoming one to warn about.
        $this->assertSame(0, local_recertify_next_reset_time($schedule, $timestart, $now, $timecompleted));
    }

    /**
     * A learner still inside the retention period is warned about, not reset.
     */
    public function test_completion_schedule_within_retention(): void {
        $now = mktime(12, 0, 0, 8, 29, 2026);
        $timestart = mktime(12, 0, 0, 1, 15, 2026);
        $timecompleted = mktime(12, 0, 0, 7, 1, 2026);

        $schedule = $this->schedule(['scheduletype' => 'completion', 'intervalmonths' => 3]);

        $this->assertSame(0, local_recertify_last_due_reset_time($schedule, $timestart, $now, $timecompleted));

        $next = local_recertify_next_reset_time($schedule, $timestart, $now, $timecompleted);
        $this->assertGreaterThan($now, $next);
        $this->assertSame('2026-10-01', date('Y-m-d', $next));
    }

    /**
     * A learner who has never completed the course is left alone entirely.
     *
     * The enrolment date must not be used as a fallback anchor here: there is no
     * completed record to retain, so nothing should be wiped.
     */
    public function test_completion_schedule_ignores_non_completers(): void {
        $now = mktime(12, 0, 0, 8, 29, 2026);
        $timestart = mktime(12, 0, 0, 1, 1, 2015);

        $schedule = $this->schedule(['scheduletype' => 'completion', 'intervalmonths' => 3]);

        $this->assertSame(0, local_recertify_last_due_reset_time($schedule, $timestart, $now, 0));
        $this->assertSame(0, local_recertify_next_reset_time($schedule, $timestart, $now, 0));
    }

    /**
     * A completion schedule with a zero interval is clamped rather than firing instantly.
     */
    public function test_completion_schedule_clamps_zero_interval(): void {
        $now = mktime(12, 0, 0, 8, 29, 2026);
        $timecompleted = mktime(12, 0, 0, 8, 20, 2026);

        $schedule = $this->schedule(['scheduletype' => 'completion', 'intervalmonths' => 0]);

        // Clamped to one month, so a learner who completed nine days ago is not yet due.
        $this->assertSame(0, local_recertify_last_due_reset_time($schedule, 0, $now, $timecompleted));

        $next = local_recertify_next_reset_time($schedule, 0, $now, $timecompleted);
        $this->assertSame('2026-09-20', date('Y-m-d', $next));
    }

    /**
     * A malformed fixed date falls back to 1 January rather than producing a bad timestamp.
     */
    public function test_fixeddate_parsing(): void {
        $this->assertSame([1, 1], local_recertify_parse_fixeddate(null));
        $this->assertSame([1, 1], local_recertify_parse_fixeddate('nonsense'));
        $this->assertSame([7, 1], local_recertify_parse_fixeddate('07-01'));
        $this->assertSame([12, 31], local_recertify_parse_fixeddate('12-31'));
    }

    /**
     * The audit log accepts a write and is idempotent per cycle.
     *
     * This is the regression test for the reserved-word column that made every insert
     * fail on MySQL and MariaDB, and for the duplicate warning emails.
     */
    public function test_log_write_and_idempotency(): void {
        global $DB;

        $cycle = mktime(12, 0, 0, 1, 1, 2026);

        $this->assertTrue(local_recertify_log(1, 2, 3, 'completion', 'reset', $cycle));
        $this->assertFalse(local_recertify_log(1, 2, 3, 'completion', 'reset', $cycle));
        $this->assertEquals(1, $DB->count_records('local_recertify_log', ['action' => 'reset']));

        // A different cycle is a separate row.
        $this->assertTrue(local_recertify_log(1, 2, 3, 'completion', 'reset', $cycle + YEARSECS));
        $this->assertEquals(2, $DB->count_records('local_recertify_log', ['action' => 'reset']));

        // Warnings are tracked separately from resets.
        $this->assertTrue(local_recertify_log(1, 2, 3, '', 'warned', $cycle));
        $this->assertFalse(local_recertify_log(1, 2, 3, '', 'warned', $cycle));

        $record = $DB->get_record('local_recertify_log', ['action' => 'warned']);
        $this->assertSame('scheduled', $record->triggertype);
    }

    /**
     * A completion-depth reset clears completion but leaves grades alone.
     */
    public function test_reset_user_completion_depth(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $DB->insert_record('course_completions', (object)[
            'userid' => $user->id,
            'course' => $course->id,
            'timeenrolled' => time(),
            'timestarted' => time(),
            'timecompleted' => time(),
        ]);

        $schedule = $this->schedule(['courseid' => $course->id, 'resetdepth' => 'completion']);
        local_recertify_reset_user((int)$user->id, (int)$course->id, $schedule);

        $this->assertFalse($DB->record_exists('course_completions', [
            'userid' => $user->id,
            'course' => $course->id,
        ]));
    }

    /**
     * A grades-depth reset clears both the grades and the completion record.
     *
     * The completion record is deleted after the grade work rather than before it,
     * because it is the anchor a completion-anchored schedule measures from and the task
     * retries a failed reset by re-deriving that anchor. This checks the reordering did
     * not leave the record behind.
     */
    public function test_reset_user_grades_depth(): void {
        global $DB, $CFG;

        require_once($CFG->libdir . '/gradelib.php');

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $DB->insert_record('course_completions', (object)[
            'userid' => $user->id,
            'course' => $course->id,
            'timeenrolled' => time(),
            'timestarted' => time(),
            'timecompleted' => time(),
        ]);

        $itemid = $DB->insert_record('grade_items', (object)[
            'courseid' => $course->id,
            'itemtype' => 'manual',
            'itemname' => 'Recertification test item',
            'gradetype' => GRADE_TYPE_VALUE,
            'grademax' => 100,
            'grademin' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
        $DB->insert_record('grade_grades', (object)[
            'itemid' => $itemid,
            'userid' => $user->id,
            'rawgrade' => 80,
            'finalgrade' => 80,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);

        $schedule = $this->schedule(['courseid' => $course->id, 'resetdepth' => 'grades']);
        local_recertify_reset_user((int)$user->id, (int)$course->id, $schedule);

        // The regrade that follows the delete can recreate empty rows for course totals,
        // so this asserts that no actual grade survives rather than that no row does.
        $remaining = $DB->get_field_sql(
            "SELECT COUNT(1)
               FROM {grade_grades} gg
               JOIN {grade_items} gi ON gi.id = gg.itemid
              WHERE gi.courseid = :cid AND gg.userid = :uid AND gg.finalgrade IS NOT NULL",
            ['cid' => $course->id, 'uid' => $user->id]
        );
        $this->assertEquals(0, $remaining);

        $this->assertFalse($DB->record_exists('course_completions', [
            'userid' => $user->id,
            'course' => $course->id,
        ]));
    }
}
