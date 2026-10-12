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
/** @package local_recertify @copyright 2026 LMS Labs @license http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later */
require(__DIR__ . '/../../config.php');

use local_recertify\cycle_service;
use local_recertify\cycle_choices;
use mod_certificatepro\local\issue_service;

require_login();
$context = context_system::instance();
$userid = optional_param('userid', $USER->id, PARAM_INT);
cycle_service::require_read($userid);
$manager = has_capability('local/recertify:manage', $context);
$page = max(0, optional_param('page', 0, PARAM_INT));
$url = new moodle_url('/local/recertify/cycles.php', ['userid' => $userid]);
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_title(get_string('cycles', 'local_recertify'));
$PAGE->set_heading(get_string('cycles', 'local_recertify'));
$PAGE->set_pagelayout('standard');
$available = class_exists('\\mod_certificatepro\\local\\issue_service');
$error = '';
$preview = null;
$selection = [];
$learnersearch = optional_param('learnersearch', '', PARAM_TEXT);
$learningsearch = optional_param('learningsearch', '', PARAM_TEXT);
$issuersearch = optional_param('issuersearch', '', PARAM_TEXT);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_sesskey();
    try {
        $action = required_param('action', PARAM_ALPHA);
        $id = optional_param('cycleid', 0, PARAM_INT);
        if ($action === 'allocate' || $action === 'preview') {
            $selection = [
                'kind' => required_param('kind', PARAM_ALPHA),
                'sourceid' => required_param('sourceid', PARAM_INT),
                'certificateid' => required_param('certificateid', PARAM_INT),
                'validitydays' => required_param('validitydays', PARAM_INT),
                'parentid' => optional_param('parentid', 0, PARAM_INT),
            ];
            $args = [$userid, $selection['kind'], $selection['sourceid'], $selection['certificateid'],
                $selection['validitydays'] * DAYSECS, $selection['parentid']];
            if ($action === 'preview') {
                $preview = cycle_service::preview(...$args);
            } else {
                cycle_service::allocate(...$args);
            }
        } else {
            $cycle = $DB->get_record(cycle_service::TABLE, ['id' => $id, 'userid' => $userid], '*', MUST_EXIST);
            if ($action === 'achieve') {
                if (!$available) {
                    throw new moodle_exception('cycleunavailable', 'local_recertify');
                }
                cycle_service::achieve($id);
                $issue = issue_service::issue_cycle($id);
                if (empty($issue->pdfhash)) {
                    throw new moodle_exception('cyclepending', 'local_recertify');
                }
            } else if ($action === 'revoke') {
                cycle_service::revoke($id, required_param('reason', PARAM_TEXT));
            } else {
                throw new invalid_parameter_exception('Unknown action');
            }
        }
        if ($action !== 'preview') {
            redirect($url);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// Existing bytes only. Downloading never retries a renderer or reissues an award.
if ($downloadid = optional_param('download', 0, PARAM_INT)) {
    $cycle = $DB->get_record(cycle_service::TABLE, ['id' => $downloadid, 'userid' => $userid], '*', MUST_EXIST);
    if (!$available) {
        throw new moodle_exception('cycleunavailable', 'local_recertify');
    }
    $issue = $DB->get_record('certificatepro_issues', ['cycleid' => $cycle->id, 'userid' => $userid], '*', MUST_EXIST);
    if (empty($issue->pdfhash) || (!$manager && issue_service::status($issue) === 'revoked')) {
        throw new moodle_exception('cyclepending', 'local_recertify');
    }
    $instance = $DB->get_record('certificatepro', ['id' => $issue->certificateproid], '*', MUST_EXIST);
    $cm = get_coursemodule_from_instance('certificatepro', $instance->id, $instance->course, false, MUST_EXIST);
    $files = get_file_storage()->get_area_files(context_module::instance($cm->id)->id,
        'mod_certificatepro', issue_service::FILEAREA, $issue->id, 'id', false);
    $file = $files ? reset($files) : false;
    if (!$file || hash('sha256', $file->get_content()) !== $issue->pdfhash) {
        throw new moodle_exception('historicaldocumentmissing', 'mod_certificatepro');
    }
    send_stored_file($file, 0, 0, true);
}

echo $OUTPUT->header();
if ($manager) {
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => '/local/recertify/cycles.php']);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $userid]);
    echo html_writer::tag('label', 'Search learners by name ' . html_writer::empty_tag('input',
        ['type' => 'search', 'name' => 'learnersearch', 'value' => $learnersearch]));
    echo html_writer::tag('button', 'Search learners', ['type' => 'submit', 'class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');
    $learners = cycle_choices::search('learner', $userid, $learnersearch);
    echo html_writer::tag('p', 'Up to 100 matches. Narrow the search if the name is not listed.');
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => '/local/recertify/cycles.php']);
    echo html_writer::tag('label', 'Learner', ['for' => 'id_learner']);
    echo html_writer::select($learners, 'userid', $userid, ['' => 'Choose a learner'],
        ['id' => 'id_learner', 'required' => 'required']);
    echo html_writer::tag('button', 'View learner history', ['type' => 'submit', 'class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');
}
$recipient = $DB->get_record('user', ['id' => $userid], '*', MUST_EXIST);
echo $OUTPUT->heading(fullname($recipient), 3);
echo $OUTPUT->notification(get_string('cyclesafety', 'local_recertify'), 'info');
if ($error) {
    echo $OUTPUT->notification(s($error), 'error');
}
if (!$available) {
    echo $OUTPUT->notification(get_string('cycleunavailable', 'local_recertify'), 'warning');
}
$count = $DB->count_records(cycle_service::TABLE, ['userid' => $userid]);
$cycles = $DB->get_records(cycle_service::TABLE, ['userid' => $userid], 'id DESC', '*', $page * 25, 25);
$table = new html_table();
$table->head = ['Cycle', 'Learning', 'Status', 'Achieved', 'Valid until', 'Previous cycle', 'Actions'];
$form = static function (array $fields, string $label, string $extra = '') use ($url): string {
    $html = html_writer::start_tag('form', ['method' => 'post', 'action' => $url->out(false)]);
    foreach (['sesskey' => sesskey()] + $fields as $name => $value) {
        $html .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => $name, 'value' => $value]);
    }
    return $html . $extra . html_writer::tag('button', s($label), ['type' => 'submit', 'class' => 'btn btn-secondary'])
        . html_writer::end_tag('form');
};
foreach ($cycles as $cycle) {
    $issue = $available ? $DB->get_record('certificatepro_issues', ['cycleid' => $cycle->id]) : false;
    $status = cycle_service::status($cycle);
    $actions = '';
    if ($cycle->status !== 'revoked' && $available && (!$issue || !$issue->pdfhash)) {
        $actions .= $form(['action' => 'achieve', 'cycleid' => $cycle->id], 'Check completion / retry document');
    }
    if ($issue) {
        $status .= ' / document: ' . issue_service::status($issue);
        if ($issue->pdfhash && ($manager || issue_service::status($issue) !== 'revoked')) {
            $actions .= html_writer::link(new moodle_url($url, ['download' => $cycle->id]), 'Download certificate');
        }
        $actions .= ' ' . html_writer::link(new moodle_url('/mod/certificatepro/verify.php',
            ['code' => $issue->code]), 'Verify');
    }
    if ($manager && $cycle->status !== 'revoked') {
        $actions .= $form(['action' => 'revoke', 'cycleid' => $cycle->id], 'Revoke',
            html_writer::tag('label', 'Reason ' . html_writer::empty_tag('input',
                ['name' => 'reason', 'required' => 'required', 'type' => 'text'])));
    }
    $table->data[] = [$cycle->id, s($cycle->name), s($status),
        $cycle->timeachieved ? userdate($cycle->timeachieved) : '—',
        $cycle->expires ? userdate($cycle->expires) : 'No expiry', $cycle->parentid ?: '—', $actions];
}
echo html_writer::table($table);
echo $OUTPUT->paging_bar($count, $page, 25, $url);
if ($manager && $available) {
    echo $OUTPUT->heading('Allocate initial learning or a renewal', 3);
    echo html_writer::tag('p', 'Choose existing learning for this learner. For a renewal, select the previous award '
        . 'and new refresher learning. No enrolment or reset is performed. '
        . 'The selected Certificate Pro activity becomes a dedicated cycle issuer: ordinary automatic/manual issuance is disabled. '
        . 'Choose an activity with no existing ordinary certificates.');
    if ($preview !== null) {
        echo $OUTPUT->notification(s('Preview: ' . $preview
            . '. No records have been changed. '
            . 'Allocation checks again when you confirm. Completion evidence is checked when awarding.'), 'info');
        echo html_writer::tag('p', s('Validity: ' . ($selection['validitydays']
            ? $selection['validitydays'] . ' days' : 'No expiry')));
        if ($selection['parentid']) {
            $previous = $DB->get_record(cycle_service::TABLE,
                ['id' => $selection['parentid'], 'userid' => $userid], '*', MUST_EXIST);
            echo html_writer::tag('p', s('Renewing: ' . $previous->name . ' — ' . userdate($previous->timeachieved)));
        } else {
            echo html_writer::tag('p', 'Initial learning (no previous award)');
        }
        echo $form(['action' => 'allocate'] + $selection, 'Confirm allocation without resetting progress');
    }
    echo html_writer::start_tag('form', ['method' => 'get', 'action' => $url->out(false)]);
    echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'userid', 'value' => $userid]);
    foreach (['learningsearch' => ['Search course or program name', $learningsearch],
            'issuersearch' => ['Search Certificate Pro activity name', $issuersearch]] as $name => [$label, $value]) {
        echo html_writer::tag('p', html_writer::tag('label', s($label) . ' ' . html_writer::empty_tag('input',
            ['type' => 'search', 'name' => $name, 'value' => $value])));
    }
    echo html_writer::tag('button', 'Search learning and issuers', ['type' => 'submit', 'class' => 'btn btn-secondary']);
    echo html_writer::end_tag('form');
    echo html_writer::tag('p', 'Up to 100 matches per list. Courses are limited to this learner’s enrolments; '
        . 'programs to their assignments; issuers to activities you can manage. '
        . 'Inactive access, reset schedules and incompatible renewals are checked before confirmation.');
    $issuers = cycle_choices::search('certificate', $userid, $issuersearch);
    $parents = [0 => 'Initial learning (no previous award)'];
    foreach ($DB->get_records(cycle_service::TABLE, ['userid' => $userid, 'status' => 'achieved'], 'id DESC') as $parent) {
        $parents[$parent->id] = $parent->name . ' — ' . userdate($parent->timeachieved) . ' (#' . $parent->id . ')';
    }
    foreach (['course' => 'Course', 'program' => 'Program assignment'] as $kind => $label) {
        $sources = cycle_choices::search($kind, $userid, $learningsearch);
        echo $OUTPUT->heading($label, 4);
        if (!$sources || !$issuers) {
            echo html_writer::tag('p', 'No matching learning or manageable issuer. Refine the search above.');
            continue;
        }
        $fields = '';
        foreach (['sourceid' => [$label, $sources], 'certificateid' => ['Certificate Pro activity', $issuers],
                'parentid' => ['Previous award', $parents]] as $name => [$fieldlabel, $options]) {
            $id = 'id_' . $kind . '_' . $name;
            $selected = ($selection['kind'] ?? '') === $kind ? ($selection[$name] ?? '') : '';
            $fields .= html_writer::tag('p', html_writer::tag('label', s($fieldlabel), ['for' => $id]) . ' '
                . html_writer::select($options, $name, $selected, $name === 'parentid' ? false : ['' => 'Choose by name'],
                    ['id' => $id, 'required' => 'required']));
        }
        $fields .= html_writer::tag('p', html_writer::tag('label', 'Validity in days (0 = no expiry) '
            . html_writer::empty_tag('input', ['type' => 'number', 'name' => 'validitydays', 'min' => 0,
                'max' => 3650, 'value' => $selection['validitydays'] ?? 0, 'required' => 'required'])));
        echo $form(['action' => 'preview', 'kind' => $kind, 'learningsearch' => $learningsearch,
            'issuersearch' => $issuersearch], 'Check access and preview allocation', $fields);
    }
}
echo $OUTPUT->footer();
