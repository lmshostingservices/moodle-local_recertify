# Changelog

All notable changes to `local_recertify` are documented here.

## [1.2.0] - 2026-09-04

### Added

- **A third schedule type, "A set time after course completion".** The retention period
  is measured from each learner's own course completion date rather than from their
  enrolment or a shared calendar date, so a course can be configured to wipe a learner's
  history a set number of months after they finish it — three months after completion,
  for example. The existing interval field supplies the number of months, so no schema
  change is needed and existing schedules are untouched.
  - Learners who have never completed the course are out of scope entirely. The
    enrolment date is deliberately not used as a fallback anchor: there is no completed
    record to retain, so an in-progress learner is never wiped.
  - The cycle repeats without a date walk. The reset clears the course completion
    record, which is the anchor, so the learner leaves scope at the moment of the wipe
    and re-enters it only on completing the course again.
  - Advance warnings work as for the other types, keyed to the upcoming boundary, so
    each learner is warned once per retention period.
  - PHPUnit coverage for the new arithmetic, including non-completers, learners inside
    the retention window, and the zero-interval clamp.
- **`tests/task/process_recertification_test.php`** — end-to-end coverage of the
  scheduled task against real enrolment and completion records: the join onto
  `course_completions`, a null completion date, the learner dropping out of scope after a
  wipe, re-completion opening a new cycle, one warning per retention period, and a
  grades-depth reset running to completion.

### Fixed

- **Grades-depth and full-depth resets have never worked.** The reset called
  `grade_regrade_final_grades($courseid, $userid)`, and core rejects that: the third
  argument, the single grade item whose raw grade changed, is mandatory whenever a user
  id is given, so the call threw *"updated_item cannot be null!"* every time. The task
  caught the throw, deleted its own cycle marker and logged the reset as FAILED, so every
  affected learner was retried on the next cron run and failed again, indefinitely, with
  their grades deleted but their completion left standing. Only *Completion only* depth
  ever completed. The gradebook is now flagged for recalculation with
  `grade_item::force_regrading()` instead — which is also the cheaper fix, since the
  learner's rows are gone from the category and course totals as well as the leaf items,
  and a course with a thousand learners due on the same day no longer means a thousand
  inline full-course regrades. Covered by a task-level test that fails against the old
  call.
- **`db/install.xml` failed two of Moodle's own plugin conformance tests.** It was not in
  canonical XMLDB form (every non-sequence field was missing `SEQUENCE="false"`), so
  `core\db\plugin_checks_test::test_db_install_file` failed, and it declared
  `DEFAULT=""` on the NOT NULL char column `depth`, which XMLDB rejects — that emitted a
  debugging warning on every load of the file and made
  `core_privacy\privacy\provider_test::test_table_coverage` fail as unexpected output.
  Both are fixed. No upgrade step is needed: Moodle's schema comparison treats a null and
  an empty-string default as equivalent, verified against a PostgreSQL database created
  from 1.1.1's schema and then upgraded.
- **A failed reset could strand a learner permanently.** `local_recertify_reset_user()`
  deleted the course completion record before the grade work, and the task's failure
  path removes the cycle marker so the learner is retried on the next run. If the
  regrade threw, the learner was left with no completion record — no anchor for a
  completion-anchored schedule — so the retry found nothing due and the partial reset
  was never finished or logged. The completion record is now deleted last, after the
  grade work, so a failure leaves the anchor intact and the retry works.
- The advance-warning window is now skipped when a learner has no upcoming reset at all,
  instead of measuring a window against a boundary of 1970. Only reachable through the
  new schedule type, where a non-completer legitimately has no next reset date.
- The privacy provider declared four of the eight columns `export_user_data()` actually
  exports. `action`, `triggertype`, `resettime` and `scheduleid` are now declared too.
- The schedule list showed "1 months after completion" for a one-month retention.

### Changed

- The interval field is shown for both the relative and the completion schedule types.
  It is hidden with a single "hide when fixed" condition rather than two "not equal to"
  conditions, because Moodle OR-s multiple `hideIf` rules on one element and two such
  rules would have hidden the field for every schedule type.
- `edit.php` now validates the submitted schedule type against the full whitelist rather
  than collapsing anything that is not `fixed` to `relative`.

## [1.1.1] - 2026-08-30

### Fixed

- **The upgrade aborted with "Table does not exist" on any site missing one of the
  plugin's tables.** The upgrade step called `field_exists()` directly, and that method
  throws `ddl_table_missing_exception` when the table itself is absent — which happens
  after a partial uninstall, a restore from a dump that excluded the tables, or an
  install that recorded its version but did not finish. The upgrade now recreates any
  missing table from `install.xml` before touching columns, and every migration below it
  is individually guarded, so it is safe to re-run.
- The `trigger` to `triggertype` rename is now skipped when `triggertype` already
  exists, so a partially applied upgrade can be resumed rather than failing.

## [1.1.0] - 2026-08-29

### Added

- **`edit.php` — the schedule editor.** 1.0.1 shipped without it, so the "Add
  recertification schedule" button led to a *File not found* page and no schedule could
  ever be created. The plugin therefore did nothing at all. There is now a proper
  `moodleform` with a course picker, schedule type, interval or fixed date, warning
  window, reset depth and the per-activity wipe options, with full server-side
  validation.
- **`export.php` — the CSV audit-log export**, likewise missing in 1.0.1. Streamed via
  recordset so a large log does not exhaust memory.
- **`db/messages.php`**, declaring the `warningnotice` and `resetnotice` message
  providers. Without it `message_send()` rejected both notifications as coming from an
  unregistered provider, so no learner was ever notified.
- PHPUnit coverage for the schedule arithmetic, the audit log and a completion-depth
  reset.
- A schedule can now be enabled or disabled from the list without opening the editor.

### Fixed

- **Resets could never fire.** The task compared `now >= next_reset_time()`, but that
  function always returns a date in the future, so the comparison could never be true.
  A learner enrolled three years ago on a twelve-month cycle was reported as next due in
  2027 and was never reset. There are now two functions: one for the next upcoming reset
  (used for warnings) and one for the most recent boundary already passed (used to decide
  whether a reset is due).
- **`completion_info::delete_course_completion_data_for_user()` does not exist** in any
  version of Moodle, so every reset died with *Call to undefined method*. Per-user
  completion is now cleared directly across `course_modules_completion`,
  `course_modules_viewed`, `course_completion_crit_compl` and `course_completions`, with
  the learner's two completion cache entries dropped afterwards.
- **Audit logging failed completely on MySQL and MariaDB.** The log table used `trigger`
  as a column name, which is a reserved word, so every `INSERT` failed with a SQL syntax
  error. The column is now `triggertype`, with an upgrade step that renames it in place.
- **A zero interval hung cron.** `strtotime('+0 months')` returns its input unchanged, so
  the date walk looped forever. The interval is now validated in the form and in site
  settings, and clamped in code regardless.
- **Warning emails repeated on every cron run** for the whole warning window — fourteen
  identical emails per learner with the default settings. Warnings and resets are now
  keyed to the cycle they belong to, enforced by a unique database index, so each is sent
  exactly once.
- **The Delete action did nothing.** The link was rendered but `$action` was never tested.
  Delete now works, behind `$OUTPUT->confirm()` and a sesskey check.
- **A full wipe orphaned data.** Deleting `quiz_attempts` rows left `quiz_grades` and
  question attempt steps behind; deleting `assign_submission` rows left `assign_grades`
  and the submitted files in the file pool. Wipes now run through the module APIs —
  `quiz_delete_user_attempts()`, the `assignsubmission`/`assignfeedback` per-user
  callbacks, and `scorm_delete_tracks()` — followed by a grade update for each activity.
- **SCORM wipes crashed on Moodle 5.** The code deleted from `scorm_scoes_track`, a table
  removed in Moodle 5.0 when SCORM tracking moved to `scorm_attempt` and
  `scorm_scoes_value`. Using `scorm_delete_tracks()` works on both schemas.
- **Two broken language strings.** `get_string('enabled')` and
  `get_string('deleteconfirm', 'admin')` both resolved against the wrong component, so
  the schedule table header and the delete confirmation rendered as `[[enabled]]` and
  `[[deleteconfirm]]`.
- **Fixed schedules ignored the learner.** They returned the same date for everyone
  regardless of enrolment date, so somebody who enrolled yesterday would be reset at the
  next occurrence. A learner who enrolled after the most recent occurrence is now skipped
  until the following one. Timestamps are built with `make_timestamp()` so the site
  timezone is respected.
- **Site default settings were never used.** All nine were defined and read by nothing.
  They now seed the form when a new schedule is created.
- Relative schedules fall back to the enrolment record's creation date when no explicit
  start date is set, instead of walking from 1970.
- A reset that throws now removes its own cycle marker so the next run retries, rather
  than silently skipping the learner forever.

### Changed

- The admin pages use `admin_externalpage_setup()`, restoring the admin breadcrumb and
  settings search.
- `local/recertify:viewlog` is now actually checked before the audit log is shown or
  exported; previously it was declared and never used.
- The audit log is paged, and records warnings as well as resets.
- Reset depth is documented in the interface, and selecting a full wipe shows an explicit
  warning that the deletion is permanent.

## [1.0.1] - 2026-07-31

- Initial release.
