<?php
/*
Plugin Name: Payhubix Gateway For Woocommerce
Description: A WooCommerce payment gateway that allows users to pay via Payhubix API.
Version: 1.1.2
Plugin URI:  https://payhubix.com
Author: Payhubix TM, Mohammad Bina
Author URI: https://payhubix.com
License: GPL-2.0+
License URI: http://www.gnu.org/licenses/gpl-2.0.txt
Text Domain: payhubix-gateway-wc
Domain Path: /languages
*/

add_action('plugins_loaded', 'woocommerce_payhubix', 0);
function woocommerce_payhubix(){
    if (!class_exists('WC_Payment_Gateway'))
        return; // if the WC payment gateway class 

    include(plugin_dir_path(__FILE__) . 'class-gateway-payhubix.php');
}


add_filter('woocommerce_payment_gateways', 'add_payhubix_gateway');

function add_payhubix_gateway($gateways) {
  $gateways[] = 'Payhubix_Gateway';
  return $gateways;
}

/**
 * Custom function to declare compatibility with cart_checkout_blocks feature 
*/
function declare_cart_checkout_blocks_compatibility() {
    // Check if the required class exists
    if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
        // Declare compatibility for 'cart_checkout_blocks'
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
}
// Hook the custom function to the 'before_woocommerce_init' action
add_action('before_woocommerce_init', 'declare_cart_checkout_blocks_compatibility');

// Hook the custom function to the 'woocommerce_blocks_loaded' action
add_action( 'woocommerce_blocks_loaded', 'oawoo_register_order_approval_payment_method_type' );

/**
 * Custom function to register a payment method type
 */
function oawoo_register_order_approval_payment_method_type() {
    // Check if the required class exists
    if ( ! class_exists( 'Automattic\WooCommerce\Blocks\Payments\Integrations\AbstractPaymentMethodType' ) ) {
        return;
    }

    // Include the custom Blocks Checkout class
    require_once plugin_dir_path(__FILE__) . 'class-block-payhubix.php';

    // Hook the registration function to the 'woocommerce_blocks_payment_method_type_registration' action
    add_action(
        'woocommerce_blocks_payment_method_type_registration',
        function( Automattic\WooCommerce\Blocks\Payments\PaymentMethodRegistry $payment_method_registry ) {
            // Register an instance of Payhubix_Gateway_Blocks
            $payment_method_registry->register( new Payhubix_Gateway_Blocks );
        }
    );
}
?>
