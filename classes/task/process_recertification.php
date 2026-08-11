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
        return get_string('pluginname', 'local_recertify') . ' — process resets';
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

        foreach ($schedules as $schedule) {
            // Get all active enrolments for this course.
            $enrolments = $DB->get_records_sql("
                SELECT ue.id, ue.userid, ue.timestart
                  FROM {user_enrolments} ue
                  JOIN {enrol} e ON e.id = ue.enrolid
                  JOIN {user} u  ON u.id = ue.userid
                 WHERE e.courseid  = :cid
                   AND ue.status   = :active
                   AND u.deleted   = 0
                   AND u.suspended = 0
            ", ['cid' => $schedule->courseid, 'active' => ENROL_USER_ACTIVE]);

            foreach ($enrolments as $ue) {
                $nextresettime = local_recertify_next_reset_time($schedule, (int)$ue->timestart);

                // Send advance warning notification.
                $warntime = $nextresettime - ((int)$schedule->warningdays * DAYSECS);
                if ($now >= $warntime && $now < $nextresettime) {
                    $this->send_warning($ue->userid, $schedule->courseid, $nextresettime, $schedule);
                }

                // Perform reset if due.
                if ($now >= $nextresettime) {
                    // Check not already reset this cycle (within last 24h to avoid double-run).
                    $alreadyreset = $DB->record_exists_select('local_recertify_log',
                        'userid = :uid AND courseid = :cid AND timecreated > :since',
                        ['uid' => $ue->userid, 'cid' => $schedule->courseid, 'since' => $nextresettime - DAYSECS]);
                    if (!$alreadyreset) {
                        local_recertify_reset_user($ue->userid, $schedule->courseid, $schedule);
                        local_recertify_log($schedule->id, $schedule->courseid, $ue->userid, $schedule->resetdepth);
                        if (!empty($schedule->notifyonreset)) {
                            $this->send_reset_notification($ue->userid, $schedule->courseid);
                        }
                        mtrace("  Recertification reset: user {$ue->userid} in course {$schedule->courseid}");
                    }
                }
            }
        }
    }

    /**
     * Send a warning notification before reset.
     *
     * @param int      $userid
     * @param int      $courseid
     * @param int      $resettime Unix timestamp of the upcoming reset.
     * @param stdClass $schedule
     */
    private function send_warning(int $userid, int $courseid, int $resettime, \stdClass $schedule): void {
        global $DB, $CFG;

        $user   = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$user || !$course) {
            return;
        }
        $days = max(1, (int)round(($resettime - time()) / DAYSECS));

        $a = (object)[
            'firstname'  => $user->firstname,
            'course'     => format_string($course->fullname),
            'days'       => $days,
            'resetdate'  => userdate($resettime),
            'siteurl'    => $CFG->wwwroot,
            'sitename'   => get_site()->fullname,
        ];

        $message              = new \core\message\message();
        $message->component   = 'local_recertify';
        $message->name        = 'warningnotice';
        $message->userto      = $user;
        $message->userfrom    = \core_user::get_noreply_user();
        $message->subject     = get_string('warningemail_subject', 'local_recertify', $a);
        $message->fullmessage = get_string('warningemail_body', 'local_recertify', $a);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml   = '';
        $message->smallmessage      = $message->subject;
        $message->notification      = 1;
        message_send($message);
    }

    /**
     * Send a notification at the moment of reset.
     *
     * @param int $userid
     * @param int $courseid
     */
    private function send_reset_notification(int $userid, int $courseid): void {
        global $DB, $CFG;

        $user   = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$user || !$course) {
            return;
        }
        $a = (object)[
            'firstname' => $user->firstname,
            'course'    => format_string($course->fullname),
            'siteurl'   => $CFG->wwwroot,
            'sitename'  => get_site()->fullname,
        ];

        $message              = new \core\message\message();
        $message->component   = 'local_recertify';
        $message->name        = 'resetnotice';
        $message->userto      = $user;
        $message->userfrom    = \core_user::get_noreply_user();
        $message->subject     = get_string('resetemail_subject', 'local_recertify', $a);
        $message->fullmessage = get_string('resetemail_body', 'local_recertify', $a);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml   = '';
        $message->smallmessage      = $message->subject;
        $message->notification      = 1;
        message_send($message);
    }
}
