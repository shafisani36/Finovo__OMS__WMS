<?php
/**
 * Endpoints Finovo calls on this shop: index.php?route=extension/finovo/api/<action>.
 * Reading: health, products, option_values, orders, order_products, order_statuses, order_status.
 * Writing: add_order_history, set_stock, create_order (through OpenCart's own order code, so the shop's emails
 * and stock rules apply), save_product, delete_product. Request bodies are JSON.
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
	 * Products in pages: {offset, limit}, or the products {product_ids}. Also returns the public image folder URL.
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
			" . (($ids = $this->ids('product_ids')) ? "WHERE p.product_id IN (" . implode(',', $ids) . ")" : '') . "
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
	 * Orders with a status, oldest first: {modified_since, offset, limit}, or the orders {order_ids}.
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
		$ids = $this->ids('order_ids');
		$since .= $ids ? " AND o.order_id IN (" . implode(',', $ids) . ")" : '';

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

	/**
	 * The shop's order statuses in its default language.
	 */
	public function order_statuses() {
		if (!$this->authorized()) {
			return;
		}

		$query = $this->db->query("SELECT order_status_id, name FROM `" . DB_PREFIX . "order_status` WHERE language_id = '" . (int)$this->config->get('config_language_id') . "' ORDER BY order_status_id");

		$this->respond(200, array('rows' => $query->rows));
	}

	/**
	 * The current status of an order: {order_id}.
	 */
	public function order_status() {
		if (!$this->authorized()) {
			return;
		}

		$input = $this->input();
		$query = $this->db->query("SELECT order_status_id FROM `" . DB_PREFIX . "order` WHERE order_id = '" . (int)($input['order_id'] ?? 0) . "'");

		if (!$query->num_rows) {
			$this->respond(422, array('status' => 'error', 'message' => 'The order does not exist.'));

			return;
		}

		$this->respond(200, array('order_status_id' => (int)$query->row['order_status_id']));
	}

	/**
	 * Move an order to a status with a comment: {order_id, order_status_id, comment, notify}.
	 */
	public function add_order_history() {
		if (!$this->authorized()) {
			return;
		}

		$input = $this->input();
		$orderId = (int)($input['order_id'] ?? 0);
		$statusId = (int)($input['order_status_id'] ?? 0);

		if (!$this->orderExists($orderId) || !$this->statusExists($statusId)) {
			$this->respond(422, array('status' => 'error', 'message' => 'Unknown order or order status.'));

			return;
		}

		$this->load->model('checkout/order');
		$this->model_checkout_order->addOrderHistory($orderId, $statusId, (string)($input['comment'] ?? ''), !empty($input['notify']));

		$this->respond(200, array('status' => 'ok'));
	}

	/**
	 * Set the stock of a product or of one of its option values: {product_id, product_option_value_id, quantity}.
	 */
	public function set_stock() {
		if (!$this->authorized()) {
			return;
		}

		$input = $this->input();
		$quantity = (int)($input['quantity'] ?? 0);

		if (!empty($input['product_option_value_id'])) {
			$this->db->query("UPDATE `" . DB_PREFIX . "product_option_value` SET quantity = '" . $quantity . "' WHERE product_option_value_id = '" . (int)$input['product_option_value_id'] . "'");
		} else {
			$this->db->query("UPDATE `" . DB_PREFIX . "product` SET quantity = '" . $quantity . "' WHERE product_id = '" . (int)($input['product_id'] ?? 0) . "'");
		}

		$this->respond(200, array('status' => 'ok'));
	}

	/**
	 * Place an order taken in Finovo and give it its first status: {order, order_status_id, comment}.
	 * Amounts arrive in the order's currency and are stored in the shop's default currency, as OpenCart does.
	 */
	public function create_order() {
		if (!$this->authorized()) {
			return;
		}

		$input = $this->input();
		$order = isset($input['order']) && is_array($input['order']) ? $input['order'] : array();
		$statusId = (int)($input['order_status_id'] ?? 0);

		if (empty($order['products']) || !is_array($order['products']) || !$this->statusExists($statusId)) {
			$this->respond(422, array('status' => 'error', 'message' => 'The order has no products or an unknown status.'));

			return;
		}

		$currency = $this->db->query("SELECT * FROM `" . DB_PREFIX . "currency` WHERE code = '" . $this->db->escape((string)($order['currency_code'] ?? '')) . "'")->row;

		if (!$currency) {
			$currency = $this->db->query("SELECT * FROM `" . DB_PREFIX . "currency` WHERE code = '" . $this->db->escape((string)$this->config->get('config_currency')) . "'")->row;
		}

		$rate = $currency && (float)$currency['value'] > 0 ? (float)$currency['value'] : 1.0;
		$customer = isset($order['customer']) && is_array($order['customer']) ? $order['customer'] : array();
		$payment = $this->orderAddress('payment', isset($order['payment']) && is_array($order['payment']) ? $order['payment'] : array());
		$shipping = $this->orderAddress('shipping', isset($order['shipping']) && is_array($order['shipping']) ? $order['shipping'] : array());
		$server = !empty($this->request->server['HTTPS']) ? $this->config->get('config_ssl') : $this->config->get('config_url');

		$products = array();

		foreach ($order['products'] as $line) {
			$products[] = array(
				'product_id' => (int)($line['product_id'] ?? 0),
				'name' => (string)($line['name'] ?? ''),
				'model' => (string)($line['model'] ?? ''),
				'option' => $this->orderOptions((int)($line['product_option_value_id'] ?? 0)),
				'download' => array(),
				'quantity' => (int)($line['quantity'] ?? 0),
				'subtract' => 1,
				'price' => (float)($line['price'] ?? 0) / $rate,
				'total' => (float)($line['total'] ?? 0) / $rate,
				'tax' => (float)($line['tax'] ?? 0) / $rate,
				'reward' => 0,
			);
		}

		$totals = array();

		foreach (isset($order['totals']) && is_array($order['totals']) ? $order['totals'] : array() as $total) {
			$totals[] = array(
				'code' => (string)($total['code'] ?? ''),
				'title' => (string)($total['title'] ?? ''),
				'value' => (float)($total['value'] ?? 0) / $rate,
				'sort_order' => (int)($total['sort_order'] ?? 0),
			);
		}

		$data = array_merge(array(
			'invoice_prefix' => (string)$this->config->get('config_invoice_prefix'),
			'store_id' => 0,
			'store_name' => (string)$this->config->get('config_name'),
			'store_url' => $server ? $server : HTTP_SERVER,
			'customer_id' => 0,
			'customer_group_id' => (int)$this->config->get('config_customer_group_id'),
			'firstname' => (string)($customer['firstname'] ?? ''),
			'lastname' => (string)($customer['lastname'] ?? ''),
			'email' => (string)($customer['email'] ?? ''),
			'telephone' => (string)($customer['telephone'] ?? ''),
			'custom_field' => array(),
			'payment_method' => (string)($order['payment_method'] ?? 'Finovo'),
			'payment_code' => 'finovo',
			'shipping_method' => 'Shipping',
			'shipping_code' => 'finovo.finovo',
			'products' => $products,
			'vouchers' => array(),
			'comment' => (string)($order['comment'] ?? ''),
			'total' => (float)($order['total'] ?? 0) / $rate,
			'affiliate_id' => 0,
			'commission' => 0,
			'marketing_id' => 0,
			'tracking' => '',
			'language_id' => (int)$this->config->get('config_language_id'),
			'currency_id' => $currency ? (int)$currency['currency_id'] : 0,
			'currency_code' => $currency ? (string)$currency['code'] : '',
			'currency_value' => $rate,
			'ip' => '',
			'forwarded_ip' => '',
			'user_agent' => 'Finovo',
			'accept_language' => '',
			'totals' => $totals,
		), $payment, $shipping);

		$this->load->model('checkout/order');
		$orderId = (int)$this->model_checkout_order->addOrder($data);
		$this->model_checkout_order->addOrderHistory($orderId, $statusId, (string)($input['comment'] ?? ''), false);

		$this->respond(200, array('order_id' => $orderId));
	}

	/**
	 * payment_* or shipping_* fields of an order, with the country and zone this shop knows.
	 */
	private function orderAddress($type, array $address) {
		$country = $this->db->query("SELECT * FROM `" . DB_PREFIX . "country` WHERE iso_code_2 = '" . $this->db->escape(strtoupper((string)($address['country_code'] ?? ''))) . "'")->row;
		$zoneName = (string)($address['zone'] ?? '');
		$zone = $country && $zoneName !== '' ? $this->db->query("SELECT * FROM `" . DB_PREFIX . "zone` WHERE country_id = '" . (int)$country['country_id'] . "' AND (name = '" . $this->db->escape($zoneName) . "' OR code = '" . $this->db->escape($zoneName) . "')")->row : array();

		return array(
			$type . '_firstname' => (string)($address['firstname'] ?? ''),
			$type . '_lastname' => (string)($address['lastname'] ?? ''),
			$type . '_company' => (string)($address['company'] ?? ''),
			$type . '_address_1' => (string)($address['address_1'] ?? ''),
			$type . '_address_2' => (string)($address['address_2'] ?? ''),
			$type . '_city' => (string)($address['city'] ?? ''),
			$type . '_postcode' => (string)($address['postcode'] ?? ''),
			$type . '_country' => $country ? (string)$country['name'] : (string)($address['country_code'] ?? ''),
			$type . '_country_id' => $country ? (int)$country['country_id'] : 0,
			$type . '_zone' => $zone ? (string)$zone['name'] : $zoneName,
			$type . '_zone_id' => $zone ? (int)$zone['zone_id'] : 0,
			$type . '_address_format' => $country ? (string)$country['address_format'] : '',
			$type . '_custom_field' => array(),
		);
	}

	/**
	 * The chosen option value of an order line (e.g. Size: Large), as OpenCart's checkout records it.
	 */
	private function orderOptions($optionValueId) {
		if ($optionValueId <= 0) {
			return array();
		}

		$language = (int)$this->config->get('config_language_id');
		$row = $this->db->query("SELECT pov.product_option_id, pov.product_option_value_id, od.name, ovd.name AS value, o.type
			FROM `" . DB_PREFIX . "product_option_value` pov
			JOIN `" . DB_PREFIX . "option` o ON o.option_id = pov.option_id
			JOIN `" . DB_PREFIX . "option_description` od ON od.option_id = pov.option_id AND od.language_id = '" . $language . "'
			JOIN `" . DB_PREFIX . "option_value_description` ovd ON ovd.option_value_id = pov.option_value_id AND ovd.language_id = '" . $language . "'
			WHERE pov.product_option_value_id = '" . (int)$optionValueId . "'")->row;

		return $row ? array($row) : array();
	}

	private function orderExists($orderId) {
		return $orderId > 0 && $this->db->query("SELECT order_id FROM `" . DB_PREFIX . "order` WHERE order_id = '" . (int)$orderId . "'")->num_rows > 0;
	}

	private function statusExists($statusId) {
		return $statusId > 0 && $this->db->query("SELECT order_status_id FROM `" . DB_PREFIX . "order_status` WHERE order_status_id = '" . (int)$statusId . "' LIMIT 1")->num_rows > 0;
	}

	/**
	 * Create (no product_id) or update a product in the default store: {product}. A product with variants gets one
	 * select option whose values are the variants. Answers {product_id, option_values: {key: product_option_value_id}}.
	 */
	public function save_product() {
		if (!$this->authorized()) {
			return;
		}

		$input = $this->input();
		$product = isset($input['product']) && is_array($input['product']) ? $input['product'] : array();

		if (empty($product['name'])) {
			$this->respond(422, array('status' => 'error', 'message' => 'The product has no name.'));

			return;
		}

		$productId = (int)($product['product_id'] ?? 0);
		$fields = "model = '" . $this->db->escape((string)($product['model'] ?? '')) . "', sku = '" . $this->db->escape((string)($product['sku'] ?? '')) . "', price = '" . (float)($product['price'] ?? 0) . "', status = '" . (int)!empty($product['status']) . "', date_modified = NOW()";
		$name = $this->db->escape((string)$product['name']);
		$description = $this->db->escape((string)($product['description'] ?? ''));

		if ($productId > 0 && $this->db->query("SELECT product_id FROM `" . DB_PREFIX . "product` WHERE product_id = '" . $productId . "'")->num_rows) {
			$this->db->query("UPDATE `" . DB_PREFIX . "product` SET " . $fields . " WHERE product_id = '" . $productId . "'");
			$this->db->query("UPDATE `" . DB_PREFIX . "product_description` SET name = '" . $name . "', description = '" . $description . "', meta_title = '" . $name . "' WHERE product_id = '" . $productId . "'");
		} else {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "product` SET " . $fields . ", quantity = '0', stock_status_id = '" . (int)$this->config->get('config_stock_status_id') . "', shipping = '1', subtract = '1', minimum = '1', date_available = CURDATE(), date_added = NOW()");
			$productId = (int)$this->db->getLastId();

			foreach ($this->languageIds() as $languageId) {
				$this->db->query("INSERT INTO `" . DB_PREFIX . "product_description` SET product_id = '" . $productId . "', language_id = '" . (int)$languageId . "', name = '" . $name . "', description = '" . $description . "', tag = '', meta_title = '" . $name . "', meta_description = '', meta_keyword = ''");
			}

			$this->db->query("INSERT INTO `" . DB_PREFIX . "product_to_store` SET product_id = '" . $productId . "', store_id = '0'");
		}

		$values = isset($product['values']) && is_array($product['values']) ? $product['values'] : array();
		$optionValues = $values ? $this->saveOptionValues($productId, (string)($product['option_name'] ?? 'Option'), $values) : array();

		$this->cache->delete('product');
		$this->respond(200, array('product_id' => $productId, 'option_values' => (object)$optionValues));
	}

	/**
	 * Delete a product from the shop: {product_id}.
	 */
	public function delete_product() {
		if (!$this->authorized()) {
			return;
		}

		$input = $this->input();
		$productId = (int)($input['product_id'] ?? 0);

		foreach (array('product', 'product_description', 'product_to_store', 'product_to_category', 'product_option', 'product_option_value', 'product_image', 'product_discount', 'product_special', 'product_attribute', 'product_related', 'product_reward', 'product_to_layout', 'product_to_download', 'product_filter') as $table) {
			$this->db->query("DELETE FROM `" . DB_PREFIX . $table . "` WHERE product_id = '" . $productId . "'");
		}

		$this->cache->delete('product');
		$this->respond(200, array('status' => 'ok'));
	}

	/**
	 * The product's select option and its values; values no longer sold are removed.
	 */
	private function saveOptionValues($productId, $optionName, array $values) {
		$optionId = $this->namedId('option', 'option_id', $optionName, 0);
		$productOption = $this->db->query("SELECT product_option_id FROM `" . DB_PREFIX . "product_option` WHERE product_id = '" . (int)$productId . "' AND option_id = '" . (int)$optionId . "'")->row;

		if ($productOption) {
			$productOptionId = (int)$productOption['product_option_id'];
		} else {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "product_option` SET product_id = '" . (int)$productId . "', option_id = '" . (int)$optionId . "', value = '', required = '1'");
			$productOptionId = (int)$this->db->getLastId();
		}

		$ids = array();

		foreach ($values as $value) {
			$diff = (float)($value['price_diff'] ?? 0);
			$price = "price = '" . abs($diff) . "', price_prefix = '" . ($diff < 0 ? '-' : '+') . "'";
			$existingId = (int)($value['product_option_value_id'] ?? 0);

			if ($existingId > 0 && $this->db->query("SELECT product_option_value_id FROM `" . DB_PREFIX . "product_option_value` WHERE product_option_value_id = '" . $existingId . "' AND product_id = '" . (int)$productId . "'")->num_rows) {
				$this->db->query("UPDATE `" . DB_PREFIX . "product_option_value` SET " . $price . " WHERE product_option_value_id = '" . $existingId . "'");
				$ids[(string)$value['key']] = $existingId;

				continue;
			}

			$optionValueId = $this->namedId('option_value', 'option_value_id', (string)($value['name'] ?? ''), $optionId);
			$this->db->query("INSERT INTO `" . DB_PREFIX . "product_option_value` SET product_option_id = '" . $productOptionId . "', product_id = '" . (int)$productId . "', option_id = '" . (int)$optionId . "', option_value_id = '" . (int)$optionValueId . "', quantity = '0', subtract = '1', " . $price . ", points = '0', points_prefix = '+', weight = '0', weight_prefix = '+'");
			$ids[(string)$value['key']] = (int)$this->db->getLastId();
		}

		$kept = $ids ? implode(',', array_map('intval', $ids)) : '0';
		$this->db->query("DELETE FROM `" . DB_PREFIX . "product_option_value` WHERE product_option_id = '" . $productOptionId . "' AND product_option_value_id NOT IN (" . $kept . ")");

		return $ids;
	}

	/**
	 * The ID of an option (or an option's value) by name, created in every language when the shop has none.
	 */
	private function namedId($table, $key, $name, $optionId) {
		$language = (int)$this->config->get('config_language_id');
		$filter = $optionId ? " AND option_id = '" . (int)$optionId . "'" : '';
		$found = $this->db->query("SELECT `" . $key . "` AS id FROM `" . DB_PREFIX . $table . "_description` WHERE language_id = '" . $language . "' AND name = '" . $this->db->escape($name) . "'" . $filter . " LIMIT 1")->row;

		if ($found) {
			return (int)$found['id'];
		}

		if ($table === 'option') {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "option` SET type = 'select', sort_order = '0'");
		} else {
			$this->db->query("INSERT INTO `" . DB_PREFIX . "option_value` SET option_id = '" . (int)$optionId . "', image = '', sort_order = '0'");
		}

		$id = (int)$this->db->getLastId();

		foreach ($this->languageIds() as $languageId) {
			$this->db->query("INSERT INTO `" . DB_PREFIX . $table . "_description` SET `" . $key . "` = '" . $id . "', language_id = '" . (int)$languageId . "'" . ($optionId ? ", option_id = '" . (int)$optionId . "'" : '') . ", name = '" . $this->db->escape($name) . "'");
		}

		return $id;
	}

	private function languageIds() {
		$ids = array();

		foreach ($this->db->query("SELECT language_id FROM `" . DB_PREFIX . "language`")->rows as $row) {
			$ids[] = (int)$row['language_id'];
		}

		return $ids ? $ids : array((int)$this->config->get('config_language_id'));
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
		$messages = array(200 => 'OK', 401 => 'Unauthorized', 422 => 'Unprocessable Entity', 503 => 'Service Unavailable');

		$this->response->addHeader('HTTP/1.1 ' . $code . ' ' . $messages[$code]);
		$this->response->addHeader('Content-Type: application/json');
		$this->response->setOutput(json_encode($data));
	}
}
