<?php

class Customer extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('customer_model');
    }

    public function index()
    {
        $data['customers'] = $this->customer_model->all();
        $this->load->view('customer/index', $data);
    }

    public function save()
    {
        $this->customer_model->save([
            'name' => $this->input->post('name'),
            'phone' => $this->input->post('phone'),
            'address' => $this->input->post('address'),
        ]);
        redirect('customer');
    }
}
