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
 * Tests for limiting a reset to selected activities.
 *
 * @package    local_recertify
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_recertify;

/**
 * Covers the activity scope added in 1.3.0.
 *
 * @covers ::local_recertify_reset_user
 * @covers ::local_recertify_get_scope_cmids
 * @covers ::local_recertify_module_instances
 * @covers ::local_recertify_scope_gates_course_completion
 */
final class scope_test extends \advanced_testcase {
    /** @var \stdClass Course under test. */
    protected $course;

    /** @var \stdClass Learner under test. */
    protected $user;

    /** @var \stdClass Page activity that the schedule will target. */
    protected $targetcm;

    /** @var \stdClass Page activity that must be left alone. */
    protected $othercm;

    /**
     * Build a course with two completion-tracked activities and a completed learner.
     */
    public function setUp(): void {
        global $CFG, $DB;

        parent::setUp();
        require_once($CFG->dirroot . '/local/recertify/lib.php');
        $this->resetAfterTest(true);

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course(['enablecompletion' => 1]);
        $this->user = $gen->create_user();
        $gen->enrol_user($this->user->id, $this->course->id, 'student');

        $opts = ['course' => $this->course->id, 'completion' => COMPLETION_TRACKING_MANUAL];
        $this->targetcm = $gen->create_module('page', $opts);
        $this->othercm = $gen->create_module('page', $opts);

        foreach ([$this->targetcm->cmid, $this->othercm->cmid] as $cmid) {
            $DB->insert_record('course_modules_completion', (object)[
                'coursemoduleid' => $cmid,
                'userid' => $this->user->id,
                'completionstate' => COMPLETION_COMPLETE,
                'overrideby' => null,
                'timemodified' => time(),
            ]);
        }

        $DB->insert_record('course_completions', (object)[
            'userid' => $this->user->id,
            'course' => $this->course->id,
            'timeenrolled' => 0,
            'timestarted' => 0,
            'timecompleted' => time(),
            'reaggregate' => 0,
        ]);
    }

    /**
     * Create a schedule row, optionally scoped to the given course module ids.
     *
     * @param array $overrides Schedule field overrides.
     * @param array $cmids Course module ids to scope to.
     * @return \stdClass The stored schedule.
     */
    protected function create_schedule(array $overrides = [], array $cmids = []): \stdClass {
        global $DB;

        $id = $DB->insert_record('local_recertify_schedule', (object)array_merge([
            'courseid' => $this->course->id,
            'enabled' => 1,
            'scheduletype' => 'relative',
            'intervalmonths' => 12,
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

        foreach ($cmids as $cmid) {
            $DB->insert_record('local_recertify_schedule_cm', (object)[
                'scheduleid' => $id,
                'cmid' => $cmid,
            ]);
        }

        return $DB->get_record('local_recertify_schedule', ['id' => $id], '*', MUST_EXIST);
    }

    /**
     * Whether the learner still has a completion row for an activity.
     *
     * @param int $cmid
     * @return bool
     */
    protected function has_completion(int $cmid): bool {
        global $DB;

        return $DB->record_exists('course_modules_completion', [
            'coursemoduleid' => $cmid,
            'userid' => $this->user->id,
        ]);
    }

    /**
     * A scoped reset clears the selected activity and leaves the other one untouched.
     */
    public function test_scoped_reset_clears_only_selected_activities(): void {
        $schedule = $this->create_schedule(
            ['activityscope' => 'selected'],
            [$this->targetcm->cmid]
        );

        local_recertify_reset_user((int)$this->user->id, (int)$this->course->id, $schedule);

        $this->assertFalse($this->has_completion((int)$this->targetcm->cmid));
        $this->assertTrue($this->has_completion((int)$this->othercm->cmid));
    }

    /**
     * The default scope still clears the whole course, exactly as 1.2.1 did.
     */
    public function test_all_scope_is_unchanged_behaviour(): void {
        $schedule = $this->create_schedule();

        local_recertify_reset_user((int)$this->user->id, (int)$this->course->id, $schedule);

        $this->assertFalse($this->has_completion((int)$this->targetcm->cmid));
        $this->assertFalse($this->has_completion((int)$this->othercm->cmid));
    }

    /**
     * Course completion survives when no selected activity gates it.
     *
     * This is the rule that stops a narrow reset silently voiding someone's certificate.
     */
    public function test_course_completion_kept_when_scope_does_not_gate_it(): void {
        global $DB;

        $schedule = $this->create_schedule(
            ['activityscope' => 'selected'],
            [$this->targetcm->cmid]
        );

        local_recertify_reset_user((int)$this->user->id, (int)$this->course->id, $schedule);

        $this->assertTrue($DB->record_exists('course_completions', [
            'userid' => $this->user->id,
            'course' => $this->course->id,
        ]));
    }

    /**
     * Course completion is revoked when a selected activity is a completion criterion.
     */
    public function test_course_completion_revoked_when_scope_gates_it(): void {
        global $DB;

        $criteriaid = $DB->insert_record('course_completion_criteria', (object)[
            'course' => $this->course->id,
            'criteriatype' => COMPLETION_CRITERIA_TYPE_ACTIVITY,
            'module' => 'page',
            'moduleinstance' => $this->targetcm->cmid,
            'courseinstance' => null,
            'enrolperiod' => null,
            'timeend' => null,
            'gradepass' => null,
            'role' => null,
        ]);
        $DB->insert_record('course_completion_crit_compl', (object)[
            'userid' => $this->user->id,
            'course' => $this->course->id,
            'criteriaid' => $criteriaid,
            'timecompleted' => time(),
        ]);

        $this->assertTrue(local_recertify_scope_gates_course_completion(
            (int)$this->course->id,
            [(int)$this->targetcm->cmid]
        ));

        $schedule = $this->create_schedule(
            ['activityscope' => 'selected'],
            [$this->targetcm->cmid]
        );

        local_recertify_reset_user((int)$this->user->id, (int)$this->course->id, $schedule);

        $this->assertFalse($DB->record_exists('course_completions', [
            'userid' => $this->user->id,
            'course' => $this->course->id,
        ]));
        $this->assertFalse($DB->record_exists('course_completion_crit_compl', [
            'userid' => $this->user->id,
            'criteriaid' => $criteriaid,
        ]));
    }

    /**
     * A scoped schedule whose activities have all been deleted resets nothing.
     *
     * The empty-array case must never collapse into "whole course", which would turn the
     * narrowest possible schedule into the widest.
     */
    public function test_scope_with_deleted_activities_resets_nothing(): void {
        global $DB;

        $schedule = $this->create_schedule(
            ['activityscope' => 'selected'],
            [$this->targetcm->cmid]
        );

        // Simulate the activity having been deleted without the observer running.
        $DB->delete_records('course_modules', ['id' => $this->targetcm->cmid]);

        $this->assertSame([], local_recertify_get_scope_cmids($schedule));

        local_recertify_reset_user((int)$this->user->id, (int)$this->course->id, $schedule);

        $this->assertTrue($this->has_completion((int)$this->othercm->cmid));
        $this->assertTrue($DB->record_exists('course_completions', [
            'userid' => $this->user->id,
            'course' => $this->course->id,
        ]));
    }

    /**
     * Scope resolution reports the whole course as null, never as an empty list.
     */
    public function test_scope_resolution_distinguishes_all_from_empty(): void {
        global $DB;

        $schedule = $this->create_schedule();
        $this->assertNull(local_recertify_get_scope_cmids($schedule));

        // The same schedule is reused rather than creating a second one: a course may
        // only ever have one schedule, enforced by a unique index on courseid.
        $DB->set_field('local_recertify_schedule', 'activityscope', 'selected', ['id' => $schedule->id]);
        $DB->insert_record('local_recertify_schedule_cm', (object)[
            'scheduleid' => $schedule->id,
            'cmid' => $this->targetcm->cmid,
        ]);

        $scoped = $DB->get_record('local_recertify_schedule', ['id' => $schedule->id], '*', MUST_EXIST);
        $this->assertSame([(int)$this->targetcm->cmid], local_recertify_get_scope_cmids($scoped));
    }

    /**
     * Module instance lookup honours the scope, and ignores other module types.
     */
    public function test_module_instances_respects_scope(): void {
        $gen = $this->getDataGenerator();
        $quiz1 = $gen->create_module('quiz', ['course' => $this->course->id]);
        $quiz2 = $gen->create_module('quiz', ['course' => $this->course->id]);

        $all = local_recertify_module_instances((int)$this->course->id, null, 'quiz');
        sort($all);
        $this->assertSame([(int)$quiz1->id, (int)$quiz2->id], $all);

        $scoped = local_recertify_module_instances((int)$this->course->id, [(int)$quiz2->cmid], 'quiz');
        $this->assertSame([(int)$quiz2->id], $scoped);

        // A scope naming no quizzes must wipe no quizzes.
        $pagesonly = local_recertify_module_instances(
            (int)$this->course->id,
            [(int)$this->targetcm->cmid],
            'quiz'
        );
        $this->assertSame([], $pagesonly);
    }

    /**
     * The picker lists every activity, flagging those without completion tracking.
     */
    public function test_activity_options_lists_all_activities(): void {
        $gen = $this->getDataGenerator();
        $untracked = $gen->create_module('quiz', [
            'course' => $this->course->id,
            'name' => 'Fire safety test',
            'completion' => COMPLETION_TRACKING_NONE,
        ]);

        $options = local_recertify_activity_options((int)$this->course->id);

        // Both completion-tracked pages and the untracked quiz are offered.
        $this->assertArrayHasKey($this->targetcm->cmid, $options);
        $this->assertArrayHasKey($this->othercm->cmid, $options);
        $this->assertArrayHasKey($untracked->cmid, $options);

        $flag = get_string('activitynotracking', 'local_recertify');
        $this->assertStringContainsString($flag, $options[$untracked->cmid]);
        $this->assertStringNotContainsString($flag, $options[$this->targetcm->cmid]);
        $this->assertStringContainsString('Fire safety test', $options[$untracked->cmid]);
    }

    /**
     * The schedule form builds in both modes.
     *
     * The create form must not offer the activity picker, because the course - and so the
     * activity list - is not known until the schedule exists.
     */
    public function test_schedule_form_builds_in_both_modes(): void {
        global $PAGE;

        $PAGE->set_url('/local/recertify/edit.php');

        $createform = new \local_recertify\form\schedule_form(null, []);
        $this->assertFalse($this->inner_form($createform)->elementExists('cmids'));

        $editform = new \local_recertify\form\schedule_form(null, [
            'id' => 1,
            'coursename' => 'Course',
            'activities' => local_recertify_activity_options((int)$this->course->id),
        ]);
        $this->assertTrue($this->inner_form($editform)->elementExists('cmids'));
        $this->assertTrue($this->inner_form($editform)->elementExists('activityscope'));
    }

    /**
     * Reach moodleform's protected QuickForm so elements can be inspected.
     *
     * @param \moodleform $form
     * @return \MoodleQuickForm
     */
    protected function inner_form(\moodleform $form): \MoodleQuickForm {
        $property = new \ReflectionProperty(\moodleform::class, '_form');
        $property->setAccessible(true);

        return $property->getValue($form);
    }

    /**
     * A grades-depth scoped reset clears only the selected activity's grades.
     */
    public function test_scoped_grades_reset_leaves_other_activity_grades(): void {
        global $DB;

        $gen = $this->getDataGenerator();
        $target = $gen->create_module('quiz', ['course' => $this->course->id, 'grade' => 100]);
        $other = $gen->create_module('quiz', ['course' => $this->course->id, 'grade' => 100]);

        $items = [];
        foreach (['target' => $target, 'other' => $other] as $key => $quiz) {
            $items[$key] = $DB->get_record('grade_items', [
                'courseid' => $this->course->id,
                'itemtype' => 'mod',
                'itemmodule' => 'quiz',
                'iteminstance' => $quiz->id,
            ], '*', MUST_EXIST);

            $DB->insert_record('grade_grades', (object)[
                'itemid' => $items[$key]->id,
                'userid' => $this->user->id,
                'rawgrade' => 75,
                'finalgrade' => 75,
                'timecreated' => time(),
                'timemodified' => time(),
            ]);
        }

        $schedule = $this->create_schedule(
            ['activityscope' => 'selected', 'resetdepth' => 'grades'],
            [$target->cmid]
        );

        local_recertify_reset_user((int)$this->user->id, (int)$this->course->id, $schedule);

        $this->assertFalse($DB->record_exists('grade_grades', [
            'itemid' => $items['target']->id,
            'userid' => $this->user->id,
        ]));
        $this->assertTrue($DB->record_exists('grade_grades', [
            'itemid' => $items['other']->id,
            'userid' => $this->user->id,
        ]));
    }
}
