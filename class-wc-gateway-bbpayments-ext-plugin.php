<?php
/*
Plugin Name: BB Payments System Gateway
Description: Provides a BB Payments System Gateway
Author: BB
Version: 0.1.0
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

add_action('plugins_loaded', 'wc_bb_payments_gateway_load', 0);

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
     * @extends        WC_Gateway_BB_Payments
     * @version        0.1.0
     * @package        WooCommerce/Classes/Payment
     * @author        BB
     */
    class WC_Gateway_BB_Payments extends WC_Payment_Gateway
    {
        /**
         * Constructor for the gateway.
         *
         * @access public
         * @return void
         */
        public function __construct() {
            global $woocommerce;

            $this->id = 'bb_payments';
            $this->icon = plugins_url('images/bb_payments.png', __FILE__);
            $this->has_fields = false;
            $this->method_title = __('BB Payments', 'woocommerce');
            $this->liveurl = 'https://checkout.kabbalah.info/en/projects/bb_books/external_client';
            $this->confirm_url = 'https://checkout.kabbalah.info/en/payments';
            $this->notify_url = str_replace('https:', 'http:', add_query_arg('wc-api', 'WC_Gateway_BB_Payments', home_url('/')));

            // Load the settings.
            $this->init_form_fields();
            $this->init_settings();

            // Define user set variables
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->testmode = $this->get_option('testmode');
            $this->debug = true; //$this->get_option('debug');
            $this->access_token = $this->get_option('access_token');
            $this->app_secret = $this->get_option('app_secret');
            $this->form_submission_method = $this->get_option('form_submission_method') == 'yes' ? true : false;
            $this->liveurl = $this->get_option('liveurl');
            $this->confirm_url = $this->get_option('confirm_url');

            // Logs
            if ('yes' == $this->debug)
                $this->log =  new WC_Logger();

            // Payment listener/API hook
            add_action('woocommerce_api_wc_gateway_bb_payments', array($this, 'check_ipn_response'));

            // Actions
            add_action('valid_bb_payments_ipn_request', array($this, 'successful_request'));
	        add_action('woocommerce_receipt_bb_payments', array( $this, 'receipt_page' ) );
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            $this->enabled = (('yes' == $this->get_option('enabled')) && !empty($this->access_token) && !empty($this->app_secret) && $this->is_valid_for_use()) ? 'yes' : 'no';

            // Checking if app_url is not empty.
            if (empty($this->confirm_url)) {
                add_action('admin_notices', array($this, 'confirm_url_missing_message'));
            }

            // Checking if app_url is not empty.
            if (empty($this->liveurl)) {
                add_action('admin_notices', array($this, 'app_url_missing_message'));
            }

            // Checking if access_token is not empty.
            if (empty($this->access_token)) {
                add_action('admin_notices', array($this, 'access_token_missing_message'));
            }

            // Checking if app_secret is not empty.
            if (empty($this->app_secret)) {
                add_action('admin_notices', array($this, 'app_secret_missing_message'));
            }
			
        }

        /**
         * Check if this gateway is enabled and available in the user's country
         *
         * @access public
         * @return bool
         */
        function is_valid_for_use() {
            return (!in_array(get_woocommerce_currency(), apply_filters('woocommerce_bb_payments_supported_currencies', array('USD', 'ILS')))) ? false : true;
        }

        /**
         * Admin Panel Options
         * - Options for bits like 'title' and availability on a country-by-country basis
         *
         * @since 1.0.0
         */
        public function admin_options() {
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
        function init_form_fields() {
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
                'access_token' => array(
                    'title' => __('Application Access Token', 'wcbb_payments'),
                    'type' => 'text',
                    'description' => __('Please enter your BB Payments Access Token.', 'wcbb_payments'),
                    'default' => ''),
                'app_secret' => array(
                    'title' => __('Application Secret', 'wc_bb_payments'),
                    'type' => 'textarea',
                    'description' => __('Please enter your BB Payments Application Secret.', 'wcbb_payments'),
                    'default' => ''),
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
                'form_submission_method' => array(
                    'title' => __('Submission method', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Use form submission method.', 'woocommerce'),
                    'description' => __('Enable this to post order data to BB Payments via a form instead of using a redirect/querystring.', 'woocommerce'),
                    'default' => 'no'),
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

        /**
         * Get Args for passing to BB Payments
         *
         * @access public
         * @param mixed $order
         * @return array
         */
        function get_payment_args($order) {
            $order_id = $order->id;

			$this->log_message('Generating payment form for order ' . $order->id . '. Notify URL: ' . $this->notify_url);

            $args = array(
		    'access_token' => $this->access_token,

                    'currency' => get_woocommerce_currency(),
                    'return' => $this->get_return_url($order),
                    'cancel_return' => $order->get_cancel_order_url(),

                    // Order key + ID
                    'invoiceID' => $order->id,
                    'custom1' => serialize(array($order_id, $order->order_key)),

                    // IPN
                    'notify_url' => $this->notify_url,

                    // Billing Address info
                    'first_name' => $order->shipping_first_name,
                    'last_name' => $order->shipping_last_name,
                    'email' => $order->billing_email,
                    'country' => $order->shipping_country,
                    'city' => $order->shipping_city,
                    'zip' => $order->shipping_postcode,

		    'language' => ICL_LANGUAGE_CODE,

		    'installments' => 3,
		    'amount' => number_format($order->get_total(), 2, '.', '')
            );

            $item_names = array();
            $items = $order->get_items();
            if (sizeof($items) > 0)
                foreach ($items as $item)
                    if ($item['qty'])
                        $item_names[] = '"' . $item['name'] . '" x ' . $item['qty'];

            $args['item_name'] = sprintf(__('Order %s', 'woocommerce'), $order->get_order_number());
            $args['amount_level'] = implode(', ', $item_names);

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
        function generate_form($order_id) {
            global $woocommerce;
            $this->log_message( 'generate_form');
            $order = new WC_Order($order_id);

            $addr = $this->liveurl;
            $args = $this->get_payment_args($order);

            $this->log_message( 'Processing payment via GET(' . $addr . ')');
			$this->log_message( 'Payment arguments for order #' . $order_id . ': ' . print_r( $args, true ) );

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

            return '<form action="' . esc_url($addr) . '" method="post" id="bb_payments_payment_form" target="_top">
			' . implode('', $args_array) . '
			<input type="submit" class="button alt" id="submit_bb_payments_payment_form" value="' . __('Pay via BB Payments', 'woocommerce') . '" /> <a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' . __('Cancel order &amp; restore cart', 'woocommerce') . '</a>
		    </form>';
        }

        /**
         * Process the payment and return the result
         *
         * @access public
         * @param int $order_id
         * @return array
         */
        function process_payment($order_id) {
            $order = new WC_Order($order_id);

            if (!$this->form_submission_method) {
                $args = $this->get_payment_args($order);
    		if ($this->testmode == 'yes') $args['test_mode'] = '1';
                $args = http_build_query($args, '', '&');
                $addr = $this->liveurl;

                $this->log_message( 'Processing payment via GET...' . $addr . '?' . $args);

                return array('result' => 'success', 'redirect' => $addr . '?' . $args);
            } else {
                $this->log_message( 'Processing payment via POST...');

                return array(
                    'result' => 'success',
                    'redirect' => add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('pay'))))
                );
            }
        }

	/**
         * Output for the order received page.
         *
         * @return void
         */
        public function receipt_page( $order ) {
		$this->log_message( 'receipt_page');
            echo $this->generate_form( $order );
        }

        /**
         * Check IPN validity
         **/
        function check_ipn_request_is_valid($data) {
            $this->log_message( 'Checking IPN response is valid...');

            // Get recieved values from post data
            $received_values = stripslashes_deep($data);
            $order = new WC_Order($received_values['invoiceID']);
            $this->log_message( print_r($order, true));

            // Build request
            $args = array(
	        'access_token' => $this->access_token,
                'invoiceID' => $received_values['invoiceID'],
		'amount' => number_format($order->get_total(), 2, '.', ''),
                'currency' => get_woocommerce_currency()
            );

	    // Encode request
	    $pub_key = openssl_pkey_get_public($this->app_secret);
	    $query = '';
	    $index = 0;
	    foreach ($args as $key => $value) {
		if ($value === NULL) continue;

		$q = "{$key}={$value}";
		if (openssl_public_encrypt($q, $encrypted, $pub_key) === FALSE) {
		    while ($msg = openssl_error_string()) { echo "<p>$msg</p>"; }
		    return false;
		}
		$query .= "#q{$index}=" . base64_encode($encrypted);
		$index++;
	    }
	    $query = substr($query, 1);

            // Submit request and get response
	    $data = array('data' => $query);
	    $confirm = $this->confirm_url . '/' . $received_values['payment_id'] . '/external_confirm';
	    $params = array(
        	'body' 		=> $data,
        	'sslverify' 	=> false,
        	'timeout' 	=> 60,
        	'httpversion'   => '1.1',
        	'headers'       => array( 'host' => 'checkout.kabbalah.info' ),
        	'user-agent'	=> 'WooCommerce/' . $woocommerce->version
	    );
            $response = wp_remote_post($confirm, $params);

            // check to see if the request was valid
            if (is_wp_error($response) || $response['response']['code'] != 200) {
                $this->log_message( 'Received invalid response');
                $this->log_message( print_r($response['body'], true) );
                if (is_wp_error($response))
                    $this->log_message( 'Error response: ' . $response->get_error_message());

                return false;
            }

	    // Decode and check response
	    $params = array();
	    $values = explode('&', $response['body']);
	    foreach ($values as $p) {
	    	$parts = explode('=', $p, 2);
	    	openssl_public_decrypt(base64_decode($parts[1]), $params[$parts[0]], $pub_key);
	    }

	    // Check answer is SUCCESS
	    $status = ($params['status'] == 'SUCCESS' && $args['invoiceID'] == $params['invoiceID'] && $args['amount'] == $params['amount'] && $args['currency'] == $params['currency']) ? TRUE : FALSE;

	    $this->log_message( 'Received ' . ($status ? '' : 'in') . 'valid encrypted response');

            return $status;
        }

        /**
         * Check for IPN Response
         *
         * @access public
         * @return void
         */
        function check_ipn_response() {
            @ob_clean();

            $this->log_message( 'Received response from BB Payments');

            if ($this->check_ipn_request_is_valid($_GET)) {
                header('HTTP/1.1 200 OK');
                do_action("valid_bb_payments_ipn_request", $_GET);
            } else {
                wp_die("BB Payment IPN Request Failure");
            }
        }

        /**
         * Successful Payment!
         *
         * @access public
         * @param array $posted
         * @return void
         */
        function successful_request($posted) {
            $this->log_message('successful_request');
            $posted = stripslashes_deep($posted);

            // Custom holds post ID
            if (!empty($posted['invoiceID']) && !empty($posted['custom1'])) {

                $order = $this->get_bb_payments_order($posted);

                $this->log_message( 'Found order #' . $order->id);

                // Check order not already completed
                if ($order->status == 'completed') {
                    $this->log_message( 'Aborting, Order #' . $order->id . ' is already complete.');
                    exit;
                }

                // Validate Amount
                if ($order->get_total() != $posted['amount']) {
                    $this->log_message( 'Payment error: Amounts do not match (' . $posted['amount'] . ')');

                    // Put this order on-hold for manual checking
                    $order->update_status('on-hold', sprintf(__('Validation error: BB Payments amounts do not match (%s).', 'woocommerce'), $posted['amount']));

                    exit;
                }
                if (get_woocommerce_currency() != $posted['currency']) {
                    $this->log_message( 'Payment error: Currencies do not match (' . $posted['currency'] . ')');

                    // Put this order on-hold for manual checking
                    $order->update_status('on-hold', sprintf(__('Validation error: BB Payments currencies do not match (%s).', 'woocommerce'), $posted['currency']));

                    exit;
                }

		// Validate Email Address
		if ( strcasecmp( trim( $posted['receiver_email'] ), trim( $this->receiver_email ) ) != 0 ) {
			$this->log_message( "IPN Response is for another one: {$posted['email']} our email is {$this->receiver_email}" );

			// Put this order on-hold for manual checking
			$order->update_status( 'on-hold', sprintf( __( 'Validation error: BB Payments IPN response from a different email address (%s).', 'woocommerce' ), $posted['email'] ) );

			exit;
		}

                // Store PP Details
                if (!empty($posted['email']))
                    update_post_meta($order->id, 'Payer email address', $posted['email']);
                if (!empty($posted['payment_id']))
                    update_post_meta($order->id, 'Transaction ID', $posted['payment_id']);
                if (!empty($posted['first_name']))
                    update_post_meta($order->id, 'Payer first name', $posted['first_name']);
                if (!empty($posted['last_name']))
                    update_post_meta($order->id, 'Payer last name', $posted['last_name']);

                $order->add_order_note(__('IPN payment completed', 'woocommerce'));
                $order->payment_complete();

                $this->log_message( 'Payment complete.');

                wp_redirect($this->get_return_url($order));
		exit;
	    } else {
                $this->log_message( 'Not found order for payment: ' . print_r($posted, true));
	    }
        }

        /**
         * get_bb_payments_order function.
         *
         * @access public
         * @param mixed $posted
         * @return void
         */
        function get_bb_payments_order($posted) {
		$this->log_message( 'get_bb_payments_order');
            $custom = maybe_unserialize($posted['custom1']);

            // Backwards comp for IPN requests
            if (is_numeric($custom)) {
                $order_id = (int)$custom;
                $order_key = $posted['invoice'];
            } elseif (is_string($custom)) {
                $order_id = (int)str_replace($this->invoice_prefix, '', $custom);
                $order_key = $custom;
            } else {
                list($order_id, $order_key) = $custom;
            }

            $order = new WC_Order($order_id);

            if (!isset($order->id)) {
                // We have an invalid $order_id, probably because invoice_prefix has changed
                $order_id = woocommerce_get_order_id_by_order_key($order_key);
                $order = new WC_Order($order_id);
            }

            // Validate key
            if ($order->order_key !== $order_key) {
                $this->log_message('Error: Order Key does not match invoice.');
                exit;
            }

            return $order;
        }

        /**
         * Adds error message when something is not confugured
         */
        public function app_missing_message($problem) {
            $this->log_message('app_missing_message');
            echo sprintf(
                    '<div class="error"><p><strong>BB Payments Gateway Disabled</strong> You should fill your %s in. <a href="%s">Click here to configure!</a></p></div>',
                    $problem,
                    get_admin_url() . 'admin.php?page=woocommerce_settings&amp;tab=payment_gateways&amp;section=WC_Gateway_BB_Payments'
                );
        }

        /**
         * Adds error message when not configured the access_token.
         */
        public function access_token_missing_message() { $this->app_missing_message('Access Token'); }

        /**
         * Adds error message when not configured the app_secret.
         */
        public function app_secret_missing_message() { $this->app_missing_message('Application Secret'); }

        /**
         * Adds error message when not configured the app_secret.
         */
        public function app_url_missing_message() { $this->app_missing_message('Application URL'); }

        /**
         * Adds message to log (if permitted)
         */
//	public function log_message($message) { if ($this->debug == 'yes') $this->log->add('BB Payments', $message); }
	public function log_message($message) { $this->log->add('BB Payments', $message); }

	public function hash_implode($glue, $hash) {
		$result = array();
		foreach ($hash as $key => $value) {
			$result[] = $key . ': ' . $value;
		}
		return implode($glue, $result);
	}
    }
}
