<?php

require_once dirname(__FILE__) . '/TinyTestCase.php';

class Tiny_Dashboard_Test extends Tiny_TestCase
{
	/**
	 * Builds the widget data
	 */
	private function widget_data($uploaded, $remaining, $unoptimized_size = 0, $optimized_size = 0)
	{
		return Tiny_Dashboard::get_widget_data(array(
			'uploaded-images' => $uploaded,
			'optimized-image-sizes' => 0,
			'available-unoptimized-sizes' => 0,
			'optimized-library-size' => $optimized_size,
			'unoptimized-library-size' => $unoptimized_size,
			'estimated_credit_use' => 0,
			'available-for-optimization' => array_fill(0, $remaining, array('ID' => 1, 'post_title' => 'image')),
			'display-percentage' => 0,
		));
	}

	public function test_is_empty_when_there_are_no_images()
	{
		$data = $this->widget_data(0, 0);

		$this->assertSame('empty', $data['status']);
		$this->assertSame(0, $data['percentage']);
		$this->assertSame(0, $data['images_remaining']);
		$this->assertSame('panda-waiting.png', $data['panda']);
	}

	public function test_is_in_progress_when_images_remain()
	{
		$data = $this->widget_data(1250, 330);

		$this->assertSame('in_progress', $data['status']);
		$this->assertSame(920, $data['images_optimized']);
		$this->assertSame(1250, $data['images_total']);
		$this->assertSame(330, $data['images_remaining']);
		$this->assertSame(73, $data['percentage']);
		$this->assertSame('panda-eating.png', $data['panda']);
	}

	public function test_is_done_when_no_images_remain()
	{
		$data = $this->widget_data(1250, 0);

		$this->assertSame('done', $data['status']);
		$this->assertSame(1250, $data['images_optimized']);
		$this->assertSame(100, $data['percentage']);
		$this->assertSame('all done', $data['label']);
		$this->assertSame('panda-laying.png', $data['panda']);
	}

	public function test_labels_below_half_as_keep_going()
	{
		$this->assertSame('keep going', $this->widget_data(100, 51)['label']);
	}

	public function test_labels_half_and_above_as_almost_there()
	{
		$this->assertSame('almost there', $this->widget_data(100, 50)['label']);
	}

	public function test_does_not_show_100_percent_while_images_remain()
	{
		$data = $this->widget_data(1000, 1);

		$this->assertSame('in_progress', $data['status']);
		$this->assertSame(99, $data['percentage']);
	}

	public function test_bytes_saved_is_the_library_size_reduction()
	{
		$this->assertSame(2000, $this->widget_data(10, 3, 5000, 3000)['bytes_saved']);
	}

	public function test_bytes_saved_is_never_negative()
	{
		$this->assertSame(0, $this->widget_data(10, 3, 3000, 5000)['bytes_saved']);
	}
}
