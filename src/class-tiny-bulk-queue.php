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
 * Optimizes the media library from a queue instead of from the browser.
 */
class Tiny_Bulk_Queue extends Tiny_Vendor_WP_Background_Process {

	/* Queue state. The compression results themselves stay in Tiny_Config::META_KEY. */
	const META_STATUS   = '_tinywp_status';
	const META_ATTEMPTS = '_tinywp_attempts';
	const META_CLAIMED  = '_tinywp_claimed';
	const META_ERROR    = '_tinywp_error';
	const META_PROGRESS = '_tinywp_progress';

	const STATUS_PENDING    = 'pending';
	const STATUS_PROCESSING = 'processing';
	const STATUS_DONE       = 'done';
	const STATUS_FAILED     = 'failed';
	const STATUS_SKIPPED    = 'skipped';

	/* Attachments claimed per round trip to the database. */
	const BATCH_SIZE = 20;

	/* Attempts an image gets before it is left alone for the rest of the run. */
	const MAX_ATTEMPTS = 3;

	/*
	How long a claim is honoured. A process that dies mid image leaves the row
		in 'processing'; after this it is handed back to the queue. Keep it above
		the time a single image can reasonably take. */
	const CLAIM_TIMEOUT = 300;

	/*
	Summary of the current run. Written at the boundaries of a run and, for the
		table of recent results, once per image. The counts themselves are never
		stored: they are queried from postmeta. */
	const RUN_OPTION = 'tinypng_bulk_queue_run';

	/* Number of images the page shows ahead of the one being optimized. */
	const QUEUE_PREVIEW = 10;

	/* Row details for images the queue has not reached, kept between polls. */
	const PREVIEW_CACHE = 'tinypng_bulk_queue_preview';

	/* How long those details are trusted without being worked out again. */
	const PREVIEW_CACHE_LIFE = 300;

	/*
	How long a run may show no progress before it is reported as stuck. Longer
		than the cron health check interval, which restarts a chain that died. */
	const STALL_AFTER = 600;

	protected $prefix = 'tinypng';
	protected $action = 'bulk_queue';

	/*
	An image with many sizes takes longer than the 60 seconds the library locks
		for by default, which would let a second process pick up the same work
		while the first one is still busy with it. */
	protected $queue_lock_time = 300;

	/**
	 * Tinify settings.
	 *
	 * @var Tiny_Settings
	 */
	private $settings;

	/**
	 * Chain ID for runs started outside of the queue's own loopback request.
	 *
	 * @var string
	 */
	private $started_chain_id;

	/**
	 * Attachment this process is compressing right now.
	 *
	 * @var int|null
	 */
	private $processing_id;

	public function __construct( $settings ) {
		$this->settings = $settings;
		parent::__construct();

		add_action(
			'tiny_image_size_compressed',
			array( $this, 'record_size_progress' ),
			10,
			3
		);
	}

	/**
	 * Note how far along the image being compressed is.
	 *
	 * An image with many sizes takes a while, and without this the page would
	 * show the same row as busy for a minute with nothing to say about it.
	 *
	 * Other things compress images too, on upload or from the media library, so
	 * only the image this process claimed is recorded.
	 *
	 * @param int $id        Attachment ID.
	 * @param int $processed Sizes processed so far.
	 * @param int $total     Sizes this compression will process.
	 */
	public function record_size_progress( $id, $processed, $total ) {
		if ( intval( $id ) !== $this->processing_id || $total < 1 ) {
			return;
		}

		update_post_meta(
			$id,
			self::META_PROGRESS,
			intval( round( $processed / $total * 100 ) )
		);
	}

	/* ---------------------------------------------------------------------
	 * Starting and stopping a run
	 * ------------------------------------------------------------------ */

	/**
	 * Start optimizing.
	 *
	 * @param int[]|null $ids Attachments to optimize, or null for the whole library.
	 * @return array The state the run starts out with.
	 */
	public function start( $ids = null ) {
		/*
		A cancel or pause only records a flag; the handler that clears it runs
			in the loopback request. If that request never arrived the flag is
			still set, and it would stop this run before it claimed anything.
			Starting is an explicit instruction, so clear it here. */
		delete_site_option( $this->get_status_key() );

		$this->clear_queue_meta();

		$mode = is_array( $ids ) ? 'selection' : 'all';

		if ( 'selection' === $mode ) {
			$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
			foreach ( $ids as $id ) {
				add_post_meta( $id, self::META_STATUS, self::STATUS_PENDING, true );
			}
		}

		self::save_run(
			array_merge(
				self::empty_run(),
				array(
					'status'     => 'running',
					'mode'       => $mode,
					'started_at' => time(),
				)
			)
		);

		if ( $this->is_queue_empty() ) {
			$this->complete();
			return $this->get_progress();
		}

		$unreachable = $this->loopback_error();

		if ( ! is_null( $unreachable ) ) {
			$run                  = self::get_run();
			$run['status']        = 'unreachable';
			$run['error_message'] = $unreachable;
			self::save_run( $run );

			return $this->get_progress();
		}

		$this->dispatch();

		return $this->get_progress();
	}

	/**
	 * Check that WordPress can reach its own admin-ajax.php.
	 *
	 * @return string|null Why the loopback failed, or null when it works.
	 */
	protected function loopback_error() {
		$url = $this->get_query_url();

		$response = wp_remote_post(
			$url,
			array(
				'timeout'   => 10,
				'blocking'  => true,
				/* An action nobody handles: WordPress answers without side effects. */
				'body'      => array( 'action' => $this->identifier . '_ping' ),
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return sprintf( '%s (%s)', $response->get_error_message(), $url );
		}

		$code = intval( wp_remote_retrieve_response_code( $response ) );

		/* Password protected staging sites answer, but never run the handler. */
		if ( 401 === $code || 403 === $code ) {
			return sprintf( 'HTTP %d (%s)', $code, $url );
		}

		return null;
	}

	/**
	 * Forget what the last run concluded.
	 *
	 * A status records what was left to do for an image under the settings in
	 * force when it was written. Turn on another image size, or switch on
	 * conversion, and that conclusion no longer holds: an image marked done may
	 * well have work waiting again. Rather than let the page go on reporting a
	 * finished library, throw the bookkeeping away so the next run looks at
	 * everything afresh. The compression results themselves are left alone.
	 *
	 * A run that is under way is left to finish; it is working from these very
	 * rows.
	 */
	public function invalidate() {
		if ( $this->is_active() ) {
			return;
		}

		$this->clear_queue_meta();
		delete_site_option( self::RUN_OPTION );
	}

	/**
	 * Stop working through the queue, leaving it as it is.
	 */
	public function pause_run() {
		$this->pause();
	}

	/**
	 * Pick the run back up where it left off.
	 */
	public function resume_run() {
		$this->resume();
	}

	/**
	 * Throw away what is left of the queue, keeping what it already did.
	 */
	public function cancel_run() {
		if ( $this->is_active() ) {
			$this->cancel();
		} else {
			$this->delete_all();
		}
	}

	/**
	 * Progress of the current run.
	 *
	 * @return array
	 */
	public function get_progress() {
		$run    = self::get_run();
		$counts = $this->status_counts();

		/* Images this run has an answer for, against what the library holds. */
		$recorded = array_sum( $counts );
		$library  = $this->image_attachment_count();

		$total = $recorded;

		if ( 'all' === $run['mode'] ) {
			/*
			Everything that has not been given a status yet is still waiting,
				so the library total is the denominator. */
			$total = max( $recorded, $library );
		}

		$run['counts'] = $counts;
		$run['total']  = $total;
		/* Images in the library, whatever any run has made of them. */
		$run['library'] = $library;
		/*
		Images the run actually got to. Cancelling marks whatever was still
			waiting as cancelled, and those were never looked at: counting them
			here would fill the bar to the end and report nothing left to do,
			which is the opposite of what stopping half way means. */
		$run['processed'] = $counts[ self::STATUS_DONE ]
			+ $counts[ self::STATUS_FAILED ]
			+ $counts[ self::STATUS_SKIPPED ];
		$run['optimized'] = $counts[ self::STATUS_DONE ];
		$run['failed']    = $counts[ self::STATUS_FAILED ];
		$run['skipped']   = $counts[ self::STATUS_SKIPPED ];

		$run['is_processing'] = $this->is_processing();
		$run['is_queued']     = ! $this->is_queue_empty( $counts, $library );

		/*
		is_active() would put the same question to the database again, by way of
			is_queued(). It is the answer just worked out, together with the
			flags the library keeps in options. */
		$run['is_active'] = $run['is_queued']
			|| $run['is_processing']
			|| $this->is_paused()
			|| $this->is_cancelled();

		$rows           = $this->list_rows();
		$run['current'] = $rows['current'];
		$run['queued']  = $rows['queued'];

		/*
		Pausing is the library's own flag rather than something written into the
			run, so that a paused run still reads as running everywhere that
			decides whether there is work left to claim. */
		if ( 'running' === $run['status'] && $this->is_paused() ) {
			$run['status'] = 'paused';
		}

		/*
		A run that is neither queued nor holding the lock, and that has not
			reported progress for a while, is never going to finish on its own:
			say so rather than leave the page spinning forever. */
		if ( 'running' === $run['status'] && ! $run['is_active'] ) {
			$last = $run['updated_at'] ? $run['updated_at'] : $run['started_at'];

			if ( $last && ( time() - $last ) > self::STALL_AFTER ) {
				$run['status'] = 'stalled';
			}
		}

		/*
		Finishing is a fact about the run, not about the library. A run clears
			the statuses it works from when it starts, so an image uploaded
			after one finished carries none: it has never been looked at. Rather
			than let a stale 'done' claim the library is processed, report the
			run as over and the work as waiting to be started again. */
		if ( 'done' === $run['status'] && $library > $recorded ) {
			$run['status'] = 'idle';
			$run['total']  = max( $recorded, $library );
		}

		return $run;
	}

	/**
	 * Replaces the library's check against its batch rows in the options table.
	 *
	 * Both counts are queries, and a caller that has just made them can hand
	 * them over rather than have them made again.
	 *
	 * @param array<string,int>|null $counts  Attachments per status.
	 * @param int|null               $library Images the library holds.
	 * @return bool
	 */
	protected function is_queue_empty( $counts = null, $library = null ) {
		$run = self::get_run();

		/* A run that was cancelled or finished leaves nothing claimable. */
		if ( 'running' !== $run['status'] ) {
			return true;
		}

		if ( is_null( $counts ) ) {
			$counts = $this->status_counts();
		}

		if ( $counts[ self::STATUS_PENDING ] > 0 || $counts[ self::STATUS_PROCESSING ] > 0 ) {
			return false;
		}

		if ( 'all' === $run['mode'] ) {
			/*
			Attachments without a status row have not been looked at yet.
				Comparing counts avoids the NOT EXISTS scan on every poll. */
			if ( is_null( $library ) ) {
				$library = $this->image_attachment_count();
			}

			return $library <= array_sum( $counts );
		}

		return true;
	}

	/**
	 * Take the next attachments off the queue.
	 *
	 * Claiming is a compare and set on the status: update_post_meta only writes
	 * when the row still reads 'pending', so two processes cannot walk away with
	 * the same image.
	 *
	 * @param int $limit Maximum number of attachments to claim.
	 * @return int[] Attachment IDs claimed by this process.
	 */
	protected function claim_batch( $limit ) {
		global $wpdb;

		$this->reclaim_stale();

		$run = self::get_run();
		if ( 'all' === $run['mode'] ) {
			$this->mark_next_pending( $limit );
		}

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta
				WHERE meta_key = %s AND meta_value = %s
				LIMIT %d",
				self::META_STATUS,
				self::STATUS_PENDING,
				$limit
			)
		);

		$claimed = array();
		foreach ( $ids as $id ) {
			$id = intval( $id );

			if ( intval( get_post_meta( $id, self::META_ATTEMPTS, true ) ) >= self::MAX_ATTEMPTS ) {
				update_post_meta( $id, self::META_STATUS, self::STATUS_FAILED );
				continue;
			}

			$claimed_by_us = update_post_meta(
				$id,
				self::META_STATUS,
				self::STATUS_PROCESSING,
				self::STATUS_PENDING
			);

			if ( ! $claimed_by_us ) {
				/* Another process got there first. */
				continue;
			}

			update_post_meta( $id, self::META_CLAIMED, time() );
			$claimed[] = $id;
		}

		return $claimed;
	}

	/**
	 * Give a chunk of not yet considered attachments a pending status.
	 *
	 * This is the only query that has to look at wp_posts, and it runs with a
	 * small limit once per batch rather than once per run over the whole library.
	 *
	 * @param int $limit Maximum number of attachments to mark.
	 */
	protected function mark_next_pending( $limit ) {
		foreach ( $this->unmarked_attachments( $limit ) as $id ) {
			add_post_meta( $id, self::META_STATUS, self::STATUS_PENDING, true );
		}
	}

	/**
	 * Attachments the run has not looked at yet, newest first.
	 *
	 * @param int $limit Maximum number of attachments to return.
	 * @return int[]
	 */
	protected function unmarked_attachments( $limit ) {
		global $wpdb;

		$mimes        = self::mime_types();
		$placeholders = implode( ', ', array_fill( 0, count( $mimes ), '%s' ) );

		$query = "SELECT p.ID FROM $wpdb->posts p
			WHERE p.post_type = 'attachment'
				AND p.post_mime_type IN ($placeholders)
				AND NOT EXISTS (
					SELECT 1 FROM $wpdb->postmeta m
					WHERE m.post_id = p.ID AND m.meta_key = %s
				)
			ORDER BY p.ID DESC
			LIMIT %d";

		$args = array_merge( $mimes, array( self::META_STATUS, $limit ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Only the generated list of %s placeholders is interpolated.
		return array_map( 'intval', $wpdb->get_col( $wpdb->prepare( $query, $args ) ) );
	}

	/**
	 * The queue: the image being optimized and the ones lined up behind it.
	 *
	 * Only what is still to come. An image drops off the list once the queue is
	 * done with it, which is the whole of what the list means.
	 *
	 * A process claims a whole batch at once and then works through it one
	 * image at a time, so a claim is not the same as being under way. Only the
	 * images that have reported progress are actually being optimized; the rest
	 * of the batch is still waiting, along with anything else marked pending.
	 *
	 * Nothing else goes in. With no run there is no queue, and images the queue
	 * has not taken up are not waiting for anything.
	 *
	 * @return array{current: array[], queued: array[]}
	 */
	protected function list_rows() {
		$started = array();
		$claimed = array();

		foreach ( $this->ids_with_status( self::STATUS_PROCESSING, self::BATCH_SIZE ) as $id ) {
			if ( metadata_exists( 'post', $id, self::META_PROGRESS ) ) {
				$started[] = $id;
			} else {
				$claimed[] = $id;
			}
		}

		$queued = array_slice(
			array_merge(
				$claimed,
				$this->ids_with_status( self::STATUS_PENDING, self::QUEUE_PREVIEW )
			),
			0,
			self::QUEUE_PREVIEW
		);

		$details = $this->describe_all( array_merge( $started, $queued ) );

		$rows = array(
			'current' => array(),
			'queued'  => array(),
		);

		foreach ( $started as $id ) {
			$row               = $details[ $id ];
			$row['status']     = self::STATUS_PROCESSING;
			$row['progress']   = intval( get_post_meta( $id, self::META_PROGRESS, true ) );
			$rows['current'][] = $row;
		}

		foreach ( $queued as $id ) {
			$row              = $details[ $id ];
			$row['status']    = self::STATUS_PENDING;
			$rows['queued'][] = $row;
		}

		return $rows;
	}

	/**
	 * Describe images for the list, reusing what was worked out last time.
	 *
	 * Describing an image reads its metadata and then asks the filesystem for
	 * the size of every file behind it, which is far more than a poll every
	 * second should be doing for rows that have not moved.
	 *
	 * A description is only good for the compression metadata it was read from,
	 * so each one is kept under a stamp of that metadata. Optimizing an image
	 * changes the stamp and the row is worked out again; nothing has to
	 * remember to clear it, and a worker finishing an image cannot race a poll
	 * writing the cache back.
	 *
	 * @param int[] $ids Attachments to describe.
	 * @return array<int,array> Description per attachment ID.
	 */
	private function describe_all( array $ids ) {
		if ( empty( $ids ) ) {
			return array();
		}

		$cached = get_transient( self::PREVIEW_CACHE );

		if ( ! is_array( $cached ) ) {
			$cached = array();
		}

		/* One query for the metadata the stamps, and the rows, are read from. */
		update_meta_cache( 'post', $ids );

		$details = array();
		$fresh   = array();
		$worked  = false;

		foreach ( $ids as $id ) {
			$stamp = md5(
				maybe_serialize( get_post_meta( $id, Tiny_Config::META_KEY, true ) )
			);

			if ( isset( $cached[ $id ]['stamp'] ) && $cached[ $id ]['stamp'] === $stamp ) {
				$fresh[ $id ] = $cached[ $id ];
			} else {
				$fresh[ $id ] = array(
					'stamp' => $stamp,
					'row'   => $this->describe_in_full( $id ),
				);

				$worked = true;
			}

			$details[ $id ] = $fresh[ $id ]['row'];
		}

		/* Only what the page shows is kept, so the entry cannot grow. */
		if ( $worked || count( $fresh ) !== count( $cached ) ) {
			set_transient( self::PREVIEW_CACHE, $fresh, self::PREVIEW_CACHE_LIFE );
		}

		return $details;
	}

	/**
	 * Attachments sitting in one status, newest first.
	 *
	 * @param string $status One of the STATUS_ constants.
	 * @param int    $limit  Maximum number of attachments to return.
	 * @return int[]
	 */
	protected function ids_with_status( $status, $limit ) {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT post_id FROM $wpdb->postmeta
				WHERE meta_key = %s AND meta_value = %s
				ORDER BY post_id DESC
				LIMIT %d",
				self::META_STATUS,
				$status,
				$limit
			)
		);

		return array_map( 'intval', $ids );
	}

	/**
	 * Hand back claims from processes that died mid image.
	 */
	protected function reclaim_stale() {
		global $wpdb;

		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT status.post_id FROM $wpdb->postmeta status
				INNER JOIN $wpdb->postmeta claimed
					ON claimed.post_id = status.post_id AND claimed.meta_key = %s
				WHERE status.meta_key = %s
					AND status.meta_value = %s
					AND CAST(claimed.meta_value AS UNSIGNED) < %d
				LIMIT %d",
				self::META_CLAIMED,
				self::META_STATUS,
				self::STATUS_PROCESSING,
				time() - self::CLAIM_TIMEOUT,
				self::BATCH_SIZE
			)
		);

		foreach ( $ids as $id ) {
			$this->release( intval( $id ) );
		}
	}

	/**
	 * Put a claimed attachment back on the queue.
	 *
	 * @param int $id Attachment ID.
	 */
	protected function release( $id ) {
		delete_post_meta( $id, self::META_PROGRESS );
		update_post_meta( $id, self::META_STATUS, self::STATUS_PENDING );
	}

	/**
	 * How many attachments sit in each status.
	 *
	 * @return array<string,int>
	 */
	protected function status_counts() {
		global $wpdb;

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT meta_value AS status, COUNT(*) AS total
				FROM $wpdb->postmeta
				WHERE meta_key = %s
				GROUP BY meta_value",
				self::META_STATUS
			),
			ARRAY_A
		);

		$counts = array_fill_keys( self::statuses(), 0 );

		foreach ( (array) $rows as $row ) {
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = intval( $row['total'] );
			}
		}

		return $counts;
	}

	/**
	 * Number of images in the library the plugin can optimize.
	 *
	 * @return int
	 */
	protected function image_attachment_count() {
		global $wpdb;

		$mimes        = self::mime_types();
		$placeholders = implode( ', ', array_fill( 0, count( $mimes ), '%s' ) );

		$query = "SELECT COUNT(*) FROM $wpdb->posts
			WHERE post_type = 'attachment' AND post_mime_type IN ($placeholders)";

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Only the generated list of %s placeholders is interpolated.
		return intval( $wpdb->get_var( $wpdb->prepare( $query, $mimes ) ) );
	}

	/**
	 * Forget the queue, keeping the compression results themselves.
	 */
	protected function clear_queue_meta() {
		global $wpdb;

		delete_transient( self::PREVIEW_CACHE );

		$keys         = self::meta_keys();
		$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

		do {
			$select = "SELECT DISTINCT post_id FROM $wpdb->postmeta
				WHERE meta_key IN ($placeholders)
				LIMIT 500";

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Only the generated list of %s placeholders is interpolated.
			$ids = $wpdb->get_col( $wpdb->prepare( $select, $keys ) );

			if ( empty( $ids ) ) {
				return;
			}

			$ids = array_map( 'intval', $ids );
			$in  = implode( ', ', $ids );

			$delete = "DELETE FROM $wpdb->postmeta
				WHERE meta_key IN ($placeholders) AND post_id IN ($in)";

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Only generated %s placeholders and a list of integers are interpolated.
			$wpdb->query( $wpdb->prepare( $delete, $keys ) );

			foreach ( $ids as $id ) {
				wp_cache_delete( $id, 'post_meta' );
			}
		} while ( true );
	}

	/* ---------------------------------------------------------------------
	 * Processing
	 * ------------------------------------------------------------------ */

	/**
	 * Work through the queue.
	 *
	 * The library's own loop reads and rewrites a batch row in the options
	 * table; this one claims attachments instead. Everything around it, the
	 * lock, the limits and the decision to hand over to the next process, is
	 * kept the same.
	 */
	protected function handle() {
		$this->lock_process();

		/**
		 * Number of seconds to sleep between batches. Defaults to 0 seconds.
		 *
		 * @param int $seconds
		 */
		$throttle = max(
			0,
			apply_filters( $this->identifier . '_seconds_between_batches', 0 )
		);

		$worked = false;

		do {
			$batch = $this->claim_batch( self::BATCH_SIZE );

			foreach ( $batch as $index => $id ) {
				$worked = true;
				$this->task( $id );

				sleep( $throttle );

				if ( ! $this->should_continue() ) {
					/*
					Hand back what this process will not get to, so the next
						one can pick it up right away instead of waiting for
						the claim to go stale. */
					foreach ( array_slice( $batch, $index + 1 ) as $unclaimed ) {
						$this->release( $unclaimed );
					}
					break 2;
				}
			}
		} while ( ! empty( $batch ) && $this->should_continue() );

		$this->unlock_process();

		if ( $this->is_queue_empty() ) {
			$this->complete();
		} elseif ( $worked && ! $this->is_paused() ) {
			$this->dispatch();
		}

		/*
		Nothing claimed and nothing finished means the only thing left is a
			claim held by another process. Leave it to the cron health check
			rather than spinning up another chain immediately. */

		return $this->maybe_wp_die();
	}

	/**
	 * Optimize a single attachment.
	 *
	 * Always returns false: the queue lives in postmeta, so there is nothing to
	 * hand back to the library's batch.
	 *
	 * @param mixed $item Attachment ID.
	 * @return false
	 */
	protected function task( $item ) {
		$id       = intval( $item );
		$attempts = intval( get_post_meta( $id, self::META_ATTEMPTS, true ) ) + 1;

		update_post_meta( $id, self::META_ATTEMPTS, $attempts );

		if ( ! $this->is_supported_attachment( $id ) ) {
			$this->finish( $id, self::STATUS_SKIPPED );
			return false;
		}

		$this->processing_id = $id;
		update_post_meta( $id, self::META_PROGRESS, 0 );

		$tiny_image = new Tiny_Image( $this->settings, $id );

		Tiny_Logger::debug(
			'compress from bulk queue',
			array(
				'image_id' => $id,
			)
		);

		try {
			$result = $tiny_image->compress();
		} catch ( Exception $e ) {
			$this->fail( $id, $attempts, $e->getMessage() );
			return false;
		}

		wp_update_attachment_metadata( $id, $tiny_image->get_wp_metadata() );

		$optimized = isset( $result['success'] ) ? intval( $result['success'] ) : 0;

		if ( ! empty( $result['failed'] ) ) {
			$this->fail( $id, $attempts, $tiny_image->get_latest_error() );
			return false;
		}

		$this->finish( $id, $optimized > 0 ? self::STATUS_DONE : self::STATUS_SKIPPED );

		return false;
	}

	/**
	 * Record the outcome of an attachment that was processed.
	 *
	 * @param int    $id     Attachment ID.
	 * @param string $status One of the STATUS_ constants.
	 */
	protected function finish( $id, $status ) {
		update_post_meta( $id, self::META_STATUS, $status );
		delete_post_meta( $id, self::META_ERROR );
		delete_post_meta( $id, self::META_PROGRESS );
		$this->processing_id = null;

		$this->touch();
	}

	/**
	 * Record a failure, and queue the attachment again if it has attempts left.
	 *
	 * One bad image cannot poison the run: it is retried a few times and then
	 * left alone while the rest of the library carries on.
	 *
	 * @param int    $id       Attachment ID.
	 * @param int    $attempts Attempts made so far, including this one.
	 * @param string $message  Why it failed.
	 */
	protected function fail( $id, $attempts, $message ) {
		update_post_meta( $id, self::META_ERROR, (string) $message );
		delete_post_meta( $id, self::META_PROGRESS );
		$this->processing_id = null;

		if ( $attempts < self::MAX_ATTEMPTS ) {
			$this->release( $id );
			return;
		}

		update_post_meta( $id, self::META_STATUS, self::STATUS_FAILED );
		$this->touch();
	}

	/* ---------------------------------------------------------------------
	 * Run boundaries
	 * ------------------------------------------------------------------ */

	/**
	 * Mark the run as finished once the queue has drained.
	 */
	protected function complete() {
		parent::complete();

		$run = self::get_run();
		if ( 'running' === $run['status'] ) {
			$run['status']      = 'done';
			$run['finished_at'] = time();
			self::save_run( $run );
		}
	}

	/**
	 * Drop what is still queued, keeping the results of what already ran.
	 *
	 * Replaces the library's batch cleanup. Anything still waiting or claimed
	 * becomes 'cancelled' so the counts on the page stay meaningful.
	 */
	public function delete_all() {
		global $wpdb;

		do {
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM $wpdb->postmeta
					WHERE meta_key = %s AND meta_value IN ( %s, %s )
					LIMIT 500",
					self::META_STATUS,
					self::STATUS_PENDING,
					self::STATUS_PROCESSING
				)
			);

			if ( empty( $ids ) ) {
				break;
			}

			$ids = array_map( 'intval', $ids );
			$in  = implode( ', ', $ids );

			$keys         = self::meta_keys();
			$placeholders = implode( ', ', array_fill( 0, count( $keys ), '%s' ) );

			/*
			The rows go rather than change to some cancelled state: an image
				taken out of the queue is one the queue has not looked at, which
				is what having no status row means. */
			$delete = "DELETE FROM $wpdb->postmeta
				WHERE meta_key IN ($placeholders) AND post_id IN ($in)";

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- Only generated %s placeholders and a list of integers are interpolated.
			$wpdb->query( $wpdb->prepare( $delete, $keys ) );

			foreach ( $ids as $id ) {
				wp_cache_delete( $id, 'post_meta' );
			}
		} while ( true );

		delete_site_option( $this->get_status_key() );

		$this->cancelled();
	}

	/**
	 * Mark the run as cancelled.
	 */
	protected function cancelled() {
		parent::cancelled();

		$run                = self::get_run();
		$run['status']      = 'cancelled';
		$run['finished_at'] = time();
		self::save_run( $run );
	}

	/* ---------------------------------------------------------------------
	 * Library plumbing
	 * ------------------------------------------------------------------ */

	/**
	 * Where the loopback request that keeps the queue moving is sent.
	 *
	 * Mirrors the WORDPRESS_HOST escape hatch used for compression on upload:
	 * in the development containers the site URL carries the port published on
	 * the host, which the container itself cannot reach.
	 *
	 * @return string
	 */
	protected function get_query_url() {
		$host = getenv( 'WORDPRESS_HOST' );

		if ( false !== $host ) {
			return $host . '/wp-admin/admin-ajax.php';
		}

		return parent::get_query_url();
	}

	/**
	 * ID tying together the loopback requests of a single run.
	 * @return string
	 */
	public function get_chain_id() {
		if ( wp_doing_ajax() && ! check_ajax_referer( $this->identifier, 'nonce', false ) ) {
			if ( empty( $this->started_chain_id ) ) {
				$this->started_chain_id = wp_generate_uuid4();
			}

			return $this->started_chain_id;
		}

		return parent::get_chain_id();
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function is_supported_attachment( $id ) {
		if ( 'attachment' !== get_post_type( $id ) ) {
			return false;
		}

		return in_array( get_post_mime_type( $id ), self::mime_types(), true );
	}

	private static function mime_types() {
		return array( 'image/jpeg', 'image/png', 'image/webp' );
	}

	private static function meta_keys() {
		return array(
			self::META_STATUS,
			self::META_ATTEMPTS,
			self::META_CLAIMED,
			self::META_ERROR,
			self::META_PROGRESS,
		);
	}

	private static function statuses() {
		return array(
			self::STATUS_PENDING,
			self::STATUS_PROCESSING,
			self::STATUS_DONE,
			self::STATUS_FAILED,
			self::STATUS_SKIPPED,
		);
	}

	/**
	 * Keep the last handful of results for the table on the page.
	 *
	 * Display only: the numbers the page shows come from postmeta, this is just
	 * the detail that would be expensive to recompute for every poll.
	 *
	 * @param array $entry Result of processing one attachment.
	 */
	/**
	 * Name, file and thumbnail of an attachment.
	 *
	 * @param int $id Attachment ID.
	 * @return array
	 */
	private function describe( $id ) {
		$file = get_post_meta( $id, '_wp_attached_file', true );

		return array(
			'id'        => $id,
			'title'     => get_the_title( $id ),
			'filename'  => $file ? wp_basename( $file ) : '',
			'thumbnail' => wp_get_attachment_image(
				$id,
				array( 38, 36 ),
				true,
				array(
					'class' => 'pinkynail',
					'alt'   => '',
				)
			),
		);
	}

	/**
	 * Everything a row says about an image: what it is, and what it is now.
	 *
	 * The savings are read from the compression metadata rather than from a
	 * record of the run, so an image shows what it saved whether that happened
	 * a moment ago or in a run last month.
	 *
	 * The status is left to the caller: the same description serves an image
	 * being optimized, one still waiting, and one already dealt with.
	 *
	 * @param int $id Attachment ID.
	 * @return array
	 */
	private function describe_in_full( $id ) {
		$image = new Tiny_Image( $this->settings, $id );
		$stats = $image->get_statistics(
			$this->settings->get_sizes(),
			$this->settings->get_active_tinify_sizes()
		);

		return array_merge(
			self::entry_defaults(),
			$this->describe( $id ),
			array(
				'sizes'            => self::size_count( $stats ),
				'sizes_compressed' => $stats['image_sizes_compressed'],
				'sizes_converted'  => $stats['image_sizes_converted'],
				'initial_size'     => size_format( $stats['initial_total_size'], 1 ),
				'optimized_size'   => size_format( $stats['compressed_total_size'], 1 ),
				'savings'          => $image->get_savings( $stats ),
			)
		);
	}


	/**
	 * Image sizes the plugin looks after for an image.
	 *
	 * @param array $stats Statistics of a single image.
	 * @return int
	 */
	private static function size_count( array $stats ) {
		return $stats['image_sizes_optimized'] + $stats['available_unoptimized_sizes'];
	}

	/**
	 * Everything a row on the page can show, empty.
	 *
	 * @return array
	 */
	private static function entry_defaults() {
		return array(
			'id'               => 0,
			'title'            => '',
			'filename'         => '',
			'status'           => self::STATUS_SKIPPED,
			'message'          => null,
			'sizes'            => 0,
			'sizes_compressed' => 0,
			'sizes_converted'  => 0,
			'initial_size'     => null,
			'optimized_size'   => null,
			'savings'          => 0,
			'thumbnail'        => '',
			'progress'         => 0,
		);
	}

	/**
	 * Note that the run moved on, so a slow image is not read as a stall.
	 */
	private function touch() {
		$run = self::get_run();

		$run['updated_at'] = time();

		self::save_run( $run );
	}

	public static function get_run() {
		$run = get_site_option( self::RUN_OPTION );

		if ( ! is_array( $run ) ) {
			return self::empty_run();
		}

		return array_merge( self::empty_run(), $run );
	}

	private static function save_run( array $run ) {
		update_site_option( self::RUN_OPTION, $run );
	}

	private static function empty_run() {
		return array(
			'status'        => 'idle',
			'mode'          => 'all',
			'error_message' => null,
			'started_at'    => null,
			'updated_at'    => null,
			'finished_at'   => null,
		);
	}
}
