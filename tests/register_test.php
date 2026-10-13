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
 * Tests for the training record captured at reset time.
 *
 * @package    local_recertify
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_recertify;

use local_recertify\task\process_recertification;

/**
 * Covers the completion date and final grade recorded in the audit log in 1.4.0.
 *
 * The point of this feature is that the record outlives the data it describes, so most
 * of these tests assert on what is still there after the reset has destroyed the source.
 *
 * @covers ::local_recertify_log
 * @covers \local_recertify\task\process_recertification
 */
final class register_test extends \advanced_testcase {
    /** @var \stdClass Course under test. */
    protected $course;

    /** @var \stdClass Learner under test. */
    protected $user;

    /**
     * Load the plugin library before each test.
     */
    public function setUp(): void {
        global $CFG;

        parent::setUp();
        require_once($CFG->dirroot . '/local/recertify/lib.php');
        $this->resetAfterTest(true);

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['enablecompletion' => 1]);
        $this->user = $gen->create_user();
        $gen->enrol_user($this->user->id, $this->course->id, 'student');
    }

    /**
     * Give the learner a completion date and a course final grade.
     *
     * @param int   $timecompleted
     * @param float $finalgrade
     */
    protected function give_completion(int $timecompleted, float $finalgrade): void {
        global $DB, $CFG;

        require_once($CFG->libdir . '/gradelib.php');

        $DB->insert_record('course_completions', (object)[
            'userid' => $this->user->id,
            'course' => $this->course->id,
            'timeenrolled' => 0,
            'timestarted' => 0,
            'timecompleted' => $timecompleted,
            'reaggregate' => 0,
        ]);

        // Moodle creates the course total grade item lazily, so ask the API for it
        // rather than assuming a row already exists.
        $courseitem = \grade_item::fetch_course_item($this->course->id);
        $this->assertNotEmpty($courseitem);

        $DB->insert_record('grade_grades', (object)[
            'itemid' => $courseitem->id,
            'userid' => $this->user->id,
            'rawgrade' => $finalgrade,
            'finalgrade' => $finalgrade,
            'timecreated' => time(),
            'timemodified' => time(),
        ]);
    }

    /**
     * Create a completion-anchored schedule for the course.
     *
     * @param array $overrides
     * @return int
     */
    protected function create_schedule(array $overrides = []): int {
        global $DB;

        return (int)$DB->insert_record('local_recertify_schedule', (object)array_merge([
            'courseid' => $this->course->id,
            'enabled' => 1,
            'scheduletype' => 'completion',
            'intervalmonths' => 3,
            'fixeddate' => '01-01',
            'warningdays' => 0,
            'resetdepth' => 'completion',
            'activityscope' => 'all',
            'wipeassignments' => 0,
            'wipequizattempts' => 0,
            'wipescormtracks' => 0,
            'notifyonreset' => 0,
            'timecreated' => time(),
            'timemodified' => time(),
        ], $overrides));
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
     * The completion date and grade survive the reset that destroys them.
     */
    public function test_training_record_outlives_the_reset(): void {
        global $DB;

        $completed = strtotime('-4 months');
        $this->give_completion($completed, 85.0);
        $this->create_schedule();

        $this->run_task();

        // The source data is gone.
        $this->assertFalse($DB->record_exists('course_completions', [
            'userid' => $this->user->id,
            'course' => $this->course->id,
        ]));

        // The record of it is not.
        $log = $DB->get_record('local_recertify_log', [
            'userid' => $this->user->id,
            'action' => 'reset',
        ], '*', MUST_EXIST);

        $this->assertEquals($completed, $log->completiontime);
        $this->assertEqualsWithDelta(85.0, (float)$log->finalgrade, 0.001);

        // And it reads as "completed on X, reset on Y".
        $this->assertGreaterThan($log->completiontime, (int)$log->timecreated);
    }

    /**
     * Turning the audit log setting off must not discard the training record.
     *
     * That setting is about reset depth. The completion snapshot is the compliance
     * record and cannot be recovered once the reset has run, so it is always kept.
     */
    public function test_record_kept_even_when_audit_detail_disabled(): void {
        global $DB;

        set_config('enableauditlog', 0, 'local_recertify');

        $completed = strtotime('-4 months');
        $this->give_completion($completed, 72.5);
        $this->create_schedule();

        $this->run_task();

        $log = $DB->get_record('local_recertify_log', [
            'userid' => $this->user->id,
            'action' => 'reset',
        ], '*', MUST_EXIST);

        // Depth detail is dropped, as that setting intends.
        $this->assertSame('', $log->depth);

        // The training record is not.
        $this->assertEquals($completed, $log->completiontime);
        $this->assertEqualsWithDelta(72.5, (float)$log->finalgrade, 0.001);
    }

    /**
     * A learner with no grade records a completion date and a null grade, not a zero.
     *
     * Zero is a real mark. Recording it for someone who was never graded would make the
     * register say something untrue.
     */
    public function test_missing_grade_is_null_not_zero(): void {
        global $DB;

        $completed = strtotime('-4 months');
        $DB->insert_record('course_completions', (object)[
            'userid' => $this->user->id,
            'course' => $this->course->id,
            'timeenrolled' => 0,
            'timestarted' => 0,
            'timecompleted' => $completed,
            'reaggregate' => 0,
        ]);
        $this->create_schedule();

        $this->run_task();

        $log = $DB->get_record('local_recertify_log', [
            'userid' => $this->user->id,
            'action' => 'reset',
        ], '*', MUST_EXIST);

        $this->assertEquals($completed, $log->completiontime);
        $this->assertNull($log->finalgrade);
    }

    /**
     * Warning rows carry no training record, because nothing has been cleared.
     */
    public function test_warning_rows_carry_no_record(): void {
        global $DB;

        // Completed just under three months ago, so the wipe is a week away.
        $this->give_completion(strtotime('-3 months +7 days'), 90.0);
        $this->create_schedule(['warningdays' => 14]);

        $sink = $this->redirectMessages();
        $this->run_task();
        $sink->close();

        $log = $DB->get_record('local_recertify_log', [
            'userid' => $this->user->id,
            'action' => 'warned',
        ], '*', MUST_EXIST);

        $this->assertEquals(0, $log->completiontime);
        $this->assertNull($log->finalgrade);

        // Nothing was reset, so the learner keeps their completion.
        $this->assertTrue($DB->record_exists('course_completions', [
            'userid' => $this->user->id,
            'course' => $this->course->id,
        ]));
    }

    /**
     * The register also works for an enrolment-anchored schedule.
     *
     * Those learners are reset on their enrolment anniversary rather than from their
     * completion date, but the completion they had is still worth recording.
     */
    public function test_record_captured_for_relative_schedule(): void {
        global $DB;

        $DB->set_field(
            'user_enrolments',
            'timestart',
            strtotime('-3 years'),
            ['userid' => $this->user->id]
        );

        $completed = strtotime('-2 years');
        $this->give_completion($completed, 61.0);
        $this->create_schedule(['scheduletype' => 'relative', 'intervalmonths' => 12]);

        $this->run_task();

        $log = $DB->get_record('local_recertify_log', [
            'userid' => $this->user->id,
            'action' => 'reset',
        ], '*', MUST_EXIST);

        $this->assertEquals($completed, $log->completiontime);
        $this->assertEqualsWithDelta(61.0, (float)$log->finalgrade, 0.001);
    }
}
