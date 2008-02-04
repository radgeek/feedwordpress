<?php
/*
Plugin Name: FeedWordPress
Plugin URI: http://projects.radgeek.com/feedwordpress
Description: simple and flexible Atom/RSS syndication for WordPress
Version: 0.992a
Author: Charles Johnson
Author URI: http://radgeek.com/
License: GPL
Last modified: 2007-11-29 09:06 PDT
*/

# This uses code derived from:
# -	wp-rss-aggregate.php by Kellan Elliot-McCrea <kellan@protest.net>
# -	HTTP Navigator 2 by Keyvan Minoukadeh <keyvan@k1m.com>
# -	Ultra-Liberal Feed Finder by Mark Pilgrim <mark@diveintomark.org>
# according to the terms of the GNU General Public License.
#
# INSTALLATION: see README.text or <http://projects.radgeek.com/install>
#
# USAGE: once FeedWordPress is installed, you manage just about everything from
# the WordPress Dashboard, under Options --> Syndication or Links --> Syndicated
# To ensure that fresh content is added as it becomes available, get your
# contributors to put your XML-RPC URI (if WordPress is installed at
# <http://www.zyx.com/blog>, XML-RPC requests should be sent to
# <http://www.zyx.com/blog/xmlrpc.php>), or see `update-feeds.php`	

# -- Don't change these unless you know what you're doing...

define ('FEEDWORDPRESS_VERSION', '0.992a');
define ('FEEDWORDPRESS_AUTHOR_CONTACT', 'http://radgeek.com/contact');
define ('DEFAULT_SYNDICATION_CATEGORY', 'Contributors');

define ('FEEDWORDPRESS_DEBUG', false);

define ('FEEDWORDPRESS_CAT_SEPARATOR_PATTERN', '/[:\n]/');
define ('FEEDWORDPRESS_CAT_SEPARATOR', "\n");

define ('FEEDVALIDATOR_URI', 'http://feedvalidator.org/check.cgi');

define ('FEEDWORDPRESS_FRESHNESS_INTERVAL', 10*60); // Every ten minutes

define ('FWP_SCHEMA_20', 3308); // Database schema # for WP 2.0
define ('FWP_SCHEMA_21', 4772); // Database schema # for WP 2.1
define ('FWP_SCHEMA_23', 5495); // Database schema # for WP 2.3

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

if (function_exists('wp_enqueue_script')) :
	wp_enqueue_script( 'ajaxcat' ); // Provides the handy-dandy new category text box
endif;

// Magic quotes are just about the stupidest thing ever.
if (is_array($_POST)) :
	$fwp_post = stripslashes_deep($_POST);
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
	
	# Filter in original permalinks if the user wants that
	add_filter('post_link', 'syndication_permalink', 1);
	
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
## LEGACY API: Replicate or mock up functions for legacy support purposes ######
################################################################################

if (!function_exists('get_option')) {
	function get_option ($option) {
		return get_settings($option);
	}
}
if (!function_exists('current_user_can')) {
	$legacy_capability_hack = true;
	function current_user_can ($task) {
		global $user_level;

		$can = false;

		// This is **not** a full replacement for current_user_can. It
		// is only for checking the capabilities we care about via the
		// WordPress 1.5 user levels.
		switch ($task) {
		case 'manage_options':
			$can = ($user_level >= 6);
			break;
		case 'manage_links':
			$can = ($user_level >= 5);
			break;
		} /* switch */
		return $can;
	}
} else {
	$legacy_capability_hack = false;
}
if (!function_exists('sanitize_user')) {
	function sanitize_user ($text, $strict) {
		return $text; // Don't munge it if it wasn't munged going in...
	}
}
if (!function_exists('wp_insert_user')) {
	function wp_insert_user ($userdata) {
		#-- Help WordPress 1.5.x quack like a duck
		$login = $userdata['user_login'];
		$author = $userdata['display_name'];
		$nice_author = $userdata['user_nicename'];
		$email = $userdata['user_email'];
		$url = $userdata['user_url'];

		$wpdb->query (
			"INSERT INTO $wpdb->users
			 SET
				ID='0',
				user_login='$login',
				user_firstname='$author',
				user_nickname='$author',
				user_nicename='$nice_author',
				user_description='$author',
				user_email='$email',
				user_url='$url'");
		$id = $wpdb->insert_id;
		
		return $id;
	}
}
################################################################################
## TEMPLATE API: functions to make your templates syndication-aware ############
################################################################################

function is_syndicated () { return (strlen(get_syndication_feed_id()) > 0); }

function the_syndication_source_link () { echo get_syndication_source_link(); }
function get_syndication_source_link () { list($n) = get_post_custom_values('syndication_source_uri'); return $n; }

function get_syndication_source () { list($n) = get_post_custom_values('syndication_source'); return $n; }
function the_syndication_source () { echo get_syndication_source(); }

function get_syndication_feed () { list($u) = get_post_custom_values('syndication_feed'); return $u; }
function the_syndication_feed () { echo get_syndication_feed (); }

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

	if ( is_syndicated() ) :
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

function syndication_permalink ($permalink = '') {
	if (get_option('feedwordpress_munge_permalink') != 'no'):
		$uri = get_syndication_permalink();
		return ((strlen($uri) > 0) ? $uri : $permalink);
	else:
		return $permalink;
	endif;
} // function syndication_permalink ()

################################################################################
## UPGRADE INTERFACE: Have users upgrade DB from older versions of FWP #########
################################################################################

function fwp_upgrade_page () {
	if (isset($GLOBALS['fwp_post']['action']) and $GLOBALS['fwp_post']['action']=='Upgrade') :
		$ver = get_option('feedwordpress_version');
		if (get_option('feedwordpress_version') != FEEDWORDPRESS_VERSION) :
			echo "<div class=\"wrap\">\n";
			echo "<h2>Upgrading FeedWordPress...</h2>";

			$feedwordpress =& new FeedWordPress;
			$feedwordpress->upgrade_database();
			echo "<p><strong>Done!</strong> Upgraded database to version ".FEEDWORDPRESS_VERSION.".</p>\n";
			echo "<form action=\"\" method=\"get\">\n";
			echo "<div class=\"submit\"><input type=\"hidden\" name=\"page\" value=\"".basename(__FILE__)."\" />";
			echo "<input type=\"submit\" value=\"Continue &raquo;\" /></form></div>\n";
			echo "</div>\n";
			return;
		else :
			echo "<div class=\"updated\"><p>Already at version ".FEEDWORDPRESS_VERSION."!</p></div>";
		endif;
	endif;
?>
<div class="wrap">
<h2>Upgrade FeedWordPress</h2>

<p>It appears that you have installed FeedWordPress
<?php echo FEEDWORDPRESS_VERSION; ?> as an upgrade to an existing installation of
FeedWordPress. That's no problem, but you will need to take a minute out first
to upgrade your database: some necessary changes in how the software keeps
track of posts and feeds will cause problems such as duplicate posts and broken
templates if we were to continue without the upgrade.</p>

<p>Note that most of FeedWordPress's functionality is temporarily disabled
until we have successfully completed the upgrade. Everything should begin
working as normal again once the upgrade is complete. There's extraordinarily
little chance of any damage as the result of the upgrade, but if you're paranoid
like me you may want to back up your database before you proceed.</p>

<p>This may take several minutes for a large installation.</p>

<form action="" method="post">
<div class="submit"><input type="submit" name="action" value="Upgrade" /></div>
</form>
</div>
<?php
} // function fwp_upgrade_page ()

################################################################################
## ADMIN MENU ADD-ONS: implement Dashboard management pages ####################
################################################################################

function fwp_add_pages () {
	global $legacy_capability_hack;

	if ($legacy_capability_hack) :
		// old & busted: numeric user levels
		$manage_links = 5;
		$manage_options = 6;
	else :
		// new hotness: named capabilities
		$manage_links = 'manage_links';
		$manage_options = 'manage_options';
	endif;

	//add_submenu_page('plugins.php', 'Akismet Configuration', 'Akismet Configuration', 'manage_options', 'syndication-manage-page', 'fwp_syndication_manage_page');
	add_menu_page('Syndicated Sites', 'Syndication', $manage_links, 'feedwordpress/'.basename(__FILE__), 'fwp_syndication_manage_page');
	add_submenu_page('feedwordpress/'.basename(__FILE__), 'Syndication Options', 'Options', $manage_options, 'feedwordpress/syndication-options.php');
	add_options_page('Syndication Options', 'Syndication', $manage_options, 'feedwordpress/syndication-options.php');
} // function fwp_add_pages () */

function fwp_category_box ($checked, $object) {
	global $wp_db_version;

	if (isset($wp_db_version) and $wp_db_version >= FWP_SCHEMA_20) : // WordPress 2.x
?>
		<div id="poststuff">
		
		<div id="moremeta">
		<div id="grabit" class="dbx-group">
			<fieldset id="categorydiv" class="dbx-box">
			<h3 class="dbx-handle"><?php _e('Categories') ?></h3>
			<div class="dbx-content">
			<p style="font-size:smaller;font-style:bold;margin:0">Place <?php print $object; ?> under...</p>
			<p id="jaxcat"></p>
			<ul id="categorychecklist"><?php write_nested_categories($checked); ?></ul></div>
			</fieldset>
		</div>
		</div>
		</div>
<?php
	else : // WordPress 1.5
?>
		<fieldset id="categorydiv" style="width: 20%; margin-right: 2em">
		<legend><?php _e('Categories') ?></legend>
		<p style="font-size:smaller;font-style:bold;margin:0">Place <?php print $object; ?> under...</p>
		<div style="height: 20em"><?php write_nested_categories($checked); ?></div>
		</fieldset>
<?php
	endif;
}

function update_feeds_mention ($feed) {
	echo "<li>Updating <cite>".$feed['link/name']."</cite> from &lt;<a href=\""
		.$feed['link/uri']."\">".$feed['link/uri']."</a>&gt; ...</li>\n";
	flush();
}

function fwp_syndication_manage_page () {
	global $wpdb;

	if (FeedWordPress::needs_upgrade()) :
		fwp_upgrade_page();
		return;
	endif;

?>
<?php $cont = true;
if (isset($_REQUEST['action'])):
	if ($_REQUEST['action'] == 'feedfinder') : $cont = fwp_feedfinder_page();
	elseif ($_REQUEST['action'] == 'switchfeed') : $cont = fwp_switchfeed_page();
	elseif ($_REQUEST['action'] == 'linkedit') : $cont = fwp_linkedit_page();
	elseif ($_REQUEST['action'] == 'Unsubscribe from Checked Links' or $_REQUEST['action'] == 'Unsubscribe') : $cont = fwp_multidelete_page();
	endif;
endif;

if ($cont):
?>
<?php
	$links = FeedWordPress::syndicated_links();

	if (isset($_POST['update']) or isset($_POST['action']) or isset($_POST['update_uri'])) :
		$fwp_update_invoke = 'post';
	else :
		$fwp_update_invoke = 'get';
	endif;

	$update_set = array();
	if (isset($_POST['link_ids']) and is_array($_POST['link_ids']) and ($_POST['action']=='Update Checked Links')) :
		$targets = $wpdb->get_results("
			SELECT * FROM $wpdb->links
			WHERE link_id IN (".implode(",",$_POST['link_ids']).")
			");
		if (is_array($targets)) :
			foreach ($targets as $target) :
				$update_set[] = $target->link_rss;
			endforeach;
		else : // This should never happen
			FeedWordPress::critical_bug('fwp_syndication_manage_page::targets', $targets, __LINE__);
		endif;
	elseif (isset($_POST['update_uri'])) :
		$update_set[] = $_POST['update_uri'];
	endif;

	if ($fwp_update_invoke != 'get' and count($update_set) > 0) : // Only do things with side-effects for HTTP POST or command line
		$feedwordpress =& new FeedWordPress;
		add_action('feedwordpress_check_feed', 'update_feeds_mention');
		
		echo "<div class=\"updated\">\n";
		echo "<ul>\n";
		$tdelta = NULL;
		foreach ($update_set as $uri) :
			if ($uri == '*') : $uri = NULL; endif;
			$delta = $feedwordpress->update($uri);
			if (!is_null($delta)) :
				if (is_null($tdelta)) :
					$tdelta = $delta;
				else :
					$tdelta['new'] += $delta['new'];
					$tdelta['updated'] += $delta['updated'];
				endif;
			else :
				echo "<li><p><strong>Error:</strong> There was a problem updating <a href=\"$uri\">$uri</a></p></li>\n";
			endif;
		endforeach;
		echo "</ul>\n";

		if (!is_null($tdelta)) :
			$mesg = array();
			if (isset($delta['new'])) : $mesg[] = ' '.$tdelta['new'].' new posts were syndicated'; endif;
			if (isset($delta['updated'])) : $mesg[] = ' '.$tdelta['updated'].' existing posts were updated'; endif;
			echo "<p>Update complete.".implode(' and', $mesg)."</p>";
			echo "\n"; flush();
		endif;
		echo "</div> <!-- class=\"updated\" -->\n";
	endif;

	?>
	<div class="wrap">
	<form action="" method="POST">
	<h2>Update feeds now</h2>
	<p>Check currently scheduled feeds for new and updated posts.</p>

<?php 	if (!get_option('feedwordpress_automatic_updates')) : ?>
	<p><strong>Note:</strong> Automatic updates are currently turned
	<strong>off</strong>. New posts from your feeds will not be syndicated
	until you manually check for them here. You can turn on automatic
	updates under <a href="admin.php?page=feedwordpress/syndication-options.php">Syndication
	Options</a>.</p>
<?php 	endif; ?>
	

	<div class="submit"><input type="hidden" name="update_uri" value="*" /><input type="submit" name="update" value="Update" /></div>
	</form>
	</div> <!-- class="wrap" -->

	<form action="admin.php?page=feedwordpress/<?php echo basename(__FILE__); ?>" method="post">
	<div class="wrap">
	<h2>Syndicated Sites</h2>
<?php	$alt_row = true;
	if ($links): ?>

<table width="100%" cellpadding="3" cellspacing="3">
<tr>
<th width="20%"><?php _e('Name'); ?></th>
<th width="40%"><?php _e('Feed'); ?></th>
<th colspan="4"><?php _e('Action'); ?></th>
</tr>

<?php		foreach ($links as $link):
			$alt_row = !$alt_row; ?>
<tr<?php echo ($alt_row?' class="alternate"':''); ?>>
<td><a href="<?php echo wp_specialchars($link->link_url, 'both'); ?>"><?php echo wp_specialchars($link->link_name, 'both'); ?></a></td>
<?php 
			if (strlen($link->link_rss) > 0):
				$caption='Switch Feed';
				$uri_bits = parse_url($link->link_rss);
				$uri_bits['host'] = preg_replace('/^www\./i', '', $uri_bits['host']);
				$display_uri =
					(isset($uri_bits['user'])?$uri_bits['user'].'@':'')
					.(isset($uri_bits['host'])?$uri_bits['host']:'')
					.(isset($uri_bits['port'])?':'.$uri_bits['port']:'')
					.(isset($uri_bits['path'])?$uri_bits['path']:'')
					.(isset($uri_bits['query'])?'?'.$uri_bits['query']:'');
				if (strlen($display_uri) > 32) : $display_uri = substr($display_uri, 0, 32).'&#8230;'; endif;
?>
				<td>
				<strong><a href="<?php echo $link->link_rss; ?>"><?php echo wp_specialchars($display_uri, 'both'); ?></a></strong></td>
<?php
			else:
				$caption='Find Feed';
?>
				<td style="background-color:#FFFFD0"><p><strong>no
				feed assigned</strong></p></td>
<?php
			endif;
?>
			<td><a href="admin.php?page=feedwordpress/<?php echo basename(__FILE__); ?>&amp;link_id=<?php echo $link->link_id; ?>&amp;action=linkedit" class="edit"><?php _e('Edit')?></a></td>
			<td><a href="admin.php?page=feedwordpress/<?php echo basename(__FILE__); ?>&amp;link_id=<?php echo $link->link_id; ?>&amp;action=feedfinder" class="edit"><?php echo $caption; ?></a></td>
			<td><a href="admin.php?page=feedwordpress/<?php echo basename(__FILE__); ?>&amp;link_id=<?php echo $link->link_id; ?>&amp;action=Unsubscribe" class="delete"><?php _e('Unsubscribe'); ?></a></td>
			<td><input type="checkbox" name="link_ids[]" value="<?php echo $link->link_id; ?>" /></td>
<?php
			echo "\n\t</tr>";
		endforeach;
	else:
?>

<p>There are no websites currently listed for syndication.</p>

<?php	endif; ?>
	</table>

	<br/><hr/>
	<div class="submit"><input type="submit" class="delete" name="action" value="Unsubscribe from Checked Links" />
	<input type="submit" name="action" value="Update Checked Links" /></div>
	</div> <!-- class="wrap" -->
	</form>

	<div class="wrap">
	<form action="admin.php?page=feedwordpress/<?php echo basename(__FILE__); ?>" method="post">
	<h2>Add a new syndicated site:</h2>
	<div>
	<label for="add-uri">Website or newsfeed:</label>
	<input type="text" name="lookup" id="add-uri" value="URI" size="64" />
	<input type="hidden" name="action" value="feedfinder" />
	</div>
	<div class="submit"><input type="submit" value="Syndicate &raquo;" /></div>
	</form>
	</div> <!-- class="wrap" -->
<?php
endif;
}

function fwp_feedfinder_page () {
	global $wpdb;

	$lookup = (isset($_REQUEST['lookup'])?$_REQUEST['lookup']:NULL);

	if (isset($_REQUEST['link_id']) and ($_REQUEST['link_id']!=0)):
		$link_id = $_REQUEST['link_id'];
		if (!is_numeric($link_id)) : FeedWordPress::critical_bug('fwp_feedfinder_page::link_id', $link_id, __LINE__); endif;
		
		$link = $wpdb->get_row("SELECT * FROM $wpdb->links WHERE link_id='".$wpdb->escape($link_id)."'");
		if (is_object($link)):
			if (is_null($lookup)) $lookup = $link->link_url;
			$name = wp_specialchars($link->link_name, 'both');
		else:
			die (__("Cheatin' uh ?"));
		endif;
	else:
		$name = "New Syndicated Feed";
		$link_id = 0;
	endif;
?>
	<div class="wrap">
	<h2>Feed Finder: <?php echo $name; ?></h2>
<?php
	$f =& new FeedFinder($lookup);
	$feeds = $f->find();
	if (count($feeds) > 0):
		foreach ($feeds as $key => $f):
			$rss = fetch_rss($f);
			if ($rss):
				$feed_title = isset($rss->channel['title'])?$rss->channel['title']:$rss->channel['link'];
				$feed_link = isset($rss->channel['link'])?$rss->channel['link']:'';
?>
				<form action="admin.php?page=feedwordpress/<?php echo basename(__FILE__); ?>" method="post">
				<fieldset style="clear: both">
				<legend><?php echo $rss->feed_type; ?> <?php echo $rss->feed_version; ?> feed</legend>

				<?php if ($link_id===0): ?>
					<input type="hidden" name="feed_title" value="<?php echo wp_specialchars($feed_title, 'both'); ?>" />
					<input type="hidden" name="feed_link" value="<?php echo wp_specialchars($feed_link, 'both'); ?>" />
				<?php endif; ?>

				<input type="hidden" name="link_id" value="<?php echo $link_id; ?>" />
				<input type="hidden" name="feed" value="<?php echo wp_specialchars($f, 'both'); ?>" />
				<input type="hidden" name="action" value="switchfeed" />

				<div>
				<div style="float:right; background-color:#D0D0D0; color: black; width:45%; font-size:70%; border-left: 1px dotted #A0A0A0; padding-left: 0.5em; margin-left: 1.0em">
<?php				if (count($rss->items) > 0): ?>
					<?php $item = $rss->items[0]; ?>
					<h3>Sample Item</h3>
					<ul>
					<li><strong>Title:</strong> <a href="<?php echo $item['link']; ?>"><?php echo $item['title']; ?></a></li>
					<li><strong>Date:</strong> <?php echo isset($item['date_timestamp']) ? date('d-M-y g:i:s a', $item['date_timestamp']) : 'unknown'; ?></li>
					</ul>
					<div class="entry">
					<?php echo (isset($item['content']['encoded'])?$item['content']['encoded']:$item['description']); ?>
					</div>
<?php				else: ?>
					<h3>No Items</h3>
					<p>FeedWordPress found no posts on this feed.</p>
<?php				endif; ?>
				</div>

				<div>
				<h3>Feed Information</h3>
				<ul>
				<li><strong>Website:</strong> <a href="<?php echo $feed_link; ?>"><?php echo is_null($feed_title)?'<em>Unknown</em>':$feed_title; ?></a></li>
				<li><strong>Feed URI:</strong> <a href="<?php echo wp_specialchars($f, 'both'); ?>"><?php echo wp_specialchars($f, 'both'); ?></a> (<a title="Check feed &lt;<?php echo wp_specialchars($f, 'both'); ?>&gt; for validity" href="http://feedvalidator.org/check.cgi?url=<?php echo urlencode($f); ?>">validate</a>)</li>
				<li><strong>Encoding:</strong> <?php echo isset($rss->encoding)?wp_specialchars($rss->encoding, 'both'):"<em>Unknown</em>"; ?></li>
				<li><strong>Description:</strong> <?php echo isset($rss->channel['description'])?wp_specialchars($rss->channel['description'], 'both'):"<em>Unknown</em>"; ?></li>
				</ul>
				<div class="submit"><input type="submit" name="Use" value="&laquo; Use this feed" /></div>
				<div class="submit"><input type="submit" name="Cancel" value="&laquo; Cancel" /></div>
				</div>
				</div>
				</fieldset>
				</form>
<?php			endif;
		endforeach;
	else:
		echo "<p><strong>no feed found</strong></p>";
	endif;
?>
	</div>

	<form action="admin.php?page=feedwordpress/<?php echo basename(__FILE__); ?>" method="post">
	<div class="wrap">
	<h2>Use another feed</h2>
	<div><label>Feed:</label>
	<input type="text" name="lookup" value="URI" />
	<input type="hidden" name="link_id" value="<?php echo $link_id; ?>" />
	<input type="hidden" name="action" value="feedfinder" /></div>
	<div class="submit"><input type="submit" value="Use this feed &raquo;" /></div>
	</div>
	</form>
<?php
	return false; // Don't continue
}

function fwp_switchfeed_page () {
	global $wpdb, $wp_db_version;

	check_admin_referer();
	if (!isset($_REQUEST['Cancel'])):
		if (!current_user_can('manage_links')):
			die (__("Cheatin' uh ?"));
		elseif (isset($_REQUEST['link_id']) and ($_REQUEST['link_id']==0)):
			$link_id = FeedWordPress::syndicate_link($_REQUEST['feed_title'], $_REQUEST['feed_link'], $_REQUEST['feed']);
			if ($link_id): ?>
<div class="updated"><p><a href="<?php echo $_REQUEST['feed_link']; ?>"><?php echo wp_specialchars($_REQUEST['feed_title'], 'both'); ?></a>
has been added as a contributing site, using the newsfeed at &lt;<a href="<?php echo $_REQUEST['feed']; ?>"><?php echo wp_specialchars($_REQUEST['feed'], 'both'); ?></a>&gt;.</p></div>
<?php			else: ?>
<div class="updated"><p>There was a problem adding the newsfeed. [SQL: <?php echo wp_specialchars(mysql_error(), 'both'); ?>]</p></div>
<?php			endif;
		elseif (isset($_REQUEST['link_id'])):
			// Update link_rss
			$result = $wpdb->query("
			UPDATE $wpdb->links
			SET
				link_rss = '".$wpdb->escape($_REQUEST['feed'])."'
			WHERE link_id = '".$wpdb->escape($_REQUEST['link_id'])."'
			");
			
			if ($result):
				$result = $wpdb->get_row("
				SELECT link_name, link_url FROM $wpdb->links
				WHERE link_id = '".$wpdb->escape($_REQUEST['link_id'])."'
				");
			?> 
<div class="updated"><p>Feed for <a href="<?php echo $result->link_url; ?>"><?php echo wp_specialchars($result->link_name, 'both'); ?></a>
updated to &lt;<a href="<?php echo $_REQUEST['feed']; ?>"><?php echo wp_specialchars($_REQUEST['feed'], 'both'); ?></a>&gt;.</p></div>
			<?php else: ?>
<div class="updated"><p>Nothing was changed.</p></div>
			<?php endif;
		endif;
	endif;
	return true; // Continue
}

function fwp_linkedit_page () {
	global $wpdb;

	check_admin_referer(); // Make sure we arrived here from the Dashboard

	$special_settings = array ( /* Regular expression syntax is OK here */
		'cats',
		'cat_split',
		'hardcode name',
		'hardcode url',
		'hardcode description',
		'hardcode categories', /* Deprecated */
		'post status',
		'comment status',
		'ping status',
		'unfamiliar author',
		'unfamliar categories',
		'map authors',
		'update/.*',
		'feed/.*',
		'link/.*',
	);

	if (!current_user_can('manage_links')) :
		die (__("Cheatin' uh ?"));
	elseif (isset($_REQUEST['feedfinder'])) :
		return fwp_feedfinder_page(); // re-route to Feed Finder page
	else :
		$link_id = (int) $_REQUEST['link_id'];
		$link =& new SyndicatedLink($link_id);

		if ($link->found()) :
			if (isset($GLOBALS['fwp_post']['save'])) :
				$alter = array ();
				
				$meta = $link->settings;
				//if (isset($meta['cats'])):
				//	$meta['cats'] = preg_split(FEEDWORDPRESS_CAT_SEPARATOR_PATTERN, $meta['cats']);
				//endif;

				// custom feed settings first
				foreach ($GLOBALS['fwp_post']['notes'] as $mn) :
					$mn['key0'] = trim($mn['key0']);
					$mn['key1'] = trim($mn['key1']);
					if (preg_match("\007^(("
							.implode(')|(',$special_settings)
							."))$\007i",
							$mn['key1'])) :
						$mn['key1'] = 'user/'.$mn['key1'];
					endif;

					if (strlen($mn['key0']) > 0) :
						unset($meta[$mn['key0']]); // out with the old
					endif;
					
					if (($mn['action']=='update') and (strlen($mn['key1']) > 0)) :
						$meta[$mn['key1']] = $mn['value']; // in with the new
					endif;
				endforeach;
				
				// now stuff through the web form
				// hardcoded feed info
				if (isset($GLOBALS['fwp_post']['hardcode_name'])) :
					$meta['hardcode name'] = $GLOBALS['fwp_post']['hardcode_name'];
					if (FeedWordPress::affirmative($meta, 'hardcode name')) :
						$alter[] = "link_name = '".$wpdb->escape($GLOBALS['fwp_post']['name'])."'";
					endif;
				endif;
				if (isset($GLOBALS['fwp_post']['hardcode_description'])) :
					$meta['hardcode description'] = $GLOBALS['fwp_post']['hardcode_description'];
					if (FeedWordPress::affirmative($meta, 'hardcode description')) :
						$alter[] = "link_description = '".$wpdb->escape($GLOBALS['fwp_post']['description'])."'";
					endif;
				endif;
				if (isset($GLOBALS['fwp_post']['hardcode_url'])) :
					$meta['hardcode url'] = $GLOBALS['fwp_post']['hardcode_url'];
					if (FeedWordPress::affirmative($meta, 'hardcode url')) :
						$alter[] = "link_url = '".$wpdb->escape($GLOBALS['fwp_post']['linkurl'])."'";
					endif;
				endif;
				
				// Update scheduling
				if (isset($GLOBALS['fwp_post']['update_schedule'])) :
					$meta['update/hold'] = $GLOBALS['fwp_post']['update_schedule'];
				endif;

				// Categories
				if (isset($GLOBALS['fwp_post']['post_category'])) :
					$meta['cats'] = array();
					foreach ($GLOBALS['fwp_post']['post_category'] as $cat_id) :
						$meta['cats'][] = '{#'.$cat_id.'}';
					endforeach;
				else :
					unset($meta['cats']);
				endif;

				// Post status, comment status, ping status
				foreach (array('post', 'comment', 'ping') as $what) :
					$sfield = "feed_{$what}_status";
					if (isset($GLOBALS['fwp_post'][$sfield])) :
						if ($GLOBALS['fwp_post'][$sfield]=='site-default') :
							unset($meta["{$what} status"]);
						else :
							$meta["{$what} status"] = $GLOBALS['fwp_post'][$sfield];
						endif;
					endif;
				endforeach;

				// Unfamiliar author, unfamiliar categories
				foreach (array("author", "category") as $what) :
					$sfield = "unfamiliar_{$what}";
					if (isset($GLOBALS['fwp_post'][$sfield])) :
						if ('site-default'==$GLOBALS['fwp_post'][$sfield]) :
							unset($meta["unfamiliar {$what}"]);
						elseif ('newuser'==$GLOBALS['fwp_post'][$sfield]) :
							$newuser_name = trim($GLOBALS['fwp_post']["{$sfield}_newuser"]);
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
									$meta["unfamiliar {$what}"] = $newuser_id;
								else :
									// TODO: Add some error detection and reporting
								endif;
							else :
								// TODO: Add some error reporting
							endif;
						else :
							$meta["unfamiliar {$what}"] = $GLOBALS['fwp_post'][$sfield];
						endif;
					endif;
				endforeach;
				
				// Handle author mapping rules
				if (isset($GLOBALS['fwp_post']['author_rules_name']) and isset($GLOBALS['fwp_post']['author_rules_action'])) :
					unset($meta['map authors']);
					foreach ($GLOBALS['fwp_post']['author_rules_name'] as $key => $name) :
						// Normalize for case and whitespace
						$name = strtolower(trim($name));
						$author_action = strtolower(trim($GLOBALS['fwp_post']['author_rules_action'][$key]));
						
						if (strlen($name) > 0) :
							if ('newuser' == $author_action) :
								$newuser_name = trim($GLOBALS['fwp_post']['author_rules_newuser'][$key]);
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
										$meta['map authors']['name'][$name] = $newuser_id;
									else :
										// TODO: Add some error detection and reporting
									endif;
								else :
									// TODO: Add some error reporting
								endif;
							else :
								$meta['map authors']['name'][$name] = $author_action;
							endif;
						endif;
					endforeach;
				endif;

				if (isset($GLOBALS['fwp_post']['add_author_rule_name']) and isset($GLOBALS['fwp_post']['add_author_rule_action'])) :
					$name = strtolower(trim($GLOBALS['fwp_post']['add_author_rule_name']));
					$author_action = strtolower(trim($GLOBALS['fwp_post']['add_author_rule_action']));
					if (strlen($name) > 0) :
						if ('newuser' == $author_action) :
							$newuser_name = trim($GLOBALS['fwp_post']['add_author_rule_newuser']);
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
									$meta['map authors']['name'][$name] = $newuser_id;
								else :
									// TODO: Add some error detection and reporting
								endif;
							else :
								// TODO: Add some error reporting
							endif;
						else :
							$meta['map authors']['name'][$name] = $author_action;
						endif;
					endif;
				endif;

				if (isset($GLOBALS['fwp_post']['cat_split'])) :
					if (strlen(trim($GLOBALS['fwp_post']['cat_split'])) > 0) :
						$meta['cat_split'] = trim($GLOBALS['fwp_post']['cat_split']);
					else :
						unset($meta['cat_split']);
					endif;
				endif;

				if (is_array($meta['cats'])) :
					$meta['cats'] = implode(FEEDWORDPRESS_CAT_SEPARATOR, $meta['cats']);
				endif;

				// Collapse the author mapping rule structure back into a flat string
				if (isset($meta['map authors'])) :
					$ma = array();
					foreach ($meta['map authors'] as $rule_type => $author_rules) :
						foreach ($author_rules as $author_name => $author_action) :
							$ma[] = $rule_type."\n".$author_name."\n".$author_action;
						endforeach;
					endforeach;
					$meta['map authors'] = implode("\n\n", $ma);
				endif;
				
				$notes = '';
				foreach ($meta as $key => $value) :
					$notes .= $key . ": ". addcslashes($value, "\0..\37".'\\') . "\n";
				endforeach;
				$alter[] = "link_notes = '".$wpdb->escape($notes)."'";

				$alter_set = implode(", ", $alter);

				// issue update query
				$result = $wpdb->query("
				UPDATE $wpdb->links
				SET $alter_set
				WHERE link_id='$link_id'
				");
				$updated_link = true;

				// reload link information from DB
				$link =& new SyndicatedLink($link_id);
			else :
				$updated_link = false;
			endif;

			$db_link = $link->link;
			$link_url = wp_specialchars($db_link->link_url, 1);
			$link_name = wp_specialchars($db_link->link_name, 1);
			$link_description = wp_specialchars($db_link->link_description, 'both');
			$link_notes = wp_specialchars($db_link->link_notes, 'both');
			$link_rss_uri = wp_specialchars($db_link->link_rss, 'both');
			
			$meta = $link->settings;
			$post_status_global = get_option('feedwordpress_syndicated_post_status');
			$comment_status_global = get_option('feedwordpress_syndicated_comment_status');
			$ping_status_global = get_option('feedwordpress_syndicated_ping_status');
			
			$status['post'] = array('publish' => '', 'private' => '', 'draft' => '', 'site-default' => '');
			$status['comment'] = array('open' => '', 'closed' => '', 'site-default' => '');
			$status['ping'] = array('open' => '', 'closed' => '', 'site-default' => '');

			foreach (array('post', 'comment', 'ping') as $what) :
				if (isset($meta["{$what} status"])) :
					$status[$what][$meta["{$what} status"]] = ' checked="checked"';
				else :
					$status[$what]['site-default'] = ' checked="checked"';
				endif;
			endforeach;

			$unfamiliar['author'] = array ('create' => '','default' => '','filter' => '');
			$unfamiliar['category'] = array ('create'=>'','default'=>'','filter'=>'');

			foreach (array('author', 'category') as $what) :
				if (is_string($meta["unfamiliar {$what}"]) and
				array_key_exists($meta["unfamiliar {$what}"], $unfamiliar[$what])) :
					$key = $meta["unfamiliar {$what}"];
				else:
					$key = 'site-default';
				endif;
				$unfamiliar[$what][$key] = ' checked="checked"';
			endforeach;

			$dogs = get_nested_categories(-1, 0);
			if (is_array($meta['cats'])) :
				$cats = array_map('strtolower',
					array_map('trim',
						$meta['cats']
					));
			else:
				$cats = array();
			endif;
			
			foreach ($dogs as $tag => $dog) :
				$found_by_name = in_array(strtolower(trim($dog['cat_name'])), $cats);
				
				if (isset($dog['cat_ID'])) : $dog['cat_id'] = $dog['cat_ID']; endif;
				$found_by_id = in_array('{#'.trim($dog['cat_id']).'}', $cats);

				if ($found_by_name or $found_by_id) :
					$dogs[$tag]['checked'] = true;
				endif;
			endforeach;
		else :
			die( __('Link not found.') ); 
		endif;

	?>
<script type="text/javascript">
	function flip_hardcode (item) {
		ed=document.getElementById('basics-'+item+'-edit');
		view=document.getElementById('basics-'+item+'-view');
		
		o = document.getElementById('basics-hardcode-'+item);
		if (o.value=='yes') { ed.style.display='inline'; view.style.display='none'; }
		else { ed.style.display='none'; view.style.display='inline'; }
	}
	function flip_newuser (item) {
		rollup=document.getElementById(item);
		newuser=document.getElementById(item+'-newuser');
		sitewide=document.getElementById(item+'-default');
		if (rollup) {
			if ('newuser'==rollup.value) {
				if (newuser) newuser.style.display='block';
				if (sitewide) sitewide.style.display='none';
			} else if ('site-default'==rollup.value) {
				if (newuser) newuser.style.display='none';
				if (sitewide) sitewide.style.display='block';
			} else {
				if (newuser) newuser.style.display='none';
				if (sitewide) sitewide.style.display='none';
			}
		}
	}
</script>

<?php if ($updated_link) : ?>
<div class="updated"><p>Syndicated feed settings updated.</p></div>
<?php endif; ?>

<form action="admin.php?page=feedwordpress/<?php echo basename(__FILE__); ?>" method="post">
<div class="wrap">
<input type="hidden" name="link_id" value="<?php echo $link_id; ?>" />
<input type="hidden" name="action" value="linkedit" />
<input type="hidden" name="save" value="link" />

<h2>Edit a syndicated feed:</h2>
<fieldset class="options"><legend>Basics</legend>
<table class="editform" width="100%" cellspacing="2" cellpadding="5">
<tr>
<th scope="row" width="20%"><?php _e('Feed URI:') ?></th>
<td width="60%"><a href="<?php echo wp_specialchars($link_rss_uri, 'both'); ?>"><?php echo $link_rss_uri; ?></a>
(<a href="<?php echo FEEDVALIDATOR_URI; ?>?url=<?php echo urlencode($link_rss_uri); ?>"
title="Check feed &lt;<?php echo wp_specialchars($link_rss_uri, 'both'); ?>&gt; for validity">validate</a>)
</td>
<td width="20%"><input type="submit" name="feedfinder" value="switch &rarr;" style="font-size:smaller" /></td>
</tr>
<tr>
<th scope="row" width="20%"><?php _e('Link Name:') ?></th>
<td width="60%"><input type="text" id="basics-name-edit" name="name"
value="<?php echo $link_name; ?>" style="width: 95%" />
<span id="basics-name-view"><strong><?php echo $link_name; ?></strong></span>
</td>
<td>
<select id="basics-hardcode-name" onchange="flip_hardcode('name')" name="hardcode_name">
<option value="no" <?php echo $link->hardcode('name')?'':'selected="selected"'; ?>>update automatically</option>
<option value="yes" <?php echo $link->hardcode('name')?'selected="selected"':''; ?>>edit manually</option>
</select>
</td>
</tr>
<tr>
<th scope="row" width="20%"><?php _e('Short description:') ?></th>
<td width="60%">
<input id="basics-description-edit" type="text" name="description" value="<?php echo $link_description; ?>" style="width: 95%" />
<span id="basics-description-view"><strong><?php echo $link_description; ?></strong></span>
</td>
<td>
<select id="basics-hardcode-description" onchange="flip_hardcode('description')"
name="hardcode_description">
<option value="no" <?php echo $link->hardcode('description')?'':'selected="selected"'; ?>>update automatically</option>
<option value="yes" <?php echo $link->hardcode('description')?'selected="selected"':''; ?>>edit manually</option>
</select></td>
</tr>
<tr>
<th width="20%" scope="row"><?php _e('Homepage:') ?></th>
<td width="60%">
<input id="basics-url-edit" type="text" name="linkurl" value="<?php echo $link_url; ?>" style="width: 95%;" />
<a id="basics-url-view" href="<?php echo $link_url; ?>"><?php echo $link_url; ?></a></td>
<td>
<select id="basics-hardcode-url" onchange="flip_hardcode('url')" name="hardcode_url">
<option value="no"<?php echo $link->hardcode('url')?'':' selected="selected"'; ?>>update automatically</option>
<option value="yes"<?php echo $link->hardcode('url')?' selected="selected"':''; ?>>edit manually</option>
</select></td></tr>

<tr>
<th width="20%"><?php _e('Last update') ?>:</th>
<td colspan="2"><?php
	if (isset($meta['update/last'])) :
		echo strftime('%x %X', $meta['update/last'])." ";
	else :
		echo " none yet";
	endif;
?></td></tr>
<tr><th width="20%">Next update:</th>
<td colspan="2"><?php
	$holdem = (isset($meta['update/hold']) ? $meta['update/hold'] : 'scheduled');
?>
<select name="update_schedule">
<option value="scheduled"<?php echo ($holdem=='scheduled')?' selected="selected"':''; ?>>update on schedule <?php
	echo " (";
	if (isset($meta['update/ttl']) and is_numeric($meta['update/ttl'])) :
		if (isset($meta['update/timed']) and $meta['update/timed']=='automatically') :
			echo 'next: ';
			$next = $meta['update/last'] + ((int) $meta['update/ttl'] * 60);
			if (strftime('%x', time()) != strftime('%x', $next)) :
				echo strftime('%x', $next)." ";
			endif;
			echo strftime('%X', $meta['update/last']+((int) $meta['update/ttl']*60));
		else :
			echo "every ".$meta['update/ttl']." minute".(($meta['update/ttl']!=1)?"s":"");
		endif;
	else:
		echo "next scheduled update";
	endif;
	echo ")";
?></option>
<option value="next"<?php echo ($holdem=='next')?' selected="selected"':''; ?>>update ASAP</option>
<option value="ping"<?php echo ($holdem=='ping')?' selected="selected"':''; ?>>update only when pinged</option>
</select></tr>
</table>
</fieldset>

<script type="text/javascript">
flip_hardcode('name');
flip_hardcode('description');
flip_hardcode('url');
</script>

<p class="submit">
<input type="submit" name="submit" value="<?php _e('Save Changes &raquo;') ?>" />
</p>

<fieldset class="options">
<legend>Syndicated Posts</legend>

<?php fwp_category_box($dogs, 'all syndicated posts from this feed'); ?>

<table class="editform" width="75%" cellspacing="2" cellpadding="5">
<tr><th width="27%" scope="row" style="vertical-align:top">Publication:</th>
<td width="73%" style="vertical-align:top"><ul style="margin:0; list-style:none">
<li><label><input type="radio" name="feed_post_status" value="site-default"
<?php echo $status['post']['site-default']; ?> /> Use site-wide setting from <a href="admin.php?page=feedwordpress/syndication-options.php">Syndication Options</a>
(currently: <strong><?php echo ($post_status_global ? $post_status_global : 'publish'); ?></strong>)</label></li>
<li><label><input type="radio" name="feed_post_status" value="publish"
<?php echo $status['post']['publish']; ?> /> Publish posts from this feed immediately</label></li>
<li><label><input type="radio" name="feed_post_status" value="private"
<?php echo $status['post']['private']; ?> /> Hold posts from this feed as private posts</label></li>
<li><label><input type="radio" name="feed_post_status" value="draft"
<?php echo $status['post']['draft']; ?> /> Hold posts from this feed as drafts</label></li>
</ul></td>
</tr>

<tr><th width="27%" scope="row" style="vertical-align:top">Comments:</th>
<td width="73%"><ul style="margin:0; list-style:none">
<li><label><input type="radio" name="feed_comment_status" value="site-default"
<?php echo $status['comment']['site-default']; ?> /> Use site-wide setting from <a href="admin.php?page=feedwordpress/syndication-options.php">Syndication Options</a>
(currently: <strong><?php echo ($comment_status_global ? $comment_status_global : 'closed'); ?>)</strong></label></li>
<li><label><input type="radio" name="feed_comment_status" value="open"
<?php echo $status['comment']['open']; ?> /> Allow comments on syndicated posts from this feed</label></li>
<li><label><input type="radio" name="feed_comment_status" value="closed"
<?php echo $status['comment']['closed']; ?> /> Don't allow comments on syndicated posts from this feed</label></li>
</ul></td>
</tr>

<tr><th width="27%" scope="row" style="vertical-align:top">Trackback and Pingback:</th>
<td width="73%"><ul style="margin:0; list-style:none">
<li><label><input type="radio" name="feed_ping_status" value="site-default"
<?php echo $status['ping']['site-default']; ?> /> Use site-wide setting from <a href="admin.php?page=feedwordpress/syndication-options.php">Syndication Options</a>
(currently: <strong><?php echo ($ping_status_global ? $ping_status_global : 'closed'); ?>)</strong></label></li>
<li><label><input type="radio" name="feed_ping_status" value="open"
<?php echo $status['ping']['open']; ?> /> Accept pings on syndicated posts from this feed</label></li>
<li><label><input type="radio" name="feed_ping_status" value="closed"
<?php echo $status['ping']['closed']; ?> /> Don't accept pings on syndicated posts from this feed</label></li>
</ul></td>
</tr>
</table>
</fieldset>

<p class="submit">
<input type="submit" name="submit" value="<?php _e('Save Changes &raquo;') ?>" />
</p>

<fieldset class="options">
<legend>Syndicated Authors</legend>
<?php $authorlist = fwp_author_list(); ?>

<table>
<tr><th colspan="3" style="text-align: left; padding-top: 1.0em; border-bottom: 1px dotted black;">For posts by specific authors. Blank out a name to delete the rule.</th></tr>

<?php if (isset($meta['map authors'])) : $i=0; foreach ($meta['map authors'] as $author_rules) : foreach ($author_rules as $author_name => $author_action) : $i++; ?>
<tr>
  <th style="text-align: left">Posts by <input type="text" name="author_rules_name[]" value="<?php echo htmlspecialchars($author_name); ?>" /></th>
  <td>
  <select id="author-rules-<?php echo $i; ?>" name="author_rules_action[]" onchange="flip_newuser('author-rules-<?php echo $i; ?>');">
    <?php foreach ($authorlist as $local_author_id => $local_author_name) : ?>
    <option value="<?php echo $local_author_id; ?>"<?php if ($local_author_id==$author_action) : echo ' selected="selected"'; endif; ?>>are assigned to <?php echo $local_author_name; ?></option>
    <?php endforeach; ?>
    <option value="newuser">will be assigned to a new user...</option>
    <option value="filter"<?php if ('filter'==$author_action) : echo ' selected="selected"'; endif; ?>>get filtered out</option>
  </select>
  </td>
  <td><div id="author-rules-<?php echo $i; ?>-newuser">named <input type="text" name="author_rules_newuser[]" value="" /></div></td>
</tr>
<?php endforeach; endforeach; endif; ?>

<tr><th colspan="3" style="text-align: left; padding-top: 1.0em; border-bottom: 1px dotted black;">Fill in to set up a new rule:</th></tr>

<tr>
  <th style="text-align: left">Posts by <input type="text" name="add_author_rule_name" /></th>
  <td>
    <select id="add-author-rule" name="add_author_rule_action" onchange="flip_newuser('add-author-rule');">
      <?php foreach ($authorlist as $author_id => $author_name) : ?>
      <option value="<?php echo $author_id; ?>">are assigned to <?php echo $author_name; ?></option>
      <?php endforeach; ?>
      <option value="newuser">will be assigned to a new user...</option>
      <option value="filter">get filtered out</option>
    </select>
  </td>
  <td><div id="add-author-rule-newuser">named <input type="text" name="add_author_rule_newuser" value="" /></div></td>
</tr>

<tr><th colspan="3" style="text-align: left; padding-top: 1.0em; border-bottom: 1px dotted black;">For posts by authors that haven't been syndicated before:</th></tr>
<tr>
  <th style="text-align: left">Posts by new authors</th>
  <td> 
  <select id="unfamiliar-author" name="unfamiliar_author" onchange="flip_newuser('unfamiliar-author');">
    <option value="site-default"<?php if (!isset($meta['unfamiliar author'])) : ?>selected="selected"<?php endif; ?>>are handled using site-wide settings</option>
    <option value="create"<?php if ('create'==$meta['unfamiliar author']) : ?>selected="selected"<?php endif; ?>>create a new author account</option>
    <?php foreach ($authorlist as $author_id => $author_name) : ?>
      <option value="<?php echo $author_id; ?>"<?php if ($author_id==$meta['unfamiliar author']) : ?>selected="selected"<?php endif; ?>>are assigned to <?php echo $author_name; ?></option>
    <?php endforeach; ?>
    <option value="newuser">will be assigned to a new user...</option>
    <option value="filter"<?php if ('filter'==$meta['unfamiliar author']) : ?>selected="selected"<?php endif; ?>>get filtered out</option>
  </select>
  </td>
  <td>
  <div id="unfamiliar-author-default">Site-wide settings can be set in <a href="admin.php?page=feedwordpress/syndication-options.php">Syndication Options</a></div>
  <div id="unfamiliar-author-newuser">named <input type="text" name="unfamiliar_author_newuser" value="" /></div>
  </td>
</tr>

</table>
</fieldset>

<script>
	flip_newuser('unfamiliar-author');
<?php for ($j=1; $j<=$i; $j++) : ?>
	flip_newuser('author-rules-<?php echo $j; ?>');
<?php endfor; ?>
	flip_newuser('add-author-rule');
</script>

<p class="submit">
<input type="submit" name="submit" value="<?php _e('Save Changes &raquo;') ?>" />
</p>

<fieldset>
<legend>Advanced Feed Options</legend>
<table class="editform" width="100%" cellspacing="2" cellpadding="5">

<tr>
<th width="20%" scope="row" style="vertical-align:top">Unfamiliar categories:</th>
<td width="80%"><ul style="margin: 0; list-style:none">
<li><label><input type="radio" name="unfamiliar_category" value="site-default"<?php echo $unfamiliar['category']['site-default']; ?> /> use site-wide setting from <a href="admin.php?page=feedwordpress/syndication-options.php">Syndication Options</a>
(currently <strong><?php echo FeedWordPress::on_unfamiliar('category'); ?></strong>)</label></li>
<li><label><input type="radio" name="unfamiliar_category" value="create"<?php echo $unfamiliar['category']['create']; ?> /> create any categories the post is in</label></li>
<li><label><input type="radio" name="unfamiliar_category" value="default"<?php echo $unfamiliar['category']['default']; ?> /> don't create new categories</label></li>
<li><label><input type="radio" name="unfamiliar_category" value="filter"<?php echo $unfamiliar['category']['filter']; ?> /> don't create new categories and don't syndicate posts unless they match at least one familiar category</label></li>
</ul></td>
</tr>

<tr>
<th width="20%" scope="row" style="vertical-align:top">Multiple categories:</th>
<td width="80%"> 
<input type="text" size="20" id="cat_split" name="cat_split" value="<?php if (isset($meta['cat_split'])) : echo htmlspecialchars($meta['cat_split']); endif; ?>" /><br/>
Enter a <a href="http://us.php.net/manual/en/reference.pcre.pattern.syntax.php">Perl-compatible regular expression</a> here if the feed provides multiple
categories in a single category element. The regular expression should match
the characters used to separate one category from the next. If the feed uses
spaces (like <a href="http://del.icio.us/">del.icio.us</a>), use the pattern "\s".
If the feed does not provide multiple categories in a single element, leave this
blank.</td>
</tr>
</table>
</fieldset>

<p class="submit">
<input type="submit" name="submit" value="<?php _e('Save Changes &raquo;') ?>" />
</p>

<fieldset id="postcustom">
<legend>Custom Settings (for use in templates)</legend>
<div id="postcustomstuff">
<table id="meta-list" cellpadding="3">
	<tr>
	<th>Key</th>
	<th>Value</th>
	<th>Action</th>
	</tr>

<?php
	$i = 0;
	foreach ($meta as $key => $value) :
		if (!preg_match("\007^((".implode(')|(', $special_settings)."))$\007i", $key)) :
?>
			<tr style="vertical-align:top">
			<th width="30%" scope="row"><input type="hidden" name="notes[<?php echo $i; ?>][key0]" value="<?php echo wp_specialchars($key, 'both'); ?>" />
			<input id="notes-<?php echo $i; ?>-key" name="notes[<?php echo $i; ?>][key1]" value="<?php echo wp_specialchars($key, 'both'); ?>" /></th>
			<td width="60%"><textarea rows="2" cols="40" id="notes-<?php echo $i; ?>-value" name="notes[<?php echo $i; ?>][value]"><?php echo wp_specialchars($value, 'both'); ?></textarea></td>
			<td width="10%"><select name="notes[<?php echo $i; ?>][action]">
			<option value="update">save changes</option>
			<option value="delete">delete this setting</option>
			</select></td>
			</tr>
<?php
			$i++;
		endif;
	endforeach;
?>
	<tr>
	<th scope="row"><input type="text" size="10" name="notes[<?php echo $i; ?>][key1]" value="" /></th>
	<td><textarea name="notes[<?php echo $i; ?>][value]" rows="2" cols="40"></textarea></td>
	<td><em>add new setting...</em><input type="hidden" name="notes[<?php echo $i; ?>][action]" value="update" /></td>
	</tr>
</table>
</fieldset>

<p class="submit">
<input type="submit" name="submit" value="<?php _e('Save Changes &raquo;') ?>" />
</p>

</div>
	<?php
	endif;
	return false; // Don't continue
}

function fwp_author_list () {
	global $wpdb;
	$ret = array();
	$users = $wpdb->get_results("SELECT * FROM $wpdb->users ORDER BY display_name");
	if (is_array($users)) :
		foreach ($users as $user) :
			$id = (int) $user->ID;
			$ret[$id] = $user->display_name;
		endforeach;
	endif;
	return $ret;
}

function fwp_multidelete_page () {
	global $wpdb;

	check_admin_referer(); // Make sure the referers are kosher

	$link_ids = (isset($_REQUEST['link_ids']) ? $_REQUEST['link_ids'] : array());
	if (isset($_REQUEST['link_id'])) : array_push($link_ids, $_REQUEST['link_id']); endif;

	if (!current_user_can('manage_links')):
		die (__("Cheatin' uh ?"));
	elseif (isset($GLOBALS['fwp_post']['confirm']) and $GLOBALS['fwp_post']['confirm']=='Delete'):
		foreach ($GLOBALS['fwp_post']['link_action'] as $link_id => $what) :
			$do_it[$what][] = $link_id;
		endforeach;

		$alter = array();
		if (count($do_it['hide']) > 0) :
			$hidem = "(".implode(', ', $do_it['hide']).")";
			$alter[] = "
			UPDATE $wpdb->links
			SET link_visible = 'N'
			WHERE link_id IN {$hidem}
			";
		endif;

		if (count($do_it['nuke']) > 0) :
			$nukem = "(".implode(', ', $do_it['nuke']).")";
			
			// Make a list of the items syndicated from this feed...
			$post_ids = $wpdb->get_col("
				SELECT post_id FROM $wpdb->postmeta
				WHERE meta_key = 'syndication_feed_id'
				AND meta_value IN {$nukem}
			");

			// ... and kill them all
			if (count($post_ids) > 0) :
				foreach ($post_ids as $post_id) :
					wp_delete_post($post_id);
				endforeach;
			endif;

			$alter[] = "
			DELETE FROM $wpdb->links
			WHERE link_id IN {$nukem}
			";
		endif;

		if (count($do_it['delete']) > 0) :
			$deletem = "(".implode(', ', $do_it['delete']).")";

			// Make the items syndicated from this feed appear to be locally-authored
			$alter[] = "
				DELETE FROM $wpdb->postmeta
				WHERE meta_key = 'syndication_feed_id'
				AND meta_value IN {$deletem}
			";

			// ... and delete the links themselves.
			$alter[] = "
			DELETE FROM $wpdb->links
			WHERE link_id IN {$deletem}
			";
		endif;

		$errs = array(); $success = array ();
		foreach ($alter as $sql) :
			$result = $wpdb->query($sql);
			if (!$result):
				$errs[] = mysql_error();
			endif;
		endforeach;
		
		if (count($alter) > 0) :
			echo "<div class=\"updated\">\n";
			if (count($errs) > 0) :
				echo "There were some problems processing your ";
				echo "unsubscribe request. [SQL: ".implode('; ', $errs)."]";
			else :
				echo "Your unsubscribe request(s) have been processed.";
			endif;
			echo "</div>\n";
		endif;

		return true; // Continue on to Syndicated Sites listing
	else :
		$targets = $wpdb->get_results("
			SELECT * FROM $wpdb->links
			WHERE link_id IN (".implode(",",$link_ids).")
			");
?>
<form action="admin.php?page=feedwordpress/<?php echo basename(__FILE__); ?>" method="post">
<div class="wrap">
<input type="hidden" name="action" value="Unsubscribe" />
<input type="hidden" name="confirm" value="Delete" />

<h2>Unsubscribe from Syndicated Links:</h2>
<?php	foreach ($targets as $link) :
		$link_url = wp_specialchars($link->link_url, 1);
		$link_name = wp_specialchars($link->link_name, 1);
		$link_description = wp_specialchars($link->link_description, 'both');
		$link_rss = wp_specialchars($link->link_rss, 'both');
?>
<fieldset>
<legend><?php echo $link_name; ?></legend>
<table class="editform" width="100%" cellspacing="2" cellpadding="5">
<tr><th scope="row" width="20%"><?php _e('Feed URI:') ?></th>
<td width="80%"><a href="<?php echo $link_rss; ?>"><?php echo $link_rss; ?></a></td></tr>
<tr><th scope="row" width="20%"><?php _e('Short description:') ?></th>
<td width="80%"><?php echo $link_description; ?></span></td></tr>
<tr><th width="20%" scope="row"><?php _e('Homepage:') ?></th>
<td width="80%"><a href="<?php echo $link_url; ?>"><?php echo $link_url; ?></a></td></tr>
<tr style="vertical-align:top"><th width="20%" scope="row">Subscription <?php _e('Options') ?>:</th>
<td width="80%"><ul style="margin:0; padding: 0; list-style: none">
<li><input type="radio" id="hide-<?php echo $link->link_id; ?>"
name="link_action[<?php echo $link->link_id; ?>]" value="hide" />
<label for="hide-<?php echo $link->link_id; ?>">Turn off the subscription for this
syndicated link<br/><span style="font-size:smaller">(Keep the feed information
and all the posts from this feed in the database, but don't syndicate any
new posts from the feed.)</span></label></li>
<li><input type="radio" id="nuke-<?php echo $link->link_id; ?>"
name="link_action[<?php echo $link->link_id; ?>]" value="nuke" />
<label for="nuke-<?php echo $link->link_id; ?>">Delete this syndicated link and all the
posts that were syndicated from it</label></li>
<li><input type="radio" id="delete-<?php echo $link->link_id; ?>"
name="link_action[<?php echo $link->link_id; ?>]" value="delete" />
<label for="delete-<?php echo $link->link_id; ?>">Delete this syndicated link, but
<em>keep</em> posts that were syndicated from it (as if they were authored
locally).</label></li>
<li><input type="radio" id="nothing-<?php echo $link->link_id; ?>"
name="link_action[<?php echo $link->link_id; ?>]" value="nothing" />
<label for="nothing-<?php echo $link->link_id; ?>">Keep this feed as it is. I changed
my mind.</label></li>
</ul>
</table>
</fieldset>
<?php	endforeach; ?>

<div class="submit">
<input class="delete" type="submit" name="submit" value="<?php _e('Unsubscribe from selected feeds &raquo;') ?>" />
</div>
</div>
<?php
		return false; // Don't continue on to Syndicated Sites listing
	endif;
}

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
	function update ($uri = null) {
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

		// Loop through and check for new posts
		$delta = NULL;
		foreach ($this->feeds as $feed) :
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
				$added = $feed->poll();
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
				"link_category" => array($cat_id),
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
		$set = array('create', 'default', 'filter');

		if (is_string($override)) :
			$ret = strtolower($override);
		endif;

		if (!is_numeric($override) and !in_array($ret, $set)) :
			$ret = get_option('feedwordpress_unfamiliar_'.$what);
			if (!is_numeric($ret) and !in_array($ret, $set)) :
				$ret = 'create';
			endif;
		endif;

		return $ret;
	} // function FeedWordPress::on_unfamiliar()

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
				elseif (function_exists('wp_insert_category')) : 
					$cat_id = wp_insert_category(array('cat_name' => $cat));
				// WordPress 1.5 and 2.0.x
				elseif (!isset($wp_db_version) or $wp_db_version < FWP_SCHEMA_21) :
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

	var $strip_attrs = array (
		      array('[a-z]+', 'style'),
		      array('[a-z]+', 'target'),
	);

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

			$this->post['post_title'] = $this->item['title'];

			// This just gives usan alphanumeric representation of
			// the author. We will look up (or create) the numeric
			// ID for the author in SyndicatedPost::add()
			$this->post['named']['author'] = $this->author();

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
		
			# FeedWordPress used to resolve URIs relative to the
			# feed URI. It now relies on the xml:base support
			# baked in to the MagpieRSS upgrade. So all we do here
			# now is to sanitize problematic attributes.
			#
			# This kind of sucks. I intend to replace it with
			# lib_filter sometime soon.
			foreach ($this->strip_attrs as $pair):
				list($tag,$attr) = $pair;
				$content = preg_replace (
					":(<$tag [^>]*)($attr=(\"[^\">]*\"|[^>\\s]+))([^>]*>):i",
					"\\1\\4",
					$content
				);
			endforeach;

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

			$this->post['post_content'] = $content;
			
			if (!is_null($excerpt)):
				$this->post['post_excerpt'] = $excerpt;
			endif;
			
			// This is unnecessary if we use wp_insert_post
			if (!$this->use_api('wp_insert_post')) :
				$this->post['post_name'] = sanitize_title($this->post['post_title']);
			endif;

			$this->post['epoch']['issued'] = $this->published();
			$this->post['epoch']['created'] = $this->created();
			$this->post['epoch']['modified'] = $this->updated();

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
			$this->post['guid'] = $this->guid();

			// RSS 2.0 / Atom 1.0 enclosure support
			if ( isset($this->item['enclosure#']) ) :
				for ($i = 1; $i <= $this->item['enclosure#']; $i++) :
					$eid = (($i > 1) ? "#{$id}" : "");
					$this->post['meta']['enclosure'][] =
						$this->item["enclosure{$eid}@url"]."\n".
						$this->item["enclosure{$eid}@length"]."\n".
						$this->item["enclosure{$eid}@type"];
				endfor;
			endif;

			// In case you want to point back to the blog this was syndicated from
			if (isset($this->feed->channel['title'])) :
				$this->post['meta']['syndication_source'] = $this->feed->channel['title'];
			endif;
			if (isset($this->feed->channel['link'])) :
				$this->post['meta']['syndication_source_uri'] = $this->feed->channel['link'];
			endif;
			
			// Store information on human-readable and machine-readable comment URIs
			if (isset($this->item['comments'])) :
				$this->post['meta']['rss:comments'] = $this->item['comments'];
			endif;
			if (isset($this->item['wfw']['commentrss'])) :
				$this->post['meta']['wfw:commentRSS'] = $this->item['wfw']['commentrss'];
			endif;

			// Store information to identify the feed that this came from
			$this->post['meta']['syndication_feed'] = $this->feedmeta['link/uri'];
			$this->post['meta']['syndication_feed_id'] = $this->feedmeta['link/id'];

			// In case you want to know the external permalink...
			$this->post['meta']['syndication_permalink'] = $this->item['link'];

			// Feed-by-feed options for author and category creation
			$this->post['named']['unfamiliar']['author'] = $this->feedmeta['unfamiliar author'];
			$this->post['named']['unfamiliar']['category'] = $this->feedmeta['unfamiliar categories'];

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

			preg_match('/([0-9]+)-([0-9]+)-([0-9]+) ([0-9]+):([0-9]+):([0-9]+)/', $result->post_modified_gmt, $backref);
			$updated = gmmktime($backref[4], $backref[5], $backref[6], $backref[2], $backref[3], $backref[1]);
			if (!$result) :
				$this->_freshness = 2; // New content
			elseif ($this->updated() > $updated) :
				$this->_freshness = 1; // Updated content
				$this->_wp_id = $result->id;
			else :
				$this->_freshness = 0; // Same old, same old
				$this->_wp_id = $result->id;
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
			$this->post['post_category'] = $this->category_ids (
				$this->post['named']['category'],
				FeedWordPress::on_unfamiliar('category', $this->post['named']['unfamiliar']['category'])
			);
				
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

		if ($this->use_api('wp_insert_post')) :
			$dbpost['post_pingback'] = false; // Tell WP 2.1 and 2.2 not to process for pingbacks
			$this->_wp_id = wp_insert_post($dbpost);
			
			// This should never happen.
			if (!is_numeric($this->_wp_id) or ($this->_wp_id == 0)) :
				FeedWordPress::critical_bug('SyndicatedPost (_wp_id problem)', $this, __LINE__);
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
				FeedWordPress::critical_bug('SyndicatedPost::_wp_id', $this->_wp_id, __LINE__);
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
			$this->_wp_id = wp_insert_post($dbpost);

			// This should never happen.
			if (!is_numeric($this->_wp_id) or ($this->_wp_id == 0)) :
				FeedWordPress::critical_bug('SyndicatedPost::_wp_id', $this->_wp_id, __LINE__);
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
				FeedWordPress::critical_bug('SyndicatedPost::insert_new::wp_version', $GLOBALS['wp_version'], __LINE__); 	
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
		global $wpdb, $wp_db_version; // test for WordPress 2.0 database schema

		$a = $this->author();
		$author = $a['name'];
		$email = $a['email'];
		$url = $a['uri'];

		// Never can be too careful...
		$login = sanitize_user($author, /*strict=*/ true);
		$login = apply_filters('pre_user_login', $login);

		$nice_author = sanitize_title($author);
		$nice_author = apply_filters('pre_user_nicename', $nice_author);

		$reg_author = $wpdb->escape(preg_quote($author));
		$author = $wpdb->escape($author);
		$email = $wpdb->escape($email);
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

			#-- WordPress 1.5.x
			if (!isset($wp_db_version)) :
				$id = $wpdb->get_var(
				"SELECT ID from $wpdb->users
				 WHERE
					TRIM(LCASE(user_login)) = TRIM(LCASE('$login')) OR
					(
						LENGTH(TRIM(LCASE(user_email))) > 0
						AND TRIM(LCASE(user_email)) = TRIM(LCASE('$email'))
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

			#-- WordPress 2.0+
			elseif ($wp_db_version >= 2966) :

				// First try the user core data table.
				$id = $wpdb->get_var(
				"SELECT ID FROM $wpdb->users
				WHERE
					TRIM(LCASE(user_login)) = TRIM(LCASE('$login'))
					OR (
						LENGTH(TRIM(LCASE(user_email))) > 0
						AND TRIM(LCASE(user_email)) = TRIM(LCASE('$email'))
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
	function category_ids ($cats, $unfamiliar_category = 'create') {
		global $wpdb;

		// We need to normalize whitespace because (1) trailing
		// whitespace can cause PHP and MySQL not to see eye to eye on
		// VARCHAR comparisons for some versions of MySQL (cf.
		// <http://dev.mysql.com/doc/mysql/en/char.html>), and (2)
		// because I doubt most people want to make a semantic
		// distinction between 'Computers' and 'Computers  '
		$cats = array_map('trim', $cats);

		$cat_ids = array ();
		foreach ($cats as $cat_name) :
			if (preg_match('/^{#([0-9]+)}$/', $cat_name, $backref)) :
				$cat_id = (int) $backref[1];
				if (function_exists('is_term') and is_term($cat_id, 'category')) :
					$cat_ids[] = $cat_id;
				elseif (get_category($cat_id)) :
					$cat_ids[] = $cat_id;
				endif;
			else :
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
					elseif ('create'===$unfamiliar_category) :
						$term = wp_insert_term($cat_name, 'category');
						$cat_ids[] = $term['term_id'];
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
		return $cat_ids;
	} // function SyndicatedPost::category_ids ()

	function use_api ($tag) {
		global $wp_db_version;
		if ('wp_insert_post'==$tag) :
			// Before 2.2, wp_insert_post does too much of the wrong stuff to use it
			// In 1.5 it was such a resource hog it would make PHP segfault on big updates
			$ret = (isset($wp_db_version) and $wp_db_version > FWP_SCHEMA_21);
		endif;
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
			$epoch = time();
		endif;
		
		return $epoch;
	}
	function updated ($fallback = true) {
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
			$epoch = $this->published(/*fallback=*/ false);
		endif;
		
		# If everything failed, then default to the current time.
		if (is_null($epoch)) :
			$epoch = time();
		endif;

		return $epoch;
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
			
			// `hardcode categories` is deprecated in favor of `unfamiliar categories`
			if (
				FeedWordPress::affirmative($this->settings, 'hardcode categories')
				and !isset($this->settings['unfamiliar categories'])
			) :
				$this->settings['unfamiliar categories'] = 'default';
			endif;
			
			// Set this up automagically for del.icio.us
			if (!isset($this->settings['cat_split']) and false !== strpos($this->link->link_rss, 'del.icio.us')) : 
				$this->settings['cat_split'] = '\s'; // Whitespace separates multiple tags in del.icio.us RSS feeds
			endif;

			if (isset($this->settings['cats'])):
				$this->settings['cats'] = preg_split(FEEDWORDPRESS_CAT_SEPARATOR_PATTERN, $this->settings['cats']);
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
	} // SyndicatedLink::SyndicatedLink ()
	
	function found () {
		return is_object($this->link);
	}

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
	}

	function poll () {
		global $wpdb;

		$this->magpie = fetch_rss($this->link->link_rss);
		$new_count = NULL;

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
	
			// Copy back without a few things that we don't want to save in the notes
			$to_notes = $this->settings;

			if (is_array($to_notes['cats'])) :
				$to_notes['cats'] = implode(FEEDWORDPRESS_CAT_SEPARATOR, $to_notes['cats']);
			endif;

			if (isset($to_notes['map authors'])) :
				$ma = array();
				foreach ($to_notes['map authors'] as $rule_type => $author_rules) :
					foreach ($author_rules as $author_name => $author_action) :
						$ma[] = $rule_type."\n".$author_name."\n".$author_action;
					endforeach;
				endforeach;
				$to_notes['map authors'] = implode("\n\n", $ma);
			endif;

			unset($to_notes['link/id']); unset($to_notes['link/uri']);
			unset($to_notes['link/name']);
			unset($to_notes['hardcode categories']); // Deprecated
	
			$notes = '';
			foreach ($to_notes as $key => $value) :
				$notes .= $key . ": ". addcslashes($value, "\0..\37".'\\') . "\n";
			endforeach;
			$update[] = "link_notes = '".$wpdb->escape($notes)."'";
	
			$update_set = implode(',', $update);
			
			// Update the properties of the link from the feed information
			$result = $wpdb->query("
				UPDATE $wpdb->links
				SET $update_set
				WHERE link_id='$this->id'
			");

			# -- Add new posts from feed and update any updated posts
			if (is_array($this->magpie->items)) :
				foreach ($this->magpie->items as $item) :
					$post =& new SyndicatedPost($item, $this);
					if (!$post->filtered()) :
						$new = $post->store();
						if ( $new !== false ) $new_count[$new]++;
					endif;
				endforeach;
			endif;
			
			// Copy back any changes to feed settings made in the course of updating (e.g. new author rules)
			$to_notes = $this->settings;

			if (is_array($to_notes['cats'])) :
				$to_notes['cats'] = implode(FEEDWORDPRESS_CAT_SEPARATOR, $to_notes['cats']);
			endif;

			if (isset($to_notes['map authors'])) :
				$ma = array();
				foreach ($to_notes['map authors'] as $rule_type => $author_rules) :
					foreach ($author_rules as $author_name => $author_action) :
						$ma[] = $rule_type."\n".$author_name."\n".$author_action;
					endforeach;
				endforeach;
				$to_notes['map authors'] = implode("\n\n", $ma);
			endif;

			unset($to_notes['link/id']); unset($to_notes['link/uri']);
			unset($to_notes['link/name']);
			unset($to_notes['hardcode categories']); // Deprecated
	
			$notes = '';
			foreach ($to_notes as $key => $value) :
				$notes .= $key . ": ". addcslashes($value, "\0..\37".'\\') . "\n";
			endforeach;

			$update_set = "link_notes = '".$wpdb->escape($notes)."'";
			
			// Update the properties of the link from the feed information
			$result = $wpdb->query("
				UPDATE $wpdb->links
				SET $update_set
				WHERE link_id='$this->id'
			");
		endif;
		return $new_count;
	} /* SyndicatedLink::poll() */
	
	function uri () {
		return (is_object($this->link) ? $this->link->link_rss : NULL);
	}
	function homepage () {
		return (isset($this->settings['feed/link']) ? $this->settings['feed/link'] : NULL);
	}

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
	} // function SyndicatedLink::flatten_array ()

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
	} // function SyndicatedLink::hardcode ()

	function syndicated_status ($what, $default) {
		global $wpdb;

		$ret = get_option("feedwordpress_syndicated_{$what}_status");
		if ( isset($this->settings["$what status"]) ) :
			$ret = $this->settings["$what status"];
		elseif (!$ret) :
			$ret = $default;
		endif;
		return $wpdb->escape(trim(strtolower($ret)));
	} // function SyndicatedLink:syndicated_status ()
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
		    // Snoopy is an HTTP client in PHP
		    $client = new Snoopy();
		    
		    // Prepare headers and internal settings
		    $client->rawheaders['Connection'] = 'close';
		    $client->accept = 'application/atom+xml application/rdf+xml application/rss+xml application/xml text/html */*';
		    $client->agent = 'feedfinder/1.2 (compatible; PHP FeedFinder) +http://projects.radgeek.com/feedwordpress';
		    $client->read_timeout = 25;
		    
		    // Fetch the HTML or feed
		    @$client->fetch($this->uri);
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
?>
