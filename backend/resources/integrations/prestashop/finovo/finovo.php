<?php
/**
 * Finovo OMS connector for PrestaShop 1.7, 8 and 9.
 *
 * Installing the module creates a webservice key for Finovo and sends it to the Finovo store that
 * generated this download, so the merchant connects the shop without copying any keys.
 *
 * The module is also the payment module of orders Finovo creates in this shop (phone, walk-in or wholesale
 * orders taken in Finovo): they are recorded with the cart's own total, like any checkout.
 *
 * It tells Finovo the moment an order or product changes (signed with the webservice key), so Finovo imports it
 * at once instead of waiting for its next sync.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Finovo extends PaymentModule
{
    const KEY_SETTING = 'FINOVO_WEBSERVICE_KEY_ID';

    /** Where Finovo receives this shop's notifications (sent by Finovo when the shop connects). */
    const WEBHOOK_SETTING = 'FINOVO_WEBHOOK_URL';

    /** Shop events Finovo is told about. */
    const HOOKS = [
        'actionValidateOrder',
        'actionOrderStatusPostUpdate',
        'actionOrderEdited',
        'actionProductAdd',
        'actionProductUpdate',
        'actionProductDelete',
    ];

    /** Webservice resources Finovo reads or updates. */
    const PERMISSIONS = [
        'products' => ['GET', 'HEAD', 'POST', 'PUT', 'DELETE'],
        'combinations' => ['GET', 'HEAD', 'POST', 'PUT', 'DELETE'],
        'product_options' => ['GET', 'HEAD', 'POST'],
        'product_option_values' => ['GET', 'HEAD', 'POST'],
        'categories' => ['GET', 'HEAD'],
        'stock_availables' => ['GET', 'HEAD', 'PUT'],
        'orders' => ['GET', 'HEAD', 'POST'],
        'order_details' => ['GET', 'HEAD'],
        'order_histories' => ['GET', 'HEAD', 'POST'],
        'order_states' => ['GET', 'HEAD'],
        'order_carriers' => ['GET', 'HEAD', 'PUT'],
        'customers' => ['GET', 'HEAD', 'POST'],
        'addresses' => ['GET', 'HEAD', 'POST', 'PUT'],
        'carts' => ['GET', 'HEAD', 'POST'],
        'countries' => ['GET', 'HEAD'],
        'currencies' => ['GET', 'HEAD'],
        'languages' => ['GET', 'HEAD'],
        'carriers' => ['GET', 'HEAD'],
        'messages' => ['GET', 'HEAD', 'POST'],
    ];

    public function __construct()
    {
        $this->name = 'finovo';
        $this->tab = 'market_place';
        $this->version = '1.3.0';
        $this->author = 'Finovo';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7.0.0', 'max' => _PS_VERSION_];
        $this->bootstrap = true;
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';

        parent::__construct();

        $this->displayName = $this->l('Finovo OMS Connector');
        $this->description = $this->l('Connects this shop to Finovo so orders and stock sync automatically.');
        $this->confirmUninstall = $this->l('Finovo will stop receiving orders from this shop. Continue?');
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        $error = $this->connect();

        if ($error !== null) {
            $this->_errors[] = $error;
            parent::uninstall();

            return false;
        }

        return true;
    }

    public function uninstall()
    {
        $this->removeWebserviceKey();
        Configuration::deleteByName(self::KEY_SETTING);
        Configuration::deleteByName(self::WEBHOOK_SETTING);

        return parent::uninstall();
    }

    /**
     * Orders Finovo creates through the webservice are recorded with the cart's own total (products, carrier
     * and taxes as this shop works them out), so PrestaShop never flags them as a payment error.
     */
    public function validateOrder(
        $id_cart,
        $id_order_state,
        $amount_paid,
        $payment_method = 'Unknown',
        $message = null,
        $extra_vars = [],
        $currency_special = null,
        $dont_touch_amount = false,
        $secure_key = false,
        $shop = null,
        $order_reference = null
    ) {
        $cart = new Cart((int) $id_cart);
        $arguments = [$id_cart, $id_order_state, (float) $cart->getOrderTotal(true, Cart::BOTH), $payment_method, $message, $extra_vars, $currency_special, $dont_touch_amount, $secure_key, $shop];

        if ($order_reference !== null) {
            $arguments[] = $order_reference;
        }

        return parent::validateOrder(...$arguments);
    }

    public function hookActionValidateOrder($params)
    {
        $this->notify('order.updated', isset($params['order']) ? $params['order']->id : 0);
    }

    public function hookActionOrderStatusPostUpdate($params)
    {
        $this->notify('order.updated', isset($params['id_order']) ? $params['id_order'] : 0);
    }

    public function hookActionOrderEdited($params)
    {
        $this->notify('order.updated', isset($params['order']) ? $params['order']->id : 0);
    }

    public function hookActionProductAdd($params)
    {
        $this->notify('product.updated', isset($params['id_product']) ? $params['id_product'] : 0);
    }

    public function hookActionProductUpdate($params)
    {
        $this->notify('product.updated', isset($params['id_product']) ? $params['id_product'] : 0);
    }

    public function hookActionProductDelete($params)
    {
        $this->notify('product.deleted', isset($params['id_product']) ? $params['id_product'] : 0);
    }

    /**
     * Tell Finovo a record changed. Signed with the webservice key; a slow or failed call never holds up the shop
     * (Finovo's regular sync picks the change up anyway).
     */
    private function notify($topic, $id)
    {
        $url = (string) Configuration::get(self::WEBHOOK_SETTING);
        $key = $this->currentWebserviceKey();

        if ($url === '' || $key === null || (int) $id <= 0 || !function_exists('curl_init')) {
            return;
        }

        $body = json_encode(['id' => (int) $id]);
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'X-Finovo-Topic: ' . $topic,
                'X-Finovo-Delivery: ' . Tools::passwdGen(24, 'ALPHANUMERIC'),
                'X-Finovo-Signature: ' . base64_encode(hash_hmac('sha256', $body, $key->key, true)),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 3,
            CURLOPT_TIMEOUT => 5,
        ]);
        curl_exec($curl);
        curl_close($curl);
    }

    /**
     * Module configuration page: connection status and a button to connect again.
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitFinovoReconnect')) {
            $error = $this->connect();
            $output .= $error === null
                ? $this->displayConfirmation($this->l('This shop is connected to Finovo.'))
                : $this->displayError($error);
        }

        $connected = $this->currentWebserviceKey() !== null;
        $action = AdminController::$currentIndex.'&configure='.$this->name.'&token='.Tools::getAdminTokenLite('AdminModules');

        return $output.'
            <div class="panel">
                <h3>'.$this->escape($this->displayName).'</h3>
                <p>'.$this->escape($connected
                    ? $this->l('Status: connected. Finovo reads orders and products, and updates orders, statuses and stock for this shop.')
                    : $this->l('Status: not connected.')).'</p>
                <form method="post" action="'.$this->escape($action).'">
                    <button type="submit" name="submitFinovoReconnect" class="btn btn-primary">'
                        .$this->escape($connected ? $this->l('Connect again') : $this->l('Connect to Finovo')).
                    '</button>
                </form>
            </div>';
    }

    /**
     * Create a webservice key, send it to Finovo and keep it only if Finovo accepts it.
     *
     * @return string|null error message, or null when connected
     */
    private function connect()
    {
        $settings = $this->connectionSettings();

        if ($settings === null) {
            return $this->l('This module file is not linked to a Finovo store. Download it again from Finovo (Stores → Connect).');
        }

        Configuration::updateValue('PS_WEBSERVICE', 1);

        $key = $this->createWebserviceKey();

        if ($key === null) {
            return $this->l('Could not create a webservice key for Finovo.');
        }

        $response = $this->post($settings['callback_url'], [
            'state' => $settings['state'],
            'webservice_key' => $key->key,
            'shop_url' => $this->context->shop->getBaseURL(true),
            'notifications' => true,
        ]);

        if (!$response['ok']) {
            $key->delete();

            return sprintf($this->l('Finovo could not connect this shop: %s'), $response['message']);
        }

        $this->removeWebserviceKey();
        Configuration::updateValue(self::KEY_SETTING, (int) $key->id);
        Configuration::updateValue(self::WEBHOOK_SETTING, $response['webhook_url']);

        // Hooks are (re)registered here too, so "Connect again" after an upgrade switches notifications on.
        foreach (self::HOOKS as $hook) {
            $this->registerHook($hook);
        }

        return null;
    }

    /**
     * @return array{callback_url: string, state: string}|null
     */
    private function connectionSettings()
    {
        $file = dirname(__FILE__).'/connection.php';

        if (!is_file($file)) {
            return null;
        }

        $settings = include $file;

        if (!is_array($settings) || empty($settings['callback_url']) || empty($settings['state'])) {
            return null;
        }

        return $settings;
    }

    /**
     * @return WebserviceKey|null
     */
    private function createWebserviceKey()
    {
        $key = new WebserviceKey();
        $key->key = Tools::strtoupper(Tools::passwdGen(32, 'ALPHANUMERIC'));
        $key->description = 'Finovo OMS';
        $key->active = 1;

        if (!$key->add()) {
            return null;
        }

        $permissions = [];
        foreach (self::PERMISSIONS as $resource => $methods) {
            foreach ($methods as $method) {
                $permissions[$resource][$method] = 'on';
            }
        }

        WebserviceKey::setPermissionForAccount($key->id, $permissions);

        return $key;
    }

    /**
     * @return WebserviceKey|null
     */
    private function currentWebserviceKey()
    {
        $id = (int) Configuration::get(self::KEY_SETTING);

        if ($id <= 0) {
            return null;
        }

        $key = new WebserviceKey($id);

        return Validate::isLoadedObject($key) ? $key : null;
    }

    private function removeWebserviceKey()
    {
        $key = $this->currentWebserviceKey();

        if ($key !== null) {
            $key->delete();
        }
    }

    /**
     * @return array{ok: bool, message: string, webhook_url: string}
     */
    private function post($url, array $payload)
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'message' => $this->l('the PHP curl extension is not enabled on this server.')];
        }

        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 60,
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            return ['ok' => false, 'message' => $error !== '' ? $error : $this->l('no response from Finovo.')];
        }

        $json = json_decode($body, true);
        $message = is_array($json) && isset($json['message']) ? (string) $json['message'] : 'HTTP '.$status;
        $ok = $status >= 200 && $status < 300 && is_array($json) && isset($json['status']) && $json['status'] === 'success';
        $webhookUrl = is_array($json) && !empty($json['webhook_url']) ? (string) $json['webhook_url'] : '';

        return ['ok' => $ok, 'message' => $message, 'webhook_url' => $webhookUrl];
    }

    private function escape($text)
    {
        return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
    }
}
