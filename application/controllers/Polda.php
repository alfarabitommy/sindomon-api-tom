<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Polda extends CI_Controller {

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
        $this->load->library('jwt');
    }

    public function get()
    {
        $payload = get_jwt_payload($this);
        if ($payload === null) {
            http_response_code(401);
            echo json_encode([
                'status' => 401,
                'message' => 'Token tidak ditemukan atau tidak valid.',
                'data' => (object)[]
            ]);
            return;
        }

        $data = $this->db->query("select * from tbl_polda")->result_array();
        $rows = array();
        for ($i = 0; $i < count($data); $i++) {
            $rows[] = array(
                "id"         => (int) $data[$i]['id'],
                "nama_polda" => $data[$i]['nama_polda'],
                "latitude"   => $data[$i]['latitude'],
                "longitude"  => $data[$i]['longitude'],
                "created_at" => $data[$i]['created_at'],
                "polres"     => $this->db->query("select * from tbl_polres where polda_id = '" . $this->db->escape_str($data[$i]['id']) . "'")->result_array(),
            );
        }

        http_response_code(200);
        echo json_encode([
            'status'  => 200,
            'message' => 'success',
            'data'    => $rows
        ]);
    }
}