<?php
/**
 * Endpoints Finovo calls on this shop: index.php?route=extension/finovo/api/<action> (health, products,
 * option_values, orders, order_products). Read-only; request bodies are JSON.
 * Every request must carry the API key and an HMAC-SHA256 signature (timestamp + nonce + body) made with the secret
 * the Finovo module created at install.
 */
class ControllerExtensionFinovoApi extends Controller {
	/** Signed requests older than this are refused, so a captured request cannot be replayed later. */
	const MAX_AGE_SECONDS = 300;

	public function health() {
		if (!$this->authorized()) {
			return;
		}

		$query = $this->db->query("SELECT COUNT(*) AS total FROM `" . DB_PREFIX . "order` WHERE order_status_id > '0'");

		$this->respond(200, array(
			'status' => 'ok',
			'platform' => 'opencart',
			'version' => VERSION,
			'orders' => (int)$query->row['total'],
		));
	}

	/**
	 * Products in pages: {offset, limit}. Also returns the public image folder URL.
	 */
	public function products() {
		if (!$this->authorized()) {
			return;
		}

		$input = $this->input();
		$language = (int)$this->config->get('config_language_id');
		$offset = max(0, (int)($input['offset'] ?? 0));
		$limit = min(500, max(1, (int)($input['limit'] ?? 100)));

		$query = $this->db->query("SELECT p.product_id, p.model, p.sku, p.upc, p.ean, p.quantity, p.price, p.image, p.status, pd.name, pd.description,
			(SELECT cd.name FROM `" . DB_PREFIX . "product_to_category` pc JOIN `" . DB_PREFIX . "category_description` cd ON cd.category_id = pc.category_id AND cd.language_id = '" . $language . "' WHERE pc.product_id = p.product_id LIMIT 1) AS category
			FROM `" . DB_PREFIX . "product` p
			LEFT JOIN `" . DB_PREFIX . "product_description` pd ON pd.product_id = p.product_id AND pd.language_id = '" . $language . "'
			ORDER BY p.product_id LIMIT " . $offset . ", " . $limit);

		$server = !empty($this->request->server['HTTPS']) ? $this->config->get('config_ssl') : $this->config->get('config_url');

		$this->respond(200, array('rows' => $query->rows, 'image_base_url' => ($server ? $server : HTTP_SERVER) . 'image/'));
	}

	/**
	 * Option values (OpenCart's stock-keeping unit below a product): {product_ids}.
	 */
	public function option_values() {
		if (!$this->authorized()) {
			return;
		}

		$language = (int)$this->config->get('config_language_id');
		$ids = $this->ids('product_ids');

		$rows = $ids ? $this->db->query("SELECT pov.product_option_value_id, pov.product_id, pov.quantity, pov.subtract, pov.price, pov.price_prefix, od.name AS option_name, ovd.name AS value_name
			FROM `" . DB_PREFIX . "product_option_value` pov
			JOIN `" . DB_PREFIX . "option_description` od ON od.option_id = pov.option_id AND od.language_id = '" . $language . "'
			JOIN `" . DB_PREFIX . "option_value_description` ovd ON ovd.option_value_id = pov.option_value_id AND ovd.language_id = '" . $language . "'
			WHERE pov.product_id IN (" . implode(',', $ids) . ") ORDER BY pov.product_option_value_id")->rows : array();

		$this->respond(200, array('rows' => $rows));
	}

	/**
	 * Orders with a status, oldest first: {modified_since, offset, limit}.
	 */
	public function orders() {
		if (!$this->authorized()) {
			return;
		}

		$input = $this->input();
		$language = (int)$this->config->get('config_language_id');
		$offset = max(0, (int)($input['offset'] ?? 0));
		$limit = min(500, max(1, (int)($input['limit'] ?? 100)));
		$since = !empty($input['modified_since']) ? " AND o.date_modified > '" . $this->db->escape((string)$input['modified_since']) . "'" : '';

		$query = $this->db->query("SELECT o.order_id, o.invoice_prefix, o.firstname, o.lastname, o.email, o.telephone,
			o.shipping_firstname, o.shipping_lastname, o.shipping_company, o.shipping_address_1, o.shipping_address_2,
			o.shipping_city, o.shipping_postcode, o.shipping_zone, o.shipping_country, o.comment,
			o.total, o.currency_code, o.currency_value, o.order_status_id, o.date_added, o.date_modified, os.name AS status_name
			FROM `" . DB_PREFIX . "order` o
			LEFT JOIN `" . DB_PREFIX . "order_status` os ON os.order_status_id = o.order_status_id AND os.language_id = '" . $language . "'
			WHERE o.order_status_id > '0'" . $since . "
			ORDER BY o.order_id LIMIT " . $offset . ", " . $limit);

		$this->respond(200, array('rows' => $query->rows));
	}

	/**
	 * Lines of the given orders: {order_ids}.
	 */
	public function order_products() {
		if (!$this->authorized()) {
			return;
		}

		$ids = $this->ids('order_ids');

		$rows = $ids ? $this->db->query("SELECT op.order_product_id, op.order_id, op.product_id, op.name, op.model, op.quantity, op.price, op.total, op.tax,
			(SELECT oo.product_option_value_id FROM `" . DB_PREFIX . "order_option` oo WHERE oo.order_product_id = op.order_product_id ORDER BY oo.order_option_id LIMIT 1) AS product_option_value_id
			FROM `" . DB_PREFIX . "order_product` op
			WHERE op.order_id IN (" . implode(',', $ids) . ")")->rows : array();

		$this->respond(200, array('rows' => $rows));
	}

	private function input() {
		$data = json_decode((string)file_get_contents('php://input'), true);

		return is_array($data) ? $data : array();
	}

	/**
	 * A list of positive integer IDs from the request body (never interpolated unchecked into SQL).
	 */
	private function ids($key) {
		$input = $this->input();
		$ids = isset($input[$key]) && is_array($input[$key]) ? $input[$key] : array();

		return array_values(array_unique(array_filter(array_map('intval', $ids), function ($id) {
			return $id > 0;
		})));
	}

	private function authorized() {
		$apiKey = (string)$this->config->get('module_finovo_api_key');
		$secret = (string)$this->config->get('module_finovo_api_secret');

		if (!$this->config->get('module_finovo_status') || $apiKey === '' || $secret === '') {
			$this->respond(503, array('status' => 'error', 'message' => 'The Finovo module is not connected.'));

			return false;
		}

		$sentKey = $this->header('X-Bridge-Api-Key');
		$timestamp = $this->header('X-Bridge-Timestamp');
		$nonce = $this->header('X-Bridge-Nonce');
		$signature = $this->header('X-Bridge-Signature');
		$body = (string)file_get_contents('php://input');
		$expected = hash_hmac('sha256', $timestamp . $nonce . $body, $secret);

		if (!hash_equals($apiKey, $sentKey) || !ctype_digit($timestamp) || abs(time() - (int)$timestamp) > self::MAX_AGE_SECONDS || $nonce === '' || !hash_equals($expected, $signature)) {
			$this->respond(401, array('status' => 'error', 'message' => 'Invalid Finovo signature.'));

			return false;
		}

		return true;
	}

	private function header($name) {
		$key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));

		return isset($this->request->server[$key]) ? (string)$this->request->server[$key] : '';
	}

	private function respond($code, array $data) {
		$messages = array(200 => 'OK', 401 => 'Unauthorized', 503 => 'Service Unavailable');

		$this->response->addHeader('HTTP/1.1 ' . $code . ' ' . $messages[$code]);
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}
}
