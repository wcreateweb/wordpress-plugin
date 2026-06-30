<?php
/**
 * PHPUnit bootstrap for the Brain Monkey test suite.
 *
 * Loads, before any test class:
 *  - the Composer autoloader (which initialises Patchwork for Brain Monkey);
 *  - the plugin's class autoloader for src/class-*.php;
 *  - the few support files that live outside that naming convention.
 */

require_once dirname( __FILE__ ) . '/../vendor/autoload.php';
require_once dirname( __FILE__ ) . '/helpers/wordpress-cli.php';
require_once dirname( __FILE__ ) . '/../src/config/class-tiny-config.php';
require_once dirname( __FILE__ ) . '/../src/compatibility/wpml/class-tiny-wpml.php';
require_once dirname( __FILE__ ) . '/../src/compatibility/as3cf/class-tiny-as3cf.php';
require_once dirname( __FILE__ ) . '/../src/compatibility/woocommerce/class-tiny-woocommerce.php';

function plugin_autoloader( $class ) {
	$file = dirname( __FILE__ ) . '/../src/class-' .
		str_replace( '_', '-', strtolower( $class ) ) . '.php';

	if ( file_exists( $file ) ) {
		include $file;
	}
}

spl_autoload_register( 'plugin_autoloader' );

class Tiny_PHP {
	public static $fopen_available = true;
	public static $client_supported = true;

	public static function fopen_available() {
		return self::$fopen_available;
	}

	public static function client_supported() {
		return self::$client_supported;
	}
}