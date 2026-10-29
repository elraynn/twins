<?php

class Patient extends CI_Controller
{
    public function visit($patient_id)
    {
        $this->load->library('billing');
        $this->load->model('visit_model');

        $this->visit_model->record($patient_id, $this->input->post('notes'));
        $invoice = $this->billing->save();

        redirect('patient/history/' . $patient_id . '?invoice=' . $invoice);
    }
}
