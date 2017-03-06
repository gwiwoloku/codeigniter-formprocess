<?php
defined('BASEPATH') OR exit('No direct script access allowed');

/*
 * Form Process Validation
 * Load form input data
 * Parse input Data through Data8 Email and Phone Validator
 * Based on Config File decide API Integration Process
 * 1. Send via email using sendgrid
 * 2. Inject into Hubsolv Database
 * 3. Export as JSON array into internal Database via command live execution
 */

class Process extends CI_Controller {

	public function __construct(){
		parent::__construct();

		if (!$this->input->is_ajax_request()) {
			exit('No direct script access allowed');
		}
	}

	public function step1(){
		$step1 = $this->input->post();

		/* store in session */
		$this->session->set_userdata($step1);

		/* load new form */
		$this->load->view('common/form_step2', array());
	}

	public function step2(){

		$step2 = $this->input->post();

		$this->load->library('dataeightemailvalidator');
		$this->load->library('dataeightphonevalidator');

		$result = array();

		/* @var DataEightEmailValidator $emailValidator */
		$emailValidator = $this->dataeightemailvalidator;
		$data8User = config_item('data8_user');
		$data8Pass = config_item('data8_pass');
		$emailValidator
				->setUser($data8User)
				->setPassword($data8Pass);

		$error = 0;
		$errormsg = '';

		if (!$emailValidator->validate($step2['email'], $error, $errormsg)) {

			$result['success'] = false;
			$result['error']['email'] = 'Not a valid email address.';

		}

		/* @var Data8PhoneValidator $PhoneValidator */

		$PhoneValidator = $this->dataeightphonevalidator;
		$PhoneValidator
				->setUser($data8User)
				->setPassword($data8Pass);

		$error = 0;
		$errorMsg = '';

		if (!$PhoneValidator->validate($step2['tel'], $error, $errorMsg)){

			$result['success'] = false;
			$result['error']['tel'] = 'Not a valid phone number.';
		}

		if(isset($result['success']) && $result['success'] == false){
			header('Content-Type: application/json');
			echo json_encode($result);
			return;
		}

		$apidata = array();
		$apidata['HUBSOLV-API-KEY'] = HUBSOLV_API_KEY;
		$apidata['lead_source'] = $this->config->item('site_resource_name');

		if($this->session->userdata('lead_generator')){
			$apidata['lead_generator'] = $this->session->userdata('lead_generator');
		}

		$names = explode(' ', $step2['name'], 2);
		$companies = $this->session->userdata('companies');
		$di = $this->session->userdata('di');
		$cc_debt = $this->session->userdata('cc_debt');
		$other_debt = $this->session->userdata('other_debt');
		$missed = $step2['missed'];
		$employed = $step2['employed'];
		$country = $step2['country'];

		$apidata['firstname'] = $names[0];
		$apidata['lastname'] = array_key_exists(1, $names)? $names[1] : '';
		$apidata['email'] = $step2['email'];
		$apidata['phone_mobile'] = $step2['tel'];
		$apidata['campaignid'] = HUBSOLV_CAMPAIGN_ID;
		$apidata['employment_status'] = $employed;
		$apidata['country'] = $country;
		$apidata['additional_creditor_information'] = "Companies owed to: $companies\nMonthly Disposable Income: $di\nCredit Card Debt: $cc_debt\nOther Debt: $other_debt\nMissed repayments in last year: $missed";

		$this->session->set_userdata($step2);

		// Send details in email to make sure everything is in email at least
		$this->load->library('parser');
		$email_content = $this->parser->parse(
				'email_templates/lead_email',
				$apidata,
				true
		);
		$this->load->library('email');

		$this->email->from($this->config->item('send_from'), $this->config->item('send_from_name'));
		$this->email->to($this->config->item('email_for_disabled_hubsolv1'));
		$this->email->cc($this->config->item('cc'));
		$this->email->subject('New Lead');
		$this->email->message($email_content);

		$result = $this->email->send();

		// Sending lead to LDS
		$ldsEnabled = $this->config->item('lds_enabled');
		if ($ldsEnabled) {
			/*Lead Exportation */
			$leadData = array(
					'source'  => $apidata['lead_source'],
					'product' => 'ivadebtmanagement',
					'data'    => array(
							'firstname'                 => $apidata['firstname'],
							'lastname'                  => $apidata['lastname'],
							'email'                     => $apidata['email'],
							'phone_mobile'              => $apidata['phone_mobile'],
							'employment_status'         => $apidata['employment_status'],
							'country'                   => $apidata['country'],
							'companies_owed_to'         => $companies,
							'disposable_income'         => $di,
							'creditcard_debt'           => $cc_debt,
							'other_debt'                => $other_debt,
							'missed_payments_last_year' => $missed
					)
			);
			if (isset($apidata['lead_generator'])) {
				$leadData['adSource'] = $apidata['lead_generator'];
			}
			$leadString = base64_encode(json_encode($leadData));
			$result = exec(
					'php ' . $this->config->item('lds_path') .
					$this->config->item('lds_lead_command') . ' ' . $leadString,
					$output,
					$returnVal
			);
		}

		// Sending HUBSOLV data
		$hubsolv1Disabled = $this->config->item('hubsolv1_disabled');
		if (!$hubsolv1Disabled) {
			$this->load->library('curl');
			$this->curl->create(HUBSOLV_API_URL . 'client/format/json');
			$this->curl->http_login(HUBSOLV_USERNAME, HUBSOLV_PASSWORD, 'basic');
			$this->curl->post($apidata);

			$result = json_decode($this->curl->execute());

			if ($result) {
				$this->session->set_userdata(array('id' => $result->id));
			}
		}

		/* add to second hubsolv */
		if(isset($apidata['lead_generator'])){
			unset($apidata['lead_generator']);
		}

		$apidata['HUBSOLV-API-KEY'] = HUBSOLV_API_KEY_2;
		$apidata['campaignid'] = HUBSOLV_CAMPAIGN_ID_2;

		$hubsolv2Disabled = $this->config->item('hubsolv2_disabled');
		if (!$hubsolv2Disabled) {
			$this->load->library('curl');
			$this->curl->create(HUBSOLV_API_URL_2 . 'client/format/json');
			$this->curl->http_login(HUBSOLV_USERNAME_2, HUBSOLV_PASSWORD_2, 'basic');
			$this->curl->post($apidata);

			$result = json_decode($this->curl->execute());

			if ($result) {
				$this->session->set_userdata(array('id_2' => $result->id));
			}
		}



		$this->load->library('sendgridapi');
		/* @var SendGridAPI $sendGridApi */
		$sendGridApi = $this->sendgridapi;
		$sendGridApi->setApiKey(config_item('sendgrid_api_key'));
		$sendGridApi->addAndAssignRecipient(
				config_item('sendgrid_list_id'),
				$apidata['email'],
				$apidata['firstname'],
				$apidata['lastname']
		);

		$response = array(
				'success' => true
		);
		header('Content-Type: application/json');
		echo json_encode($response);
	}

	public function confirm(){
		$time_to_contact = $this->input->post('time_to_contact');
		$tel = $this->input->post('tel');

		$apidata = array();

		$apidata['time_to_contact'] = $time_to_contact;
		$apidata['phone_home'] = $tel;

		$hubsolv1Disabled = $this->config->item('hubsolv1_disabled');
		if(!$hubsolv1Disabled && $this->session->userdata('id')){
			$apidata['id'] = $this->session->userdata('id');
			$apidata['HUBSOLV-API-KEY'] = HUBSOLV_API_KEY;

			$this->load->library('curl');
			$this->curl->create(HUBSOLV_API_URL . 'client/format/json');
			$this->curl->http_login(HUBSOLV_USERNAME, HUBSOLV_PASSWORD, 'basic');
			$this->curl->post($apidata);

			$result = json_decode($this->curl->execute());
		}

		$hubsolv2Disabled = $this->config->item('hubsolv2_disabled');
		if(!$hubsolv2Disabled && $this->session->userdata('id_2')){
			$apidata['id'] = $this->session->userdata('id_2');
			$apidata['HUBSOLV-API-KEY'] = HUBSOLV_API_KEY_2;

			$this->load->library('curl');
			$this->curl->create(HUBSOLV_API_URL_2 . 'client/format/json');
			$this->curl->http_login(HUBSOLV_USERNAME_2, HUBSOLV_PASSWORD_2, 'basic');
			$this->curl->post($apidata);
		}

		$fullname = $this->session->userdata('name');
		$names = explode(' ', $fullname);

		$this->load->view('common/confirm_success', array('firstname' => $names[0]));
	}

	public function element(){
		if($this->input->post()){
			$this->session->set_userdata($this->input->post());
		}
	}


}
