# Course Recertification (`local_recertify`)

A local plugin for Moodle that resets activity completion, course completion and optionally grades for enrolled learners on a recurring schedule, so they must re-certify every N months.

## Key features

- **Per-course schedules** — each course gets its own schedule, created and edited from Site administration → Plugins → Local plugins → Course Recertification
- **Two schedule types** — relative to each learner's own enrolment date, or a fixed calendar date each year for everyone in the course
- **Three reset depths** — completion only, completion and grades, or a full wipe that also removes quiz attempts, assignment submissions and SCORM tracking
- **Advance warning** — learners are notified a configurable number of days before their reset, exactly once per cycle
- **Reset notification** — an optional message at the moment the reset happens
- **Idempotent by design** — a unique index on the cycle means no learner is ever reset twice for the same cycle, however often cron runs
- **Full audit log** — every reset and every warning is recorded, with CSV export
- **Site-wide defaults** — set the values new schedules start from
- **GDPR Privacy API** — full privacy provider with export and deletion support
- No AI credits required; no external API calls

## How it works

A daily scheduled task walks every enabled schedule and every active enrolment in its course.

For each learner it works out the **most recent reset boundary that has already passed**. If there is one and it has not already been logged, the learner is reset and the reset is recorded against that boundary. It separately works out the **next upcoming boundary**; if today falls inside the warning window, one warning is sent and recorded against that boundary.

Keeping those two questions apart matters: the "next" date is always in the future, so it can never tell you a reset is due.

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
vendor/bin/phpunit local/recertify/tests/lib_test.php
```

## Licence

GNU GPL v3 or later — see [COPYING](https://www.gnu.org/licenses/gpl-3.0.html)

## Support

support@lmshostingservices.com
