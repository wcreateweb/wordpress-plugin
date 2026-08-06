<?php
/**
 * The Images panel header.
 *
 * A pure function of the report, so a fresh scan and a single re-classified row produce the
 * same thing and can never disagree. Re-rendered on its own after every Optimize, which is
 * why it is a fragment rather than part of the panel view.
 *
 * @var array       $summary        Totals from Tiny_Page_Scan::summarize().
 * @var string|null $account_notice Account-wide blocker, or null.
 */

?>
<div class="tiny-images-header" data-tiny-fragment="header"
	data-summary="<?php echo esc_attr( wp_json_encode( $summary ) ); ?>">

	<?php if ( ! is_null( $account_notice ) ) : ?>
		<p class="tiny-images-account">
			<span class="tiny-images-account-icon" aria-hidden="true">!</span>
			<?php echo esc_html( $account_notice ); ?>
		</p>
	<?php endif; ?>

	<?php if ( 0 === $summary['images'] ) : ?>

		<p class="tiny-images-count">
			<?php esc_html_e( 'No images on this page', 'tiny-compress-images' ); ?>
		</p>

	<?php else : ?>

		<p class="tiny-images-count">
			<?php
			/* translators: %d: number of images found on the page. */
			$tiny_images_text = _n(
				'<strong>%d</strong> image on this page',
				'<strong>%d</strong> images on this page',
				$summary['images'],
				'tiny-compress-images'
			);

			printf(
				wp_kses( $tiny_images_text, array( 'strong' => array() ) ),
				absint( $summary['images'] )
			);
			?>
		</p>

		<?php if ( $summary['optimizable_sizes'] > 0 ) : ?>

			<p class="tiny-images-sub">
				<?php
				/* translators: %d: number of image sizes that can still be optimized. */
				$tiny_sizes_text = _n(
					'%d size can be optimized',
					'%d sizes can be optimized',
					$summary['optimizable_sizes'],
					'tiny-compress-images'
				);

				printf(
					esc_html( $tiny_sizes_text ),
					absint( $summary['optimizable_sizes'] )
				);
				?>
			</p>

			<button type="button" class="tiny-images-button tiny-images-optimize-all">
				<?php esc_html_e( 'Optimize all', 'tiny-compress-images' ); ?>
			</button>

		<?php elseif ( 0 === $summary['resolved_rows'] ) : ?>

			<p class="tiny-images-sub">
				<?php esc_html_e( 'None are in your media library', 'tiny-compress-images' ); ?>
			</p>

		<?php elseif ( $summary['saved_bytes'] > 0 ) : ?>

			<p class="tiny-images-sub">
				<?php
				printf(
					/* translators: %s: total bytes saved, already formatted. */
					esc_html__( 'All sizes optimized &middot; saved %s', 'tiny-compress-images' ),
					esc_html( size_format( $summary['saved_bytes'], 1 ) )
				);
				?>
			</p>

		<?php else : ?>

			<p class="tiny-images-sub">
				<?php esc_html_e( 'All sizes optimized', 'tiny-compress-images' ); ?>
			</p>

		<?php endif; ?>

	<?php endif; ?>
</div>
