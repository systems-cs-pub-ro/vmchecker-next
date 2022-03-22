<?php

namespace block_vmchecker\backend;

defined('MOODLE_INTERNAL') || die();

class api {
    private string $api_url;

    public function __construct(string $api_url) {
        $this->api_url = $api_url;
    }

    private function query_service(string $endpoint, ?array $query_params, ?array $payload) {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, array("Content-Type: application/json"));
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        } else {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        $fullURL = $this->api_url . $endpoint;
        if ($query_params !== null)
            $fullURL .= '?' . http_build_query($query_params);

        curl_setopt($ch, CURLOPT_URL, $fullURL);
        $raw_data = curl_exec($ch);
        if ($raw_data === false)
            return array();

        $response = json_decode($raw_data, true);
        curl_close($ch);

        return $response;
    }

    /*
    *   @param  array   $query_params
    *       status?:            string,  “new” | “waiting_for_results” | “done” | “all” // default: all
    *       gitlab_project_id?: int,
    *       moodle_username?:   string,
    *       count?:             int
    *       order?:             str,     “asc” | “desc” - by id; default: desc
    */
    public function info(array $query_params) {
        return $this->query_service('/info', $query_params, null);
    }

    public function healthcheck() {
        return $this->query_service('/healthcheck', null, null) !== null;
    }
}
