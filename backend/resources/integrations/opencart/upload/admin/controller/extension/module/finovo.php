<?php
/**
 * Finovo OMS connector for OpenCart 3.
 *
 * Installing the module (Extensions → Modules → Install) creates an API key and secret for Finovo and sends them
 * to the Finovo store that generated this download. Finovo then calls catalog/controller/extension/finovo/api.php
 * with requests signed by that secret, and the module tells Finovo the moment an order or product changes.
 */
class ControllerExtensionModuleFinovo extends Controller {
	/** Shop events Finovo is told about: code => [trigger, action]. */
	const EVENTS = array(
		'finovo_order' => array('catalog/model/checkout/order/addOrderHistory/after', 'extension/finovo/notify/order'),
		'finovo_product_add' => array('admin/model/catalog/product/addProduct/after', 'extension/module/finovo/productSaved'),
		'finovo_product_edit' => array('admin/model/catalog/product/editProduct/after', 'extension/module/finovo/productSaved'),
		'finovo_product_delete' => array('admin/model/catalog/product/deleteProduct/after', 'extension/module/finovo/productDeleted'),
	);

	public function install() {
		$this->connect();
	}

	public function uninstall() {
		$this->load->model('setting/setting');
		$this->load->model('setting/event');
		$this->model_setting_setting->deleteSetting('module_finovo');

		foreach (array_keys(self::EVENTS) as $code) {
			$this->model_setting_event->deleteEventByCode($code);
		}
	}

	/**
	 * Event: a product was added or edited in the admin.
	 */
	public function productSaved(&$route, &$args, &$output) {
		$productId = substr($route, -strlen('addProduct')) === 'addProduct' ? (int)$output : (int)($args[0] ?? 0);

		\Finovo\Notifier::send($this->config, 'product.updated', $productId);
	}

	/**
	 * Event: a product was deleted in the admin.
	 */
	public function productDeleted(&$route, &$args, &$output) {
		\Finovo\Notifier::send($this->config, 'product.deleted', (int)($args[0] ?? 0));
	}

	public function index() {
		$this->load->language('extension/module/finovo');
		$this->load->model('setting/setting');

		$this->document->setTitle($this->language->get('heading_title'));

		$data['error_warning'] = '';
		$data['success'] = '';

		if ($this->request->server['REQUEST_METHOD'] == 'POST') {
			if (!$this->user->hasPermission('modify', 'extension/module/finovo')) {
				$data['error_warning'] = $this->language->get('error_permission');
			} elseif (($error = $this->connect()) !== null) {
				$data['error_warning'] = $error;
			} else {
				$data['success'] = $this->language->get('text_success');
			}
		}

		$settings = $this->model_setting_setting->getSetting('module_finovo');
		$connected = !empty($settings['module_finovo_status']);

		if (!$connected && $data['error_warning'] === '' && !empty($settings['module_finovo_error'])) {
			$data['error_warning'] = $settings['module_finovo_error'];
		}

		$data['status'] = $this->language->get($connected ? 'text_connected' : 'text_not_connected');
		$data['button_connect'] = $this->language->get($connected ? 'button_reconnect' : 'button_connect');

		$token = 'user_token=' . $this->session->data['user_token'];

		$data['breadcrumbs'] = array(
			array('text' => $this->language->get('text_home'), 'href' => $this->url->link('common/dashboard', $token, true)),
			array('text' => $this->language->get('text_extension'), 'href' => $this->url->link('marketplace/extension', $token . '&type=module', true)),
			array('text' => $this->language->get('heading_title'), 'href' => $this->url->link('extension/module/finovo', $token, true)),
		);

		$data['action'] = $this->url->link('extension/module/finovo', $token, true);
		$data['cancel'] = $this->url->link('marketplace/extension', $token . '&type=module', true);

		$data['header'] = $this->load->controller('common/header');
		$data['column_left'] = $this->load->controller('common/column_left');
		$data['footer'] = $this->load->controller('common/footer');

		$this->response->setOutput($this->load->view('extension/module/finovo', $data));
	}

	/**
	 * Create new API credentials, send them to Finovo and keep them only if Finovo can reach the shop with them.
	 *
	 * @return string|null error message, or null when connected
	 */
	private function connect() {
		$this->load->language('extension/module/finovo');
		$this->load->model('setting/setting');

		$connection = $this->connectionSettings();

		if ($connection === null) {
			return $this->saveError($this->language->get('error_connection_file'));
		}

		$previous = $this->model_setting_setting->getSetting('module_finovo');
		$apiKey = 'finovo_' . bin2hex(random_bytes(12));
		$apiSecret = bin2hex(random_bytes(32));

		// Saved first: Finovo calls the shop with these credentials before it answers.
		$this->model_setting_setting->editSetting('module_finovo', array(
			'module_finovo_status' => 1,
			'module_finovo_api_key' => $apiKey,
			'module_finovo_api_secret' => $apiSecret,
			'module_finovo_error' => '',
		));

		$response = $this->post($connection['callback_url'], array(
			'state' => $connection['state'],
			'api_key' => $apiKey,
			'api_secret' => $apiSecret,
			'shop_url' => $this->shopUrl(),
			'notifications' => true,
		));

		if (!$response['ok']) {
			$restored = $previous ? $previous : array('module_finovo_status' => 0);
			$restored['module_finovo_error'] = sprintf($this->language->get('error_finovo'), $response['message']);
			$this->model_setting_setting->editSetting('module_finovo', $restored);

			return $restored['module_finovo_error'];
		}

		$this->model_setting_setting->editSetting('module_finovo', array(
			'module_finovo_status' => 1,
			'module_finovo_api_key' => $apiKey,
			'module_finovo_api_secret' => $apiSecret,
			'module_finovo_webhook_url' => $response['webhook_url'],
			'module_finovo_error' => '',
		));
		$this->registerEvents();

		return null;
	}

	/**
	 * (Re)register the events that notify Finovo, so "Connect again" after an upgrade switches them on.
	 */
	private function registerEvents() {
		$this->load->model('setting/event');

		foreach (self::EVENTS as $code => $event) {
			$this->model_setting_event->deleteEventByCode($code);
			$this->model_setting_event->addEvent($code, $event[0], $event[1]);
		}
	}

	/**
	 * @return array|null callback_url and state written by Finovo into this download
	 */
	private function connectionSettings() {
		$file = DIR_SYSTEM . 'library/finovo/connection.php';

		if (!is_file($file)) {
			return null;
		}

		$settings = include $file;

		if (!is_array($settings) || empty($settings['callback_url']) || empty($settings['state'])) {
			return null;
		}

		return $settings;
	}

	private function shopUrl() {
		return ($this->config->get('config_secure') && defined('HTTPS_CATALOG')) ? HTTPS_CATALOG : HTTP_CATALOG;
	}

	private function saveError($message) {
		$this->model_setting_setting->editSetting('module_finovo', array(
			'module_finovo_status' => 0,
			'module_finovo_error' => $message,
		));

		return $message;
	}

	/**
	 * @return array ok (bool), message (string) and webhook_url (string)
	 */
	private function post($url, array $payload) {
		if (!function_exists('curl_init')) {
			return array('ok' => false, 'message' => $this->language->get('error_curl'));
		}

		$curl = curl_init($url);

		curl_setopt_array($curl, array(
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => json_encode($payload),
			CURLOPT_HTTPHEADER => array('Content-Type: application/json', 'Accept: application/json'),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT => 60,
		));

		$body = curl_exec($curl);
		$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$error = curl_error($curl);

		curl_close($curl);

		if ($body === false) {
			return array('ok' => false, 'message' => $error !== '' ? $error : $this->language->get('error_no_response'));
		}

		$json = json_decode($body, true);
		$message = (is_array($json) && isset($json['message'])) ? (string)$json['message'] : 'HTTP ' . $status;
		$ok = $status >= 200 && $status < 300 && is_array($json) && isset($json['status']) && $json['status'] === 'success';
		$webhookUrl = (is_array($json) && !empty($json['webhook_url'])) ? (string)$json['webhook_url'] : '';

		return array('ok' => $ok, 'message' => $message, 'webhook_url' => $webhookUrl);
	}
}
