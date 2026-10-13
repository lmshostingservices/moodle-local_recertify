# Course Recertification (`local_recertify`)

A local plugin for Moodle that resets activity completion, course completion and optionally grades for enrolled learners on a recurring schedule, so they must re-certify every N months.

## Key features

- **Per-course schedules** — each course gets its own schedule, created and edited from Site administration → Plugins → Local plugins → Course Recertification
- **Three schedule types** — relative to each learner's own enrolment date, a fixed calendar date each year for everyone in the course, or a retention period measured from each learner's own course completion date ("wipe their record 3 months after they finish")
- **Reset whole course or selected activities** — a schedule normally clears every activity in its course, or you can limit it to activities you pick, leaving the rest of the learner's progress alone
- **Three reset depths** — completion only, completion and grades, or a full wipe that also removes quiz attempts, assignment submissions and SCORM tracking
- **Advance warning** — learners are notified a configurable number of days before their reset, exactly once per cycle
- **Reset notification** — an optional message at the moment the reset happens
- **Idempotent by design** — every reset and warning is keyed to the cycle it belongs to, so no learner is reset twice or emailed twice for the same cycle, however often cron runs
- **Training record** — every reset records the learner's completion date and final grade as they were before the wipe, so the log reads "completed on X, reset on Y" and survives the data it describes
- **Full audit log** — every reset and every warning is recorded, with CSV export
- **Site-wide defaults** — set the values new schedules start from
- **GDPR Privacy API** — full privacy provider with export and deletion support
- No AI credits required; no external API calls

## How it works

A daily scheduled task walks every enabled schedule and every active enrolment in its course.

For each learner it works out the **most recent reset boundary that has already passed**. If there is one and it has not already been logged, the learner is reset and the reset is recorded against that boundary. It separately works out the **next upcoming boundary**; if today falls inside the warning window, one warning is sent and recorded against that boundary.

Keeping those two questions apart matters: the "next" date is always in the future, so it can never tell you a reset is due.

## Schedule types

| Type | Clock starts | Who is in scope |
| --- | --- | --- |
| Relative to enrolment date | The learner's enrolment start date | Every active enrolment |
| Fixed date each year | The calendar date you set | Everyone enrolled before the most recent occurrence |
| A set time after course completion | The learner's own course completion date | Only learners who have completed the course |

### A set time after course completion

Set the schedule type to **A set time after course completion** and the interval to the retention period you want — `3` for "wipe their course history three months after they complete it". Each learner's clock starts on their own completion date, so nobody is wiped on a shared calendar date and nobody is wiped early.

Learners who have **never completed the course are left alone entirely**. There is no completed record to retain, so the enrolment date is deliberately not used as a fallback; an in-progress learner is never touched by this schedule type, however long they have been enrolled.

The cycle repeats without any date arithmetic. The reset clears the course completion record, which is the anchor for this schedule type, so the learner drops out of scope the moment they are wiped and re-enters it only when they complete the course again — at which point the retention period starts over from the new completion date.

Advance warnings work as they do for the other types: a learner on a three-month retention with a 14-day warning window is notified once, two weeks before their record is due to be wiped.

> **Choose your reset depth carefully with this schedule type.** At *Completion only* depth the underlying attempts and grades survive the wipe, and Moodle's own completion aggregation may then re-satisfy the course criteria from that surviving data and mark the learner complete again — which restarts the retention clock instead of requiring them to re-certify. If the intent is that the learner must actually do the course again, use *Completion and grades* or a *full* wipe.

## Limiting a reset to selected activities

By default a schedule clears every activity in its course. Setting **Activities to reset**
to *Only the selected activities* narrows it to the ones you tick — useful when a course
contains something that should survive recertification, such as an induction module.

This changes *what* a reset clears, not *when* it runs. The schedule is still one per
course with one clock.

- Activity completion, viewed flags, grades and the quiz/assignment/SCORM wipes are all
  restricted to the selected activities. The per-type deletion checkboxes still apply on
  top: a selected quiz loses its attempts only if *Delete quiz attempts* is also ticked.
- Every activity is listed, including those with completion tracking switched off, because
  an untracked activity can still hold attempts and grades worth clearing.
- The picker appears when you **edit** a schedule. A new schedule has no course chosen
  while its form is built, so create it first and narrow it on the next screen.

> **Course completion.** A scoped reset revokes the learner's course completion only when
> one of the selected activities is a completion criterion for the course. Moodle has no
> way to un-complete a course, so leaving the record alone would mean the learner stays
> course-complete — and keeps a valid certificate — while a required activity sits
> incomplete. Revoking it every time would be equally wrong: clearing an activity that
> never gated completion would reset their completion date and trigger a fresh
> certificate for no reason.

## The training record

The audit log is not only a record of what the plugin did — it is the record that the
training happened. Each reset row stores the learner's **course completion date** and
**final course grade** exactly as they were immediately before the reset cleared them,
so the log reads "completed on X with grade Y, reset on Z". Both appear on the
management page and in the CSV export.

A learner with no grade records null rather than zero: zero is a real mark, and
recording it for someone never graded would make the register untrue.

The **Record reset depth in the audit log** setting does not switch this off. That
setting concerns reset detail; the completion snapshot cannot be recovered once the
reset has run, so it is always kept.

> **It starts from the day you install this version.** It cannot recover completions
> already cleared by an earlier release, and it only captures completions this plugin
> itself resets. Learners given a backdated *enrolment* date but no Moodle completion
> record have nothing to capture until their first real completion.

## Reset depths

| Depth | What is removed |
| --- | --- |
| Completion only | Activity completion, viewed flags, course completion and criteria |
| Completion and grades | The above, plus the learner's grades in the course, followed by a regrade |
| Full | The above, plus attempts and submissions for the activity types you tick |

A full wipe is **permanent**. It runs through each module's own API — `quiz_delete_user_attempts()`, the `assignsubmission` and `assignfeedback` per-user callbacks, and `scorm_delete_tracks()` — so grades, question attempt steps and submitted files go with the attempt rather than being orphaned. Test it on one course before enabling it widely.

## Installation

1. Site administration → Plugins → Install plugins → upload the ZIP
2. Site administration → Plugins → Local plugins → Course Recertification → Settings, to set the defaults new schedules start from
3. Open **Manage course recertification** and add a schedule for a course
4. Leave the schedule disabled until you have checked the dates it produces, then enable it

## Upgrading from 1.0.1

1.0.1 never performed a reset and never sent a notification, so there is no historical data to migrate. The upgrade renames the reserved-word column, adds two fields and one index, and preserves any schedules you created by other means. Review each schedule after upgrading: resets that could not fire before will start firing.

## Capabilities

| Capability | Default | Purpose |
| --- | --- | --- |
| `local/recertify:manage` | Manager | Create, edit, enable and delete schedules |
| `local/recertify:viewlog` | Manager | View and export the audit log |

## Compatibility

Moodle 4.4 – 5.2 · PHP 8.0+ · MySQL, MariaDB or PostgreSQL

## Testing

```
vendor/bin/phpunit --testsuite local_recertify_testsuite
```

`tests/lib_test.php` covers the schedule arithmetic, the audit log and the reset
functions; `tests/task/process_recertification_test.php` runs the scheduled task itself
against real enrolment and completion records.

Verified on Moodle 5.0 with PHP 8.4 and PostgreSQL 16: 18 plugin tests, plus Moodle's own
plugin conformance suites (`vendor/bin/phpunit --filter local_recertify`), plus a
1.1.1 → 1.2.0 upgrade on a live database followed by
`admin/cli/check_database_schema.php`.

## Licence

GNU GPL v3 or later — see [COPYING](https://www.gnu.org/licenses/gpl-3.0.html)

## Support

support@lmshostingservices.com
