# Course Recertification (`local_recertify`)

A local plugin for Moodle that automatically resets activity completion, course completion, and optionally grades for enrolled learners on a recurring schedule — so they must re-certify every N months.

## Key features

- **Relative schedule**: reset N months after each learner's enrolment date (rolling anniversary)
- **Fixed schedule**: reset on a specific annual calendar date (e.g. 1 Jan) regardless of enrolment date
- **Advance warning notifications**: email + Moodle notification sent N days before reset
- **Configurable reset depth**:
  - Completion only — activity and course completion marks
  - Completion + grades — above plus gradebook entries
  - Full data wipe — above plus quiz attempts, assignment submissions, SCORM tracks
- **Per-course overrides** — site defaults can be overridden per course
- **Full audit log** — every reset logged with user, course, depth, trigger, timestamp
- **GDPR Privacy API** — personal data in the audit log is exportable and deletable
- No AI credits required; no external API calls

## Installation

1. Download the ZIP from lms-labs.com → Plugins → Course Recertification
2. Moodle → Site administration → Plugins → Install plugins → upload ZIP
3. Configure site defaults at Site administration → Plugins → Local plugins → Course Recertification
4. Enable per course at Course settings → Recertification tab

## Warning

The "Full data wipe" reset depth permanently deletes quiz attempts, assignment submissions, and SCORM tracking records. **This cannot be undone.** Test on a staging site before enabling in production.

## Compatibility

Moodle 4.4 – 5.x · PHP 7.4+ · MySQL or PostgreSQL

## Licence

GNU GPL v3 or later — see [COPYING](https://www.gnu.org/licenses/gpl-3.0.html)

## Support

support@lmshostingservices.com · https://lms-labs.com/docs/course-recertification
