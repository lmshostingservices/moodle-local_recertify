# Course Recertification (`local_recertify`)

A local plugin for Moodle that resets activity completion, course completion and optionally grades for enrolled learners on a recurring schedule, so they must re-certify every N months.

## Key features

- **Per-course schedules** — each course gets its own schedule, created and edited from Site administration → Plugins → Local plugins → Course Recertification
- **Three schedule types** — relative to each learner's own enrolment date, a fixed calendar date each year for everyone in the course, or a retention period measured from each learner's own course completion date ("wipe their record 3 months after they finish")
- **Three reset depths** — completion only, completion and grades, or a full wipe that also removes quiz attempts, assignment submissions and SCORM tracking
- **Advance warning** — learners are notified a configurable number of days before their reset, exactly once per cycle
- **Reset notification** — an optional message at the moment the reset happens
- **Idempotent by design** — every reset and warning is keyed to the cycle it belongs to, so no learner is reset twice or emailed twice for the same cycle, however often cron runs
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
