<?php
namespace Finovo;

/**
 * Sends a change notification to Finovo, signed with the module's API secret (HMAC-SHA256 of the body).
 * A slow or failed call never holds up the shop; Finovo's regular sync picks the change up anyway.
 */
class Notifier {
	/**
	 * @param \Config $config
	 * @param string $topic order.updated, product.updated or product.deleted
	 * @param int $id
	 */
	public static function send($config, $topic, $id) {
		$url = (string)$config->get('module_finovo_webhook_url');
		$secret = (string)$config->get('module_finovo_api_secret');

		if ($url === '' || $secret === '' || (int)$id <= 0 || !$config->get('module_finovo_status') || !function_exists('curl_init')) {
			return;
		}

		$body = json_encode(array('id' => (int)$id));
		$curl = curl_init($url);

		curl_setopt_array($curl, array(
			CURLOPT_POST => true,
			CURLOPT_POSTFIELDS => $body,
			CURLOPT_HTTPHEADER => array(
				'Content-Type: application/json',
				'X-Finovo-Topic: ' . $topic,
				'X-Finovo-Delivery: ' . bin2hex(random_bytes(12)),
				'X-Finovo-Signature: ' . base64_encode(hash_hmac('sha256', $body, $secret, true)),
			),
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 3,
			CURLOPT_TIMEOUT => 5,
		));

		curl_exec($curl);
		curl_close($curl);
	}
}
