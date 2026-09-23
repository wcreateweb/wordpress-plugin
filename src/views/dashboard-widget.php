<?php

/**
 * Dashboard Widget
 *
 * @var array $widget See Tiny_Dashboard::get_widget_data().
 *
 */
$percentage = $widget['percentage'];
$label = $widget['label'];

$bulk_url = admin_url('upload.php?page=tiny-bulk-optimization');
$settings_url = admin_url('options-general.php?page=tinify');
?>

<div class="tiny-widget">
	<?php if ('in_progress' === $widget['status']) { ?>
		<div class="tiny-widget-bulk">
			<span><?php esc_html_e('You’re leaving performance on the table.', 'tiny-compress-images'); ?></span>
			<a class="tiny-widget-bulk-button" href="<?php echo esc_url($bulk_url); ?>">
				<?php esc_html_e('Bulk Optimizer', 'tiny-compress-images'); ?>
				<svg width="9" height="16" viewBox="0 0 9 16" aria-hidden="true" focusable="false">
					<path d="M8.694 8.739 1.775 15.694a1.04 1.04 0 0 1-1.47-1.478L6.489 8 .306 1.784A1.04 1.04 0 0 1 1.777.306l6.919 6.955a1.05 1.05 0 0 1-.002 1.478Z" fill="currentColor" />
				</svg>
			</a>
			<span>
				<?php
				printf(
					/* translators: %s: number of images that can still be optimized */
					wp_kses(__('Optimize the remaining <strong>%s images</strong>.', 'tiny-compress-images'), array('strong' => array())),
					esc_html(number_format_i18n($widget['images_remaining']))
				);
				?>
			</span>
		</div>
	<?php } ?>

	<div class="tiny-widget-body">
		<div class="tiny-widget-main">
			<?php if ('empty' === $widget['status']) { ?>
				<div class="tiny-widget-stats">
					<strong class="tiny-widget-title"><?php esc_html_e('George is hungry', 'tiny-compress-images'); ?></strong>
					<span><?php esc_html_e('There are no images uploaded yet.', 'tiny-compress-images'); ?></span>
				</div>
			<?php } else { ?>
				<div class="tiny-widget-progress">
					<?php require __DIR__ . '/progress-circle.php'; ?>
					<div class="tiny-widget-stats">
						<strong class="tiny-widget-title">
							<?php
							printf(
								/* translators: 1: number of optimized images, 2: total number of images */
								esc_html__('%1$s of %2$s images', 'tiny-compress-images'),
								esc_html(number_format_i18n($widget['images_optimized'])),
								esc_html(number_format_i18n($widget['images_total']))
							);
							?>
						</strong>
						<span>
							<?php
							printf(
								/* translators: %s: bytes saved, e.g. 21.7 MB */
								esc_html__('%s saved', 'tiny-compress-images'),
								esc_html(size_format($widget['bytes_saved'], 1))
							);
							?>
						</span>
					</div>
				</div>
			<?php } ?>

			<nav class="tiny-widget-nav">
				<a href="<?php echo esc_url($bulk_url); ?>"><?php esc_html_e('Optimize Library', 'tiny-compress-images'); ?></a>
				<a href="<?php echo esc_url($settings_url); ?>"><?php esc_html_e('Settings', 'tiny-compress-images'); ?></a>
			</nav>
		</div>

		<img
			class="tiny-widget-panda tiny-widget-<?php echo esc_attr(basename($widget['panda'], '.png')); ?>"
			src="<?php echo esc_url(plugins_url('/images/' . $widget['panda'], __DIR__)); ?>"
			alt="" />
	</div>
</div>
