<?php
/*
* Tiny Compress Images - WordPress plugin.
* Copyright (C) 2015-2023 Tinify B.V.
*
* This program is free software; you can redistribute it and/or modify it
* under the terms of the GNU General Public License as published by the Free
* Software Foundation; either version 2 of the License, or (at your option)
* any later version.
*
* This program is distributed in the hope that it will be useful, but WITHOUT
* ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
* FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
* more details.
*
* You should have received a copy of the GNU General Public License along
* with this program; if not, write to the Free Software Foundation, Inc., 51
* Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA.
*/
class Tiny_Dashboard extends Tiny_WP_Base {

	/**
	 * @var Tiny_Settings settings
	 */
	private $settings;

	/**
	 * @param Tiny_Settings $settings
	 */
	public function __construct( $settings ) {
		parent::__construct();
		$this->settings = $settings;
	}

	public function admin_init() {
		add_action(
			'wp_dashboard_setup',
			$this->get_method( 'add_dashboard_widget' )
		);
	}

	public function add_dashboard_widget() {
		wp_enqueue_style(
			self::NAME . '_dashboard_widget',
			plugins_url( '/css/dashboard-widget.css', __FILE__ ),
			array(),
			self::wp_version(),
		);

		wp_add_dashboard_widget(
			$this->get_prefixed_name( 'dashboard_widget' ),
			esc_html__( 'TinyPNG - JPEG, PNG & WebP image compression', 'tiny-compress-images' ),
			$this->get_method( 'add_widget_view' )
		);
	}

	public function add_widget_view() {
		$optimization_statistics = Tiny_Bulk_Optimization::get_optimization_statistics( $this->settings );
		$widget = self::get_widget_data( $optimization_statistics );
		include __DIR__ . '/views/dashboard-widget.php';
	}

	/**
	 * Logic for the view
	 *
	 * @param array $optimization_stats See Tiny_Bulk_Optimization::get_optimization_statistics().
	 * @return array
	 */
	public static function get_widget_data( $optimization_stats ) {
		$images_remaining = count( $optimization_stats['available-for-optimization'] );
		$bytes_total = $optimization_stats['unoptimized-library-size'];
		$images_total = max( 0, intval( $optimization_stats['uploaded-images'] ) );
		
		$images_optimized = $images_total - $images_remaining;
		$images_optimized = max( 0, intval( $images_optimized ) );
		$images_remaining = max( 0, $images_total - $images_optimized );
		$bytes_saved =  $bytes_total - $optimization_stats['optimized-library-size'];

		$percentage = $images_total > 0
			? intval( floor( $images_optimized / $images_total * 100 ) )
			: 0;
		$percentage = max( 0, min( 100, $percentage ) );

		if ( 0 === $images_total ) {
			$status = 'empty';
			$label = '';
			$panda = 'panda-waiting.png';
		} elseif ( 0 === $images_remaining ) {
			$status = 'done';
			$label = __( 'all done', 'tiny-compress-images' );
			$panda = 'panda-laying.png';
		} else {
			$status = 'in_progress';
			$label = $percentage >= 50
				? __( 'almost there', 'tiny-compress-images' )
				: __( 'keep going', 'tiny-compress-images' );
			$panda = 'panda-eating.png';
		}

		return array(
			'status' => $status,
			'images_optimized' => $images_optimized,
			'images_total' => $images_total,
			'images_remaining' => $images_remaining,
			'bytes_saved' => max( 0, intval( $bytes_saved ) ),
			'percentage' => $percentage,
			'label' => $label,
			'panda' => $panda,
		);
	}
}
