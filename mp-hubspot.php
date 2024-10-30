<?php
/*
 Plugin Name: MemberPress HubSpot Integration
 Author: Nathan Smith
 Tags: HubSpot, MemberPress, Workflowm, CRM
 Description: HubSpot autoresponder integration for MemberPress
 Version: 1.0
 Author URI: https://nathan.services
 */

if(!defined('ABSPATH')) {
	die('You are not allowed to call this page directly.');
}

include_once(ABSPATH . 'wp-admin/includes/plugin.php');
if(!is_plugin_active('memberpress/memberpress.php')) {
	return;
}

class MP_Hubspot {
	const ID = 'mp_hubspot';
	const SETTING_GROUP = 'mp_hubspot_settings_group';
	const SETTING = 'mp_hubspot_settings';
	const POST_META = 'mp_hubspot_meta';
	const ENDPOINT_BASE = 'https://api.hubapi.com';
	protected $options,$workflows;

	public function __construct() {
		add_action('admin_menu',[$this,'menuList']);
		add_action('mepr-signup',[$this,'processSignup']);
		//add_action('mepr-txn-store',[$this,'processChange']);
		//add_action('mepr-subscr-store',[$this,'processChange']);
		add_action('admin_init',[$this,'registerSettings']);
		add_action('mepr-product-advanced-metabox',[$this,'displayMetaBox']);
		add_action('mepr-product-save-meta',[$this,'saveMeta']);
	}
	public function menuList() {
		add_submenu_page('options-general.php','MemberPress HubSpot Settings', 'MemberPress HubSpot Settings', 'administrator', self::ID, [$this,'settingsPage']);
	}

	public function displayMetaBox($product) {
		if(!$this->isAuth()) {
			return;
		}

		$workflow_id = get_post_meta($product->ID,self::POST_META,true);
		?>
		<div class="product-options-panel">
			<label>Enroll in HubSpot workflow on subscribe:</label>
			<select name="<?=self::ID . '_workflow'?>">
				<option value="">None</option>
			<?php foreach($this->getWorkflows() as $id => $workflow) { ?>
				<option value="<?=$id?>" <?=selected($workflow_id,$id)?>><?=$workflow?></option>
			<?php } ?>
			</select>
		</div>
		<?php
	}

	public function saveMeta($product) {
		update_post_meta($product->ID,self::POST_META,$_POST[self::ID . '_workflow']);
	}

	public function registerSettings() {
		register_setting(self::SETTING_GROUP,self::SETTING,[$this,'sanitize']);
		add_settings_section(self::ID . '_key','',null,self::ID);
		add_settings_field('apiKey','API Key',function() {
			?>
		<input type="text" name="<?=self::SETTING . '[apiKey]'?>" value="<?=(isset($this->options['apiKey']) ? esc_attr($this->options['apiKey']) : null)?>"/>
		<?php
		},self::ID,self::ID . '_key');
	}

	public function processSignup($transaction) {
		$product = $transaction->product();
		$user = $transaction->user(true);
		$workflow_id = get_post_meta($product->ID,self::POST_META,true);

		if(!$this->isAuth() || !$workflow_id) {
			return;
		}

		$email = $user->user_email;
		$result = $this->apiCall('POST',self::ENDPOINT_BASE . '/automation/v2/workflows/' . $workflow_id . '/enrollments/contacts/' . rawurlencode($email));
	}

	public function sanitize($options) {
		$new = [];
		foreach($options as $key => $value) {
			$value = (int)$value;
			if($value) {
				$new[$key] = $value;
			}
		}
		$new['apiKey'] = $options['apiKey'];
		return $new;
	}

	public function settingsPage() {
		$this->options = get_option(self::SETTING);
?>
<div class="wrap">
	<h1>MemberPress HubSpot Settings</h1>
	<form method="post" action="options.php">
	<?php
		settings_fields(self::SETTING_GROUP);
		do_settings_sections(self::ID);
		submit_button();
	?>
	</form>
</div>
<?php
	}

	public function getWorkflows() {
		if($this->workflows) {
			return $this->workflows;
		}
		$result = $this->apiCall('GET', self::ENDPOINT_BASE . '/automation/v3/workflows/');
		$workflows = [];
		foreach($result['workflows'] as $workflow) {
			$workflows[$workflow['id']] = $workflow['name'];
		}
		$this->workflows = $workflows;
		return $this->workflows;
	}

	public function apiCall($method,$endpoint,$data=null) {
		$this->options = get_option(self::SETTING);
		if(empty($data)) $data = array();

		$http = new WP_Http();
		$args = [
			'method' => $method,
		];

		if(is_array($data)) {
			$args['body'] = json_encode($data);
		}
		else {
			$args['body'] = $data;
		}

		$args['headers'] = [
			'Content-Type' => 'application/json',
		];

		// Sign the request
		$pieces = parse_url($endpoint);
		$query = '';
		if(isset($pieces['query'])) {
			$query = $pieces['query'];
			// Can't do this. HubSpot uses duplicate property names that won't hash. e.g. property=firstname&property=lastname
			// parse_str($pieces['query'],$query);
		}
		$query .= '&hapikey=' . $this->options['apiKey'];

		$url = $pieces['scheme'] . '://' . $pieces['host'] . $pieces['path'] . '?' . ltrim($query,'&');

		$result = $http->request($url,$args);
		$code = $result['http_response']->get_status();

		if($code == 200 || $code == 204) {
			$data = json_decode($result['http_response']->get_data(),true);
			return $data;
		}
		return null;
	}

	public function isAuth() {
		$this->options = get_option(self::SETTING);
		return !empty($this->options['apiKey']);
	}
}
$mp_hubspot = new MP_Hubspot();
