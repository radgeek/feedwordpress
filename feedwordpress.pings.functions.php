<?php
################################################################################
## fwp_hold_pings() and fwp_release_pings(): Outbound XML-RPC ping reform   ####
## ... 'coz it's rude to send 500 pings the first time your aggregator runs ####
################################################################################

global $fwp_held_ping;	// Why declare it as global if it's not used anywhere else? (gwyneth 20230920)

/** @var  int|null  Hold pings (or not yet, if NULL). */
$fwp_held_ping = NULL;

function fwp_hold_pings() {
	global $fwp_held_ping;
	if ( is_null ( $fwp_held_ping ) ) :
		$fwp_held_ping = 0;	// 0: ready to hold pings; none yet received
		FeedWordPress::diagnostic(
			'syndicated_posts:do_pings',
			'FeedWordPress is set up to hold pings, fwp_held_ping=' . json_encode( $fwp_held_ping )
		);
	endif;
} /* function fwp_hold_pings() */

/**
 * Attempts to schedule an immediate ping via the event scheduler,
 * falling back to direct ping if scheduler isn't available.
 *
 * @uses wp_schedule_single_event()
 * @uses generic_ping()
 * @uses FeedWordPress::diagnostic()
 *
 * @global $fwp_held_ping
 */
function fwp_release_pings() {
	global $fwp_held_ping;

	$diag_message = null;
	if ( $fwp_held_ping ) :
		if  ( function_exists( 'wp_schedule_single_event') ) :
			if ( wp_schedule_single_event( time(), 'do_pings' ) ) :
				$diag_message = 'scheduled release of pings';
			else :
				$diag_message = 'scheduling release of pings failed';
			endif;
		else :
			generic_ping( $fwp_held_ping );
			$diag_message = 'released pings';
		endif;
	endif;

	$fwp_held_ping = NULL;	// NULL: not holding pings anymore

	if ( ! is_null( $diag_message ) ) :
		FeedWordPress::diagnostic(
			'syndicated_posts:do_pings',
			"FeedWordPress {$diag_message}, fwp_held_ping=" . json_encode( $fwp_held_ping )	// isn't this _always_ null? (gwyneth 20230919)
		);
	endif;
} /* function fwp_release_pings() */

/**
 * Pings, unless held.
 */
function fwp_do_pings() {
	global $fwp_held_ping, $post_id;

	if ( ! is_null( $fwp_held_ping ) and $post_id ) : // Defer until we're done updating
		$fwp_held_ping = $post_id;

		FeedWordPress::diagnostic(
			'syndicated_posts:do_pings',
			"FeedWordPress intercepted a ping event, fwp_held_ping=".json_encode($fwp_held_ping)
		);

	elseif ( function_exists( 'do_all_pings' ) ) :
		do_all_pings();
	else :
		generic_ping( $fwp_held_ping );
	endif;
} /* function fwp_do_pings() */

/**
 * Pings post, using one or several of the possible methods,
 * e.g. XMLRPC_REQUEST, APP_REQUEST.
 *
 * @param  int  $post_id  Post being considered for pinging.
 *
 */
function fwp_publish_post_hook( $post_id ) {
	global $fwp_held_ping;

	if ( ! is_null( $fwp_held_ping ) ) : // Syndicated post. Don't mark with _pingme
		if ( defined( 'XMLRPC_REQUEST' ) ) :
			do_action( 'xmlrpc_publish_post', $post_id );
		endif;
		if ( defined( 'APP_REQUEST' ) ) :
			do_action( 'app_publish_post', $post_id );
		endif;
		if ( defined( 'WP_IMPORTING' ) ) :
			return;
		endif;

		// Defer sending out pings until we finish updating
		$fwp_held_ping = $post_id;

		FeedWordPress::diagnostic(
			'syndicated_posts:do_pings',
			"FeedWordPress intercepted a post event, fwp_held_ping=" . json_encode( $fwp_held_ping )
		);
	else :
		if ( function_exists( '_publish_post_hook' ) ) : // WordPress 2.3
			_publish_post_hook( $post_id );
		endif;
	endif;
} /* function fwp_publish_post_hook() */
