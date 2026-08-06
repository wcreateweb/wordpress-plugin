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
 * Turns the image references collected from a rendered page into a report.
 *
 * A reference is one <img> or <picture> element with its src, srcset candidates and
 * <source> URLs. A row is one attachment, gathering every reference that resolved to it.
 * The report is the ordered list of rows.
 *
 * Deliberately free of WordPress request state: everything it needs arrives through the
 * constructor or the method arguments, so the resolver and the classifier are testable
 * without an ajax request behind them.
 *
 * @since 3.8.0
 */
class Tiny_Page_Scan {

	/**
	 * Row states, in the order they are presented.
	 *
	 * Failure is not among them: a failed size has no output, so it stays uncompressed and
	 * the row stays optimizable. Failure is a modifier, carried by the row's reason.
	 */
	private static $state_order = array(
		'optimizable' => 0,
		'optimized'   => 1,
		'unsupported' => 2,
		'unresolved'  => 3,
	);

	/**
	 * Stored error class names that mean no image can succeed until the account is fixed.
	 * These surface once, above the panel, instead of on every row.
	 */
	private static $account_errors = array(
		'Tinify\AccountException',
		'KeyError',
	);

	/** Stored error class names that will fail again if retried right away. */
	private static $rejected_errors = array(
		'Tinify\ClientException',
	);

	/** @var Tiny_Settings */
	private $settings;

	/** Per-request memo of uploads-relative path to attachment id, including misses. */
	private $id_memo = array();

	/** Per-request memo of attachment metadata, keyed on attachment id. */
	private $meta_memo = array();

	/**
	 * Every uploads-relative path accounted for by an attachment resolved during this scan,
	 * mapped to its attachment id and size name.
	 *
	 * attachment_url_to_postid() is an unindexed meta_value scan, so it costs a full table
	 * scan per call. Indexing an attachment's own metadata the first time it resolves
	 * answers the rest of its srcset for free, which is the difference between one lookup
	 * per attachment and one per referenced URL.
	 */
	private $path_index = array();

	public function __construct( $settings ) {
		$this->settings = $settings;
	}

	/**
	 * Resolves, classifies and orders the references collected from one page.
	 *
	 * @param array $references List of array( 'src' => string, 'urls' => string[] ).
	 * @return array The report: an ordered list of rows.
	 */
	public function scan( $references ) {
		$by_attachment = array();
		$unresolved    = array();
		$position      = 0;

		foreach ( $references as $reference ) {
			if ( ! is_array( $reference ) || empty( $reference['urls'] ) ) {
				continue;
			}

			$resolved_any = false;

			foreach ( (array) $reference['urls'] as $url ) {
				$match = $this->resolve( $url );
				if ( is_null( $match ) ) {
					continue;
				}

				$resolved_any = true;
				$id           = $match['attachment_id'];

				if ( ! isset( $by_attachment[ $id ] ) ) {
					$by_attachment[ $id ] = array(
						'position' => $position,
						'sizes'    => array(),
					);
				}

				/*
				Keyed by the size name cast to string purely to deduplicate. ORIGINAL is
					integer 0, which array_unique() and a mixed-key merge both mishandle,
					so the value keeps the original type and the key is thrown away. */
				$by_attachment[ $id ]['sizes'][ (string) $match['size'] ] = $match['size'];
			}

			if ( ! $resolved_any ) {
				/*
				One unresolved row per reference, not per URL. A reference whose four
					srcset candidates all fail is one thing the page shows once, and four
					near-identical rows would bury the rows that can be acted on. */
				$unresolved[] = array(
					'position' => $position,
					'url'      => isset( $reference['src'] ) ? $reference['src'] : '',
				);
			}

			++$position;
		}

		$rows = array();

		foreach ( $by_attachment as $id => $group ) {
			$row             = $this->build_row( $id, array_values( $group['sizes'] ) );
			$row['position'] = $group['position'];
			$rows[]          = $row;
		}

		foreach ( $unresolved as $entry ) {
			$row             = self::empty_row( 'unresolved' );
			$row['position'] = $entry['position'];
			$row['url']      = $entry['url'];
			$rows[]          = $row;
		}

		usort( $rows, array( __CLASS__, 'compare_rows' ) );

		return $rows;
	}

	/**
	 * Classifies one attachment against a set of size names.
	 *
	 * Takes its size set rather than deriving one, so the planned full-site scan can call
	 * it with get_active_tinify_sizes() without anything here changing.
	 *
	 * @param int   $attachment_id
	 * @param array $size_names Page-referenced size names.
	 * @return array One row.
	 */
	public function build_row( $attachment_id, $size_names ) {
		$tiny_image = new Tiny_Image( $this->settings, $attachment_id );

		$row                  = self::empty_row( 'optimizable' );
		$row['attachment_id'] = $attachment_id;
		$row['title']         = get_the_title( $attachment_id );
		$row['thumbnail']     = wp_get_attachment_image_url( $attachment_id, 'thumbnail' );
		$row['format']        = self::format_label( $tiny_image->get_mime_type() );

		if ( ! $tiny_image->file_type_allowed() ) {
			$row['state'] = 'unsupported';
			return $row;
		}

		$conversion_enabled  = $this->settings->get_conversion_enabled();
		$active_tinify_sizes = $this->settings->get_active_tinify_sizes();

		foreach ( $size_names as $size_name ) {
			$size = $tiny_image->get_image_size( $size_name );
			if ( is_null( $size ) ) {
				continue;
			}

			/*
			The set compress() would actually process, not merely "has an output". With
				conversion on it also picks up sizes that are compressed but not yet
				converted, and reporting those as done would promise less work than the
				button performs. */
			$pending = ! $size->is_duplicate()
				&& ( $size->uncompressed() || ( $conversion_enabled && $size->unconverted() ) );

			$bytes = $this->size_bytes( $attachment_id, $size_name, $size );

			$row['referenced_sizes'][] = array(
				'size'      => $size_name,
				'label'     => self::size_label( $size_name ),
				'bytes'     => $bytes,
				'pending'   => $pending,
				'duplicate' => $size->is_duplicate(),
				/*
				A size outside the active set is still compressed by the panel: the
					seam unions the submitted sizes with the settings. Recorded so the
					panel can disclose that before the user clicks. */
				'active'    => in_array( $size_name, $active_tinify_sizes, true ),
			);

			$row['total_bytes'] += $bytes;
			$row['saved_bytes'] += self::size_saving( $size );

			if ( $pending ) {
				++$row['pending'];
			}

			if ( isset( $size->meta['error'] ) ) {
				++$row['failed'];
				$row['reason']        = self::worst_reason( $row['reason'], $size->meta['error'] );
				$row['error_message'] = isset( $size->meta['message'] )
					? $size->meta['message']
					: null;
			}
		}

		if ( empty( $row['referenced_sizes'] ) ) {
			$row['state'] = 'unsupported';
		} elseif ( 0 === $row['pending'] ) {
			$row['state'] = 'optimized';
		}

		return $row;
	}

	/**
	 * Reduces a report to the numbers the panel header shows.
	 *
	 * A pure function of the report, so the header can be re-rendered from a fresh scan or
	 * from a single re-classified row without the two ever disagreeing.
	 *
	 * @param array $rows
	 * @return array
	 */
	public function summarize( $rows ) {
		$summary           = array_fill_keys( self::summary_keys(), 0 );
		$summary['images'] = count( $rows );

		foreach ( $rows as $row ) {
			$summary['optimizable_sizes'] += $row['pending'];
			$summary['saved_bytes']       += $row['saved_bytes'];

			if ( ! is_null( $row['attachment_id'] ) ) {
				++$summary['resolved_rows'];
			}
			if ( 'optimizable' === $row['state'] ) {
				++$summary['optimizable_rows'];
			}
		}

		return $summary;
	}

	/**
	 * The keys a header summary carries.
	 *
	 * @return array
	 */
	public static function summary_keys() {
		return array(
			'images',
			'optimizable_sizes',
			'optimizable_rows',
			'resolved_rows',
			'saved_bytes',
		);
	}

	/**
	 * Adjusts a summary for one row that has changed, without recounting the page.
	 *
	 * Only the two rows are trusted to describe themselves; the summary is a baseline the
	 * panel carries between requests. A wrong baseline shows wrong totals to whoever sent
	 * it and is corrected by the next scan, which recounts everything.
	 *
	 * @param array $summary Baseline totals.
	 * @param array $before  The row as it was.
	 * @param array $after   The row as it is now.
	 * @return array
	 */
	public static function apply_row_delta( $summary, $before, $after ) {
		$summary['optimizable_sizes'] += $after['pending'] - $before['pending'];
		$summary['saved_bytes']       += $after['saved_bytes'] - $before['saved_bytes'];

		$was = ( 'optimizable' === $before['state'] ) ? 1 : 0;
		$is  = ( 'optimizable' === $after['state'] ) ? 1 : 0;

		$summary['optimizable_rows'] += $is - $was;

		foreach ( $summary as $key => $value ) {
			if ( $value < 0 ) {
				$summary[ $key ] = 0;
			}
		}

		return $summary;
	}

	/**
	 * The account-wide condition, if any, that no row can work around.
	 *
	 * Read before the user clicks, from the credits stored at the last compression, so an
	 * exhausted account is disclosed rather than discovered. Nothing here calls the API.
	 *
	 * @return string|null A message, or null when the account looks usable.
	 */
	public function account_notice() {
		$remaining = $this->settings->get_remaining_credits();

		if ( '' !== $remaining && ! is_null( $remaining ) && false !== $remaining
			&& intval( $remaining ) <= 0 ) {
			return __(
				'You have reached your compression limit this month.',
				'tiny-compress-images'
			);
		}

		return null;
	}

	/**
	 * Whether a stored error class name means the whole account is blocked.
	 *
	 * @param string $error
	 * @return bool
	 */
	public static function is_account_error( $error ) {
		return in_array( $error, self::$account_errors, true );
	}

	/* ------------------------------------------------------------------- resolution */

	/**
	 * @param string $url One referenced URL.
	 * @return array|null array( 'attachment_id' => int, 'size' => string|int )
	 */
	private function resolve( $url ) {
		$path = $this->uploads_relative_path( $url );
		if ( is_null( $path ) ) {
			return null;
		}

		if ( isset( $this->path_index[ $path ] ) ) {
			return $this->path_index[ $path ];
		}

		$id = $this->lookup_attachment_id( $path );
		if ( ! $id ) {
			return null;
		}

		$meta = $this->attachment_metadata( $id );
		if ( ! is_array( $meta ) || ! isset( $meta['file'] ) ) {
			return null;
		}

		$this->index_attachment( $id, $meta );

		/*
		The lookup is a search heuristic; the metadata comparison in index_attachment() is
			the proof. An id that cannot account for this exact path fails closed rather
			than being reported against the wrong attachment. */
		return isset( $this->path_index[ $path ] ) ? $this->path_index[ $path ] : null;
	}

	/**
	 * Normalises a referenced URL to a path relative to the uploads base URL.
	 *
	 * Returns a path rather than a URL on purpose: attachment_url_to_postid() compares
	 * against _wp_attached_file, which stores exactly this, and passing a path avoids the
	 * scheme rewriting core performs on anything that looks like a URL.
	 *
	 * @param string $url
	 * @return string|null Null for anything not under this site's uploads directory.
	 */
	private function uploads_relative_path( $url ) {
		if ( ! is_string( $url ) ) {
			return null;
		}

		$url = trim( html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $url || 0 === strpos( $url, 'data:' ) ) {
			return null;
		}

		$url = strtok( $url, '#' );
		$url = strtok( $url, '?' );

		if ( 0 === strpos( $url, '//' ) ) {
			$url = 'https:' . $url;
		} elseif ( 0 === strpos( $url, '/' ) ) {
			$url = site_url( $url );
		}

		$uploads = wp_get_upload_dir();

		// Compared without the scheme, so an https page referencing http uploads still matches.
		$bare_url  = preg_replace( '~^https?://~', '', $url );
		$bare_base = preg_replace( '~^https?://~', '', $uploads['baseurl'] ) . '/';

		if ( 0 !== strpos( $bare_url, $bare_base ) ) {
			return null;
		}

		// Safe to decode: sanitize_file_name() strips the percent sign from stored names.
		return rawurldecode( substr( $bare_url, strlen( $bare_base ) ) );
	}

	/**
	 * @param string $path Uploads-relative path.
	 * @return int Attachment id, or 0.
	 */
	private function lookup_attachment_id( $path ) {
		if ( array_key_exists( $path, $this->id_memo ) ) {
			return $this->id_memo[ $path ];
		}

		$id = 0;

		foreach ( self::lookup_candidates( $path ) as $candidate ) {
			$found = attachment_url_to_postid( $candidate );
			if ( ! empty( $found ) ) {
				$id = intval( $found );
				break;
			}
		}

		$this->id_memo[ $path ] = $id;

		return $id;
	}

	/**
	 * The paths worth asking the database about, cheapest-to-succeed first.
	 *
	 * The -WxH stripped form goes first because a page's URLs are overwhelmingly sub-size
	 * URLs, and each candidate costs a full table scan. Ordering is safe to choose on cost
	 * alone because the proof is the metadata comparison, not the order: a file genuinely
	 * named photo-1024x768.jpg fails that comparison and the verbatim candidate is tried
	 * next.
	 *
	 * -scaled and -rotated are appended rather than stripped. Both appear in
	 * _wp_attached_file, so stripping them would search for a file that was never recorded.
	 *
	 * @param string $path
	 * @return array
	 */
	private static function lookup_candidates( $path ) {
		$bases = array();

		$unsized = preg_replace( '~-\d+x\d+(\.[a-z0-9]+)$~i', '$1', $path );
		if ( $unsized !== $path ) {
			$bases[] = $unsized;
		}
		$bases[] = $path;

		/*
		A converted file is not recorded in _wp_attached_file under its own extension, so
			an AVIF or WebP <source> URL can never be found without swapping the extension
			back. This has to happen during the lookup: the size mapping that also knows
			about conversion runs only after an id has been found, and for these URLs none
			ever would be. Last, so an actually-uploaded .webp still matches verbatim. */
		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
		if ( in_array( $extension, array( 'avif', 'webp' ), true ) ) {
			$originals = $bases;
			foreach ( array( 'jpg', 'jpeg', 'png' ) as $swap ) {
				foreach ( $originals as $base ) {
					$bases[] = preg_replace( '~\.[a-z0-9]+$~i', '.' . $swap, $base );
				}
			}
		}

		$candidates = array();
		foreach ( $bases as $base ) {
			$candidates[] = $base;
			foreach ( array( '-scaled', '-rotated' ) as $suffix ) {
				$candidates[] = preg_replace( '~(\.[a-z0-9]+)$~i', $suffix . '$1', $base );
			}
		}

		return array_values( array_unique( $candidates ) );
	}

	/**
	 * Records every path this attachment's metadata accounts for.
	 *
	 * This is both the cache and the proof. Core's wp_image_file_matches_image_meta() asks
	 * whether one path belongs to one attachment; building the whole map instead answers
	 * that question for every later URL without another query, and the converted paths
	 * recorded by compression fold an AVIF or WebP URL back onto the size it came from.
	 *
	 * @param int   $id
	 * @param array $meta
	 */
	private function index_attachment( $id, $meta ) {
		$marker = '#' . $id;
		if ( isset( $this->path_index[ $marker ] ) ) {
			return;
		}
		$this->path_index[ $marker ] = true;

		$dir = dirname( $meta['file'] );
		$dir = ( '.' === $dir ) ? '' : $dir . '/';

		$sizes                  = array();
		$sizes[ $meta['file'] ] = Tiny_Image::ORIGINAL;

		if ( isset( $meta['original_image'] ) ) {
			$original           = $dir . wp_basename( $meta['original_image'] );
			$sizes[ $original ] = Tiny_Image::ORIGINAL_UNSCALED;
		}

		if ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size_name => $size_info ) {
				if ( isset( $size_info['file'] ) ) {
					$sizes[ $dir . wp_basename( $size_info['file'] ) ] = $size_name;
				}
			}
		}

		$tiny_image = new Tiny_Image( $this->settings, $id );
		$uploads    = wp_get_upload_dir();
		$basedir    = trailingslashit( $uploads['basedir'] );

		foreach ( $sizes as $path => $size_name ) {
			$entry = array(
				'attachment_id' => $id,
				'size'          => $size_name,
			);

			$this->path_index[ $path ] = $entry;

			$size = $tiny_image->get_image_size( $size_name );
			if ( is_null( $size ) || ! isset( $size->meta['convert']['path'] ) ) {
				continue;
			}

			$converted = $size->meta['convert']['path'];
			if ( 0 === strpos( $converted, $basedir ) ) {
				$this->path_index[ substr( $converted, strlen( $basedir ) ) ] = $entry;
			}
		}
	}

	/**
	 * @param int $id
	 * @return array|false
	 */
	private function attachment_metadata( $id ) {
		if ( ! array_key_exists( $id, $this->meta_memo ) ) {
			$this->meta_memo[ $id ] = wp_get_attachment_metadata( $id );
		}
		return $this->meta_memo[ $id ];
	}

	/* ----------------------------------------------------------------- presentation */

	/**
	 * The size on disk now, preferring figures already recorded over stat calls.
	 *
	 * Tiny_Image_Size::filesize() is file_exists() plus filesize(), two stats per size, so
	 * a long page on a library predating WP 6.0 can cost hundreds of them.
	 *
	 * @param int          $attachment_id
	 * @param string|int   $size_name
	 * @param Tiny_Image_Size $size
	 * @return int
	 */
	private function size_bytes( $attachment_id, $size_name, $size ) {
		if ( isset( $size->meta['output']['size'] ) ) {
			return intval( $size->meta['output']['size'] );
		}
		if ( isset( $size->meta['input']['size'] ) ) {
			return intval( $size->meta['input']['size'] );
		}

		$meta = $this->attachment_metadata( $attachment_id );

		if ( Tiny_Image::ORIGINAL === $size_name && isset( $meta['filesize'] ) ) {
			return intval( $meta['filesize'] );
		}
		if ( isset( $meta['sizes'][ $size_name ]['filesize'] ) ) {
			return intval( $meta['sizes'][ $size_name ]['filesize'] );
		}

		return $size->filesize();
	}

	/**
	 * Bytes this size has already saved, from what compression recorded.
	 *
	 * @param Tiny_Image_Size $size
	 * @return int
	 */
	private static function size_saving( $size ) {
		if ( ! isset( $size->meta['input']['size'], $size->meta['output']['size'] ) ) {
			return 0;
		}

		$saving = intval( $size->meta['input']['size'] ) - intval( $size->meta['output']['size'] );

		return $saving > 0 ? $saving : 0;
	}

	/**
	 * Collapses a stored error class name to what the user can act on.
	 *
	 * Account errors are handled above the panel, so they never become a row reason.
	 *
	 * @param string|null $current
	 * @param string      $error Stored error class name.
	 * @return string|null
	 */
	private static function worst_reason( $current, $error ) {
		if ( self::is_account_error( $error ) ) {
			return $current;
		}

		if ( in_array( $error, self::$rejected_errors, true ) ) {
			return 'rejected';
		}

		return is_null( $current ) ? 'temporary' : $current;
	}

	private static function empty_row( $state ) {
		return array(
			'state'            => $state,
			'position'         => 0,
			'attachment_id'    => null,
			'title'            => null,
			'thumbnail'        => null,
			'format'           => null,
			'referenced_sizes' => array(),
			'total_bytes'      => 0,
			'saved_bytes'      => 0,
			'pending'          => 0,
			'failed'           => 0,
			'reason'           => null,
			'error_message'    => null,
			'url'              => null,
		);
	}

	/**
	 * @param string|int $size_name
	 * @return string
	 */
	public static function size_label( $size_name ) {
		if ( Tiny_Image::is_original( $size_name ) ) {
			return __( 'original', 'tiny-compress-images' );
		}
		if ( Tiny_Image::is_original_unscaled( $size_name ) ) {
			return __( 'original (unscaled)', 'tiny-compress-images' );
		}
		return (string) $size_name;
	}

	/**
	 * @param string $mime_type
	 * @return string
	 */
	private static function format_label( $mime_type ) {
		$labels = array(
			'image/jpeg' => 'jpg',
			'image/png'  => 'png',
			'image/webp' => 'webp',
			'image/gif'  => 'gif',
			'image/avif' => 'avif',
		);

		return isset( $labels[ $mime_type ] ) ? $labels[ $mime_type ] : $mime_type;
	}

	/**
	 * Orders by state group, then by where the page first referenced the attachment.
	 *
	 * Reference positions are unique, so this is already a strict total order and needs no
	 * tie-break -- which matters because usort() is only stable from PHP 8.0.
	 *
	 * @param array $a
	 * @param array $b
	 * @return int
	 */
	public static function compare_rows( $a, $b ) {
		$group_a = self::$state_order[ $a['state'] ];
		$group_b = self::$state_order[ $b['state'] ];

		if ( $group_a !== $group_b ) {
			return $group_a < $group_b ? -1 : 1;
		}
		if ( $a['position'] === $b['position'] ) {
			return 0;
		}

		return $a['position'] < $b['position'] ? -1 : 1;
	}
}
