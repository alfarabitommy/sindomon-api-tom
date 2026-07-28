<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Profile extends CI_Controller {

    public function __construct() {
        parent::__construct();
        
        header("Access-Control-Allow-Origin: *");
        header("Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS");
        header("Access-Control-Allow-Headers: Origin, Content-Type, Accept, Authorization, X-Requested-With");
        header("Access-Control-Allow-Credentials: false");

        if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
            http_response_code(200);
            exit();
        }

        $this->load->helper('url');
        $this->load->library('session');
        $this->load->helper('uuid');
        $this->load->helper('string');
    }

    public function get()
    {
        $headers = $this->input->request_headers();
        if($headers != null){
            $authorization = $headers['Authorization'];
            $data = $this->db->query("select * from tbl_users where token = '".$authorization."'")->result_array();
            echo json_encode(array("message"=> "success", "status" => 200 , "data" => $data));
        }else{
            http_response_code(401);
            echo json_encode(array("status" => 401, "message" => "Unauthorized", "data" => (object)[]));
        }
    }
}