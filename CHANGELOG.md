# Changelog

All notable changes to `local_recertify` are documented here.

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
