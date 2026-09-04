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
 * Language strings for local_recertify.
 *
 * @package    local_recertify
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['action'] = 'Action';
$string['addschedule'] = 'Add recertification schedule';
$string['auditlog'] = 'Audit log';
$string['courseid'] = 'Course ID';
$string['cycledate'] = 'Cycle date';
$string['defaultsheading'] = 'Defaults for new schedules';
$string['defaultsheading_desc'] = 'These values are used to pre-fill the form when you create a new recertification schedule. Changing them does not affect schedules that already exist.';
$string['deleteconfirm'] = 'Delete the recertification schedule for "{$a}"? Learners already reset under this schedule keep their audit log entries.';
$string['depthsection'] = 'What gets reset';
$string['editschedule'] = 'Edit recertification schedule';
$string['enableauditlog'] = 'Record reset depth in the audit log';
$string['enableauditlog_help'] = 'Every reset is always recorded so a learner is never reset twice in the same cycle. Turning this off omits only the reset depth detail from the log.';
$string['errorduplicatecourse'] = 'This course already has a recertification schedule. Edit the existing one instead.';
$string['errorfixeddate'] = 'Enter a valid date as MM-DD, for example 07-01 for 1 July.';
$string['errorintervalmonths'] = 'The interval must be at least one month.';
$string['errorintervaltoolarge'] = 'The interval must be 600 months or fewer.';
$string['errorwarningdays'] = 'Warning days must be between 0 and 365.';
$string['everynmonths'] = 'Every {$a} months';
$string['exportauditlog'] = 'Export audit log (CSV)';
$string['fixeddate'] = 'Fixed reset date (MM-DD)';
$string['fixeddate_help'] = 'The calendar date each year on which every learner in the course is reset, written as MM-DD. Learners who enrolled after the most recent occurrence are not reset until the next one.';
$string['interval'] = 'Interval';
$string['intervalmonths'] = 'Interval (months)';
$string['intervalmonths_help'] = 'For a schedule relative to enrolment, how many months after a learner\'s enrolment start date the first reset falls, and the gap between resets after that. For a schedule measured from course completion, how long the learner\'s completed record is kept before it is wiped — for example 3 for "three months after they complete". Must be at least 1.';
$string['log_reset'] = 'Reset';
$string['log_warned'] = 'Warning sent';
$string['managerecertify'] = 'Manage course recertification';
$string['monthaftercompletion'] = '1 month after completion';
$string['monthsaftercompletion'] = '{$a} months after completion';
$string['nologentries'] = 'Nothing has been logged yet.';
$string['noschedules'] = 'No recertification schedules have been created yet.';
$string['notifyonreset'] = 'Notify the learner when their progress is reset';
$string['notifyonreset_help'] = 'Sends a notification at the moment of reset, in addition to any advance warning.';
$string['notifysection'] = 'Notifications';
$string['pluginname'] = 'Course Recertification';
$string['pluginname_help'] = 'Periodically resets course completion so learners must re-certify, with per-course schedules, advance warnings and a full audit trail.';
$string['privacy:metadata:local_recertify_log'] = 'Audit log of recertification resets and warnings.';
$string['privacy:metadata:local_recertify_log:action'] = 'Whether the entry records a reset or an advance warning.';
$string['privacy:metadata:local_recertify_log:courseid'] = 'The course the learner was reset in.';
$string['privacy:metadata:local_recertify_log:resettime'] = 'The recertification cycle the entry belongs to.';
$string['privacy:metadata:local_recertify_log:scheduleid'] = 'The recertification schedule the entry was made under.';
$string['privacy:metadata:local_recertify_log:triggertype'] = 'Whether the reset was scheduled or triggered manually.';
$string['privacy:metadata:local_recertify_log:depth'] = 'How much of the learner\'s progress was reset.';
$string['privacy:metadata:local_recertify_log:timecreated'] = 'When the reset happened.';
$string['privacy:metadata:local_recertify_log:userid'] = 'The user who was reset.';
$string['resetdepth'] = 'Reset depth';
$string['resetdepth_completion'] = 'Completion only';
$string['resetdepth_full'] = 'Completion, grades and activity data';
$string['resetdepth_grades'] = 'Completion and grades';
$string['resetdepth_help'] = 'How much of the learner\'s progress is cleared. Each level includes the one above it. "Completion only" is reversible in practice because the underlying attempts survive. "Completion, grades and activity data" permanently deletes the learner\'s attempts, submissions and uploaded files for the activity types you tick below, and cannot be undone - test it on a single course before enabling it widely.';
$string['resetemail_body'] = 'Hello {$a->firstname},

Your progress in {$a->course} has been reset for recertification. You will need to complete the course again.

Start here: {$a->courseurl}

{$a->sitename}';
$string['resetemail_subject'] = 'Your progress in {$a->course} has been reset for recertification';
$string['schedulecreated'] = 'Recertification schedule created.';
$string['scheduledeleted'] = 'Recertification schedule for "{$a}" deleted.';
$string['schedulesection'] = 'Schedule';
$string['scheduleupdated'] = 'Recertification schedule updated.';
$string['scheduletype'] = 'Schedule type';
$string['scheduletype_completion'] = 'A set time after course completion';
$string['scheduletype_fixed'] = 'Fixed date each year';
$string['scheduletype_help'] = 'A relative schedule resets each learner on the anniversary of their own enrolment. A fixed schedule resets everyone in the course on the same calendar date each year. A completion schedule waits a set number of months after each learner finishes the course and then wipes their record, so the clock starts when they complete rather than when they enrol; learners who have not completed the course are left alone.';
$string['scheduletype_relative'] = 'Relative to enrolment date';
$string['taskprocess'] = 'Course Recertification: process resets';
$string['trigger'] = 'Triggered by';
$string['userid'] = 'User ID';
$string['warningdays'] = 'Advance warning (days)';
$string['warningdays_help'] = 'How many days before a reset the learner is notified. Set to 0 to send no advance warning. One notification is sent per learner per cycle.';
$string['warningemail_body'] = 'Hello {$a->firstname},

Your certification for {$a->course} is due for renewal in {$a->days} days, on {$a->resetdate}.

When that date arrives your progress in the course will be reset and you will need to complete it again.

Go to the course: {$a->courseurl}

{$a->sitename}';
$string['warningemail_subject'] = 'Recertification due in {$a->days} days: {$a->course}';
$string['wipeassignments'] = 'Delete assignment submissions on a full reset';
$string['wipeassignments_help'] = 'Removes the learner\'s submissions, grades and submitted files for every assignment in the course. Permanent.';
$string['wipequizattempts'] = 'Delete quiz attempts on a full reset';
$string['wipequizattempts_help'] = 'Removes the learner\'s attempts, quiz grades and question responses for every quiz in the course. Permanent.';
$string['wipescormtracks'] = 'Delete SCORM tracking data on a full reset';
$string['wipescormtracks_help'] = 'Removes the learner\'s SCORM attempts and tracking data for every SCORM package in the course. Permanent.';
$string['messageprovider:resetnotice'] = 'Notification that your progress has been reset for recertification';
$string['messageprovider:warningnotice'] = 'Advance warning that recertification is due';
$string['recertify:manage'] = 'Manage recertification schedules';
$string['recertify:viewlog'] = 'View the recertification audit log';
