<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Persistent log of imports and importer activity.
 *
 * Records every entry that enters the calendar — automatically (scheduled IGDB
 * importer) or manually (quick-add modal, with or without an IGDB match) — plus
 * the auto-importer's run summaries, cancellations, and permanent deletions.
 *
 * Stored as a single non-autoloaded option: a newest-first array capped to
 * MAX_ENTRIES. A one-time backfill seeds it from existing posts so history that
 * predates this feature is not lost.
 */
class GC_Import_Log {

	const OPTION_NAME     = 'gc_import_log';
	const BACKFILL_OPTION = 'gc_import_log_backfilled';
	const MAX_ENTRIES     = 1000;

	// Event types.
	const EV_IMPORT = 'import'; // A new entry was added.
	const EV_RUN    = 'run';    // A scheduled/manual auto-import run finished.
	const EV_CANCEL = 'cancel'; // An entry was auto-moved to draft (IGDB cancelled it).
	const EV_DELETE = 'delete'; // An imported entry was permanently deleted.

	// Import sources.
	const SRC_AUTO   = 'auto';   // Scheduled importer / "Run Now".
	const SRC_IGDB   = 'igdb';   // Manual quick-add from an IGDB search result.
	const SRC_MANUAL = 'manual'; // Manual quick-add typed by hand (no IGDB match).

	/**
	 * Append a log record. Newest first, capped to MAX_ENTRIES.
	 *
	 * @param string $event One of the EV_* constants.
	 * @param array  $args  { t?, src?, pid?, igdb?, ttl?, msg? }
	 */
	public static function record( $event, array $args = array() ) {
		$entry = array(
			't'    => isset( $args['t'] ) ? (int) $args['t'] : time(),
			'ev'   => $event,
			'src'  => isset( $args['src'] ) ? (string) $args['src'] : '',
			'pid'  => isset( $args['pid'] ) ? (int) $args['pid'] : 0,
			'igdb' => isset( $args['igdb'] ) ? (int) $args['igdb'] : 0,
			'ttl'  => isset( $args['ttl'] ) ? (string) $args['ttl'] : '',
			'msg'  => isset( $args['msg'] ) ? (string) $args['msg'] : '',
		);

		$log = self::all();
		array_unshift( $log, $entry );
		if ( count( $log ) > self::MAX_ENTRIES ) {
			$log = array_slice( $log, 0, self::MAX_ENTRIES );
		}
		// autoload = false: this can grow large and is only read on the Logs page.
		update_option( self::OPTION_NAME, $log, false );
	}

	public static function record_import( $post_id, $source, $igdb_id = 0, $title = '' ) {
		$post_id = (int) $post_id;
		// Guard: never log the same entry's import twice.
		if ( $post_id && get_post_meta( $post_id, 'gc_import_logged', true ) ) {
			return;
		}
		self::record( self::EV_IMPORT, array(
			'src'  => $source,
			'pid'  => $post_id,
			'igdb' => (int) $igdb_id,
			'ttl'  => $title ?: get_the_title( $post_id ),
		) );
		if ( $post_id ) {
			update_post_meta( $post_id, 'gc_import_logged', 1 );
		}
	}

	public static function record_run( array $stats ) {
		$imported  = (int) ( $stats['imported'] ?? 0 );
		$synced    = (int) ( $stats['synced'] ?? 0 );
		$cancelled = (int) ( $stats['cancelled'] ?? 0 );
		$msg       = sprintf(
			/* translators: 1: new count, 2: updated count, 3: cancelled count */
			__( '%1$d new, %2$d updated, %3$d cancelled', 'game-calendar' ),
			$imported,
			$synced,
			$cancelled
		);
		self::record( self::EV_RUN, array( 'msg' => $msg ) );
	}

	public static function record_cancel( $post_id ) {
		self::record( self::EV_CANCEL, array(
			'src'  => self::SRC_AUTO,
			'pid'  => (int) $post_id,
			'igdb' => (int) get_post_meta( $post_id, 'gc_igdb_id', true ),
			'ttl'  => get_the_title( $post_id ),
			'msg'  => __( 'Cancelled on IGDB — moved to draft', 'game-calendar' ),
		) );
	}

	public static function record_delete( $post_id, $igdb_id, $title ) {
		self::record( self::EV_DELETE, array(
			'pid'  => (int) $post_id,
			'igdb' => (int) $igdb_id,
			'ttl'  => (string) $title,
			'msg'  => __( 'Permanently deleted — blocked from re-import', 'game-calendar' ),
		) );
	}

	/**
	 * @return array Newest-first list of log entries.
	 */
	public static function all() {
		$log = get_option( self::OPTION_NAME, array() );
		return is_array( $log ) ? $log : array();
	}

	public static function clear() {
		update_option( self::OPTION_NAME, array(), false );
	}

	/**
	 * Summary counts for the page header.
	 *
	 * @return array { total, auto, manual, last_run_time, last_run_msg }
	 */
	public static function stats() {
		$auto = 0;
		$manual = 0;
		$last_run_time = 0;
		$last_run_msg = '';

		foreach ( self::all() as $e ) {
			if ( self::EV_IMPORT === $e['ev'] ) {
				if ( self::SRC_AUTO === $e['src'] ) {
					$auto++;
				} else {
					$manual++;
				}
			} elseif ( self::EV_RUN === $e['ev'] && ! $last_run_time ) {
				$last_run_time = (int) $e['t'];
				$last_run_msg  = (string) $e['msg'];
			}
		}

		return array(
			'total'         => $auto + $manual,
			'auto'          => $auto,
			'manual'        => $manual,
			'last_run_time' => $last_run_time,
			'last_run_msg'  => $last_run_msg,
		);
	}

	/**
	 * Seed the log from existing entries the first time it is viewed, so the
	 * log reflects everything imported before logging existed. Runs once.
	 */
	public static function maybe_backfill() {
		if ( get_option( self::BACKFILL_OPTION ) ) {
			return;
		}
		// Mark done up front so a slow/large backfill can't run twice concurrently.
		update_option( self::BACKFILL_OPTION, 1, false );

		// Any entries already logged live (e.g. an auto-import that ran before the
		// Logs page was first opened) must not be backfilled a second time.
		$existing    = self::all();
		$logged_pids = array();
		foreach ( $existing as $e ) {
			if ( self::EV_IMPORT === $e['ev'] && $e['pid'] ) {
				$logged_pids[ (int) $e['pid'] ] = true;
			}
		}

		$posts = get_posts( array(
			'post_type'      => array( 'gc_release', 'gc_event', 'gc_dlc' ),
			'post_status'    => array( 'publish', 'draft' ),
			'posts_per_page' => self::MAX_ENTRIES,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'no_found_rows'  => true,
		) );

		$backfill = array();
		foreach ( $posts as $post ) {
			if ( isset( $logged_pids[ (int) $post->ID ] ) ) {
				continue;
			}
			$igdb_id = (int) get_post_meta( $post->ID, 'gc_igdb_id', true );
			if ( get_post_meta( $post->ID, 'gc_auto_imported', true ) ) {
				$source = self::SRC_AUTO;
			} elseif ( $igdb_id ) {
				$source = self::SRC_IGDB;
			} else {
				$source = self::SRC_MANUAL;
			}

			$backfill[] = array(
				't'    => (int) get_post_time( 'U', true, $post ),
				'ev'   => self::EV_IMPORT,
				'src'  => $source,
				'pid'  => (int) $post->ID,
				'igdb' => $igdb_id,
				'ttl'  => $post->post_title,
				'msg'  => __( 'Backfilled from existing entry', 'game-calendar' ),
			);
		}

		if ( empty( $backfill ) ) {
			return;
		}

		// Merge live + backfilled records and keep the newest MAX_ENTRIES.
		$merged = array_merge( $existing, $backfill );
		usort( $merged, function ( $a, $b ) {
			return (int) $b['t'] <=> (int) $a['t'];
		} );
		if ( count( $merged ) > self::MAX_ENTRIES ) {
			$merged = array_slice( $merged, 0, self::MAX_ENTRIES );
		}
		update_option( self::OPTION_NAME, $merged, false );
	}
}
