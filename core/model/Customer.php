<?php
/**
 * Customer class
 * Customer contact information
 *
 * @author Jonathan Davis
 * @version 1.0
 * @copyright Ingenesis Limited, 28 March, 2008
 * @package shopp
 **/
require("Billing.php");
require("Shipping.php");

class Customer extends DatabaseObject {
	static $table = "customer";
	
	var $login = false;
	var $info = false;
	var $newuser = false;
	
	var $accounts = "none";			// Account system setting
	var $merchant = "";
	
	var $management = array(
		"account" => "account",
		"downloads" => "downloads",
		"history" => "history",
		"status" => "status",
		"logout" => "logout",
		);
	
	function Customer ($id=false,$key=false) {
		global $Shopp;
		
		$this->accounts = $Shopp->Settings->get('account_system');
		$this->merchant = $Shopp->Settings->get('merchant_email');
		
		$this->init(self::$table);
		$this->load($id,$key);
		if (!empty($this->id)) $this->load_info();

	}
	
	function load_info () {
		$this->info = new ObjectMeta($this->id,'customer');
	}
	
	function management () {
		global $Shopp;

		if (isset($_GET['acct'])) {
			switch ($_GET['acct']) {
				case "receipt": break;
				case "history": $this->load_orders(); break;
				case "downloads": $this->load_downloads(); break;
				// case "logout": $Shopp->Cart->logout(); break;
			}
		}
		
		if (!empty($_POST['vieworder']) && !empty($_POST['purchaseid'])) {
			
			$Purchase = new Purchase($_POST['purchaseid']);
			if ($Purchase->email == $_POST['email']) {
				$Shopp->Purchase = $Purchase;
				$Purchase->load_purchased();
				ob_start();
				include(SHOPP_TEMPLATES."/receipt.php");
				$content = ob_get_contents();
				ob_end_clean();
				return apply_filters('shopp_account_vieworder',$content);
			}
		}

		if (!empty($_GET['acct']) && !empty($_GET['id'])) {
			$Purchase = new Purchase($_GET['id']);
			if ($Purchase->customer != $this->id) {
				new ShoppError(sprintf(__('Order number %s could not be found in your order history.','Shopp'),$Purchase->id),'customer_order_history',SHOPP_AUTH_ERR);
				unset($_GET['acct']);
				return false;
			} else {
				$Shopp->Purchase = $Purchase;
				$Purchase->load_purchased();
				ob_start();
				include(SHOPP_TEMPLATES."/receipt.php");
				$content = ob_get_contents();
				ob_end_clean();
			}
			$management = apply_filters('shopp_account_management_url',
				'<p><a href="'.$this->tag('url').'">&laquo; Return to Account Management</a></p>');
			
			$content = $management.$content.$management;
			
			echo apply_filters('shopp_account_manager',$content);
			return false;
		}

		if (!empty($_POST['customer'])) {
			$this->updates($_POST);
			if (!empty($_POST['password']) && $_POST['password'] == $_POST['confirm-password']) {
				$this->password = wp_hash_password($_POST['password']);
				if($this->accounts == "wordpress" && !empty($this->wpuser)) wp_set_password( $_POST['password'], $this->wpuser ); 
				$this->save();
			} else {
				if (!empty($_POST['password'])) new ShoppError(__('The passwords you entered do not match. Please re-enter your passwords.','Shopp'), 'customer_account_management');
			}
		}
		
	}
	
	function recovery () {
		global $Shopp;
		$errors = array();
		
		// Check email or login supplied
		if (empty($_POST['account-login'])) {
			if ($this->accounts == "wordpress") $errors[] = new ShoppError(__('Enter an email address or login name','Shopp'));
			else $errors[] = new ShoppError(__('Enter an email address','Shopp'));
		} else {
			// Check that the account exists
			if (strpos($_POST['account-login'],'@') !== false) {
				$RecoveryCustomer = new Customer($_POST['account-login'],'email');
				if (!$RecoveryCustomer->id)
					$errors[] = new ShoppError(__('There is no user registered with that email address.','Shopp'),'password_recover_noaccount',SHOPP_AUTH_ERR);
			} else {
				$user_data = get_userdatabylogin($_POST['account-login']);
				$RecoveryCustomer = new Customer($user_data->ID,'wpuser');
				if (empty($RecoveryCustomer->id))
					$errors[] = new ShoppError(__('There is no user registered with that login name.','Shopp'),'password_recover_noaccount',SHOPP_AUTH_ERR);				
			}
		}
		
		// return errors
		if (!empty($errors)) return;

		// Generate new key
		$RecoveryCustomer->activation = wp_generate_password(20, false);
		do_action_ref_array('shopp_generate_password_key', array(&$RecoveryCustomer));
		$RecoveryCustomer->save();

		$subject = apply_filters('shopp_recover_password_subject', sprintf(__('[%s] Password Recovery Request','Shopp'),get_option('blogname')));
		
		$_ = array();
		$_[] = 'From: "'.get_option('blogname').'" <'.$Shopp->Settings->get('merchant_email').'>';
		$_[] = 'To: '.$RecoveryCustomer->email;
		$_[] = 'Subject: '.$subject;
		$_[] = '';
		$_[] = __('A request has been made to reset the password for the following site and account:','Shopp');
		$_[] = get_option('siteurl');
		$_[] = '';
		if (isset($_POST['email-login']))
			$_[] = sprintf(__('Email: %s','Shopp'), $RecoveryCustomer->email);
		if (isset($_POST['loginname-login']))
			$_[] = sprintf(__('Login name: %s','Shopp'), $user_data->user_login);
		$_[] = '';
		$_[] = __('To reset your password visit the following address, otherwise just ignore this email and nothing will happen.');
		$_[] = '';
		$_[] = add_query_arg(array('acct'=>'rp','key'=>$RecoveryCustomer->activation),$Shopp->link('account'));
		$message = apply_filters('shopp_recover_password_message',join("\r\n",$_));
		
		if (!shopp_email($message)) {
			new ShoppError(__('The e-mail could not be sent.'),'password_recovery_email',SHOPP_ERR);
			shopp_redirect(add_query_arg('acct','recover',$Shopp->link('account')));
		} else {
			new ShoppError(__('Check your email address for instructions on resetting the password for your account.','Shopp'),'password_recovery_email',SHOPP_ERR);
		}

	}
	
	function reset_password ($activation) {
		if ($this->accounts == "none") return;
		
		$user_data = false;
		$activation = preg_replace('/[^a-z0-9]/i', '', $activation);

		$errors = array();
		if (empty($activation) || !is_string($activation))
			$errors[] = new ShoppError(__('Invalid key'));
		
		$RecoveryCustomer = new Customer($activation,'activation');
		if (empty($RecoveryCustomer->id)) 
			$errors[] = new ShoppError(__('Invalid key'));
		
		if (!empty($errors)) return false;

		// Generate a new random password
		$password = wp_generate_password();
		
		do_action_ref_array('password_reset', array(&$RecoveryCustomer,$password));
		
		$RecoveryCustomer->password = wp_hash_password($password);
		if ($this->accounts == "wordpress") {
			$user_data = get_userdata($RecoveryCustomer->wpuser);
			wp_set_password($password, $user_data->ID);
		}
		
		$RecoveryCustomer->activation = '';
		$RecoveryCustomer->save();
		
		$subject = apply_filters('shopp_reset_password_subject', sprintf(__('[%s] New Password','Shopp'),get_option('blogname')));
		
		$_ = array();
		$_[] = 'From: "'.get_option('blogname').'" <'.$Shopp->Settings->get('merchant_email').'>';
		$_[] = 'To: '.$RecoveryCustomer->email;
		$_[] = 'Subject: '.$subject;
		$_[] = '';
		$_[] = sprintf(__('Your new password for %s:','Shopp'),get_option('siteurl'));
		$_[] = '';
		if ($user_data)
			$_[] = sprintf(__('Login name: %s','Shopp'), $user_data->user_login);
		$_[] = sprintf(__('Password: %s'), $password) . "\r\n";
		$_[] = '';
		$_[] = __('Click here to login:').' '.$Shopp->link('account');
		$message = apply_filters('shopp_reset_password_message',join("\r\n",$_));
		
		if (!shopp_email($message)) {
			new ShoppError(__('The e-mail could not be sent.'),'password_reset_email',SHOPP_ERR);
			shopp_redirect(add_query_arg('acct','recover',$Shopp->link('account')));
		} else {
			new ShoppError(__('Check your email address for your new password.','Shopp'),'password_reset_email',SHOPP_ERR);
		}
		unset($_GET['acct']);
	}
	
	function load_downloads () {
		if (empty($this->id)) return false;
		$db =& DB::get();
		$orders = DatabaseObject::tablename(Purchase::$table);
		$purchases = DatabaseObject::tablename(Purchased::$table);
		$pricing = DatabaseObject::tablename(Price::$table);
		$asset = DatabaseObject::tablename(ProductDownload::$table);
		$query = "SELECT p.*,f.id as download,f.name as filename,f.value AS filedata FROM $purchases AS p LEFT JOIN $orders AS o ON o.id=p.purchase LEFT JOIN $asset AS f ON f.parent=p.price WHERE o.customer=$this->id AND context='price' AND type='download'";
		$this->downloads = $db->query($query,AS_ARRAY);
		foreach ($this->downloads as &$download) {
			$download->filedata = unserialize($download->filedata);
			foreach ($download->filedata as $property => $value) {
				$download->{$property} = $value;
			}
		}
	}

	function load_orders ($filters=array()) {
		if (empty($this->id)) return false;
		global $Shopp;
		$db =& DB::get();
		
		$where = '';
		if (isset($filters['where'])) $where = " AND {$filters['where']}";
		$orders = DatabaseObject::tablename(Purchase::$table);
		$purchases = DatabaseObject::tablename(Purchased::$table);
		$query = "SELECT o.* FROM $orders AS o WHERE o.customer=$this->id $where ORDER BY created DESC";
		$Shopp->purchases = $db->query($query,AS_ARRAY);
		foreach($Shopp->purchases as &$p) {
			$Purchase = new Purchase();
			$Purchase->updates($p);
			$p = $Purchase;
		}
	}
	
	function create_wpuser () {
		require_once(ABSPATH."/wp-includes/registration.php");
		if (empty($this->login)) return false;
		if (username_exists($this->login)){
			new ShoppError(__('The login name you provided is already in use.  Please choose another login name.','Shopp'),'login_exists',SHOPP_ERR);
			return false;
		}
		if (empty($this->password)) $this->password = wp_generate_password(12,true);
		
		// Create the WordPress account
		$wpuser = wp_insert_user(array(
			'user_login' => $this->login,
			'user_pass' => $this->password,
			'user_email' => $this->email,
			'display_name' => $this->firstname.' '.$this->Customer->lastname,
			'nickname' => $handle,
			'first_name' => $this->firstname,
			'last_name' => $this->lastname
		));
		if (!$wpuser) return false;

		// Link the WP user ID to this customer record
		$this->wpuser = $wpuser;
		
		// Send email notification of the new account
		wp_new_user_notification( $wpuser, $this->password );
		$this->password = "";
		if (SHOPP_DEBUG) new ShoppError('Successfully created the WordPress user for the Shopp account.',false,SHOPP_DEBUG_ERR);
		
		$this->newuser = true;
		
		return true;
	}
	
	function taxrule ($rule) {
		switch ($rule['p']) {
			case "customer-type": return ($rule['v'] == $this->type); break;
		}
		return false;
	}
	
	function exportcolumns () {
		$prefix = "c.";
		return array(
			$prefix.'firstname' => __('Customer\'s First Name','Shopp'),
			$prefix.'lastname' => __('Customer\'s Last Name','Shopp'),
			$prefix.'email' => __('Customer\'s Email Address','Shopp'),
			$prefix.'phone' => __('Customer\'s Phone Number','Shopp'),
			$prefix.'company' => __('Customer\'s Company','Shopp'),
			$prefix.'marketing' => __('Customer\'s Marketing Preference','Shopp'),
			$prefix.'info' => __('Customer\'s Custom Information','Shopp'),
			$prefix.'created' => __('Customer Created Date','Shopp'),
			$prefix.'modified' => __('Customer Last Updated Date','Shopp'),
			);
	}
	
	function tag ($property,$options=array()) {
		global $Shopp;
		
		$menus = array(
			"account" => __("My Account","Shopp"),
			"downloads" => __("Downloads","Shopp"),
			"history" => __("Order History","Shopp"),
			"status" => __("Order Status","Shopp"),
			"logout" => __("Logout","Shopp")
		);
		
		// Return strings with no options
		switch ($property) {
			case "url": return add_query_arg('acct',false,esc_url($_SERVER['REQUEST_URI'])); break;
			case "accounturl": return $Shopp->link('account'); break;
			case "recover-url": return add_query_arg('acct','recover',$Shopp->link('account'));
			case "process":
				if (isset($_GET['acct'])) return $_GET['acct'];
				return false;

			case "loggedin": return $Shopp->Order->Customer->login; break;
			case "notloggedin": return (!$Shopp->Order->Customer->login && $Shopp->Settings->get('account_system') != "none"); break;
			case "login-label": 
				$accounts = $Shopp->Settings->get('account_system');
				$label = __('Email Address','Shopp');
				if ($accounts == "wordpress") $label = __('Login Name','Shopp');
				if (isset($options['label'])) $label = $options['label'];
				return $label;
				break;
			case "email-login": 
			case "loginname-login": 
			case "account-login": 
				if (!empty($_POST['account-login']))
					$options['value'] = $_POST['account-login']; 
				return '<input type="text" name="account-login" id="account-login"'.inputattrs($options).' />';
				break;
			case "password-login": 
				if (!empty($_POST['password-login']))
					$options['value'] = $_POST['password-login']; 
				return '<input type="password" name="password-login" id="password-login"'.inputattrs($options).' />';
				break;
			case "recover-button":
				if (!isset($options['value'])) $options['value'] = __('Get New Password','Shopp');
 					return '<input type="submit" name="recover-login" id="recover-button"'.inputattrs($options).' />';
				break;
			case "submit-login": // Deprecating
			case "login-button":
				if (!isset($options['value'])) $options['value'] = __('Login','Shopp');
				if (!is_shopp_page('checkout'))
					$string = '<input type="hidden" name="process-login" id="process-login" value="true" />';
				else $string = '<input type="hidden" name="process-login" id="process-login" value="false" />';
				$string .= '<input type="submit" name="submit-login" id="submit-login"'.inputattrs($options).' />';
				return $string;
				break;
			case "errors-exist": return true;
				$Errors = &ShoppErrors();
				return ($Errors->exist(SHOPP_AUTH_ERR));
				break;
			case "login-errors":
				$result = "";
				$Errors = &ShoppErrors();
				if (!$Errors->exist(SHOPP_AUTH_ERR)) return false;
				$errors = $Errors->get(SHOPP_AUTH_ERR);
				foreach ((array)$errors as $error) if (!empty($error)) $result .= '<p class="error">'.$error->message(true).'</p>';
				return $result;
				break;

			case "menu":
				if (!isset($this->_menu_looping)) {
					reset($this->management);
					$this->_menu_looping = true;
				} else next($this->management);
				
				if (current($this->management) !== false) return true;
				else {
					unset($this->_menu_looping);
					reset($this->management);
					return false;
				}
				break;
			case "management":				
				if (array_key_exists('url',$options)) return add_query_arg('acct',key($this->management),$Shopp->link('account'));
				if (array_key_exists('action',$options)) return key($this->management);
				return $menus[key($this->management)];
			case "accounts": return $Shopp->Settings->get('account_system'); break;
			case "hasaccount": 
				$system = $Shopp->Settings->get('account_system');
				if ($system == "wordpress") return ($this->wpuser != 0);
				elseif ($system == "shopp") return (!empty($this->password));
				else return false;
			case "wpuser-created": return $this->newuser;
			case "order-lookup":
				$auth = $Shopp->Settings->get('account_system');
				if ($auth != "none") return true;
			
				if (!empty($_POST['vieworder']) && !empty($_POST['purchaseid'])) {
					require_once("Purchase.php");
					$Purchase = new Purchase($_POST['purchaseid']);
					if ($Purchase->email == $_POST['email']) {
						$Shopp->Purchase = $Purchase;
						$Purchase->load_purchased();
						ob_start();
						include(SHOPP_TEMPLATES."/receipt.php");
						$content = ob_get_contents();
						ob_end_clean();
						return apply_filters('shopp_order_lookup',$content);
					}
				}

				ob_start();
				include(SHOPP_ADMIN_PATH."/orders/account.php");
				$content = ob_get_contents();
				ob_end_clean();
				return apply_filters('shopp_order_lookup',$content);
				break;

			case "firstname": 
				if (isset($options['mode']) && $options['mode'] == "value") return $this->firstname;
				if (!empty($this->firstname))
					$options['value'] = $this->firstname; 
				return '<input type="text" name="firstname" id="firstname"'.inputattrs($options).' />';
				break;
			case "lastname":
				if (isset($options['mode']) && $options['mode'] == "value") return $this->lastname;
				if (!empty($this->lastname))
					$options['value'] = $this->lastname; 
				return '<input type="text" name="lastname" id="lastname"'.inputattrs($options).' />'; 
				break;
			case "company":
				if (isset($options['mode']) && $options['mode'] == "value") return $this->company;
				if (!empty($this->company))
					$options['value'] = $this->company; 
				return '<input type="text" name="company" id="company"'.inputattrs($options).' />'; 
				break;
			case "email":
				if (isset($options['mode']) && $options['mode'] == "value") return $this->email;
				if (!empty($this->email))
					$options['value'] = $this->email; 
				return '<input type="text" name="email" id="email"'.inputattrs($options).' />';
				break;
			case "loginname":
				if (isset($options['mode']) && $options['mode'] == "value") return $this->loginname;
				if (!empty($this->login))
					$options['value'] = $this->login; 
				return '<input type="text" name="login" id="login"'.inputattrs($options).' />';
				break;
			case "password":
				if (isset($options['mode']) && $options['mode'] == "value") 
					return strlen($this->password) == 34?str_pad('&bull;',8):$this->password;
				$options['value'] = "";
				return '<input type="password" name="password" id="password"'.inputattrs($options).' />';
				break;
			case "confirm-password":
				$options['value'] = ""; 
				return '<input type="password" name="confirm-password" id="confirm-password"'.inputattrs($options).' />';
				break;
			case "phone": 
				if (isset($options['mode']) && $options['mode'] == "value") return $this->phone;
				if (!empty($this->phone))
					$options['value'] = $this->phone; 
				return '<input type="text" name="phone" id="phone"'.inputattrs($options).' />'; 
				break;
			case "hasinfo":
			case "has-info":
				if (!is_object($this->info) || $this->info->is_empty()) return false;
				if (!isset($this->_info_looping)) {
					reset($this->info);
					$this->_info_looping = true;
				} else next($this->info);
				
				if (current($this->info) !== false) return true;
				else {
					unset($this->_info_looping);
					reset($this->info);
					return false;
				}
				break;
			case "info":
				if (is_array($this->info) && isset($options['mode'])) {
					$options['name'] = key($this->info);
					$options['value'] = current($this->info);
				} else {
					$options['name'] = isset($options['name'])?$options['name']:false;
					$options['value'] = isset($options['value'])?$options['value']:false;
				}

				$allowed_types = array("text","password","hidden","checkbox","radio");
				if (empty($options['type'])) $options['type'] = "hidden";
				if (in_array($options['type'],$allowed_types)) {
					if (isset($options['mode']) && $options['mode'] == "name") return $options['name'];
					if (isset($options['mode']) && $options['mode'] == "value") return $options['value'];
					return '<input type="text" name="info['.$options['name'].']" id="customer-info-'.sanitize_title_with_dashes($options['name']).'"'.inputattrs($options).' />'; 
				}
				break;
			case "save-button":
				if (!isset($options['label'])) $options['label'] = __('Save','Shopp');
				$result = '<input type="hidden" name="customer" value="true" />';
				$result .= '<input type="submit" name="save" id="save-button"'.inputattrs($options).' />'; 
				return $result;
				break;
			
			
			// Downloads UI tags
			case "hasdownloads":
			case "has-downloads": return (!empty($this->downloads)); break;
			case "downloads":
				if (empty($this->downloads)) return false;
				if (!isset($this->_dowload_looping)) {
					reset($this->downloads);
					$this->_dowload_looping = true;
				} else next($this->downloads);
			
				if (current($this->downloads) !== false) return true;
				else {
					unset($this->_dowload_looping);
					reset($this->downloads);
					return false;
				}
				break;
			case "download":
				$download = current($this->downloads);
				$df = get_option('date_format');
				$properties = unserialize($download->properties);
				$string = '';
				if (array_key_exists('id',$options)) $string .= $download->download;
				if (array_key_exists('purchase',$options)) $string .= $download->purchase;
				if (array_key_exists('name',$options)) $string .= $download->name;
				if (array_key_exists('variation',$options)) $string .= $download->optionlabel;
				if (array_key_exists('downloads',$options)) $string .= $download->downloads;
				if (array_key_exists('key',$options)) $string .= $download->dkey;
				if (array_key_exists('created',$options)) $string .= $download->created;
				if (array_key_exists('total',$options)) $string .= money($download->total);
				if (array_key_exists('filetype',$options)) $string .= $properties['mimetype'];
				if (array_key_exists('size',$options)) $string .= readableFileSize($download->size);
				if (array_key_exists('date',$options)) $string .= _d($df,mktimestamp($download->created));
				if (array_key_exists('url',$options)) $string .= (SHOPP_PERMALINKS) ?
					$Shopp->shopuri."download/".$download->dkey : 
					add_query_arg('shopp_download',$download->dkey,$Shopp->link('account'));
				
				return $string;
				break;
				
			// Downloads UI tags
			case "haspurchases":
			case "has-purchases":
				$filters = array();
				if (isset($options['daysago'])) 
					$filters['where'] = "UNIX_TIMESTAMP(o.created) > UNIX_TIMESTAMP()-".($options['daysago']*86400);
				if (empty($Shopp->purchases)) $this->load_orders($filters);
				return (!empty($Shopp->purchases));
				break;
			case "purchases":
				if (!isset($this->_purchaseloop)) {
					reset($Shopp->purchases);
					$Shopp->Purchase = current($Shopp->purchases);
					$this->_purchaseloop = true;
				} else {
					$Shopp->Purchase = next($Shopp->purchases);
				}

				if (current($Shopp->purchases) !== false) return true;
				else {
					unset($this->_purchaseloop);
					return false;
				}
				break;
			case "receipt":
				return add_query_arg(
					array(
						'acct'=>'receipt',
						'id'=>$Shopp->Purchase->id),
						$Shopp->link('account'));

		}
	}

} // end Customer class

class CustomersExport {
	var $sitename = "";
	var $headings = false;
	var $data = false;
	var $defined = array();
	var $customer_cols = array();
	var $billing_cols = array();
	var $shipping_cols = array();
	var $selected = array();
	var $recordstart = true;
	var $content_type = "text/plain";
	var $extension = "txt";
	var $set = 0;
	var $limit = 1024;
	
	function CustomersExport () {
		global $Shopp;
		
		$this->customer_cols = Customer::exportcolumns();
		$this->billing_cols = Billing::exportcolumns();
		$this->shipping_cols = Shipping::exportcolumns();
		$this->defined = array_merge($this->customer_cols,$this->billing_cols,$this->shipping_cols);
		
		$this->sitename = get_bloginfo('name');
		$this->headings = ($Shopp->Settings->get('customerexport_headers') == "on");
		$this->selected = $Shopp->Settings->get('customerexport_columns');
		$Shopp->Settings->save('customerexport_lastexport',mktime());
	}
	
	function query ($request=array()) {
		$db =& DB::get();
		if (empty($request)) $request = $_GET;
		
		if (!empty($request['start'])) {
			list($month,$day,$year) = explode("/",$request['start']);
			$starts = mktime(0,0,0,$month,$day,$year);
		}
		
		if (!empty($request['end'])) {
			list($month,$day,$year) = explode("/",$request['end']);
			$ends = mktime(0,0,0,$month,$day,$year);
		}
		
		$where = "WHERE c.id IS NOT NULL ";
		if (isset($request['s']) && !empty($request['s'])) $where .= " AND (id='{$request['s']}' OR firstname LIKE '%{$request['s']}%' OR lastname LIKE '%{$request['s']}%' OR CONCAT(firstname,' ',lastname) LIKE '%{$request['s']}%' OR transactionid LIKE '%{$request['s']}%')";
		if (!empty($request['start']) && !empty($request['end'])) $where .= " AND  (UNIX_TIMESTAMP(c.created) >= $starts AND UNIX_TIMESTAMP(c.created) <= $ends)";
		
		$customer_table = DatabaseObject::tablename(Customer::$table);
		$billing_table = DatabaseObject::tablename(Billing::$table);
		$shipping_table = DatabaseObject::tablename(Shipping::$table);
		$offset = $this->set*$this->limit;
		
		$c = 0; $columns = array();
		foreach ($this->selected as $column) $columns[] = "$column AS col".$c++;
		$query = "SELECT ".join(",",$columns)." FROM $customer_table AS c LEFT JOIN $billing_table AS b ON c.id=b.customer LEFT JOIN $shipping_table AS s ON c.id=s.customer $where ORDER BY c.created ASC LIMIT $offset,$this->limit";
		$this->data = $db->query($query,AS_ARRAY);
	}

	// Implement for exporting all the data
	function output () {
		if (!$this->data) $this->query();
		if (!$this->data) return false;
		header("Content-type: $this->content_type; charset=UTF-8");
		header("Content-Disposition: attachment; filename=\"$this->sitename Customer Export.$this->extension\"");
		header("Content-Description: Delivered by WordPress/Shopp ".SHOPP_VERSION);
		header("Cache-Control: maxage=1");
		header("Pragma: public");

		$this->begin();
		if ($this->headings) $this->heading();
		$this->records();
		$this->end();
	}
	
	function begin() {}
	
	function heading () {
		foreach ($this->selected as $name)
			$this->export($this->defined[$name]);
		$this->record();
	}
	
	function records () {
		while (!empty($this->data)) {
			foreach ($this->data as $key => $record) {
				foreach(get_object_vars($record) as $column)
					$this->export($this->parse($column));
				$this->record();
			}
			$this->set++;
			$this->query();
		}
	}
	
	function parse ($column) {
		if (preg_match("/^[sibNaO](?:\:.+?\{.*\}$|\:.+;$|;$)/",$column)) {
			$list = unserialize($column);
			$column = "";
			foreach ($list as $name => $value)
				$column .= (empty($column)?"":";")."$name:$value";
		}
		return $column;
	}

	function end() {}
	
	// Implement for exporting a single value
	function export ($value) {
		echo ($this->recordstart?"":"\t").$value;
		$this->recordstart = false;
	}
	
	function record () {
		echo "\n";
		$this->recordstart = true;
	}
	
}

class CustomersTabExport extends CustomersExport {
	function CustomersTabExport () {
		parent::CustomersExport();
		$this->output();
	}
}

class CustomersCSVExport extends CustomersExport {
	function CustomersCSVExport () {
		parent::CustomersExport();
		$this->content_type = "text/csv";
		$this->extension = "csv";
		$this->output();
	}
	
	function export ($value) {
		$value = str_replace('"','""',$value);
		if (preg_match('/^\s|[,"\n\r]|\s$/',$value)) $value = '"'.$value.'"';
		echo ($this->recordstart?"":",").$value;
		$this->recordstart = false;
	}
	
}

class CustomersXLSExport extends CustomersExport {
	function CustomersXLSExport () {
		parent::CustomersExport();
		$this->content_type = "application/vnd.ms-excel";
		$this->extension = "xls";
		$this->c = 0; $this->r = 0;
		$this->output();
	}
	
	function begin () {
		echo pack("ssssss", 0x809, 0x8, 0x0, 0x10, 0x0, 0x0);
	}
	
	function end () {
		echo pack("ss", 0x0A, 0x00);
	}
	
	function export ($value) {
		if (preg_match('/^[\d\.]+$/',$value)) {
		 	echo pack("sssss", 0x203, 14, $this->r, $this->c, 0x0);
			echo pack("d", $value);
		} else {
			$l = strlen($value);
			echo pack("ssssss", 0x204, 8+$l, $this->r, $this->c, 0x0, $l);
			echo $value;
		}
		$this->c++;
	}
	
	function record () {
		$this->c = 0;
		$this->r++;
	}
}

?>