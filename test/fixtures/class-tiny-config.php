<?php

if (! defined('TINY_DEBUG')) {
	define('TINY_DEBUG', null);
}
define('AWS_REGION', getenv('AWS_REGION'));
define('AWS_ENDPOINT', getenv('AWS_ENDPOINT'));
define('AWS_ACCESS_KEY_ID', getenv('AWS_ACCESS_KEY_ID'));
define('AWS_SECRET_ACCESS_KEY', getenv('AWS_SECRET_ACCESS_KEY'));

class Tiny_Config
{
	/* URL is only used by fopen driver. */
	const SHRINK_URL = 'http://tinify-mock-api/shrink';
	const KEYS_URL = 'http://tinify-mock-api/keys';
	const MONTHLY_FREE_COMPRESSIONS = 500;
	const META_KEY = 'tiny_compress_images';
}

/**
 * Filter for adding arguments to the AS3CF AWS client. 
 * Required to point the client to the LocalStack instance.
 */
add_filter('as3cf_aws_init_client_args', function ($args) {
	$args['endpoint'] = AWS_ENDPOINT;
	$args['use_path_style_endpoint'] = true;
	$args['region'] = AWS_REGION;
	return $args;
});

// Force all S3 URLs to use LocalStack instead of s3.amazonaws.com
add_filter('as3cf_aws_s3_url_domain', function ($domain, $bucket, $region, $expires, $args) {
	// Replace any AWS domain with LocalStack
	return AWS_ENDPOINT . '/' . $bucket;
}, 10, 5);
