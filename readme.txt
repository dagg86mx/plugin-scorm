=== Tutor SCORM ===
Tags: scorm, tutor lms, lms, elearning, xapi
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Play SCORM 1.2 and SCORM 2004 packages inside Tutor LMS lessons, track learner progress and complete lessons automatically. Replaces xAPI-based delivery.

== Description ==

Tutor SCORM adds a built-in SCORM runtime to WordPress and integrates it with Tutor LMS:

* SCORM 1.2 and SCORM 2004 (3rd/4th edition) with automatic version detection.
* Upload ZIP packages from the editor or the Media Library.
* Import whole courses: a ZIP with outline.csv / outline.json and one SCORM per lesson creates the Tutor course, topics and lessons and attaches each package (admin screen or `wp tutor-scorm import-course`).
* Inline (iframe) or pop-up player, full screen, multi-SCO navigation.
* Per learner and SCO tracking: status, score, total time, sessions, suspend data, interactions and objectives.
* Automatic Tutor LMS lesson completion (configurable rule) and course auto-completion support.
* Reports with filters, CSV export and progress reset.
* xAPI migration helper listing lessons that still embed xAPI content.
* Spanish (es_ES) translation included.

Usage: upload a package in Tutor LMS > SCORM Packages, then attach it to lessons or use `[tutor_scorm id="123"]`.

== Installation ==

1. Upload the plugin folder to `wp-content/plugins/tutor-scorm/`.
2. Activate the plugin.
3. Configure it in Tutor LMS > SCORM Settings.

== Changelog ==

= 1.1.0 =
* Course bundle import: create a Tutor LMS course, topics and lessons from a ZIP with an outline file and attach a SCORM package to each lesson.
* WP-CLI command `wp tutor-scorm import-course`.
* FTP inbox folder for bundles larger than the upload limit.

= 1.0.0 =
* First release.
