<?php

if ( ! defined( 'TINY_DEBUG' ) ) {
	define( 'TINY_DEBUG', null );
}

class Tiny_Config {
	/* URL is only used by fopen driver. */
	const SHRINK_URL                = 'http://tinify-mock-api/shrink';
	const KEYS_URL                  = 'http://tinify-mock-api/keys';
	const MONTHLY_FREE_COMPRESSIONS = 500;
	const META_KEY                  = '_tiny_compress_images';
	const LEGACY_META_KEY           = 'tiny_compress_images';
}


// ajax hook to delete all attachments as doing it via UI is flaky
add_action( 'wp_ajax_clear_media_library', 'clear_media_library' );
function clear_media_library() {
	$attachments = get_posts( array(
		'post_type'      => 'attachment',
		'posts_per_page' => -1,
		'fields'         => 'ids',
	) );

	foreach ( $attachments as $id ) {
		wp_delete_attachment( $id, true );
	}

	wp_send_json_success( array(
		'deleted' => count( $attachments ),
	) );
}

/*
Renders an attachment the way a theme would: via wp_get_attachment_image(), which
emits class="attachment-{size} size-{size}" and a real srcset, but no wp-image-{id}
class. Post content typed in the editor is the only thing that carries wp-image-{id},
so the page scanner needs both kinds on a fixture page to be exercised properly.
Usage in post content: [tiny_theme_image id="123" size="large"] */
add_shortcode( 'tiny_theme_image', 'tiny_theme_image_shortcode' );
function tiny_theme_image_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'id'   => 0,
			'size' => 'large',
		),
		$atts
	);

	return wp_get_attachment_image( intval( $atts['id'] ), $atts['size'] );
}

/*
Writes a file into the uploads directory with no attachment record behind it, so the
scanner has a URL that lives under uploads but resolves to nothing. Returns the URL.
This is one of the two 'unresolved' cases; the other is an off-site host. */
add_action( 'wp_ajax_create_orphan_upload', 'create_orphan_upload' );
function create_orphan_upload() {
	$uploads  = wp_upload_dir();
	$filename = 'orphan-no-attachment.jpg';
	// This file is copied to src/config/ by bin/run-mocks, so go up to the plugin root.
	$source   = dirname( dirname( dirname( __FILE__ ) ) ) . '/test/fixtures/input-example.jpg';
	$target   = $uploads['path'] . '/' . $filename;

	if ( ! file_exists( $source ) ) {
		wp_send_json_error( array( 'message' => 'fixture image not found at ' . $source ) );
	}

	if ( ! copy( $source, $target ) ) {
		wp_send_json_error( array( 'message' => 'could not write ' . $target ) );
	}

	wp_send_json_success( array(
		'url' => $uploads['url'] . '/' . $filename,
	) );
}