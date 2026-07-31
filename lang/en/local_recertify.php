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
 * Language strings for local_recertify.
 *
 * @package    local_recertify
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$string['pluginname']  = 'Course Recertification';
$string['pluginname_help'] = 'Automatically resets activity completion, course completion, and optionally grades ' .
    'for enrolled learners on a recurring schedule — rolling anniversary or fixed calendar date.';

// Settings.
$string['scheduletype']          = 'Schedule type';
$string['scheduletype_help']     = 'Relative: reset N months after each learner\'s enrolment date. ' .
    'Fixed: reset on a specific calendar date (MM-DD) each year.';
$string['scheduletype_relative'] = 'Relative (rolling anniversary from enrolment)';
$string['scheduletype_fixed']    = 'Fixed (annual calendar date)';
$string['intervalmonths']        = 'Recertification interval (months)';
$string['intervalmonths_help']   = 'For relative schedules: how many months between resets.';
$string['fixeddate']             = 'Fixed annual reset date (MM-DD)';
$string['fixeddate_help']        = 'For fixed schedules: the day of the year on which resets fire (e.g. 01-01 for 1 January).';
$string['warningdays']           = 'Warning notice (days before reset)';
$string['warningdays_help']      = 'Send an advance warning email and Moodle notification this many days before reset.';
$string['resetdepth']            = 'Reset depth';
$string['resetdepth_help']       = 'How deep the reset goes. Full data wipe is irreversible — test on staging first.';
$string['resetdepth_completion'] = 'Completion only';
$string['resetdepth_grades']     = 'Completion + grades';
$string['resetdepth_full']       = 'Full data wipe (quiz attempts, assignment submissions, SCORM tracks)';
$string['wipeassignments']       = 'Wipe assignment submissions';
$string['wipequizattempts']      = 'Wipe quiz attempts';
$string['wipescormtracks']       = 'Wipe SCORM tracks';
$string['notifyonreset']         = 'Notify learner at moment of reset';
$string['enableauditlog']        = 'Enable audit log';

// Admin pages.
$string['managerecertify']       = 'Manage Course Recertification';
$string['schedules']             = 'Schedules';
$string['auditlog']              = 'Audit log';
$string['addschedule']           = 'Add recertification schedule';
$string['editschedule']          = 'Edit schedule';
$string['deleteschedule']        = 'Delete schedule';
$string['schedulefor']           = 'Schedule for: {$a}';
$string['nextresetat']           = 'Next reset';
$string['lastresetat']           = 'Last reset';
$string['affectedusers']         = 'Affected learners';
$string['resetconfirm']          = 'Are you sure you want to run a manual recertification reset for {$a} learners?';
$string['resetmanual']           = 'Run reset now';
$string['exportauditlog']        = 'Export audit log (CSV)';

// Notifications.
$string['warningemail_subject']  = 'Your certification in {$a->course} expires in {$a->days} days';
$string['warningemail_body']     = 'Hi {$a->firstname},

Your certification in "{$a->course}" is due for renewal in {$a->days} days ({$a->resetdate}).

Please log in to {$a->siteurl} to complete your recertification before this date.

This is an automated message from {$a->sitename}.';
$string['resetemail_subject']    = 'Your certification in {$a->course} has been reset for recertification';
$string['resetemail_body']       = 'Hi {$a->firstname},

Your completion record in "{$a->course}" has been reset. ' .
    'Please log in to {$a->siteurl} to complete the course again to maintain your certification.

This is an automated message from {$a->sitename}.';

// Privacy.
$string['privacy:metadata:local_recertify_log']          = 'Audit log of all recertification resets.';
$string['privacy:metadata:local_recertify_log:userid']   = 'The user whose record was reset.';
$string['privacy:metadata:local_recertify_log:courseid'] = 'The course that was reset.';
$string['privacy:metadata:local_recertify_log:timecreated'] = 'When the reset was performed.';
$string['privacy:metadata:local_recertify_log:depth']    = 'The reset depth applied.';

// Capabilities.
$string['recertify:manage']  = 'Manage course recertification schedules';
$string['recertify:viewlog'] = 'View the recertification audit log';
