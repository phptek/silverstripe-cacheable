<?php
/**
 * 
 * SilverStripe environment file for use with Shippable CI
 * 
 * @author Russell Michell 2015 <russ@theruss.com>
 */

// Are we in the Shippable environment?
if(strstr(getcwd(), 'shippable') !== false) {
	// Standard shippable MySQL config - don't change!
	define('SS_ENVIRONMENT_TYPE', 'dev');
	define('SS_DATABASE_SERVER', '127.0.0.1');
    define('SS_DATABASE_NAME', 'ss_cacheable_tests');
	define('SS_DATABASE_USERNAME', 'shippable');
	define('SS_DATABASE_PASSWORD', '');

	// Required for unit-tests etc to run
	$path = array(
		'github.com',
		'phptek',
		'silverstripe-shippable'
	);
	$_FILE_TO_URL_MAPPING[join('/', $path)] = 'http://localhost';
}
