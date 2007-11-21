<?php
/*
Plugin Name: FeedWordPress
Plugin URI: http://projects.radgeek.com/feedwordpress
Description: simple and flexible Atom/RSS syndication for WordPress
Version: 0.9
Author: Charles Johnson
Author URI: http://www.radgeek.com/
*/

# Author: Charles Johnson <technophilia@radgeek.com>
# License: GPL
# Last modified: 2005-03-25
#
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

# -- Change these as you please
define ('FEEDWORDPRESS_LOG_UPDATES', true); // Make false if you hate status updates sent to error_log()

# -- Don't change these unless you know what you're doing...
define ('RPC_MAGIC', 'tag:radgeek.com/projects/feedwordpress/');
define ('FEEDWORDPRESS_VERSION', '0.9');
define ('DEFAULT_SYNDICATION_CATEGORY', 'Contributors');

// Note that the rss-functions.php that comes prepackaged with WordPress is
// old & busted. For the new hotness, drop a copy of rss-functions.php from
// this archive into wp-includes/rss-functions.php
require_once (ABSPATH . WPINC . '/rss-functions.php');

// Is this being loaded from within WordPress?
if (!isset($wp_version)):
	echo "FeedWordPress/".FEEDWORDPRESS_VERSION.": an Atom/RSS aggregator plugin for WordPress 1.5\n";
	exit;
endif;

	# Remove default WordPress auto-paragraph filter.
	remove_filter('the_content', 'wpautop');
	remove_filter('the_excerpt', 'wpautop');
	remove_filter('comment_text', 'wpautop');

	# What really should happen here is that we create our own ueber-filter,
	# to run with the highest possible priority, which would intercept
	# and check whether or not the post comes from off the wire, and
	# pre-empty any further formatting filters if it does. Then we could
	# leave wpautop in peace and not worry about Markdown, Textile, etc.
	# Sadly, WordPress 1.5 gives you no way to pre-empt downstream filters
	# (and no way for the furthest downstream filter to recover the original
	# content, either.)

	# add_filter('the_content', 'feedwordpress_preempt', 10);
	# add_filter('the_excerpt', 'feedwordpress_preempt', 10);
	# add_filter('comment_text', 'feedwordpress_preempt', 30);

	add_filter('post_link', 'syndication_permalink', 1);

	# Admin menu
	add_action('admin_menu', 'fwp_add_pages');

	# Inbound XML-RPC update methods
	add_filter('xmlrpc_methods', 'feedwordpress_xmlrpc_hook');

	# Outbound XML-RPC reform
	remove_action('publish_post', 'generic_ping');
	add_action('publish_post', 'fwp_catch_ping');

$update_logging = get_settings('feedwordpress_update_logging');

# -- Logging status updates to error_log, if you want it
if ($update_logging == 'yes') :
	add_action('post_syndicated_item', 'log_feedwordpress_post', 100);
	add_action('update_syndicated_item', 'log_feedwordpress_update_post', 100);
	add_action('feedwordpress_update', 'log_feedwordpress_update_feeds', 100);
	add_action('feedwordpress_check_feed', 'log_feedwordpress_check_feed', 100);
	add_action('feedwordpress_update_complete', 'log_feedwordpress_update_complete', 100);

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
		$uri = $feed['url']; $name = $feed['name'];
		error_log("[".date('Y-m-d H:i:s')."][feedwordpress] Examining $name <$uri>");
	}

	function log_feedwordpress_update_complete ($delta) {
		$mesg = array();
		if (isset($delta['new'])) $mesg[] = 'added '.$delta['new'].' new posts';
		if (isset($delta['updated'])) $mesg[] = 'updated '.$delta['updated'].' existing posts';
		if (empty($mesg)) $mesg[] = 'nothing changed';

		error_log("[".date('Y-m-d H:i:s')."][feedwordpress] "
			.(is_null($delta) ? "I don't syndicate <$uri>"
			: implode(' and ', $mesg)));
	}
endif;

# -- Template functions for syndication sites
function is_syndicated () { return (strlen(get_syndication_feed()) > 0); }

function the_syndication_source_link () { echo get_syndication_source_link(); }
function get_syndication_source_link () { list($n) = get_post_custom_values('syndication_source_uri'); return $n; }

function get_syndication_source () { list($n) = get_post_custom_values('syndication_source'); return $n; }
function the_syndication_source () { echo get_syndication_source(); }

function get_syndication_feed () { list($u) = get_post_custom_values('syndication_feed'); return $u; }
function the_syndication_feed () { echo get_syndication_feed (); }

function get_feed_meta ($key) {
	global $wpdb;
	$feed = get_syndication_feed();
	
	$ret = NULL;
	if (strlen($feed) > 0):
		$result = $wpdb->get_var("
		SELECT link_notes FROM $wpdb->links
		WHERE link_rss = '".$wpdb->escape($feed)."'"
		);
		
		$notes = explode("\n", $result);
		foreach ($notes as $note):
			list($k, $v) = explode(': ', $note, 2);
			$meta[$k] = $v;
		endforeach;
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

# -- Filters for templates and feeds
function syndication_permalink ($permalink = '') {
	if (get_settings('feedwordpress_munge_permalink') != 'no'):
		$uri = get_syndication_permalink();
		return ((strlen($uri) > 0) ? $uri : $permalink);
	else:
		return $permalink;
	endif;
} // function syndication_permalink ()

# -- Admin menu add-ons
function fwp_add_pages () {
	add_submenu_page('link-manager.php', 'Syndicated Sites', 'Syndicated', 5, __FILE__, 'fwp_syndication_manage_page');
	add_options_page('Syndication', 'Syndication', 6, __FILE__, 'fwp_syndication_options_page');
} // function fwp_add_pages () */

function fwp_syndication_options_page () {
        global $wpdb, $user_level;
	
	$caption = 'Save Changes';
	if (isset($_REQUEST['action']) and $_REQUEST['action']=$caption):
		check_admin_referer();

		if ($user_level < 6):
			die (__("Cheatin' uh ?"));
		else:
			update_option('feedwordpress_rpc_secret', $_REQUEST['rpc_secret']);
			update_option('feedwordpress_cat_id', $_REQUEST['syndication_category']);
			update_option('feedwordpress_munge_permalink', $_REQUEST['munge_permalink']);
			update_option('feedwordpress_update_logging', $_REQUEST['update_logging']);
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
	$results = $wpdb->get_results("SELECT cat_id, cat_name, auto_toggle FROM $wpdb->linkcategories ORDER BY cat_id");
?>
<div class="wrap">
<h2>Syndication Options</h2>
<form action="" method="post">
<fieldset class="options">
<legend>Template Options</legend>
<table class="editform" width="100%" cellspacing="2" cellpadding="5">
<tr>
<th width="33%" scope="row">Permalinks for syndicated posts point to:</th>
<td width="67%"><select name="munge_permalink" size="1">
<option value="yes"<?=($munge_permalink=='yes')?' selected="selected"':''?>>source website</option>
<option value="no"<?=($munge_permalink=='no')?' selected="selected"':''?>>this website</option>
</select></td>
</tr>
</table>
<div class="submit"><input type="submit" name="action" value="<?=$caption?>" /></div>
</fieldset>

<fieldset class="options">
<legend>Syndication Options</legend>
<table class="editform" width="100%" cellspacing="2" cellpadding="5">
<tr>
<th width="33%" scope="row">Syndicate links in category:</th>
<td width="67%"><?php
		echo "\n<select name=\"syndication_category\" size=\"1\">";
		foreach ($results as $row) {
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
</table>
<div class="submit"><input type="submit" name="action" value="<?=$caption?>" /></div>
</fieldset>

<fieldset class="options">
<legend>Back-end Options</legend>
<table class="editform" width="100%" cellspacing="2" cellpadding="5">
<tr>
<th width="33%" scope="row">XML-RPC update secret word:</th>
<td width="67%"><input id="rpc_secret" name="rpc_secret" value="<?=$rpc_secret?>" />
</td>
</tr>
<tr>
<th scope="row">Write update notices to PHP logs:</th>
<td><select name="update_logging" size="1">
<option value="yes"<?=(($update_logging=='yes')?' selected="selected"':'')?>>yes</option>
<option value="no"<?=(($update_logging!='yes')?' selected="selected"':'')?>>no</option>
</select></td>
</tr>
</table>
<div class="submit"><input type="submit" name="action" value="<?=$caption?>" /></div>
</fieldset>
</form>
</div>
<?php
}

function fwp_syndication_manage_page () {
	global $user_level, $wpdb;
?>
<?php $cont = true;
if (isset($_REQUEST['action'])):
	//die("ACTION: '".$_REQUEST['action']."'");
	if ($_REQUEST['action'] == 'feedfinder'): $cont = fwp_feedfinder_page();
	elseif ($_REQUEST['action'] == 'switchfeed'): $cont = fwp_switchfeed_page();
	elseif ($_REQUEST['action'] == 'Delete Checked'): $cont = fwp_multidelete_page();
	endif;
endif;

if ($cont):
?>
<?php
	$links = get_linkobjects(FeedWordPress::link_category_id());
?>
	<div class="wrap">
	<form action="link-manager.php?page=feedwordpress.php" method="post">
	<h2>Syndicate a new site:</h2>
	<div>
	<label for="add-uri">Website or newsfeed:</label>
	<input type="text" name="lookup" id="add-uri" value="URI" size="64" />
	<input type="hidden" name="action" value="feedfinder" />
	</div>
	<div class="submit"><input type="submit" value="Syndicate &raquo;" /></div>
	</form>
	</div>

	<form action="link-manager.php?page=feedwordpress.php" method="post">
	<div class="wrap">
	<h2>Syndicated Sites</h2>
<?php	$alt_row = true;
	if ($links): ?>

<table width="100%" cellpadding="3" cellspacing="3">
<tr>
<th width="20%"><?php _e('Name'); ?></th>
<th width="50%"><?php _e('Feed'); ?></th>
<th colspan="4"><?php _e('Action'); ?></th>
</tr>

<?php		foreach ($links as $link):
			$alt_row = !$alt_row; ?>
<tr<?=($alt_row?' class="alternate"':'')?>>
<td><a href="<?=wp_specialchars($link->link_url)?>"><?=wp_specialchars($link->link_name)?></a></td>
<?php 			if (strlen($link->link_rss) > 0): $caption='Switch Feed'; ?>
<td style="font-size:smaller;text-align:center">
<strong><a href="<?=$link->link_rss?>"><?=wp_specialchars($link->link_rss)?></a></strong>
<em>check validity</em> <a style="vertical-align:middle"
title="Check feed &lt;<?=wp_specialchars($link->link_rss)?>&gt; for validity"
href="http://feedvalidator.org/check.cgi?url=<?=urlencode($link->link_rss)?>"><img
src="../wp-images/smilies/icon_arrow.gif" alt="&rarr;" /></a></td>
<?php			else: $caption='Find Feed'; ?>
<td style="background-color:#FFFFD0"><p><strong>no
feed assigned</strong></p></td>
<?		endif; ?>
<?php		if (($link->user_level <= $user_level)): ?>
			<td><a href="link-manager.php?page=feedwordpress.php&amp;link_id=<?=$link->link_id?>&amp;action=feedfinder" class="edit"><?=$caption?></a></div></td>
			<td><a href="link-manager.php?link_id=<?=$link->link_id?>&amp;action=linkedit" class="edit"><?php _e('Edit')?></a></td>
			<td><a href="link-manager.php?link_id=<?=$link->link_id?>&amp;action=Delete" onclick="return confirm('You are about to delete this link.\\n  \'Cancel\' to stop, \'OK\' to delete.');" class="delete"><?php _e('Delete'); ?></a></td>
			<td><input type="checkbox" name="linkcheck[]" value="<?=$link->link_id?>" /></td>
<?php		else:
			echo "<td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td><td>&nbsp;</td>\n";
		endif;
		echo "\n\t</tr>";
?>
		</tr>
<?php
		endforeach;
	else: ?>

<p>There are no websites currently listed for syndication.</p>

<?php	endif; ?>
	</table>
	</div>
	
	<div class="wrap">
	<h2>Manage Multiple Links</h2>
	<div class="submit">
	<input type="submit" class="delete" name="action" value="Delete Checked" />
	</div>
	</div>
	</form>
<?php
endif;
}

function fwp_feedfinder_page () {
	global $user_level, $wpdb;

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
	<h2>Feed Finder: <?=$name?></h2>
<?php	$f =& new FeedFinder($lookup);
	$feeds = $f->find();
	if (count($feeds) > 0):
		foreach ($feeds as $key => $f):
			$rss = fetch_rss($f);
			if ($rss):
				$feed_title = isset($rss->channel['title'])?$rss->channel['title']:$rss->channel['link'];
				$feed_link = isset($rss->channel['link'])?$rss->channel['link']:'';
?>
				<form action="link-manager.php?page=feedwordpress.php" method="post">
				<fieldset style="clear: both">
				<legend><?=$rss->feed_type?> <?=$rss->feed_version?> feed</legend>

				<?php if ($link_id===0): ?>
					<input type="hidden" name="feed_title" value="<?=wp_specialchars($feed_title)?>" />
					<input type="hidden" name="feed_link" value="<?=wp_specialchars($feed_link)?>" />
				<?php endif; ?>

				<input type="hidden" name="link_id" value="<?=$link_id?>" />
				<input type="hidden" name="feed" value="<?=wp_specialchars($f)?>" />
				<input type="hidden" name="action" value="switchfeed" />

				<div>
				<div style="float:right; background-color:#D0D0D0; color: black; width:45%; font-size:70%; border-left: 1px dotted #A0A0A0; padding-left: 0.5em; margin-left: 1.0em">
<?php				if (count($rss->items) > 0): ?>
					<?php $item = $rss->items[0]; ?>
					<h3>Sample Item</h3>
					<ul>
					<li><strong>Title:</strong> <a href="<?=$item['link']?>"><?=$item['title']?></a></li>
					<li><strong>Date:</strong> <?=isset($item['date_timestamp']) ? date('d-M-y g:i:s a', $item['date_timestamp']) : 'unknown'?></li>
					</ul>
					<div class="entry">
					<?=(isset($item['content']['encoded'])?$item['content']['encoded']:$item['description'])?>
					</div>
<?php				else: ?>
					<h3>No Items</h3>
					<p>FeedWordPress found no posts on this feed.</p>
<?php				endif; ?>
				</div>

				<div>
				<h3>Feed Information</h3>
				<ul>
				<li><strong>Website:</strong> <a href="<?=$feed_link?>"><?=is_null($feed_title)?'<em>Unknown</em>':$feed_title?></a></li>
				<li><strong>Feed URI:</strong> <a href="<?=wp_specialchars($f)?>"><?=wp_specialchars($f)?></a> <a title="Check feed &lt;<?=wp_specialchars($f)?>&gt; for validity" href="http://feedvalidator.org/check.cgi?url=<?=urlencode($f)?>"><img src="../wp-images/smilies/icon_arrow.gif" alt="(&rarr;)" /></a></li>
				<li><strong>Encoding:</strong> <?=isset($rss->encoding)?wp_specialchars($rss->encoding):"<em>Unknown</em>"?></li>
				<li><strong>Description:</strong> <?=isset($rss->channel['description'])?wp_specialchars($rss->channel['description']):"<em>Unknown</em>"?></li>
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

	<form action="link-manager.php?page=feedwordpress.php" method="post">
	<div class="wrap">
	<h2>Use another feed</h2>
	<div><label>Feed:</label>
	<input type="text" name="lookup" value="URI" />
	<input type="hidden" name="link_id" value="<?=$link_id?>" />
	<input type="hidden" name="action" value="feedfinder" /></div>
	<div class="submit"><input type="submit" value="Use this feed &raquo;" /></div>
	</div>
	</form>
<?php
	return false; // Don't continue
}

function fwp_switchfeed_page () {
	global $wpdb, $user_level;

	check_admin_referer();
	if (!isset($_REQUEST['Cancel'])):
		if ($user_level < 5):
			die (__("Cheatin' uh ?"));
		elseif (isset($_REQUEST['link_id']) and ($_REQUEST['link_id']==0)):
			// Get the category ID#
			$cat_id = FeedWordPress::link_category_id();
			$result = $wpdb->query("
			INSERT INTO $wpdb->links
			SET
				link_name = '".$wpdb->escape($_REQUEST['feed_title'])."',
				link_url = '".$wpdb->escape($_REQUEST['feed_link'])."',
				link_category = '".$wpdb->escape($cat_id)."',
				link_rss = '".$wpdb->escape($_REQUEST['feed'])."'
			");

			if ($result): ?>
<div class="updated"><p><a href="<?=$_REQUEST['feed_link']?>"><?=wp_specialchars($_REQUEST['feed_title'])?></a>
has been added as a contributing site, using the newfeed at &lt;<a href="<?=$_REQUEST['feed']?>"><?=wp_specialchars($_REQUEST['feed'])?></a>&gt;.</p></div>
<?php			else: ?>
<div class="updated"><p>There was a problem adding the newsfeed. [SQL: <?=wp_specialchars(mysql_error())?>]</p></div>
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
<div class="updated"><p>Feed for <a href="<?=$result->link_url?>"><?=wp_specialchars($result->link_name)?></a>
updated to &lt;<a href="<?=$_REQUEST['feed']?>"><?=wp_specialchars($_REQUEST['feed'])?></a>&gt;.</p></div>
			<?php else: ?>
<div class="updated"><p>Nothing was changed.</p></div>
			<?php endif;
		endif;
	endif;
	return true; // Continue
}

function fwp_multidelete_page () {
	global $wpdb, $user_level;
	check_admin_referer();
	if ($user_level < 5):
		die (__("Cheatin' uh ?"));
	else:
		// Update link_rss
		$result = $wpdb->query("
		DELETE FROM $wpdb->links
		WHERE link_id IN (".implode(',',$_REQUEST['linkcheck']).")
		");

		if ($result):
			$mesg = "Sites deleted from syndication list.";
		else:
			$mesg = "There was a problem deleting the sites from the syndication list. [SQL: ".mysql_error()."]";
		endif;
		echo "<div class=\"updated\">$mesg</div>\n";
	endif;
	return true;
}

# -- Outbound XML-RPC ping reform
# 'coz it's rude to send 500 pings the first time your aggregator runs
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

// class FeedWordPress: handle the updating of the feeds and plug in to the
// XML-RPC interface
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
	#		cats: computers:web
	#		feed/key: value
	#
	#	Keys that start with "feed/" are gleaned from the data supplied
	#	by the feed itself, and will be overwritten with each update.
	#
	#	The value of `cats` is used as a colon-separated (:) list of
	#	default categories for any post coming from a particular feed. 
	#	(In the example above, any posts from this feed will be placed
	#	in the "computers" and "web" categories--*in addition to* any
	#	categories that may already be applied to the posts.)
	#
	#	Values of keys in link_notes are accessible from templates using
	#	the function `get_feed_meta($key)` if this plugin is activated.
	
	function FeedWordPress () {
		$result = get_linkobjects(FeedWordPress::link_category_id());
	
		$feeds = array ();
		if ($result): foreach ($result as $link):
			$sec = array ();

			if (strlen($link->link_rss) > 0):
				$notes = explode("\n", $link->link_notes);
				foreach ($notes as $note):
					list($key, $value) = explode(": ", $note, 2);
					if (strlen($key) > 0) $sec[$key] = $value;
				endforeach;

				$sec['url'] = $link->link_rss;
				$sec['name'] = $link->link_name;

				if (isset($sec['cats'])):
					$sec['cats'] = explode(':',$sec['cats']);
				endif;
				$sec['link_id'] = $link->link_id;

				$feeds[] = $sec;
			endif;
		endforeach; endif;
		
		$this->feeds = $feeds;
	} // function acquire_feeds ()

	function update ($uri) {
		global $wpdb;
		
		do_action('feedwordpress_update', $uri);

		// Secret voodoo tag: URI for updating *everything*.
		$secret = RPC_MAGIC.FeedWordPress::rpc_secret();

		fwp_hold_pings();

		// Loop through and check for new posts
		$delta = NULL;		
		foreach ($this->feeds as $feed) {
			if (($uri === $secret)
			or ($uri === $feed['url'])
			or ($uri === $feed['feed/link'])) {
				if (is_null($delta)) $delta = array('new' => 0, 'updated' => 0);
				do_action('feedwordpress_check_feed', array($feed));
				$added = $this->feed2wp($wpdb, $feed);
				if (isset($added['new'])) $delta['new'] += $added['new'];
				if (isset($added['updated'])) $delta['updated'] += $added['updated'];
			} /* if */
		} /* foreach */
		
		do_action('feedwordpress_update_complete', array($delta));
		fwp_release_pings();

		return $delta;
	}

	function feed2wp ($wpdb, $f) {
		$feed = fetch_rss($f['url']);
		$new_count = array('new' => 0, 'updated' => 0);

		$this->update_feed($wpdb, $feed->channel, $f);
	
		if (is_array($feed->items)) :
			foreach ($feed->items as $item) :
				$post = $this->item_to_post($wpdb, $item, $feed->channel, $f);
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
		if (is_array($arr)):
			foreach ($arr as $key => $value) {
				if (is_scalar($value)) {
					$ret[$prefix.$key] = $value;
				} else {
					$ret = array_merge($ret, $this->flatten_array($value, $prefix.$key.$separator, $separator));
				} /* if */
			} /* foreach */
		endif;
		return $ret;
	} // function FeedWordPress::flatten_array ()
	
	function resolve_relative_uri ($matches) {
		return $matches[1].Relative_URI::resolve($matches[2], $this->_base).$matches[3];
	} // function FeedWordPress::resolve_relative_uri ()
	
	function update_feed ($wpdb, $channel, $f) {
		$affirmo = array ('y', 'yes', 't', 'true', 1);
	
		$link_id = $f['link_id'];

		if (!isset($channel['id'])) {
		  $channel['id'] = $f['url'];
		}

		$update = array();
		if (isset($channel['link'])) {
			$update[] = "link_url = '".$wpdb->escape($channel['link'])."'";
		}
		if (isset($channel['title']) and (!isset($f['hardcode name'])
		or in_array(trim(strtolower($f['hardcode name'])), $affirmo))) {
			$update[] = "link_name = '".$wpdb->escape($channel['title'])."'";
		}

		if (isset($channel['tagline'])) {
		  $update[] = "link_description = '".$wpdb->escape($channel['tagline'])."'";
		} elseif (isset($channel['description'])) {
		  $update[] = "link_description = '".$wpdb->escape($channel['description'])."'";
		}

		if (is_array($f['cats'])) {
		  $f['cats'] = implode(':',$f['cats']);
		} /* if */

		$f = array_merge($f, $this->flatten_array($channel));

		# -- A few things we don't want to save in the notes
		unset($f['link_id']); unset($f['uri']); unset($f['url']);

		$notes = '';
		foreach ($f as $key => $value) {
		  $notes .= "${key}: $value\n";
		}
		$update[] = "link_notes = '".$wpdb->escape($notes)."'";

		$update_set = implode(',', $update);
		
		// if we've already have this feed, update
		$result = $wpdb->query("
			UPDATE $wpdb->links
			SET $update_set
			WHERE link_id='$link_id'
		");
	} // function FeedWordPress::update_feed ()
	
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
	//
	function item_to_post($wpdb, $item, $channel, $f) {
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
			if (isset($item['dc']['creator'])):
				$post['named']['author']['name'] = $item['dc']['creator'];
			elseif (isset($item['dc']['creator'])):
				$post['named']['author']['name'] = $item['dc']['contributor'];
			elseif (isset($item['author_name'])):
				$post['named']['author']['name'] = $item['author_name'];
			else:
				$post['named']['author']['name'] = $channel['title'];
			endif;
			
			if (isset($item['author_email'])):
				$post['named']['author']['email'] = $item['author_email'];
			endif;
			
			if (isset($item['author_url'])):
				$post['named']['author']['url'] = $item['author_url'];
			else:
				$post['named']['author']['url'] = $channel['link'];
			endif;

			// ... So far we just have an alphanumeric
			// representation of the author. We will look up (or
			// create) the numeric ID for the author in
			// FeedWordPress::add_post()

			# Identify content and sanitize it.
			# ---------------------------------
			if (isset($item['content']['encoded']) and $item['content']['encoded']):
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
		
			$post['post_content'] = $wpdb->escape($content);
				
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
			elseif (isset($item['issued'])):
				$post['epoch']['issued'] = parse_w3cdtf($item['issued']);
			elseif (isset($item['pubdate'])):
				$post['epoch']['issued'] = strtotime($item['pubdate']);
			else:
				$post['epoch']['issued'] = time();
			endif;
	
			# As far as I know, only atom currently has a reliable way to
			# specify when something was *modified* last
			if (isset($item['modified'])):
				$post['epoch']['modified'] = parse_w3cdtf($item['modified']);
			else:
				$post['epoch']['modified'] = $post['epoch']['issued'];
			endif;
	
			$post['post_date'] = date('Y-m-d H:i:s', $post['epoch']['issued']);
			$post['post_modified'] = date('Y-m-d H:i:s', $post['epoch']['modified']);
			$post['post_date_gmt'] = gmdate('Y-m-d H:i:s', $post['epoch']['issued']);
			$post['post_modified_gmt'] = gmdate('Y-m-d H:i:s', $post['epoch']['modified']);
			
			# Use feed-level preferences or a sensible default.
			$post['post_status'] = (isset($f['post status']) ? $wpdb->escape(trim(strtolower($f['post status']))) : 'publish');
			$post['comment_status'] = (isset($f['comment status']) ? $wpdb->escape(trim(strtolower($f['comment status']))) : 'closed');
			$post['ping_status'] = (isset($f['ping status']) ? $wpdb->escape(trim(strtolower($f['ping status']))) : 'closed');
	
			// Unique ID (hopefully a unique tag: URI); failing that, the permalink
			if (isset($item['id'])):
				$post['guid'] = $wpdb->escape($item['id']);
			else:
				$post['guid'] = $wpdb->escape($item['link']);
			endif;
	
			if (isset($channel['title'])) $post['syndication_source'] = $channel['title'];
			if (isset($channel['link'])) $post['syndication_source_uri'] = $channel['link'];
			$post['syndication_feed'] = $f['url'];
	
			// In case you want to know the external permalink...
			$post['syndication_permalink'] = $item['link'];

			// Categories: start with default categories
			$post['named']['category'] = $f['cats'];

			// Now add categories from the post, if we have 'em
			if (is_array($item['categories'])):
				foreach ($item['categories'] as $cat):
					if ( strpos($f['url'], 'del.icio.us') !== false ):
						$post['named']['category'] = array_merge($post['named']['category'], explode(' ', $cat));
					else:
						$post['named']['category'][] = $cat;
					endif;
				endforeach;
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

		if (!$result):
			$freshness = 2; // New content
		elseif ($post['epoch']['modified'] > $result->modified):
			$freshness = 1; // Updated content
		else:
			$freshness = 0;
		endif;

		if ($freshness > 0) :
			# -- Look up, or create, numeric ID for author
			$post['post_author'] = $this->author_to_id (
				$wpdb,
				$post['named']['author']['name'],
				$post['named']['author']['email'],
				$post['named']['author']['url']
			);
			
			# -- Look up, or create, numeric ID for categories
			$post['post_category'] = $this->lookup_categories (
				$wpdb,
				$post['named']['category']
			);
		
			unset($post['named']);
		endif;
		
		$post = apply_filters('syndicated_post', $post);
		if (is_null($post)) $freshness = 0;

		if ($freshness == 2) :
			// The item has not yet been added. So let's add it.

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
				post_content = '".$post['post_content']."',
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

			$this->add_rss_meta($wpdb, $postId, $post);

			do_action('post_syndicated_item', $postId);

			$ret = 'new';
		elseif ($freshness == 1) :
			$postId = $result->id; $modified = $result->modified;

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

			$this->add_rss_meta($wpdb, $postId, $post);
			
			do_action('update_syndicated_item', $postId);

			$ret = 'updated';			
		else:
			$ret = false;
		endif;
		
		return $ret;
	} // function FeedWordPress::add_post ()
	
	# function FeedWordPress::add_to_category ()
	#
	# If there is a way to properly hook in to wp_insert_post, then this
	# function will no longer be needed. In the meantime, here it is.
	# --- cut here ---
	function add_to_category($wpdb, $postId, $post_categories) {
		// Default to category 1 ("Uncategorized"), if nothing else
		if (!$post_categories) $post_categories[] = 1;

		// Clean the slate (in case we're updating)
		$results = $wpdb->query("
		DELETE FROM $wpdb->post2cat
		WHERE post_id = $postId
		");

		foreach ($post_categories as $post_category):
			$results = $wpdb->query("
			INSERT INTO $wpdb->post2cat
			SET
				post_id = $postId,
				category_id = $post_category
			");
		endforeach;
	} // function FeedWordPress::add_to_category ()
	# --- cut here ---

	// FeedWordPress::add_rss_meta: adds feed meta-data to user-defined keys
	// for each entry. Interesting feed meta-data is tagged in the $post
	// array using the prefix 'syndication_'. This should be used for
	// anything that the WordPress user might want to access about a post's
	// original source that isn't provided for by standard WP meta-data
	// (i.e., beyond author, title, timestamp, and categories)
	function add_rss_meta ($wpdb, $postId, $post) {
		foreach ($post as $key => $value):
			if (strpos($key, "syndication_") === 0):
				$value = $wpdb->escape($value);

				$result = $wpdb->query("
				DELETE FROM $wpdb->postmeta
				WHERE post_id='$postId' AND meta_key='$key'
				");

				$result = $wpdb->query("
				INSERT INTO $wpdb->postmeta
				SET
					post_id='$postId',
					meta_key='$key',
					meta_value='$value'
				");
			endif;
		endforeach;
	} /* FeedWordPress::add_rss_meta () */

	// FeedWordPress::author_to_id (): get the ID for an author name from
	// the feed. Create the author if necessary.
	function author_to_id ($wpdb, $author, $email, $url) {
		// Never can be too careful...
		$nice_author = sanitize_title($author);
		$author = $wpdb->escape($author);
		$email = $wpdb->escape($email);
		$url = $wpdb->escape($url);
	
		$id = $wpdb->get_var(
				"SELECT ID from $wpdb->users
				 WHERE
					user_login = '$author' OR
					user_firstname = '$author' OR
					user_nickname = '$author' OR
					user_description = '$author' OR
					user_nicename = '$nice_author'");
	
		if (is_null($id)):
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
		endif;
		return $id;	
	} // function FeedWordPress::author_to_id ()
	
	// look up (and create) category ids from a list of categories
	function lookup_categories ($wpdb, $cats) {
		if ( !count($cats) ) return array();
		
		# i'd kill for a decent map function in PHP
		# but that would require functiosn to be first class object, or at least
		# coderef support
		$cat_strs = array();
		foreach ( $cats as $c ) {
			$c = $wpdb->escape($c); $c = "'$c'"; 
			$cat_strs[] = $c;	
		}
	
		$cat_sql = join(',', $cat_strs);
		$sql = "SELECT cat_ID,cat_name from $wpdb->categories WHERE cat_name IN ($cat_sql)";
		$results = $wpdb->get_results($sql);
		
		$cat_ids 	= array();
		$cat_found	= array();
		
		if (!is_null($results)):
			foreach ( $results as $row ) {
				$cat_ids[] = $row->cat_ID;
				$cat_found[] = strtolower($row->cat_name); // Normalize to avoid case problems
			}
		endif;
	
		foreach ($cats as $new_cat):
			$sql =  "INSERT INTO $wpdb->categories (cat_name, category_nicename) 
				VALUES ('%s', '%s')";
			if (!in_array(strtolower($new_cat), $cat_found)):
				$nice_cat = sanitize_title($new_cat);
				$wpdb->query(sprintf($sql, $wpdb->escape($new_cat), $nice_cat));
				$cat_ids[] = $wpdb->insert_id;
			endif;
		endforeach;
		return $cat_ids;
	} // function FeedWordPress::lookup_categories ()
	
	function rpc_secret () {
		return get_settings('feedwordpress_rpc_secret');
	} // function FeedWordPress::rpc_secret ()
                                                                
	function link_category_id () {
		global $wpdb;

		$cat_id = get_settings('feedwordpress_cat_id');
		
		// If we don't yet *have* the category, we'll have to create it
		if ($cat_id === false) {
			$cat = $wpdb->escape(DEFAULT_SYNDICATION_CATEGORY);

			// Look for something with the right name...
			$cat_id = $wpdb->get_var("
				SELECT cat_id FROM $wpdb->linkcategories
				WHERE cat_name='$cat'
			");
			
			// If you still can't find anything, make it for yourself.
			if (!$cat_id) {
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
			}
			
			update_option('feedwordpress_cat_id', $cat_id);
		}
		return $cat_id;
	}

	function link_category () {
		global $wpdb;

		$cat_id = FeedWordPress::link_category_id();

		// Get the ID# for the category name...
		$cat_name = $wpdb->get_var("
		SELECT cat_name FROM $wpdb->linkcategories
		WHERE cat_id='$cat_id'
		");
		return $cat_name;
	}
} // class FeedWordPress

# -- Inbound XML-RPC plugin interface
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
		if ($this->_cache_uri !== $this->uri) {
			// Retrieve, with headers, using cURL
			$ch = curl_init($this->uri);
			curl_setopt($ch, CURLOPT_HEADER, false);
			curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('Connection: close'));
			curl_setopt($ch, CURLOPT_HTTPHEADER, array('User-Agent: feedfinder/1.2 (compatible; PHP FeedFinder) +http://projects.radgeek.com/feedwordpress'));
			curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
			curl_setopt($ch, CURLOPT_TIMEOUT, 15);
			$response = curl_exec($ch);
			curl_close($ch);

			// Split into headers and content
			$this->_data = $response;

			// Kilroy was here
			$this->_cache_uri = $this->uri;
		} /* if */
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
		$uri_parts['query'] = $parts['query'];

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
?>
