<?php
/*
Plugin Name: FeedWordPress
Plugin URI: http://projects.radgeek.com/feedwordpress
Description: simple and flexible Atom/RSS syndication for WordPress
Version: 0.981
Author: Charles Johnson
Author URI: http://radgeek.com/
License: GPL
Last modified: 2007-02-17 4:23pm EST
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
define ('RPC_MAGIC', 'tag:radgeek.com/projects/feedwordpress/');
define ('FEEDWORDPRESS_VERSION', '0.981');
define ('DEFAULT_SYNDICATION_CATEGORY', 'Contributors');

define ('FEEDWORDPRESS_CAT_SEPARATOR_PATTERN', '/[:\n]/');
define ('FEEDWORDPRESS_CAT_SEPARATOR', "\n");

define ('FEEDVALIDATOR_URI', 'http://feedvalidator.org/check.cgi');

// Note that the rss-functions.php that comes prepackaged with WordPress is
// old & busted. For the new hotness, drop a copy of rss.php from
// this archive into wp-includes/rss.php

if (is_readable(ABSPATH . WPINC . '/rss.php')) :
	require_once (ABSPATH . WPINC . '/rss.php');
else :
	require_once (ABSPATH . WPINC . '/rss-functions.php');
endif;

if (isset($wp_db_version) and $wp_db_version >= 3308) :
	require_once (ABSPATH . WPINC . '/registration-functions.php');
	require_once (ABSPATH . 'wp-admin/admin-db.php');
endif;

// Is this being loaded from within WordPress 1.5 or later?
if (isset($wp_version) and $wp_version >= 1.5):

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
		remove_action('publish_post', 'generic_ping');
		add_action('publish_post', 'fwp_catch_ping');
	
		# Hook in logging functions only if the logging option is ON
		$update_logging = get_settings('feedwordpress_update_logging');
		if ($update_logging == 'yes') :
			add_action('post_syndicated_item', 'log_feedwordpress_post', 100);
			add_action('update_syndicated_item', 'log_feedwordpress_update_post', 100);
			add_action('feedwordpress_update', 'log_feedwordpress_update_feeds', 100);
			add_action('feedwordpress_check_feed', 'log_feedwordpress_check_feed', 100);
			add_action('feedwordpress_update_complete', 'log_feedwordpress_update_complete', 100);
		endif;
	else :
		# Hook in the menus, which will just point to the upgrade interface
		add_action('admin_menu', 'fwp_add_pages');
	endif; // if (!FeedWordPress::needs_upgrade())
endif;

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
			$result = $feedwordpress_linkcache[$feed_id];
		else :
			$result = $wpdb->get_row("
			SELECT * FROM $wpdb->links
			WHERE (link_id = '".$wpdb->escape($feed_id)."')"
			);
			$feedwordpress_linkcache[$feed_id] = $result;
		endif;

		$meta = FeedWordPress::notes_to_settings($result->link_notes);
		$ret = $meta[$key];
	endif; /* if */
	return $ret;
}

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
	if (get_settings('feedwordpress_munge_permalink') != 'no'):
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
	if (isset($_POST['action']) and $_POST['action']=='Upgrade') :
		$ver = get_settings('feedwordpress_version');
		if (get_settings('feedwordpress_version') != FEEDWORDPRESS_VERSION) :
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

	add_submenu_page('link-manager.php', 'Syndicated Sites', 'Syndicated', $manage_links, basename(__FILE__), 'fwp_syndication_manage_page');
	add_options_page('Syndication Options', 'Syndication', $manage_options, basename(__FILE__), 'fwp_syndication_options_page');
} // function fwp_add_pages () */

function fwp_syndication_options_page () {
        global $wpdb, $wp_db_version;
	
	if (FeedWordPress::needs_upgrade()) :
		fwp_upgrade_page();
		return;
	endif;

	$caption = 'Save Changes';
	if (isset($_POST['action']) and $_POST['action']==$caption):
		check_admin_referer();

		if (!current_user_can('manage_options')):
			die (__("Cheatin' uh ?"));
		else:
			update_option('feedwordpress_rpc_secret', $_REQUEST['rpc_secret']);
			update_option('feedwordpress_cat_id', $_REQUEST['syndication_category']);
			update_option('feedwordpress_munge_permalink', $_REQUEST['munge_permalink']);
			update_option('feedwordpress_update_logging', $_REQUEST['update_logging']);
			update_option('feedwordpress_unfamiliar_author', $_REQUEST['unfamiliar_author']);
			update_option('feedwordpress_unfamiliar_category', $_REQUEST['unfamiliar_category']);
			update_option('feedwordpress_syndicated_post_status', $_REQUEST['post_status']);

			// Categories
			$cats = array();
			if (isset($_POST['post_category'])) :
				$cat_set = "(".implode(",", $_POST['post_category']).")";
				$cats = $wpdb->get_col(
				"SELECT cat_name
				FROM $wpdb->categories
				WHERE cat_ID IN {$cat_set}
				");
			endif;

			if (!empty($cats)) :
				update_option('feedwordpress_syndication_cats', implode("\n", $cats));
			else :
				delete_option('feedwordpress_syndication_cats');
			endif;

			if (isset($_REQUEST['comment_status']) and ($_REQUEST['comment_status'] == 'open')) :
				update_option('feedwordpress_syndicated_comment_status', 'open');
			else :
				update_option('feedwordpress_syndicated_comment_status', 'closed');
			endif;

			if (isset($_REQUEST['ping_status']) and ($_REQUEST['ping_status'] == 'open')) :
				update_option('feedwordpress_syndicated_ping_status', 'open');
			else :
				update_option('feedwordpress_syndicated_ping_status', 'closed');
			endif;
			
			if (isset($_REQUEST['hardcode_name']) and ($_REQUEST['hardcode_name'] == 'no')) :
				update_option('feedwordpress_hardcode_name', 'no');
			else :
				update_option('feedwordpress_hardcode_name', 'yes');
			endif;
			
			if (isset($_REQUEST['hardcode_description']) and ($_REQUEST['hardcode_description'] == 'no')) :
				update_option('feedwordpress_hardcode_description', 'no');
			else :
				update_option('feedwordpress_hardcode_description', 'yes');
			endif;

			if (isset($_REQUEST['hardcode_url']) and ($_REQUEST['hardcode_url'] == 'no')) :
				update_option('feedwordpress_hardcode_url', 'no');
			else :
				update_option('feedwordpress_hardcode_url', 'yes');
			endif;
?>
<div class="updated">
<p><?php _e('Options saved.')?></p>
</div>
<?php
		endif;
	endif;

	$cat_id = FeedWordPress::link_category_id();
	$rpc_secret = FeedWordPress::rpc_secret();
	$munge_permalink = get_settings('feedwordpress_munge_permalink');
	$update_logging = get_settings('feedwordpress_update_logging');

	$hardcode_name = get_settings('feedwordpress_hardcode_name');
	$hardcode_description = get_settings('feedwordpress_hardcode_description');
	$hardcode_url = get_settings('feedwordpress_hardcode_url');

	$post_status = FeedWordPress::syndicated_status('post', array(), 'publish');
	$comment_status = FeedWordPress::syndicated_status('comment', array(), 'closed');
	$ping_status = FeedWordPress::syndicated_status('ping', array(), 'closed');

	$unfamiliar_author = array ('create' => '','default' => '','filter' => '');
	$ua = FeedWordPress::on_unfamiliar('author');
	if (is_string($ua) and array_key_exists($ua, $unfamiliar_author)) :
		$unfamiliar_author[$ua] = ' checked="checked"';
	endif;
	$unfamiliar_category = array ('create'=>'','default'=>'','filter'=>'');
	$uc = FeedWordPress::on_unfamiliar('category');
	if (is_string($uc) and array_key_exists($uc, $unfamiliar_category)) :
		$unfamiliar_category[$uc] = ' checked="checked"';
	endif;
	
	if (isset($wp_db_version) and $wp_db_version >= 4772) :
		$results = get_categories('type=link');
	else :
		$results = $wpdb->get_results("SELECT cat_id, cat_name, auto_toggle FROM $wpdb->linkcategories ORDER BY cat_id");
	endif;

	$cats = get_settings('feedwordpress_syndication_cats');
	$dogs = get_nested_categories(-1, 0);
	$cats = array_map('strtolower',
		array_map('trim',
			preg_split(FEEDWORDPRESS_CAT_SEPARATOR_PATTERN, $cats)
		));
	
	foreach ($dogs as $tag => $dog) :
		if (in_array(strtolower(trim($dog['cat_name'])), $cats)) :
			$dogs[$tag]['checked'] = true;
		endif;
	endforeach;

?>
<div class="wrap">
<h2>Syndication Options</h2>
<form action="" method="post">
<fieldset class="options">
<legend>Syndicated Feeds</legend>
<table class="editform" width="100%" cellspacing="2" cellpadding="5">
<tr>
<th width="33%" scope="row">Syndicate links in category:</th>
<td width="67%"><?php
		echo "\n<select name=\"syndication_category\" size=\"1\">";
		foreach ($results as $row) {
			if (!isset($row->cat_id)) { $row->cat_id = $row->cat_ID; }
			
			echo "\n\t<option value=\"$row->cat_id\"";
			if ($row->cat_id == $cat_id)
				echo " selected='selected'";
			echo ">$row->cat_id: ".wp_specialchars($row->cat_name);
			if ('Y' == $row->auto_toggle)
				echo ' (auto toggle)';
			echo "</option>\n";
		}
		echo "\n</select>\n";
?></td>
</tr>

<tr><th width="33%" scope="row" style="vertical-align:top">Update live from feed:</th>
<td width="67%"><ul style="margin:0;list-style:none">
<li><input type="checkbox" name="hardcode_name" value="no"<?php echo (($hardcode_name=='yes')?'':' checked="checked"');?>/> Contributor name (feed title)</li>
<li><input type="checkbox" name="hardcode_description" value="no"<?php echo (($hardcode_description=='yes')?'':' checked="checked"');?>/> Contributor description (feed tagline)</li>
<li><input type="checkbox" name="hardcode_url" value="no"<?php echo (($hardcode_url=='yes')?'':' checked="checked"');?>/> Homepage (feed link)</li>
</ul></td></tr>
</table>
</fieldset>

<fieldset class="options">
<legend>Syndicated Posts</legend>

<?php fwp_category_box($dogs, '<em>all syndicated posts</em>'); ?>

<table class="editform" width="75%" cellspacing="2" cellpadding="5">
<tr style="vertical-align: top"><th width="33%" scope="row">Publication:</th>
<td width="67%"><ul style="margin: 0; padding: 0; list-style:none">
<li><label><input type="radio" name="post_status" value="publish"<?php echo ($post_status=='publish')?' checked="checked"':''; ?> /> Publish syndicated posts immediately</label></li>
<li><label><input type="radio" name="post_status" value="draft"<?php echo ($post_status=='draft')?' checked="checked"':''; ?> /> Hold syndicated posts as drafts</label></li>
<li><label><input type="radio" name="post_status" value="private"<?php echo ($post_status=='private')?' checked="checked"':''; ?> /> Hold syndicated posts as private posts</label></li>
</ul></td></tr>

<tr style="vertical-align: top"><th width="33%" scope="row">Comments:</th>
<td width="67%"><ul style="margin: 0; padding: 0; list-style:none">
<li><label><input type="radio" name="comment_status" value="open"<?php echo ($comment_status=='open')?' checked="checked"':''; ?> /> Allow comments on syndicated posts</label></li>
<li><label><input type="radio" name="comment_status" value="closed"<?php echo ($comment_status!='open')?' checked="checked"':''; ?> /> Don't allow comments on syndicated posts</label></li>
</ul></td></tr>

<tr style="vertical-align: top"><th width="33%" scope="row">Trackback and Pingback:</th>
<td width="67%"><ul style="margin:0; padding: 0; list-style:none">
<li><label><input type="radio" name="ping_status" value="open"<?php echo ($ping_status=='open')?' checked="checked"':''; ?> /> Accept pings on syndicated posts</label></li>
<li><label><input type="radio" name="ping_status" value="closed"<?php echo ($ping_status!='open')?' checked="checked"':''; ?> /> Don't accept pings on syndicated posts</label></li>
</ul></td></tr>

<tr style="vertical-align: top"><th width="33%" scope="row" style="vertical-align:top">Unfamiliar authors:</th>
<td width="67%"><ul style="margin: 0; padding: 0; list-style:none">
<li><label><input type="radio" name="unfamiliar_author" value="create"<?php echo $unfamiliar_author['create']; ?>/> create a new author account</label></li>
<li><label><input type="radio" name="unfamiliar_author" value="default"<?php echo $unfamiliar_author['default']; ?> /> attribute the post to the default author</label></li>
<li><label><input type="radio" name="unfamiliar_author" value="filter"<?php echo $unfamiliar_author['filter']; ?> /> don't syndicate the post</label></li>
</ul></td></tr>
<tr style="vertical-align: top"><th width="33%" scope="row" style="vertical-align:top">Unfamiliar categories:</th>
<td width="67%"><ul style="margin: 0; padding:0; list-style:none">
<li><label><input type="radio" name="unfamiliar_category" value="create"<?php echo $unfamiliar_category['create']; ?>/> create any categories the post is in</label></li>
<li><label><input type="radio" name="unfamiliar_category" value="default"<?php echo $unfamiliar_category['default']; ?>/> don't create new categories</li>
<li><label><input type="radio" name="unfamiliar_category" value="filter"<?php echo $unfamiliar_category['filter']; ?>/> don't create new categories and don't syndicate posts unless they match at least one familiar category</label></li>
</ul></td></tr>

<tr style="vertical-align: top"><th width="33%" scope="row">Permalinks point to:</th>
<td width="67%"><select name="munge_permalink" size="1">
<option value="yes"<?php echo ($munge_permalink=='yes')?' selected="selected"':''; ?>>original website</option>
<option value="no"<?php echo ($munge_permalink=='no')?' selected="selected"':''; ?>>this website</option>
</select></td></tr>
</table>
<div class="submit"><input type="submit" name="action" value="<?php echo $caption; ?>" /></div>
</fieldset>

<fieldset class="options">
<legend>Back-end Options</legend>
<table class="editform" width="100%" cellspacing="2" cellpadding="5">
<tr>
<th width="33%" scope="row">XML-RPC update secret word:</th>
<td width="67%"><input id="rpc_secret" name="rpc_secret" value="<?php echo $rpc_secret; ?>" />
</td>
</tr>
<tr>
<th scope="row">Write update notices to PHP logs:</th>
<td><select name="update_logging" size="1">
<option value="yes"<?php echo (($update_logging=='yes')?' selected="selected"':''); ?>>yes</option>
<option value="no"<?php echo (($update_logging!='yes')?' selected="selected"':''); ?>>no</option>
</select></td>
</tr>
</table>
<div class="submit"><input type="submit" name="action" value="<?php echo $caption; ?>" /></div>
</fieldset>
</form>
</div>
<?php
}

function fwp_category_box ($checked, $object) {
	global $wp_db_version;

	if (isset($wp_db_version) and $wp_db_version >= 3308) : // WordPress 2.x
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
	elseif ($_REQUEST['action'] == 'Unsubscribe from Checked' or $_REQUEST['action'] == 'Unsubscribe') : $cont = fwp_multidelete_page();
	endif;
endif;

if ($cont):
?>
<?php
	$links = FeedWordPress::syndicated_links();
?>
	<div class="wrap">
	<form action="link-manager.php?page=<?php echo basename(__FILE__); ?>" method="post">
	<h2>Syndicate a new site:</h2>
	<div>
	<label for="add-uri">Website or newsfeed:</label>
	<input type="text" name="lookup" id="add-uri" value="URI" size="64" />
	<input type="hidden" name="action" value="feedfinder" />
	</div>
	<div class="submit"><input type="submit" value="Syndicate &raquo;" /></div>
	</form>
	</div>

	<form action="link-manager.php?page=<?php echo basename(__FILE__); ?>" method="post">
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
<td><a href="<?php echo wp_specialchars($link->link_url); ?>"><?php echo wp_specialchars($link->link_name); ?></a></td>
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
				<strong><a href="<?php echo $link->link_rss; ?>"><?php echo wp_specialchars($display_uri); ?></a></strong></td>
<?php
			else:
				$caption='Find Feed';
?>
				<td style="background-color:#FFFFD0"><p><strong>no
				feed assigned</strong></p></td>
<?php
			endif;
?>
			<td><a href="link-manager.php?page=<?php echo basename(__FILE__); ?>&amp;link_id=<?php echo $link->link_id; ?>&amp;action=linkedit" class="edit"><?php _e('Edit')?></a></td>
			<td><a href="link-manager.php?page=<?php echo basename(__FILE__); ?>&amp;link_id=<?php echo $link->link_id; ?>&amp;action=feedfinder" class="edit"><?php echo $caption; ?></a></td>
			<td><a href="link-manager.php?page=<?php echo basename(__FILE__); ?>&amp;link_id=<?php echo $link->link_id; ?>&amp;action=Unsubscribe" class="delete"><?php _e('Unsubscribe'); ?></a></td>
			<td><input type="checkbox" name="link_ids[]" value="<?php echo $link->link_id; ?>" /></td>
<?php
			echo "\n\t</tr>";
		endforeach;
	else:
?>

<p>There are no websites currently listed for syndication.</p>

<?php	endif; ?>
	</table>
	</div>
	
	<div class="wrap">
	<h2>Manage Multiple Links</h2>
	<div class="submit">
	<input type="submit" class="delete" name="action" value="Unsubscribe from Checked" />
	</div>
	</div>
	</form>
<?php
endif;
}

function fwp_feedfinder_page () {
	global $wpdb;

	$lookup = (isset($_REQUEST['lookup'])?$_REQUEST['lookup']:NULL);

	if (isset($_REQUEST['link_id']) and ($_REQUEST['link_id']!=0)):
		$link_id = $_REQUEST['link_id'];
		$link = $wpdb->get_row("SELECT * FROM $wpdb->links WHERE link_id='".$wpdb->escape($link_id)."'");
		if (is_object($link)):
			if (is_null($lookup)) $lookup = $link->link_url;
			$name = wp_specialchars($link->link_name);
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
				<form action="link-manager.php?page=<?php echo basename(__FILE__); ?>" method="post">
				<fieldset style="clear: both">
				<legend><?php echo $rss->feed_type; ?> <?php echo $rss->feed_version; ?> feed</legend>

				<?php if ($link_id===0): ?>
					<input type="hidden" name="feed_title" value="<?php echo wp_specialchars($feed_title); ?>" />
					<input type="hidden" name="feed_link" value="<?php echo wp_specialchars($feed_link); ?>" />
				<?php endif; ?>

				<input type="hidden" name="link_id" value="<?php echo $link_id; ?>" />
				<input type="hidden" name="feed" value="<?php echo wp_specialchars($f); ?>" />
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
				<li><strong>Feed URI:</strong> <a href="<?php echo wp_specialchars($f); ?>"><?php echo wp_specialchars($f); ?></a> <a title="Check feed &lt;<?php echo wp_specialchars($f); ?>&gt; for validity" href="http://feedvalidator.org/check.cgi?url=<?php echo urlencode($f); ?>"><img src="../wp-images/smilies/icon_arrow.gif" alt="(&rarr;)" /></a></li>
				<li><strong>Encoding:</strong> <?php echo isset($rss->encoding)?wp_specialchars($rss->encoding):"<em>Unknown</em>"; ?></li>
				<li><strong>Description:</strong> <?php echo isset($rss->channel['description'])?wp_specialchars($rss->channel['description']):"<em>Unknown</em>"; ?></li>
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

	<form action="link-manager.php?page=<?php echo basename(__FILE__); ?>" method="post">
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
<div class="updated"><p><a href="<?php echo $_REQUEST['feed_link']; ?>"><?php echo wp_specialchars($_REQUEST['feed_title']); ?></a>
has been added as a contributing site, using the newsfeed at &lt;<a href="<?php echo $_REQUEST['feed']; ?>"><?php echo wp_specialchars($_REQUEST['feed']); ?></a>&gt;.</p></div>
<?php			else: ?>
<div class="updated"><p>There was a problem adding the newsfeed. [SQL: <?php echo wp_specialchars(mysql_error()); ?>]</p></div>
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
<div class="updated"><p>Feed for <a href="<?php echo $result->link_url; ?>"><?php echo wp_specialchars($result->link_name); ?></a>
updated to &lt;<a href="<?php echo $_REQUEST['feed']; ?>"><?php echo wp_specialchars($_REQUEST['feed']); ?></a>&gt;.</p></div>
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
		'hardcode name',
		'hardcode url',
		'hardcode description',
		'hardcode categories', /* Deprecated */
		'post status',
		'comment status',
		'ping status',
		'unfamiliar author',
		'unfamliar categories',
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
		$row = $wpdb->get_row("
		SELECT * FROM $wpdb->links WHERE link_id = $link_id
		");

		if ($row) :
			if (isset($_POST['save'])) :
				$alter = array ();
				
				$meta = FeedWordPress::notes_to_settings($row->link_notes);
				if (isset($meta['cats'])):
					$meta['cats'] = preg_split(FEEDWORDPRESS_CAT_SEPARATOR_PATTERN, $meta['cats']);
				endif;

				// custom feed settings first
				foreach ($_POST['notes'] as $mn) :
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
				if (isset($_POST['hardcode_name'])) :
					$meta['hardcode name'] = $_POST['hardcode_name'];
					if (FeedWordPress::affirmative($meta, 'hardcode name')) :
						$alter[] = "link_name = '".$wpdb->escape($_POST['name'])."'";
					endif;
				endif;
				if (isset($_POST['hardcode_description'])) :
					$meta['hardcode description'] = $_POST['hardcode_description'];
					if (FeedWordPress::affirmative($meta, 'hardcode description')) :
						$alter[] = "link_description = '".$wpdb->escape($_POST['description'])."'";
					endif;
				endif;
				if (isset($_POST['hardcode_url'])) :
					$meta['hardcode url'] = $_POST['hardcode_url'];
					if (FeedWordPress::affirmative($meta, 'hardcode url')) :
						$alter[] = "link_url = '".$wpdb->escape($_POST['linkurl'])."'";
					endif;
				endif;
				
				// Update scheduling
				if (isset($_POST['update_schedule'])) :
					$meta['update/hold'] = $_POST['update_schedule'];
				endif;

				// Categories
				if (isset($_POST['post_category'])) :
					$cat_set = "(".implode(",", $_POST['post_category']).")";
					$meta['cats'] = $wpdb->get_col(
					"SELECT cat_name
					FROM $wpdb->categories
					WHERE cat_ID IN {$cat_set}
					");
					if (count($meta['cats']) == 0) :
						unset($meta['cats']);
					endif;
				else :
					unset($meta['cats']);
				endif;

				// Post status, comment status, ping status
				foreach (array('post', 'comment', 'ping') as $what) :
					$sfield = "feed_{$what}_status";
					if (isset($_POST[$sfield])) :
						if ($_POST[$sfield]=='site-default') :
							unset($meta["{$what} status"]);
						else :
							$meta["{$what} status"] = $_POST[$sfield];
						endif;
					endif;
				endforeach;

				// Unfamiliar author, unfamiliar categories
				foreach (array("author", "category") as $what) :
					$sfield = "unfamiliar_{$what}";
					if (isset($_POST[$sfield])) :
						if ($_POST[$sfield]=='site-default') :
							unset($meta["unfamiliar {$what}"]);
						else :
							$meta["unfamiliar {$what}"] = $_POST[$sfield];
						endif;
					endif;
				endforeach;
				
				if (is_array($meta['cats'])) :
					$meta['cats'] = implode(FEEDWORDPRESS_CAT_SEPARATOR, $meta['cats']);
				endif;
		
				$notes = '';
				foreach ($meta as $key => $value) :
					$notes .= $key . ": ". addcslashes($value, "\0..\37") . "\n";
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
				$row = $wpdb->get_row("
				SELECT * FROM $wpdb->links WHERE link_id = $link_id
				");
			else :
				$updated_link = false;
			endif;

			$link_url = wp_specialchars($row->link_url, 1);
			$link_name = wp_specialchars($row->link_name, 1);
			$link_image = $row->link_image;
			$link_target = $row->link_target;
			$link_category = $row->link_category;
			$link_description = wp_specialchars($row->link_description);
			$link_visible = $row->link_visible;
			$link_rating = $row->link_rating;
			$link_rel = $row->link_rel;
			$link_notes = wp_specialchars($row->link_notes);
			$link_rss_uri = wp_specialchars($row->link_rss);
			
			$meta = FeedWordPress::notes_to_settings($row->link_notes);

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
			$cats = array_map('strtolower',
				array_map('trim',
					preg_split(FEEDWORDPRESS_CAT_SEPARATOR_PATTERN, $meta['cats'])
				));
			
			foreach ($dogs as $tag => $dog) :
				if (in_array(strtolower(trim($dog['cat_name'])), $cats)) :
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
</script>

<?php if ($updated_link) : ?>
<div class="updated"><p>Syndicated feed settings updated.</p></div>
<?php endif; ?>

<form action="link-manager.php?page=<?php echo basename(__FILE__); ?>" method="post">
<div class="wrap">
<input type="hidden" name="link_id" value="<?php echo $link_id; ?>" />
<input type="hidden" name="action" value="linkedit" />
<input type="hidden" name="save" value="link" />

<h2>Edit a syndicated feed:</h2>
<fieldset><legend>Basics</legend>
<table class="editform" width="100%" cellspacing="2" cellpadding="5">
<tr>
<th scope="row" width="20%"><?php _e('Feed URI:') ?></th>
<td width="60%"><a href="<?php echo wp_specialchars($link_rss_uri); ?>"><?php echo $link_rss_uri; ?></a>
<a href="<?php echo FEEDVALIDATOR_URI; ?>?url=<?php echo urlencode($link_rss_uri); ?>"
title="Check feed &lt;<?php echo wp_specialchars($link_rss_uri); ?>&gt; for validity"><img src="../wp-images/smilies/icon_arrow.gif" alt="&rarr;" /></a>
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
<option value="no" <?php echo FeedWordPress::hardcode('name', $meta)?'':'selected="selected"'; ?>>update automatically</option>
<option value="yes" <?php echo FeedWordPress::hardcode('name', $meta)?'selected="selected"':''; ?>>edit manually</option>
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
<option value="no" <?php echo FeedWordPress::hardcode('description', $meta)?'':'selected="selected"'; ?>>update automatically</option>
<option value="yes" <?php echo FeedWordPress::hardcode('description', $meta)?'selected="selected"':''; ?>>edit manually</option>
</select></td>
</tr>
<tr>
<th width="20%" scope="row"><?php _e('Homepage:') ?></th>
<td width="60%">
<input id="basics-url-edit" type="text" name="linkurl" value="<?php echo $link_url; ?>" style="width: 95%;" />
<a id="basics-url-view" href="<?php echo $link_url; ?>"><?php echo $link_url; ?></a></td>
<td>
<select id="basics-hardcode-url" onchange="flip_hardcode('url')" name="hardcode_url">
<option value="no"<?php echo FeedWordPress::hardcode('url', $meta)?'':' selected="selected"'; ?>>update live from feed</option>
<option value="yes"<?php echo FeedWordPress::hardcode('url', $meta)?' selected="selected"':''; ?>>edit manually</option>
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

<fieldset>
<legend>Syndicated Posts</legend>

<?php fwp_category_box($dogs, 'all syndicated posts from this feed'); ?>

<table class="editform" width="80%" cellspacing="2" cellpadding="5">
<tr><th width="20%" scope="row" style="vertical-align:top">Publication:</th>
<td width="80%" style="vertical-align:top"><ul style="margin:0; list-style:none">
<li><label><input type="radio" name="feed_post_status" value="site-default"
<?php echo $status['post']['site-default']; ?> /> Use site-wide setting from <a href="options-general.php?page=<?php echo basename(__FILE__); ?>">Syndication Options</a>
(currently: <strong><?php echo FeedWordPress::syndicated_status('post', array(), 'publish'); ?></strong>)</label></li>
<li><label><input type="radio" name="feed_post_status" value="publish"
<?php echo $status['post']['publish']; ?> /> Publish posts from this feed immediately</label></li>
<li><label><input type="radio" name="feed_post_status" value="private"
<?php echo $status['post']['private']; ?> /> Hold posts from this feed as private posts</label></li>
<li><label><input type="radio" name="feed_post_status" value="draft"
<?php echo $status['post']['draft']; ?> /> Hold posts from this feed as drafts</label></li>
</ul></td>
</tr>

<tr><th width="20%" scope="row" style="vertical-align:top">Comments:</th>
<td width="80%"><ul style="margin:0; list-style:none">
<li><label><input type="radio" name="feed_comment_status" value="site-default"
<?php echo $status['comment']['site-default']; ?> /> Use site-wide setting from <a href="options-general.php?page=<?php echo basename(__FILE__); ?>">Syndication Options</a>
(currently: <strong><?php echo FeedWordPress::syndicated_status('comment', array(), 'closed'); ?>)</strong></label></li>
<li><label><input type="radio" name="feed_comment_status" value="open"
<?php echo $status['comment']['open']; ?> /> Allow comments on syndicated posts from this feed</label></li>
<li><label><input type="radio" name="feed_comment_status" value="closed"
<?php echo $status['comment']['closed']; ?> /> Don't allow comments on syndicated posts from this feed</label></li>
</ul></td>
</tr>

<tr><th width="20%" scope="row" style="vertical-align:top">Trackback and Pingback:</th>
<td width="80%"><ul style="margin:0; list-style:none">
<li><label><input type="radio" name="feed_ping_status" value="site-default"
<?php echo $status['ping']['site-default']; ?> /> Use site-wide setting from <a href="options-general.php?page=<?php echo basename(__FILE__); ?>">Syndication Options</a>
(currently: <strong><?php echo FeedWordPress::syndicated_status('ping', array(), 'closed'); ?>)</strong></label></li>
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

<fieldset>
<legend>Advanced Feed Options</legend>
<table class="editform" width="100%" cellspacing="2" cellpadding="5">
<tr>
<th width="20%" scope="row" style="vertical-align:top">Unfamiliar authors:</th>
<td width="80%"><ul style="margin: 0; list-style:none">
<li><label><input type="radio" name="unfamiliar_author" value="site-default"<?php echo $unfamiliar['author']['site-default']; ?> /> use site-wide setting from <a href="options-general.php?page=<?php echo basename(__FILE__); ?>">Syndication Options</a>
(currently <strong><?php echo FeedWordPress::on_unfamiliar('author');; ?></strong>)</label></li>
<li><label><input type="radio" name="unfamiliar_author" value="create"<?php echo $unfamiliar['author']['create']; ?>/> create a new author account</label></li>
<li><label><input type="radio" name="unfamiliar_author" value="default"<?php echo $unfamiliar['author']['default']; ?> /> attribute the post to the default author</label></li>
<li><label><input type="radio" name="unfamiliar_author" value="filter"<?php echo $unfamiliar['author']['filter']; ?> /> don't syndicate the post</label></li>
</ul></td>
</tr>

<tr>
<th width="20%" scope="row" style="vertical-align:top">Unfamiliar categories:</th>
<td width="80%"><ul style="margin: 0; list-style:none">
<li><label><input type="radio" name="unfamiliar_category" value="site-default"<?php echo $unfamiliar['category']['site-default']; ?> /> use site-wide setting from <a href="options-general.php?page=<?php echo basename(__FILE__); ?>">Syndication Options</a>
(currently <strong><?php echo FeedWordPress::on_unfamiliar('category');; ?></strong>)</label></li>
<li><label><input type="radio" name="unfamiliar_category" value="create"<?php echo $unfamiliar['category']['create']; ?> /> create any categories the post is in</label></li>
<li><label><input type="radio" name="unfamiliar_category" value="default"<?php echo $unfamiliar['category']['default']; ?> /> don't create new categories</label></li>
<li><label><input type="radio" name="unfamiliar_category" value="filter"<?php echo $unfamiliar['category']['filter']; ?> /> don't create new categories and don't syndicate posts unless they match at least one familiar category</label></li>
</ul></td>
</tr></table>
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
			<th width="30%" scope="row"><input type="hidden" name="notes[<?php echo $i; ?>][key0]" value="<?php echo wp_specialchars($key); ?>" />
			<input id="notes-<?php echo $i; ?>-key" name="notes[<?php echo $i; ?>][key1]" value="<?php echo wp_specialchars($key); ?>" /></th>
			<td width="60%"><textarea rows="2" cols="40" id="notes-<?php echo $i; ?>-value" name="notes[<?php echo $i; ?>][value]"><?php echo wp_specialchars($value); ?></textarea></td>
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

function fwp_multidelete_page () {
	global $wpdb;

	check_admin_referer(); // Make sure the referers are kosher

	$link_ids = (isset($_REQUEST['link_ids']) ? $_REQUEST['link_ids'] : array());
	if (isset($_REQUEST['link_id'])) : array_push($link_ids, $_REQUEST['link_id']); endif;

	if (!current_user_can('manage_links')):
		die (__("Cheatin' uh ?"));
	elseif (isset($_POST['confirm']) and $_POST['confirm']=='Delete'):
		foreach ($_POST['link_action'] as $link_id => $what) :
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
<form action="link-manager.php?page=<?php echo basename(__FILE__); ?>" method="post">
<div class="wrap">
<input type="hidden" name="action" value="Unsubscribe" />
<input type="hidden" name="confirm" value="Delete" />

<h2>Unsubscribe from Syndicated Links:</h2>
<?php	foreach ($targets as $link) :
		$link_url = wp_specialchars($link->link_url, 1);
		$link_name = wp_specialchars($link->link_name, 1);
		$link_description = wp_specialchars($link->link_description);
		$link_rss = wp_specialchars($link->link_rss);
		$meta = FeedWordPress::notes_to_settings($link->link_notes);
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
		generic_ping($fwp_held_ping);
	endif;
	$fwp_held_ping = NULL;	// NULL: not holding pings anymore
}

function fwp_catch_ping ($post_id = 0) {
	global $fwp_held_ping;
	if (!is_null($fwp_held_ping) and $post_id):
		$fwp_held_ping = $post_id;
	else:
		generic_ping($fwp_held_ping);
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

	var $_base = NULL;
	var $feeds = NULL;

	# function FeedWordPress (): Contructor; gain list of feeds 
	#
	# To keep things compact and editable from within WordPress, we use a
	# category of the WordPress "Links" for our list of feeds to syndicate
	# By default, we use all the links in the "Contributors" category.
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
	
	function FeedWordPress () {
		$result = FeedWordPress::syndicated_links();
	
		$feeds = array ();
		if ($result): foreach ($result as $link):
			if (strlen($link->link_rss) > 0):
				$sec = FeedWordPress::notes_to_settings($link->link_notes);
				$sec['link/uri'] = $link->link_rss;
				$sec['link/name'] = $link->link_name;
				$sec['link/id'] = $link->link_id;

				// `hardcode categories` is deprecated in favor
				// of `unfamiliar categories`
				if (
					FeedWordPress::affirmative($sec, 'hardcode categories')
					and !isset($sec['unfamiliar categories'])
				) :
					$sec['unfamiliar categories'] = 'default';
				endif;

				if (isset($sec['cats'])):
					$sec['cats'] = preg_split(FEEDWORDPRESS_CAT_SEPARATOR_PATTERN, $sec['cats']);
				endif;

				$feeds[] = $sec;
			endif;
		endforeach; endif;
		
		$this->feeds = $feeds;
	} // FeedWordPress::FeedWordPress ()

	# function notes_to_settings (): Convert WordPress Link Notes to array
	# of feed-level settings
	#
	# Arguments:
	# ----------
	# * $link_notes (string): the text from the Link Notes section of a link
	#
	# Returns:
	# --------
	# An associative array of settings stored in the Link Notes field. (For
	# the `unfamiliar authors` setting, for example, simply look up the
	# value of $meta['unfamiliar authors'], if $meta contains the value
	# returned by `notes_to_settings()`.
	#
	# Values in FeedWordPress feed settings are escaped using C-style
	# slashes. The escaped characters will already have been processed and
	# converted in the returned array.
	function notes_to_settings ($link_notes) {
		$notes = explode("\n", $link_notes);
		
		$sec = array ();
		foreach ($notes as $note):
			list($key, $value) = explode(": ", $note, 2);

			if (strlen($key) > 0) :
				// Unescape and trim() off the whitespace.
				// Thanks to Ray Lischner for pointing out the
				// need to trim off whitespace.
				$sec[$key] = stripcslashes (trim($value));
			endif;
		endforeach;
		return $sec;
	} // FeedWordPress::notes_to_settings ()

	# function update (): polls for updates on one or more Contributor feeds
	#
	# Arguments:
	# ----------
	# *    $uri (string): either the URI of the feed to poll, the URI of the
	#      website (human-readable link) whose feed you want to poll, or a
	#      "magic" tag: URI composed of the URI in the constant `RPC_MAGIC`
	#      and a "secret word" set in the FeedWordPress Options.
	#
	#      If the "magic" URI is used, then FeedWordPress will poll any
	#      feeds that are ready for polling. It will not poll feeds that are
	#      marked as "Invisible" Links (signifying that the subscription has
	#      been de-activated), or feeds that are not yet stale according to
	#      their TTL setting (which is either set in the feed, or else
	#      set randomly within a window of 30 minutes - 2 hours).
	#
	# Returns:
	# --------
	# *    Normally returns an associative array, with 'new' => the number
	#      of new posts added during the update, and 'updated' => the number
	#      of old posts that were updated during the update. If both numbers
	#      are zero, there was no change since the last poll on that URI.
	#
	# *    Returns NULL if URI it was passed was not a URI that this
	#      installation of FeedWordPress syndicates (the most common cause
	#      of this error is attempts to poll all feeds, lacking, or using
	#      an incorrect, "secret word."
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
	function update ($uri) {
		global $wpdb;

		$uri = trim($uri);

		if (FeedWordPress::needs_upgrade()) : // Will make duplicate posts if we don't hold off
			return NULL;
		endif;

		do_action('feedwordpress_update', $uri);

		// Secret voodoo tag: URI for updating *everything*.
		$secret = RPC_MAGIC.FeedWordPress::rpc_secret();

		fwp_hold_pings(); // Only send out one ping for the whole to-do

		// Loop through and check for new posts
		$delta = NULL;		
		foreach ($this->feeds as $feed) :
			$pinged_that = in_array($uri, array($secret, $feed['link/uri'], $feed['feed/link']));

			if ($uri != $secret) : // A site-specific ping always updates
				$timely = true;
			elseif (isset($feed['update/hold']) and ($feed['update/hold']=='ping')) :
				$timely = false;
			elseif (isset($feed['update/hold']) and ($feed['update/hold']=='next')) :
				$timely = true;
			elseif (!isset($feed['update/ttl']) or !isset($feed['update/last'])) :
				$timely = true;
			else :
				$after = ((int) $feed['update/last'])
					+((int) $feed['update/ttl'] * 60);
				$timely = (time() >= $after);
			endif;

			if ($pinged_that and is_null($delta)) :			// If at least one feed was hit for updating...
				$delta = array('new' => 0, 'updated' => 0);	// ... don't return error condition 
			endif;

			if ($pinged_that and $timely) :
				do_action('feedwordpress_check_feed', $feed);
				$added = $this->feed2wp($wpdb, $feed);
				if (isset($added['new'])) : $delta['new'] += $added['new']; endif;
				if (isset($added['updated'])) : $delta['updated'] += $added['updated']; endif;
			endif;
		endforeach;

		do_action('feedwordpress_update_complete', $delta);
		fwp_release_pings(); // Now that we're done, send the one ping

		return $delta;
	}

	function feed2wp ($wpdb, $f) {
		$feed = fetch_rss($f['link/uri']);
		$new_count = array('new' => 0, 'updated' => 0);

		$this->update_feed($wpdb, $feed->channel, $f);
	
		if (is_array($feed->items)) :
			foreach ($feed->items as $item) :
				$post = $this->item_to_post($wpdb, $item, $feed, $f);
				if (!is_null($post)) :
					$new = $this->add_post($wpdb, $post);
					if ( $new !== false ) $new_count[$new]++;
				endif;
			endforeach;
		endif;
		return $new_count;
	} // function feed2wp ()
		
	// FeedWordPress::flatten_array (): flatten an array. Useful for
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
	} // function FeedWordPress::flatten_array ()
	
	function resolve_relative_uri ($matches) {
		return $matches[1].Relative_URI::resolve($matches[2], $this->_base).$matches[3];
	} // function FeedWordPress::resolve_relative_uri ()
	
	function hardcode ($what, $f) {
		$default = get_settings("feedwordpress_hardcode_$what");
		if ( $default === 'yes' ) :
			// If the default is to hardcode, then we want the
			// negation of negative(): TRUE by default and FALSE if
			// the setting is explicitly "no"
			$ret = !FeedWordPress::negative($f, "hardcode $what");
		else :
			// If the default is NOT to hardcode, then we want
			// affirmative(): FALSE by default and TRUE if the
			// setting is explicitly "yes"
			$ret = FeedWordPress::affirmative($f, "hardcode $what");
		endif;
		return $ret;
	}

	function syndicated_status ($what, $f, $default) {
		global $wpdb;

		$ret = get_settings("feedwordpress_syndicated_{$what}_status");
		if ( isset($f["$what status"]) ) :
			$ret = $f["$what status"];
		elseif (!$ret) :
			$ret = $default;
		endif;
		return $wpdb->escape(trim(strtolower($ret)));
	}

	function negative ($f, $setting) {
		$nego = array ('n', 'no', 'f', 'false');
		return (isset($f[$setting]) and in_array(strtolower($f[$setting]), $nego));
	}

	function affirmative ($f, $setting) {
		$affirmo = array ('y', 'yes', 't', 'true', 1);
		return (isset($f[$setting]) and in_array(strtolower($f[$setting]), $affirmo));
	}
	
	function feed_ttl ($channel) {
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
	}

	function update_feed ($wpdb, $channel, $f) {
		$link_id = $f['link/id'];

		if (!isset($channel['id'])) :
			$channel['id'] = $f['link/uri'];
		endif;

		$update = array();
		if (!FeedWordPress::hardcode('url', $f) and isset($channel['link'])) :
			$update[] = "link_url = '".$wpdb->escape($channel['link'])."'";
		endif;

		if (!FeedWordPress::hardcode('name', $f) and isset($channel['title'])) :
			$update[] = "link_name = '".$wpdb->escape($channel['title'])."'";
		endif;

		if (!FeedWordPress::hardcode('description', $f)) :
			if (isset($channel['tagline'])) :
				$update[] = "link_description = '".$wpdb->escape($channel['tagline'])."'";
			elseif (isset($channel['description'])) :
				$update[] = "link_description = '".$wpdb->escape($channel['description'])."'";
			endif;
		endif;

		if (is_array($f['cats'])) :
			$f['cats'] = implode(FEEDWORDPRESS_CAT_SEPARATOR, $f['cats']);
		endif;

		$f = array_merge($f, $this->flatten_array($channel));
		
		$f['update/last'] = time();
		$ttl = $this->feed_ttl($channel);
		if (!is_null($ttl)) :
			$f['update/ttl'] = $ttl;
			$f['update/timed'] = 'feed';
		else :
			$f['update/ttl'] = rand(30, 120); // spread over time interval for staggered updates
			$f['update/timed'] = 'automatically';
		endif;

		if (!isset($f['update/hold']) or $f['update/hold']!='ping') :
			$f['update/hold'] = 'scheduled';
		endif;

		# -- A few things we don't want to save in the notes
		unset($f['link/id']); unset($f['link/uri']);
		unset($f['link/name']);
		unset($f['hardcode categories']); // Deprecated

		$notes = '';
		foreach ($f as $key => $value) :
			$notes .= $key . ": ". addcslashes($value, "\0..\37") . "\n";
		endforeach;
		$update[] = "link_notes = '".$wpdb->escape($notes)."'";

		$update_set = implode(',', $update);
		
		// Update the properties of the link from the feed information
		$result = $wpdb->query("
			UPDATE $wpdb->links
			SET $update_set
			WHERE link_id='$link_id'
		");
	} // function FeedWordPress::update_feed ()

	function date_created ($item) {
		if (isset($item['dc']['created'])) :
			$epoch = @parse_w3cdtf($item['dc']['created']);
		elseif (isset($item['dcterms']['created'])) :
			$epoch = @parse_w3cdtf($item['dcterms']['created']);
		elseif (isset($item['created'])): // Atom 0.3
			$epoch = @parse_w3cdtf($item['created']);
		endif;
		return $epoch;
	}

	function guid ($item, $feed) {
		if (isset($item['id'])): 			// Atom 0.3 / 1.0
			$guid = $item['id'];
		elseif (isset($item['atom']['id'])) :		// Namespaced Atom
			$guid = $item['atom']['id'];
		elseif (isset($item['guid'])) :			// RSS 2.0
			$guid = $item['guid'];
		elseif (isset($item['dc']['identifier'])) :	// yeah, right
			$guid = $item['dc']['identifier'];
		else :
			// The feed does not seem to have provided us with a
			// unique identifier, so we'll have to cobble together
			// a tag: URI that might work for us. The base of the
			// URI will be the host name of the feed source ...
			$bits = parse_url($feed['link/uri']);
			$guid = 'tag:'.$bits['host'];

			// If we have a date of creation, then we can use that
			// to uniquely identify the item. (On the other hand, if
			// the feed producer was consicentious enough to
			// generate dates of creation, she probably also was
			// conscientious enough to generate unique identifiers.)
			if (!is_null(FeedWordPress::date_created($item))) :
				$guid .= '://post.'.date('YmdHis', FeedWordPress::date_created($item));
			
			// Otherwise, use both the URI of the item, *and* the
			// item's title. We have to use both because titles are
			// often not unique, and sometimes links aren't unique
			// either (e.g. Bitch (S)HITLIST, Mozilla Dot Org news,
			// some podcasts). But it's rare to have *both* the same
			// title *and* the same link for two different items. So
			// this is about the best we can do.
			else :
				$guid .= '://'.md5($item['link'].'/'.$item['title']);
			endif;
		endif;
		return $guid;
	}

	// item_to_post(): convert information from a single item from an
	// Atom/RSS feed to a post for WordPress's database.
	//
	// item_to_post() invokes the syndicated_item filter on each item it
	// receives. Filters should return either (a) the item unmodified,
	// (b) the item modified according to the rules of the filter, or
	// (c) NULL. A NULL item will not be posted into the database.
	//
	// N.B.: item_to_post and the syndicate_item filter really ought to have
	// *no* side effects on the WordPress database (that's why, for example,
	// we handle lookup/creation of numeric author and category IDs in
	// add_post()). If you want plugins that have side effects on the posts
	// database, you should probably hook into the action
	// post_syndicated_item
	function item_to_post($wpdb, $item, $rss, $f) {
		$channel = $rss->channel;

		$post = array();

		// This is ugly as all hell. I'd like to use apply_filters()'s
		// alleged support for a variable argument count, but this seems
		// to have been broken in WordPress 1.5. It'll be fixed somehow
		// in WP 1.5.1, but I'm aiming at WP 1.5 compatibility across
		// the board here.
		//
		// Cf.: <http://mosquito.wordpress.org/view.php?id=901>
		global $fwp_channel, $fwp_feedmeta;
		$fwp_channel = $channel; $fwp_feedmeta = $f;
		
		$item = apply_filters('syndicated_item', $item);
		
		// Filters can halt further processing by returning NULL
		if (is_null($item)) :
			$post = NULL;
		else :
			$post['post_title'] = $wpdb->escape($item['title']);

			$post['named']['author'] = array ();
			
			if (isset($item['author_name'])):
				$post['named']['author']['name'] = $item['author_name'];
			elseif (isset($item['dc']['creator'])):
				$post['named']['author']['name'] = $item['dc']['creator'];
			elseif (isset($item['dc']['contributor'])):
				$post['named']['author']['name'] = $item['dc']['contributor'];
			elseif (isset($channel['dc']['creator'])) :
				$post['named']['author']['name'] = $channel['dc']['creator'];
			elseif (isset($channel['dc']['contributor'])) :
				$post['named']['author']['name'] = $channel['dc']['contributor'];
			elseif (isset($channel['author_name'])) :
				$post['named']['author']['name'] = $channel['author_name'];
			elseif ($rss->is_rss() and isset($item['author'])) :
				// The author element in RSS is allegedly an
				// e-mail address, but lots of people don't use
				// it that way. So let's make of it what we can.
				$post['named']['author'] = parse_email_with_realname($item['author']);
				
				if (!isset($post['named']['author']['name'])) :
					if (isset($post['named']['author']['email'])) :
						$post['named']['author']['name'] = $post['named']['author']['email'];
					else :
						$post['named']['author']['name'] = $channel['title'];
					endif;
				endif;
			else :
				$post['named']['author']['name'] = $channel['title'];
			endif;
			
			if (isset($item['author_email'])):
				$post['named']['author']['email'] = $item['author_email'];
			elseif (isset($channel['author_email'])) :
				$post['named']['author']['email'] = $channel['author_email'];
			endif;
			
			if (isset($item['author_url'])):
				$post['named']['author']['uri'] = $item['author_url'];
			elseif (isset($channel['author_url'])) :
				$post['named']['author']['uri'] = $item['author_url'];
			else:
				$post['named']['author']['uri'] = $channel['link'];
			endif;

			// ... So far we just have an alphanumeric
			// representation of the author. We will look up (or
			// create) the numeric ID for the author in
			// FeedWordPress::add_post()

			# Identify content and sanitize it.
			# ---------------------------------
			if (isset($item['xhtml']['body'])) :
				$content = $item['xhtml']['body'];
			elseif (isset($item['xhtml']['div'])) :
				$content = $item['xhtml']['div'];
			elseif (isset($item['content']['encoded']) and $item['content']['encoded']):
				$content = $item['content']['encoded'];
			else:
				$content = $item['description'];
			endif;
		
			# Resolve relative URIs in post content
			#
			# N.B.: We *might* get screwed over by xml:base. But I don't see
			#	any way to get that information out of MagpieRSS if it's
			#	in the feed, and if it's in the content itself we'd have
			#	to do yet more XML parsing to do things right. For now
			#	this will have to do.

			$this->_base = $item['link']; // Reset the base for resolving relative URIs
			foreach ($this->uri_attrs as $pair):
				list($tag,$attr) = $pair;
				$content = preg_replace_callback (
					":(<$tag [^>]*$attr=\")([^\">]*)(\"[^>]*>):i",
					array(&$this,'resolve_relative_uri'),
					$content
				);
			endforeach;

			# Sanitize problematic attributes
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
			if ( isset($item['description']) and $item['description'] ) :
				$excerpt = $item['description'];
			elseif ( isset($content) and $content ) :
				$excerpt = strip_tags($content);
				if (strlen($excerpt) > 255) :
					$excerpt = substr($excerpt,0,252).'...';
				endif;
			endif;

			$post['post_content'] = $wpdb->escape($content);
			
			if (!is_null($excerpt)):
				$post['post_excerpt'] = $wpdb->escape($excerpt);
			endif;
			
			# This is unneeded if wp_insert_post can be used.
			# --- cut here ---
			$post['post_name'] = sanitize_title($post['post_title']);
			# --- cut here ---

			# RSS is a fucking mess. Figure out whether we have a date in
			# dc:date, <issued>, <pubDate>, etc., and get it into Unix epoch
			# format for reformatting. If you can't find anything, use the
			# current time.
			if (isset($item['dc']['date'])):
				$post['epoch']['issued'] = parse_w3cdtf($item['dc']['date']);
			elseif (isset($item['dcterms']['issued'])) :
				$post['epoch']['issued'] = parse_w3cdtf($item['dcterms']['issued']);
			elseif (isset($item['published'])) : // Atom 1.0
				$post['epoch']['issued'] = parse_w3cdtf($item['published']);
			elseif (isset($item['issued'])): // Atom 0.3
				$post['epoch']['issued'] = parse_w3cdtf($item['issued']);
			elseif (isset($item['pubdate'])): // RSS 2.0
				$post['epoch']['issued'] = strtotime($item['pubdate']);
			else:
				$post['epoch']['issued'] = null;
			endif;

			# And again, for the created date
			$post['epoch']['created'] = FeedWordPress::date_created($item);

			# As far as I know, only atom currently has a reliable way to
			# specify when something was *modified* last
			if (isset($item['dc']['modified'])) : 			// Not really correct
				$post['epoch']['modified'] = @parse_w3cdtf($item['dc']['modified']);
			elseif (isset($item['dcterms']['modified'])) : 		// Dublin Core extensions
				$post['epoch']['modified'] = @parse_w3cdtf($item['dcterms']['modified']);
			elseif (isset($item['modified'])): 			// Atom 0.3
				$post['epoch']['modified'] = @parse_w3cdtf($item['modified']);
			elseif (isset($item['updated'])): 			// Atom 1.0
				$post['epoch']['modified'] = @parse_w3cdtf($item['updated']);
			elseif (isset($post['epoch']['issued'])) :		// Fall back to issued / dc:date
				$post['epoch']['modified'] = $post['epoch']['issued'];
			else :
				$post['epoch']['modified'] = time();
			endif;

			$post['post_date'] = date('Y-m-d H:i:s', (!is_null($post['epoch']['issued']) ? $post['epoch']['issued'] : $post['epoch']['modified']));
			$post['post_modified'] = date('Y-m-d H:i:s', $post['epoch']['modified']);
			$post['post_date_gmt'] = gmdate('Y-m-d H:i:s', (!is_null($post['epoch']['issued']) ? $post['epoch']['issued'] : $post['epoch']['modified']));
			$post['post_modified_gmt'] = gmdate('Y-m-d H:i:s', $post['epoch']['modified']);

			# Use feed-level preferences or the global default.
			$post['post_status'] = FeedWordPress::syndicated_status('post', $f, 'publish');
			$post['comment_status'] = FeedWordPress::syndicated_status('comment', $f, 'closed');
			$post['ping_status'] = FeedWordPress::syndicated_status('ping', $f, 'closed');

			// Unique ID (hopefully a unique tag: URI); failing that, the permalink
			$post['guid'] = $wpdb->escape(FeedWordPress::guid($item, $f));

			// RSS 2.0 / Atom 1.0 enclosure support
			if ( isset($item['enclosure#']) ) :
				for ($i = 1; $i <= $item['enclosure#']; $i++) :
					$eid = (($i > 1) ? "#{$id}" : "");
					$post['meta']['enclosure'][] =
						$item["enclosure{$eid}@url"]."\n".
						$item["enclosure{$eid}@length"]."\n".
						$item["enclosure{$eid}@type"];
				endfor;
			endif;

			// In case you want to point back to the blog this was syndicated from
			if (isset($channel['title'])) $post['meta']['syndication_source'] = $channel['title'];
			if (isset($channel['link'])) $post['meta']['syndication_source_uri'] = $channel['link'];
			
			// Store information on human-readable and machine-readable comment URIs
			if (isset($item['comments'])) : $post['meta']['rss:comments'] = $item['comments']; endif;
			if (isset($item['wfw']['commentrss'])) : $post['meta']['wfw:commentRSS'] = $item['wfw']['commentrss']; endif;

			// Store information to identify the feed that this came from
			$post['meta']['syndication_feed'] = $f['link/uri'];
			$post['meta']['syndication_feed_id'] = $f['link/id'];

			// In case you want to know the external permalink...
			$post['meta']['syndication_permalink'] = $item['link'];

			// Feed-by-feed options for author and category creation
			$post['named']['unfamiliar']['author'] = $f['unfamiliar author'];
			$post['named']['unfamiliar']['category'] = $f['unfamiliar categories'];

			// Categories: start with default categories
			$fc = get_settings("feedwordpress_syndication_cats");
			if ($fc) : $post['named']['preset/category'] = explode("\n", $fc);
			else : $post['named']['preset/category'] = array();
			endif;
			if (is_array($f['cats'])) :
				$post['named']['preset/category'] = array_merge($post['named']['preset/category'], $f['cats']);
			endif;

			// Now add categories from the post, if we have 'em
			$post['named']['category'] = array();
			if ( isset($item['category#']) ) :
				for ($i = 1; $i <= $item['category#']; $i++) :
					$cat_idx = (($i > 1) ? "#{$i}" : "");
					$cat = $item["category{$cat_idx}"];

					if ( strpos($f['link/uri'], 'del.icio.us') !== false ):
						$post['named']['category'] = array_merge($post['named']['category'], explode(' ', $cat));
					else:
						$post['named']['category'][] = $cat;
					endif;
				endfor;
			endif;
		endif;
		return $post;
	} // function FeedWordPress::item_to_post ()
	
	function add_post ($wpdb, $post) {
		$guid = $post['guid'];
		$result = $wpdb->get_row("
		SELECT id, guid, UNIX_TIMESTAMP(post_modified) AS modified
		FROM $wpdb->posts WHERE guid='$guid'
		");

		if (!$result) :
			$freshness = 2; // New content
		elseif ($post['epoch']['modified'] > $result->modified) :
			$freshness = 1; // Updated content
		else :
			$freshness = 0;
		endif;

		if ($freshness > 0) :
			# -- Look up, or create, numeric ID for author
			$post['post_author'] = $this->author_to_id (
				$wpdb,
				$post['named']['author']['name'],
				$post['named']['author']['email'],
				$post['named']['author']['uri'],
				FeedWordPress::on_unfamiliar('author', $post['named']['unfamiliar']['author'])
			);

			if (is_null($post['post_author'])) :
				$freshness = 0;
			else :
				# -- Look up, or create, numeric ID for categories
				$post['post_category'] = $this->lookup_categories (
					$wpdb,
					$post['named']['category'],
					FeedWordPress::on_unfamiliar('category', $post['named']['unfamiliar']['category'])
				);
				
				if (is_null($post['post_category'])) : // filter mode on, no matching categories; drop the post
					$freshness = 0;
				else : // filter mode off or at least one match; now add on the feed and global presets
					$post['post_category'] = array_merge (
						$post['post_category'],
						$this->lookup_categories (
							$wpdb,
							$post['named']['preset/category'],
							'default'
						)
					);
				endif;
			endif;
			
			unset($post['named']);
		endif;
		
		if ($freshness > 0) :
			$post = apply_filters('syndicated_post', $post);
			if (is_null($post)) $freshness = 0;
		endif;
		
		if ($freshness == 2) :
			// The item has not yet been added. So let's add it.
			$postId = $this->insert_new_post($post);
			$this->add_rss_meta($wpdb, $postId, $post);
			do_action('post_syndicated_item', $postId);

			$ret = 'new';
		elseif ($freshness == 1) :
			$post['ID'] = $result->id; $modified = $result->modified;
			$this->update_existing_post($post);
			$this->add_rss_meta($wpdb, $post['ID'], $post);
			do_action('update_syndicated_item', $post['ID']);

			$ret = 'updated';			
		else :
			$ret = false;
		endif;
		
		return $ret;
	} // function FeedWordPress::add_post ()

	function insert_new_post ($post) {
		global $wpdb;
		
		$guid = $post['guid'];

		# The right way to do this would be to use:
		#
		#	$postId = wp_insert_post($post);
		# 	$result = $wpdb->query("
		#		UPDATE $wpdb->posts
		# 		SET
		# 			guid='$guid'
		# 		WHERE post_id='$postId'
		# 	");
		#
		# in place of everything in the cut below. Alas,
		# wp_insert_post seems to be a memory hog; using it
		# to insert several posts in one session makes php
		# segfault after inserting 50-100 posts. This can get
		# pretty annoying, especially if you are trying to
		# update your feeds for the first time.
		#
		# --- cut here ---
		$result = $wpdb->query("
		INSERT INTO $wpdb->posts
		SET
			guid = '$guid',
			post_author = '".$post['post_author']."',
			post_date = '".$post['post_date']."',
			post_date_gmt = '".$post['post_date_gmt']."',
			post_content = '".$post['post_content']."',"
			.(isset($post['post_excerpt']) ? "post_excerpt = '".$post['post_excerpt']."'," : "")."
			post_title = '".$post['post_title']."',
			post_name = '".$post['post_name']."',
			post_modified = '".$post['post_modified']."',
			post_modified_gmt = '".$post['post_modified_gmt']."',
			comment_status = '".$post['comment_status']."',
			ping_status = '".$post['ping_status']."',
			post_status = '".$post['post_status']."'
		");
		$postId = $wpdb->insert_id;
		$this->add_to_category($wpdb, $postId, $post['post_category']);

		// Since we are not going through official channels, we need to
		// manually tell WordPress that we've published a new post.
		// We need to make sure to do this in order for FeedWordPress
		// to play well  with the staticize-reloaded plugin (something
		// that a large aggregator website is going to *want* to be
		// able to use).
		do_action('publish_post', $postId);
		# --- cut here ---
		
		return $postId;
	} /* FeedWordPress::insert_new_post() */

	function update_existing_post ($post) {
		global $wpdb;
		
		$guid = $post['guid'];
		$postId = $post['ID'];

		$result = $wpdb->query("
		UPDATE $wpdb->posts
		SET
			post_author = '".$post['post_author']."',
			post_content = '".$post['post_content']."',
			post_title = '".$post['post_title']."',
			post_name = '".$post['post_name']."',
			post_modified = '".$post['post_modified']."',
			post_modified_gmt = '".$post['post_modified_gmt']."'
		WHERE guid='$guid'
		");
		$this->add_to_category($wpdb, $postId, $post['post_category']);

		// Since we are not going through official channels, we need to
		// manually tell WordPress that we've published a new post.
		// We need to make sure to do this in order for FeedWordPress
		// to play well  with the staticize-reloaded plugin (something
		// that a large aggregator website is going to *want* to be
		// able to use).
		do_action('edit_post', $postId);
	} /* FeedWordPress::update_existing_post() */

	# function FeedWordPress::add_to_category ()
	#
	# If there is a way to properly hook in to wp_insert_post, then this
	# function will no longer be needed. In the meantime, here it is.
	# --- cut here ---
	function add_to_category($wpdb, $postId, $post_categories) {
		global $wp_db_version; // test for WordPress 2.0 database schema

		// Default to category 1 ("Uncategorized"), if nothing else
		if (!$post_categories) $post_categories[] = 1;

		// Now pass the buck to the WordPress API...
		wp_set_post_cats('', $postId, $post_categories);
	} // function FeedWordPress::add_to_category ()
	# --- cut here ---

	// FeedWordPress::add_rss_meta: adds interesting meta-data to each entry
	// using the space for custom keys. The set of keys and values to add is
	// specified by the keys and values of $post['meta']. This is used to
	// store anything that the WordPress user might want to access from a
	// template concerning the post's original source that isn't provided
	// for by standard WP meta-data (i.e., any interesting data about the
	// syndicated post other than author, title, timestamp, categories, and
	// guid). It's also used to hook into WordPress's support for
	// enclosures.
	function add_rss_meta ($wpdb, $postId, $post) {
		if ( is_array($post) and isset($post['meta']) and is_array($post['meta']) ) :
			foreach ( $post['meta'] as $key => $values ) :

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
	} /* FeedWordPress::add_rss_meta () */

	// FeedWordPress::author_to_id (): get the ID for an author name from
	// the feed. Create the author if necessary.
	function author_to_id ($wpdb, $author, $email, $url, $unfamiliar_author = 'create') {
		global $wp_db_version; // test for WordPress 2.0 database schema

		// Never can be too careful...
		$nice_author = sanitize_title($author);
		$reg_author = $wpdb->escape(preg_quote($author));
		$author = $wpdb->escape($author);
		$email = $wpdb->escape($email);
		$url = $wpdb->escape($url);

		// Look for an existing author record that fits...
		if (!isset($wp_db_version)) :
			#-- WordPress 1.5.x
			$id = $wpdb->get_var(
			"SELECT ID from $wpdb->users
			 WHERE
				TRIM(LCASE(user_login)) = TRIM(LCASE('$author')) OR
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
						'(^|\\n)a.k.a.( |\\t)*:?( |\\t)*',
						LCASE('$reg_author'),
						'( |\\t|\\r)*(\\n|\$)'
					)
				)
			");
		elseif ($wp_db_version >= 2966) :
			#-- WordPress 2.0+
			
			# First try the user core data table.
			$id = $wpdb->get_var(
			"SELECT ID FROM $wpdb->users
			WHERE
				TRIM(LCASE(user_login)) = TRIM(LCASE('$author'))
				OR (
					LENGTH(TRIM(LCASE(user_email))) > 0
					AND TRIM(LCASE(user_email)) = TRIM(LCASE('$email'))
				)
				OR TRIM(LCASE(user_nicename)) = TRIM(LCASE('$nice_author'))
			");

			if (is_null($id)) : # Then look for aliases in the user meta data table
				$id = $wpdb->get_var(
				"SELECT user_id FROM $wpdb->usermeta
				WHERE
					(meta_key = 'description' AND TRIM(LCASE(meta_value)) = TRIM(LCASE('$author')))
					OR (
						meta_key = 'description'
						AND LCASE(meta_value)
						RLIKE CONCAT(
							'(^|\\n)a.k.a.( |\\t)*:?( |\\t)*',
							LCASE('$reg_author'),
							'( |\\t|\\r)*(\\n|\$)'
						)
					)
				");
			endif;
		endif;

		// ... if you don't find one, then do what you need to do
		if (is_null($id)) :
			if ($unfamiliar_author === 'create') :
				if (!isset($wp_db_version)) :
					#-- WordPress 1.5.x
					$wpdb->query (
						"INSERT INTO $wpdb->users
						 SET
							ID='0',
							user_login='$author',
							user_firstname='$author',
							user_nickname='$author',
							user_nicename='$nice_author',
							user_description='$author',
							user_email='$email',
							user_url='$url'");
					$id = $wpdb->insert_id;
				elseif ($wp_db_version >= 2966) :
					#-- WordPress 2.0+
					$userdata = array();
					
					#-- user table data
					$userdata['ID'] = NULL; // new user
					$userdata['user_login'] = $author;
					$userdata['user_pass'] = FeedWordPress::rpc_secret();
					$userdata['user_email'] = $email;
					$userdata['user_url'] = $url;
					$userdata['display_name'] = $author;

					$id = wp_insert_user($userdata);
				endif;
			elseif ($unfamiliar_author === 'default') :
				$id = 1;
			endif;
		endif;
		return $id;	
	} // function FeedWordPress::author_to_id ()

	// look up (and create) category ids from a list of categories
	function lookup_categories ($wpdb, $cats, $unfamiliar_category = 'create') {
		// Normalize whitespace because (1) trailing whitespace can
		// cause PHP and MySQL not to see eye to eye on VARCHAR
		// comparisons for some versions of MySQL (cf.
		// <http://dev.mysql.com/doc/mysql/en/char.html>), and (2)
		// because I doubt most people want to make a semantic
		// distinction between 'Computers' and 'Computers  '
		$cats = array_map('trim', $cats);

		$cat_ids = array ();

		if ( count($cats) > 0 ) :
			# i'd kill for a decent map function in PHP
			# but that would require functions to be first class object,
			# or at least coderef support
			$cat_str = array ();
			$cat_aka = array ();
			foreach ( $cats as $c ) :
				$resc = $wpdb->escape(preg_quote($c));
				$esc = $wpdb->escape($c);
				$cat_str[] = "'$esc'";

				$cat_aka[] = "(LOWER(category_description)
				RLIKE CONCAT('(^|\n)a.k.a.( |\t)*:?( |\t)*', LOWER('{$resc}'), '( |\t|\r)*(\n|\$)'))";
			endforeach;

			$match_cat_name = 'cat_name IN ('.join(',', $cat_str).')';
			$match_cat_alias = join(' OR ', $cat_aka);

			$results = $wpdb->get_results(
			"SELECT
				cat_ID,
				cat_name,
				category_description
			FROM $wpdb->categories
			WHERE ($match_cat_name) OR ($match_cat_alias)"
			);
			
			$cat_ids	= array();
			$found		= array();
			
			if (!is_null($results)):
				foreach ( $results as $row ) :
					// Add existing ID to list of numerical
					// IDs to eventually place post in
					$cat_ids[] = $row->cat_ID;
					
					// Add name to list of categories not to
					// create afresh. Normalizing case with
					// strtolower() avoids mismatches in
					// VARCHAR comparison between PHP (which
					// has case-sensitive comparisons) and
					// MySQL (which has case-insensitive
					// comparisons for the field types used
					// by WordPress)
					$found[] = strtolower(trim($row->cat_name));

					// Add name of any aliases to list of
					// categories not to create afresh.
					if (preg_match_all('/^a.k.a. \s* :? \s* (.*\S) \s*$/mx',
					$row->category_description, $aka,
					PREG_PATTERN_ORDER)) :
						$found = array_merge (
							$found,
							array_map('strtolower',
							array_map('trim',
								$aka[1]
							))
						);
					endif;
				endforeach;
			endif;

			foreach ($cats as $new_cat) :
				if (($unfamiliar_category==='create') and !in_array(strtolower($new_cat), $found)) :
					$nice_cat = sanitize_title($new_cat);
					$wpdb->query(sprintf("
						INSERT INTO $wpdb->categories
						SET
							cat_name='%s',
							category_nicename='%s'
					", $wpdb->escape($new_cat), $nice_cat));
					$cat_ids[] = $wpdb->insert_id;
				endif;
			endforeach;
			
			if ((count($cat_ids) == 0) and ($unfamiliar_category === 'filter')) :
				$cat_ids = NULL; // Drop the post
			endif;
		endif;
		return $cat_ids;
	} // function FeedWordPress::lookup_categories ()
	
	function rpc_secret () {
		return get_settings('feedwordpress_rpc_secret');
	} // function FeedWordPress::rpc_secret ()

	function on_unfamiliar ($what = 'author', $override = NULL) {
		$set = array('create', 'default', 'filter');

		$ret = strtolower($override);
		if (!in_array($ret, $set)) :
			$ret = get_settings('feedwordpress_unfamiliar_'.$what);
			if (!in_array($ret, $set)) :
				$ret = 'create';
			endif;
		endif;

		return $ret;
	} // function FeedWordPress::on_unfamiliar()

	function syndicated_links () {
		$contributors = FeedWordPress::link_category_id();
		if (function_exists('get_bookmarks')) {
			$links = get_bookmarks(array("category" => $contributors));
		} else {
			$links = get_linkobjects($contributors); // deprecated as of WP 2.1
		} // if
		return $links;
	} // function syndicated_links()

	function syndicate_link ($name, $uri, $rss) {
		global $wpdb;

		// Get the category ID#
		$cat_id = FeedWordPress::link_category_id();

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
	} // function insert_syndicated_link()

	function link_category_named ($name) {
		global $wp_db_version, $wpdb;
		
		if (!isset($wp_db_version) or $wp_db_version < 4772) {
			// WordPress 1.5 and 2.0.x have segregated post and link categories
			$ct = $wpdb->linkcategories;
		} else {
			// WordPress 2.1 has a unified category table for both
			$ct = $wpdb->categories; 
		}
		return $wpdb->get_var("SELECT cat_id FROM $ct WHERE cat_name='$name'");				
	}

	function create_link_category ($name) {
		global $wp_db_version, $wpdb;

		if (!isset($wp_db_version) or $wp_db_version < 4772) {
			$result = $wpdb->query("
			INSERT INTO $wpdb->linkcategories
			SET
				cat_id = 0,
				cat_name='$name',
				show_images='N',
				show_description='N',
				show_rating='N',
				show_updated='N',
				sort_order='name'
			");
			$cat_id = $wpdb->insert_id;
		} else {
			// Why the fuck is this API function only available in a wp-admin module?
			$cat_id = wp_insert_category(array('cat_name' => $name));
		}
	}

	function link_category_id () {
		global $wpdb, $wp_db_version;

		$cat_id = get_settings('feedwordpress_cat_id');
		
		// If we don't yet *have* the category, we'll have to create it
		if ($cat_id === false) {
			$cat = $wpdb->escape(DEFAULT_SYNDICATION_CATEGORY);
			
			// Look for something with the right name...
			$cat_id = FeedWordPress::link_category_named($cat);

			// If you still can't find anything, make it for yourself.
			if (!$cat_id) {
				$cat_id = FeedWordPress::create_link_category($cat);
			}

			update_option('feedwordpress_cat_id', $cat_id);
		}
		return $cat_id;
	}

	function link_category () {
		global $wpdb, $wp_db_version;

		$cat_id = FeedWordPress::link_category_id();

		// Get the ID# for the category name...
		if (!isset($wp_db_version) or $wp_db_version < 4772) {
			// WordPress 1.5 and 2.0.x
			$cat_name = $wpdb->get_var("
			SELECT cat_name FROM $wpdb->linkcategories
			WHERE cat_id='$cat_id'
			");
		} else {
			// WordPress 2.1
			$category = get_category($cat_id);
			$cat_name = $category->cat_name;
		}
		return $cat_name;
	}

	function needs_upgrade () {
		global $wpdb;
		$fwp_db_version = get_settings('feedwordpress_version');
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
					if (!get_settings('feedwordpress_rpc_secret')) :
						update_option('feedwordpress_rpc_secret', substr(md5(uniqid(microtime())), 0, 6));
					endif;
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

} // class FeedWordPress

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
}

// take your best guess at the realname and e-mail, given a string
define('FWP_REGEX_EMAIL_ADDY', '([^@"(<\s]+@[^"@(<\s]+\.[^"@(<\s]+)');
define('FWP_REGEX_EMAIL_NAME', '("([^"]*)"|([^"<(]+\S))');
define('FWP_REGEX_EMAIL_POSTFIX_NAME', "/^\s*".FWP_REGEX_EMAIL_ADDY."\s+\(".FWP_REGEX_EMAIL_NAME."\)\s*$/");
define('FWP_REGEX_EMAIL_PREFIX_NAME', "/^\s*".FWP_REGEX_EMAIL_NAME."\s*<".FWP_REGEX_EMAIL_ADDY.">\s*$/");
define('FWP_REGEX_EMAIL_JUST_ADDY', "/^\s*".FWP_REGEX_EMAIL_ADDY."\s*$/");
define('FWP_REGEX_EMAIL_JUST_NAME', "/^\s*".FWP_REGEX_EMAIL_NAME."\s*$/");

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
