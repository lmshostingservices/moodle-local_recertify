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
 * Scheduled task: process recertification resets.
 *
 * @package    local_recertify
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_recertify\task;

use core\task\scheduled_task;

/**
 * Process all due recertification resets and send advance warning notifications.
 */
class process_recertification extends scheduled_task {
    /**
     * Return the task name shown in the admin UI.
     *
     * @return string
     */
    public function get_name(): string {
        return get_string('taskprocess', 'local_recertify');
    }

    /**
     * Run the task.
     */
    public function execute(): void {
        global $DB, $CFG;

        require_once($CFG->dirroot . '/local/recertify/lib.php');

        $schedules = $DB->get_records('local_recertify_schedule', ['enabled' => 1]);
        if (!$schedules) {
            return;
        }

        $now = time();
        $resets = 0;
        $warnings = 0;

        foreach ($schedules as $schedule) {
            if (!$DB->record_exists('course', ['id' => $schedule->courseid])) {
                mtrace("  Skipping schedule {$schedule->id}: course {$schedule->courseid} no longer exists");
                continue;
            }

            $sql = "SELECT ue.id, ue.userid, ue.timestart, ue.timecreated
                      FROM {user_enrolments} ue
                      JOIN {enrol} e ON e.id = ue.enrolid
                      JOIN {user} u ON u.id = ue.userid
                     WHERE e.courseid = :cid
                       AND ue.status = :active
                       AND u.deleted = 0
                       AND u.suspended = 0";
            $params = ['cid' => $schedule->courseid, 'active' => ENROL_USER_ACTIVE];
            $enrolments = $DB->get_records_sql($sql, $params);

            foreach ($enrolments as $ue) {
                // Enrolments created without an explicit start date fall back to the date
                // the enrolment record itself was made, so relative schedules still work.
                $timestart = (int)$ue->timestart ?: (int)$ue->timecreated;

                // Is a reset due? This is the most recent cycle boundary already passed,
                // never a future date, so the comparison can actually succeed.
                $duetime = local_recertify_last_due_reset_time($schedule, $timestart, $now);
                if ($duetime > 0) {
                    // The log row doubles as the idempotency record for this cycle.
                    $written = local_recertify_log(
                        (int)$schedule->id,
                        (int)$schedule->courseid,
                        (int)$ue->userid,
                        $schedule->resetdepth,
                        'reset',
                        $duetime
                    );

                    if ($written) {
                        try {
                            local_recertify_reset_user((int)$ue->userid, (int)$schedule->courseid, $schedule);
                            $resets++;
                            mtrace("  Recertification reset: user {$ue->userid} in course {$schedule->courseid}");

                            if (!empty($schedule->notifyonreset)) {
                                $this->send_reset_notification((int)$ue->userid, (int)$schedule->courseid);
                            }
                        } catch (\Throwable $e) {
                            // Remove the marker so the next run retries this learner.
                            $DB->delete_records('local_recertify_log', [
                                'userid' => $ue->userid,
                                'courseid' => $schedule->courseid,
                                'action' => 'reset',
                                'resettime' => $duetime,
                            ]);
                            mtrace("  Recertification reset FAILED for user {$ue->userid} in course "
                                . "{$schedule->courseid}: " . $e->getMessage());
                        }
                    }
                }

                // Advance warning for the next upcoming cycle.
                $warningdays = (int)$schedule->warningdays;
                if ($warningdays > 0) {
                    $nexttime = local_recertify_next_reset_time($schedule, $timestart, $now);
                    $warnfrom = $nexttime - ($warningdays * DAYSECS);

                    if ($now >= $warnfrom && $now < $nexttime) {
                        // Keyed on the cycle, so one warning per learner per cycle rather
                        // than one on every cron run for the whole warning window.
                        $written = local_recertify_log(
                            (int)$schedule->id,
                            (int)$schedule->courseid,
                            (int)$ue->userid,
                            '',
                            'warned',
                            $nexttime
                        );
                        if ($written) {
                            $this->send_warning((int)$ue->userid, (int)$schedule->courseid, $nexttime);
                            $warnings++;
                        }
                    }
                }
            }
        }

        mtrace("  local_recertify: {$resets} resets, {$warnings} warnings sent");
    }

    /**
     * Send a warning notification before a reset.
     *
     * @param int $userid
     * @param int $courseid
     * @param int $resettime Unix timestamp of the upcoming reset.
     */
    protected function send_warning(int $userid, int $courseid, int $resettime): void {
        global $DB, $CFG;

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$user || !$course) {
            return;
        }

        $a = (object)[
            'firstname' => $user->firstname,
            'course' => format_string($course->fullname),
            'days' => max(1, (int)round(($resettime - time()) / DAYSECS)),
            'resetdate' => userdate($resettime),
            'courseurl' => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
            'siteurl' => $CFG->wwwroot,
            'sitename' => format_string(get_site()->fullname),
        ];

        $this->send_message(
            $user,
            'warningnotice',
            get_string('warningemail_subject', 'local_recertify', $a),
            get_string('warningemail_body', 'local_recertify', $a),
            $courseid
        );
    }

    /**
     * Send a notification at the moment of reset.
     *
     * @param int $userid
     * @param int $courseid
     */
    protected function send_reset_notification(int $userid, int $courseid): void {
        global $DB, $CFG;

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$user || !$course) {
            return;
        }

        $a = (object)[
            'firstname' => $user->firstname,
            'course' => format_string($course->fullname),
            'courseurl' => (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false),
            'siteurl' => $CFG->wwwroot,
            'sitename' => format_string(get_site()->fullname),
        ];

        $this->send_message(
            $user,
            'resetnotice',
            get_string('resetemail_subject', 'local_recertify', $a),
            get_string('resetemail_body', 'local_recertify', $a),
            $courseid
        );
    }

    /**
     * Build and send one notification through the message API.
     *
     * Both providers are declared in db/messages.php. Without that file message_send()
     * rejects the message and nothing is ever delivered.
     *
     * @param \stdClass $user Recipient.
     * @param string $name Message provider name.
     * @param string $subject
     * @param string $body Plain text body.
     * @param int $courseid
     */
    protected function send_message(\stdClass $user, string $name, string $subject, string $body, int $courseid): void {
        $message = new \core\message\message();
        $message->component = 'local_recertify';
        $message->name = $name;
        $message->courseid = $courseid;
        $message->userto = $user;
        $message->userfrom = \core_user::get_noreply_user();
        $message->subject = $subject;
        $message->fullmessage = $body;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = text_to_html($body);
        $message->smallmessage = $subject;
        $message->notification = 1;
        $message->contexturl = (new \moodle_url('/course/view.php', ['id' => $courseid]))->out(false);
        $message->contexturlname = get_string('course');

        message_send($message);
    }
}
