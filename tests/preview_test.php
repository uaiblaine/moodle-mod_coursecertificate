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

namespace mod_coursecertificate;

use advanced_testcase;
use mod_coursecertificate\output\view_page;
use tool_certificate_generator;

#[\PHPUnit\Framework\Attributes\CoversClass(\mod_coursecertificate\helper::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_coursecertificate\permission::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\mod_coursecertificate\output\view_page::class)]
/**
 * Tests for the certificate preview of the course certificate activity.
 *
 * @package     mod_coursecertificate
 * @category    test
 * @covers      \mod_coursecertificate\helper
 * @covers      \mod_coursecertificate\permission
 * @covers      \mod_coursecertificate\output\view_page
 * @copyright   2026 Anderson Blaine
 * @license     http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class preview_test extends advanced_testcase {
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
     * Creates a course with a certificate activity.
     *
     * @param bool $withtemplate false for an activity whose template was never selected
     * @return array the course, its course module and its template (null without one)
     */
    protected function create_activity(bool $withtemplate = true): array {
        global $DB, $PAGE;
        $course = $this->getDataGenerator()->create_course();
        $template = $this->get_certificate_generator()->create_template((object) ['name' => 'Preview template']);
        // A template without a page renders nothing.
        $this->get_certificate_generator()->create_page($template);
        $coursecertificate = $this->getDataGenerator()->create_module('coursecertificate', [
            'course' => $course->id,
            'template' => $template->get_id(),
        ]);
        if (!$withtemplate) {
            // The generator always picks a template, so the unselected state is written directly.
            $DB->set_field('coursecertificate', 'template', 0, ['id' => $coursecertificate->id]);
            $template = null;
        }
        $cm = get_fast_modinfo($course)->instances['coursecertificate'][$coursecertificate->id];
        // The view page reads the page URL for its group selector.
        $PAGE->set_cm($cm, $course);
        $PAGE->set_url(new \moodle_url('/mod/coursecertificate/view.php', ['id' => $cm->id]));
        return [$course, $cm, $template];
    }

    /**
     * Exports the view page of an activity for the current user.
     *
     * @param \stdClass $course
     * @param \cm_info $cm
     * @return array the template context
     */
    protected function export_view_page(\stdClass $course, \cm_info $cm): array {
        global $PAGE;
        $page = new view_page((int) $cm->id, 0, 10, $course, $cm);
        return $page->export_for_template($PAGE->get_renderer('coursecertificate'));
    }

    /**
     * A teacher, who holds no capability on the template itself, gets the activity's template as a PDF.
     */
    public function test_teacher_previews_the_template(): void {
        [$course, $cm, $template] = $this->create_activity();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $this->setUser($teacher);

        $this->assertFalse($template->can_manage());
        $this->assertTrue(permission::can_preview(\context_module::instance($cm->id)));

        $previewed = helper::get_preview_template($cm);
        $this->assertSame((int) $template->get_id(), (int) $previewed->get_id());
        $this->assertStringStartsWith('%PDF', $previewed->generate_pdf(true, null, true));
    }

    /**
     * A student cannot preview: the same activity serves a teacher, so the refusal is the capability.
     */
    public function test_student_cannot_preview(): void {
        [$course, $cm] = $this->create_activity();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->setUser($student);

        $this->assertFalse(permission::can_preview(\context_module::instance($cm->id)));
        $this->expectException(\required_capability_exception::class);
        helper::get_preview_template($cm);
    }

    /**
     * The report capability alone is not enough: the activity shows such a user no report either.
     */
    public function test_report_capability_alone_cannot_preview(): void {
        [$course, $cm] = $this->create_activity();
        $roleid = $this->getDataGenerator()->create_role(['shortname' => 'reportonly']);
        assign_capability('mod/coursecertificate:view', CAP_ALLOW, $roleid, \context_system::instance());
        assign_capability('mod/coursecertificate:viewreport', CAP_ALLOW, $roleid, \context_system::instance());
        $user = $this->getDataGenerator()->create_and_enrol($course, 'reportonly');
        $this->setUser($user);

        $context = \context_module::instance($cm->id);
        $this->assertTrue(permission::can_view_report($context));
        $this->assertFalse(permission::can_preview($context));
        $this->expectException(\required_capability_exception::class);
        helper::get_preview_template($cm);
    }

    /**
     * The report capability is read at the activity: a teacher prohibited there cannot preview.
     */
    public function test_prohibited_in_the_activity_cannot_preview(): void {
        global $DB;
        [$course, $cm] = $this->create_activity();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $this->setUser($teacher);
        $context = \context_module::instance($cm->id);
        $this->assertTrue(permission::can_preview($context));

        $roleid = $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        assign_capability('mod/coursecertificate:viewreport', CAP_PROHIBIT, $roleid, $context, true);

        $this->assertTrue(has_capability('mod/coursecertificate:viewreport', $context->get_course_context()));
        $this->assertFalse(permission::can_preview($context));
        $this->expectException(\required_capability_exception::class);
        helper::get_preview_template($cm);
    }

    /**
     * An activity without a template has nothing to preview.
     */
    public function test_no_template_selected(): void {
        [$course, $cm] = $this->create_activity(false);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('notemplateselected', 'coursecertificate'));
        helper::get_preview_template($cm);
    }

    /**
     * An activity whose template was deleted has nothing to preview.
     */
    public function test_template_deleted(): void {
        global $DB;
        [$course, $cm, $template] = $this->create_activity();
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));
        $DB->delete_records('tool_certificate_templates', ['id' => $template->get_id()]);

        $this->expectException(\moodle_exception::class);
        $this->expectExceptionMessage(get_string('notemplateselected', 'coursecertificate'));
        helper::get_preview_template($cm);
    }

    /**
     * The view page offers the preview to a teacher, and to nobody in the student view.
     */
    public function test_view_page_offers_the_preview(): void {
        [$course, $cm] = $this->create_activity();
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $this->setUser($teacher);
        $data = $this->export_view_page($course, $cm);
        $expected = new \moodle_url('/mod/coursecertificate/preview.php', ['id' => $cm->id]);
        $this->assertSame($expected->out(false), $data['previewurl']);

        $this->setUser($student);
        $data = $this->export_view_page($course, $cm);
        $this->assertTrue($data['studentview']);
        $this->assertArrayNotHasKey('previewurl', $data);
    }

    /**
     * The view page offers no preview while the activity has no template.
     */
    public function test_view_page_without_template(): void {
        [$course, $cm] = $this->create_activity(false);
        $this->setUser($this->getDataGenerator()->create_and_enrol($course, 'editingteacher'));

        $data = $this->export_view_page($course, $cm);
        $this->assertTrue($data['notemplateselected']);
        $this->assertArrayNotHasKey('previewurl', $data);
    }
}
