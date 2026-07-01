<?php

use function PHPUnit\Framework\assertEquals;
use function PHPUnit\Framework\assertFalse;
use function PHPUnit\Framework\assertNotFalse;
use function PHPUnit\Framework\assertTrue;
use Brain\Monkey\Functions;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\content\LargeFileContent;

class Tiny_Logger_Test extends TinyV2_TestCase
{
	/** @var \Mockery\MockInterface */
	protected $mock_filesystem;

	public function setUp(): void
	{
		parent::setUp();
		Tiny_Logger::reset();
		Functions\stubs(array(
			'wp_upload_dir' => array(
				'basedir' => $this->vfs->url() . '/wp-content/uploads',
			),
			'wp_mkdir_p' => function ( $dir ) {
				@mkdir( $dir, 0755, true );
				return true;
			},
			'current_time' => function ( $format ) {
				return date( $format );
			},
			'get_option' => 'on',
		));

		$this->mock_filesystem = Mockery::mock( 'WP_Filesystem_Base' );
		$this->mock_filesystem->shouldReceive( 'exists' )
			->zeroOrMoreTimes()
			->andReturnUsing( function ( $path ) {
				return file_exists( $path );
			} );
		$this->mock_filesystem->shouldReceive( 'size' )
			->zeroOrMoreTimes()
			->andReturnUsing( function ( $path ) {
				return filesize( $path );
			} );
		$this->mock_filesystem->shouldReceive( 'delete' )
			->zeroOrMoreTimes()
			->andReturnUsing( function ( $path ) {
				return is_dir( $path ) ? rmdir( $path ) : unlink( $path );
			} );
		$this->mock_filesystem->shouldReceive( 'put_contents' )
			->zeroOrMoreTimes()
			->andReturnUsing( function ( $path, $contents, $chmod = false ) {
				$dir = dirname( $path );
				if ( ! is_dir( $dir ) ) {
					@mkdir( $dir, 0755, true );
				}
				return file_put_contents( $path, $contents ) !== false;
			} );

		global $wp_filesystem;
		$wp_filesystem = $this->mock_filesystem;
	}

	public function tearDown(): void
	{
		$logger = Tiny_Logger::get_instance();
		$logger->clear_logs();
		$logger->reset();
		parent::tearDown();
	}

	public function test_logger_always_has_one_instance()
	{
		$instance1 = Tiny_Logger::get_instance();
		$instance2 = Tiny_Logger::get_instance();
		assertEquals($instance1, $instance2, 'logger should be a singleton');
	}

	public function test_get_log_enabled_memoizes_log_enabled()
	{
		$logger = Tiny_Logger::get_instance();
		assertTrue($logger->get_log_enabled(), 'log should be enabled when tinypng_logging_enabled is on');
	}

	public function test_sets_log_path_on_construct()
	{
		$logger = Tiny_Logger::get_instance();
		assertEquals($logger->get_log_file_path(), 'vfs://root/wp-content/uploads/tiny-compress-logs/tiny-compress.log');
	}

	public function test_registers_save_update_when_log_enabled()
	{
		$logger = Tiny_Logger::get_instance();
		$logger->init();
		assertEquals(has_filter('pre_update_option_tinypng_logging_enabled', 'Tiny_Logger::on_save_log_enabled'), 10);
	}

	public function test_option_hook_updates_log_enabled()
	{
		Functions\when('get_option')->justReturn(false);
		Tiny_Logger::init();
		$logger = Tiny_Logger::get_instance();

		assertFalse($logger->get_log_enabled(), 'option is not set so should be false');

		assertTrue(has_filter('pre_update_option_tinypng_logging_enabled'));

		Tiny_Logger::on_save_log_enabled('on', null);

		assertTrue($logger->get_log_enabled(), 'when option is updated, should be true');
	}

	public function test_will_not_log_if_disabled()
	{
		Functions\when('get_option')->justReturn(false);

		$logger = Tiny_Logger::get_instance();

		Tiny_Logger::error('This should not be logged');
		Tiny_Logger::debug('This should also not be logged');

		$log_path = $logger->get_log_file_path();
		$log_exists = file_exists($log_path);
		assertFalse($log_exists, 'log file should not exist when logging is disabled');
	}

	public function test_creates_log_when_log_is_enabled()
	{
		$logger = Tiny_Logger::get_instance();
		$log_path = $logger->get_log_file_path();
		$log_exists = file_exists($log_path);
		assertFalse($log_exists, 'log file should not exist initially');

		Tiny_Logger::error('This should be logged');
		Tiny_Logger::debug('This should also be logged');

		$log_path = $logger->get_log_file_path();
		$log_exists = file_exists($log_path);
		assertTrue($log_exists, 'log file is created when logging is enabled');
	}

	public function test_removes_full_log_and_creates_new()
	{
		$log_dir_path = 'wp-content/uploads/tiny-compress-logs';
		vfsStream::newDirectory($log_dir_path)->at($this->vfs);
		$log_dir = $this->vfs->getChild($log_dir_path);

		vfsStream::newFile('tiny-compress.log')
			->withContent(LargeFileContent::withMegabytes(2.1))
			->at($log_dir);

		$logger = Tiny_Logger::get_instance();

		assertTrue(filesize($logger->get_log_file_path()) > 2097152, 'log file should be larger than 2MB');

		Tiny_Logger::error('This should be logged');

		assertTrue(filesize($logger->get_log_file_path()) < 1048576, 'log file rotated and less than 1MB');
	}

	public function test_clears_logs_when_turned_on()
	{
		$log_dir_path = 'wp-content/uploads/tiny-compress-logs';
		vfsStream::newDirectory($log_dir_path)->at($this->vfs);
		$log_dir = $this->vfs->getChild($log_dir_path);
		vfsStream::newFile('tiny-compress.log')
			->withContent('Some existing log content')
			->at($log_dir);

		$logger = Tiny_Logger::get_instance();
		$log_path = $logger->get_log_file_path();

		assertTrue(file_exists($log_path), 'log file should exist');

		Tiny_Logger::on_save_log_enabled( 'on', 'off' );

		assertFalse(file_exists($log_path), 'log file should be deleted after turning on logging');
	}

	public function test_keeps_logs_when_unchanged()
	{
		$log_dir_path = 'wp-content/uploads/tiny-compress-logs';
		vfsStream::newDirectory($log_dir_path)->at($this->vfs);
		$log_dir = $this->vfs->getChild($log_dir_path);
		vfsStream::newFile('tiny-compress.log')
			->withContent('Some existing log content')
			->at($log_dir);

		$logger = Tiny_Logger::get_instance();
		$log_path = $logger->get_log_file_path();

		assertTrue(file_exists($log_path), 'log file should exist');

		Tiny_Logger::on_save_log_enabled( 'on', 'on' );

		assertTrue(file_exists($log_path), 'log file should still exist when settings remain unchanged');

		unlink($log_path);
	}
}
