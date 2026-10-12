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
 * Read-only, manager-scoped choices for the existing cycle engine.
 *
 * @package local_recertify
 * @copyright 2026 LMS Labs
 * @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class cycle_choices {
    /** Limit each search; managers can narrow by name instead of loading an entire site. */
    public const LIMIT = 100;

    /** Search names using literal, parameterised matching. */
    public static function search(string $type, int $userid, string $query): array {
        global $DB;
        require_capability('local/recertify:manage', \context_system::instance());
        $params = ['query' => '%' . $DB->sql_like_escape(trim($query)) . '%'];
        $options = [];
        if ($type === 'learner') {
            $name = $DB->sql_concat('firstname', "' '", 'lastname');
            $records = $DB->get_records_select('user',
                'deleted = 0 AND username <> :guest AND ' . $DB->sql_like($name, ':query', false),
                $params + ['guest' => 'guest'], 'lastname, firstname, id', '*', 0, self::LIMIT);
            foreach ($records as $record) {
                $options[$record->id] = fullname($record) . ' (' . $record->username . ')'
                    . ($record->suspended ? ' — suspended' : '');
            }
        } else if ($type === 'course') {
            // Include inactive enrolments so the read-only preflight can explain the conflict.
            $records = $DB->get_records_sql(
                "SELECT DISTINCT c.id, c.fullname, c.shortname
                   FROM {course} c JOIN {enrol} e ON e.courseid = c.id
                   JOIN {user_enrolments} ue ON ue.enrolid = e.id
                  WHERE ue.userid = :userid AND " . $DB->sql_like('c.fullname', ':query', false) .
                " ORDER BY c.fullname, c.id", $params + ['userid' => $userid], 0, self::LIMIT);
            foreach ($records as $record) {
                $options[$record->id] = $record->fullname . ' (' . $record->shortname . ')';
            }
        } else if ($type === 'program' && class_exists('\\local_trainingpathways\\progress_service')) {
            $records = $DB->get_records_sql(
                "SELECT a.id, a.status, a.timeassigned, p.name
                   FROM {local_tp_assignments} a JOIN {local_tp_pathways} p ON p.id = a.pathwayid
                  WHERE a.userid = :userid AND " . $DB->sql_like('p.name', ':query', false) .
                " ORDER BY p.name, a.id", $params + ['userid' => $userid], 0, self::LIMIT);
            foreach ($records as $record) {
                $options[$record->id] = $record->name . ' — ' . $record->status . ' — '
                    . userdate($record->timeassigned) . ' (#' . $record->id . ')';
            }
        } else if ($type === 'certificate' && class_exists('\\mod_certificatepro\\local\\issue_service')) {
            $records = $DB->get_recordset_sql(
                "SELECT cp.id, cp.name, cp.course, c.fullname
                   FROM {certificatepro} cp JOIN {course} c ON c.id = cp.course
                  WHERE " . $DB->sql_like('cp.name', ':query', false) . " ORDER BY cp.name, cp.id", $params);
            foreach ($records as $record) {
                $cm = get_coursemodule_from_instance('certificatepro', $record->id, $record->course);
                if ($cm && has_capability('mod/certificatepro:manage', \context_module::instance($cm->id))) {
                    $options[$record->id] = $record->name . ' — ' . $record->fullname . ' (#' . $record->id . ')';
                    if (count($options) >= self::LIMIT) {
                        break;
                    }
                }
            }
            $records->close();
        }
        return $options;
    }
}
