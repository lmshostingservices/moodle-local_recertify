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
 * End-to-end tests for the local_recertify scheduled task.
 *
 * @package    local_recertify
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_recertify\task;

/**
 * Tests that run the scheduled task against real enrolment and completion records.
 *
 * The date arithmetic is covered by lib_test. These tests cover the parts that only a
 * real database can show: the join onto course_completions, the null completion date,
 * and the claim that clearing the completion record is what makes the cycle repeat.
 *
 * @covers \local_recertify\task\process_recertification
 */
final class process_recertification_test extends \advanced_testcase {
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
     * Run the task, discarding its mtrace output.
     */
    protected function run_task(): void {
        ob_start();
        (new process_recertification())->execute();
        ob_end_clean();
    }

    /**
     * Create a completion-anchored schedule for a course.
     *
     * @param int   $courseid
     * @param array $overrides Field overrides.
     * @return int The schedule id.
     */
    protected function create_schedule(int $courseid, array $overrides = []): int {
        global $DB;

        return (int)$DB->insert_record('local_recertify_schedule', (object)array_merge([
            'courseid' => $courseid,
            'enabled' => 1,
            'scheduletype' => 'completion',
            'intervalmonths' => 3,
            'fixeddate' => '01-01',
            'warningdays' => 0,
            'resetdepth' => 'completion',
            'wipeassignments' => 0,
            'wipequizattempts' => 0,
            'wipescormtracks' => 0,
            'notifyonreset' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ], $overrides));
    }

    /**
     * Give a user a course completion record.
     *
     * @param int      $userid
     * @param int      $courseid
     * @param int|null $timecompleted Null for a record that exists but is still in progress.
     */
    protected function mark_completed(int $userid, int $courseid, ?int $timecompleted): void {
        global $DB;

        $DB->insert_record('course_completions', (object)[
            'userid' => $userid,
            'course' => $courseid,
            'timeenrolled' => 0,
            'timestarted' => 0,
            'timecompleted' => $timecompleted,
            'reaggregate' => 0,
        ]);
    }

    /**
     * Only a learner past the retention period is wiped, and only once.
     *
     * This exercises the three states the join can produce: a completion date old enough
     * to be due, one still inside the retention window, and no completion row at all.
     */
    public function test_completion_schedule_end_to_end(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);

        $due = $this->getDataGenerator()->create_user();
        $recent = $this->getDataGenerator()->create_user();
        $inprogress = $this->getDataGenerator()->create_user();
        $never = $this->getDataGenerator()->create_user();

        foreach ([$due, $recent, $inprogress, $never] as $user) {
            $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');
        }

        $this->mark_completed((int)$due->id, (int)$course->id, strtotime('-4 months'));
        $this->mark_completed((int)$recent->id, (int)$course->id, strtotime('-1 month'));
        // A row that exists but has a null completion date, which is the state every
        // learner is left in after a reset once core's completion cron revisits them.
        $this->mark_completed((int)$inprogress->id, (int)$course->id, null);
        // $never gets no row at all.

        $this->create_schedule((int)$course->id, ['intervalmonths' => 3]);

        $this->run_task();

        // Only the learner past the retention period is wiped.
        $this->assertFalse($DB->record_exists('course_completions', [
            'userid' => $due->id,
            'course' => $course->id,
        ]));
        $this->assertTrue($DB->record_exists('course_completions', [
            'userid' => $recent->id,
            'course' => $course->id,
        ]));
        $this->assertTrue($DB->record_exists('course_completions', [
            'userid' => $inprogress->id,
            'course' => $course->id,
        ]));

        $resets = $DB->get_records('local_recertify_log', ['action' => 'reset']);
        $this->assertCount(1, $resets);
        $this->assertEquals($due->id, reset($resets)->userid);

        // The learner has left scope, so a second run is a no-op rather than a second
        // reset. This is the mechanism the schedule type relies on to repeat.
        $this->run_task();
        $this->assertEquals(1, $DB->count_records('local_recertify_log', ['action' => 'reset']));
    }

    /**
     * Completing the course again starts a fresh retention period.
     */
    public function test_recompletion_starts_a_new_cycle(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $this->mark_completed((int)$user->id, (int)$course->id, strtotime('-4 months'));
        $this->create_schedule((int)$course->id, ['intervalmonths' => 3]);

        $this->run_task();
        $this->assertEquals(1, $DB->count_records('local_recertify_log', ['action' => 'reset']));

        // The learner completes again, inside the retention period: still nothing due.
        $this->mark_completed((int)$user->id, (int)$course->id, strtotime('-1 month'));
        $this->run_task();
        $this->assertEquals(1, $DB->count_records('local_recertify_log', ['action' => 'reset']));
        $this->assertTrue($DB->record_exists('course_completions', [
            'userid' => $user->id,
            'course' => $course->id,
        ]));

        // Once that newer completion ages past the retention period, it is wiped too,
        // under a different cycle key.
        $DB->set_field(
            'course_completions',
            'timecompleted',
            strtotime('-5 months'),
            ['userid' => $user->id, 'course' => $course->id]
        );
        $this->run_task();
        $this->assertEquals(2, $DB->count_records('local_recertify_log', ['action' => 'reset']));
        $this->assertFalse($DB->record_exists('course_completions', [
            'userid' => $user->id,
            'course' => $course->id,
        ]));
    }

    /**
     * A learner inside the warning window is warned exactly once, and non-completers are not.
     */
    public function test_warning_is_sent_once_and_only_to_completers(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $warned = $this->getDataGenerator()->create_user();
        $never = $this->getDataGenerator()->create_user();

        $this->getDataGenerator()->enrol_user($warned->id, $course->id, 'student');
        $this->getDataGenerator()->enrol_user($never->id, $course->id, 'student');

        // Completed just under three months ago, so the wipe is a week away.
        $this->mark_completed((int)$warned->id, (int)$course->id, strtotime('-3 months +7 days'));

        $this->create_schedule((int)$course->id, ['intervalmonths' => 3, 'warningdays' => 14]);

        $sink = $this->redirectMessages();
        $this->run_task();

        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $this->assertEquals($warned->id, $messages[0]->useridto);

        $warnings = $DB->get_records('local_recertify_log', ['action' => 'warned']);
        $this->assertCount(1, $warnings);
        $this->assertEquals($warned->id, reset($warnings)->userid);

        // Nothing has been reset: the retention period has not elapsed.
        $this->assertEquals(0, $DB->count_records('local_recertify_log', ['action' => 'reset']));

        // The window lasts a fortnight, but the learner is warned only once in it.
        $this->run_task();
        $sink->close();
        $this->assertEquals(1, $DB->count_records('local_recertify_log', ['action' => 'warned']));
    }

    /**
     * A grades-depth reset clears the learner's grades and completion together.
     *
     * The regrade this path performs used to throw, which the task caught and reported as
     * a failed reset on every run, so this covers the whole task path rather than just
     * the library function.
     */
    public function test_grades_depth_reset_through_the_task(): void {
        global $DB;

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $user = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->enrol_user($user->id, $course->id, 'student');

        $this->mark_completed((int)$user->id, (int)$course->id, strtotime('-4 months'));

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

        $this->create_schedule((int)$course->id, ['intervalmonths' => 3, 'resetdepth' => 'grades']);

        $this->run_task();

        // The reset completed rather than being caught and retried.
        $reset = $DB->get_record('local_recertify_log', ['action' => 'reset']);
        $this->assertNotEmpty($reset);
        $this->assertSame('grades', $reset->depth);

        $this->assertFalse($DB->record_exists('grade_grades', ['itemid' => $itemid, 'userid' => $user->id]));
        $this->assertFalse($DB->record_exists('course_completions', [
            'userid' => $user->id,
            'course' => $course->id,
        ]));
    }
}
