<?php
################################################################################
## fwp_hold_pings() and fwp_release_pings(): Outbound XML-RPC ping reform   ####
## ... 'coz it's rude to send 500 pings the first time your aggregator runs ####
################################################################################

global $fwp_held_ping;

$fwp_held_ping = NULL;		// NULL: not holding pings yet

function fwp_hold_pings () {
	global $fwp_held_ping;
	if (is_null($fwp_held_ping)):
		$fwp_held_ping = 0;	// 0: ready to hold pings; none yet received
		FeedWordPress::diagnostic(
			'syndicated_posts:do_pings',
			'FeedWordPress is set up to hold pings, fwp_held_ping='.json_encode($fwp_held_ping)
		);
	endif;
}

function fwp_release_pings () {
	global $fwp_held_ping;

	$diag_message = null;
	if ($fwp_held_ping):
		if (function_exists('wp_schedule_single_event')) :
			wp_schedule_single_event(time(), 'do_pings');
			$diag_message = 'scheduled release of pings';
		else :
			generic_ping($fwp_held_ping);
			$diag_message = 'released pings';
		endif;
	endif;
	
	$fwp_held_ping = NULL;	// NULL: not holding pings anymore
	
	if (!is_null($diag_message)) :
		FeedWordPress::diagnostic(
			'syndicated_posts:do_pings',
			"FeedWordPress ${diag_message}, fwp_held_ping=".json_encode($fwp_held_ping)
		);
	endif;
}

function fwp_do_pings () {
	if (!is_null($fwp_held_ping) and $post_id) : // Defer until we're done updating
		$fwp_held_ping = $post_id;

		FeedWordPress::diagnostic(
			'syndicated_posts:do_pings',
			"FeedWordPress intercepted a ping event, fwp_held_ping=".json_encode($fwp_held_ping)
		);

	elseif (function_exists('do_all_pings')) :
		do_all_pings();
	else :
		generic_ping($fwp_held_ping);
	endif;
}

function fwp_publish_post_hook ($post_id) {
	global $fwp_held_ping;

	if (!is_null($fwp_held_ping)) : // Syndicated post. Don't mark with _pingme
		if ( defined('XMLRPC_REQUEST') )
			do_action('xmlrpc_publish_post', $post_id);
		if ( defined('APP_REQUEST') )
			do_action('app_publish_post', $post_id);

		if ( defined('WP_IMPORTING') )
			return;

		// Defer sending out pings until we finish updating
		$fwp_held_ping = $post_id;
		
		FeedWordPress::diagnostic(
			'syndicated_posts:do_pings',
			"FeedWordPress intercepted a post event, fwp_held_ping=".json_encode($fwp_held_ping)
		);
	else :
		if (function_exists('_publish_post_hook')) : // WordPress 2.3
			_publish_post_hook($post_id);
		endif;
	endif;
}
