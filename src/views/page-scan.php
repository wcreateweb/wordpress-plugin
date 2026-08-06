<?php
/**
 * The Images panel, rendered whole when the panel is opened.
 *
 * Afterwards only its fragments are replaced: the header and one row at a time.
 *
 * @var array       $report         Ordered rows from Tiny_Page_Scan::scan().
 * @var array       $summary        Totals from Tiny_Page_Scan::summarize().
 * @var string|null $account_notice Account-wide blocker, or null.
 */

require __DIR__ . '/page-scan-header.php';

if ( ! empty( $report ) ) {
	echo '<ul class="tiny-images-rows">';
	foreach ( $report as $row ) {
		include __DIR__ . '/page-scan-row.php';
	}
	echo '</ul>';
}

if ( $summary['optimizable_sizes'] > 0 ) {
	echo '<p class="tiny-images-help">';
	esc_html_e( 'Reload the page to see the optimized images.', 'tiny-compress-images' );
	echo '</p>';
}
