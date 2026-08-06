<?php
/*
* Tiny Compress Images - WordPress plugin.
* Copyright (C) 2015-2026 Tinify B.V.
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

/**
 * The Images node in the front-end admin bar.
 *
 * Registration and asset loading only -- the scan lives in Tiny_Page_Scan and the request
 * handling in Tiny_Plugin. This is the plugin's first and only wp_enqueue_scripts callback,
 * so it runs on every front-end page view by a logged-in editor. Keeping it this short is
 * what keeps "what did we add to every page load?" answerable.
 *
 * Nothing is scanned here. The panel is empty markup until the user opens it.
 *
 * @since 3.8.0
 */
class Tiny_Admin_Bar extends Tiny_WP_Base {

	const NODE_ID = 'tiny-images';

	public function init() {
		if ( is_admin() ) {
			return;
		}

		add_action( 'admin_bar_menu', $this->get_method( 'add_node' ), 100 );
		add_action( 'wp_enqueue_scripts', $this->get_method( 'enqueue_scripts' ) );
	}

	/**
	 * Whether this viewer gets the node at all.
	 *
	 * @return bool
	 */
	private function is_available() {
		return is_user_logged_in()
			&& current_user_can( 'upload_files' )
			&& is_admin_bar_showing();
	}

	/**
	 * @param WP_Admin_Bar $wp_admin_bar
	 */
	public function add_node( $wp_admin_bar ) {
		if ( ! $this->is_available() ) {
			return;
		}

		$wp_admin_bar->add_node(
			array(
				'id'    => self::NODE_ID,
				'title' => esc_html__( 'Images', 'tiny-compress-images' ),
				'href'  => false,
				'meta'  => array(
					'class' => 'tiny-images-node',
				),
			)
		);

		$wp_admin_bar->add_node(
			array(
				'parent' => self::NODE_ID,
				'id'     => self::NODE_ID . '-panel',
				'title'  => '<div class="tiny-images-panel" data-state="idle"></div>',
			)
		);
	}

	public function enqueue_scripts() {
		if ( ! $this->is_available() ) {
			return;
		}

		wp_enqueue_style(
			self::NAME . '_admin_bar',
			plugins_url( '/css/admin-bar.css', __FILE__ ),
			array(),
			Tiny_Plugin::version()
		);

		wp_register_script(
			self::NAME . '_admin_bar',
			plugins_url( '/js/admin-bar.js', __FILE__ ),
			array(),
			Tiny_Plugin::version(),
			true
		);

		wp_localize_script(
			self::NAME . '_admin_bar',
			'tinyImages',
			array(
				'ajaxurl'          => admin_url( 'admin-ajax.php' ),
				'nonce'            => wp_create_nonce( 'tiny-compress' ),
				'L10nScanning'     => __( 'Looking at this page', 'tiny-compress-images' ),
				'L10nOptimizing'   => __( 'Optimizing', 'tiny-compress-images' ),
				'L10nCancel'       => __( 'Cancel', 'tiny-compress-images' ),
				'L10nCancelled'    => __( 'Cancelled', 'tiny-compress-images' ),
				'L10nRequestError' => __(
					'Could not reach your site. Please try again.',
					'tiny-compress-images'
				),
			)
		);

		wp_enqueue_script( self::NAME . '_admin_bar' );
	}
}
