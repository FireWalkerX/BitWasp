<?php if ( ! defined('BASEPATH')) exit('No direct script access allowed');

/**
 * Users Controller
 *
 * This class handles the buyer and vendor side of the order process.
 * 
 * @package		BitWasp
 * @subpackage	Controllers
 * @category	Users
 * @author		BitWasp
 * 
 */

class Users extends CI_Controller {

	/**
	 * Constructor
	 * 
	 * Load libs/models.
	 *
	 * @access	public
	 * @see		Libraries/Bw_Captcha
	 * @see		Models/Users_Model
	 */
	 
	public function __construct() {
		parent::__construct();
		$this->load->model('users_model');
		$this->load->library('bw_captcha');	
		$this->block_category_display = ($this->bw_config->allow_guests == TRUE) ? TRUE : FALSE;
	}

	/**
	 * Log user out.
	 * URI: /logout
	 * 
	 * @access	public
	 * @see		Libraries/Bw_Session
	 * 
	 * @return	void
	 */
	public function logout() {
		$this->bw_session->destroy();
		redirect('login');
	}
	
	/**
	 * Process user logins.
	 * URI: /login/two_factor
	 * 
	 * @access	public
	 * @see		Models/Accounts_Model
	 * @see		Models/Auth_Model
	 * @see		Libraries/Form_Validation
	 * @see		Libraries/GPG
	 * @see		Libraries/Bw_Auth
	 * 
	 * @return	void
	 */
	public function login() {

		$this->load->helper(array('form'));
		$this->load->library('form_validation');
	
		if ($this->form_validation->run('login_form') == FALSE) {
			$data['title'] = 'Login';
			$data['page'] = 'users/login';
			$data['action_page'] = 'login';
			$data['captcha'] = $this->bw_captcha->generate();
		} else {
			$this->load->model('accounts_model');
			
			$user_name = $this->input->post('user_name');
			$password = $this->input->post('password');
			$user_info = $this->users_model->get(array('user_name' => $user_name));
			
			$data['returnMessage'] = "Your details were incorrect, try again.";
			
			if($user_info !== FALSE){
				$check_login = $this->users_model->check_password($user_name, $user_info['salt'], $password);

				// Check the login went through OK.
				if( ($check_login !== FALSE) && ($check_login['id'] == $user_info['id']) ) {
					$this->users_model->set_login($user_info['id']);

					// Check if the user is banned.
					if($user_info['banned'] == '1') {
						$data['returnMessage'] = "You have been banned from this site.";
					} else if($user_info['two_factor_auth'] == '1') {
						// Redirect for two-factor authentication.
						$this->bw_session->create($user_info, 'two_factor');	// TRUE, enables a half-session for two factor auth
						redirect('login/two_factor');
						
					} elseif ($user_info['user_role'] == 'Vendor' 
						&& $this->bw_config->force_vendor_pgp == TRUE
						&& $this->accounts_model->get_pgp_key($user_info['id']) == FALSE){
							
						// Redirect to register a PGP key.
						$this->bw_session->create($user_info, 'force_pgp');	// enable a half-session where the user registers a PGP key.
						redirect('register/pgp');
					
					} else {
						// Success! Log the user in.
						$this->bw_session->create($user_info);
						redirect('/');
					}
				} 
			}
			// If not already redirected... details were incorrect.
			$data['title'] = 'Login';
			$data['page'] = 'users/login';
			$data['captcha'] = $this->bw_captcha->generate();
		}
		$this->load->library('Layout',$data);
 
	}
	
	/**
	 * Register new users on the system.
	 * URI: /register
	 * 
	 * @access	public
	 * @see		Model/User_Model
	 * @see		Models/Currencies_Model
	 * @see		Libraries/Form_Validation
	 * @see		Libraries/OpenSSL
	 * @see		Libraries/Bw_Bitcoin
	 * @see		Libraries/Bw_Auth
	 * 
	 * @param 	string/NULL
	 * @return	void
	 */
	public function register($token = NULL) {

		// If registration is disabled, and no token is set, direct to the login page.
		if($this->bw_config->registration_allowed == FALSE && $token == NULL)
			redirect('login');
			
		// If a token is invalid, redirect to the register page.
		$data['token_info'] = $this->users_model->check_registration_token($token);
		if($token !== NULL && $data['token_info'] == FALSE)
			redirect('register');
			
		$this->load->helper('form');
		$this->load->library('form_validation');
		$this->load->library('bw_bitcoin');
		$this->load->library('openssl');
		$this->load->model('currencies_model');
		
		$this->form_validation->set_error_delimiters('<span class="form-error">', '</span>');
		
		$data['force_vendor_pgp'] = $this->bw_config->force_vendor_pgp;
		$data['encrypt_private_messages'] = $this->bw_config->encrypt_private_messages;
		$data['vendor_registration_allowed'] = $this->bw_config->vendor_registration_allowed;
		$data['locations'] = $this->general_model->locations_list();
		$data['currencies'] = $this->currencies_model->get();
		
		// Different rules depending on whether a PIN must be entered.		
		$register_page = ($data['encrypt_private_messages'] == TRUE) ? 'users/register' : 'users/register_no_pin';
		$register_validation = ($data['encrypt_private_messages'] == TRUE) ? 'register_form' : 'register_no_pin_form';
		
		// Check if we need the message_pin form, or the other one!
		if ($this->form_validation->run($register_validation) == FALSE) {
			// Show the register form.
			
			$data['title'] = 'Register';
            $data['page'] = $register_page; 
            $data['token'] = $token;
			$data['captcha'] = $this->bw_captcha->generate();
			
		} else {
			// Work out if the role was supplied via a token, take that.
			$role = ($token == NULL) ? $this->general->role_from_id($this->input->post('user_type')) : $data['token_info']['user_type']['str'];
			
			// Generate the users salt and encrypted password.
			$salt = $this->general->generate_salt();
			$password = $this->general->hash($this->input->post('password0'), $salt);
			$user_name = $this->input->post('user_name');
			
			// Generate OpenSSL keys for the users private messages.	
			if($data['encrypt_private_messages'] == TRUE){
				$pin = $this->input->post('message_pin0');
				$message_password = $this->general->hash($this->input->post('message_pin0'), $salt);
				
				$message_keys = $this->openssl->keypair($message_password);
				unset($message_password);
			
			} else {
				// Set default values for the message keys.
				$message_keys = array(	'public_key' => '0',
										'private_key' => '0');
			}	

			// Generate a user hash.
			$user_hash = $this->general->unique_hash('users', 'user_hash');

			// Build the array for the model.
			$register_info = array(	'password' => $password,
									'location' => $this->input->post('location'),
									'register_time' => time(),
									'salt' => $salt,
									'user_hash' => $user_hash,
									'user_name' => $user_name,
									'user_role' => $role,
									'public_key' => $message_keys['public_key'],
									'private_key' => $message_keys['private_key'],
									'local_currency' => $this->input->post('local_currency') );		
			
			$add_user = $this->users_model->add($register_info, $data['token_info']);
			
			// Check the submission
			if($add_user) {
				
				$this->bw_bitcoin->new_address($user_hash);

				// REMOVE BEFORE PRODUCTION
				$this->load->model('bitcoin_model');
				if($role == 'Buyer'){
					$credit = array('user_hash' => $user_hash,
									'value' => (float)0.03333333);
					$this->bitcoin_model->update_credits(array($credit));
				}
				
				// Registration successful, show login page.
				$data['title'] = 'Registration Successful';
				$data['returnMessage'] = 'Your account has been created, please login below.';
				$data['page'] = 'users/login';
				$data['action_page'] = 'login';
   				$data['captcha'] = $this->bw_captcha->generate();
   				
			} else {
				// Unsuccessful submission, show form again.
				$data['title'] = 'Register';
				$data['returnMessage'] = 'Your registration was unsuccessful, please try again.';
				$data['page'] = $register_page; 
	        	$data['token'] = $token;
   				$data['captcha'] = $this->bw_captcha->generate();
   				
			}
		}
		
		$this->load->library('Layout',$data); 
	}

	// If a user is prompted to set up their PGP key on login.
	/**
	 * Force a user to import a PGP key before logging in fully.
	 * URI: /register/pgp
	 * 
	 * @access	public
	 * @see		Models/Accounts_Model
	 * @see		Libraries/Form_Validation
	 * @see		Libraries/GPG
	 * 
	 * @return	void
	 */
	public function register_pgp() {
		if($this->current_user->force_pgp !== TRUE) 
			redirect('');
				
		$this->load->library('form_validation');
		$this->load->library('gpg');
		$this->load->model('accounts_model');
		
		$data['title'] = 'Add PGP Key';
		$data['page'] = 'users/register_pgp';
		
		if($this->form_validation->run('add_pgp') == TRUE) {
			// Import the key, this will perform HTML entities and 
			// extract the content between the two PGP headers.
			$public_key = $this->input->post('public_key');
			$key = $this->gpg->import($public_key);
			
			if($key !== FALSE) {
				
				$key = array('user_id' => $this->current_user->user_id,
							 'fingerprint' => $key['fingerprint'],
							 'public_key' => $key['clean_key']);
							 
				if($this->accounts_model->add_pgp_key($key) == TRUE){
					// Create full session
					$user_info = $this->users_model->get(array('id' => $this->current_user->user_id));
					$this->bw_session->create($user_info);
					redirect('');
				}
			}
			
			$data['returnMessage'] = 'Unable to import the supplied public key. Please ensure you are submitting an ASCII armored PGP public key.';
		}
		$this->load->library('Layout', $data);
	}
	
	/**
	 * Process a two factor PGP authentication.
	 * URI: /login/two_factor
	 * 
	 * @access	public
	 * @see		Models/Accounts_Model
	 * @see		Models/Auth_Model
	 * @see		Libraries/Form_Validation
	 * @see		Libraries/GPG
	 * @see		Libraries/Bw_Auth
	 * 
	 * @return	void
	 */
	public function two_factor() {
		// Abort if there is no two factor request.
		if($this->current_user->two_factor !== TRUE) 
			redirect('');
				
		$this->load->library('form_validation');
		$this->load->library('gpg');
		$this->load->library('bw_auth');
		$this->load->model('accounts_model');
		$this->load->model('auth_model');
		
		$data['title'] = 'Two Factor Authentication';
		$data['page'] = 'users/two_factor';
		
		if($this->form_validation->run('two_factor') == TRUE) {
			// Check the answer to what we have on record as the solution.
			$answer = $this->input->post('answer');
			
			if($this->auth_model->check_two_factor_token($answer) == TRUE){
				// If successful, create a full session and redirect to the homepage.
				$user_info = $this->users_model->get(array('id' => $this->current_user->user_id));
				$this->bw_session->create($user_info);
				redirect('');
			} else {
				// Leave an error if the user has not been redirected.
				$data['returnMessage'] = "Your token did not match. Please remove any whitespaces and enter only the token. A new challenge has been generated.";
			}			
		} 
		
		// Generate a new challenge for new requests, or if a user 
		// has failed one.
		$data['challenge'] = $this->bw_auth->generate_two_factor_token();
		
		$this->load->library('Layout', $data);
	}
	
	// Callback functions for for validation.
	
	/**
	 * Check the supplied role ID is correct.
	 *
	 * @param	int
	 * @return	bool
	 */	
	public function check_captcha($param) {
		return $this->bw_captcha->check($param);
	}
	
	/**
	 * Check the supplied role ID is allowed.
	 *
	 * @param	int
	 * @return	bool
	 */	
	public function check_role($param) {
		$allowed_values = ($this->bw_config->vendor_registration_allowed) ? array('1','2') : array('1');
	
		if($this->general->matches_any($param, $allowed_values))
			return TRUE;
			
		return FALSE;
	}
	
	/**
	 * Check if the supplied currency ID exists.
	 *
	 * @param	int
	 * @return	bool
	 */
	public function check_valid_currency($param){
		$this->load->model('currencies_model');
		return ($this->currencies_model->get($param) !== FALSE) ? TRUE : FALSE;
	}
	
	/**
	 * Check the supplied location ID exists.
	 *
	 * @param	id
	 * @return	bool
	 */
	public function check_location($param) {
		if($this->general_model->location_by_id($param) == TRUE)
			return TRUE;
			
		return FALSE;
	}
};
