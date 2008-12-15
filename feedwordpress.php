<?php
/*
Plugin Name: FeedWordPress
Plugin URI: http://projects.radgeek.com/feedwordpress
Description: simple and flexible Atom/RSS syndication for WordPress
Version: 2008.1214
Author: Charles Johnson
Author URI: http://radgeek.com/
License: GPL
Last modified: 2008-12-14 4:29pm PST
*/

# This uses code derived from:
# -	wp-rss-aggregate.php by Kellan Elliot-McCrea <kellan@protest.net>
# -	HTTP Navigator 2 by Keyvan Minoukadeh <keyvan@k1m.com>
# -	Ultra-Liberal Feed Finder by Mark Pilgrim <mark@diveintomark.org>
# according to the terms of the GNU General Public License.
#
# INSTALLATION: see readme.txt or <http://projects.radgeek.com/install>
#
# USAGE: once FeedWordPress is installed, you manage just about everything from
# the WordPress Dashboard, under the Syndication menu. To ensure that fresh
# content is added as it becomes available, you can convince your contributors
# to put your XML-RPC URI (if WordPress is installed at
# <http://www.zyx.com/blog>, XML-RPC requests should be sent to
# <http://www.zyx.com/blog/xmlrpc.php>), or update manually under the
# Syndication menu, or set up automatic updates under Syndication --> Settings,
# or use a cron job.

# -- Don't change these unless you know what you're doing...

define ('FEEDWORDPRESS_VERSION', '2008.1214');
define ('FEEDWORDPRESS_AUTHOR_CONTACT', 'http://radgeek.com/contact');
define ('DEFAULT_SYNDICATION_CATEGORY', 'Contributors');

define ('FEEDWORDPRESS_DEBUG', false);

define ('FEEDWORDPRESS_CAT_SEPARATOR_PATTERN', '/[:\n]/');
define ('FEEDWORDPRESS_CAT_SEPARATOR', "\n");

define ('FEEDVALIDATOR_URI', 'http://feedvalidator.org/check.cgi');

define ('FEEDWORDPRESS_FRESHNESS_INTERVAL', 10*60); // Every ten minutes

define ('FWP_SCHEMA_HAS_USERMETA', 2966);
define ('FWP_SCHEMA_20', 3308); // Database schema # for WP 2.0
define ('FWP_SCHEMA_21', 4772); // Database schema # for WP 2.1
define ('FWP_SCHEMA_23', 5495); // Database schema # for WP 2.3
define ('FWP_SCHEMA_25', 7558); // Database schema # for WP 2.5
define ('FWP_SCHEMA_26', 8201); // Database schema # for WP 2.6
define ('FWP_SCHEMA_27', 9872); // Database schema # for WP 2.7

if (FEEDWORDPRESS_DEBUG) :
	// Help us to pick out errors, if any.
	ini_set('error_reporting', E_ALL & ~E_NOTICE);
	ini_set('display_errors', true);
	define('MAGPIE_DEBUG', true);
	
	 // When testing we don't want cache issues to interfere. But this is
	 // a VERY BAD SETTING for a production server. Webmasters will eat your 
	 // face for breakfast if you use it, and the baby Jesus will cry. So
	 // make sure FEEDWORDPRESS_DEBUG is FALSE for any site that will be
	 // used for more than testing purposes!
	define('MAGPIE_CACHE_AGE', 1);
else :
	define('MAGPIE_DEBUG', false);
endif;

// Note that the rss-functions.php that comes prepackaged with WordPress is
// old & busted. For the new hotness, drop a copy of rss.php from
// this archive into wp-includes/rss.php

if (is_readable(ABSPATH . WPINC . '/rss.php')) :
	require_once (ABSPATH . WPINC . '/rss.php');
else :
	require_once (ABSPATH . WPINC . '/rss-functions.php');
endif;

if (isset($wp_db_version)) :
	if ($wp_db_version >= FWP_SCHEMA_23) :
		require_once (ABSPATH . WPINC . '/registration.php'); 		// for wp_insert_user
	elseif ($wp_db_version >= FWP_SCHEMA_21) : // WordPress 2.1 and 2.2, but not 2.3
		require_once (ABSPATH . WPINC . '/registration.php'); 		// for wp_insert_user
		require_once (ABSPATH . 'wp-admin/admin-db.php'); 		// for wp_insert_category 
	elseif ($wp_db_version >= FWP_SCHEMA_20) : // WordPress 2.0
		require_once (ABSPATH . WPINC . '/registration-functions.php');	// for wp_insert_user
		require_once (ABSPATH . 'wp-admin/admin-db.php');		// for wp_insert_category
	endif;
endif;

require_once(dirname(__FILE__) . '/compatability.php'); // LEGACY API: Replicate or mock up functions for legacy support purposes

// Magic quotes are just about the stupidest thing ever.
if (is_array($_POST)) :
	$fwp_post = stripslashes_deep($_POST);
endif;

// Get the path relative to the plugins directory in which FWP is stored
preg_match (
	'|/wp-content/plugins/(.+)$|',
	dirname(__FILE__),
	$ref
);

if (isset($ref[1])) :
	$fwp_path = $ref[1];
else : // Something went wrong. Let's just guess.
	$fwp_path = 'feedwordpress';
endif;

// If this is a FeedWordPress admin page, queue up scripts for AJAX functions that FWP uses
// If it is a display page or a non-FeedWordPress admin page, don't.
if (is_admin() and isset($_REQUEST['page']) and preg_match("|^{$fwp_path}/|", $_REQUEST['page'])) :
	if (function_exists('wp_enqueue_script')) :
		if (isset($wp_db_version) and $wp_db_version >= FWP_SCHEMA_25) :
			wp_enqueue_script('post'); // for magic tag and category boxes
			wp_enqueue_script('thickbox'); // for fold-up boxes
			wp_enqueue_script('admin-forms'); // for checkbox selection
		else :
			wp_enqueue_script( 'ajaxcat' ); // Provides the handy-dandy new category text box
		endif;
	endif;
	if (function_exists('wp_enqueue_style')) :
		if (fwp_test_wp_version(FWP_SCHEMA_25)) :
			wp_enqueue_style('dashboard');
		endif;
	endif;
	if (function_exists('wp_admin_css')) :
		if (fwp_test_wp_version(FWP_SCHEMA_25)) :
			wp_admin_css('css/dashboard');
		endif;
	endif;
endif;

if (!FeedWordPress::needs_upgrade()) : // only work if the conditions are safe!

	# Syndicated items are generally received in output-ready (X)HTML and
	# should not be folded, crumpled, mutilated, or spindled by WordPress
	# formatting filters. But we don't want to interfere with filters for
	# any locally-authored posts, either.
	#
	# What WordPress should really have is a way for upstream filters to
	# stop downstream filters from running at all. Since it doesn't, and
	# since a downstream filter can't access the original copy of the text
	# that is being filtered, what we will do here is (1) save a copy of the
	# original text upstream, before any other filters run, and then (2)
	# retrieve that copy downstream, after all the other filters run, *if*
	# this is a syndicated post

	add_filter('the_content', 'feedwordpress_preserve_syndicated_content', -10000);
	add_filter('the_content', 'feedwordpress_restore_syndicated_content', 10000);
	
	add_action('atom_entry', 'feedwordpress_item_feed_data');
	
	# Filter in original permalinks if the user wants that
	add_filter('post_link', 'syndication_permalink', 1);

	# WTF? By default, wp_insert_link runs incoming link_url and link_rss
	# URIs through default filters that include `wp_kses()`. But `wp_kses()`
	# just happens to escape any occurrence of & to &amp; -- which just
	# happens to fuck up any URI with a & to separate GET parameters.
	remove_filter('pre_link_rss', 'wp_filter_kses');
	remove_filter('pre_link_url', 'wp_filter_kses');
	
	# Admin menu
	add_action('admin_menu', 'fwp_add_pages');
	
	# Inbound XML-RPC update methods
	add_filter('xmlrpc_methods', 'feedwordpress_xmlrpc_hook');
	
	# Outbound XML-RPC ping reform
	remove_action('publish_post', 'generic_ping'); // WP 1.5.x
	remove_action('do_pings', 'do_all_pings', 10, 1); // WP 2.1, 2.2
	remove_action('publish_post', '_publish_post_hook', 5, 1); // WP 2.3

	add_action('publish_post', 'fwp_publish_post_hook', 5, 1);
	add_action('do_pings', 'fwp_do_pings', 10, 1);
	add_action('feedwordpress_update', 'fwp_hold_pings');
	add_action('feedwordpress_update_complete', 'fwp_release_pings');

	# Hook in logging functions only if the logging option is ON
	$update_logging = get_option('feedwordpress_update_logging');
	if ($update_logging == 'yes') :
		add_action('post_syndicated_item', 'log_feedwordpress_post', 100);
		add_action('update_syndicated_item', 'log_feedwordpress_update_post', 100);
		add_action('feedwordpress_update', 'log_feedwordpress_update_feeds', 100);
		add_action('feedwordpress_check_feed', 'log_feedwordpress_check_feed', 100);
		add_action('feedwordpress_update_complete', 'log_feedwordpress_update_complete', 100);
	endif;
		
	# Cron-less auto-update. Hooray!
	add_action('init', 'feedwordpress_auto_update');
	
	# Default sanitizers
	add_filter('syndicated_item_content', array('SyndicatedPost', 'sanitize_content'), 0, 2);

else :
	# Hook in the menus, which will just point to the upgrade interface
	add_action('admin_menu', 'fwp_add_pages');
endif; // if (!FeedWordPress::needs_upgrade())

function feedwordpress_auto_update () {
	if (FeedWordPress::stale()) :
		$feedwordpress =& new FeedWordPress;
		$feedwordpress->update();
	endif;
	
	if (FeedWordPress::update_requested()) :
		exit;
	endif;
} /* feedwordpress_auto_update () */

################################################################################
## LOGGING FUNCTIONS: log status updates to error_log if you want it ###########
################################################################################

function log_feedwordpress_post ($id) {
	$post = wp_get_single_post($id);
	error_log("[".date('Y-m-d H:i:s')."][feedwordpress] posted "
		."'{$post->post_title}' ({$post->post_date})");
}

function log_feedwordpress_update_post ($id) {
	$post = wp_get_single_post($id);
	error_log("[".date('Y-m-d H:i:s')."][feedwordpress] updated "
		."'{$post->post_title}' ({$post->post_date})"
		." (as of {$post->post_modified})");
}

function log_feedwordpress_update_feeds ($uri) {
	error_log("[".date('Y-m-d H:i:s')."][feedwordpress] update('$uri')");
}

function log_feedwordpress_check_feed ($feed) {
	$uri = $feed['link/uri']; $name = $feed['link/name'];
	error_log("[".date('Y-m-d H:i:s')."][feedwordpress] Examining $name <$uri>");
}

function log_feedwordpress_update_complete ($delta) {
	$mesg = array();
	if (isset($delta['new'])) $mesg[] = 'added '.$delta['new'].' new posts';
	if (isset($delta['updated'])) $mesg[] = 'updated '.$delta['updated'].' existing posts';
	if (empty($mesg)) $mesg[] = 'nothing changed';

	error_log("[".date('Y-m-d H:i:s')."][feedwordpress] "
		.(is_null($delta) ? "Error: I don't syndicate that URI"
		: implode(' and ', $mesg)));
}

################################################################################
## TEMPLATE API: functions to make your templates syndication-aware ############
################################################################################

function is_syndicated () { return (strlen(get_syndication_feed_id()) > 0); }

function get_syndication_source_link ($original = NULL) {
	if (is_null($original)) : $original = FeedWordPress::use_aggregator_source_data();
	endif;

	if ($original) : $vals = get_post_custom_values('syndication_source_uri_original');
	else : $vals = array();
	endif;
	
	if (count($vals) == 0) : $vals = get_post_custom_values('syndication_source_uri');
	endif;
	
	if (count($vals) > 0) : $ret = $vals[0]; else : $ret = NULL; endif;

	return $ret;
} /* function get_syndication_source_link() */

function the_syndication_source_link ($original = NULL) { echo get_syndication_source_link($original); }

function get_syndication_source ($original = NULL) {
	if (is_null($original)) : $original = FeedWordPress::use_aggregator_source_data();
	endif;

	if ($original) : $vals = get_post_custom_values('syndication_source_original');
	else : $vals = array();
	endif;
	
	if (count($vals) == 0) : $vals = get_post_custom_values('syndication_source');
	endif;
	
	if (count($vals) > 0) : $ret = $vals[0]; else : $ret = NULL; endif;

	return $ret;
} /* function get_syndication_source() */

function the_syndication_source ($original = NULL) { echo get_syndication_source($original); }

function get_syndication_feed ($original = NULL) {
	if (is_null($original)) : $original = FeedWordPress::use_aggregator_source_data();
	endif;

	if ($original) : $vals = get_post_custom_values('syndication_feed_original');
	else : $vals = array();
	endif;
	
	if (count($vals) == 0) : $vals = get_post_custom_values('syndication_feed');
	endif;
	
	if (count($vals) > 0) : $ret = $vals[0]; else : $ret = NULL; endif;

	return $ret;
} /* function get_syndication_feed() */

function the_syndication_feed ($original = NULL) { echo get_syndication_feed ($original); }

function get_syndication_feed_guid ($original = NULL) {
	if (is_null($original)) : $original = FeedWordPress::use_aggregator_source_data();
	endif;

	if ($original) : $vals = get_post_custom_values('syndication_source_id_original');
	else : $vals = array();
	endif;
	
	if (count($vals) == 0) : $vals = array(get_feed_meta('feed/id'));
	endif;
	
	if (count($vals) > 0) : $ret = $vals[0]; else : $ret = NULL; endif;

	return $ret;
} /* function get_syndication_feed_guid () */

function the_syndication_feed_guid ($original = NULL) { echo get_syndication_feed_guid($original); }

function get_syndication_feed_id () { list($u) = get_post_custom_values('syndication_feed_id'); return $u; }
function the_syndication_feed_id () { echo get_syndication_feed_id(); }

$feedwordpress_linkcache =  array (); // only load links from database once

function get_feed_meta ($key) {
	global $wpdb, $feedwordpress_linkcache;
	$feed_id = get_syndication_feed_id();

	$ret = NULL;
	if (strlen($feed_id) > 0):
		if (isset($feedwordpress_linkcache[$feed_id])) :
			$link = $feedwordpress_linkcache[$feed_id];
		else :
			$link =& new SyndicatedLink($feed_id);
			$feedwordpress_linkcache[$feed_id] = $link;
		endif;

		$ret = $link->settings[$key];
	endif;
	return $ret;
} /* get_feed_meta() */

function get_syndication_permalink () {
	list($u) = get_post_custom_values('syndication_permalink'); return $u;
}
function the_syndication_permalink () {
	echo get_syndication_permalink();
}

################################################################################
## FILTERS: syndication-aware handling of post data for templates and feeds ####
################################################################################

$feedwordpress_the_syndicated_content = NULL;

function feedwordpress_preserve_syndicated_content ($text) {
	global $feedwordpress_the_syndicated_content;

	if ( is_syndicated() and get_option('feedwordpress_formatting_filters') != 'yes' ) :
		$feedwordpress_the_syndicated_content = $text;
	else :
		$feedwordpress_the_syndicated_content = NULL;
	endif;
	return $text;
}

function feedwordpress_restore_syndicated_content ($text) {
	global $feedwordpress_the_syndicated_content;
	
	if ( !is_null($feedwordpress_the_syndicated_content) ) :
		$text = $feedwordpress_the_syndicated_content;
	endif;

	return $text;
}

function feedwordpress_item_feed_data () {
	// In a post context....
	if (is_syndicated()) :
?>
<source>
	<title><?php the_syndication_source(); ?></title>
	<link rel="alternate" type="text/html" href="<?php the_syndication_source_link(); ?>" />
	<link rel="self" href="<?php the_syndication_feed(); ?>" />
<?php
	$id = get_syndication_feed_guid();
	if (strlen($id) > 0) :
?>
	<id><?php print $id; ?></id>
<?php
	endif;
	$updated = get_feed_meta('feed/updated');
	if (strlen($updated) > 0) : ?>
	<updated><?php print $updated; ?></updated>
<?php
	endif;
?>
</source>
<?php
	endif;
}

function syndication_permalink ($permalink = '') {
	if (get_option('feedwordpress_munge_permalink') != 'no'):
		$uri = get_syndication_permalink();
		return ((strlen($uri) > 0) ? $uri : $permalink);
	else:
		return $permalink;
	endif;
} // function syndication_permalink ()

################################################################################
## ADMIN MENU ADD-ONS: register Dashboard management pages #####################
################################################################################

function fwp_add_pages () {
	global $fwp_capability;
	global $fwp_path;

	$menu = array('Syndicated Sites', 'Syndication', $fwp_capability['manage_links'], $fwp_path.'/syndication.php', NULL);
	if (fwp_test_wp_version(FWP_SCHEMA_27)) :
		// add icon parameter
		$menu[] = WP_PLUGIN_URL.'/'.$fwp_path.'/feedwordpress-tiny.png';
	endif;

	if (fwp_test_wp_version(FWP_SCHEMA_26)) :
		$options = __('Settings');
		$longoptions = __('Syndication Settings');
	else :
		$options = __('Options');
		$longoptions = __('Syndication Options');
	endif;

	call_user_func_array('add_menu_page', $menu);
	add_submenu_page($fwp_path.'/syndication.php', 'Syndicated Authors', 'Authors', $fwp_capability['manage_options'], $fwp_path.'/authors.php');
	add_submenu_page($fwp_path.'/syndication.php', $longoptions, $options, $fwp_capability['manage_options'], $fwp_path.'/syndication-options.php');

	add_options_page($longoptions, 'Syndication', $fwp_capability['manage_options'], $fwp_path.'/syndication-options.php');
} // function fwp_add_pages () */

################################################################################
## fwp_hold_pings() and fwp_release_pings(): Outbound XML-RPC ping reform   ####
## ... 'coz it's rude to send 500 pings the first time your aggregator runs ####
################################################################################

$fwp_held_ping = NULL;		// NULL: not holding pings yet

function fwp_hold_pings () {
	global $fwp_held_ping;
	if (is_null($fwp_held_ping)):
		$fwp_held_ping = 0;	// 0: ready to hold pings; none yet received
	endif;
}

function fwp_release_pings () {
	global $fwp_held_ping;
	if ($fwp_held_ping):
		if (function_exists('wp_schedule_single_event')) :
			wp_schedule_single_event(time(), 'do_pings');
		else :
			generic_ping($fwp_held_ping);
		endif;
	endif;
	$fwp_held_ping = NULL;	// NULL: not holding pings anymore
}

function fwp_do_pings () {
	if (!is_null($fwp_held_ping) and $post_id) : // Defer until we're done updating
		$fwp_held_ping = $post_id;
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
	else :
		if (function_exists('_publish_post_hook')) : // WordPress 2.3
			_publish_post_hook($post_id);
		endif;
	endif;
}

################################################################################
## class FeedWordPress #########################################################
################################################################################

// class FeedWordPress: handles feed updates and plugs in to the XML-RPC interface
class FeedWordPress {
	var $strip_attrs = array (
		      array('[a-z]+', 'style'),
		      array('[a-z]+', 'target'),
	);
	var $uri_attrs = array (
			array('a', 'href'),
			array('applet', 'codebase'),
			array('area', 'href'),
			array('blockquote', 'cite'),
			array('body', 'background'),
			array('del', 'cite'),
			array('form', 'action'),
			array('frame', 'longdesc'),
			array('frame', 'src'),
			array('iframe', 'longdesc'),
			array('iframe', 'src'),
			array('head', 'profile'),
			array('img', 'longdesc'),
			array('img', 'src'),
			array('img', 'usemap'),
			array('input', 'src'),
			array('input', 'usemap'),
			array('ins', 'cite'),
			array('link', 'href'),
			array('object', 'classid'),
			array('object', 'codebase'),
			array('object', 'data'),
			array('object', 'usemap'),
			array('q', 'cite'),
			array('script', 'src')
	);

	var $feeds = NULL;

	# function FeedWordPress (): Contructor; retrieve a list of feeds 
	function FeedWordPress () {
		$this->feeds = array ();
		$links = FeedWordPress::syndicated_links();
		if ($links): foreach ($links as $link):
			$this->feeds[] =& new SyndicatedLink($link);
		endforeach; endif;
	} // FeedWordPress::FeedWordPress ()

	# function update (): polls for updates on one or more Contributor feeds
	#
	# Arguments:
	# ----------
	# *    $uri (string): either the URI of the feed to poll, the URI of the
	#      (human-readable) website whose feed you want to poll, or NULL.
	#
	#      If $uri is NULL, then FeedWordPress will poll any feeds that are
	#      ready for polling. It will not poll feeds that are marked as
	#      "Invisible" Links (signifying that the subscription has been
	#      de-activated), or feeds that are not yet stale according to their
	#      TTL setting (which is either set in the feed, or else set
	#      randomly within a window of 30 minutes - 2 hours).
	#
	# Returns:
	# --------
	# *    Normally returns an associative array, with 'new' => the number
	#      of new posts added during the update, and 'updated' => the number
	#      of old posts that were updated during the update. If both numbers
	#      are zero, there was no change since the last poll on that URI.
	#
	# *    Returns NULL if URI it was passed was not a URI that this
	#      installation of FeedWordPress syndicates.
	#
	# Effects:
	# --------
	# *    One or more feeds are polled for updates
	#
	# *    If the feed Link does not have a hardcoded name set, its Link
	#      Name is synchronized with the feed's title element
	#
	# *    If the feed Link does not have a hardcoded URI set, its Link URI
	#      is synchronized with the feed's human-readable link element
	#
	# *    If the feed Link does not have a hardcoded description set, its
	#      Link Description is synchronized with the feed's description,
	#      tagline, or subtitle element.
	#
	# *    The time of polling is recorded in the feed's settings, and the
	#      TTL (time until the feed is next available for polling) is set
	#      either from the feed (if it is supplied in the ttl or syndication
	#      module elements) or else from a randomly-generated time window
	#      (between 30 minutes and 2 hours).
	#
	# *    New posts from the polled feed are added to the WordPress store.
	# 
	# *    Updates to existing posts since the last poll are mirrored in the
	#      WordPress store.
	#
	function update ($uri = null, $crash_ts = null) {
		global $wpdb;

		if (FeedWordPress::needs_upgrade()) : // Will make duplicate posts if we don't hold off
			return NULL;
		endif;
		
		if (!is_null($uri)) :
			$uri = trim($uri);
		else : // Update all
			update_option('feedwordpress_last_update_all', time());
		endif;

		do_action('feedwordpress_update', $uri);

		if (is_null($crash_ts)) :
			$crash_dt = (int) get_option('feedwordpress_update_time_limit');
			if ($crash_dt > 0) :
				$crash_ts = time() + $crash_dt;
			else :
				$crash_ts = NULL;
			endif;
		endif;
		
		// Randomize order for load balancing purposes
		$feed_set = $this->feeds;
		shuffle($feed_set);

		// Loop through and check for new posts
		$delta = NULL;
		foreach ($feed_set as $feed) :
			if (!is_null($crash_ts) and (time() > $crash_ts)) : // Check whether we've exceeded the time limit
				break;
			endif;

			$pinged_that = (is_null($uri) or in_array($uri, array($feed->uri(), $feed->homepage())));

			if (!is_null($uri)) : // A site-specific ping always updates
				$timely = true;
			else :
				$timely = $feed->stale();
			endif;

			if ($pinged_that and is_null($delta)) :			// If at least one feed was hit for updating...
				$delta = array('new' => 0, 'updated' => 0);	// ... don't return error condition 
			endif;

			if ($pinged_that and $timely) :
				do_action('feedwordpress_check_feed', $feed->settings);
				$start_ts = time();
				$added = $feed->poll($crash_ts);
				do_action('feedwordpress_check_feed_complete', $feed->settings, $added, time() - $start_ts);

				if (isset($added['new'])) : $delta['new'] += $added['new']; endif;
				if (isset($added['updated'])) : $delta['updated'] += $added['updated']; endif;
			endif;
		endforeach;

		do_action('feedwordpress_update_complete', $delta);

		return $delta;
	}

	function stale () {
		if (get_option('feedwordpress_automatic_updates')) :
			$last = get_option('feedwordpress_last_update_all');
		
			// If we haven't updated all yet, give it a time window
			if (false === $last) :
				$ret = false;
				update_option('feedwordpress_last_update_all', time());
			
			// Otherwise, check against freshness interval
			elseif (is_numeric($last)) : // Expect a timestamp
				$freshness = get_option('feedwordpress_freshness');
				if (false === $freshness) : // Use default
					$freshness = FEEDWORDPRESS_FRESHNESS_INTERVAL;
				endif;
				$ret = ( (time() - $last) > $freshness);

			 // This should never happen.
			else :
				FeedWordPress::critical_bug('FeedWordPress::stale::last', $last, __LINE__);
			endif;

		// Explicit request for an update (e.g. from a cron job).
		elseif (FeedWordPress::update_requested()) :
			$ret = true;

		else :
			$ret = false;
		endif;
		return $ret;
	} // FeedWordPress::stale()
	
	function update_requested () {
		return (isset($_REQUEST['update_feedwordpress']) and $_REQUEST['update_feedwordpress']);
	} // FeedWordPress::update_requested()

	function syndicate_link ($name, $uri, $rss) {
		global $wpdb;

		// Get the category ID#
		$cat_id = FeedWordPress::link_category_id();
		
		// WordPress gets cranky if there's no homepage URI
		if (!isset($uri) or strlen($uri)<1) : $uri = $rss; endif;
		
		if (function_exists('wp_insert_link')) { // WordPress 2.x
			$link_id = wp_insert_link(array(
				"link_name" => $name,
				"link_url" => $uri,
				"link_category" => (fwp_test_wp_version(0, FWP_SCHEMA_21) ? $cat_id : array($cat_id)),
				"link_rss" => $rss
			));
		} else { // WordPress 1.5.x
			$result = $wpdb->query("
			INSERT INTO $wpdb->links
			SET
				link_name = '".$wpdb->escape($name)."',
				link_url = '".$wpdb->escape($uri)."',
				link_category = '".$wpdb->escape($cat_id)."',
				link_rss = '".$wpdb->escape($rss)."'
			");
			$link_id = $wpdb->insert_id;
		} // if
		return $link_id;
	} // function FeedWordPress::syndicate_link()

	function on_unfamiliar ($what = 'author', $override = NULL) {
		$set = array(
			'author' => array('create', 'default', 'filter'),
			'category' => array('create', 'tag', 'default', 'filter'),
		);
		
		if (is_string($override)) :
			$ret = strtolower($override);
		endif;

		if (!is_numeric($override) and !in_array($ret, $set[$what])) :
			$ret = get_option('feedwordpress_unfamiliar_'.$what);
			if (!is_numeric($ret) and !in_array($ret, $set[$what])) :
				$ret = 'create';
			endif;
		endif;

		return $ret;
	} // function FeedWordPress::on_unfamiliar()

	function null_email_set () {
		$base = get_option('feedwordpress_null_email_set');

		if ($base===false) :
			$ret = array('noreply@blogger.com'); // default
		else :
			$ret = array_map('strtolower',
				array_map('trim', explode("\n", $base)));
		endif;
		$ret = apply_filters('syndicated_item_author_null_email_set', $ret);
		return $ret;

	} /* FeedWordPress::null_email_set () */

	function is_null_email ($email) {
		$ret = in_array(strtolower(trim($email)), FeedWordPress::null_email_set());
		$ret = apply_filters('syndicated_item_author_is_null_email', $ret, $email);
		return $ret;
	} /* FeedWordPress::is_null_email () */

	function use_aggregator_source_data () {
		$ret = get_option('feedwordpress_use_aggregator_source_data');
		return apply_filters('syndicated_post_use_aggregator_source_data', ($ret=='yes'));
	}

	function syndicated_links () {
		$contributors = FeedWordPress::link_category_id();
		if (function_exists('get_bookmarks')) :
			$links = get_bookmarks(array("category" => $contributors));
		else: 
			$links = get_linkobjects($contributors); // deprecated as of WP 2.1
		endif;
		return $links;
	} // function FeedWordPress::syndicated_links()

	function link_category_id () {
		global $wpdb, $wp_db_version;

		$cat_id = get_option('feedwordpress_cat_id');
		
		// If we don't yet *have* the category, we'll have to create it
		if ($cat_id === false) :
			$cat = $wpdb->escape(DEFAULT_SYNDICATION_CATEGORY);
			
			// Look for something with the right name...
			// -----------------------------------------

			// WordPress 2.3 introduces a new taxonomy/term API
			if (function_exists('is_term')) :
				$cat_id = is_term($cat, 'link_category');
			// WordPress 2.1 and 2.2 use a common table for both link and post categories
			elseif (isset($wp_db_version) and ($wp_db_version >= FWP_SCHEMA_21 and $wp_db_version < FWP_SCHEMA_23) ) :
				$cat_id = $wpdb->get_var("SELECT cat_id FROM {$wpdb->categories} WHERE cat_name='$cat'");
			// WordPress 1.5 and 2.0.x have a separate table for link categories
			elseif (!isset($wp_db_version) or $wp_db_version < FWP_SCHEMA_21) :
				$cat_id = $wpdb->get_var("SELECT cat_id FROM {$wpdb->linkcategories} WHERE cat_name='$cat'");
			// This should never happen.
			else :
				FeedWordPress::critical_bug('FeedWordPress::link_category_id::wp_db_version', $wp_db_version, __LINE__);
			endif;

			// If you still can't find anything, make it for yourself.
			// -------------------------------------------------------
			if (!$cat_id) :
				// WordPress 2.3+ term/taxonomy API
				if (function_exists('wp_insert_term')) :
					$term = wp_insert_term($cat, 'link_category');
					$cat_id = $term['term_id'];
				// WordPress 2.1, 2.2 category API. By the way, why the fuck is this API function only available in a wp-admin module?
				elseif (function_exists('wp_insert_category') and !fwp_test_wp_version(FWP_SCHEMA_20, FWP_SCHEMA_21)) : 
					$cat_id = wp_insert_category(array('cat_name' => $cat));
				// WordPress 1.5 and 2.0.x
				elseif (fwp_test_wp_version(0, FWP_SCHEMA_21)) :
					$result = $wpdb->query("
					INSERT INTO $wpdb->linkcategories
					SET
						cat_id = 0,
						cat_name='$cat',
						show_images='N',
						show_description='N',
						show_rating='N',
						show_updated='N',
						sort_order='name'
					");
					$cat_id = $wpdb->insert_id;
				// This should never happen.
				else :
					FeedWordPress::critical_bug('FeedWordPress::link_category_id::wp_db_version', $wp_db_version, __LINE__);
				endif;
			endif;

			update_option('feedwordpress_cat_id', $cat_id);
		endif;
		return $cat_id;
	} // function FeedWordPress::link_category_id()

	# Upgrades and maintenance...
	function needs_upgrade () {
		global $wpdb;
		$fwp_db_version = get_option('feedwordpress_version');
		$ret = false; // innocent until proven guilty
		if (!$fwp_db_version or $fwp_db_version < FEEDWORDPRESS_VERSION) :
			// This is an older version or a fresh install. Does it
			// require a database upgrade or database initialization?
			if ($fwp_db_version > 0.96) :
				// No. Just brand it with the new version.
				update_option('feedwordpress_version', FEEDWORDPRESS_VERSION);
			else :
				// Yes. Check to see whether this is a fresh install or an upgrade.
				$syn = $wpdb->get_col("
				SELECT post_id
				FROM $wpdb->postmeta
				WHERE meta_key = 'syndication_feed'
				");
				if (count($syn) > 0) : // contains at least one syndicated post
					$ret = true;
				else : // fresh install; brand it as ours
					update_option('feedwordpress_version', FEEDWORDPRESS_VERSION);
				endif;
			endif;
		endif;
		return $ret;
	}

	function upgrade_database ($from = NULL) {
		global $wpdb;

		if (is_null($from) or $from <= 0.96) : $from = 0.96; endif;

		switch ($from) :
		case 0.96: // account for changes to syndication custom values and guid
			echo "<p>Upgrading database from {$from} to ".FEEDWORDPRESS_VERSION."...</p>\n";

			$cat_id = FeedWordPress::link_category_id();
			
			// Avoid duplicates
			$wpdb->query("DELETE FROM `{$wpdb->postmeta}` WHERE meta_key = 'syndication_feed_id'");
			
			// Look up all the link IDs
			$wpdb->query("
			CREATE TEMPORARY TABLE tmp_custom_values
			SELECT
				NULL AS meta_id,
				post_id,
				'syndication_feed_id' AS meta_key,
				link_id AS meta_value
			FROM `{$wpdb->postmeta}`, `{$wpdb->links}`
			WHERE
				meta_key='syndication_feed'
				AND meta_value=link_rss
				AND link_category = {$cat_id}
			");
			
			// Now attach them to their posts
			$wpdb->query("INSERT INTO `{$wpdb->postmeta}` SELECT * FROM tmp_custom_values");
			
			// And clean up after ourselves.
			$wpdb->query("DROP TABLE tmp_custom_values");
			
			// Now fix the guids to avoid duplicate posts
			echo "<ul>";
			foreach ($this->feeds as $feed) :
				echo "<li>Fixing post meta-data for <cite>".$feed['link/name']."</cite> &#8230; "; flush();
				$rss = @fetch_rss($feed['link/uri']);
				if (is_array($rss->items)) :
					foreach ($rss->items as $item) :
						$guid = $wpdb->escape(FeedWordPress::guid($item, $feed)); // new GUID algorithm
						$link = $wpdb->escape($item['link']);
						
						$wpdb->query("
						UPDATE `{$wpdb->posts}` SET guid='{$guid}' WHERE guid='{$link}'
						");
					endforeach;
				endif;
				echo "<strong>complete.</strong></li>\n";
			endforeach;
			echo "</ul>\n";

			// Mark the upgrade as successful.
			update_option('feedwordpress_version', FEEDWORDPRESS_VERSION);
		endswitch;
		echo "<p>Upgrade complete. FeedWordPress is now ready to use again.</p>";
	} /* FeedWordPress::upgrade_database() */

	function create_guid_index () {
		global $wpdb;
		
		$wpdb->query("
		CREATE INDEX {$wpdb->posts}_guid_idx ON {$wpdb->posts}(guid)
		");
	} /* FeedWordPress::create_guid_index () */
	
	# Utility functions for handling text settings
	function negative ($f, $setting) {
		$nego = array ('n', 'no', 'f', 'false');
		return (isset($f[$setting]) and in_array(strtolower($f[$setting]), $nego));
	}

	function affirmative ($f, $setting) {
		$affirmo = array ('y', 'yes', 't', 'true', 1);
		return (isset($f[$setting]) and in_array(strtolower($f[$setting]), $affirmo));
	}


	# Internal debugging functions
	function critical_bug ($varname, $var, $line) {
		global $wp_version;

		echo '<p>There may be a bug in FeedWordPress. Please <a href="'.FEEDWORDPRESS_AUTHOR_CONTACT.'">contact the author</a> and paste the following information into your e-mail:</p>';
		echo "\n<plaintext>";
		echo "Triggered at line # ".$line."\n";
		echo "FeedWordPress version: ".FEEDWORDPRESS_VERSION."\n";
		echo "WordPress version: $wp_version\n";
		echo "PHP version: ".phpversion()."\n";
		echo "\n";
		echo $varname.": "; var_dump($var); echo "\n";
		die;
	}
	
	function noncritical_bug ($varname, $var, $line) {
		if (FEEDWORDPRESS_DEBUG) : // halt only when we are doing debugging
			FeedWordPress::critical_bug($varname, $var, $line);
		endif;
	}
} // class FeedWordPress

class SyndicatedPost {
	var $item = null;
	
	var $link = null;
	var $feed = null;
	var $feedmeta = null;
	
	var $post = array ();
	var $_base = null;

	var $_freshness = null;
	var $_wp_id = null;

	function SyndicatedPost ($item, $link) {
		global $wpdb;

		$this->link = $link;
		$feedmeta = $link->settings;
		$feed = $link->magpie;

		# This is ugly as all hell. I'd like to use apply_filters()'s
		# alleged support for a variable argument count, but this seems
		# to have been broken in WordPress 1.5. It'll be fixed somehow
		# in WP 1.5.1, but I'm aiming at WP 1.5 compatibility across
		# the board here.
		#
		# Cf.: <http://mosquito.wordpress.org/view.php?id=901>
		global $fwp_channel, $fwp_feedmeta;
		$fwp_channel = $feed; $fwp_feedmeta = $feedmeta;

		$this->feed = $feed;
		$this->feedmeta = $feedmeta;

		$this->item = $item;
		$this->item = apply_filters('syndicated_item', $this->item, $this);

		# Filters can halt further processing by returning NULL
		if (is_null($this->item)) :
			$this->post = NULL;
		else :
			# Note that nothing is run through $wpdb->escape() here.
			# That's deliberate. The escaping is done at the point
			# of insertion, not here, to avoid double-escaping and
			# to avoid screwing with syndicated_post filters

			$this->post['post_title'] = apply_filters('syndicated_item_title', $this->item['title'], $this);

			// This just gives us an alphanumeric representation of
			// the author. We will look up (or create) the numeric
			// ID for the author in SyndicatedPost::add()
			$this->post['named']['author'] = apply_filters('syndicated_item_author', $this->author(), $this);

			# Identify content and sanitize it.
			# ---------------------------------
			if (isset($this->item['xhtml']['body'])) :
				$content = $this->item['xhtml']['body'];
			elseif (isset($this->item['xhtml']['div'])) :
				$content = $this->item['xhtml']['div'];
			elseif (isset($this->item['content']['encoded']) and $this->item['content']['encoded']):
				$content = $this->item['content']['encoded'];
			else:
				$content = $this->item['description'];
			endif;
			$this->post['post_content'] = apply_filters('syndicated_item_content', $content, $this);

			# Identify and sanitize excerpt
			$excerpt = NULL;
			if ( isset($this->item['description']) and $this->item['description'] ) :
				$excerpt = $this->item['description'];
			elseif ( isset($content) and $content ) :
				$excerpt = strip_tags($content);
				if (strlen($excerpt) > 255) :
					$excerpt = substr($excerpt,0,252).'...';
				endif;
			endif;
			$excerpt = apply_filters('syndicated_item_excerpt', $excerpt, $this); 

			if (!is_null($excerpt)):
				$this->post['post_excerpt'] = $excerpt;
			endif;
			
			// This is unnecessary if we use wp_insert_post
			if (!$this->use_api('wp_insert_post')) :
				$this->post['post_name'] = sanitize_title($this->post['post_title']);
			endif;

			$this->post['epoch']['issued'] = apply_filters('syndicated_item_published', $this->published(), $this);
			$this->post['epoch']['created'] = apply_filters('syndicated_item_created', $this->created(), $this);
			$this->post['epoch']['modified'] = apply_filters('syndicated_item_updated', $this->updated(), $this);

			// Dealing with timestamps in WordPress is so fucking fucked.
			$offset = (int) get_option('gmt_offset') * 60 * 60;
			$this->post['post_date'] = gmdate('Y-m-d H:i:s', $this->published() + $offset);
			$this->post['post_modified'] = gmdate('Y-m-d H:i:s', $this->updated() + $offset);
			$this->post['post_date_gmt'] = gmdate('Y-m-d H:i:s', $this->published());
			$this->post['post_modified_gmt'] = gmdate('Y-m-d H:i:s', $this->updated());

			// Use feed-level preferences or the global default.
			$this->post['post_status'] = $this->link->syndicated_status('post', 'publish');
			$this->post['comment_status'] = $this->link->syndicated_status('comment', 'closed');
			$this->post['ping_status'] = $this->link->syndicated_status('ping', 'closed');

			// Unique ID (hopefully a unique tag: URI); failing that, the permalink
			$this->post['guid'] = apply_filters('syndicated_item_guid', $this->guid(), $this);

			// RSS 2.0 / Atom 1.0 enclosure support
			if ( isset($this->item['enclosure#']) ) :
				for ($i = 1; $i <= $this->item['enclosure#']; $i++) :
					$eid = (($i > 1) ? "#{$id}" : "");
					$this->post['meta']['enclosure'][] =
						apply_filters('syndicated_item_enclosure_url', $this->item["enclosure{$eid}@url"], $this)."\n".
						apply_filters('syndicated_item_enclosure_length', $this->item["enclosure{$eid}@length"], $this)."\n".
						apply_filters('syndicated_item_enclosure_type', $this->item["enclosure{$eid}@type"], $this);
				endfor;
			endif;

			// In case you want to point back to the blog this was syndicated from
			if (isset($this->feed->channel['title'])) :
				$this->post['meta']['syndication_source'] = apply_filters('syndicated_item_source_title', $this->feed->channel['title'], $this);
			endif;

			if (isset($this->feed->channel['link'])) :
				$this->post['meta']['syndication_source_uri'] = apply_filters('syndicated_item_source_link', $this->feed->channel['link'], $this);
			endif;
			
			// Make use of atom:source data, if present in an aggregated feed
			if (isset($this->item['source_title'])) :
				$this->post['meta']['syndication_source_original'] = $this->item['source_title'];
			endif;

			if (isset($this->item['source_link'])) :
				$this->post['meta']['syndication_source_uri_original'] = $this->item['source_link'];
			endif;
			
			if (isset($this->item['source_id'])) :
				$this->post['meta']['syndication_source_id_original'] = $this->item['source_id'];
			endif;

			// Store information on human-readable and machine-readable comment URIs
			if (isset($this->item['comments'])) :
				$this->post['meta']['rss:comments'] = apply_filters('syndicated_item_comments', $this->item['comments']);
			endif;
			if (isset($this->item['wfw']['commentrss'])) :
				$this->post['meta']['wfw:commentRSS'] = apply_filters('syndicated_item_commentrss', $this->item['wfw']['commentrss']);
			endif;

			// Store information to identify the feed that this came from
			$this->post['meta']['syndication_feed'] = $this->feedmeta['link/uri'];
			$this->post['meta']['syndication_feed_id'] = $this->feedmeta['link/id'];

			if (isset($this->item['source_link_self'])) :
				$this->post['meta']['syndication_feed_original'] = $this->item['source_link_self'];
			endif;

			// In case you want to know the external permalink...
			$this->post['meta']['syndication_permalink'] = apply_filters('syndicated_item_link', $this->item['link']);

			// Store a hash of the post content for checking whether something needs to be updated
			$this->post['meta']['syndication_item_hash'] = $this->update_hash();

			// Feed-by-feed options for author and category creation
			$this->post['named']['unfamiliar']['author'] = $this->feedmeta['unfamiliar author'];
			$this->post['named']['unfamiliar']['category'] = $this->feedmeta['unfamiliar category'];

			// Categories: start with default categories, if any
			$fc = get_option("feedwordpress_syndication_cats");
			if ($fc) :
				$this->post['named']['preset/category'] = explode("\n", $fc);
			else :
				$this->post['named']['preset/category'] = array();
			endif;

			if (is_array($this->feedmeta['cats'])) :
				$this->post['named']['preset/category'] = array_merge($this->post['named']['preset/category'], $this->feedmeta['cats']);
			endif;

			// Now add categories from the post, if we have 'em
			$this->post['named']['category'] = array();
			if ( isset($this->item['category#']) ) :
				for ($i = 1; $i <= $this->item['category#']; $i++) :
					$cat_idx = (($i > 1) ? "#{$i}" : "");
					$cat = $this->item["category{$cat_idx}"];

					if ( isset($this->feedmeta['cat_split']) and strlen($this->feedmeta['cat_split']) > 0) :
						$pcre = "\007".$this->feedmeta['cat_split']."\007";
						$this->post['named']['category'] = array_merge($this->post['named']['category'], preg_split($pcre, $cat, -1 /*=no limit*/, PREG_SPLIT_NO_EMPTY));
					else :
						$this->post['named']['category'][] = $cat;
					endif;
				endfor;
			endif;
			$this->post['named']['category'] = apply_filters('syndicated_item_categories', $this->post['named']['category'], $this);
			
			// Tags: start with default tags, if any
			$ft = get_option("feedwordpress_syndication_tags");
			if ($ft) :
				$this->post['tags_input'] = explode(FEEDWORDPRESS_CAT_SEPARATOR, $ft);
			else :
				$this->post['tags_input'] = array();
			endif;
			
			if (is_array($this->feedmeta['tags'])) :
				$this->post['tags_input'] = array_merge($this->post['tags_input'], $this->feedmeta['tags']);
			endif;

		endif;
	} // SyndicatedPost::SyndicatedPost()

	function filtered () {
		return is_null($this->post);
	}

	function freshness () {
		global $wpdb;

		if ($this->filtered()) : // This should never happen.
			FeedWordPress::critical_bug('SyndicatedPost', $this, __LINE__);
		endif;
		
		if (is_null($this->_freshness)) :
			$guid = $wpdb->escape($this->guid());

			$result = $wpdb->get_row("
			SELECT id, guid, post_modified_gmt
			FROM $wpdb->posts WHERE guid='$guid'
			");

			if (!$result) :
				$this->_freshness = 2; // New content
			else:
				$stored_update_hashes = get_post_custom_values('syndication_item_hash', $result->id);
				if (count($stored_update_hashes) > 0) :
					$stored_update_hash = $stored_update_hashes[0];
					$update_hash_changed = ($stored_update_hash != $this->update_hash());
				else :
					$update_hash_changed = false;
				endif;

				preg_match('/([0-9]+)-([0-9]+)-([0-9]+) ([0-9]+):([0-9]+):([0-9]+)/', $result->post_modified_gmt, $backref);

				$last_rev_ts = gmmktime($backref[4], $backref[5], $backref[6], $backref[2], $backref[3], $backref[1]);
				$updated_ts = $this->updated(/*fallback=*/ true, /*default=*/ NULL);
				$updated = ((
					!is_null($updated_ts)
					and ($updated_ts > $last_rev_ts)
				) or $update_hash_changed);

				if ($updated) :
					$this->_freshness = 1; // Updated content
					$this->_wp_id = $result->id;
				else :
					$this->_freshness = 0; // Same old, same old
					$this->_wp_id = $result->id;
				endif;
			endif;
		endif;
		return $this->_freshness;
	}

	function wp_id () {
		if ($this->filtered()) : // This should never happen.
			FeedWordPress::critical_bug('SyndicatedPost', $this, __LINE__);
		endif;
		
		if (is_null($this->_wp_id) and is_null($this->_freshness)) :
			$fresh = $this->freshness(); // sets WP DB id in the process
		endif;
		return $this->_wp_id;
	}

	function store () {
		global $wpdb;

		if ($this->filtered()) : // This should never happen.
			FeedWordPress::critical_bug('SyndicatedPost', $this, __LINE__);
		endif;
		
		$freshness = $this->freshness();
		if ($freshness > 0) :
			# -- Look up, or create, numeric ID for author
			$this->post['post_author'] = $this->author_id (
				FeedWordPress::on_unfamiliar('author', $this->post['named']['unfamiliar']['author'])
			);

			if (is_null($this->post['post_author'])) :
				$this->post = NULL;
			endif;
		endif;
		
		if (!$this->filtered() and $freshness > 0) :
			# -- Look up, or create, numeric ID for categories
			list($pcats, $ptags) = $this->category_ids (
				$this->post['named']['category'],
				FeedWordPress::on_unfamiliar('category', $this->post['named']['unfamiliar']['category']),
				/*tags_too=*/ true
			);

			$this->post['post_category'] = $pcats;
			$this->post['tags_input'] = array_merge($this->post['tags_input'], $ptags);

			if (is_null($this->post['post_category'])) :
				// filter mode on, no matching categories; drop the post
				$this->post = NULL;
			else :
				// filter mode off or at least one match; now add on the feed and global presets
				$this->post['post_category'] = array_merge (
					$this->post['post_category'],
					$this->category_ids (
						$this->post['named']['preset/category'],
						'default'
					)
				);

				if (count($this->post['post_category']) < 1) :
					$this->post['post_category'][] = 1; // Default to category 1 ("Uncategorized" / "General") if nothing else
				endif;
			endif;
		endif;
		
		if (!$this->filtered() and $freshness > 0) :
			unset($this->post['named']);
			$this->post = apply_filters('syndicated_post', $this->post, $this);
		endif;
		
		if (!$this->filtered() and $freshness == 2) :
			// The item has not yet been added. So let's add it.
			$this->insert_new();
			$this->add_rss_meta();
			do_action('post_syndicated_item', $this->wp_id());

			$ret = 'new';
		elseif (!$this->filtered() and $freshness == 1) :
			$this->post['ID'] = $this->wp_id();
			$this->update_existing();
			$this->add_rss_meta();
			do_action('update_syndicated_item', $this->wp_id());

			$ret = 'updated';			
		else :
			$ret = false;
		endif;
		
		return $ret;
	} // function SyndicatedPost::store ()
	
	function insert_new () {
		global $wpdb, $wp_db_version;
		
		// Why the fuck doesn't wp_insert_post already do this?
		foreach ($this->post as $key => $value) :
			if (is_string($value)) :
				$dbpost[$key] = $wpdb->escape($value);
			else :
				$dbpost[$key] = $value;
			endif;
		endforeach;

		if (strlen($dbpost['post_title'].$dbpost['post_content'].$dbpost['post_excerpt']) == 0) :
			// FIXME: Option for filtering out empty posts
		endif;
		if (strlen($dbpost['post_title'])==0) :
			$dbpost['post_title'] = $this->post['meta']['syndication_source']
				.' '.gmdate('Y-m-d H:i:s', $this->published() + $offset);
			// FIXME: Option for what to fill a blank title with...
		endif;

		if ($this->use_api('wp_insert_post')) :
			$dbpost['post_pingback'] = false; // Tell WP 2.1 and 2.2 not to process for pingbacks

			// This is a ridiculous fucking kludge necessitated by WordPress 2.6 munging authorship meta-data
			add_action('_wp_put_post_revision', array($this, 'fix_revision_meta'));
			
			// Kludge to prevent kses filters from stripping the
			// content of posts when updating without a logged in
			// user who has `unfiltered_html` capability.
			add_filter('content_save_pre', array($this, 'avoid_kses_munge'), 11);
			
			$this->_wp_id = wp_insert_post($dbpost);

			// Turn off ridiculous fucking kludges #1 and #2
			remove_action('_wp_put_post_revision', array($this, 'fix_revision_meta'));
			remove_filter('content_save_pre', array($this, 'avoid_kses_munge'), 11);

			// This should never happen.
			if (!is_numeric($this->_wp_id) or ($this->_wp_id == 0)) :
				FeedWordPress::critical_bug('SyndicatedPost (_wp_id problem)', array("dbpost" => $dbpost, "this" => $this), __LINE__);
			endif;
	
			// Unfortunately, as of WordPress 2.3, wp_insert_post()
			// *still* offers no way to use a guid of your choice,
			// and munges your post modified timestamp, too.
			$result = $wpdb->query("
				UPDATE $wpdb->posts
				SET
					guid='{$dbpost['guid']}',
					post_modified='{$dbpost['post_modified']}',
					post_modified_gmt='{$dbpost['post_modified_gmt']}'
				WHERE ID='{$this->_wp_id}'
			");
		else :
			# The right way to do this is the above. But, alas,
			# in earlier versions of WordPress, wp_insert_post has
			# too much behavior (mainly related to pings) that can't
			# be overridden. In WordPress 1.5, it's enough of a
			# resource hog to make PHP segfault after inserting
			# 50-100 posts. This can get pretty annoying, especially
			# if you are trying to update your feeds for the first
			# time.

			$result = $wpdb->query("
			INSERT INTO $wpdb->posts
			SET
				guid = '{$dbpost['guid']}',
				post_author = '{$dbpost['post_author']}',
				post_date = '{$dbpost['post_date']}',
				post_date_gmt = '{$dbpost['post_date_gmt']}',
				post_content = '{$dbpost['post_content']}',"
				.(isset($dbpost['post_excerpt']) ? "post_excerpt = '{$dbpost['post_excerpt']}'," : "")."
				post_title = '{$dbpost['post_title']}',
				post_name = '{$dbpost['post_name']}',
				post_modified = '{$dbpost['post_modified']}',
				post_modified_gmt = '{$dbpost['post_modified_gmt']}',
				comment_status = '{$dbpost['comment_status']}',
				ping_status = '{$dbpost['ping_status']}',
				post_status = '{$dbpost['post_status']}'
			");
			$this->_wp_id = $wpdb->insert_id;

			// This should never happen.
			if (!is_numeric($this->_wp_id) or ($this->_wp_id == 0)) :
				FeedWordPress::critical_bug('SyndicatedPost (_wp_id problem)', array("dbpost" => $dbpost, "this" => $this), __LINE__);
			endif;

			// WordPress 1.5.x - 2.0.x
			wp_set_post_cats('1', $this->wp_id(), $this->post['post_category']);
	
			// Since we are not going through official channels, we need to
			// manually tell WordPress that we've published a new post.
			// We need to make sure to do this in order for FeedWordPress
			// to play well  with the staticize-reloaded plugin (something
			// that a large aggregator website is going to *want* to be
			// able to use).
			do_action('publish_post', $this->_wp_id);
		endif;
	} /* SyndicatedPost::insert_new() */

	function update_existing () {
		global $wpdb;

		// Why the fuck doesn't wp_insert_post already do this?
		$dbpost = array();
		foreach ($this->post as $key => $value) :
			if (is_string($value)) :
				$dbpost[$key] = $wpdb->escape($value);
			else :
				$dbpost[$key] = $value;
			endif;
		endforeach;

		if ($this->use_api('wp_insert_post')) :
			$dbpost['post_pingback'] = false; // Tell WP 2.1 and 2.2 not to process for pingbacks

			// This is a ridiculous fucking kludge necessitated by WordPress 2.6 munging authorship meta-data
			add_action('_wp_put_post_revision', array($this, 'fix_revision_meta'));

			// Kludge to prevent kses filters from stripping the
			// content of posts when updating without a logged in
			// user who has `unfiltered_html` capability.
			add_filter('content_save_pre', array($this, 'avoid_kses_munge'), 11);

			$this->_wp_id = wp_insert_post($dbpost);

			// Turn off ridiculous fucking kludges #1 and #2
			remove_action('_wp_put_post_revision', array($this, 'fix_revision_meta'));
			remove_filter('content_save_pre', array($this, 'avoid_kses_munge'), 11);

			// This should never happen.
			if (!is_numeric($this->_wp_id) or ($this->_wp_id == 0)) :
				FeedWordPress::critical_bug('SyndicatedPost (_wp_id problem)', array("dbpost" => $dbpost, "this" => $this), __LINE__);
			endif;

			// Unfortunately, as of WordPress 2.3, wp_insert_post()
			// munges your post modified timestamp.
			$result = $wpdb->query("
				UPDATE $wpdb->posts
				SET
					post_modified='{$dbpost['post_modified']}',
					post_modified_gmt='{$dbpost['post_modified_gmt']}'
				WHERE ID='{$this->_wp_id}'
			");
		else :

			$result = $wpdb->query("
			UPDATE $wpdb->posts
			SET
				post_author = '{$dbpost['post_author']}',
				post_content = '{$dbpost['post_content']}',"
				.(isset($dbpost['post_excerpt']) ? "post_excerpt = '{$dbpost['post_excerpt']}'," : "")."
				post_title = '{$dbpost['post_title']}',
				post_name = '{$dbpost['post_name']}',
				post_modified = '{$dbpost['post_modified']}',
				post_modified_gmt = '{$dbpost['post_modified_gmt']}'
			WHERE guid='{$dbpost['guid']}'
			");
	
			// WordPress 2.1.x and up
			if (function_exists('wp_set_post_categories')) :
				wp_set_post_categories($this->wp_id(), $this->post['post_category']);
			// WordPress 1.5.x - 2.0.x
			elseif (function_exists('wp_set_post_cats')) :
				wp_set_post_cats('1', $this->wp_id(), $this->post['post_category']);
			// This should never happen.
			else :
				FeedWordPress::critical_bug('SyndicatedPost (_wp_id problem)', array("dbpost" => $dbpost, "this" => $this), __LINE__);
			endif;
	
			// Since we are not going through official channels, we need to
			// manually tell WordPress that we've published a new post.
			// We need to make sure to do this in order for FeedWordPress
			// to play well  with the staticize-reloaded plugin (something
			// that a large aggregator website is going to *want* to be
			// able to use).
			do_action('edit_post', $this->post['ID']);
		endif;
	} /* SyndicatedPost::update_existing() */

	/**
	 * SyndicatedPost::fix_revision_meta() - Fixes the way WP 2.6+ fucks up
	 * meta-data (authorship, etc.) when storing revisions of an updated
	 * syndicated post.
	 *
	 * In their infinite wisdom, the WordPress coders have made it completely
	 * impossible for a plugin that uses wp_insert_post() to set certain
	 * meta-data (such as the author) when you store an old revision of an
	 * updated post. Instead, it uses the WordPress defaults (= currently
	 * active user ID if the process is running with a user logged in, or
	 * = #0 if there is no user logged in). This results in bogus authorship
	 * data for revisions that are syndicated from off the feed, unless we
	 * use a ridiculous kludge like this to end-run the munging of meta-data
	 * by _wp_put_post_revision.
	 *
	 * @param int $revision_id The revision ID to fix up meta-data
	 */
	function fix_revision_meta ($revision_id) {
		global $wpdb;
		
		$post_author = (int) $this->post['post_author'];
		
		$revision_id = (int) $revision_id;
		$wpdb->query("
		UPDATE $wpdb->posts
		SET post_author={$this->post['post_author']}
		WHERE post_type = 'revision' AND ID='$revision_id'
		");
	} /* SyndicatedPost::fix_revision_meta () */

	/**
	 * SyndicatedPost::avoid_kses_munge() -- If FeedWordPress is processing
	 * an automatic update, that generally means that wp_insert_post() is
	 * being called under the user credentials of whoever is viewing the
	 * blog at the time -- usually meaning no user at all. But if WordPress
	 * gets a wp_insert_post() when current_user_can('unfiltered_html') is
	 * false, it will run the content of the post through a kses function
	 * that strips out lots of HTML tags -- notably <object> and some others.
	 * This causes problems for syndicating (for example) feeds that contain
	 * YouTube videos. It also produces an unexpected asymmetry between
	 * automatically-initiated updates and updates initiated manually from
	 * the WordPress Dashboard (which are usually initiated under the
	 * credentials of a logged-in admin, and so don't get run through the
	 * kses function). So, to avoid the whole mess, what we do here is
	 * just forcibly disable the kses munging for a single syndicated post,
	 * by restoring the contents of the `post_content` field.
	 *
	 * @param string $content The content of the post, after other filters have gotten to it
	 * @return string The original content of the post, before other filters had a chance to munge it.
	 */
	 function avoid_kses_munge ($content) {
		 global $wpdb;
		 return $wpdb->escape($this->post['post_content']);
	 }
	 
	// SyndicatedPost::add_rss_meta: adds interesting meta-data to each entry
	// using the space for custom keys. The set of keys and values to add is
	// specified by the keys and values of $post['meta']. This is used to
	// store anything that the WordPress user might want to access from a
	// template concerning the post's original source that isn't provided
	// for by standard WP meta-data (i.e., any interesting data about the
	// syndicated post other than author, title, timestamp, categories, and
	// guid). It's also used to hook into WordPress's support for
	// enclosures.
	function add_rss_meta () {
		global $wpdb;
		if ( is_array($this->post) and isset($this->post['meta']) and is_array($this->post['meta']) ) :
			$postId = $this->wp_id();
			
			// Aggregated posts should NOT send out pingbacks.
			// WordPress 2.1-2.2 claim you can tell them not to
			// using $post_pingback, but they don't listen, so we
			// make sure here.
			$result = $wpdb->query("
			DELETE FROM $wpdb->postmeta
			WHERE post_id='$postId' AND meta_key='_pingme'
			");

			foreach ( $this->post['meta'] as $key => $values ) :

				$key = $wpdb->escape($key);

				// If this is an update, clear out the old
				// values to avoid duplication.
				$result = $wpdb->query("
				DELETE FROM $wpdb->postmeta
				WHERE post_id='$postId' AND meta_key='$key'
				");

				// Allow for either a single value or an array
				if (!is_array($values)) $values = array($values);
				foreach ( $values as $value ) :
					$value = $wpdb->escape($value);
					$result = $wpdb->query("
					INSERT INTO $wpdb->postmeta
					SET
						post_id='$postId',
						meta_key='$key',
						meta_value='$value'
					");
				endforeach;
			endforeach;
		endif;
	} /* SyndicatedPost::add_rss_meta () */

	// SyndicatedPost::author_id (): get the ID for an author name from
	// the feed. Create the author if necessary.
	function author_id ($unfamiliar_author = 'create') {
		global $wpdb;

		$a = $this->author();
		$author = $a['name'];
		$email = $a['email'];
		$url = $a['uri'];

		$match_author_by_email = !('yes' == get_option("feedwordpress_do_not_match_author_by_email"));
		if ($match_author_by_email and !FeedWordPress::is_null_email($email)) :
			$test_email = $email;
		else :
			$test_email = NULL;
		endif;

		// Never can be too careful...
		$login = sanitize_user($author, /*strict=*/ true);
		$login = apply_filters('pre_user_login', $login);

		$nice_author = sanitize_title($author);
		$nice_author = apply_filters('pre_user_nicename', $nice_author);

		$reg_author = $wpdb->escape(preg_quote($author));
		$author = $wpdb->escape($author);
		$email = $wpdb->escape($email);
		$test_email = $wpdb->escape($test_email);
		$url = $wpdb->escape($url);

		// Check for an existing author rule....
		if (isset($this->link->settings['map authors']['name'][strtolower(trim($author))])) :
			$author_rule = $this->link->settings['map authors']['name'][strtolower(trim($author))];
		else :
			$author_rule = NULL;
		endif;

		// User name is mapped to a particular author. If that author ID exists, use it.
		if (is_numeric($author_rule) and get_userdata((int) $author_rule)) :
			$id = (int) $author_rule;

		// User name is filtered out
		elseif ('filter' == $author_rule) :
			$id = NULL;
		
		else :
			// Check the database for an existing author record that might fit

			#-- WordPress 2.0+
			if (fwp_test_wp_version(FWP_SCHEMA_HAS_USERMETA)) :

				// First try the user core data table.
				$id = $wpdb->get_var(
				"SELECT ID FROM $wpdb->users
				WHERE
					TRIM(LCASE(user_login)) = TRIM(LCASE('$login'))
					OR (
						LENGTH(TRIM(LCASE(user_email))) > 0
						AND TRIM(LCASE(user_email)) = TRIM(LCASE('$test_email'))
					)
					OR TRIM(LCASE(user_nicename)) = TRIM(LCASE('$nice_author'))
				");
	
				// If that fails, look for aliases in the user meta data table
				if (is_null($id)) :
					$id = $wpdb->get_var(
					"SELECT user_id FROM $wpdb->usermeta
					WHERE
						(meta_key = 'description' AND TRIM(LCASE(meta_value)) = TRIM(LCASE('$author')))
						OR (
							meta_key = 'description'
							AND TRIM(LCASE(meta_value))
							RLIKE CONCAT(
								'(^|\\n)a\\.?k\\.?a\\.?( |\\t)*:?( |\\t)*',
								TRIM(LCASE('$reg_author')),
								'( |\\t|\\r)*(\\n|\$)'
							)
						)
					");
				endif;

			#-- WordPress 1.5.x
			else :
				$id = $wpdb->get_var(
				"SELECT ID from $wpdb->users
				 WHERE
					TRIM(LCASE(user_login)) = TRIM(LCASE('$login')) OR
					(
						LENGTH(TRIM(LCASE(user_email))) > 0
						AND TRIM(LCASE(user_email)) = TRIM(LCASE('$test_email'))
					) OR
					TRIM(LCASE(user_firstname)) = TRIM(LCASE('$author')) OR
					TRIM(LCASE(user_nickname)) = TRIM(LCASE('$author')) OR
					TRIM(LCASE(user_nicename)) = TRIM(LCASE('$nice_author')) OR
					TRIM(LCASE(user_description)) = TRIM(LCASE('$author')) OR
					(
						LOWER(user_description)
						RLIKE CONCAT(
							'(^|\\n)a\\.?k\\.?a\\.?( |\\t)*:?( |\\t)*',
							LCASE('$reg_author'),
							'( |\\t|\\r)*(\\n|\$)'
						)
					)
				");

			endif;

			// ... if you don't find one, then do what you need to do
			if (is_null($id)) :
				if ($unfamiliar_author === 'create') :
					$userdata = array();

					#-- user table data
					$userdata['ID'] = NULL; // new user
					$userdata['user_login'] = $login;
					$userdata['user_nicename'] = $nice_author;
					$userdata['user_pass'] = substr(md5(uniqid(microtime())), 0, 6); // just something random to lock it up
					$userdata['user_email'] = $email;
					$userdata['user_url'] = $url;
					$userdata['display_name'] = $author;
	
					$id = wp_insert_user($userdata);
				elseif (is_numeric($unfamiliar_author) and get_userdata((int) $unfamiliar_author)) :
					$id = (int) $unfamiliar_author;
				elseif ($unfamiliar_author === 'default') :
					$id = 1;
				endif;
			endif;
		endif;

		if ($id) :
			$this->link->settings['map authors']['name'][strtolower(trim($author))] = $id;
		endif;
		return $id;	
	} // function SyndicatedPost::author_id ()

	// look up (and create) category ids from a list of categories
	function category_ids ($cats, $unfamiliar_category = 'create', $tags_too = false) {
		global $wpdb;

		// We need to normalize whitespace because (1) trailing
		// whitespace can cause PHP and MySQL not to see eye to eye on
		// VARCHAR comparisons for some versions of MySQL (cf.
		// <http://dev.mysql.com/doc/mysql/en/char.html>), and (2)
		// because I doubt most people want to make a semantic
		// distinction between 'Computers' and 'Computers  '
		$cats = array_map('trim', $cats);

		$tags = array();

		$cat_ids = array ();
		foreach ($cats as $cat_name) :
			if (preg_match('/^{#([0-9]+)}$/', $cat_name, $backref)) :
				$cat_id = (int) $backref[1];
				if (function_exists('is_term') and is_term($cat_id, 'category')) :
					$cat_ids[] = $cat_id;
				elseif (get_category($cat_id)) :
					$cat_ids[] = $cat_id;
				endif;
			elseif (strlen($cat_name) > 0) :
				$esc = $wpdb->escape($cat_name);
				$resc = $wpdb->escape(preg_quote($cat_name));
				
				// WordPress 2.3+
				if (function_exists('is_term')) :
					$cat_id = is_term($cat_name, 'category');
					if ($cat_id) :
						$cat_ids[] = $cat_id['term_id'];
					// There must be a better way to do this...
					elseif ($results = $wpdb->get_results(
						"SELECT	term_id
						FROM $wpdb->term_taxonomy
						WHERE
							LOWER(description) RLIKE
							CONCAT('(^|\\n)a\\.?k\\.?a\\.?( |\\t)*:?( |\\t)*', LOWER('{$resc}'), '( |\\t|\\r)*(\\n|\$)')"
					)) :
						foreach ($results AS $term) :
							$cat_ids[] = (int) $term->term_id;
						endforeach;
					elseif ('tag'==$unfamiliar_category) :
						$tags[] = $cat_name;
					elseif ('create'===$unfamiliar_category) :
						$term = wp_insert_term($cat_name, 'category');
						if (is_wp_error($term)) :
							FeedWordPress::noncritical_bug('term insertion problem', array('cat_name' => $cat_name, 'term' => $term, 'this' => $this), __LINE__);
						else :
							$cat_ids[] = $term['term_id'];
						endif;
					endif;
				
				// WordPress 1.5.x - 2.2.x
				else :
					$results = $wpdb->get_results(
					"SELECT cat_ID
					FROM $wpdb->categories
					WHERE
					  (LOWER(cat_name) = LOWER('$esc'))
					  OR (LOWER(category_description)
					  RLIKE CONCAT('(^|\\n)a\\.?k\\.?a\\.?( |\\t)*:?( |\\t)*', LOWER('{$resc}'), '( |\\t|\\r)*(\\n|\$)'))
					");
					if ($results) :
						foreach  ($results as $term) :
							$cat_ids[] = (int) $term->cat_ID;
						endforeach;
					elseif ('create'===$unfamiliar_category) :
						if (function_exists('wp_insert_category')) :
							$cat_id = wp_insert_category(array('cat_name' => $cat_name));
						// And into the database we go.
						else :
							$nice_kitty = sanitize_title($cat_name);
							$wpdb->query(sprintf("
								INSERT INTO $wpdb->categories
								SET
								  cat_name='%s',
								  category_nicename='%s'
								", $wpdb->escape($cat_name), $nice_kitty
							));
							$cat_id = $wpdb->insert_id;
						endif;
						$cat_ids[] = $cat_id;
					endif;
				endif;
			endif;
		endforeach;

		if ((count($cat_ids) == 0) and ($unfamiliar_category === 'filter')) :
			$cat_ids = NULL; // Drop the post
		else :
			$cat_ids = array_unique($cat_ids);
		endif;
		
		if ($tags_too) : $ret = array($cat_ids, $tags);
		else : $ret = $cat_ids;
		endif;

		return $ret;
	} // function SyndicatedPost::category_ids ()

	function use_api ($tag) {
		global $wp_db_version;
		switch ($tag) :
		case 'wp_insert_post':
			// Before 2.2, wp_insert_post does too much of the wrong stuff to use it
			// In 1.5 it was such a resource hog it would make PHP segfault on big updates
			$ret = (isset($wp_db_version) and $wp_db_version > FWP_SCHEMA_21);
			break;
		case 'post_status_pending':
			$ret = (isset($wp_db_version) and $wp_db_version > FWP_SCHEMA_23);
			break;
		endswitch;
		return $ret;		
	} // function SyndicatedPost::use_api ()

	#### EXTRACT DATA FROM FEED ITEM ####

	function created () {
		$epoch = null;
		if (isset($this->item['dc']['created'])) :
			$epoch = @parse_w3cdtf($this->item['dc']['created']);
		elseif (isset($this->item['dcterms']['created'])) :
			$epoch = @parse_w3cdtf($this->item['dcterms']['created']);
		elseif (isset($this->item['created'])): // Atom 0.3
			$epoch = @parse_w3cdtf($this->item['created']);
		endif;
		return $epoch;
	}
	function published ($fallback = true) {
		$epoch = null;

		# RSS is a fucking mess. Figure out whether we have a date in
		# <dc:date>, <issued>, <pubDate>, etc., and get it into Unix
		# epoch format for reformatting. If we can't find anything,
		# we'll use the last-updated time.
		if (isset($this->item['dc']['date'])):				// Dublin Core
			$epoch = @parse_w3cdtf($this->item['dc']['date']);
		elseif (isset($this->item['dcterms']['issued'])) :		// Dublin Core extensions
			$epoch = @parse_w3cdtf($this->item['dcterms']['issued']);
		elseif (isset($this->item['published'])) : 			// Atom 1.0
			$epoch = @parse_w3cdtf($this->item['published']);
		elseif (isset($this->item['issued'])): 				// Atom 0.3
			$epoch = @parse_w3cdtf($this->item['issued']);
		elseif (isset($this->item['pubdate'])): 			// RSS 2.0
			$epoch = strtotime($this->item['pubdate']);
		elseif ($fallback) :						// Fall back to <updated> / <modified> if present
			$epoch = $this->updated(/*fallback=*/ false);
		endif;
		
		# If everything failed, then default to the current time.
		if (is_null($epoch)) :
			if (-1 == $default) :
				$epoch = time();
			else :
				$epoch = $default;
			endif;
		endif;
		
		return $epoch;
	}
	function updated ($fallback = true, $default = -1) {
		$epoch = null;

		# As far as I know, only dcterms and Atom have reliable ways to
		# specify when something was *modified* last. If neither is
		# available, then we'll try to get the time of publication.
		if (isset($this->item['dc']['modified'])) : 			// Not really correct
			$epoch = @parse_w3cdtf($this->item['dc']['modified']);
		elseif (isset($this->item['dcterms']['modified'])) :		// Dublin Core extensions
			$epoch = @parse_w3cdtf($this->item['dcterms']['modified']);
		elseif (isset($this->item['modified'])):			// Atom 0.3
			$epoch = @parse_w3cdtf($this->item['modified']);
		elseif (isset($this->item['updated'])):				// Atom 1.0
			$epoch = @parse_w3cdtf($this->item['updated']);
		elseif ($fallback) :						// Fall back to issued / dc:date
			$epoch = $this->published(/*fallback=*/ false, /*default=*/ $default);
		endif;
		
		# If everything failed, then default to the current time.
		if (is_null($epoch)) :
			if (-1 == $default) :
				$epoch = time();
			else :
				$epoch = $default;
			endif;
		endif;

		return $epoch;
	}

	function update_hash () {
		return md5(serialize($this->item));
	}

	function guid () {
		$guid = null;
		if (isset($this->item['id'])): 			// Atom 0.3 / 1.0
			$guid = $this->item['id'];
		elseif (isset($this->item['atom']['id'])) :	// Namespaced Atom
			$guid = $this->item['atom']['id'];
		elseif (isset($this->item['guid'])) :		// RSS 2.0
			$guid = $this->item['guid'];
		elseif (isset($this->item['dc']['identifier'])) :// yeah, right
			$guid = $this->item['dc']['identifier'];
		else :
			// The feed does not seem to have provided us with a
			// unique identifier, so we'll have to cobble together
			// a tag: URI that might work for us. The base of the
			// URI will be the host name of the feed source ...
			$bits = parse_url($this->feedmeta['link/uri']);
			$guid = 'tag:'.$bits['host'];

			// If we have a date of creation, then we can use that
			// to uniquely identify the item. (On the other hand, if
			// the feed producer was consicentious enough to
			// generate dates of creation, she probably also was
			// conscientious enough to generate unique identifiers.)
			if (!is_null($this->created())) :
				$guid .= '://post.'.date('YmdHis', $this->created());
			
			// Otherwise, use both the URI of the item, *and* the
			// item's title. We have to use both because titles are
			// often not unique, and sometimes links aren't unique
			// either (e.g. Bitch (S)HITLIST, Mozilla Dot Org news,
			// some podcasts). But it's rare to have *both* the same
			// title *and* the same link for two different items. So
			// this is about the best we can do.
			else :
				$guid .= '://'.md5($this->item['link'].'/'.$this->item['title']);
			endif;
		endif;
		return $guid;
	}
	
	function author () {
		$author = array ();
		
		if (isset($this->item['author_name'])):
			$author['name'] = $this->item['author_name'];
		elseif (isset($this->item['dc']['creator'])):
			$author['name'] = $this->item['dc']['creator'];
		elseif (isset($this->item['dc']['contributor'])):
			$author['name'] = $this->item['dc']['contributor'];
		elseif (isset($this->feed->channel['dc']['creator'])) :
			$author['name'] = $this->feed->channel['dc']['creator'];
		elseif (isset($this->feed->channel['dc']['contributor'])) :
			$author['name'] = $this->feed->channel['dc']['contributor'];
		elseif (isset($this->feed->channel['author_name'])) :
			$author['name'] = $this->feed->channel['author_name'];
		elseif ($this->feed->is_rss() and isset($this->item['author'])) :
			// The author element in RSS is allegedly an
			// e-mail address, but lots of people don't use
			// it that way. So let's make of it what we can.
			$author = parse_email_with_realname($this->item['author']);
			
			if (!isset($author['name'])) :
				if (isset($author['email'])) :
					$author['name'] = $author['email'];
				else :
					$author['name'] = $this->feed->channel['title'];
				endif;
			endif;
		else :
			$author['name'] = $this->feed->channel['title'];
		endif;
		
		if (isset($this->item['author_email'])):
			$author['email'] = $this->item['author_email'];
		elseif (isset($this->feed->channel['author_email'])) :
			$author['email'] = $this->feed->channel['author_email'];
		endif;
		
		if (isset($this->item['author_url'])):
			$author['uri'] = $this->item['author_url'];
		elseif (isset($this->feed->channel['author_url'])) :
			$author['uri'] = $this->item['author_url'];
		else:
			$author['uri'] = $this->feed->channel['link'];
		endif;

		return $author;
	} // SyndicatedPost::author()
	
	var $strip_attrs = array (
		      array('[a-z]+', 'style'),
		      array('[a-z]+', 'target'),
	);
	function sanitize_content ($content, $obj) {
		# FeedWordPress used to resolve URIs relative to the
		# feed URI. It now relies on the xml:base support
		# baked in to the MagpieRSS upgrade. So all we do here
		# now is to sanitize problematic attributes.
		#
		# This kind of sucks. I intend to replace it with
		# lib_filter sometime soon.
		foreach ($obj->strip_attrs as $pair):
			list($tag,$attr) = $pair;
			$content = preg_replace (
				":(<$tag [^>]*)($attr=(\"[^\">]*\"|[^>\\s]+))([^>]*>):i",
				"\\1\\4",
				$content
			);
		endforeach;
		return $content;
	}
} // class SyndicatedPost

# class SyndicatedLink: represents a syndication feed stored within the
# WordPress database
#
# To keep things compact and editable from within WordPress, we use all the
# links under a particular category in the WordPress "Blogroll" for the list of
# feeds to syndicate. "Contributors" is the category used by default; you can
# configure that under Options --> Syndication.
#
# Fields used are:
#
# *	link_rss: the URI of the Atom/RSS feed to syndicate
#
# *	link_notes: user-configurable options, with keys and values
#	like so:
#
#		key: value
#		cats: computers\nweb
#		feed/key: value
#
#	Keys that start with "feed/" are gleaned from the data supplied
#	by the feed itself, and will be overwritten with each update.
#
#	Values have linebreak characters escaped with C-style
#	backslashes (so, for example, a newline becomes "\n").
#
#	The value of `cats` is used as a newline-separated list of
#	default categories for any post coming from a particular feed. 
#	(In the example above, any posts from this feed will be placed
#	in the "computers" and "web" categories--*in addition to* any
#	categories that may already be applied to the posts.)
#
#	Values of keys in link_notes are accessible from templates using
#	the function `get_feed_meta($key)` if this plugin is activated.

class SyndicatedLink {
	var $id = null;
	var $link = null;
	var $settings = array ();
	var $magpie = null;

	function SyndicatedLink ($link) {
		global $wpdb;

		if (is_object($link)) :
			$this->link = $link;
			$this->id = $link->link_id;
		else :
			$this->id = $link;
			if (function_exists('get_bookmark')) : // WP 2.1+
				$this->link = get_bookmark($link);
			else :
				$this->link = $wpdb->get_row("
				SELECT * FROM $wpdb->links
				WHERE (link_id = '".$wpdb->escape($link)."')"
				);
			endif;
		endif;

		if (strlen($this->link->link_rss) > 0) :
			// Read off feed settings from link_notes
			$notes = explode("\n", $this->link->link_notes);
			foreach ($notes as $note):
				list($key, $value) = explode(": ", $note, 2);
	
				if (strlen($key) > 0) :
					// Unescape and trim() off the whitespace.
					// Thanks to Ray Lischner for pointing out the
					// need to trim off whitespace.
					$this->settings[$key] = stripcslashes (trim($value));
				endif;
			endforeach;

			// "Magic" feed settings
			$this->settings['link/uri'] = $this->link->link_rss;
			$this->settings['link/name'] = $this->link->link_name;
			$this->settings['link/id'] = $this->link->link_id;
			
			// `hardcode categories` and `unfamiliar categories` are deprecated in favor of `unfamiliar category`
			if (
				isset($this->settings['unfamiliar categories'])
				and !isset($this->settings['unfamiliar category'])
			) :
				$this->settings['unfamiliar category'] = $this->settings['unfamiliar categories'];
			endif;
			if (
				FeedWordPress::affirmative($this->settings, 'hardcode categories')
				and !isset($this->settings['unfamiliar category'])
			) :
				$this->settings['unfamiliar category'] = 'default';
			endif;

			// Set this up automagically for del.icio.us
			$bits = parse_url($this->link->link_rss);
			$tagspacers = array('del.icio.us', 'feeds.delicious.com');
			if (!isset($this->settings['cat_split']) and in_array($bits['host'], $tagspacers)) : 
				$this->settings['cat_split'] = '\s'; // Whitespace separates multiple tags in del.icio.us RSS feeds
			endif;

			if (isset($this->settings['cats'])):
				$this->settings['cats'] = preg_split(FEEDWORDPRESS_CAT_SEPARATOR_PATTERN, $this->settings['cats']);
			endif;
			if (isset($this->settings['tags'])):
				$this->settings['tags'] = preg_split(FEEDWORDPRESS_CAT_SEPARATOR_PATTERN, $this->settings['tags']);
			endif;
			
			if (isset($this->settings['map authors'])) :
				$author_rules = explode("\n\n", $this->settings['map authors']);
				$ma = array();
				foreach ($author_rules as $rule) :
					list($rule_type, $author_name, $author_action) = explode("\n", $rule);
					
					// Normalize for case and whitespace
					$rule_type = strtolower(trim($rule_type));
					$author_name = strtolower(trim($author_name));
					$author_action = strtolower(trim($author_action));
					
					$ma[$rule_type][$author_name] = $author_action;
				endforeach;
				$this->settings['map authors'] = $ma;
			endif;
		endif;
	} /* SyndicatedLink::SyndicatedLink () */
	
	function found () {
		return is_object($this->link);
	} /* SyndicatedLink::found () */

	function stale () {
		$stale = true;
		if (isset($this->settings['update/hold']) and ($this->settings['update/hold']=='ping')) :
			$stale = false; // don't update on any timed updates; pings only
		elseif (isset($this->settings['update/hold']) and ($this->settings['update/hold']=='next')) :
			$stale = true; // update on the next timed update
		elseif (!isset($this->settings['update/ttl']) or !isset($this->settings['update/last'])) :
			$stale = true; // initial update
		else :
			$after = ((int) $this->settings['update/last'])
				+((int) $this->settings['update/ttl'] * 60);
			$stale = (time() >= $after);
		endif;
		return $stale;
	} /* SyndicatedLink::stale () */

	function poll ($crash_ts = NULL) {
		global $wpdb;

		$this->magpie = fetch_rss($this->link->link_rss);
		$new_count = NULL;

		$resume = FeedWordPress::affirmative($this->settings, 'update/unfinished');
		if ($resume) :
			// pick up where we left off
			$processed = array_map('trim', explode("\n", $this->settings['update/processed']));
		else :
			// begin at the beginning
			$processed = array();
		endif;

		if (is_object($this->magpie)) :
			$new_count = array('new' => 0, 'updated' => 0);

			# -- Update Link metadata live from feed
			$channel = $this->magpie->channel;

			if (!isset($channel['id'])) :
				$channel['id'] = $this->link->link_rss;
			endif;
	
			$update = array();
			if (!$this->hardcode('url') and isset($channel['link'])) :
				$update[] = "link_url = '".$wpdb->escape($channel['link'])."'";
			endif;
	
			if (!$this->hardcode('name') and isset($channel['title'])) :
				$update[] = "link_name = '".$wpdb->escape($channel['title'])."'";
			endif;
	
			if (!$this->hardcode('description')) :
				if (isset($channel['tagline'])) :
					$update[] = "link_description = '".$wpdb->escape($channel['tagline'])."'";
				elseif (isset($channel['description'])) :
					$update[] = "link_description = '".$wpdb->escape($channel['description'])."'";
				endif;
			endif;
	
			$this->settings = array_merge($this->settings, $this->flatten_array($channel));

			$this->settings['update/last'] = time(); $ttl = $this->ttl();
			if (!is_null($ttl)) :
				$this->settings['update/ttl'] = $ttl;
				$this->settings['update/timed'] = 'feed';
			else :
				$this->settings['update/ttl'] = rand(30, 120); // spread over time interval for staggered updates
				$this->settings['update/timed'] = 'automatically';
			endif;

			if (!isset($this->settings['update/hold']) or $this->settings['update/hold']!='ping') :
				$this->settings['update/hold'] = 'scheduled';
			endif;

			$this->settings['update/unfinished'] = 'yes';

			$update[] = "link_notes = '".$wpdb->escape($this->settings_to_notes())."'";

			$update_set = implode(',', $update);
			
			// Update the properties of the link from the feed information
			$result = $wpdb->query("
				UPDATE $wpdb->links
				SET $update_set
				WHERE link_id='$this->id'
			");

			# -- Add new posts from feed and update any updated posts
			$crashed = false;

			if (is_array($this->magpie->items)) :
				foreach ($this->magpie->items as $item) :
					$post =& new SyndicatedPost($item, $this);
					if (!$resume or !in_array(trim($post->guid()), $processed)) :
						$processed[] = $post->guid();
						if (!$post->filtered()) :
							$new = $post->store();
							if ( $new !== false ) $new_count[$new]++;
						endif;

						if (!is_null($crash_ts) and (time() > $crash_ts)) :
							$crashed = true;
							break;
						endif;
					endif;
				endforeach;
			endif;
			
			// Copy back any changes to feed settings made in the course of updating (e.g. new author rules)
			$to_notes = $this->settings;

			$this->settings['update/processed'] = $processed;
			if (!$crashed) :
				$this->settings['update/unfinished'] = 'no';
			endif;

			$update_set = "link_notes = '".$wpdb->escape($this->settings_to_notes())."'";
			
			// Update the properties of the link from the feed information
			$result = $wpdb->query("
			UPDATE $wpdb->links
			SET $update_set
			WHERE link_id='$this->id'
			");
		endif;
		
		return $new_count;
	} /* SyndicatedLink::poll() */

	function map_name_to_new_user ($name, $newuser_name) {
		global $wpdb;

		if (strlen($newuser_name) > 0) :
			$userdata = array();
			$userdata['ID'] = NULL;
			
			$userdata['user_login'] = sanitize_user($newuser_name);
			$userdata['user_login'] = apply_filters('pre_user_login', $userdata['user_login']);
			
			$userdata['user_nicename'] = sanitize_title($newuser_name);
			$userdata['user_nicename'] = apply_filters('pre_user_nicename', $userdata['user_nicename']);
			
			$userdata['display_name'] = $wpdb->escape($newuser_name);
		
			$newuser_id = wp_insert_user($userdata);
			if (is_numeric($newuser_id)) :
				if (is_null($name)) : // Unfamiliar author
					$this->settings['unfamiliar author'] = $newuser_id;
				else :
					$this->settings['map authors']['name'][$name] = $newuser_id;
				endif;
			else :
				// TODO: Add some error detection and reporting
			endif;
		else :
			// TODO: Add some error reporting
		endif;
	} /* SyndicatedLink::map_name_to_new_user () */

	function settings_to_notes () {
		$to_notes = $this->settings;

		unset($to_notes['link/id']); // Magic setting; don't save
		unset($to_notes['link/uri']); // Magic setting; don't save
		unset($to_notes['link/name']); // Magic setting; don't save
		unset($to_notes['hardcode categories']); // Deprecated
		unset($to_notes['unfamiliar categories']); // Deprecated

		// Collapse array settings
		if (isset($to_notes['update/processed']) and (is_array($to_notes['update/processed']))) :
			$to_notes['update/processed'] = implode("\n", $to_notes['update/processed']);
		endif;

		if (is_array($to_notes['cats'])) :
			$to_notes['cats'] = implode(FEEDWORDPRESS_CAT_SEPARATOR, $to_notes['cats']);
		endif;
		if (is_array($to_notes['tags'])) :
			$to_notes['tags'] = implode(FEEDWORDPRESS_CAT_SEPARATOR, $to_notes['tags']);
		endif;

		// Collapse the author mapping rule structure back into a flat string
		if (isset($to_notes['map authors'])) :
			$ma = array();
			foreach ($to_notes['map authors'] as $rule_type => $author_rules) :
				foreach ($author_rules as $author_name => $author_action) :
					$ma[] = $rule_type."\n".$author_name."\n".$author_action;
				endforeach;
			endforeach;
			$to_notes['map authors'] = implode("\n\n", $ma);
		endif;

		$notes = '';
		foreach ($to_notes as $key => $value) :
			$notes .= $key . ": ". addcslashes($value, "\0..\37".'\\') . "\n";
		endforeach;
		return $notes;
	} /* SyndicatedLink::settings_to_notes () */

	function uri () {
		return (is_object($this->link) ? $this->link->link_rss : NULL);
	} /* SyndicatedLink::uri () */

	function homepage () {
		return (isset($this->settings['feed/link']) ? $this->settings['feed/link'] : NULL);
	} /* SyndicatedLink::homepage () */

	function ttl () {
		if (is_object($this->magpie)) :
			$channel = $this->magpie->channel;
		else :
			$channel = array();
		endif;

		if (isset($channel['ttl'])) :
			// "ttl stands for time to live. It's a number of
			// minutes that indicates how long a channel can be
			// cached before refreshing from the source."
			// <http://blogs.law.harvard.edu/tech/rss#ltttlgtSubelementOfLtchannelgt>	
			$ret = $channel['ttl'];
		elseif (isset($channel['sy']['updatefrequency']) or isset($channel['sy']['updateperiod'])) :
			$period_minutes = array (
				'hourly' => 60, /* minutes in an hour */
				'daily' => 1440, /* minutes in a day */
				'weekly' => 10080, /* minutes in a week */
				'monthly' => 43200, /* minutes in  a month */
				'yearly' => 525600, /* minutes in a year */
			);

			// "sy:updatePeriod: Describes the period over which the
			// channel format is updated. Acceptable values are:
			// hourly, daily, weekly, monthly, yearly. If omitted,
			// daily is assumed." <http://web.resource.org/rss/1.0/modules/syndication/>
			if (isset($channel['sy']['updateperiod'])) : $period = $channel['sy']['updateperiod'];
			else : $period = 'daily';
			endif;
			
			// "sy:updateFrequency: Used to describe the frequency 
			// of updates in relation to the update period. A
			// positive integer indicates how many times in that
			// period the channel is updated. ... If omitted a value
			// of 1 is assumed." <http://web.resource.org/rss/1.0/modules/syndication/>
			if (isset($channel['sy']['updatefrequency'])) : $freq = (int) $channel['sy']['updatefrequency'];
			else : $freq = 1;
			endif;
			
			$ret = (int) ($period_minutes[$period] / $freq);
		else :
			$ret = NULL;
		endif;
		return $ret;
	} /* SyndicatedLink::ttl() */

	// SyndicatedLink::flatten_array (): flatten an array. Useful for
	// hierarchical and namespaced elements.
	//
	// Given an array which may contain array or object elements in it,
	// return a "flattened" array: a one-dimensional array of scalars
	// containing each of the scalar elements contained within the array
	// structure. Thus, for example, if $a['b']['c']['d'] == 'e', then the
	// returned array for FeedWordPress::flatten_array($a) will contain a key
	// $a['feed/b/c/d'] with value 'e'.
	function flatten_array ($arr, $prefix = 'feed/', $separator = '/') {
		$ret = array ();
		if (is_array($arr)) :
			foreach ($arr as $key => $value) :
				if (is_scalar($value)) :
					$ret[$prefix.$key] = $value;
				else :
					$ret = array_merge($ret, $this->flatten_array($value, $prefix.$key.$separator, $separator));
				endif;
			endforeach;
		endif;
		return $ret;
	} /* SyndicatedLink::flatten_array () */

	function hardcode ($what) {
		$default = get_option("feedwordpress_hardcode_$what");
		if ( $default === 'yes' ) :
			// If the default is to hardcode, then we want the
			// negation of negative(): TRUE by default and FALSE if
			// the setting is explicitly "no"
			$ret = !FeedWordPress::negative($this->settings, "hardcode $what");
		else :
			// If the default is NOT to hardcode, then we want
			// affirmative(): FALSE by default and TRUE if the
			// setting is explicitly "yes"
			$ret = FeedWordPress::affirmative($this->settings, "hardcode $what");
		endif;
		return $ret;
	} /* SyndicatedLink::hardcode () */

	function syndicated_status ($what, $default) {
		global $wpdb;

		$ret = get_option("feedwordpress_syndicated_{$what}_status");
		if ( isset($this->settings["$what status"]) ) :
			$ret = $this->settings["$what status"];
		elseif (!$ret) :
			$ret = $default;
		endif;
		return $wpdb->escape(trim(strtolower($ret)));
	} /* SyndicatedLink:syndicated_status () */
} // class SyndicatedLink

################################################################################
## XML-RPC HOOKS: accept XML-RPC update pings from Contributors ################
################################################################################

function feedwordpress_xmlrpc_hook ($args = array ()) {
	$args['weblogUpdates.ping'] = 'feedwordpress_pong';
	return $args;
}

function feedwordpress_pong ($args) {
	$feedwordpress =& new FeedWordPress;
	$delta = @$feedwordpress->update($args[1]);
	if (is_null($delta)):
		return array('flerror' => true, 'message' => "Sorry. I don't syndicate <$args[1]>.");
	else:
		$mesg = array();
		if (isset($delta['new'])) { $mesg[] = ' '.$delta['new'].' new posts were syndicated'; }
		if (isset($delta['updated'])) { $mesg[] = ' '.$delta['updated'].' existing posts were updated'; }

		return array('flerror' => false, 'message' => "Thanks for the ping.".implode(' and', $mesg));
	endif;
}

################################################################################
## class FeedFinder: find likely feeds using autodetection and/or guesswork ####
################################################################################

class FeedFinder {
	var $uri = NULL;
	var $_cache_uri = NULL;
	
	var $verify = FALSE;
	
	var $_data = NULL;
	var $_error = NULL;
	var $_head = NULL;

	# -- Recognition patterns
	var $_feed_types = array(
		'application/rss+xml',
		'text/xml',
		'application/atom+xml',
		'application/x.atom+xml',
                'application/x-atom+xml'
	);
	var $_feed_markers = array('\\<feed', '\\<rss', 'xmlns="http://purl.org/rss/1.0');
	var $_html_markers = array('\\<html');
	var $_obvious_feed_url = array('[./]rss', '[./]rdf', '[./]atom', '[./]feed', '\.xml');
	var $_maybe_feed_url = array ('rss', 'rdf', 'atom', 'feed', 'xml');

	function FeedFinder ($uri = NULL, $verify = TRUE) {
		$this->uri = $uri; $this->verify = $verify;
	} /* FeedFinder::FeedFinder () */

	function find ($uri = NULL) {
		$ret = array ();
		if (!is_null($this->data($uri))) {
			if ($this->is_feed($uri)) {
				$ret = array($this->uri);
			} else {
				// Assume that we have HTML or XHTML (even if we don't, who's it gonna hurt?)
				// Autodiscovery is the preferred method
				$href = $this->_link_rel_feeds();
				
				// ... but we'll also take the little orange buttons
				$href = array_merge($href, $this->_a_href_feeds(TRUE));
				
				// If all that failed, look harder
				if (count($href) == 0) $href = $this->_a_href_feeds(FALSE);

				// Verify feeds and resolve relative URIs
				foreach ($href as $u) {
					$the_uri = Relative_URI::resolve($u, $this->uri);
					if ($this->verify) {
						$feed =& new FeedFinder($the_uri);
						if ($feed->is_feed()) $ret[] = $the_uri;
						$feed = NULL;
					} else {
						$ret[] = $the_uri;
					}
				} /* foreach */
			} /* if */
		} /* if */
		return array_unique($ret);
	} /* FeedFinder::find () */

	function data ($uri = NULL) {
		$this->_get($uri);
		return $this->_data;
	}

	function error () {
		return $this->_error;
	}
	
	function is_feed ($uri = NULL) {
		$data = $this->data($uri);

		return (
			preg_match (
				"\007(".implode('|',$this->_feed_markers).")\007i",
				$data
			) and !preg_match (
				"\007(".implode('|',$this->_html_markers).")\007i",
				$data
			)
		);
	} /* FeedFinder::is_feed () */

	# --- Private methods ---
	function _get ($uri = NULL) {
		if ($uri) $this->uri = $uri;

		// Is the result not yet cached?
		if ($this->_cache_uri !== $this->uri) :
			$headers['Connection'] = 'close';
			$headers['Accept'] = 'application/atom+xml application/rdf+xml application/rss+xml application/xml text/html */*';
			$headers['User-Agent'] = 'feedfinder/1.2 (compatible; PHP FeedFinder) +http://projects.radgeek.com/feedwordpress';

			// Use function provided by MagpieRSS package
			$client = _fetch_remote_file($this->uri, $headers);
			if (isset($client->error)) :
				$this->_error = $client->error;
			else :
				$this->_error = NULL;
			endif;
			$this->_data = $client->results;

			// Kilroy was here
			$this->_cache_uri = $this->uri;
		endif;
	} /* FeedFinder::_get () */

 	function _link_rel_feeds () {
		$links = $this->_tags('link');
		$link_count = count($links);

		// now figure out which one points to the RSS file
		$href = array ();
		for ($n=0; $n<$link_count; $n++) {
			if (strtolower($links[$n]['rel']) == 'alternate') {
				if (in_array(strtolower($links[$n]['type']), $this->_feed_types)) {
					$href[] = $links[$n]['href'];
				} /* if */
			} /* if */
		} /* for */
		return $href;
	}

	function _a_href_feeds ($obvious = TRUE) {
		$pattern = ($obvious ? $this->_obvious_feed_url : $this->_maybe_feed_url);

		$links = $this->_tags('a');
		$link_count = count($links);

		// now figure out which one points to the RSS file
		$href = array ();
		for ($n=0; $n<$link_count; $n++) {
			if (preg_match("\007(".implode('|',$pattern).")\007i", $links[$n]['href'])) {
				$href[] = $links[$n]['href'];
			} /* if */
		} /* for */
		return $href;
	}

	function _tags ($tag) {
		$html = $this->data();
    
		// search through the HTML, save all <link> tags
		// and store each link's attributes in an associative array
		preg_match_all('/<'.$tag.'\s+(.*?)\s*\/?>/si', $html, $matches);
		$links = $matches[1];
		$ret = array();
		$link_count = count($links);
		for ($n=0; $n<$link_count; $n++) {
			$attributes = preg_split('/\s+/s', $links[$n]);
			foreach($attributes as $attribute) {
				$att = preg_split('/\s*=\s*/s', $attribute, 2);
				if (isset($att[1])) {
					$att[1] = preg_replace('/([\'"]?)(.*)\1/', '$2', $att[1]);
					$final_link[strtolower($att[0])] = $att[1];
				} /* if */
			} /* foreach */
			$ret[$n] = $final_link;
		} /* for */
		return $ret;
	}
} /* class FeedFinder */

# Relative URI static class: PHP class for resolving relative URLs
#
# This class is derived (under the terms of the GPL) from URL Class 0.3 by
# Keyvan Minoukadeh <keyvan@k1m.com>, which is great but more than we need
# for FeedWordPress's purposes. The class has been stripped down to a single
# public method: Relative_URI::resolve($url, $base), which resolves the URI in
# $url relative to the URI in $base
#
# The upgraded MagpieRSS also uses this class. So if we have it loaded
# in, don't load it again.
if (!class_exists('Relative_URI')) {

	class Relative_URI
	{
		// Resolve relative URI in $url against the base URI in $base. If $base
		// is not supplied, then we use the REQUEST_URI of this script.
		//
		// I'm hoping this method reflects RFC 2396 Section 5.2
		function resolve ($url, $base = NULL)
		{
			if (is_null($base)):
				$base = 'http://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
			endif;
	
			$base = Relative_URI::_encode(trim($base));
			$uri_parts = Relative_URI::_parse_url($base);
	
			$url = Relative_URI::_encode(trim($url));
			$parts = Relative_URI::_parse_url($url);
	
			$uri_parts['fragment'] = (isset($parts['fragment']) ? $parts['fragment'] : null);
			$uri_parts['query'] = (isset($parts['query']) ? $parts['query'] : null);
	
			// if path is empty, and scheme, host, and query are undefined,
			// the URL is referring the base URL
			
			if (($parts['path'] == '') && !isset($parts['scheme']) && !isset($parts['host']) && !isset($parts['query'])) {
				// If the URI is empty or only a fragment, return the base URI
				return $base . (isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
			} elseif (isset($parts['scheme'])) {
				// If the scheme is set, then the URI is absolute.
				return $url;
			} elseif (isset($parts['host'])) {
				$uri_parts['host'] = $parts['host'];
				$uri_parts['path'] = $parts['path'];
			} else {
				// We have a relative path but not a host.
	
				// start ugly fix:
				// prepend slash to path if base host is set, base path is not set, and url path is not absolute
				if ($uri_parts['host'] && ($uri_parts['path'] == '')
				&& (strlen($parts['path']) > 0)
				&& (substr($parts['path'], 0, 1) != '/')) {
					$parts['path'] = '/'.$parts['path'];
				} // end ugly fix
				
				if (substr($parts['path'], 0, 1) == '/') {
					$uri_parts['path'] = $parts['path'];
				} else {
					// copy base path excluding any characters after the last (right-most) slash character
					$buffer = substr($uri_parts['path'], 0, (int)strrpos($uri_parts['path'], '/')+1);
					// append relative path
					$buffer .= $parts['path'];
					// remove "./" where "." is a complete path segment.
					$buffer = str_replace('/./', '/', $buffer);
					if (substr($buffer, 0, 2) == './') {
					    $buffer = substr($buffer, 2);
					}
					// if buffer ends with "." as a complete path segment, remove it
					if (substr($buffer, -2) == '/.') {
					    $buffer = substr($buffer, 0, -1);
					}
					// remove "<segment>/../" where <segment> is a complete path segment not equal to ".."
					$search_finished = false;
					$segment = explode('/', $buffer);
					while (!$search_finished) {
					    for ($x=0; $x+1 < count($segment);) {
						if (($segment[$x] != '') && ($segment[$x] != '..') && ($segment[$x+1] == '..')) {
						    if ($x+2 == count($segment)) $segment[] = '';
						    unset($segment[$x], $segment[$x+1]);
						    $segment = array_values($segment);
						    continue 2;
						} else {
						    $x++;
						}
					    }
					    $search_finished = true;
					}
					$buffer = (count($segment) == 1) ? '/' : implode('/', $segment);
					$uri_parts['path'] = $buffer;
	
				}
			}
	
			// If we've gotten to this point, we can try to put the pieces
			// back together.
			$ret = '';
			if (isset($uri_parts['scheme'])) $ret .= $uri_parts['scheme'].':';
			if (isset($uri_parts['user'])) {
				$ret .= $uri_parts['user'];
				if (isset($uri_parts['pass'])) $ret .= ':'.$uri_parts['parts'];
				$ret .= '@';
			}
			if (isset($uri_parts['host'])) {
				$ret .= '//'.$uri_parts['host'];
				if (isset($uri_parts['port'])) $ret .= ':'.$uri_parts['port'];
			}
			$ret .= $uri_parts['path'];
			if (isset($uri_parts['query'])) $ret .= '?'.$uri_parts['query'];
			if (isset($uri_parts['fragment'])) $ret .= '#'.$uri_parts['fragment'];
	
			return $ret;
	    }
	
	    /**
	    * Parse URL
	    *
	    * Regular expression grabbed from RFC 2396 Appendix B. 
	    * This is a replacement for PHPs builtin parse_url().
	    * @param string $url
	    * @access private
	    * @return array
	    */
	    function _parse_url($url)
	    {
		// I'm using this pattern instead of parse_url() as there's a few strings where parse_url() 
		// generates a warning.
		if (preg_match('!^(([^:/?#]+):)?(//([^/?#]*))?([^?#]*)(\?([^#]*))?(#(.*))?!', $url, $match)) {
		    $parts = array();
		    if ($match[1] != '') $parts['scheme'] = $match[2];
		    if ($match[3] != '') $parts['auth'] = $match[4];
		    // parse auth
		    if (isset($parts['auth'])) {
			// store user info
			if (($at_pos = strpos($parts['auth'], '@')) !== false) {
			    $userinfo = explode(':', substr($parts['auth'], 0, $at_pos), 2);
			    $parts['user'] = $userinfo[0];
			    if (isset($userinfo[1])) $parts['pass'] = $userinfo[1];
			    $parts['auth'] = substr($parts['auth'], $at_pos+1);
			}
			// get port number
			if ($port_pos = strrpos($parts['auth'], ':')) {
			    $parts['host'] = substr($parts['auth'], 0, $port_pos);
			    $parts['port'] = (int)substr($parts['auth'], $port_pos+1);
			    if ($parts['port'] < 1) $parts['port'] = null;
			} else {
			    $parts['host'] = $parts['auth'];
			}
		    }
		    unset($parts['auth']);
		    $parts['path'] = $match[5];
		    if (isset($match[6]) && ($match[6] != '')) $parts['query'] = $match[7];
		    if (isset($match[8]) && ($match[8] != '')) $parts['fragment'] = $match[9];
		    return $parts;
		}
		// shouldn't reach here
		return array('path'=>'');
	    }
	
	    function _encode($string)
	    {
		static $replace = array();
		if (!count($replace)) {
		    $find = array(32, 34, 60, 62, 123, 124, 125, 91, 92, 93, 94, 96, 127);
		    $find = array_merge(range(0, 31), $find);
		    $find = array_map('chr', $find);
		    foreach ($find as $char) {
			$replace[$char] = '%'.bin2hex($char);
		    }
		}
		// escape control characters and a few other characters
		$encoded = strtr($string, $replace);
		// remove any character outside the hex range: 21 - 7E (see www.asciitable.com)
		return preg_replace('/[^\x21-\x7e]/', '', $encoded);
	    }
	} // class Relative_URI
}

// take your best guess at the realname and e-mail, given a string
define('FWP_REGEX_EMAIL_ADDY', '([^@"(<\s]+@[^"@(<\s]+\.[^"@(<\s]+)');
define('FWP_REGEX_EMAIL_NAME', '("([^"]*)"|([^"<(]+\S))');
define('FWP_REGEX_EMAIL_POSTFIX_NAME', '/^\s*'.FWP_REGEX_EMAIL_ADDY."\s+\(".FWP_REGEX_EMAIL_NAME.'\)\s*$/');
define('FWP_REGEX_EMAIL_PREFIX_NAME', '/^\s*'.FWP_REGEX_EMAIL_NAME.'\s*<'.FWP_REGEX_EMAIL_ADDY.'>\s*$/');
define('FWP_REGEX_EMAIL_JUST_ADDY', '/^\s*'.FWP_REGEX_EMAIL_ADDY.'\s*$/');
define('FWP_REGEX_EMAIL_JUST_NAME', '/^\s*'.FWP_REGEX_EMAIL_NAME.'\s*$/');

function parse_email_with_realname ($email) {
	if (preg_match(FWP_REGEX_EMAIL_POSTFIX_NAME, $email, $matches)) :
		($ret['name'] = $matches[3]) or ($ret['name'] = $matches[2]);
		$ret['email'] = $matches[1];
	elseif (preg_match(FWP_REGEX_EMAIL_PREFIX_NAME, $email, $matches)) :
		($ret['name'] = $matches[2]) or ($ret['name'] = $matches[3]);
		$ret['email'] = $matches[4];
	elseif (preg_match(FWP_REGEX_EMAIL_JUST_ADDY, $email, $matches)) :
		$ret['name'] = NULL; $ret['email'] = $matches[1];
	elseif (preg_match(FWP_REGEX_EMAIL_JUST_NAME, $email, $matches)) :
		$ret['email'] = NULL;
		($ret['name'] = $matches[2]) or ($ret['name'] = $matches[3]);
	else :
		$ret['name'] = NULL; $ret['email'] = NULL;
	endif;
	return $ret;
}

