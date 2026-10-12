<?php
// This file is part of Moodle - https://moodle.org/
// Moodle is distributed under the GNU GPL v3 or later.
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
namespace local_recertify;

defined('MOODLE_INTERNAL') || die();

/**
 * Non-destructive certification attempts. No method invokes a reset or changes enrolments.
 *
 * @package local_recertify
 * @copyright 2026 LMS Labs
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cycle_service {
    public const TABLE = 'local_recertify_cycles';

    /** Authoritative status: opening a renewal does not expire its predecessor. */
    public static function status(\stdClass $cycle, ?int $now = null): string {
        if ($cycle->status === 'revoked') {
            return 'revoked';
        }
        if ($cycle->status !== 'achieved') {
            return 'open';
        }
        return $cycle->expires && $cycle->expires <= ($now ?? time()) ? 'expired' : 'valid';
    }

    /** A learner can read only their own history; system managers can audit it. */
    public static function require_read(int $userid): void {
        global $USER;
        if ((int)$USER->id !== $userid) {
            require_capability('local/recertify:manage', \context_system::instance());
        }
    }

    /** Read-only preflight. Allocation still rechecks under its original locks on submission. */
    public static function preview(int $userid, string $kind, int $sourceid, int $certificateid,
            int $validity, int $parentid = 0): string {
        global $DB;
        require_capability('local/recertify:manage', \context_system::instance());
        if (!in_array($kind, ['course', 'program'], true) || $validity < 0 || $validity > 315360000) {
            throw new \invalid_parameter_exception('Invalid source or validity period');
        }
        $recipient = $DB->get_record('user', ['id' => $userid, 'deleted' => 0], '*', MUST_EXIST);
        if ($recipient->suspended) {
            throw new \moodle_exception('cycleaccessconflict', 'local_recertify');
        }
        if (!class_exists('\\mod_certificatepro\\local\\issue_service')) {
            throw new \moodle_exception('cycleunavailable', 'local_recertify');
        }
        $instance = $DB->get_record('certificatepro', ['id' => $certificateid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('certificatepro', $instance->id, $instance->course, false, MUST_EXIST);
        require_capability('mod/certificatepro:manage', \context_module::instance($cm->id));
        $conditions = $parentid ? ['parentid' => $parentid] : [
            'userid' => $userid, 'sourcekind' => $kind, 'sourceid' => $sourceid,
            'certificateid' => $certificateid, 'parentid' => null,
        ];
        if ($existing = $DB->get_record(self::TABLE, $conditions)) {
            if ((int)$existing->userid !== $userid || $existing->sourcekind !== $kind
                    || (int)$existing->sourceid !== $sourceid
                    || (int)$existing->certificateid !== $certificateid
                    || (int)$existing->validity !== $validity) {
                throw new \invalid_parameter_exception('Retry parameters differ from the existing cycle');
            }
            return 'Existing cycle: ' . $existing->name . '. This retry will not create a second cycle.';
        }
        if ($parentid) {
            $parent = $DB->get_record(self::TABLE, ['id' => $parentid, 'userid' => $userid], '*', MUST_EXIST);
            if ($parent->status !== 'achieved' || (int)$parent->certificateid !== $certificateid) {
                throw new \invalid_parameter_exception('Renewal requires an achieved award from the same issuer');
            }
        }
        [$name] = self::source((object)[
            'userid' => $userid, 'sourcekind' => $kind, 'sourceid' => $sourceid,
            'certificateid' => $certificateid, 'parentid' => $parentid, 'timeopened' => time(),
        ], false);
        if ($DB->record_exists('certificatepro_issues', ['certificateproid' => $certificateid, 'cycleid' => null])) {
            throw new \moodle_exception('cyclededicatedissuer', 'local_recertify');
        }
        return $name . ' — ' . $instance->name;
    }

    /** Allocate an initial or renewal attempt using explicitly selected, existing learning. */
    public static function allocate(int $userid, string $kind, int $sourceid, int $certificateid,
            int $validity, int $parentid = 0): \stdClass {
        global $DB, $USER;
        require_capability('local/recertify:manage', \context_system::instance());
        if (!in_array($kind, ['course', 'program'], true) || $validity < 0 || $validity > 315360000) {
            throw new \invalid_parameter_exception('Invalid source or validity period');
        }
        $DB->get_record('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0], '*', MUST_EXIST);
        if (!class_exists('\\mod_certificatepro\\local\\issue_service')) {
            throw new \moodle_exception('cycleunavailable', 'local_recertify');
        }
        $instance = $DB->get_record('certificatepro', ['id' => $certificateid], '*', MUST_EXIST);
        $cm = get_coursemodule_from_instance('certificatepro', $instance->id, $instance->course, false, MUST_EXIST);
        require_capability('mod/certificatepro:manage', \context_module::instance($cm->id));
        $factory = \core\lock\lock_config::get_lock_factory('local_recertify');
        $key = $parentid ? 'renew_' . $parentid : "allocate_{$userid}_{$kind}_{$sourceid}_{$certificateid}";
        $lock = $factory->get_lock($key, 10);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        try {
            $conditions = $parentid ? ['parentid' => $parentid] : [
                'userid' => $userid, 'sourcekind' => $kind, 'sourceid' => $sourceid,
                'certificateid' => $certificateid, 'parentid' => null,
            ];
            $existing = $DB->get_record(self::TABLE, $conditions);
            if ($existing) {
                if ((int)$existing->userid !== $userid || $existing->sourcekind !== $kind
                        || (int)$existing->sourceid !== $sourceid
                        || (int)$existing->certificateid !== $certificateid
                        || (int)$existing->validity !== $validity) {
                    throw new \invalid_parameter_exception('Retry parameters differ from the existing cycle');
                }
                return $existing;
            }
            if ($parentid) {
                $parent = $DB->get_record(self::TABLE, ['id' => $parentid, 'userid' => $userid], '*', MUST_EXIST);
                if ($parent->status !== 'achieved' || (int)$parent->certificateid !== $certificateid) {
                    throw new \invalid_parameter_exception('Renewal requires an achieved award from the same issuer');
                }
                if ($DB->record_exists('certificatepro_issues', ['cycleid' => $parentid, 'revoked' => 1])) {
                    throw new \moodle_exception('cyclerevoked', 'local_recertify');
                }
            }
            $cycle = (object)[
                'userid' => $userid, 'parentid' => $parentid ?: null, 'sourcekind' => $kind,
                'sourceid' => $sourceid, 'certificateid' => $certificateid, 'validity' => $validity,
                'status' => 'open', 'timeopened' => time(), 'timeachieved' => 0, 'expires' => 0,
                'createdby' => $USER->id,
            ];
            [$name] = self::source($cycle, false);
            $cycle->name = $name;
            $issuerlock = \core\lock\lock_config::get_lock_factory('mod_certificatepro')
                ->get_lock('issuer_' . $certificateid, 10);
            if (!$issuerlock) {
                throw new \moodle_exception('locktimeout');
            }
            try {
                // The issuer may have been deleted while evidence/access was
                // being checked, before this request obtained the shared lock.
                $DB->get_record('certificatepro', ['id' => $certificateid], '*', MUST_EXIST);
                if ($DB->record_exists('certificatepro_issues',
                        ['certificateproid' => $certificateid, 'cycleid' => null])) {
                    throw new \moodle_exception('cyclededicatedissuer', 'local_recertify');
                }
                $transaction = $DB->start_delegated_transaction();
                $DB->set_field('certificatepro', 'cycleonly', 1, ['id' => $certificateid]);
                $cycle->id = $DB->insert_record(self::TABLE, $cycle);
                $transaction->allow_commit();
            } finally {
                $issuerlock->release();
            }
            return $DB->get_record(self::TABLE, ['id' => $cycle->id], '*', MUST_EXIST);
        } finally {
            $lock->release();
        }
    }

    /** Validate current evidence, then freeze it once. Retries never move dates. */
    public static function achieve(int $id): \stdClass {
        global $DB;
        $cycle = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
        self::require_read((int)$cycle->userid);
        $factory = \core\lock\lock_config::get_lock_factory('local_recertify');
        $lock = $factory->get_lock('cycle_' . $id, 10);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        try {
            $cycle = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
            if ($cycle->status === 'revoked') {
                throw new \moodle_exception('cyclerevoked', 'local_recertify');
            }
            if ($cycle->status !== 'achieved') {
                [, $evidence, $achieved] = self::source($cycle, true);
                $cycle->status = 'achieved';
                $cycle->evidence = json_encode($evidence, JSON_THROW_ON_ERROR);
                $cycle->timeachieved = $achieved;
                $cycle->expires = $cycle->validity ? $achieved + (int)$cycle->validity : 0;
                $DB->update_record(self::TABLE, $cycle);
            }
            return $cycle;
        } finally {
            $lock->release();
        }
    }

    /** Revoke validity but retain the award, evidence and document. */
    public static function revoke(int $id, string $reason): void {
        global $DB, $USER;
        require_capability('local/recertify:manage', \context_system::instance());
        if (trim($reason) === '') {
            throw new \invalid_parameter_exception('A revocation reason is required');
        }
        $factory = \core\lock\lock_config::get_lock_factory('local_recertify');
        $lock = $factory->get_lock('cycle_' . $id, 10);
        if (!$lock) {
            throw new \moodle_exception('locktimeout');
        }
        try {
            $cycle = $DB->get_record(self::TABLE, ['id' => $id], '*', MUST_EXIST);
            if ($cycle->status !== 'revoked') {
                $DB->update_record(self::TABLE, (object)[
                    'id' => $id, 'status' => 'revoked', 'revokedby' => $USER->id,
                    'timerevoked' => time(), 'revokedreason' => $reason,
                ]);
            }
        } finally {
            $lock->release();
        }
    }

    /** Validate access without provisioning or unsuspending any enrolment. */
    private static function course_evidence(int $courseid, \stdClass $cycle, bool $complete): array {
        global $DB;
        $course = get_course($courseid);
        $context = \context_course::instance($courseid);
        if (!is_enrolled($context, $cycle->userid, '', true)
                || $DB->record_exists('local_recertify_schedule', ['courseid' => $courseid, 'enabled' => 1])) {
            throw new \moodle_exception('cycleaccessconflict', 'local_recertify');
        }
        $completion = $DB->get_record('course_completions', ['course' => $courseid, 'userid' => $cycle->userid]);
        $at = $completion ? (int)$completion->timecompleted : 0;
        if ($complete && (!$at || ($cycle->parentid && $at <= (int)$cycle->timeopened))) {
            throw new \moodle_exception('cycleincomplete', 'local_recertify');
        }
        return ['courseid' => $courseid, 'name' => $course->fullname,
            'timemodified' => $course->timemodified, 'timecompleted' => $at];
    }

    /** Read the existing source plugin rather than trusting a client-supplied completion flag. */
    private static function source(\stdClass $cycle, bool $complete): array {
        global $CFG, $DB;
        $DB->get_record('user', ['id' => $cycle->userid, 'deleted' => 0, 'suspended' => 0], '*', MUST_EXIST);
        if ($cycle->parentid) {
            $parent = $DB->get_record(self::TABLE, ['id' => $cycle->parentid, 'userid' => $cycle->userid],
                '*', MUST_EXIST);
            if ($parent->status !== 'achieved'
                    || $DB->record_exists('certificatepro_issues', ['cycleid' => $parent->id, 'revoked' => 1])) {
                throw new \moodle_exception('cyclerevoked', 'local_recertify');
            }
        }
        if ($cycle->sourcekind === 'course') {
            $evidence = self::course_evidence((int)$cycle->sourceid, $cycle, $complete);
            return [$evidence['name'], $evidence, $evidence['timecompleted']];
        }
        if (!class_exists('\\local_trainingpathways\\progress_service')) {
            throw new \moodle_exception('cycleunavailable', 'local_recertify');
        }
        require_once($CFG->dirroot . '/local/trainingpathways/lib.php');
        $assignment = $DB->get_record('local_tp_assignments',
            ['id' => $cycle->sourceid, 'userid' => $cycle->userid], '*', MUST_EXIST);
        if (!in_array($assignment->status, ['active', 'completed'], true)) {
            throw new \moodle_exception('cycleincomplete', 'local_recertify');
        }
        if ($cycle->parentid) {
            $parent = $DB->get_record(self::TABLE, ['id' => $cycle->parentid], '*', MUST_EXIST);
            if (($parent->sourcekind === 'program' && (int)$parent->sourceid === (int)$assignment->id)
                    || $assignment->timeassigned < $parent->timeachieved) {
                throw new \moodle_exception('cycleincomplete', 'local_recertify');
            }
        }
        $pathway = $DB->get_record('local_tp_pathways', ['id' => $assignment->pathwayid], '*', MUST_EXIST);
        $tasks = $DB->get_records_sql(
            "SELECT t.* FROM {local_tp_tasks} t JOIN {local_tp_stages} s ON s.id = t.stageid
              WHERE s.pathwayid = :id AND t.required = 1", ['id' => $pathway->id]);
        if (!$tasks) {
            throw new \moodle_exception('cycleincomplete', 'local_recertify');
        }
        $evidence = ['assignment' => $assignment, 'pathway' => $pathway, 'tasks' => []];
        $achieved = 0;
        foreach ($tasks as $task) {
            if (in_array($task->tasktype, ['course', 'quiz'], true)) {
                $proof = self::course_evidence((int)$task->courseid, $cycle, $complete);
                $at = $proof['timecompleted'];
            } else {
                $proof = $DB->get_record('local_tp_progress',
                    ['assignmentid' => $assignment->id, 'taskid' => $task->id, 'userid' => $cycle->userid]);
                $at = $proof ? (int)$proof->timemodified : 0;
                if ($complete && (!$proof || $proof->status !== 'complete'
                        || ($task->tasktype === 'signoff' && (!$proof->signedoffby
                            || (int)$proof->signedoffby === (int)$cycle->userid))
                        || ($cycle->parentid && $at <= $cycle->timeopened))) {
                    throw new \moodle_exception('cycleincomplete', 'local_recertify');
                }
            }
            $evidence['tasks'][] = ['requirement' => $task, 'proof' => $proof];
            $achieved = max($achieved, $at);
        }
        return [$pathway->name, $evidence, $achieved];
    }
}
