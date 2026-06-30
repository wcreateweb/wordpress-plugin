<?php

use Yoast\WPTestUtils\BrainMonkey\TestCase;
use Brain\Monkey\Functions;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\content\LargeFileContent;

class Tiny_Cli_Test extends TestCase {
	const UPLOAD_DIR = 'wp-content/uploads';

	/**
	 * @var \org\bovigo\vfs\vfsStreamDirectory
	 */
	private $vfs;

	protected function set_up() {
		parent::set_up();
		$this->vfs = vfsStream::setup();
	}

	public function test_will_compress_attachments_given_in_params() {
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/png' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			array(
				'width'  => 1256,
				'height' => 1256,
				'file'   => '2025/07/test.png',
				'sizes'  => array(
					'thumbnail' => array(
						'file'      => 'test-150x150.png',
						'width'     => 150,
						'height'    => 150,
						'mime-type' => 'image/png',
					),
				),
			)
		);
		$this->create_images(
			'2025/07',
			array(
				'test.png'         => 137856,
				'test-150x150.jpg' => 37856,
			)
		);

		$compressor = $this->createMock( Tiny_Compress::class );
		$compressor->expects( $this->once() )
			->method( 'compress_file' )
			->with( 'vfs://root/wp-content/uploads/2025/07/test.png', false, array(), array() );

		$settings = new Tiny_Settings();
		$settings->set_compressor( $compressor );

		( new Tiny_Cli( $settings ) )->optimize( array(), array( 'attachments' => '4030' ) );
	}

	public function test_will_compress_all_uncompressed_attachments_if_none_given() {
		$this->stub_wordpress();
		Functions\when( 'get_post_mime_type' )->justReturn( 'image/png' );
		Functions\when( 'wp_get_attachment_metadata' )->justReturn(
			array(
				'width'  => 1256,
				'height' => 1256,
				'file'   => '2025/07/test.png',
				'sizes'  => array(),
			)
		);
		$this->fake_wpdb(
			array(
				array(
					'ID'                     => 1,
					'post_title'             => 'Test Image',
					'meta_value'             => serialize(
						array(
							'width'  => 1200,
							'height' => 800,
							'file'   => '2025/07/test.png',
							'sizes'  => array(),
						)
					),
					'unique_attachment_name' => '2025/07/test.png',
					'tiny_meta_value'        => '',
				),
			)
		);
		$this->create_images( '2025/07', array( 'test.png' => 137856 ) );

		$compressor = $this->createMock( Tiny_Compress::class );
		$compressor->expects( $this->once() )->method( 'compress_file' );

		$settings = new Tiny_Settings();
		$settings->set_compressor( $compressor );

		( new Tiny_Cli( $settings ) )->optimize( array(), array() );
	}
}
