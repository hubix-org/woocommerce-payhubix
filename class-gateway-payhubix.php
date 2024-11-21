<?php
class Payhubix_Gateway extends WC_Payment_Gateway
{
	protected string $api_key;
	protected string $shop_id;
	protected string $time_for_payment;

	public function __construct()
	{
		$this->id = 'payhubix_gateway';
		$this->method_title = __('Payhubix Gateway', 'payhubix-gateway');
		$this->has_fields = false;
		$this->icon = WP_PLUGIN_URL . '/' . plugin_basename(__DIR__) . '/assets/logo.png';
		$this->method_description = 
			"<a href='https://payhubix.com' target='_blank'>
				<img src='" . WP_PLUGIN_URL . '/' . plugin_basename(__DIR__) . '/assets/banner.webp' . "' style='max-width: 500px; height: auto;'>
			</a>";

		$this->init_form_fields();
		$this->init_settings();

		$this->title = $this->get_option('title');
		$this->description = $this->get_option('description');
		$this->api_key = $this->get_option('api_key');
		$this->shop_id = $this->get_option('shop_id');
		$this->time_for_payment = $this->get_option('time_for_payment');

		add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
		add_action('woocommerce_api_' . strtolower(get_class($this)), array($this, 'handle_payhubix_callback'));
	}

	public function init_form_fields()
	{
		$this->form_fields = array(
			'enabled' => array(
				'title' => __('Enable/Disable', 'payhubix-gateway'),
				'type' => 'checkbox',
				'label' => __('Enable Payhubix Payment Gateway', 'payhubix-gateway'),
				'default' => 'yes',
			),
			'title' => array(
				'title' => __('Title', 'payhubix-gateway'),
				'type' => 'text',
				'description' => __('Title of the payment method visible to the customer during checkout.', 'payhubix-gateway'),
				'default' => __('Pay with Payhubix', 'payhubix-gateway'),
			),
			'description' => array(
				'title' => __('Description', 'payhubix-gateway'),
				'type' => 'textarea',
				'description' => __('Description of the payment method visible to the customer.', 'payhubix-gateway'),
				'default' => __('Use Payhubix to securely pay for your order.', 'payhubix-gateway'),
			),
			'api_key' => array(
				'title' => __('API Key', 'payhubix-gateway'),
				'type' => 'textarea',
				'description' => __('Enter your Payhubix API key.', 'payhubix-gateway'),
				'default' => '',
			),
			'shop_id' => array(
				'title' => __('Shop ID', 'payhubix-gateway'),
				'type' => 'text',
				'description' => __('Enter your Shop ID', 'payhubix-gateway'),
				'default' => '',
			),
			'time_for_payment' => array(
				'title' => __('Time For Payment', 'payhubix-gateway'),
				'type' => 'select',
				'description' => __('The time allowed for payment.', 'payhubix-gateway'),
				'default' => '02:00',
				'options' => array(
					'00:15' => '15 minutes',
					'00:30' => '30 minutes',
					'01:00' => '1 hour',
					'02:00' => '2 hours',
					'03:00' => '3 hours',
				),
			),
		);
	}

	// Process the payment
	public function process_payment($order_id)
	{
		$order = wc_get_order($order_id);

		$response = $this->call_payhubix_api( $order );

		// Handle the response from Payhubix API
		if ( $response['error'] == false ) {
			WC()->cart->empty_cart();
			// store payhubix invoice id for payment check
			$invoice_id = $response['message']['link'];
			$order->update_meta_data('_payhubix_invoice_id', $invoice_id);
        	$order->save();
			return array(
				'result'   => 'success',
				'redirect' => $response['message']['invoice_url'],
			);
		} else {
			return array(
				'result'   => 'failure',
				'redirect' => $response['message'],
			);
		}
	}

	// handle payhubix callback
	public function handle_payhubix_callback()
	{
		if(isset($_GET['key'])){
			$req_order_id = wc_get_order_id_by_order_key($_GET['key']);
			$order = wc_get_order($req_order_id);
			$invoice_id = $order->get_meta('_payhubix_invoice_id');
			$data = $this->check_payment_status($invoice_id);

			if (isset($data['error']) && !$data['error']) {
				$invoice_data = $data['message'];

				if ($invoice_id != $invoice_data['link']){
					error_log('Payhubix callback: Invoice ID mismatch for order ' . $req_order_id);
					wc_add_notice(__('Error processing payment, please contact support.'), 'error');
					wp_redirect(wc_get_checkout_url());
					exit;
				}

				// Extract necessary info from the callback
				$order_id = $invoice_data['order_id'];
				$status = $invoice_data['status'];
	
				// Check if the order exists
				if (!$order) {
					error_log(__('Payhubix callback: Order not found for ID ') . $order_id);
					wp_redirect(wc_get_checkout_url());
					return;
				}
	
				switch ($status) {
					case 'Paid':
						// Payment successfully processed
						$order->payment_complete();
						$order->add_order_note('Payment successfully processed by Payhubix.');
						$order->update_status('completed');
						wc_add_notice(__('Your payment has been successfully processed. Thank you for your order!'), 'success');
						break;
	
					case 'Canceled':
						// Payment was canceled
						$order->update_status('cancelled');
						$order->add_order_note('Payment was canceled by the customer or Payhubix.');
						wc_add_notice(__('Your payment has been canceled. Please contact support if this is an error.'), 'error');
						break;
	
					case 'PartiallyExpired':
						// Payment is partially expired (payment was not completed in time)
						$order->update_status('on-hold');
						$order->add_order_note('Payment was partially expired, payment not fully processed.');
						wc_add_notice(__('Your payment is partially expired. Please contact support for assistance.'), 'error');
						break;
	
					case 'Expired':
						// Payment expired
						$order->update_status('cancelled');
						$order->add_order_note('Payment expired, payment not completed in time.');
						wc_add_notice(__('Your payment has expired. Please pay or place a new order.'), 'error');
						break;
						
					case 'Created':
						// Payment created
						$order->update_status('pending-payment');
						$order->add_order_note('Payment created, payment not completed in time.');
						wc_add_notice(__('Your payment has created. Please place a new order.'), 'error');
						break;
	
					default:
						// If there is an unknown status
						$order->update_status('failed');
						$order->add_order_note('Payment status unknown: ' . $status);
						wc_add_notice(__('There was an issue processing your payment. Please contact support.'), 'error');
						break;
				}
				
				// Send a success response (required by Payhubix)
				wp_redirect($order->get_checkout_order_received_url());
				wp_send_json_success();
				exit;
			} else {
				// Handle any errors in the callback data
				error_log('Payhubix callback error: ' . json_encode($data));
				wp_redirect(wc_get_checkout_url());
				wp_send_json_error();
				exit;
			}
		} else {
			error_log('Order not found!');
			wp_redirect(wc_get_checkout_url());
			wp_send_json_error();
			exit;
		}
	}

	private function check_payment_status($invoice_id)
	{
		$url = 'https://api.payhubix.com/v1/payment/invoices/' . $invoice_id;

		$args = [
			'headers' => [
				'Content-Type' => 'application/json',
				'X-Api-key' => $this->api_key,
			],
		];

		// Make the HTTP request
		$response = wp_remote_get($url, $args);

		if (is_wp_error($response)) {
			return new WP_Error('payment_error', $response->get_error_message());
		}

		$body = wp_remote_retrieve_body($response);
		$data = json_decode($body, true);

		return $data;
	}
	
	private function call_payhubix_api( $order ) {
		// Set the API endpoint URL
		$url = 'https://api.payhubix.com/v1/payment/shops/' . $this->shop_id . '/invoices/';
		
		// Prepare the data to be sent in JSON format
		$data = [
			'currency_amount'   => (int) $order->get_total(),
			'currency_symbol'   => get_woocommerce_currency(),
			'customer_email'    => $order->get_billing_email(),
			'time_for_payment'   => $this->time_for_payment,
			'currencies'        => [],
			'order_id'          => $order->get_order_number(),
			'order_description' => $order->get_title(),
			'callback_url'      => $this->get_return_url($order). '&wc-api=payhubix_gateway',
		];
	
		// Set up the arguments for the request
		$args = [
			'body'        => json_encode($data),
			'headers'     => [
				'Content-Type' => 'application/json',
				'X-Api-key'    => $this->api_key,
			],
			'timeout'     => 30,
			'data_format' => 'body',
		];
	
		// Make the HTTP POST request
		$response = wp_remote_post($url, $args);
	
		// Check for errors in the response
		if (is_wp_error($response)) {
			// Return a WP_Error object with the error message
			return new WP_Error('payment_error', $response->get_error_message());
		}
	
		// Get the response body
		$body = wp_remote_retrieve_body($response);
	
		// Decode the JSON response
		$decoded_body = json_decode($body, true);
	
		if ( !is_array($decoded_body) || isset($decoded_body['error']) && $decoded_body['error'] ) {
			return new WP_Error('payment_error', 'Invalid response from Payhubix');
		}

		// Return the response body
		return $decoded_body;
	}

}
?>