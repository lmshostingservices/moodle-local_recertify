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
 * Admin settings for local_recertify.
 *
 * These are the defaults a newly created schedule is seeded with. Existing schedules
 * keep their own values.
 *
 * @package    local_recertify
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    $ADMIN->add('localplugins', new admin_category(
        'local_recertify_cat',
        get_string('pluginname', 'local_recertify')
    ));

    // Link to the management page.
    $ADMIN->add('local_recertify_cat', new admin_externalpage(
        'local_recertify_manage',
        get_string('managerecertify', 'local_recertify'),
        new moodle_url('/local/recertify/index.php'),
        'local/recertify:manage'
    ));

    // Site-wide defaults for new schedules.
    $settings = new admin_settingpage(
        'local_recertify_settings',
        get_string('pluginname', 'local_recertify') . ' - ' . get_string('settings')
    );

    $settings->add(new admin_setting_heading(
        'local_recertify/defaultsheading',
        get_string('defaultsheading', 'local_recertify'),
        get_string('defaultsheading_desc', 'local_recertify')
    ));

    $settings->add(new admin_setting_configselect(
        'local_recertify/scheduletype',
        get_string('scheduletype', 'local_recertify'),
        get_string('scheduletype_help', 'local_recertify'),
        'relative',
        [
            'relative' => get_string('scheduletype_relative', 'local_recertify'),
            'fixed' => get_string('scheduletype_fixed', 'local_recertify'),
        ]
    ));

    // A zero interval is meaningless and used to make the scheduler loop forever.
    $settings->add(new \local_recertify\admin\setting_validatedtext(
        'local_recertify/intervalmonths',
        get_string('intervalmonths', 'local_recertify'),
        get_string('intervalmonths_help', 'local_recertify'),
        12,
        function (string $value): string {
            $months = (int)$value;
            if ($months < 1) {
                return get_string('errorintervalmonths', 'local_recertify');
            }
            if ($months > 600) {
                return get_string('errorintervaltoolarge', 'local_recertify');
            }
            return '';
        },
        PARAM_INT
    ));

    $settings->add(new \local_recertify\admin\setting_validatedtext(
        'local_recertify/fixeddate',
        get_string('fixeddate', 'local_recertify'),
        get_string('fixeddate_help', 'local_recertify'),
        '01-01',
        function (string $value): string {
            if (
                !preg_match('/^(\d{1,2})-(\d{1,2})$/', trim($value), $m)
                    || !checkdate((int)$m[1], (int)$m[2], 2024)
            ) {
                return get_string('errorfixeddate', 'local_recertify');
            }
            return '';
        },
        PARAM_TEXT
    ));

    $settings->add(new \local_recertify\admin\setting_validatedtext(
        'local_recertify/warningdays',
        get_string('warningdays', 'local_recertify'),
        get_string('warningdays_help', 'local_recertify'),
        14,
        function (string $value): string {
            $days = (int)$value;
            if ($days < 0 || $days > 365) {
                return get_string('errorwarningdays', 'local_recertify');
            }
            return '';
        },
        PARAM_INT
    ));

    $settings->add(new admin_setting_configselect(
        'local_recertify/resetdepth',
        get_string('resetdepth', 'local_recertify'),
        get_string('resetdepth_help', 'local_recertify'),
        'completion',
        [
            'completion' => get_string('resetdepth_completion', 'local_recertify'),
            'grades' => get_string('resetdepth_grades', 'local_recertify'),
            'full' => get_string('resetdepth_full', 'local_recertify'),
        ]
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_recertify/wipequizattempts',
        get_string('wipequizattempts', 'local_recertify'),
        get_string('wipequizattempts_help', 'local_recertify'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_recertify/wipeassignments',
        get_string('wipeassignments', 'local_recertify'),
        get_string('wipeassignments_help', 'local_recertify'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_recertify/wipescormtracks',
        get_string('wipescormtracks', 'local_recertify'),
        get_string('wipescormtracks_help', 'local_recertify'),
        0
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_recertify/notifyonreset',
        get_string('notifyonreset', 'local_recertify'),
        get_string('notifyonreset_help', 'local_recertify'),
        1
    ));

    $settings->add(new admin_setting_configcheckbox(
        'local_recertify/enableauditlog',
        get_string('enableauditlog', 'local_recertify'),
        get_string('enableauditlog_help', 'local_recertify'),
        1
    ));

    $ADMIN->add('local_recertify_cat', $settings);
}
