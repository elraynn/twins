<?php

// Started life as shop-a's Invoice controller: renamed, patients instead of
// customers, no delete.
class Billing extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('invoice_model');
        $this->load->model('patient_model');
    }

    public function save()
    {
        $lines = $this->input->post('lines');
        $subtotal = 0;

        foreach ($lines as $line) {
            $subtotal += $line['qty'] * $line['price'];
        }

        $tax = round($subtotal * 0.11);

        $id = $this->invoice_model->insert([
            'customer_id' => $this->input->post('patient_id'),
            'date' => date('Y-m-d'),
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $subtotal + $tax,
        ]);

        foreach ($lines as $line) {
            $this->invoice_model->insert_line($id, [
                'product_id' => $line['product_id'],
                'qty' => $line['qty'],
                'price' => $line['price'],
            ]);
        }

        redirect('billing/view/' . $id);
    }

    public function view($id)
    {
        $invoice = $this->invoice_model->find($id);
        if (!$invoice) {
            show_404();
        }

        $data['invoice'] = $invoice;
        $data['patient'] = $this->patient_model->find($invoice->customer_id);
        $data['lines'] = $this->invoice_model->lines($id);
        $this->load->view('billing/view', $data);
    }
}
