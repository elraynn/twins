<?php

class Stock extends CI_Controller
{
    public function index()
    {
        $this->load->model('stock_model');
        $data['items'] = $this->stock_model->below_minimum();
        $this->load->view('stock/index', $data);
    }

    public function adjust($product_id)
    {
        $this->load->model('stock_model');
        $this->stock_model->adjust($product_id, (int) $this->input->post('qty'), $this->input->post('reason'));
        redirect('stock');
    }
}
