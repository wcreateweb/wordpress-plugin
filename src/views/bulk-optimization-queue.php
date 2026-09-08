<?php
/**
 * Queue based bulk optimization view.
 *
 * The state this renders is worked out in Tiny_Plugin::bulk_queue_display(),
 * the same source the script uses when it polls, so the page arrives looking
 * the way it will keep looking.
 *
 * @var array       $progress
 * @var array       $stats
 * @var int         $remaining_credits
 * @var Tiny_Plugin $this
 */

$settings_url = admin_url( 'options-general.php?page=tinify' );

$total_savings = $stats['unoptimized-library-size'] - $stats['optimized-library-size'];

$display = $progress['display'];
$details = $display['details'];
$actions = $display['actions'];

$hide_details = '' === $details['text'];

/* Only some states have anything to say; the row goes when they do not. */
$hide_status = '' === $display['label'] && '' === $display['icon']
	&& ! $display['spinner'];
?>

<div class="wrap tiny-bulk-queue" id="tiny-bulk-optimization-queue">
	<div class="tiny-queue-header">
		<h1><?php esc_html_e( 'Bulk Optimization', 'tiny-compress-images' ); ?></h1>
		<p>
			<?php
			esc_html_e(
				'Improve load times by optimizing your image library.',
				'tiny-compress-images'
			);
			?>
		</p>
	</div>

	<div class="tiny-queue-columns">
		<div
			class="tiny-queue-card<?php echo $display['empty'] ? ' is-empty' : ''; ?>"
			id="tiny-queue-card"
		>
			<div class="tiny-queue-actions">
				<button
					type="button"
					class="button button-primary"
					id="tiny-queue-start"
					<?php echo $actions['start'] ? '' : 'hidden'; ?>
				>
					<svg width="20" height="17" viewBox="0 0 20 17" aria-hidden="true" focusable="false" xmlns="http://www.w3.org/2000/svg">
						<path d="M19.9236 7.61721C19.7689 7.24409 19.4042 7.00035 19.0002 7.00035L15.326 6.99941L16.962 1.2743C17.0866 0.835563 16.8991 0.368699 16.5073 0.138063C16.1164 -0.0925594 15.6157 -0.0297477 15.2932 0.292747L7.29369 8.29326C7.00775 8.57919 6.92244 9.00951 7.07713 9.38263C7.23181 9.75575 7.5965 9.99949 8.00056 9.99949H12.4837L10.0808 15.6056C9.89241 16.0462 10.0424 16.559 10.439 16.8271C10.6096 16.9424 10.8046 16.9987 10.9996 16.9987C11.2574 16.9987 11.5133 16.8993 11.7074 16.7052L19.7069 8.70566C19.9929 8.42066 20.0792 7.99127 19.9236 7.61721Z" />
						<path d="M7.00021 15H1.0003C0.448121 15 0 15.4472 0 16.0003C0 16.5534 0.448121 17.0006 1.0003 17.0006H7.00021C7.55239 17.0006 8.00051 16.5534 8.00051 16.0003C8.00051 15.4472 7.55239 15 7.00021 15Z" />
						<path d="M2.00079 12H5.00075C5.55293 12 6.00105 11.5528 6.00105 10.9997C6.00105 10.4466 5.55293 9.99939 5.00075 9.99939H2.00079C1.44861 9.99939 1.00049 10.4466 1.00049 10.9997C1.00049 11.5528 1.44861 12 2.00079 12Z" />
						<path d="M4.0003 1.99986H11.0005C11.5536 1.99986 12.0008 1.55173 12.0008 0.999551C12.0008 0.447368 11.5527 0.000183105 11.0005 0.000183105H4.0003C3.44812 0.000183105 3 0.448304 3 1.00049C3 1.55267 3.44812 1.99986 4.0003 1.99986Z" />
						<path d="M2.99982 7.00043H5.99977C6.55195 7.00043 7.00007 6.5523 7.00007 6.00012C7.00007 5.44794 6.55195 4.99982 5.99977 4.99982H2.99982C2.44763 4.99982 1.99951 5.44794 1.99951 6.00012C1.99951 6.5523 2.44763 7.00043 2.99982 7.00043Z" />
					</svg>
					<?php esc_html_e( 'Optimize My Library', 'tiny-compress-images' ); ?>
				</button>
				<span
					class="tiny-queue-run-spinner"
					id="tiny-queue-run-spinner"
					aria-hidden="true"
					<?php echo $display['running'] ? '' : 'hidden'; ?>
				>
					<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg">
						<mask id="tiny-queue-spinner-track" fill="white">
							<path d="M18 10C18 11.5823 17.5308 13.129 16.6518 14.4446C15.7727 15.7602 14.5233 16.7855 13.0615 17.391C11.5997 17.9965 9.99113 18.155 8.43928 17.8463C6.88743 17.5376 5.46197 16.7757 4.34315 15.6569C3.22433 14.538 2.4624 13.1126 2.15372 11.5607C1.84504 10.0089 2.00346 8.40034 2.60896 6.93853C3.21446 5.47672 4.23985 4.22729 5.55544 3.34824C6.87103 2.46919 8.41775 2 10 2V3.6C8.7342 3.6 7.49683 3.97535 6.44435 4.67859C5.39188 5.38184 4.57157 6.38138 4.08717 7.55083C3.60277 8.72027 3.47603 10.0071 3.72297 11.2486C3.96992 12.4901 4.57946 13.6304 5.47452 14.5255C6.36957 15.4205 7.50994 16.0301 8.75142 16.277C9.9929 16.524 11.2797 16.3972 12.4492 15.9128C13.6186 15.4284 14.6182 14.6081 15.3214 13.5556C16.0246 12.5032 16.4 11.2658 16.4 10H18Z"/>
						</mask>
						<path d="M18 10C18 11.5823 17.5308 13.129 16.6518 14.4446C15.7727 15.7602 14.5233 16.7855 13.0615 17.391C11.5997 17.9965 9.99113 18.155 8.43928 17.8463C6.88743 17.5376 5.46197 16.7757 4.34315 15.6569C3.22433 14.538 2.4624 13.1126 2.15372 11.5607C1.84504 10.0089 2.00346 8.40034 2.60896 6.93853C3.21446 5.47672 4.23985 4.22729 5.55544 3.34824C6.87103 2.46919 8.41775 2 10 2V3.6C8.7342 3.6 7.49683 3.97535 6.44435 4.67859C5.39188 5.38184 4.57157 6.38138 4.08717 7.55083C3.60277 8.72027 3.47603 10.0071 3.72297 11.2486C3.96992 12.4901 4.57946 13.6304 5.47452 14.5255C6.36957 15.4205 7.50994 16.0301 8.75142 16.277C9.9929 16.524 11.2797 16.3972 12.4492 15.9128C13.6186 15.4284 14.6182 14.6081 15.3214 13.5556C16.0246 12.5032 16.4 11.2658 16.4 10H18Z" stroke="#E1E1E1" stroke-width="4" mask="url(#tiny-queue-spinner-track)"/>
						<mask id="tiny-queue-spinner-head" fill="white">
							<path d="M10 2C11.0506 2 12.0909 2.20693 13.0615 2.60896C14.0321 3.011 14.914 3.60028 15.6569 4.34315C16.3997 5.08602 16.989 5.96793 17.391 6.93853C17.7931 7.90914 18 8.94943 18 10H16.4C16.4 9.15954 16.2345 8.32731 15.9128 7.55083C15.5912 6.77434 15.1198 6.06881 14.5255 5.47452C13.9312 4.88022 13.2257 4.4088 12.4492 4.08717C11.6727 3.76554 10.8405 3.6 10 3.6V2Z"/>
						</mask>
						<path d="M10 2C11.0506 2 12.0909 2.20693 13.0615 2.60896C14.0321 3.011 14.914 3.60028 15.6569 4.34315C16.3997 5.08602 16.989 5.96793 17.391 6.93853C17.7931 7.90914 18 8.94943 18 10H16.4C16.4 9.15954 16.2345 8.32731 15.9128 7.55083C15.5912 6.77434 15.1198 6.06881 14.5255 5.47452C13.9312 4.88022 13.2257 4.4088 12.4492 4.08717C11.6727 3.76554 10.8405 3.6 10 3.6V2Z" stroke="#3858E9" stroke-width="4" mask="url(#tiny-queue-spinner-head)"/>
					</svg>
				</span>
				<button
					type="button"
					class="button"
					id="tiny-queue-pause"
					<?php echo $actions['pause'] ? '' : 'hidden'; ?>
				>
					<?php esc_html_e( 'Pause', 'tiny-compress-images' ); ?>
				</button>
				<button
					type="button"
					class="button button-primary"
					id="tiny-queue-resume"
					<?php echo $actions['resume'] ? '' : 'hidden'; ?>
				>
					<?php esc_html_e( 'Resume', 'tiny-compress-images' ); ?>
				</button>
				<button
					type="button"
					class="button"
					id="tiny-queue-cancel"
					<?php echo $actions['cancel'] ? '' : 'hidden'; ?>
				>
					<?php esc_html_e( 'Cancel optimization', 'tiny-compress-images' ); ?>
				</button>
			</div>

			<div class="tiny-queue-progress-block">
				<p
					class="tiny-queue-status"
					id="tiny-queue-status"
					<?php echo $hide_status ? 'hidden' : ''; ?>
				>
					<span class="tiny-queue-status-icon" id="tiny-queue-status-icon">
						<?php if ( $display['spinner'] ) : ?>
							<span class="tiny-queue-spinner"></span>
						<?php elseif ( '' !== $display['icon'] ) : ?>
							<span class="dashicons <?php echo esc_attr( $display['icon'] ); ?>"></span>
						<?php endif; ?>
					</span>
					<span class="tiny-queue-status-label" id="tiny-queue-status-label">
						<?php echo esc_html( $display['label'] ); ?>
					</span>
				</p>

				<p
					class="tiny-queue-subtitle"
					id="tiny-queue-subtitle"
					<?php echo '' === $display['subtitle'] ? 'hidden' : ''; ?>
				><?php echo esc_html( $display['subtitle'] ); ?></p>

				<div
					class="tiny-queue-progress"
					id="tiny-queue-progress"
					<?php echo $display['empty'] ? 'hidden' : ''; ?>
				>
					<div class="tiny-queue-track">
						<div
							class="tiny-queue-fill<?php echo $display['complete'] ? ' is-complete' : ''; ?>"
							id="tiny-queue-fill"
							style="width: <?php echo esc_attr( $display['percentage'] ); ?>%;"
						></div>
					</div>
					<div class="tiny-queue-progress-labels">
						<span
							class="tiny-queue-percentage<?php echo $display['complete'] ? ' is-complete' : ''; ?>"
							id="tiny-queue-percentage"
						><?php echo esc_html( $display['left'] ); ?></span>
						<span class="tiny-queue-remaining" id="tiny-queue-remaining">
							<?php echo esc_html( $display['right'] ); ?>
						</span>
					</div>
				</div>

				<p
					class="tiny-queue-details<?php echo $details['error'] ? ' is-error' : ''; ?>"
					id="tiny-queue-details"
					<?php echo $hide_details ? 'hidden' : ''; ?>
				>
					<?php
					echo esc_html( $details['text'] );

					if ( '' !== $details['name'] ) {
						echo ' <strong>' . esc_html( $details['name'] ) . '</strong>';
					}
					?>
				</p>
			</div>

			<div
				class="tiny-queue-list"
				id="tiny-queue-list"
				<?php echo $display['list'] ? '' : 'hidden'; ?>
			>
				<h2>
					<?php
					esc_html_e( 'Optimization Queue', 'tiny-compress-images' );
					?>
				</h2>
				<div class="tiny-queue-rows" id="tiny-queue-rows"></div>
			</div>
		</div>

		<div class="tiny-queue-sidebar">
			<div class="tiny-queue-panel">
				<h2><?php esc_html_e( 'Statistics', 'tiny-compress-images' ); ?></h2>

				<div class="tiny-queue-stats">
					<div class="tiny-queue-stat">
						<span><?php esc_html_e( 'Uploaded images', 'tiny-compress-images' ); ?></span>
						<strong><?php echo esc_html( number_format_i18n( $stats['uploaded-images'] ) ); ?></strong>
					</div>
					<div class="tiny-queue-stat">
						<span><?php esc_html_e( 'Initial library size', 'tiny-compress-images' ); ?></span>
						<strong><?php echo esc_html( size_format( $stats['unoptimized-library-size'], 2 ) ); ?></strong>
					</div>
					<div class="tiny-queue-stat">
						<span><?php esc_html_e( 'Current size', 'tiny-compress-images' ); ?></span>
						<strong><?php echo esc_html( size_format( $stats['optimized-library-size'], 2 ) ); ?></strong>
					</div>
					<div class="tiny-queue-stat">
						<span><?php esc_html_e( 'Total savings', 'tiny-compress-images' ); ?></span>
						<strong class="tiny-queue-savings">
							<?php
							printf(
								'%s (%s%%)',
								esc_html( size_format( max( 0, $total_savings ), 1 ) ),
								esc_html( $stats['display-percentage'] )
							);
							?>
						</strong>
					</div>
					<div class="tiny-queue-stat">
						<span><?php esc_html_e( 'Credits remaining', 'tiny-compress-images' ); ?></span>
						<strong>
							<?php
							printf(
								/* translators: %s: number of credits left this month */
								esc_html__( '%s credits', 'tiny-compress-images' ),
								esc_html( number_format_i18n( $remaining_credits ) )
							);
							?>
						</strong>
					</div>
				</div>

				<hr>

				<p class="tiny-queue-note">
					<?php
					printf(
						wp_kses(
							/* translators: %s: link to settings page saying here */
							__( 'Configure compression settings %s.', 'tiny-compress-images' ),
							array(
								'a' => array(
									'href' => array(),
								),
							)
						),
						'<a href="' . esc_url( $settings_url ) . '">' .
							esc_html__( 'here', 'tiny-compress-images' ) . '</a>'
					)
					?>
				</p>
			</div>
		</div>
	</div>

	<script type="text/javascript">
	<?php
	/* The script itself is enqueued in the footer, so wait for it. */
	echo 'document.addEventListener( "DOMContentLoaded", function () { tinyBulkQueue(' .
		wp_json_encode( $progress, JSON_HEX_TAG | JSON_HEX_AMP ) . ') } )';
	?>
	</script>
</div>
