<?php
/**
 * One row of the Images panel: one attachment, or one reference that resolved to nothing.
 *
 * Re-rendered on its own after an Optimize, and deliberately keeps its position in the list
 * when it does -- nothing in the panel labels the ordering, so a row that changes state does
 * not have to move out from under the cursor.
 *
 * @var array $row A row from Tiny_Page_Scan.
 */

$tiny_state_labels = array(
	'optimizable' => __( 'can optimize', 'tiny-compress-images' ),
	'optimized'   => __( 'optimized', 'tiny-compress-images' ),
	'unsupported' => __( 'not supported', 'tiny-compress-images' ),
	'unresolved'  => __( 'not in library', 'tiny-compress-images' ),
);

$tiny_sizes_to_send = array();
$tiny_excluded      = 0;
$tiny_has_original  = false;

foreach ( $row['referenced_sizes'] as $tiny_size ) {
	$tiny_sizes_to_send[] = $tiny_size['size'];

	if ( $tiny_size['pending'] && ! $tiny_size['active'] ) {
		++$tiny_excluded;
		if ( Tiny_Image::is_original( $tiny_size['size'] )
			|| Tiny_Image::is_original_unscaled( $tiny_size['size'] ) ) {
			$tiny_has_original = true;
		}
	}
}

$tiny_title = is_null( $row['title'] ) || '' === $row['title'] ? $row['url'] : $row['title'];

?>
<li class="tiny-images-row" data-tiny-fragment="row"
	data-state="<?php echo esc_attr( $row['state'] ); ?>"
	data-id="<?php echo esc_attr( $row['attachment_id'] ); ?>"
	data-sizes="<?php echo esc_attr( wp_json_encode( $tiny_sizes_to_send ) ); ?>">

	<span class="tiny-images-thumb">
		<?php if ( $row['thumbnail'] ) : ?>
			<img src="<?php echo esc_url( $row['thumbnail'] ); ?>" alt="" />
		<?php else : ?>
			<span class="tiny-images-thumb-blank"></span>
		<?php endif; ?>
	</span>

	<span class="tiny-images-body">
		<span class="tiny-images-title" title="<?php echo esc_attr( $tiny_title ); ?>">
			<?php echo esc_html( $tiny_title ); ?>
		</span>

		<span class="tiny-images-line">
			<span class="tiny-images-chip tiny-images-chip--<?php echo esc_attr( $row['state'] ); ?>">
				<?php echo esc_html( $tiny_state_labels[ $row['state'] ] ); ?>
			</span>

			<span class="tiny-images-meta">
				<?php
				if ( 'unresolved' === $row['state'] ) {
					echo esc_html( wp_basename( (string) wp_parse_url( $row['url'], PHP_URL_PATH ) ) );
				} else {
					$tiny_meta = array( $row['format'] );

					if ( ! empty( $row['referenced_sizes'] ) ) {
						$tiny_count = count( $row['referenced_sizes'] );

						/* translators: %d: number of image sizes this page references. */
						$tiny_size_count_text = _n(
							'%d size',
							'%d sizes',
							$tiny_count,
							'tiny-compress-images'
						);

						$tiny_meta[] = sprintf( $tiny_size_count_text, $tiny_count );
						$tiny_meta[] = size_format( $row['total_bytes'], 0 );
					}

					echo esc_html( implode( ' · ', $tiny_meta ) );
				}
				?>
			</span>

			<?php if ( 'optimizable' === $row['state'] ) : ?>
				<button type="button" class="tiny-images-action tiny-images-optimize">
					<?php esc_html_e( 'Optimize', 'tiny-compress-images' ); ?>
				</button>
			<?php endif; ?>
		</span>

		<?php if ( ! is_null( $row['reason'] ) && $row['failed'] > 0 ) : ?>
			<span class="tiny-images-reason"
				<?php if ( $row['error_message'] ) : ?>
					title="<?php echo esc_attr( $row['error_message'] ); ?>"
				<?php endif; ?>>
				<?php
				if ( 'rejected' === $row['reason'] ) {
					/* translators: %d: number of image sizes that could not be optimized. */
					$tiny_reason_text = _n(
						'%d size could not be optimized',
						'%d sizes could not be optimized',
						$row['failed'],
						'tiny-compress-images'
					);
				} elseif ( 'local' === $row['reason'] ) {
					/* translators: %d: number of image sizes that could not be read from disk. */
					$tiny_reason_text = _n(
						'%d size could not be read from disk',
						'%d sizes could not be read from disk',
						$row['failed'],
						'tiny-compress-images'
					);
				} else {
					/* translators: %d: number of image sizes that failed and can be retried. */
					$tiny_reason_text = _n(
						'%d size failed, try again',
						'%d sizes failed, try again',
						$row['failed'],
						'tiny-compress-images'
					);
				}

				printf( esc_html( $tiny_reason_text ), absint( $row['failed'] ) );
				?>
			</span>
		<?php endif; ?>

		<?php if ( ! empty( $row['referenced_sizes'] ) ) : ?>
			<span class="tiny-images-sizes">
				<?php foreach ( $row['referenced_sizes'] as $tiny_size ) : ?>
					<span class="tiny-images-size<?php echo $tiny_size['pending'] ? '' : ' is-done'; ?>">
						<?php echo esc_html( $tiny_size['label'] ); ?>
					</span>
				<?php endforeach; ?>
			</span>
		<?php endif; ?>

		<?php if ( 'optimizable' === $row['state'] && $tiny_excluded > 0 ) : ?>
			<span class="tiny-images-disclosure">
				<?php
				if ( $tiny_has_original ) {
					esc_html_e(
						'Includes the original image, which your settings exclude. It will be replaced.',
						'tiny-compress-images'
					);
				} else {
					/* translators: %d: number of image sizes excluded by the settings. */
					$tiny_excluded_text = _n(
						'Includes %d size your settings exclude.',
						'Includes %d sizes your settings exclude.',
						$tiny_excluded,
						'tiny-compress-images'
					);

					printf( esc_html( $tiny_excluded_text ), absint( $tiny_excluded ) );
				}
				?>
			</span>
		<?php endif; ?>
	</span>
</li>
