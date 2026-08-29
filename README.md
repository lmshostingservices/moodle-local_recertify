# Course Recertification (`local_recertify`)

## Summary

A local plugin for Moodle that automatically resets activity completion, course completion, and optionally grades for enrolled learners on a recurring schedule — so they must re-certify every N months.

## Description

A local plugin for Moodle that automatically resets activity completion, course completion, and optionally grades for enrolled learners on a recurring schedule — so they must re-certify every N months.

## Features

- Relative schedule: reset N months after each learner's enrolment date (rolling anniversary)
- Fixed schedule: reset on a specific annual calendar date (e.g. 1 Jan) regardless of enrolment date
- Advance warning notifications: email + Moodle notification sent N days before reset
- Configurable reset depth:
- Completion only — activity and course completion marks
- Completion + grades — above plus gradebook entries
- Full data wipe — above plus quiz attempts, assignment submissions, SCORM tracks
- Per-course overrides — site defaults can be overridden per course
- Full audit log — every reset logged with user, course, depth, trigger, timestamp
- GDPR Privacy API — personal data in the audit log is exportable and deletable
- No AI credits required; no external API calls

## Installation

- Download the ZIP from lms-labs.com → Plugins → Course Recertification
- Moodle → Site administration → Plugins → Install plugins → upload ZIP
- Configure site defaults at Site administration → Plugins → Local plugins → Course Recertification
- Enable per course at Course settings → Recertification tab

## Current Release

Version 1.0.2 republishes the reviewed authoritative source under a new immutable tag because the historical tag contained a different source tree. There are no functional changes in this release.

## Licence

GNU GPL v3 or later.
