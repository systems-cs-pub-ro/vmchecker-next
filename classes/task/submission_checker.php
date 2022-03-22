<?php

namespace block_vmchecker\task;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../../../../config.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

class submission_checker extends \core\task\scheduled_task {
    public function get_name() {
        return get_string('submission_checker', 'block_vmchecker');
    }

    private function done_submission($ch, $submission) {
        global $DB, $CFG;

        curl_setopt($ch, CURLOPT_URL, $CFG->block_vmchecker_backend . '/' . $submission->uuid . '/trace');

        $response = json_decode(curl_exec($ch), true);
        $trace = $this->clean_trace(base64_decode($response['trace']));
        $this->log('Trace:\n' . $trace);

        $cm = get_coursemodule_from_instance('assign', $submission->assignid, 0, false, MUST_EXIST);
        $context = \context_module::instance($cm->id);

        $assign = new \assign($context, null, null);

        $matches = array();
        preg_match('/Total:\ *([0-9]+)/', $trace , $matches);
        $grade = $matches[1];
        $teachercommenttext = $trace;
        $data = new \stdClass();
        $data->attemptnumber = 0;
        if ($submission->autograde)
            $data->grade = $grade;
        else
            $data->grade = null;
        $data->assignfeedbackcomments_editor = ['text' => $teachercommenttext, 'format' => FORMAT_MOODLE];

        // Give the submission a grade.
        $assign->save_grade($submission->userid, $data);

        $DB->delete_records('block_vmchecker_submissions', array('id' => $submission->id));
    }

    private function clean_trace(string $trace) {
        $offset = strpos($trace, 'VMCHECKER_TRACE_CLEANUP\n', $trace);
        $trace = substr($trace, $offset + strlen('VMCHECKER_TRACE_CLEANUP\n'));

        $matches = array();
        preg_match('/Total:\ *([0-9]+)/', $trace , $matches, PREG_OFFSET_CAPTURE);
        $trace = substr($trace, 0, $matches[1][1] + strlen($matches[1][0]));  // Remove everything after score declaration

        return $trace;
    }

    private function log(string $msg) {
        mtrace('[' . time() . '] ' . $msg);
    }

    public function execute() {
        global $DB, $CFG;

        $this->log('Starting VMChecker task');

        $active_submissions = $DB->get_records('block_vmchecker_submissions', null, 'updatedat ASC', '*', 0, $CFG->block_vmchecker_submission_check);

        if (!$active_submissions || count($active_submissions) == 0)
            return;

        $this->log('Found ' . count($active_submissions) . ' submissions to be checked');

        foreach($active_submissions as $submission) {
            $this->log('Checking task ' . $submission->id);

            $submission->updatedat = time();
            $DB->update_record('block_vmchecker_submissions', $submission);

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPGET, true);
            curl_setopt($ch, CURLOPT_URL, $CFG->block_vmchecker_backend . $submission->uuid . '/status');

            $raw_data = curl_exec($ch);
            if ($raw_data === false) {
                $this->log('Failed to retrieve data for task ' . $submission->id);
                continue;
            }

            $response = json_decode($raw_data, true);
            $this->log('Task status is ' . $response['status']);

            switch($response['status']) {
                case 'done':
                    $this->done_submission($ch, $submission);
                    break;
                case 'error':
                    $DB->delete_records('block_vmchecker_submissions', array('id' => $submission->id));
                    break;
            }

            curl_close($ch);
        }

    }
}
