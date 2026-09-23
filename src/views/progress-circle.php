<?php
/**
 * Progress Circle
 *
 * @var int    $percentage Completion, 0-100.
 * @var string $label      Optional caption below the percentage.
 */
?>
<style>
div.tiny-progress-circle {
	position: relative;
	width: 117px;
	height: 117px;
	flex-shrink: 0;
}

div.tiny-progress-circle svg {
	display: block;
	transform: rotate(-90deg);
}

div.tiny-progress-circle circle {
	fill: none;
	stroke-width: 21.31;
}

div.tiny-progress-circle circle.track {
	stroke: #e1e1e1;
}

div.tiny-progress-circle circle.progress {
	stroke: #00d974;
	stroke-linecap: round;
	transition: stroke-dasharray 0.6s ease;
}

div.tiny-progress-circle div.value {
	position: absolute;
	top: 0;
	left: 0;
	display: flex;
	width: 100%;
	height: 100%;
	flex-direction: column;
	align-items: center;
	justify-content: center;
	line-height: 17px;
}

div.tiny-progress-circle div.percentage {
	font-size: 24px;
	font-weight: bold;
	color: #1e1e1e;
}

div.tiny-progress-circle div.label {
	font-size: 11px;
	color: var(--tiny-color-text-muted);
}
</style>

<div class="tiny-progress-circle">
	<svg width="117" height="117" viewBox="0 0 117 117" aria-hidden="true" focusable="false">
		<circle class="track" cx="58.5" cy="58.5" r="47.844" pathLength="100" />
		<?php if ( $percentage > 0 ) { ?>
			<circle class="progress" cx="58.5" cy="58.5" r="47.844" pathLength="100"
				stroke-dasharray="<?php echo esc_attr( $percentage ); ?> 100" />
		<?php } ?>
	</svg>
	<div class="value">
		<div class="percentage"><?php echo esc_html( $percentage ); ?>%</div>
		<?php if ( ! empty( $label ) ) { ?>
			<div class="label"><?php echo esc_html( $label ); ?></div>
		<?php } ?>
	</div>
</div>
