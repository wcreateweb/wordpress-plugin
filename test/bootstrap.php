<?php

define('ABSPATH', dirname(__FILE__) . '/');
define( 'FS_CHMOD_FILE', 0644 );

require_once dirname( __FILE__ ) . '/../vendor/autoload.php';
require_once dirname( __FILE__ ) . '/../vendor/antecedent/patchwork/Patchwork.php';
require_once dirname( __FILE__ ) . '/unit/TinyV2_TestCase.php';

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