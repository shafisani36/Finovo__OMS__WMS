<?php
/**
 * Tells Finovo the moment an order is placed or its status changes (event catalog/model/checkout/order/addOrderHistory/after,
 * registered by the Finovo module), so Finovo imports it at once.
 */
class ControllerExtensionFinovoNotify extends Controller {
	public function order(&$route, &$args, &$output) {
		\Finovo\Notifier::send($this->config, 'order.updated', isset($args[0]) ? (int)$args[0] : 0);
	}
}
