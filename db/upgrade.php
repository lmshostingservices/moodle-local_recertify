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
 * Upgrade script for local_recertify.
 *
 * @package    local_recertify
 * @copyright  2026 LMS Hosting Services
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Run all local_recertify upgrade steps.
 *
 * @param int $oldversion The version we are upgrading from.
 * @return bool
 */
function xmldb_local_recertify_upgrade(int $oldversion): bool {
    global $CFG, $DB;

    $dbman = $DB->get_manager();
    $installfile = $CFG->dirroot . '/local/recertify/db/install.xml';

    if ($oldversion < 2026082902) {
        // A site can reach an upgrade with one of the plugin's tables missing: a partial
        // uninstall, a restore from a dump that excluded them, or an install that failed
        // after the version was recorded. Recreate anything absent from install.xml first,
        // because field_exists() throws ddl_table_missing_exception on a missing table.
        foreach (['local_recertify_schedule', 'local_recertify_log'] as $tablename) {
            if (!$dbman->table_exists(new xmldb_table($tablename))) {
                $dbman->install_one_table_from_xmldb_file($installfile, $tablename);
            }
        }

        // Anything created just now already carries the current schema, so the migrations
        // below are all guarded and simply no-op in that case.
        $table = new xmldb_table('local_recertify_log');

        // The old column was named "trigger", a reserved word in MySQL and MariaDB, so
        // every insert against this table failed with a syntax error. The table is
        // therefore expected to be empty, but the rename preserves anything that is there.
        $oldfield = new xmldb_field('trigger', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'scheduled', 'depth');
        if ($dbman->field_exists($table, $oldfield) && !$dbman->field_exists($table, 'triggertype')) {
            $dbman->rename_field($table, $oldfield, 'triggertype');
        }

        // Distinguishes reset rows from advance-warning rows.
        $action = new xmldb_field('action', XMLDB_TYPE_CHAR, '20', null, XMLDB_NOTNULL, null, 'reset', 'userid');
        if (!$dbman->field_exists($table, $action)) {
            $dbman->add_field($table, $action);
        }

        // The cycle boundary a row belongs to. Combined with the lookup in
        // local_recertify_log() this makes both resets and warnings idempotent, so a
        // learner cannot be reset twice for the same cycle or emailed on every cron run
        // during the warning window. The supporting index is deliberately not unique:
        // every pre-existing row would get resettime 0, and a unique index could fail to
        // build on a site already holding two rows for the same user and course.
        $resettime = new xmldb_field('resettime', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0', 'triggertype');
        if (!$dbman->field_exists($table, $resettime)) {
            $dbman->add_field($table, $resettime);
        }

        $index = new xmldb_index('idx_cycle', XMLDB_INDEX_NOTUNIQUE, ['userid', 'courseid', 'action', 'resettime']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026082902, 'local', 'recertify');
    }

    return true;
}
