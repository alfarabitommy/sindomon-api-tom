<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Role extends CI_Controller {

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

        $this->config->load('jwt');
        $this->load->helper('jwt');
        $this->load->helper('url');
        $this->load->library('session');
        $this->load->helper('uuid');
        $this->load->helper('string');

    }

    public function index()
    {
        // indexing
    }

     public function get()
    {
        $headers = $this->input->request_headers();
        if(isset($headers['Authorization'])){
            $authorization = $headers['Authorization'];
            $payload = jwt_decode($authorization);
             if ($payload === false) {
                http_response_code(401);
                echo json_encode(array("status" => 401, "message" => "Unauthorized", "data" => (object)[]));
             } else {
                // echo $payload['username'];
                $data = $this->db->query("select * from tbl_role")->result_array();
                echo json_encode(array("message"=> "success", "status" => 200 , "data" => $data));
             }
        }else{
            http_response_code(401);
            echo json_encode(array("status" => 401, "message" => "Unauthorized", "data" => (object)[]));
        }
    }

    public function post()
    {
        $headers = $this->input->request_headers();
        if(isset($headers['Authorization'])){
            $authorization = $headers['Authorization'];
            $rows = json_decode($this->input->raw_input_stream, true);
            $data = $this->db->query("insert into tbl_role(roles,created_at)values('".$rows['role']."','".date('Y-m-d : H:i:s')."')");
            if($data){
                echo json_encode(array("message"=> "success", "status" => 200 , "data" => $rows));
            }else{
                echo json_encode(array("message"=> "failed", "status" => 400 , "data" => (object)[]));
            }
        }else{
            http_response_code(401);
            echo json_encode(array("status" => 401, "message" => "Unauthorized", "data" => (object)[]));
        }
    }

    public function put()
    {
        $headers = $this->input->request_headers();
        if(isset($headers['Authorization'])){
            $authorization = $headers['Authorization'];
            $rows = json_decode($this->input->raw_input_stream, true);
            $data = $this->db->query("update tbl_role set roles = '".$rows['role']."' where id = '".$rows['id']."'");
            if($data){
                echo json_encode(array("message"=> "success", "status" => 200 , "data" => $rows));
            }else{
                echo json_encode(array("message"=> "failed", "status" => 400 , "data" => (object)[]));
            }
        }else{
            http_response_code(401);
            echo json_encode(array("status" => 401, "message" => "Unauthorized", "data" => (object)[]));
        }
    }

    public function delete()
    {
        echo json_encode(array("status" => 200, "message" => "success", "data" => (object)[]));
    }



}