<?php
// This file is part of the mod_coursecertificate plugin for Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace mod_coursecertificate\output;

use advanced_testcase;
use tool_certificate_generator;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_coursecertificate\output\mobile::class)]
/**
 * Tests for the Moodle App view of the course certificate activity.
 *
 * @package     mod_coursecertificate
 * @category    test
 * @covers      \mod_coursecertificate\output\mobile
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class mobile_test extends advanced_testcase {
    /**
     * Set up
     */
    public function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Get certificate generator
     *
     * @return tool_certificate_generator
     */
    protected function get_certificate_generator(): tool_certificate_generator {
        return $this->getDataGenerator()->get_plugin_generator('tool_certificate');
    }

    /**
     * Creates a course with a certificate activity whose intro is HTML.
     *
     * @return array the course, the activity record, its course module and its template
     */
    protected function create_activity(): array {
        global $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $template = $this->get_certificate_generator()->create_template((object) ['name' => 'Mobile template']);
        $coursecertificate = $this->getDataGenerator()->create_module('coursecertificate', [
            'course' => $course->id,
            'template' => $template->get_id(),
            'intro' => '<p>Mobile intro</p>',
            'introformat' => FORMAT_HTML,
        ]);
        $cm = get_fast_modinfo($course)->instances['coursecertificate'][$coursecertificate->id];
        // The view reads the theme before require_course_login() sets the course; a web service request
        // allows that (WS_SERVER), a test process does not, so the course module is set up front.
        $PAGE->set_cm($cm, $course);
        $PAGE->set_url(new \moodle_url('/mod/coursecertificate/view.php', ['id' => $cm->id]));
        return [$course, $coursecertificate, $cm, $template];
    }

    /**
     * The teacher view formats the intro and lists the issues of the activity's template.
     *
     * The intro goes through {@see \core_external\util::format_text()}: the legacy
     * external_format_text() it replaced throws a coding_exception on Moodle 5.3.
     */
    public function test_mobile_certificate_view_teacher(): void {
        [$course, , $cm, $template] = $this->create_activity();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course);
        $template->issue_certificate($student->id, null, [], 'mod_coursecertificate', $course->id);
        $this->setUser($teacher);

        $result = mobile::mobile_certificate_view(['cmid' => $cm->id, 'courseid' => $course->id]);

        $html = $result['templates'][0]['html'];
        $this->assertStringContainsString('Mobile intro', $html);
        $this->assertStringContainsString(fullname($student), $html);
        $this->assertSame(0, $result['otherdata']['group']);
    }

    /**
     * The student view issues the certificate on first view and links its file.
     */
    public function test_mobile_certificate_view_student(): void {
        global $DB;
        [$course, $coursecertificate, $cm] = $this->create_activity();
        $student = $this->getDataGenerator()->create_and_enrol($course);
        $this->setUser($student);
        // The precondition: nothing has been issued before the view.
        $this->assertFalse($DB->record_exists('tool_certificate_issues', ['userid' => $student->id]));

        $result = mobile::mobile_certificate_view(['cmid' => $cm->id, 'courseid' => $course->id]);

        $issue = $DB->get_record('tool_certificate_issues', [
            'userid' => $student->id,
            'templateid' => $coursecertificate->template,
            'courseid' => $course->id,
        ], '*', MUST_EXIST);
        $html = $result['templates'][0]['html'];
        $this->assertStringContainsString('Mobile intro', $html);
        $this->assertStringContainsString($issue->code, $html);
    }
}
