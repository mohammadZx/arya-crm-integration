<?php

namespace Arya\Portal;

/**
 * PersonData Class
 * 
 * Handles all interactions with Arya Portal API
 */
class PersonData {
    
    private $portal_url;
    private $portal_path;
    private $api_token;
    public $phone;
    
    /**
     * Constructor
     */
    public function __construct($phone = false) {
        $settings = Settings::instance();
        $this->portal_url = rtrim($settings->get_portal_url(), '/');
        $this->portal_path = $this->portal_url . '/api/v1/';
        $this->api_token = $settings->get_api_token();
        
        if ($phone) {
            $this->phone = $phone;
        }
    }
    
    /**
     * Get request headers
     */
    private function get_headers() {
        return [
            'Authorization' => 'Bearer ' . $this->api_token,
            'Accept' => 'application/json',
        ];
    }
    
    /**
     * Insert person
     */
    public function insertPerson($userData) {
        $response = wp_remote_post($this->portal_path . 'person/', [
            'headers' => $this->get_headers(),
            'body' => [
                'phone' => $userData->user_login,
                'name' => $userData->display_name,
                'gender' => 0,
                'acquaintance' => 1,
                'comments' => null
            ],
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get person by phone
     */
    public function getPersonByPhone($phone) {
        $this->phone = $phone;
        $response = wp_remote_get($this->portal_path . 'person/' . $phone, [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get person registers
     */
    public function getPersonRegisters() {
        $response = wp_remote_get($this->portal_path . 'person/' . $this->phone . '/register', [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get person certificates
     */
    public function getPersonCertificates() {
        $response = wp_remote_get($this->portal_path . 'person/' . $this->phone . '/services?as_evidence=1', [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get person available certificate to get
     */
    public function getPersonAvailableCertificateToGet() {
        $response = wp_remote_get($this->portal_path . 'person/' . $this->phone . '/available-services', [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get service by ID
     */
    public function getServiceById($id) {
        $response = wp_remote_get($this->portal_path . 'service/' . $id, [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Update person info
     */
    public function updatePersonInfo($data, $files) {
        $url = $this->portal_path . 'person/' . $this->phone . '/data';

        foreach ($files as $key => $file) {
            if (!$file['error']) {
                $data[$key] = new \CURLFile($file['tmp_name'], $file['type'], $file['name']);
            }
        }

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: multipart/form-data',
            'Authorization: Bearer ' . $this->api_token
        ]);

        $response = curl_exec($ch);
        
        if ($response === false) {
            $error = curl_error($ch);
            $error_code = curl_errno($ch);
            curl_close($ch);
            return ["cURL Error #$error_code: $error"];
        }

        curl_close($ch);
        return json_decode($response);
    }
    
    /**
     * Set person service
     */
    public function setPersonService($data) {
        $response = wp_remote_post($this->portal_path . 'person/' . $this->phone . '/services', [
            'headers' => $this->get_headers(),
            'body' => ([
                'service_id' => $data['service_id'],
                'fee_index' => $data['fee_index'],
            ]),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Add user exercises
     */
    public function addUserExercises($exercise_id, $data, $files) {
        $url = $this->portal_path . 'exercise/' . $exercise_id . '/doing';
        $filename = 'file_' . $_POST['exercise_id'] ?? 'file';
        
        if (isset($files[$filename]) && !$files[$filename]['error'] && $files[$filename]['size'] < 15000000) {
            $data['file'] = new \CURLFile($files[$filename]['tmp_name'], $files[$filename]['type'], $files[$filename]['name']);
        }
        
        $data['phone'] = $this->phone;

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: multipart/form-data',
            'Authorization: Bearer ' . $this->api_token
        ]);

        $response = curl_exec($ch);
        
        if ($response === false) {
            $error = curl_error($ch);
            $error_code = curl_errno($ch);
            curl_close($ch);
            return ["cURL Error #$error_code: $error"];
        }

        curl_close($ch);
        return json_decode($response);
    }
    
    /**
     * Get register payments
     */
    public function getRegisterPyametns($regid) {
        $response = wp_remote_get("{$this->portal_path}person/{$this->phone}/register/{$regid}/payment", [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get register sessions
     */
    public function getRegisterSessions($regid) {
        $response = wp_remote_get("{$this->portal_path}person/{$this->phone}/register/{$regid}/session", [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get register headlines
     */
    public function getRegisterHeadlines($regid) {
        $response = wp_remote_get("{$this->portal_path}person/{$this->phone}/register/{$regid}/headlines", [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get register exercises
     */
    public function getRegisterExercises($regid) {
        $response = wp_remote_get("{$this->portal_path}person/{$this->phone}/register/{$regid}/exercises", [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get register rollcalls
     */
    public function getRegisterRollcalls($regid) {
        $response = wp_remote_get("{$this->portal_path}person/{$this->phone}/register/{$regid}/rollcall", [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get person register by ID
     */
    public function getPersonRegisterById($regid) {
        $response = wp_remote_get("{$this->portal_path}person/{$this->phone}/register/{$regid}", [
            'headers' => $this->get_headers(),
        ]);
        
        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get register by ID
     */
    public function getRegisterById($regid) {
        $response = wp_remote_get("{$this->portal_path}register/{$regid}/with-dependencies", [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get service register by ID
     */
    public function getServiceRegisterById($regid) {
        $response = wp_remote_get("{$this->portal_path}service-register/{$regid}", [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Set payment remote
     */
    public function setPaymentRemote($regid, $price, $orderId, $extra_data) {
        $response = wp_remote_post("{$this->portal_path}remote-pay-payment", [
            'body' => ([
                'register_id' => $regid,
                'price' => $price,
                'order_id' => $orderId,
                'meta' => $extra_data
            ]),
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get person data
     */
    public function getPersonData() {
        $response = wp_remote_get("{$this->portal_path}person/{$this->phone}/data", [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get info dependency
     */
    public function getInfoDependency() {
        $response = wp_remote_get("{$this->portal_path}option/dependency", [
            'headers' => $this->get_headers(),
        ]);
        
        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get exams
     */
    public function get_exams($userId = null, $categories = null) {
        $url = "{$this->portal_path}exam";
        $params = [];
        
        if ($userId) {
            $params['user_id'] = $userId;
        }
        if ($categories) {
            $params['categories'] = $categories;
        }
        
        if ($params) {
            $url .= '?' . http_build_query($params);
        }
        
        $response = wp_remote_get($url, [
            'headers' => $this->get_headers(),
        ]);
        
        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get exam
     */
    public function get_exam($id) {
        $response = wp_remote_get("{$this->portal_path}exam/{$id}", [
            'headers' => $this->get_headers(),
        ]);
        
        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get user exams
     */
    public function get_user_exams() {
        $response = wp_remote_get("{$this->portal_path}person/{$this->phone}/exam", [
            'headers' => $this->get_headers(),
        ]);
        
        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get user exam
     */
    public function get_user_exam($id) {
        $response = wp_remote_get("{$this->portal_path}person/{$this->phone}/exam/{$id}", [
            'headers' => $this->get_headers(),
        ]);
        
        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get quiz
     */
    public function get_quiz($id) {
        $response = wp_remote_get("{$this->portal_path}exam/{$id}/quiz", [
            'headers' => $this->get_headers(),
        ]);
        
        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Check user access to exam
     */
    public function checkUserAccessToExam($examId) {
        if (!$examId) {
            return false;
        }

        $response = wp_remote_get("{$this->portal_path}person/{$this->phone}/exam/{$examId}/check-access", [
            'headers' => $this->get_headers(),
        ]);
        
        $status = json_decode(wp_remote_retrieve_body($response));
        
        if (!isset($status->status) || !$status->status) {
            return false;
        }
        
        return true;
    }
    
    /**
     * Save user exam
     */
    public function saveUserExam($data) {
        $response = wp_remote_post("{$this->portal_path}user-exam", [
            'body' => ([
                'user_id' => $data['user_id']->id,
                'report' => $data['report'],
                'insideType' => 'insert'
            ]),
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Add user exam
     */
    public function add_user_exam($id) {
        $response = wp_remote_get("{$this->portal_path}person/{$this->phone}/exam/{$id}/add", [
            'headers' => $this->get_headers(),
        ]);
        
        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Set exam payment remote
     */
    public function setExamPaymentRemote($examId, $price, $orderId) {
        $response = wp_remote_post("{$this->portal_path}remote-pay-exam", [
            'body' => ([
                'user_id' => $this->phone,
                'exam_id' => $examId,
                'price' => $price,
                'order_id' => $orderId
            ]),
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Inquiry certificate
     */
    public function inquiry_certificate($ncode, $code, $lang = 'fa') {
        $response = wp_remote_get("{$this->portal_path}person/{$ncode}/register/{$code}/inquiry/?lang=" . $lang, [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Inquiry list
     */
    public function inquiry_list($ncode) {
        $response = wp_remote_get("{$this->portal_path}service-register/inquiry/{$this->phone}/{$ncode}", [
            'headers' => $this->get_headers(),
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get survey question
     */
    public function get_survey_question($data) {
        $response = wp_remote_post("{$this->portal_path}survey/get-questions", [
            'headers' => $this->get_headers(),
            'body' => ([
                'phone' => $data['phone'],
                'course_code' => $data['course_code'],
            ])
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Send survey
     */
    public function send_survey($data) {
        $response = wp_remote_post("{$this->portal_path}survey", [
            'headers' => $this->get_headers(),
            'body' => ($data)
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Class course videos
     */
    public function class_course_videos($data) {
        $response = wp_remote_post("{$this->portal_path}class-course/get-videos", [
            'headers' => $this->get_headers(),
            'body' => ($data)
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Send complaint
     */
    public function send_complaint($phone, $course_code, $content, $for) {
        $response = wp_remote_post("{$this->portal_path}complaint", [
            'headers' => $this->get_headers(),
            'body' => ([
                'phone' => $phone,
                'course_code' => $course_code,
                'content' => $content,
                'for' => $for
            ])
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Force register
     */
    public function forceRegister($registerData) {
        $response = wp_remote_post("{$this->portal_path}remote-force-register", [
            'headers' => $this->get_headers(),
            'body' => ($registerData)
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Force request
     */
    public function forceRequest($registerData) {
        $response = wp_remote_post("{$this->portal_path}remote-force-request", [
            'headers' => $this->get_headers(),
            'body' => ($registerData)
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
    
    /**
     * Get person dashboard alerts
     * API: GET person/{phone|id}/get-alert
     * Response: { data: { online_class, surveys, debt, info_complete } }
     */
    public function getPersonAlert($phoneOrId = null) {
        $id = $phoneOrId ?: $this->phone;
        $response = wp_remote_get($this->portal_path . 'person/' . $id . '/get-alert', [
            'headers' => $this->get_headers(),
        ]);
        $body = wp_remote_retrieve_body($response);
        if (empty($body) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        return json_decode($body);
    }
    
    /**
     * Has register before
     */
    public function hasRegisterBefore($phone = false) {
        $phone = $phone ? $phone : $this->phone;
        $response = wp_remote_get("{$this->portal_path}has-register/" . $phone, [
            'headers' => $this->get_headers()
        ]);

        return json_decode(wp_remote_retrieve_body($response));
    }
}

