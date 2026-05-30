<?php
/**
 * Plugin Name: Casa Nevia - MotoPress Custom Deposit Payment
 * Description: Modifies MotoPress Stripe integration to save cards and handles 400€ damage deposits via WP Cron.
 * Version: 1.0.0
 * Author: Maro
 * Text Domain: motopress-custom-depozit-payment
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'MPCDP_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'MPCDP_DEPOSIT_AMOUNT', 40000 ); // 400 EUR in cents

// Autoloader
spl_autoload_register( function ( $class ) {
	$prefix = 'MPCDP\\';
	$base_dir = MPCDP_PLUGIN_DIR . 'src/';

	$len = strlen( $prefix );
	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require $file;
	}
} );

// Initialize Plugin
add_action( 'plugins_loaded', function() {
    // Only run if MotoPress is active and Stripe is available
    if ( ! class_exists( 'MPHB\Payments\Gateways\Stripe\StripeAPI' ) ) {
        return;
    }
    
	new \MPCDP\StripeApi();
	new \MPCDP\AdminUI();
	new \MPCDP\CronManager();
} );
