<?php
/*
Plugin Name: BB Payments System Gateway
Description: Provides a BB Payments System Gateway
Author: BB
Version: 0.1.5
*/

/* DEBUG
echo "<pre>";
echo "UserKey: ", print_r($user_key, true);
echo "OrderId: ", print_r($order_id, true);
echo "URL: ", print_r($url, true);
echo "IPN response: " . print_r($response, true);
echo "</pre>";
exit();
*/

// Exit if accessed directly
if (!defined('ABSPATH')) exit;

/**
 * WooCommerce fallback notice.
 */
function wcbb_payments_woocommerce_fallback_notice()
{
    $html = '<div class="error">';
    $html .= '<p>' . __('WooCommerce BB Payments Gateway depends on the last version of <a href="http://wordpress.org/extend/plugins/woocommerce/">WooCommerce</a> to work!', 'woocommerce') . '</p>';
    $html .= '</div>';

    echo $html;
}

add_action('plugins_loaded', 'wc_bb_payments_gateway_load');

function wc_bb_payments_gateway_load()
{
    if (!class_exists('WC_Payment_Gateway')) {
        add_action('admin_notices', 'wcbb_payments_woocommerce_fallback_notice');
        return;
    }

    /**
     * Add the gateway to WooCommerce.
     *
     * @access public
     * @param array $methods
     * @return array
     */
    add_filter('woocommerce_payment_gateways', 'wc_bb_payments_add_gateway');

    function wc_bb_payments_add_gateway($methods)
    {
        $methods[] = 'WC_Gateway_BB_Payments';
        return $methods;
    }

    /**
     * BB Payments System Gateway Class
     *
     * Provides a BB Payments System Gateway.
     *
     * @class        WC_BB_Payments
     * @extends      WC_Gateway_BB_Payments
     * @version      0.2.0
     * @package      WooCommerce/Classes/Payment
     * @author       BB
     */
    class WC_Gateway_BB_Payments extends WC_Payment_Gateway
    {
        /**
         * Constructor for the gateway.
         *
         * @access public
         * @return void
         */
        public function __construct()
        {
            $this->id = 'bb_payments';
            $this->icon = plugins_url('images/bb_payments.png', __FILE__);
            $this->has_fields = false;

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            // Required:
            $this->liveurl = $this->get_option('liveurl');
            $this->confirm_url = $this->get_option('confirm_url');
            $this->genericSKU = $this->get_option('genericSKU');
            $this->organization = $this->get_option('organization');
            if ($this->organization == "") {
                $this->organization = "ben2";
            }
            // Optional:
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->testmode = $this->get_option('testmode');
            $this->prefix = $this->get_option('prefix');
            $this->debug = true; //$this->get_option('debug');

            // Validate required parameters
            if (empty($this->liveurl)) {
                add_action('admin_notices', array($this, 'app_url_missing_message'));
            }
            if (empty($this->confirm_url)) {
                add_action('admin_notices', array($this, 'confirm_url_missing_message'));
            }

            // Logs
            if ($this->debug)
                $this->log = new WC_Logger();

            // Payment listener/API hook
            add_action('woocommerce_api_wc_gateway_bb_payments', array($this, 'check_ipn_response'));

            // Actions
            add_action('valid_bb_payments_ipn_request', array($this, 'successful_request'));
            add_action('woocommerce_receipt_bb_payments', array($this, 'receipt_page'));

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            $this->enabled = (('yes' == $this->get_option('enabled')) && $this->is_valid_for_use()) ? 'yes' : 'no';

            // PayPal Valid IPN hook
            add_action('valid-paypal-standard-ipn-request', array($this, 'wc_bb_valid_paypal_ipn_request'));

            add_action('woocommerce_cancelled_order', array($this, 'wc_bb_cancelled_request'));
        }

        public function wc_bb_cancelled_request($order_id)
        {
            $order = wc_get_order($order_id);
            $received_values = stripslashes_deep($_GET);
            if ($received_values['error']) {
                $message = $received_values['error'];
                $order->add_order_note("Error: " . $message);
                $redirect_url = $order->get_cancel_order_url();
                wp_redirect($redirect_url);
                exit;
            }
        }

        /**
         * Check if this gateway is enabled and available in the user's country
         *
         * @access public
         * @return bool
         */
        function is_valid_for_use()
        {
            return (!in_array(get_woocommerce_currency(), apply_filters('woocommerce_bb_payments_supported_currencies', array('USD', 'ILS', 'EUR')))) ? false : true;
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         *
         * @since 1.0.0
         */
        public function admin_options()
        {
            ?>
            <h3><?php _e('BB Payments', 'woocommerce'); ?></h3>
            <p><?php _e('BB Payments works by sending the user to BB Payments to enter their payment information.', 'woocommerce'); ?></p>

            <?php if ($this->is_valid_for_use()) : ?>

            <table class="form-table">
                <?php
                // Generate the HTML For the settings form.
                $this->generate_settings_html();
                ?>
            </table>

        <?php else : ?>
            <div class="inline error"><p>
                    <strong><?php _e('Gateway Disabled', 'woocommerce'); ?></strong>: <?php _e('BB Payments does not support your store currency.', 'woocommerce'); ?>
                </p></div>
        <?php
        endif;
        }

        /**
         * Initialise Gateway Settings Form Fields
         *
         * @access public
         * @return void
         */
        function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable BB Payments', 'woocommerce'),
                    'default' => 'yes'),
                'title' => array(
                    'title' => __('Title', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                    'default' => __('BB Payments', 'woocommerce'),
                    'desc_tip' => true),
                'description' => array(
                    'title' => __('Description', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
                    'default' => __('Pay via BB Payments', 'woocommerce'),
                    'desc_tip' => true),
                'confirm_url' => array(
                    'title' => __('Confirmation URL', 'wc_bb_payments'),
                    'type' => 'text',
                    'description' => __('Please enter your BB Payments Server Confirmation URL.', 'wcbb_payments'),
                    'default' => 'https://checkout.kabbalah.info/en/payments'),
                'liveurl' => array(
                    'title' => __('Application URL', 'wc_bb_payments'),
                    'type' => 'text',
                    'description' => __('Please enter your BB Payments Server URL.', 'wcbb_payments'),
                    'default' => 'https://checkout.kabbalah.info/en/projects/bb_books/external_client'),
                'genericSKU' => array(
                    'title' => __('Generic SKU', 'woocommerce'),
                    'type' => 'text',
                    'label' => __('Generic SKU.', 'woocommerce'),
                    'description' => __('This SKU will be used for every payment without SKU.', 'woocommerce'),
                    'default' => ''),
                'organization' => array(
                    'title' => __('Organization', 'woocommerce'),
                    'type' => 'text',
                    'label' => __('Organization', 'woocommerce'),
                    'description' => __('Options: ben2, meshp18', 'woocommerce'),
                    'default' => 'ben2'),
                'prefix' => array(
                    'title' => __('Prefix for order reference', 'woocommerce'),
                    'type' => 'text',
                    'label' => __('like 66b- for 66books.co.il', 'woocommerce'),
                    'description' => __('This prefix will be used as a part of order reference in payment gateway and in invoice provider', 'woocommerce'),
                    'default' => 'ext-'),
                'testmode' => array(
                    'title' => __('Test Mode', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable BB Payments testmode', 'woocommerce'),
                    'default' => 'no',
                    'description' => __('BB Payments testmode can be used to test payments.', 'woocommerce')),
                'debug' => array(
                    'title' => __('Debug Log', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable logging', 'woocommerce'),
                    'default' => 'no',
                    'description' => sprintf(__('Log BB Payments events, such as IPN requests, inside <code>woocommerce/logs/bb_payments-%s.txt</code>', 'woocommerce'), sanitize_file_name(wp_hash('bb_payments'))))
            );
        }

        function find_sku($order)
        {
            // How to find SKUs:
            // https://businessbloomer.com/woocommerce-easily-get-product-info-title-sku-desc-product-object/
            $sku = '';
            $items = $order->get_items();
            foreach ($items as $item) {
                $product = wc_get_product($item['product_id']);
                $sku = $product->get_sku();
                // TODO: how to find all SKUs and what to do with them?
                // For now -- stop after the first found one
                if ($sku != '') {
                    break;
                }
            }

            if ($sku == '') {
                $sku = $this->genericSKU;
            }

            return $sku;
        }

        /**
         * Get Args for passing to BB Payments
         *
         * @access public
         * @param mixed $order
         * @return array
         */
        function get_payment_args($order)
        {
            $order_key = $order->order_key;
            $order_id = $order->get_order_number();

            $this->log_message('Generating payment form for order ' . $order_id);

            $locale = get_locale();
            if (ICL_LANGUAGE_CODE == 'he' || $locale == 'he_IL') {
                $language = 'HE';
            } else if (ICL_LANGUAGE_CODE == 'ru' || $locale == 'ru_RU') {
                $language = 'RU';
            } else if (ICL_LANGUAGE_CODE == 'es'
                || $locale == 'es_AR'
                || $locale == 'es_CL'
                || $locale == 'es_CO'
                || $locale == 'es_MX'
                || $locale == 'es_PE'
                || $locale == 'es_PR'
                || $locale == 'es_ES'
                || $locale == 'es_VE'
            ) {
                $language = 'ES';
            } else {
                $language = 'EN';
            }

            $sku = $this->find_sku($order);

            $args = array(
                'UserKey' => $this->user_key($order_id, $order_key),
                'GoodURL' => str_replace('https:', 'http:', add_query_arg('wc-api', 'WC_Gateway_BB_Payments', home_url('/'))),
                'ErrorURL' => $order->get_cancel_order_url(),
                'CancelURL' => $order->get_cancel_order_url(),

                'Name' => $order->get_formatted_billing_full_name(),
                'Price' => number_format($order->get_total(), 2, '.', ''),
                'Currency' => get_woocommerce_currency(),
                'Email' => $order->billing_email,
                'Phone' => $order->billing_phone,
                'Street' => $order->billing_address_1 . ' ' . $order->billing_houseno, // TODO: $order->get_formatted_billing_address(),
                'City' => $order->billing_city,
                'Country' => $order->billing_country,
                'Participants' => 1,
                'SKU' => $sku,
                'VAT' => 'N',
                'Installments' => 1,
                'Language' => $language,
                'Reference' => $this->user_key($order_id, $order_key, true),
                'Organization' => $this->organization,
                'IsVisual' => false,
            );

            $item_names = array();
            $items = $order->get_items();
            if (sizeof($items) > 0) {
                foreach ($items as $item) {
                    if ($item['qty']) {
                        $item_names[] = '"' . $item['name'] . '" x ' . $item['qty'];
                    }
                }
            }

            $args['Details'] = implode(', ', $item_names);

            $args = apply_filters('woocommerce_bb_payments_args', $args);

            return $args;
        }

        /**
         * Generate the button link
         *
         * @access public
         * @param mixed $order_id
         * @return string
         */
        function generate_form($order_id)
        {
            global $woocommerce;
            $this->log_message('generate_form');
            $order = new WC_Order($order_id);

            $addr = $this->liveurl;
            $args = $this->get_payment_args($order);

            $this->log_message('Processing payment via GET(' . $addr . ')');
            $this->log_message('Payment arguments for order #' . $order_id . ': ' . print_r($args, true));

            $args_array = array();
            if ($this->testmode == 'yes')
                $args_array[] = '<input type="hidden" name="test_mode" value="1" />';

            foreach ($args as $key => $value) {
                $args_array[] = '<input type="hidden" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '" />';
            }

            $woocommerce->add_inline_js('
				jQuery("body").block({
						message: "' . esc_js(__('Thank you for your order. We are now redirecting you to BB Payments to make payment.', 'woocommerce')) . '",
						baseZ: 99999,
						overlayCSS:
						{
							background: "#fff",
							opacity: 0.6
						},
						css: {
						padding:        "20px",
						zindex:         "9999999",
						textAlign:      "center",
						color:          "#555",
						border:         "3px solid #aaa",
						backgroundColor:"#fff",
						cursor:         "wait",
						lineHeight:		"24px",
					    }
					});
				jQuery("#submit_bb_payments_payment_form").click();
			');

            return '<form action="' . esc_url($addr) . '" method="post" id="bb_payments_payment_form" target="_top">' .
                implode('', $args_array) .
                '<input type="submit" class="button alt" id="submit_bb_payments_payment_form" value="' . __('Pay via BB Payments', 'woocommerce') . '" /> <a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Cancel order &amp; restore cart', 'woocommerce') . '</a>' .
                '</form>';
        }

        /**
         * Process the payment and return the result
         *
         * @access public
         * @param int $order_id
         * @return array
         */
        function process_payment($order_id)
        {
            $order = new WC_Order($order_id);

            $args = $this->get_payment_args($order);
            if ($this->testmode == 'yes') $args['test_mode'] = '1';
            $args = http_build_query($args, '', '&');
            $addr = $this->liveurl;

            $this->log_message('Processing payment via GET...' . $addr . '?' . $args);

            return array('result' => 'success', 'redirect' => $addr . '?' . $args);
        }

        public function wc_bb_valid_paypal_ipn_request($posted)
        {
            $this->log_message("wc_bb_valid_paypal_ipn_request: " . print_r($posted, true));
            $locale = get_locale();
            if (ICL_LANGUAGE_CODE == 'he' || $locale == 'he_IL') {
                $language = 'HE';
            } else if (ICL_LANGUAGE_CODE == 'ru' || $locale == 'ru_RU') {
                $language = 'RU';
            } else {
                $language = 'EN';
            }

            $order = !empty($posted['custom']) ? $this->get_paypal_order($posted['custom']) : false;
            if ($order) {
                $item_names = array();
                $items = $order->get_items();
                if (sizeof($items) > 0) {
                    foreach ($items as $item) {
                        if ($item['qty']) {
                            $item_names[] = '"' . $item['name'] . '" x ' . $item['qty'];
                        }
                    }
                }

                $details = implode(', ', $item_names);

                $confirm_url = str_replace('payments', 'paypal', $this->confirm_url);
                $custom = json_decode($posted['custom']);
                $order_id = $custom->order_id;
                $order_key = $custom->order_key;

                $url = $confirm_url .
                    // request
                    '?Name=' . $order->get_formatted_billing_full_name() .
                    '&Price=' . number_format($order->get_total(), 2, '.', '') .
                    '&Currency=' . $posted['mc_currency'] .
                    '&Email=' . $order->billing_email .
                    '&Phone=' . $order->billing_phone .
                    '&Street=' . $order->billing_address_1 . ' ' . $order->billing_houseno .
                    '&City=' . $order->billing_city .
                    '&Country=' . $order->billing_country .
                    '&Details=' . $details .
                    '&SKU=' . $this->find_sku($order) .
                    '&Language=' . $language .
                    '&Reference=' . $this->user_key($order_id, $order_key, true) .
                    '&Organization=' . $this->organization .
                    // response
                    '&TransactionId=' . $posted['txn_id'] .
                    '&PaymentDate=' . $posted['payment_date'] .
                    '&VoucherId=' . $posted['receiver_id'] .
                    '&Invoice=' . $posted['invoice'];
                $this->log_message("wc_bb_valid_paypal_ipn_request URL: " . print_r($url, true));
                $response = wp_remote_get($url);
                $this->log_message("wc_bb_valid_paypal_ipn_request RESPONSE: " . print_r($response, true));
            } else {
                $this->log_message('### SEND MESSAGE HERE!!!');
            }
        }

        /**
         * Get the order from the PayPal 'Custom' variable.
         *
         * @param string $raw_custom JSON Data passed back by PayPal.
         * @return bool|WC_Order object
         */
        protected function get_paypal_order($raw_custom)
        {
            // We have the data in the correct format, so get the order.
            $custom = json_decode($raw_custom);
            if ($custom && is_object($custom)) {
                $order_id = $custom->order_id;
                $order_key = $custom->order_key;
            } else {
                // Nothing was found.
                $this->log_message('Order ID and key were not found in "custom".');
                return false;
            }

            $order = wc_get_order($order_id);

            if (!$order) {
                // We have an invalid $order_id, probably because invoice_prefix has changed.
                $order_id = wc_get_order_id_by_order_key($order_key);
                $order = wc_get_order($order_id);
            }

            if (!$order || $order->get_order_key() !== $order_key) {
                $this->log_message('Order Keys do not match.');
                return false;
            }

            return $order;
        }

        /**
         * Output for the order received page.
         *
         * @return void
         */
        public function receipt_page($order)
        {
            $this->log_message('receipt_page');
            echo $this->generate_form($order);
        }

        function user_key($order_id, $order_key, $short = false)
        {
            if ($short) {
                return $this->settings["prefix"] . $order_id;
            } else {
                return $this->settings["prefix"] . "-" . $order_key . "-" . $order_id;
            }
        }

        /**
         * Check IPN validity
         **/
        function check_ipn_request_is_valid($data)
        {
            global $woocommerce;

            $this->log_message('Checking IPN response is valid...');

            // Get recieved values from post data
            $received_values = stripslashes_deep($data);
            $user_key = $received_values['user_key'];
            $parts = explode('-', $user_key);
            $order_id = end($parts);
            $order = new WC_Order($order_id);

            // Build request
            $order_key = $order->order_key;

            $sku = $this->find_sku($order);

            // Submit request and get response
            $url = $this->confirm_url . "?UserKey=" . $this->user_key($order_id, $order_key) .
                '&Price=' . number_format($order->get_total(), 2, '.', '') .
                '&Currency=' . get_woocommerce_currency() .
                '&SKU=' . $sku .
                '&Reference=' . $this->user_key($order_id, $order_key, true) .
                '&Organization=' . $this->organization;
            $response = wp_remote_get($url);
            $this->log_message("IPN response: " . print_r($response, true));

            // check to see if the request was valid
            if (is_wp_error($response) || $response['response']['code'] != 200) {
                $this->log_message('Received invalid response');
                $this->log_message("IPN response: " . print_r($response['body'], true));
                if (is_wp_error($response))
                    $this->log_message('Error response: ' . $response->get_error_message());

                $message = 'Received invalid response<br/>\n';
                $message = $message + 'Error response: ' . $response->get_error_message() + '<br/>\n';
                return array(false, $message);
            }

            // Decode and check response
            $params = array();
            $values = explode('&', $response['body']);
            foreach ($values as $p) {
                $parts = explode('=', $p, 2);
                $params[$parts[0]] = $parts[1];
            }

            // Check answer is SUCCESS
            $status = ($params['status'] == 'SUCCESS') ? TRUE : FALSE;

            $this->log_message('Received' . ($status ? ' ' : ' in') . 'valid response');

            return array($status, 'Received' . ($status ? ' ' : ' in') . 'valid response');
        }

        /**
         * Check for IPN Response
         *
         * @access public
         * @return void
         */
        function check_ipn_response()
        {
            @ob_clean();

            $this->log_message('Received response from BB Payments');
            $resp = $this->check_ipn_request_is_valid($_GET);
            if ($resp[0]) {
                header('HTTP/1.1 200 OK');
                do_action("valid_bb_payments_ipn_request", $_GET);
            } else {
                wp_die("BB Payment Request Failure: " . $resp[1]);
            }
        }

        /**
         * Successful Payment!
         *
         * @access public
         * @param array $posted
         * @return void
         */
        function successful_request($data)
        {
            $received_values = stripslashes_deep($data);
            $this->log_message('successful_request: ' . print_r($received_values, true));
            $user_key = $received_values['user_key'];
            $parts = explode('-', $user_key);

            $order_id = end($parts);
            $order_key = prev($parts);
            $order = new WC_Order($order_id);
            if (!isset($order->id)) {
                // We have an invalid $order_id, probably because invoice_prefix has changed
                $order_id = woocommerce_get_order_id_by_order_key($order_key);
                $order = new WC_Order($order_id);
            }
            if (!isset($order->id)) {
                $this->log_message('Not found order for payment: ' . print_r($received_values, true));
            }
            // Validate key
            if ($order->order_key !== $order_key) {
                $this->log_message('Error: Order Key does not match invoice.');
                exit;
            }

            $this->log_message('Found order #' . $order->id);

            // Check order not already completed
            if ($order->status == 'completed') {
                $this->log_message('Aborting, Order #' . $order->id . ' is already complete.');
                exit;
            }

            // Validate Amount
            if ($order->get_total() != ($received_values['debit_total'] / 100)) {
                $this->log_message('Payment error: Amounts do not match (' . $order->get_total() . ' vs. ' . $received_values['amount'] . ')');

                // Put this order on-hold for manual checking
                $order->update_status('on-hold', sprintf(__('Validation error: BB Payments amounts do not match (%s).', 'woocommerce'), $received_values['amount']));
                exit;
            }
            $currency = 1;
            $wcurrency = get_woocommerce_currency();
            if ($wcurrency == "USD") {
                $currency = 2;
            } elseif ($wcurrency == "EUR") {
                $currency = 0;
            }
            if ($currency != $received_values['debit_currency']) {
                $this->log_message('Payment error: Currencies do not match (' . $currency . ' vs. ' . $received_values['debit_currency'] . ')');

                // Put this order on-hold for manual checking
                $order->update_status('on-hold', sprintf(__('Validation error: BB Payments currencies do not match (%s).', 'woocommerce'), $received_values['debit_currency']));

                exit;
            }

            $order->add_order_note(__('IPN payment completed', 'woocommerce'));
            $order->payment_complete();

            $this->log_message('Payment complete.');

            wp_redirect($this->get_return_url($order));
            exit;
        }

        /**
         * Adds error message when something is not configured
         */
        public function app_missing_message($problem)
        {
            $this->log_message('app_missing_message');
            echo sprintf(
                '<div class="error"><p><strong>BB Payments Gateway Disabled</strong> You should fill your %s in. <a href="%s">Click here to configure!</a></p></div>',
                $problem,
                get_admin_url() . 'admin.php?page=woocommerce_settings&amp;tab=payment_gateways&amp;section=WC_Gateway_BB_Payments'
            );
        }

        /**
         * Adds error message when live payment URL is not configured.
         */
        public function app_url_missing_message()
        {
            $this->app_missing_message('Application URL');
        }

        /**
         * Adds message to log (if permitted)
         */
        //	public function log_message($message) { if ($this->debug == 'yes') $this->log->add('BB Payments', $message); }
        public function log_message($message)
        {
            $this->log->add('BB Payments', $message);
        }
    }
}
