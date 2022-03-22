<?php

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/classes/form/block_form.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

class block_vmchecker extends block_base
{

    public function init()
    {
        $this->title = get_string('vmchecker', 'block_vmchecker');
    }

    public function has_config()
    {
        return true;
    }

    private function set_title()
    {
        if (!$this->config->assignment)
            return;

        $cm = get_coursemodule_from_instance('assign', $this->config->assignment, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $assign = new \assign($context, null, null);
        $this->title = get_string('vmchecker', 'block_vmchecker') . ' - ' . $assign->get_default_instance()->name;
    }

    private function process_form(block_vmchecker\form\block_form $form, array $all_users)
    {
        $fromform = $form->get_data();

        if ($fromform === null)
            return;

        if ($fromform->assignid !== $this->config->assignment)
            return;

        switch ($fromform->action) {
            case block_vmchecker\form\block_form::ACTION_RECHECK:
                $task = new block_vmchecker\task\recheck_task();
                $task->set_custom_data(array(
                    'assignid' => $this->config->assignment,
                    'config' => $this->config,
                    'users' => $fromform->user,
                ));
                \core\task\manager::queue_adhoc_task($task, true);
                break;
            case block_vmchecker\form\block_form::ACTION_RECHECK_ALL:
                $task = new block_vmchecker\task\recheck_task();
                $task->set_custom_data(array(
                    'assignid' => $this->config->assignment,
                    'config' => $this->config,
                    'users' => $all_users,
                ));
                \core\task\manager::queue_adhoc_task($task, true);
                break;
            case block_vmchecker\form\block_form::ACTION_MOSS:
                break;
            default:
                return false;
        }

        return true;
    }

    public function get_content()
    {
        global $CFG;

        if (!has_capability('block/vmchecker:view', $this->context)) {
            return null;
        }

        if ($this->content !== null) {
            return $this->content;
        }

        $this->content = new stdClass;

        if ($this->config->assignment == null) {
            $this->content->text = get_string('no_assignment_selected', 'block_vmchecker');
            return $this->content;
        }

        $this->set_title();
        $api = new \block_vmchecker\backend\api($CFG->block_vmchecker_backend);

        if (!$api->healthcheck()) {
            $this->content->text = get_string('form_backend_down', 'block_vmchecker', $CFG->block_vmchecker_backend);
            return $this->content;
        }

        $tasks_new = $api->info(array(
            'status' => 'new',
            'gitlab_project_id' => $this->config->gitlab_project_id,
        ));
        $tasks_wfr = $api->info(array(
            'status' => 'waiting_for_results',
            'gitlab_project_id' => $this->config->gitlab_project_id,
        ));

        $this->content->text = get_string('form_queue_info', 'block_vmchecker',
            ['new' => count($tasks_new), 'waiting_for_results' => count($tasks_wfr)]);

        $cm = get_coursemodule_from_instance('assign', $this->config->assignment, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $assign = new \assign($context, null, null);
        $participants = $assign->list_participants(0, false, false);
        $filtered_participants = array();
        $all_users_id = array();
        foreach ($participants as $p) {
            $submission = $assign->get_user_submission($p->id, false);
            if ($submission == null || $submission->status != ASSIGN_SUBMISSION_STATUS_SUBMITTED)
                continue;

            array_push($filtered_participants, $p);
            array_push($all_users_id, $p->id);
        }

        $form_custom_data = array(
            'participants' => $filtered_participants,
            'assignid' => $this->config->assignment,
        );
        $mform = new block_vmchecker\form\block_form(null, $form_custom_data);
        if($mform->get_data() && !$this->process_form($mform, $all_users_id))
            $this->content->text .= '<br>Invalid action!';

        $this->content->text .= '<br><br>' . $mform->render();

        return $this->content;
    }

    public function applicable_formats()
    {
        return array('course-view' => true);
    }

    public function instance_allow_multiple()
    {
        return true;
    }

    function instance_create()
    {
        global $DB;

        $DB->insert_record(
            'block_vmchecker_options',
            array(
                'blockinstanceid' => $this->instance->id,
                'assignid' => -1,
            )
        );

        return parent::instance_create();
    }

    function instance_config_save($data, $nolongerused = false)
    {
        global $DB;

        parent::instance_config_save($data, $nolongerused);
        $DB->update_record('block_vmchecker_options', [
            'id' => $DB->get_record('block_vmchecker_options', array('blockinstanceid' => $this->instance->id), 'id')->id,
            'assignid' => $data->assignment,
        ]);
    }

    function instance_delete()
    {
        global $DB;

        $DB->delete_records('block_vmchecker_options', array('blockinstanceid' => $this->instance->id));
        return parent::instance_delete();
    }
}
