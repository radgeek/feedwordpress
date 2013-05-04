<?php
/*
Plugin Name: FeedWordPress
Plugin URI: http://feedwordpress.radgeek.com/
Description: simple and flexible Atom/RSS syndication for WordPress
Version: 2013.0504
Author: Charles Johnson
Author URI: http://radgeek.com/
License: GPL
*/

/**
 * @package FeedWordPress
 * @version 2013.0504
 */

# This uses code derived from:
# -	wp-rss-aggregate.php by Kellan Elliot-McCrea <kellan@protest.net>
# -	SimplePie feed parser by Ryan Parman, Geoffrey Sneddon, Ryan McCue, et al.
# -	MagpieRSS feed parser by Kellan Elliot-McCrea <kellan@protest.net>
# -	Ultra-Liberal Feed Finder by Mark Pilgrim <mark@diveintomark.org>
# -	WordPress Blog Tool and Publishing Platform <http://wordpress.org/>
# according to the terms of the GNU General Public License.
#
# INSTALLATION: see readme.txt or <http://feedwordpress.radgeek.com/install>
#
# USAGE: once FeedWordPress is installed, you manage just about everything from
# the WordPress Dashboard, under the Syndication menu. To keep fresh content
# coming in as it becomes available, you'll have to either check for updates
# manually, or set up one of the automatically-scheduled update methods. See 
# <http://feedwordpress.radgeek.com/wiki/quick-start/> for some details.

# -- Don't change these unless you know what you're doing...

define ('FEEDWORDPRESS_VERSION', '2013.0504');
define ('FEEDWORDPRESS_AUTHOR_CONTACT', 'http://radgeek.com/contact');

if (!defined('FEEDWORDPRESS_BLEG')) :
	define ('FEEDWORDPRESS_BLEG', true);
endif;

// Defaults
define ('DEFAULT_SYNDICATION_CATEGORY', 'Contributors');
define ('DEFAULT_UPDATE_PERIOD', 60); // value in minutes

if (isset($_REQUEST['feedwordpress_debug'])) :
	$feedwordpress_debug = $_REQUEST['feedwordpress_debug'];
else :
	$feedwordpress_debug = get_option('feedwordpress_debug');
	if (is_string($feedwordpress_debug)) :
		$feedwordpress_debug = ($feedwordpress_debug == 'yes');
	endif;
endif;
define ('FEEDWORDPRESS_DEBUG', $feedwordpress_debug);
$feedwordpress_compatibility = true;
define ('FEEDWORDPRESS_COMPATIBILITY', $feedwordpress_compatibility);

define ('FEEDWORDPRESS_CAT_SEPARATOR_PATTERN', '/[:\n]/');
define ('FEEDWORDPRESS_CAT_SEPARATOR', "\n");

define ('FEEDVALIDATOR_URI', 'http://feedvalidator.org/check.cgi');

define ('FEEDWORDPRESS_FRESHNESS_INTERVAL', 10*60); // Every ten minutes

define ('FWP_SCHEMA_HAS_USERMETA', 2966);
define ('FWP_SCHEMA_USES_ARGS_TAXONOMY', 12694); // Revision # for using $args['taxonomy'] to get link categories
define ('FWP_SCHEMA_30', 12694); // Database schema # for WP 3.0

if (FEEDWORDPRESS_DEBUG) :
	// Help us to pick out errors, if any.
	ini_set('error_reporting', E_ALL & ~E_NOTICE);
	ini_set('display_errors', true);
	
	 // When testing we don't want cache issues to interfere. But this is
	 // a VERY BAD SETTING for a production server. Webmasters will eat your 
	 // face for breakfast if you use it, and the baby Jesus will cry. So
	 // make sure FEEDWORDPRESS_DEBUG is FALSE for any site that will be
	 // used for more than testing purposes!
	define('FEEDWORDPRESS_CACHE_AGE', 1);
	define('FEEDWORDPRESS_CACHE_LIFETIME', 1);
	define('FEEDWORDPRESS_FETCH_TIMEOUT_DEFAULT', 60);
else :
	// Hold onto data all day for conditional GET purposes,
	// but consider it stale after 1 min (requiring a conditional GET)
	define('FEEDWORDPRESS_CACHE_LIFETIME', 24*60*60);
	define('FEEDWORDPRESS_CACHE_AGE', 1*60);
	define('FEEDWORDPRESS_FETCH_TIMEOUT_DEFAULT', 20);
endif;

// Use our the cache settings that we want.
add_filter('wp_feed_cache_transient_lifetime', array('FeedWordPress', 'cache_lifetime'));

// Ensure that we have SimplePie loaded up and ready to go.
// We no longer need a MagpieRSS upgrade module. Hallelujah!
if (!class_exists('SimplePie')) :
	require_once(ABSPATH . WPINC . '/class-simplepie.php');
endif;
require_once(ABSPATH . WPINC . '/class-feed.php');

if (!function_exists('wp_insert_user')) :
	require_once (ABSPATH . WPINC . '/registration.php'); // for wp_insert_user
endif;

$dir = dirname(__FILE__); 
require_once("${dir}/externals/myphp/myphp.class.php");
require_once("${dir}/admin-ui.php");
require_once("${dir}/feedwordpresssyndicationpage.class.php");
require_once("${dir}/compatability.php"); // Legacy API             
require_once("${dir}/syndicatedpost.class.php");
require_once("${dir}/syndicatedlink.class.php");
require_once("${dir}/feedwordpresshtml.class.php");
require_once("${dir}/feedwordpress-content-type-sniffer.class.php");
require_once("${dir}/inspectpostmeta.class.php");
require_once("${dir}/syndicationdataqueries.class.php");
require_once("${dir}/feedwordpie.class.php");
require_once("${dir}/feedwordpie_item.class.php");
require_once("${dir}/feedwordpress_file.class.php");
require_once("${dir}/feedwordpress_parser.class.php");
require_once("${dir}/feedwordpressrpc.class.php");
require_once("${dir}/feedwordpresshttpauthenticator.class.php");
require_once("${dir}/feedwordpresslocalpost.class.php");

// Magic quotes are just about the stupidest thing ever.
if (is_array($_POST)) :
	$fwp_post = $_POST;
	if (get_magic_quotes_gpc()) :
		$fwp_post = stripslashes_deep($fwp_post);
	endif;
endif;

// Get the path relative to the plugins directory in which FWP is stored
preg_match (
	'|'.preg_quote(WP_PLUGIN_DIR).'/(.+)$|',
	dirname(__FILE__),
	$ref
);

if (isset($ref[1])) :
	$fwp_path = $ref[1];
else : // Something went wrong. Let's just guess.
	$fwp_path = 'feedwordpress';
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
	add_filter('post_link', 'syndication_permalink', /*priority=*/ 1, /*arguments=*/ 3);
	add_filter('post_type_link', 'syndication_permalink', /*priority=*/ 1, /*arguments=*/ 4);
	
	# When foreign URLs are used for permalinks in feeds or display
	# contexts, they need to be escaped properly.
	add_filter('the_permalink', 'syndication_permalink_escaped');
	add_filter('the_permalink_rss', 'syndication_permalink_escaped');
	
	add_filter('post_comments_feed_link', 'syndication_comments_feed_link');

	# WTF? By default, wp_insert_link runs incoming link_url and link_rss
	# URIs through default filters that include `wp_kses()`. But `wp_kses()`
	# just happens to escape any occurrence of & to &amp; -- which just
	# happens to fuck up any URI with a & to separate GET parameters.
	remove_filter('pre_link_rss', 'wp_filter_kses');
	remove_filter('pre_link_url', 'wp_filter_kses');
	
	# Admin menu
	add_action('admin_init', array('feedwordpress', 'admin_init'));
	add_action('admin_menu', 'fwp_add_pages');
	add_action('admin_notices', 'fwp_check_debug');

	add_action('admin_menu', 'feedwordpress_add_post_edit_controls');
	add_action('save_post', 'feedwordpress_save_post_edit_controls');

	add_action('admin_footer', array('FeedWordPress', 'admin_footer'));
	
	# Inbound XML-RPC update methods
	$feedwordpressRPC = new FeedWordPressRPC;

	# Outbound XML-RPC ping reform
	remove_action('publish_post', 'generic_ping'); // WP 1.5.x
	remove_action('do_pings', 'do_all_pings', 10, 1); // WP 2.1, 2.2
	remove_action('publish_post', '_publish_post_hook', 5, 1); // WP 2.3

	add_action('publish_post', 'fwp_publish_post_hook', 5, 1);
	add_action('do_pings', 'fwp_do_pings', 10, 1);
	add_action('feedwordpress_update', 'fwp_hold_pings');
	add_action('feedwordpress_update_complete', 'fwp_release_pings');

	add_action('syndicated_feed_error', array('FeedWordPressDiagnostic', 'feed_error'), 100, 3);

	add_action('wp_footer', 'debug_out_feedwordpress_footer', -100);
	add_action('admin_footer', 'debug_out_feedwordpress_footer', -100);
	
	$feedwordpress = new FeedWordPress;

	# Cron-less auto-update. Hooray!
	$autoUpdateHook = $feedwordpress->automatic_update_hook();
	if (!is_null($autoUpdateHook)) :
		add_action($autoUpdateHook, array($feedwordpress, 'auto_update'));
	endif;
	
	add_action('init', array($feedwordpress, 'init'));
	add_action('shutdown', array($feedwordpress, 'email_diagnostic_log'));
	add_action('wp_dashboard_setup', array($feedwordpress, 'dashboard_setup'));

	# Default sanitizers
	add_filter('syndicated_item_content', array('SyndicatedPost', 'resolve_relative_uris'), 0, 2);
	add_filter('syndicated_item_content', array('SyndicatedPost', 'sanitize_content'), 0, 2);

	add_action('plugins_loaded', array('FeedWordPress', 'admin_api'));
else :
	# Hook in the menus, which will just point to the upgrade interface
	add_action('admin_menu', 'fwp_add_pages');
endif; // if (!FeedWordPress::needs_upgrade())

################################################################################
## LOGGING FUNCTIONS: log status updates to error_log if you want it ###########
################################################################################

class FeedWordPressDiagnostic {
	function feed_error ($error, $old, $link) {
		$wpError = $error['object'];
		$url = $link->uri();
		
		$mesgs = $wpError->get_error_messages();
		foreach ($mesgs as $mesg) :
			$mesg = esc_html($mesg);
			FeedWordPress::diagnostic(
				'updated_feeds:errors',
				"Feed Error: [${url}] update returned error: $mesg"
			);
			
			$hours = get_option('feedwordpress_diagnostics_persistent_errors_hours', 2);
			$span = ($error['ts'] - $error['since']);

			if ($span >= ($hours * 60 * 60)) :
				$since = date('r', $error['since']);
				$mostRecent = date('r', $error['ts']);
				FeedWordPress::diagnostic(
					'updated_feeds:errors:persistent',
					"Feed Update Error: [${url}] returning errors"
					." since ${since}:<br/><code>$mesg</code>",
					$url, $error['since'], $error['ts']
				);
			endif;
		endforeach;
	}

	function admin_emails ($id = '') {
		$users = get_users_of_blog($id);
		$recipients = array();
		foreach ($users as $user) :
			$user_id = (isset($user->user_id) ? $user->user_id : $user->ID);
			$dude = new WP_User($user_id);
			if ($dude->has_cap('administrator')) :
				if ($dude->user_email) :
					$recipients[] = $dude->user_email;
				endif;
			endif;
		endforeach;
		return $recipients;
	}
} /* class FeedWordPressDiagnostic */

function debug_out_human_readable_bytes ($quantity) {
	$quantity = (int) $quantity;
	$magnitude = 'B';
	$orders = array('KB', 'MB', 'GB', 'TB');
	while (($quantity > (1024*100)) and (count($orders) > 0)) :
		$quantity = floor($quantity / 1024);
		$magnitude = array_shift($orders);
	endwhile;
	return "${quantity} ${magnitude}";
}

function debug_out_feedwordpress_footer () {
	if (FeedWordPress::diagnostic_on('memory_usage')) :
		if (function_exists('memory_get_usage')) :
			FeedWordPress::diagnostic ('memory_usage', "Memory: Current usage: ".debug_out_human_readable_bytes(memory_get_usage()));
		endif;
		if (function_exists('memory_get_peak_usage')) :
			FeedWordPress::diagnostic ('memory_usage', "Memory: Peak usage: ".debug_out_human_readable_bytes(memory_get_peak_usage()));
		endif;
	endif;
} /* debug_out_feedwordpress_footer() */

################################################################################
## TEMPLATE API: functions to make your templates syndication-aware ############
################################################################################

/**
 * is_syndicated: Tests whether the current post in a Loop context, or a post
 * given by ID number, was syndicated by FeedWordPress. Useful for templates
 * to determine whether or not to retrieve syndication-related meta-data in
 * displaying a post.
 *
 * @param int $id The post to check for syndicated status. Defaults to the current post in a Loop context.
 * @return bool TRUE if the post's meta-data indicates it was syndicated; FALSE otherwise 
 */ 
function is_syndicated ($id = NULL) {
	$p = new FeedWordPressLocalPost($id);
	return $p->is_syndicated();
} /* function is_syndicated() */

function feedwordpress_display_url ($url, $before = 60, $after = 0) {
	$bits = parse_url($url);
	
	// Strip out crufty subdomains
	if (isset($bits['host'])) :
  		$bits['host'] = preg_replace('/^www[0-9]*\./i', '', $bits['host']);
  	endif;
  
  	// Reassemble bit-by-bit with minimum of crufty elements
	$url = (isset($bits['user'])?$bits['user'].'@':'')
		.(isset($bits['host'])?$bits['host']:'')
		.(isset($bits['path'])?$bits['path']:'')
		.(isset($uri_bits['port'])?':'.$uri_bits['port']:'')
		.(isset($bits['query'])?'?'.$bits['query']:'');

	if (strlen($url) > ($before+$after)) :
		$url = substr($url, 0, $before).'…'.substr($url, 0 - $after, $after);
	endif;

	return $url;
} /* feedwordpress_display_url () */

function get_syndication_source_property ($original, $id, $local, $remote = NULL) {
	$p = new FeedWordPressLocalPost($id);
	return $p->meta($local, array("unproxy" => $original, "unproxied setting" => $remote));
} /* function get_syndication_source_property () */

function get_syndication_source_link ($original = NULL, $id = NULL) {
	$p = new FeedWordPressLocalPost($id);
	return $p->syndication_source_link($original);
} /* function get_syndication_source_link() */

function the_syndication_source_link ($original = NULL, $id = NULL) {
	echo get_syndication_source_link($original, $id);
} /* function the_syndication_source_link() */

function get_syndication_source ($original = NULL, $id = NULL) {
	$p = new FeedWordPressLocalPost($id);
	return $p->syndication_source($original);
} /* function get_syndication_source() */

function the_syndication_source ($original = NULL, $id = NULL) {
	echo get_syndication_source($original, $id);
} /* function the_syndication_source () */

function get_syndication_feed ($original = NULL, $id = NULL) {
	$p = new FeedWordPressLocalPost($id);
	return $p->syndication_feed($original);
} /* function get_syndication_feed() */

function the_syndication_feed ($original = NULL, $id = NULL) {
	echo get_syndication_feed($original, $id);
} /* function the_syndication_feed() */

function get_syndication_feed_guid ($original = NULL, $id = NULL) {
	$p = new FeedWordPressLocalPost($id);
	return $p->syndication_feed_guid($original);
} /* function get_syndication_feed_guid () */

function the_syndication_feed_guid ($original = NULL, $id = NULL) {
	echo get_syndication_feed_guid($original, $id);
} /* function the_syndication_feed_guid () */

function get_syndication_feed_id ($id = NULL) {
	$p = new FeedWordPressLocalPost($id);
	return $p->feed_id();
} /* function get_syndication_feed_id () */

function the_syndication_feed_id ($id = NULL) {
	echo get_syndication_feed_id($id);
} /* function the_syndication_feed_id () */

function get_syndication_feed_object ($id = NULL) {
	$p = new FeedWordPressLocalPost($id);
	return $p->feed();
} /* function get_syndication_feed_object() */

function get_feed_meta ($key, $id = NULL) {
	$ret = NULL;

	$link = get_syndication_feed_object($id);
	if (is_object($link) and isset($link->settings[$key])) :
		$ret = $link->settings[$key];
	endif;
	return $ret;
} /* function get_feed_meta() */

function get_syndication_permalink ($id = NULL) {
	$p = new FeedWordPressLocalPost($id);
	return $p->syndication_permalink();
} /* function get_syndication_permalink () */

function the_syndication_permalink ($id = NULL) {
	echo get_syndication_permalink($id);
} /* function the_syndication_permalink () */

/**
 * get_local_permalink: returns a string containing the internal permalink
 * for a post (whether syndicated or not) on your local WordPress installation.
 * This may be useful if you want permalinks to point to the original source of
 * an article for most purposes, but want to retrieve a URL for the local
 * representation of the post for one or two limited purposes (for example,
 * linking to a comments page on your local aggregator site).
 *
 * @param $id The numerical ID of the post to get the permalink for. If empty,
 * 	defaults to the current post in a Loop context.
 * @return string The URL of the local permalink for this post.
 *
 * @uses get_permalink()
 * @global $feedwordpress_the_original_permalink
 *
 * @since 2010.0217
 */
function get_local_permalink ($id = NULL) {
	global $feedwordpress_the_original_permalink;
	
	// get permalink, and thus activate filter and force global to be filled
	// with original URL.
	$url = get_permalink($id);
	return $feedwordpress_the_original_permalink;
} /* get_local_permalink() */

/**
 * the_original_permalink: displays the contents of get_original_permalink()
 *
 * @param $id The numerical ID of the post to get the permalink for. If empty,
 * 	defaults to the current post in a Loop context.
 *
 * @uses get_local_permalinks()
 * @uses apply_filters
 *
 * @since 2010.0217
 */
function the_local_permalink ($id = NULL) {
	print apply_filters('the_permalink', get_local_permalink($id));
} /* function the_local_permalink() */

################################################################################
## FILTERS: syndication-aware handling of post data for templates and feeds ####
################################################################################

$feedwordpress_the_syndicated_content = NULL;
$feedwordpress_the_original_permalink = NULL;

function feedwordpress_preserve_syndicated_content ($text) {
	global $feedwordpress_the_syndicated_content;

	$p = new FeedWordPressLocalPost;
	if (!$p->is_exposed_to_formatting_filters()) :
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
	<title><?php print htmlspecialchars(get_syndication_source()); ?></title>
	<link rel="alternate" type="text/html" href="<?php print htmlspecialchars(get_syndication_source_link()); ?>" />
	<link rel="self" href="<?php print htmlspecialchars(get_syndication_feed()); ?>" />
<?php
	$id = get_syndication_feed_guid();
	if (strlen($id) > 0) :
?>
	<id><?php print htmlspecialchars($id); ?></id>
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

/**
 * syndication_permalink: Allow WordPress to use the original remote URL of
 * syndicated posts as their permalink. Can be turned on or off by by setting in
 * Syndication => Posts & Links. Saves the old internal permalink in a global
 * variable for later use.
 *
 * @param string $permalink The internal permalink
 * @return string The new permalink. Same as the old if the post is not
 *	syndicated, or if FWP is set to use internal permalinks, or if the post
 *	was syndicated, but didn't have a proper permalink recorded.
 *
 * @uses SyndicatedLink::setting()
 * @uses get_syndication_permalink()
 * @uses get_syndication_feed_object()
 * @uses url_to_postid()
 * @global $id
 * @global $feedwordpress_the_original_permalink
 */
function syndication_permalink ($permalink = '', $post = null, $leavename = false, $sample = false) {
	global $id;
	global $feedwordpress_the_original_permalink;
	
	// Save the local permalink in case we need to retrieve it later.
	$feedwordpress_the_original_permalink = $permalink;

	if (is_object($post) and isset($post->ID) and !empty($post->ID)) :
		// Use the post ID we've been provided with.
		$postId = $post->ID;
	elseif (is_string($permalink) and strlen($permalink) > 0) :
		// Map this permalink to a post ID so we can get the correct
		// permalink even outside of the Post Loop. Props Björn.
		$postId = url_to_postid($permalink);
	else :
		// If the permalink string is empty but Post Loop context
		// provides an id.
		$postId = $id;
	endif;

	$munge = false;
	$link = get_syndication_feed_object($postId);
	if (is_object($link)) :
		$munge = ($link->setting('munge permalink', 'munge_permalink', 'yes') != 'no');
	endif;

	if ($munge):
		$uri = get_syndication_permalink($postId);
		$permalink = ((strlen($uri) > 0) ? $uri : $permalink);
	endif;
	return $permalink;
} /* function syndication_permalink () */

/**
 * syndication_permalink_escaped: Escape XML special characters in syndicated
 * permalinks when used in feed contexts and HTML display contexts.
 *
 * @param string $permalink
 * @return string
 *
 * @uses is_syndicated()
 * @uses FeedWordPress::munge_permalinks()
 *
 */
function syndication_permalink_escaped ($permalink) {
	/* FIXME: This should review link settings not just global settings */
	if (is_syndicated() and FeedWordPress::munge_permalinks()) :
		// This is a foreign link; WordPress can't vouch for its not
		// having any entities that need to be &-escaped. So we'll do
		// it here.
		$permalink = esc_html($permalink);
	endif;
	return $permalink;
} /* function syndication_permalink_escaped() */ 

/**
 * syndication_comments_feed_link: Escape XML special characters in comments
 * feed links 
 *
 * @param string $link
 * @return string
 *
 * @uses is_syndicated()
 * @uses FeedWordPress::munge_permalinks()
 */
function syndication_comments_feed_link ($link) {
	global $feedwordpress_the_original_permalink, $id;

	if (is_syndicated() and FeedWordPress::munge_permalinks()) :
		// If the source post provided a comment feed URL using
		// wfw:commentRss or atom:link/@rel="replies" we can make use of
		// that value here.
		$source = get_syndication_feed_object();
		$replacement = NULL;
		
		if ($source->setting('munge comments feed links', 'munge_comments_feed_links', 'yes') != 'no') :
			$commentFeeds = get_post_custom_values('wfw:commentRSS');
			if (
				is_array($commentFeeds)
				and (count($commentFeeds) > 0)
				and (strlen($commentFeeds[0]) > 0)
			) :
				$replacement = $commentFeeds[0];
				
				// This is a foreign link; WordPress can't vouch for its not
				// having any entities that need to be &-escaped. So we'll do it
				// here.
				$replacement = esc_html($replacement);
			endif;
		endif;
		
		if (is_null($replacement)) :
			// Q: How can we get the proper feed format, since the
			// format is, stupidly, not passed to the filter?
			// A: Kludge kludge kludge kludge!
			$fancy_permalinks = ('' != get_option('permalink_structure'));
			if ($fancy_permalinks) :
				preg_match('|/feed(/([^/]+))?/?$|', $link, $ref);

				$format = (isset($ref[2]) ? $ref[2] : '');
				if (strlen($format) == 0) : $format = get_default_feed(); endif;

				$replacement = trailingslashit($feedwordpress_the_original_permalink) . 'feed';
				if ($format != get_default_feed()) :
					$replacement .= '/'.$format;
				endif;
				$replacement = user_trailingslashit($replacement, 'single_feed');
			else :
				// No fancy permalinks = no problem
				// WordPress doesn't call get_permalink() to
				// generate the comment feed URL, so the
				// comments feed link is never munged by FWP.
			endif;
		endif;
		
		if (!is_null($replacement)) : $link = $replacement; endif;
	endif;
	return $link;
} /* function syndication_comments_feed_link() */

################################################################################
## ADMIN MENU ADD-ONS: register Dashboard management pages #####################
################################################################################

function fwp_add_pages () {
	global $feedwordpress;
	
	$menu_cap = FeedWordPress::menu_cap();
	$settings_cap = FeedWordPress::menu_cap(/*sub=*/ true);
	$syndicationMenu = FeedWordPress::path('syndication.php');
	
	add_menu_page(
		'Syndicated Sites', 'Syndication',
		$menu_cap,
		$syndicationMenu,
		NULL,
		WP_PLUGIN_URL.'/'.FeedWordPress::path('feedwordpress-tiny.png')
	);

	do_action('feedwordpress_admin_menu_pre_feeds', $menu_cap, $settings_cap);
	add_submenu_page(
		$syndicationMenu, 'Syndicated Sites', 'Syndicated Sites',
		$settings_cap, $syndicationMenu
	);

	do_action('feedwordpress_admin_menu_pre_feeds', $menu_cap, $settings_cap);
	add_submenu_page(
		$syndicationMenu, 'Syndicated Feeds & Updates', 'Feeds & Updates',
		$settings_cap, FeedWordPress::path('feeds-page.php')
	);

	do_action('feedwordpress_admin_menu_pre_posts', $menu_cap, $settings_cap);
	add_submenu_page(
		$syndicationMenu, 'Syndicated Posts & Links', 'Posts & Links',
		$settings_cap, FeedWordPress::path('posts-page.php')
	);

	do_action('feedwordpress_admin_menu_pre_authors', $menu_cap, $settings_cap);
	add_submenu_page(
		$syndicationMenu, 'Syndicated Authors', 'Authors',
		$settings_cap, FeedWordPress::path('authors-page.php')
	);

	do_action('feedwordpress_admin_menu_pre_categories', $menu_cap, $settings_cap);
	add_submenu_page(
		$syndicationMenu, 'Categories & Tags', 'Categories & Tags',
		$settings_cap, FeedWordPress::path('categories-page.php')
	);

	do_action('feedwordpress_admin_menu_pre_performance', $menu_cap, $settings_cap);
	add_submenu_page(
		$syndicationMenu, 'FeedWordPress Performance', 'Performance',
		$settings_cap, FeedWordPress::path('performance-page.php')
	);

	do_action('feedwordpress_admin_menu_pre_diagnostics', $menu_cap, $settings_cap);
	add_submenu_page(
		$syndicationMenu, 'FeedWordPress Diagnostics', 'Diagnostics',
		$settings_cap, FeedWordPress::path('diagnostics-page.php')
	);
	
	add_filter('page_row_actions', array($feedwordpress, 'row_actions'), 10, 2);
	add_filter('post_row_actions', array($feedwordpress, 'row_actions'), 10, 2);
} /* function fwp_add_pages () */

function fwp_check_debug () {
	// This is a horrible fucking kludge that I have to do because the
	// admin notice code is triggered before the code that updates the
	// setting.
	if (isset($_POST['feedwordpress_debug'])) :
		$feedwordpress_debug = $_POST['feedwordpress_debug'];
	else :
		$feedwordpress_debug = get_option('feedwordpress_debug');
	endif;
	if ($feedwordpress_debug==='yes') :
?>
		<div class="error">
<p><strong>FeedWordPress warning.</strong> Debugging mode is <strong>ON</strong>.
While it remains on, FeedWordPress displays many diagnostic error messages,
warnings, and notices that are ordinarily suppressed, and also turns off all
caching of feeds. Use with caution: this setting is absolutely inappropriate
for a production server.</p>
		</div>
<?php
	endif;
} /* function fwp_check_debug () */

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

	function feedwordpress_add_post_edit_controls () {
		global $feedwordpress;
		global $inspectPostMeta;

		// Put in Manual Editing checkbox
		add_meta_box('feedwordpress-post-controls', __('Syndication'), 'feedwordpress_post_edit_controls', 'post', 'side', 'high');
		
		add_filter('user_can_richedit', array($feedwordpress, 'user_can_richedit'), 1000, 1);
		
		if (FeedWordPress::diagnostic_on('syndicated_posts:static_meta_data')) :
			$inspectPostMeta = new InspectPostMeta;
		endif;
	} // function FeedWordPress::postEditControls
	
	function feedwordpress_post_edit_controls () {
		global $post;
		
		$frozen_values = get_post_custom_values('_syndication_freeze_updates', $post->ID);
		$frozen_post = (count($frozen_values) > 0 and 'yes' == $frozen_values[0]);

		if (is_syndicated($post->ID)) :
		?>
		<p>This is a syndicated post, which originally appeared at
		<cite><?php print esc_html(get_syndication_source(NULL, $post->ID)); ?></cite>.
		<a href="<?php print esc_html(get_syndication_permalink($post->ID)); ?>">View original post</a>.</p>
		
		<p><input type="hidden" name="feedwordpress_noncename" id="feedwordpress_noncename" value="<?php print wp_create_nonce(plugin_basename(__FILE__)); ?>" />
		<label><input type="checkbox" name="freeze_updates" value="yes" <?php if ($frozen_post) : ?>checked="checked"<?php endif; ?> /> <strong>Manual editing.</strong>
		If set, FeedWordPress will not overwrite the changes you make manually
		to this post, if the syndicated content is updated on the
		feed.</label></p>
		<?php
		else :
		?>
		<p>This post was created locally at this website.</p>
		<?php
		endif;
	} // function feedwordpress_post_edit_controls () */

	function feedwordpress_save_post_edit_controls ( $post_id ) {
		global $post;
		
		if (!isset($_POST['feedwordpress_noncename']) or !wp_verify_nonce($_POST['feedwordpress_noncename'], plugin_basename(__FILE__))) :
			return $post_id;
		endif;
	
		// Verify if this is an auto save routine. If it is our form has
		// not been submitted, so we don't want to do anything.
		if ( defined('DOING_AUTOSAVE') and DOING_AUTOSAVE ) :
			return $post_id;
		endif;
		
		// Check permissions
		if ( !current_user_can( 'edit_'.$_POST['post_type'], $post_id) ) :
			return $post_id;
		endif;
		
		// OK, we're golden. Now let's save some data.
		if (isset($_POST['freeze_updates'])) :
			update_post_meta($post_id, '_syndication_freeze_updates', $_POST['freeze_updates']);
			$ret = $_POST['freeze_updates'];
		else :
			delete_post_meta($post_id, '_syndication_freeze_updates');
			$ret = NULL;
		endif;
		
		return $ret;
	} // function feedwordpress_save_edit_controls

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
	var $feedurls = NULL;
	var $httpauth = NULL;
	
	# function FeedWordPress (): Contructor; retrieve a list of feeds 
	function FeedWordPress () {
		$this->feeds = array ();
		$this->feedurls  = array();
		$links = FeedWordPress::syndicated_links();
		if ($links): foreach ($links as $link):
			$id = intval($link->link_id);
			$url = $link->link_rss;
			
			// Store for later reference.
			$this->feeds[$id] = $id;
			if (strlen($url) > 0) :
				$this->feedurls[$url] = $id;
			endif;
		endforeach; endif;

		$this->httpauth = new FeedWordPressHTTPAuthenticator;
	} // FeedWordPress::FeedWordPress ()

	function subscribed ($id) {
		return (isset($this->feedurls[$id]) or isset($this->feeds[$id]));
	} /* FeedWordPress::subscribed () */
	
	function subscription ($which) {
		$sub = NULL;
		
		if (is_string($which) and isset($this->feedurls[$which])) :
			$which = $this->feedurls[$which];
		endif;
		
		if (isset($this->feeds[$which])) :
			$sub = $this->feeds[$which];
		endif;

		// If it's not in the in-memory cache already, try to load it from DB.
		// This is necessary to fill requests for subscriptions that we don't
		// cache in memory, e.g. for deactivated feeds.
		if (is_null($sub)) :
			$sub = get_bookmark($which);
		endif;

		// Load 'er up if you haven't already.
		if (!is_null($sub) and !($sub InstanceOf SyndicatedLink)) :
			$link = new SyndicatedLink($sub);
			$this->feeds[$which] = $link;
			$sub = $link;
		endif;
		
		return $sub;
	} /* FeedWordPress::subscription () */
	
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
		
		if (!is_null($uri) and $uri != '*') :
			$uri = trim($uri);
		else : // Update all
			if ($this->update_hooked) :
				$diag = $this->update_hooked;
			else :
				$diag = 'Initiating a MANUAL check-in on the update schedule at '.date('r', time());
			endif;
			$this->diagnostic('update_schedule:check', $diag);

			update_option('feedwordpress_last_update_all', time());
		endif;

		do_action('feedwordpress_update', $uri);

		if (is_null($crash_ts)) :
			$crash_ts = $this->crash_ts();
		endif;
		
		// Randomize order for load balancing purposes
		$feed_set = array_keys($this->feeds);
		shuffle($feed_set);

		$updateWindow = (int) get_option('feedwordpress_update_window', DEFAULT_UPDATE_PERIOD) * 60 /* sec/min */;
		$interval = (int) get_option('feedwordpress_freshness', FEEDWORDPRESS_FRESHNESS_INTERVAL);
		$portion = max(
			(int) ceil(count($feed_set) / ($updateWindow / $interval)),
			10
		);

		$max_polls = apply_filters('feedwordpress_polls_per_update', get_option(
			'feedwordpress_polls_per_update',	$portion
		), $uri);

		$feed_set = apply_filters('feedwordpress_update_feeds', $feed_set, $uri);
		
	
		// Loop through and check for new posts
		$delta = NULL; $remaining = $max_polls;
		foreach ($feed_set as $feed_id) :

			$feed = $this->subscription($feed_id);

			// Has this process overstayed its welcome?
			if (
				// Over time limit?
				(!is_null($crash_ts) and (time() > $crash_ts))
				
				// Over feed count?
				or ($remaining == 0) 
			) :
				break;
			endif;
			
			$pinged_that = (is_null($uri) or ($uri=='*') or in_array($uri, array($feed->uri(), $feed->homepage())));

			if (!is_null($uri)) : // A site-specific ping always updates
				$timely = true;
			else :
				$timely = $feed->stale();
			endif;

			if ($pinged_that and is_null($delta)) :		// If at least one feed was hit for updating...
				$delta = array('new' => 0, 'updated' => 0);	// ... don't return error condition 
			endif;

			if ($pinged_that and $timely) :
				$remaining = $remaining - 1;

				do_action('feedwordpress_check_feed', $feed->settings);
				$start_ts = time();
				$added = $feed->poll($crash_ts);
				do_action('feedwordpress_check_feed_complete', $feed->settings, $added, time() - $start_ts);

				if (is_array($added)) : // Success
					if (isset($added['new'])) : $delta['new'] += $added['new']; endif;
					if (isset($added['updated'])) : $delta['updated'] += $added['updated']; endif;
				endif;
			endif;
		endforeach;

		do_action('feedwordpress_update_complete', $delta);

		return $delta;
	}

	function crash_ts ($default = NULL) {
		$crash_dt = (int) get_option('feedwordpress_update_time_limit', 0);
		if ($crash_dt > 0) :
			$crash_ts = time() + $crash_dt;
		else :
			$crash_ts = $default;
		endif;
		return $crash_ts;
	}
	
	function secret_key () {
		$secret = get_option('feedwordpress_secret_key', false);
		if (!$secret) : // Generate random key.
			$secret = substr(md5(uniqid(microtime())), 0, 6);
			update_option('feedwordpress_secret_key', $secret);
		endif;
		return $secret;
	}
	
	function has_secret () {
		return ($this->by_request('feedwordpress_key', $this->secret_key()));
	}

	// Utility function so I don't have to repeat myself w/ 1,000,003 isset()'s
	function by_request ($param, $eq = NULL) {
		$match = false;
		if (isset($_REQUEST[$param])) :
			$match = (is_null($eq) ? $_REQUEST[$param] : ($eq==$_REQUEST[$param]));
		endif;
		return $match;
	}
	
	var $update_hooked = NULL;
	function automatic_update_hook ($params = array()) {
		$params = wp_parse_args($params, array( // Defaults
			'setting only' => false,
		));
		$hook = get_option('feedwordpress_automatic_updates', NULL);
		$method = 'FeedWordPress option';

		// Allow for forced behavior in testing.
		if (
			!$params['setting only']
			and $this->has_secret()
			and $this->by_request('automatic_update')
		) :
			$hook = $_REQUEST['automatic_update'];
			$method = 'URL parameter';
		endif;
		
		$exact = $hook; // Before munging
		
		if (!!$hook) :
			if ($hook != 'init') : // Constrain values.
				$hook = 'shutdown';
			endif;
		endif;
		
		if ($hook) :
			$this->update_hooked = "Initiating an AUTOMATIC CHECK FOR UPDATES ON PAGE LOAD ".$hook." due to ".$method." = ".trim($this->val($exact));
		endif;
		
		return $hook; 
	}
	function last_update_all () {
		$last = get_option('feedwordpress_last_update_all');
		if ($this->has_secret() and $this->by_request('automatic_update')) :
			$last = 1; // A long, long time ago.
		elseif ($this->has_secret() and $this->by_request('last_update_all')) :
			$last = $_REQUEST['last_update_all'];
		endif;
		return $last;
	}
	function force_update_all () {
		return ($this->has_secret() and $this->by_request('force_update_feeds'));
	}
	
	function stale () {
		if (!is_null($this->automatic_update_hook())) :
			// Do our best to avoid possible simultaneous
			// updates by getting up-to-the-minute settings.
			
			$last = $this->last_update_all();
		
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
				$ret = ( (time() - $last) > $freshness );
			
			// This should never happen.
			else :
				FeedWordPress::critical_bug('FeedWordPress::stale::last', $last, __LINE__, __FILE__);
			endif;

		else :
			$ret = false;
		endif;
		return $ret;
	} // FeedWordPress::stale()

	static function admin_init () {
		// WordPress 3.5+ compat: the WP devs are in the midst of removing Links from the WordPress core. Eventually we'll have to deal
		// with the possible disappearance of the wp_links table as a whole; but in the meantime, we just need to turn on the interface
		// element to avoid problems with user capabilities that presume the presence of the Links Manager in the admin UI.
		if (!intval(get_option('link_manager_enabled', false))) :
			update_option('link_manager_enabled', true);
		endif;
	} /* FeedWordPress::admin_init() */

	function admin_api () {
		// This sucks, but WordPress doesn't give us any other way to
		// easily invoke a permanent-delete from a plugged in post
		// actions link. So we create a magic parameter, and when this
		// magic parameter is activated, the WordPress trashcan is
		// temporarily de-activated.
		
		if (MyPHP::request('fwp_post_delete')=='nuke') :
			// Get post ID #
			$post_id = MyPHP::request('post');
			if (!$post_id) :
				$post_id = MyPHP::request('post_ID');
			endif;
			
			// Make sure we've got the right nonce and all that.
			check_admin_referer('delete-post_' . $post_id);
			
			// If so, disable the trashcan.
			define('EMPTY_TRASH_DAYS', 0);
		endif;

	}

	function init () {
		global $fwp_path;
		
		// If this is a FeedWordPress admin page, queue up scripts for AJAX
		// functions that FWP uses. If it is a display page or a non-FWP admin
		// page, don't.
		wp_register_style('feedwordpress-elements', WP_PLUGIN_URL.'/'.$fwp_path.'/feedwordpress-elements.css');
		if (FeedWordPressSettingsUI::is_admin()) :
			// For JavaScript that needs to be generated dynamically
			add_action('admin_print_scripts', array('FeedWordPressSettingsUI', 'admin_scripts'));
		
			// For CSS that needs to be generated dynamically.
			add_action('admin_print_styles', array('FeedWordPressSettingsUI', 'admin_styles'));
		
			wp_enqueue_style('dashboard');
			wp_enqueue_style('feedwordpress-elements');
		
			/*if (function_exists('wp_admin_css')) :
				wp_admin_css('css/dashboard');
			endif;*/
		endif;

		// This is a special post status for hiding posts that have expired
		register_post_status('fwpretired', array(
		'label' => _x('Retired', 'post'),
		'label_count' => _n_noop('Retired <span class="count">(%s)</span>', 'Retired <span class="count">(%s)</span>'),
		'exclude_from_search' => true,
		'public' => false,
		'publicly_queryable' => false,
		'show_in_admin_all_list' => false,
		'show_in_admin_status_list' => true,
		));
		add_action(
			/*hook=*/ 'template_redirect',
			/*function=*/ array($this, 'redirect_retired'),
			/*priority=*/ -100
		);

		add_action('wp_ajax_fwp_feeds', array($this, 'fwp_feeds'));
		add_action('wp_ajax_fwp_feedcontents', array($this, 'fwp_feedcontents'));
		add_action('wp_ajax_fwp_xpathtest', array($this, 'fwp_xpathtest'));
		
		$this->clear_cache_magic_url();
		$this->update_magic_url();
	} /* FeedWordPress::init() */
	
	function fwp_feeds () {
		$feeds = array();
		$feed_ids = $this->feeds;
		
		foreach ($feed_ids as $id) :
			$sub = $this->subscription($id);
			$feeds[] = array(
			"id" => $id,
			"url" => $sub->uri(),
			"name" => $sub->name(/*fromFeed=*/ false),
			);
		endforeach;

		header("Content-Type: application/json");	
		echo json_encode($feeds);
		exit;
	}

	function fwp_feedcontents () {
		$feed_id = MyPHP::request('feed_id');
		
		// Let's load up some data from the feed . . .
		$feed = $this->subscription($feed_id);
		$posts = $feed->live_posts();
		
		if (is_wp_error($posts)) :
			header("HTTP/1.1 502 Bad Gateway");
			$result = $posts;
		else :
			$result = array();

			foreach ($posts as $post) :
				$p = new SyndicatedPost($post, $feed);
				
				$result[] = array(
					"post_title" => $p->entry->get_title(),
					"post_link" => $p->permalink(),
					"guid" => $p->guid(),
					"post_date" => $p->published(),
				);
			endforeach;
		endif;
		
		header("Content-Type: application/json");
		
		echo json_encode($result);
		
		// This is an AJAX request, so close it out thus.
		die;
	} /* FeedWordPress::fwp_feedcontents () */
	
	function fwp_xpathtest () {
		$xpath = MyPHP::request('xpath');
		$feed_id = MyPHP::request('feed_id');
		$post_id = MyPHP::request('post_id');
		
		$expr = new FeedWordPressParsedPostMeta($xpath);
		
		// Let's load up some data from the feed . . .
		$feed = $this->subscription($feed_id);
		$posts = $feed->live_posts();
		
		if (!is_wp_error($posts)) :
			if (strlen($post_id) == 0) :
				$post = $posts[0];
			else :
				$post = null;
	
				foreach ($posts as $p) :
					if ($p->get_id() == $post_id) :
						$post = $p;
					endif;
				endforeach;
			endif;
		
			$post = new SyndicatedPost($post, $feed);
			$meta = $expr->do_substitutions($post);
			
			$result = array(
			"post_title" => $post->entry->get_title(),
			"post_link" => $post->permalink(),
			"guid" => $post->guid(),
			"expression" => $xpath,
			"results" => $meta
			);
		else :
			$result = array(
			"expression" => $xpath,
			"feed_id" => $feed_id,
			"post_id" => $post_id,
			"results" => $posts
			);
			
			header("HTTP/1.1 503 Bad Gateway");
		endif;
		
		header("Content-Type: application/json");
		
		echo json_encode($result);
		
		// This is an AJAX request, so close it out thus.
		die;
	} /* FeedWordPress::fwp_xpathtest () */
	
	function redirect_retired () {
		global $wp_query;
		if (is_singular()) :
			if ('fwpretired'==$wp_query->post->post_status) :
				do_action('feedwordpress_redirect_retired', $wp_query->post);
				
				if (!($template = get_404_template())) :
					$template = get_index_template();
				endif;
				if ($template = apply_filters('template_include', $template)) :
					header("HTTP/1.1 410 Gone");
					include($template);
				endif;
				exit;
			endif;
		endif;
	}
	
	function row_actions ($actions, $post) {
		if (is_syndicated($post->ID)) :
			$link = get_delete_post_link($post->ID, '', true);
			$link = MyPHP::url($link, array("fwp_post_delete" => "nuke"));
			
			$caption = 'Erase the record of this post (will be re-syndicated if it still appears on the feed).';
			$linktext = 'Erase/Resyndicate';
			
			$keys = array_keys($actions);
			$links = array();
			foreach ($keys as $key) :
				$links[$key] = $actions[$key];
				
				if ('trash'==$key) :
					$links[$key] = "<a class='submitdelete' title='" . esc_attr( __( 'Move this item to the Trash (will NOT be re-syndicated)' ) ) . "' href='" . get_delete_post_link( $post->ID ) . "'>" . __( 'Trash/Don&#8217;t Resyndicate' ) . "</a>";
					
					// Placeholder.
					$links['delete'] = '';
				endif;
			endforeach;
			
			$links['delete'] = '<a class="submitdelete" title="'.esc_attr(__($caption)).'" href="' . $link . '">' . __($linktext) . '</a>';

			$actions = $links;
		endif;
		return $actions;
	}
	
	function dashboard_setup () {
		$see_it = FeedWordPress::menu_cap();
		
		if (current_user_can($see_it)) :
			// Get the stylesheet
			wp_enqueue_style('feedwordpress-elements');
	
			$widget_id = 'feedwordpress_dashboard';
			$widget_name = __('Syndicated Sources');
			$column = 'side';
			$priority = 'core';
	
			// I would love to use wp_add_dashboard_widget() here and save
			// myself some trouble. But WP 3 does not yet have any way to
			// push a dashboard widget onto the side, or to give it a default
			// location.
			add_meta_box(
				/*id=*/ $widget_id,
				/*title=*/ $widget_name,
				/*callback=*/ array($this, 'dashboard'),
				/*page=*/ 'dashboard',
				/*context=*/ $column,
				/*priority=*/ $priority
			);
			/*control_callback= array($this, 'dashboard_control') */
			
			// This is kind of rude, I know, but the dashboard widget isn't
			// worth much if users don't know that it exists, and I don't
			// know of any better way to reorder the boxen.
			//
			// Gleefully ripped off of codex.wordpress.org/Dashboard_Widgets_API
			
			// Globalize the metaboxes array, this holds all the widgets for wp-admin
			global $wp_meta_boxes;
	
			// Get the regular dashboard widgets array 
			// (which has our new widget already but at the end)
	
			$normal_dashboard = $wp_meta_boxes['dashboard'][$column][$priority];
		
			// Backup and delete our new dashbaord widget from the end of the array
			if (isset($normal_dashboard[$widget_id])) :
				$backup = array();
				$backup[$widget_id] = $normal_dashboard[$widget_id];
				unset($normal_dashboard[$widget_id]);
	
				// Merge the two arrays together so our widget is at the
				// beginning
				$sorted_dashboard = array_merge($backup, $normal_dashboard);
	
				// Save the sorted array back into the original metaboxes 
				$wp_meta_boxes['dashboard'][$column][$priority] = $sorted_dashboard;
			endif;
		endif;
	} /* FeedWordPress::dashboard_setup () */
	
	function dashboard () {
		$syndicationPage = new FeedWordPressSyndicationPage(dirname(__FILE__).'/syndication.php');
		$syndicationPage->dashboard_box($syndicationPage);
	} /* FeedWordPress::dashboard () */

	function user_can_richedit ($rich_edit) {

		$post = new FeedWordPressLocalPost;
		
		if (!$post->is_exposed_to_formatting_filters()) :
			// Disable visual editor and only allow operations
			// directly on HTML if post is bypassing fmt filters
			$rich_edit = false;
		endif;
		
		return $rich_edit;

	} /* FeedWordPress::user_can_richedit () */
	
	function update_magic_url () {
		global $wpdb;

		// Explicit update request in the HTTP request (e.g. from a cron job)
		if ($this->update_requested()) :
			$this->update_hooked = "Initiating a CRON JOB CHECK-IN ON UPDATE SCHEDULE due to URL parameter = ".trim($this->val($_REQUEST['update_feedwordpress']));

			$this->update($this->update_requested_url());
			
			if (FEEDWORDPRESS_DEBUG and count($wpdb->queries) > 0) :
				$mysqlTime = 0.0;
				$byTime = array();
				foreach ($wpdb->queries as $query) :
					$time = $query[1] * 1000000.0;
					$mysqlTime += $query[1];
					if (!isset($byTime[$time])) : $byTime[$time] = array(); endif;
					$byTime[$time][] = $query[0]. ' // STACK: ' . $query[2];   
				endforeach;
				krsort($byTime);
	       
				foreach ($byTime as $time => $querySet) :
					foreach ($querySet as $query) :
						print "[".(sprintf('%4.4f', $time/1000.0)) . "ms] $query\n";
					endforeach;
				endforeach;
				echo $this->log_prefix()."$wpdb->num_queries queries. $mysqlTime seconds in MySQL. Total of "; timer_stop(1); print " seconds.";
			endif;
	
			debug_out_feedwordpress_footer();
	
			// Magic URL should return nothing but a 200 OK header packet
			// when successful.
			exit;
		endif;
	} /* FeedWordPress::magic_update_url () */

	function clear_cache_magic_url () {
		if ($this->clear_cache_requested()) :
			$this->clear_cache();
		endif;
	} /* FeedWordPress::clear_cache_magic_url() */
	
	function clear_cache_requested () {
		return MyPHP::request('clear_cache');
	} /* FeedWordPress::clear_cache_requested() */

	function update_requested () {
		return FeedWordPress::by_request('update_feedwordpress');
	} // FeedWordPress::update_requested()

	function update_requested_url () {
		$ret = null;
		
		if (($_REQUEST['update_feedwordpress']=='*')
		or (preg_match('|^http://.*|i', $_REQUEST['update_feedwordpress']))) :
			$ret = $_REQUEST['update_feedwordpress'];
		endif;

		return $ret;
	} // FeedWordPress::update_requested_url()

	function auto_update () {
		if ($this->stale()) :
			$this->update();
		endif;
	} /* FeedWordPress::auto_update () */

	function find_link ($uri, $field = 'link_rss') {
		global $wpdb;

		$unslashed = untrailingslashit($uri);
		$slashed = trailingslashit($uri);
		$link_id = $wpdb->get_var($wpdb->prepare("
		SELECT link_id FROM $wpdb->links WHERE $field IN ('%s', '%s')
		LIMIT 1", $unslashed, $slashed
		));
		
		return $link_id;
	} /* FeedWordPress::find_link () */

	function syndicate_link ($name, $uri, $rss) {
		// Get the category ID#
		$cat_id = FeedWordPress::link_category_id();
		if (!is_wp_error($cat_id)) :
			$link_category = array($cat_id);
		else :
			$link_category = array();
		endif;

		// WordPress gets cranky if there's no homepage URI
		if (!is_string($uri) or strlen($uri)<1) : $uri = $rss; endif;
		
		// Check if this feed URL is already being syndicated.
		$link_id = wp_insert_link(array(
		"link_id" => FeedWordPress::find_link($rss), // insert if nothing was found; else update
		"link_rss" => $rss,
		"link_name" => $name,
		"link_url" => $uri,
		"link_category" => $link_category,
		"link_visible" => 'Y', // reactivate if inactivated
		));

		return $link_id;
	} /* function FeedWordPress::syndicate_link() */

	/*static*/ function syndicated_status ($what, $default) {
		$ret = get_option("feedwordpress_syndicated_{$what}_status");
		if (!$ret) :
			$ret = $default;
		endif;
		return $ret;
	} /* FeedWordPress::syndicated_status() */

	function on_unfamiliar ($what = 'author') {
		switch ($what) :
		case 'category' : $suffix = ':category'; break;
		case 'post_tag' : $suffix = ':post_tag'; break;
		default: $suffix = '';
		endswitch;
		
		return get_option('feedwordpress_unfamiliar_'.$what, 'create'.$suffix);
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

	/**
	 * FeedWordPress::munge_permalinks: check whether or not FeedWordPress
	 * should rewrite permalinks for syndicated items to reflect their
	 * original location.
	 *
	 * @return bool TRUE if FeedWordPress SHOULD rewrite permalinks; FALSE otherwise
	 */
	/*static*/ function munge_permalinks () {
		return (get_option('feedwordpress_munge_permalink', /*default=*/ 'yes') != 'no');
	} /* FeedWordPress::munge_permalinks() */

	function syndicated_links ($args = array()) {
		$contributors = FeedWordPress::link_category_id();
		if (!is_wp_error($contributors)) :
			$links = get_bookmarks(array_merge(
				array("category" => $contributors),
				$args
			));
		else :
			$links = array();
		endif;

		return $links;
	} // function FeedWordPress::syndicated_links()

	function link_category_id () {
		global $wpdb, $wp_db_version;

		$cat_id = get_option('feedwordpress_cat_id');
		
		// If we don't yet have the category ID stored, search by name
		if (!$cat_id) :
			$cat_id = FeedWordPressCompatibility::link_category_id(DEFAULT_SYNDICATION_CATEGORY);

			if ($cat_id) :
				// We found it; let's stamp it.
				update_option('feedwordpress_cat_id', $cat_id);
			endif;

		// If we *do* have the category ID stored, verify that it exists
		else :
			$cat_id = FeedWordPressCompatibility::link_category_id((int) $cat_id, 'cat_id');
		endif;
		
		// If we could not find an appropriate link category,
		// make a new one for ourselves.
		if (!$cat_id) :
			$cat_id = FeedWordPressCompatibility::insert_link_category(DEFAULT_SYNDICATION_CATEGORY);
			if (!is_wp_error($cat_id)) :
				// Stamp it
				update_option('feedwordpress_cat_id', $cat_id);
			endif;
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
			if ($fwp_db_version <= 0.96) :
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
			elseif ($fwp_db_version < 2009.0707) :
				// We need to clear out any busted AJAX crap
				$wpdb->query("
				DELETE FROM $wpdb->usermeta
				WHERE LOCATE('feedwordpress', meta_key)
				AND LOCATE('box', meta_key);
				");

				update_option('feedwordpress_version', FEEDWORDPRESS_VERSION);
			elseif ($fwp_db_version < 2010.0814) :
				// Change in terminology.
				if (get_option('feedwordpress_unfamiliar_category', 'create')=='default') :
					update_option('feedwordpress_unfamiliar_category', 'null');
				endif;
				foreach (FeedWordPress::syndicated_links() as $link) :
					$sub = new SyndicatedLink($link);
					
					$remap_uf = array(
						'default' => 'null',
						'filter' => 'null',
						'create' => 'create:category',
						'tag' => 'create:post_tag'
					);
					if (isset($sub->settings['unfamiliar category'])) :
						if ($sub->settings['unfamiliar category']=='filter') :
							$sub->settings['match/filter'] = array('category');
						endif;
						foreach ($remap_uf as $from => $to) :
							if ($sub->settings['unfamiliar category']==$from) :
								$sub->settings['unfamiliar category'] = $to;
							endif;
						endforeach;
					endif;
					
					if (isset($sub->settings['add global categories'])) :
						$sub->settings['add/category'] = $sub->settings['add global categories'];
						unset($sub->settings['add global categories']);
					endif;
					
					$sub->save_settings(/*reload=*/ true);
				endforeach;
				update_option('feedwordpress_version', FEEDWORDPRESS_VERSION);
			else :
				// No. Just brand it with the new version.
				update_option('feedwordpress_version', FEEDWORDPRESS_VERSION);
			endif;
		endif;
		return $ret;
	}

	function upgrade_database ($from = NULL) {
		global $wpdb;

		if (is_null($from) or $from <= 0.96) : $from = 0.96; endif;

		switch ($from) :
		case 0.96:
			 // Dropping legacy upgrade code. If anyone is still
			 // using 0.96 and just now decided to upgrade, well, I'm
			 // sorry about that. You'll just have to cope with a few
			 // duplicate posts.

			// Mark the upgrade as successful.
			update_option('feedwordpress_version', FEEDWORDPRESS_VERSION);
		endswitch;
		echo "<p>Upgrade complete. FeedWordPress is now ready to use again.</p>";
	} /* FeedWordPress::upgrade_database() */

	function has_guid_index () {
		global $wpdb;
		
		$found = false; // Guilty until proven innocent.

		$results = $wpdb->get_results("
		SHOW INDEXES FROM {$wpdb->posts}
		");
		if ($results) :
			foreach ($results as $index) :
				if (isset($index->Column_name)
				and ('guid' == $index->Column_name)) :
					$found = true;
				endif;
			endforeach;
		endif;
		return $found;
	} /* FeedWordPress::has_guid_index () */
	
	function create_guid_index () {
		global $wpdb;
		
		$wpdb->query("
		CREATE INDEX {$wpdb->posts}_guid_idx ON {$wpdb->posts}(guid)
		");
	} /* FeedWordPress::create_guid_index () */
	
	function remove_guid_index () {
		global $wpdb;
		
		$wpdb->query("
		DROP INDEX {$wpdb->posts}_guid_idx ON {$wpdb->posts}
		");
	}

	/*static*/ function fetch_timeout () {
		return apply_filters(
			'feedwordpress_fetch_timeout',
			intval(get_option('feedwordpress_fetch_timeout', FEEDWORDPRESS_FETCH_TIMEOUT_DEFAULT))
		);
	}
	
	/*static*/ function fetch ($url, $params = array()) {
		if (is_wp_error($url)) :
			// Let's bounce.
			return $url;
		endif;
		
		$force_feed = true; // Default

		// Allow user to change default feed-fetch timeout with a global setting. Props Erigami Scholey-Fuller <http://www.piepalace.ca/blog/2010/11/feedwordpress-broke-my-heart.html>			'timeout' => 
		$timeout = FeedWordPress::fetch_timeout();
 		
		if (!is_array($params)) :
			$force_feed = $params;
		else : // Parameter array
			$args = wp_parse_args(array(
			'force_feed' => $force_feed,
			'timeout' => $timeout
			), $params);
			
			extract($args);
		endif;
		$timeout = intval($timeout);
		
		$pie_class = apply_filters('feedwordpress_simplepie_class', 'FeedWordPie');
		$cache_class = apply_filters('feedwordpress_cache_class', 'WP_Feed_Cache');
		$file_class = apply_filters('feedwordpress_file_class', 'FeedWordPress_File');
		$parser_class = apply_filters('feedwordpress_parser_class', 'FeedWordPress_Parser');
		$item_class = apply_filters('feedwordpress_item_class', 'FeedWordPie_Item');
		$sniffer_class = apply_filters('feedwordpress_sniffer_class', 'FeedWordPress_Content_Type_Sniffer');

		$feed = new $pie_class;
		$feed->set_feed_url($url);
		$feed->set_cache_class($cache_class);
		$feed->set_timeout($timeout);
		
		$feed->set_content_type_sniffer_class($sniffer_class);
		$feed->set_file_class($file_class);
		$feed->set_parser_class($parser_class);
		$feed->set_item_class($item_class);
		$feed->force_feed($force_feed);
		$feed->set_cache_duration(FeedWordPress::cache_duration($params));
		$feed->init();
		$feed->handle_content_type();
		
		if ($feed->error()) :
			$ret = new WP_Error('simplepie-error', $feed->error());
		else :
			$ret = $feed;
		endif;
		return $ret;
	} /* FeedWordPress::fetch () */
	
	function clear_cache () {
		global $wpdb;
		
		// Just in case, clear out any old MagpieRSS cache records.
		$magpies = $wpdb->query("
		DELETE FROM {$wpdb->options}
		WHERE option_name LIKE 'rss_%' AND LENGTH(option_name) > 32
		");

		// The WordPress SimplePie module stores its cached feeds as
		// transient records in the options table. The data itself is
		// stored in `_transient_feed_{md5 of url}` and the last-modified
		// timestamp in `_transient_feed_mod_{md5 of url}`. Timeouts for
		// these records are stored in `_transient_timeout_feed_{md5}`.
		// Since the md5 is always 32 characters in length, the
		// option_name is always over 32 characters.
		$simplepies = $wpdb->query("
		DELETE FROM {$wpdb->options}
		WHERE option_name LIKE '_transient%_feed_%' AND LENGTH(option_name) > 32
		");
		$simplepies = (int) ($simplepies / 4); // Each transient has 4 rows: the data, the modified timestamp; and the timeouts for each

		return ($magpies + $simplepies);
	} /* FeedWordPress::clear_cache () */

	function cache_duration ($params = array()) {
		$params = wp_parse_args($params, array(
		"cache" => true,
		));
		
		$duration = NULL;
		if (!$params['cache']) :
			$duration = 0;
		elseif (defined('FEEDWORDPRESS_CACHE_AGE')) :
			$duration = FEEDWORDPRESS_CACHE_AGE;
		endif;
		return $duration;
	}
	function cache_lifetime ($duration) {
		// Check for explicit setting of a lifetime duration
		if (defined('FEEDWORDPRESS_CACHE_LIFETIME')) :
			$duration = FEEDWORDPRESS_CACHE_LIFETIME;

		// Fall back to the cache freshness duration
		elseif (defined('FEEDWORDPRESS_CACHE_AGE')) :
			$duration = FEEDWORDPRESS_CACHE_AGE;
		endif;
		
		// Fall back to WordPress default
		return $duration;
	} /* FeedWordPress::cache_lifetime () */

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
	function critical_bug ($varname, $var, $line, $file = NULL) {
		global $wp_version;
		
		if (!is_null($file)) :
			$location = "line # ${line} of ".basename($file);
		else :
			$location = "line # ${line}";
		endif;
		
		print '<p><strong>Critical error:</strong> There may be a bug in FeedWordPress. Please <a href="'.FEEDWORDPRESS_AUTHOR_CONTACT.'">contact the author</a> and paste the following information into your e-mail:</p>';
		print "\n<plaintext>";
		print "Triggered at ${location}\n";
		print "FeedWordPress: ".FEEDWORDPRESS_VERSION."\n";
		print "WordPress:     {$wp_version}\n";
		print "PHP:           ".phpversion()."\n";
		print "Error data:    ";
		print  $varname.": "; var_dump($var); echo "\n";
		die;
	}
	
	function noncritical_bug ($varname, $var, $line, $file = NULL) {
		if (FEEDWORDPRESS_DEBUG) : // halt only when we are doing debugging
			FeedWordPress::critical_bug($varname, $var, $line, $file);
		endif;
	}
	
	function val ($v, $no_newlines = false) {
		ob_start();
		var_dump($v);
		$out = ob_get_contents(); ob_end_clean();
		
		if ($no_newlines) :
			$out = preg_replace('/\s+/', " ", $out);
		endif;
		return $out;
	} /* FeedWordPress::val () */

	function diagnostic_on ($level) {
		$show = get_option('feedwordpress_diagnostics_show', array());
		return (in_array($level, $show));
	} /* FeedWordPress::diagnostic_on () */

	function diagnostic ($level, $out, $persist = NULL, $since = NULL, $mostRecent = NULL) {
		global $feedwordpress_admin_footer;

		$output = get_option('feedwordpress_diagnostics_output', array());
		$dlog = get_option('feedwordpress_diagnostics_log', array());

		$diagnostic_nesting = count(explode(":", $level));

		if (FeedWordPress::diagnostic_on($level)) :
			foreach ($output as $method) :
				switch ($method) :
				case 'echo' :
					if (!FeedWordPress::update_requested()) :
						echo "<div><pre><strong>Diag".str_repeat('====', $diagnostic_nesting-1).'|</strong> '.$out."</pre></div>";
					endif;
					break;
				case 'echo_in_cronjob' :
					if (FeedWordPress::update_requested()) :
						echo FeedWordPress::log_prefix()." ".$out."\n";
					endif;
					break;
				case 'admin_footer' :
					$feedwordpress_admin_footer[] = $out;
					break;
				case 'error_log' :
					error_log(FeedWordPress::log_prefix().' '.$out);
					break;
				case 'email' :
					
					if (is_null($persist)) :
						$sect = 'occurrent';
						$hook = (isset($dlog['mesg'][$sect]) ? count($dlog['mesg'][$sect]) : 0);
						$line = array("Time" => time(), "Message" => $out);
					else :
						$sect = 'persistent';
						$hook = md5($level."\n".$persist);
						$line = array("Since" => $since, "Message" => $out, "Most Recent" => $mostRecent);
					endif;
					
					if (!isset($dlog['mesg'])) : $dlog['mesg'] = array(); endif;
					if (!isset($dlog['mesg'][$sect])) : $dlog['mesg'][$sect] = array(); endif;
					
					$dlog['mesg'][$sect][$hook] = $line;
				endswitch;
			endforeach;
		endif;
		
		update_option('feedwordpress_diagnostics_log', $dlog);		
	} /* FeedWordPress::diagnostic () */
	
	function email_diagnostics_override () {
		return ($this->has_secret() and isset($_REQUEST['feedwordpress_email_diagnostics']) and !!$_REQUEST['feedwordpress_email_diagnostics']);
	}
	function has_emailed_diagnostics ($dlog) {
		$ret = false;
		if ($this->email_diagnostics_override()
		or (isset($dlog['schedule']) and isset($dlog['schedule']['last']))) :
			$ret = true;
		endif;
		return $ret;
	}
	function ready_to_email_diagnostics ($dlog) {
		$ret = false;
		if ($this->email_diagnostics_override()
		or (time() > ($dlog['schedule']['last'] + $dlog['schedule']['freq']))) :
			$ret = true;
		endif;
		return $ret;
	}
	
	function email_diagnostic_log ($params = array()) {
		$params = wp_parse_args($params, array(
		"force" => false,
		));

		$dlog = get_option('feedwordpress_diagnostics_log', array());
		
		if ($this->has_emailed_diagnostics($dlog)) :
			if ($this->ready_to_email_diagnostics($dlog)) :
				// No news is good news; only send if
				// there are some messages to send.
				$body = NULL;
				if (!isset($dlog['mesg'])) : $dlog['mesg'] = array(); endif;
				
				foreach ($dlog['mesg'] as $sect => $mesgs) :
					if (count($mesgs) > 0) :
						if (is_null($body)) : $body = ''; endif;
						
						$paradigm = reset($mesgs);
						$body .= "<h2>".ucfirst($sect)." issues</h2>\n"
							."<table>\n"
							."<thead><tr>\n";
						foreach ($paradigm as $col => $value) :
							$body .= '<th scope="col">'.$col."</th>\n";
						endforeach;
						$body .= "</tr></thead>\n"
							."<tbody>\n";
						
						foreach ($mesgs as $line) :
							$body .= "<tr>\n";
							foreach ($line as $col => $cell) :
								if (is_numeric($cell)) :
									$cell = date('j-M-y, h:i a', $cell);
								endif;
								$class = strtolower(preg_replace('/\s+/', '-', $col));
								$body .= "<td class=\"$class\">${cell}</td>";
							endforeach;
							$body .= "</tr>\n";
						endforeach;
						
						$body .= "</tbody>\n</table>\n\n";
					endif;
				endforeach;
				
				$body = apply_filters('feedwordpress_diagnostic_email_body', $body, $dlog);
				if (!is_null($body)) :
					$home = feedwordpress_display_url(get_bloginfo('url'));
					$subj = apply_filters('feedwordpress_diagnostic_email_subject', $home . " syndication issues", $dlog);
					$agent = 'FeedWordPress '.FEEDWORDPRESS_VERSION;
					$body = <<<EOMAIL
<html>
<head>
<title>$subj</title>
<style type="text/css">
	body { background-color: white; color: black; }
	table { width: 100%; border: 1px solid black; }
	table thead tr th { background-color: #ff7700; color: white; border-bottom: 1px solid black; }
	table thead tr { border-bottom: 1px solid black; }
	table tr { vertical-align: top; }
	table .since { width: 20%; }
	table .time { width: 20%; }
	table .most-recently { width: 20%; }
	table .message { width: auto; }
</style>
</head>
<body>
<h1>Syndication Issues encountered by $agent on $home</h1>
$body
</body>
</html>

EOMAIL;

					$ded = get_option('feedwordpress_diagnostics_email_destination', 'admins');

					// e-mail address
					if (preg_match('/^mailto:(.*)$/', $ded, $ref)) :
						$recipients = array($ref[1]);
						
					// userid
					elseif (preg_match('/^user:(.*)$/', $ded, $ref)) :
						$userdata = get_userdata((int) $ref[1]);
						$recipients = array($userdata->user_email);
					
					// admins
					else :
						$recipients = FeedWordPressDiagnostic::admin_emails();
					endif;

					$mesgId = 'feedwordpress+'.time().'@'.$home;
					$parentId = get_option('feedwordpress_diagnostics_email_root_message_id', NULL);

					$head = array("Message-ID: <$mesgId>");
					if (!is_null($parentId)) :
						// We've already sent off a diagnostic message in the past. Let's do some
						// magic to help with threading, in the hopes that all diagnostic messages
						// get threaded together.
						$head[] = "References: <$parentId>";
						$head[] = "In-Reply-To: <$parentId>";
						$subj = "Re: ".$subj;
					else :
						// This is the first of its kind. Let's mark it as such.
						update_option('feedwordpress_diagnostics_email_root_message_id', $mesgId);
					endif;
					$head = apply_filters('feedwordpress_diagnostic_email_headers', $head);

					foreach ($recipients as $email) :						
						add_filter('wp_mail_content_type', array('FeedWordPress', 'allow_html_mail'));
						wp_mail($email, $subj, $body, $head);
						remove_filter('wp_mail_content_type', array('FeedWordPress', 'allow_html_mail'));
					endforeach;
				endif;
				
				// Clear the logs
				$dlog['mesg']['persistent'] = array();
				$dlog['mesg']['occurrent'] = array();
				
				// Set schedule for next update
				$dlog['schedule']['last'] = time();
			endif;
		else :
			$dlog['schedule'] = array(
				'freq' => 24 /*hr*/ * 60 /*min*/ * 60 /*s*/,
				'last' => time(),
			);
		endif;
		
		update_option('feedwordpress_diagnostics_log', $dlog);
	} /* FeedWordPress::email_diagnostic_log () */
	
	function allow_html_mail () {
		return 'text/html';
	} /* FeedWordPress::allow_html_mail () */

	function admin_footer () {
		global $feedwordpress_admin_footer;
		foreach ($feedwordpress_admin_footer as $line) :
			echo '<div><pre>'.$line.'</pre></div>';
		endforeach;
	} /* FeedWordPress::admin_footer () */
	
	function log_prefix ($date = false) {
		$home = get_bloginfo('url');
		$prefix = '['.feedwordpress_display_url($home).'] [feedwordpress] ';
		if ($date) :
			$prefix = "[".date('Y-m-d H:i:s')."]".$prefix;
		endif;
		return $prefix;
	} /* FeedWordPress::log_prefix () */
	
	function menu_cap ($sub = false) {
		if ($sub) :
			$cap = apply_filters('feedwordpress_menu_settings_capacity', 'manage_options');
		else :
			$cap = apply_filters('feedwordpress_menu_main_capacity', 'manage_links');
		endif;
		return $cap;
	} /* FeedWordPress::menu_cap () */
	
	function path ($filename = '') {
		global $fwp_path;
		
		$path = $fwp_path;
		if (strlen($filename) > 0) :
			$path .= '/'.$filename;
		endif;
		return $path;
	}
	
	// These are superceded by MyPHP::param/post/get/request, but kept
	// here for backward compatibility.
	function param ($key, $type = 'REQUEST', $default = NULL) {
		return MyPHP::param($key, $default, $type);
	}
	function post ($key, $default = NULL) {
		return MyPHP::post($key, $default);
	}
} // class FeedWordPress

$feedwordpress_admin_footer = array();

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

