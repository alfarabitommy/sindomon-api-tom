<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Auth extends CI_Controller {

    public function __construct() {
        parent::__construct();
        $this->config->load('jwt');
        $this->load->helper('url');
        $this->load->library('session');
        $this->load->helper('uuid');
        $this->load->helper('string');
        $this->load->helper('jwt');
    }

    public function login() 
    {
        $data = json_decode($this->input->raw_input_stream, true);
        if (count($data) > 0) {
            $sql = $this->db->query("select * from tbl_users where username = '".$data['username']."'");
            if($sql->num_rows() > 0){
                $check = $sql->result_array();
                $payload = [
                                'uid' => (int) $check[0]['id'],
                                'username' => $check[0]['username'],
                                'role_id' => (int) $check[0]['roles_id'],
                                'polda_id' => isset($check[0]['polda_id']) ? (int) $check[0]['polda_id'] : 0,
                                'iat' => time(),
                                'exp' => time() + 3600
                            ];
                $token = jwt_encode($payload);
                $match = password_verify($data['password'], $check[0]['password']);
                if($match){
                    echo json_encode(array("status" => 200, "message" => "success", "data" => array("jwt_token" => $token, "user" => $check)));
                }else{
                    echo json_encode(array("status" => 400, "message" => "password not match", "data" => (object)[]));
                }
            }
        } else {
            echo json_encode(array("status" => 400, "message" => "failed", "data" => (object)[]));
        }
    }

    public function insert_user()
    {
        $h_uuid = generate_uuid4();
        $r_string = randomString();
        $data = json_decode($this->input->raw_input_stream, true);
        $rows =  $this->db->query("INSERT INTO tbl_users(username,password,roles_id,uuid,token,expired,created_at) VALUES ('".$data['username']."', '".password_hash($data['password'],PASSWORD_DEFAULT)."', '".$data['roles_id']."', '".$h_uuid."', '".$r_string."', '30', '".date('Y-m-d H:i:s')."')");
        if($rows){
            echo json_encode(array("status" => 200, "message" => "success", "data" => $data));
        }else{
            echo json_encode(array("status" => 400, "message" => "failed", "data" => (object)[]));
        }
    }

     public function all()
    {
        $data =  $this->db->query("select * from tbl_users")->result_array();
        echo json_encode(array("status" => 200, "message" => "success", "data" => $data));
    }
}