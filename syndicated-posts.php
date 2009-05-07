<?php
require_once(dirname(__FILE__) . '/admin-ui.php');

function fwp_posts_page () {
	global $wpdb, $wp_db_version;

	FeedWordPressCompatibility::validate_http_request(/*action=*/ 'feedwordpress_author_settings', /*capability=*/ 'manage_links');

	if (isset($GLOBALS['fwp_post']['save']) or isset($GLOBALS['fwp_post']['fix_mismatch'])) :
		$link_id = $_REQUEST['save_link_id'];
	elseif (isset($_REQUEST['link_id'])) :
		$link_id = $_REQUEST['link_id'];
	else :
		$link_id = NULL;
	endif;

	if (is_numeric($link_id) and $link_id) :
		$link =& new SyndicatedLink($link_id);
	else :
		$link = NULL;
	endif;

	$mesg = null;

	////////////////////////////////////////////////
	// Process POST request, if any /////////////////
	////////////////////////////////////////////////
	if (isset($GLOBALS['fwp_post']['save'])) :
		if (is_object($link) and $link->found()) :
			$alter = array ();

			// Post status, comment status, ping status
			foreach (array('post', 'comment', 'ping') as $what) :
				$sfield = "feed_{$what}_status";
				if (isset($GLOBALS['fwp_post'][$sfield])) :
					if ($GLOBALS['fwp_post'][$sfield]=='site-default') :
						unset($link->settings["{$what} status"]);
					else :
						$link->settings["{$what} status"] = $GLOBALS['fwp_post'][$sfield];
					endif;
				endif;
			endforeach;
			
			$alter[] = "link_notes = '".$wpdb->escape($link->settings_to_notes())."'";

			$alter_set = implode(", ", $alter);

			// issue update query
			$result = $wpdb->query("
			UPDATE $wpdb->links
			SET $alter_set
			WHERE link_id='$link_id'
			");
			$updated_link = true;

			// reload link information from DB
			if (function_exists('clean_bookmark_cache')) :
				clean_bookmark_cache($link_id);
			endif;
			$link =& new SyndicatedLink($link_id);
		else :
			// update_option ...
			if (isset($GLOBALS['fwp_post']['feed_post_status'])) :
				update_option('feedwordpress_syndicated_post_status', $GLOBALS['fwp_post']['feed_post_status']);
			endif;
			
			update_option('feedwordpress_munge_permalink', $_REQUEST['munge_permalink']);
			update_option('feedwordpress_use_aggregator_source_data', $_REQUEST['use_aggregator_source_data']);
			update_option('feedwordpress_formatting_filters', $_REQUEST['formatting_filters']);

			if (isset($_REQUEST['feed_comment_status']) and ($_REQUEST['feed_comment_status'] == 'open')) :
				update_option('feedwordpress_syndicated_comment_status', 'open');
			else :
				update_option('feedwordpress_syndicated_comment_status', 'closed');
			endif;
	
			if (isset($_REQUEST['feed_ping_status']) and ($_REQUEST['feed_ping_status'] == 'open')) :
				update_option('feedwordpress_syndicated_ping_status', 'open');
			else :
				update_option('feedwordpress_syndicated_ping_status', 'closed');
			endif;
	
			$updated_link = true;
		endif;
	else :
		$updated_link = false;
	endif;

	////////////////////////////////////////////////
	// Get defaults from database //////////////////
	////////////////////////////////////////////////
	$unfamiliar = array ('create' => '','default' => '','filter' => '');

	$post_status_global = get_option('feedwordpress_syndicated_post_status');
	if (!$post_status_global) : $post_status_global = 'publish'; endif;
	
	$comment_status_global = get_option('feedwordpress_syndicated_comment_status');
	if (!$comment_status_global) : $comment_status_global = 'closed'; endif;

	$ping_status_global = get_option('feedwordpress_syndicated_ping_status');
	if (!$ping_status_global) : $ping_status_global = 'closed'; endif;

	$status['post'] = array('publish' => '', 'private' => '', 'draft' => '');
	if (SyndicatedPost::use_api('post_status_pending')) :
		$status['post']['pending'] = '';
	endif;

	$status['comment'] = array('open' => '', 'closed' => '');
	$status['ping'] = array('open' => '', 'closed' => '');

	if (is_object($link) and $link->found()) :
		$thePostsPhrase = __('posts from this feed');

		foreach (array('post', 'comment', 'ping') as $what) :
			$status[$what]['site-default'] = '';
			if (isset($link->settings["{$what} status"])) :
				$status[$what][$link->settings["{$what} status"]] = ' checked="checked"';
			else :
				$status[$what]['site-default'] = ' checked="checked"';
			endif;
		endforeach;
	else :
		$thePostsPhrase = __('syndicated posts');

		$status['post'][$post_status_global] = ' checked="checked"';
		$status['comment'][$comment_status_global] = ' checked="checked"';
		$status['ping'][$ping_status_global] = ' checked="checked"';

		$munge_permalink = get_option('feedwordpress_munge_permalink');
		$formatting_filters = get_option('feedwordpress_formatting_filters');
		$use_aggregator_source_data = get_option('feedwordpress_use_aggregator_source_data');
	endif;

	$unfamiliar[$key] = ' selected="selected"';

	$match_author_by_email = !('yes' == get_option("feedwordpress_do_not_match_author_by_email"));
	$null_emails = FeedWordPress::null_email_set();
?>
<script type="text/javascript">
	function contextual_appearance (item, appear, disappear, value, visibleStyle, checkbox) {
		if (typeof(visibleStyle)=='undefined') visibleStyle = 'block';

		var rollup=document.getElementById(item);
		var newuser=document.getElementById(appear);
		var sitewide=document.getElementById(disappear);
		if (rollup) {
			if ((checkbox && rollup.checked) || (!checkbox && value==rollup.value)) {
				if (newuser) newuser.style.display=visibleStyle;
				if (sitewide) sitewide.style.display='none';
			} else {
				if (newuser) newuser.style.display='none';
				if (sitewide) sitewide.style.display=visibleStyle;
			}
		}
	}
</script>

<?php if ($updated_link) : ?>
<div class="updated"><p>Syndicated author settings updated.</p></div>
<?php elseif (!is_null($mesg)) : ?>
<div class="updated"><p><?php print wp_specialchars($mesg, 1); ?></p></div>
<?php endif; ?>

<div class="wrap">
<form style="position: relative" action="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/<?php echo basename(__FILE__); ?>" method="post">
<div><?php
	FeedWordPressCompatibility::stamp_nonce('feedwordpress_author_settings');

	if (is_numeric($link_id) and $link_id) :
?>
<input type="hidden" name="save_link_id" value="<?php echo $link_id; ?>" />
<?php
	else :
?>
<input type="hidden" name="save_link_id" value="*" />
<?php
	endif;
?>
</div>

<?php $links = FeedWordPress::syndicated_links(); ?>
<?php if (fwp_test_wp_version(FWP_SCHEMA_27)) : ?>
	<div class="icon32"><img src="<?php print htmlspecialchars(WP_PLUGIN_URL.'/'.$GLOBALS['fwp_path'].'/feedwordpress.png'); ?>" alt="" /></div>
<?php endif; ?>
<h2>Syndicated Post Settings<?php if (!is_null($link) and $link->found()) : ?>: <?php echo wp_specialchars($link->link->link_name, 1); ?><?php endif; ?></h2>

<style type="text/css">
	table.edit-form th { width: 27%; vertical-align: top; }
	table.edit-form td { width: 73%; vertical-align: top; }
	table.edit-form td ul.options { margin: 0; padding: 0; list-style: none; }
</style>

<?php if (fwp_test_wp_version(FWP_SCHEMA_27)) : ?>
	<style type="text/css">	
	#post-search {
		float: right;
		margin:11px 12px 0;
		min-width: 130px;
		position:relative;
	}
	.fwpfs {
		color: #dddddd;
		background:#797979 url(<?php bloginfo('home') ?>/wp-admin/images/fav.png) repeat-x scroll left center;
		border-color:#777777 #777777 #666666 !important; -moz-border-radius-bottomleft:12px;
		-moz-border-radius-bottomright:12px;
		-moz-border-radius-topleft:12px;
		-moz-border-radius-topright:12px;
		border-style:solid;
		border-width:1px;
		line-height:15px;
		padding:3px 30px 4px 12px;
	}
	.fwpfs.slide-down {
		border-bottom-color: #626262;
		-moz-border-radius-bottomleft:0;
		-moz-border-radius-bottomright:0;
		-moz-border-radius-topleft:12px;
		-moz-border-radius-topright:12px;
		background-image:url(<?php bloginfo('home') ?>/wp-admin/images/fav-top.png);
		background-position:0 top;
		background-repeat:repeat-x;
		border-bottom-style:solid;
		border-bottom-width:1px;
	}
	</style>
	
	<script type="text/javascript">
		jQuery(document).ready(function($){
			$('.fwpfs').toggle(
				function(){$('.fwpfs').removeClass('slideUp').addClass('slideDown'); setTimeout(function(){if ( $('.fwpfs').hasClass('slideDown') ) { $('.fwpfs').addClass('slide-down'); }}, 10) },
				function(){$('.fwpfs').removeClass('slideDown').addClass('slideUp'); setTimeout(function(){if ( $('.fwpfs').hasClass('slideUp') ) { $('.fwpfs').removeClass('slide-down'); }}, 10) }
			);
			$('.fwpfs').bind(
				'change',
				function () { this.form.submit(); }
			);
			$('#post-search .button').css( 'display', 'none' );
		});
	</script>
<?php endif; /* else : */?>
<p id="post-search">
<select name="link_id" class="fwpfs" style="max-width: 20.0em;">
  <option value="*"<?php if (is_null($link) or !$link->found()) : ?> selected="selected"<?php endif; ?>>- defaults for all feeds -</option>
<?php if ($links) : foreach ($links as $ddlink) : ?>
  <option value="<?php print (int) $ddlink->link_id; ?>"<?php if (!is_null($link) and ($link->link->link_id==$ddlink->link_id)) : ?> selected="selected"<?php endif; ?>><?php print wp_specialchars($ddlink->link_name, 1); ?></option>
<?php endforeach; endif; ?>
</select>
<input class="button" type="submit" name="go" value="<?php _e('Go') ?> &raquo;" />
</p>
<?php /* endif; */ ?>

<?php if (!is_null($link) and $link->found()) : ?>
	<p>These settings only affect posts syndicated from
	<strong><?php echo wp_specialchars($link->link->link_name, 1); ?></strong>.</p>
<?php else : ?>
	<p>These settings affect posts syndicated from any feed unless they are overridden
	by settings for that specific feed.</p>
<?php endif; ?>

<div id="poststuff">
<div id="post-body">
<?php fwp_option_box_opener('Publication', 'publicationdiv', 'postbox'); ?>
<table class="form-table" cellspacing="2" cellpadding="5">
<tr><th width="27%" scope="row" style="vertical-align:top">Status for new posts:</th>
<td width="73%" style="vertical-align:top"><ul style="margin:0; list-style:none">

<?php if (is_object($link) and $link->found()) : ?>
<li><label><input type="radio" name="feed_post_status" value="site-default"
<?php echo $status['post']['site-default']; ?> /> Use <a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/syndication-options.php">site-wide setting</a>
(currently: <strong><?php echo ($post_status_global ? $post_status_global : 'publish'); ?></strong>)</label></li>
<?php endif; ?>

<li><label><input type="radio" name="feed_post_status" value="publish"
<?php echo $status['post']['publish']; ?> /> Publish <?php print $thePostsPhrase; ?> immediately</label></li>

<?php if (SyndicatedPost::use_api('post_status_pending')) : ?>
<li><label><input type="radio" name="feed_post_status" value="pending"
<?php echo $status['post']['pending']; ?>/> Hold <?php print $thePostsPhrase; ?> for review; mark as Pending</label></li>
<?php endif; ?>

<li><label><input type="radio" name="feed_post_status" value="draft"
<?php echo $status['post']['draft']; ?> /> Save <?php print $thePostsPhrase; ?> as drafts</label></li>
<li><label><input type="radio" name="feed_post_status" value="private"
<?php echo $status['post']['private']; ?> /> Save <?php print $thePostsPhrase; ?> as private posts</label></li>
</ul></td>
</tr>
</table>
<?php
	fwp_option_box_closer();
?>

<?php if (!(is_object($link) and $link->found())) : ?>
<?php fwp_option_box_opener('Formatting', 'formattingdiv', 'postbox'); ?>
<table class="form-table" cellspacing="2" cellpadding="5">
<tr><th scope="row">Formatting filters:</th>
<td><select name="formatting_filters" size="1">
<option value="no"<?php echo ($formatting_filters!='yes')?' selected="selected"':''; ?>>Protect syndicated posts from formatting filters</option>
<option value="yes"<?php echo ($formatting_filters=='yes')?' selected="selected"':''; ?>>Expose syndicated posts to formatting filters</option>
</select>
<p class="setting-description">If you have trouble getting plugins to work that are supposed to insert
elements after posts (like relevant links or a social networking
<q>Share This</q> button), try changing this option to see if it fixes your
problem.</p>
</td></tr>
</table>
<?php	fwp_option_box_closer(); ?>

<?php
	fwp_option_box_opener('Links', 'linksdiv', 'postbox');
?>
<table class="form-table" cellspacing="2" cellpadding="5">
<tr><th  scope="row">Permalinks:</th>
<td><select name="munge_permalink" size="1">
<option value="yes"<?php echo ($munge_permalink=='yes')?' selected="selected"':''; ?>>point to the copy on the original website</option>
<option value="no"<?php echo ($munge_permalink=='no')?' selected="selected"':''; ?>>point to the local copy on this website</option>
</select></td>
</tr>

<tr><th scope="row">Posts from aggregator feeds:</th>
<td><ul class="options">
<li><label><input type="radio" name="use_aggregator_source_data" value="no"<?php echo ($use_aggregator_source_data!="yes")?' checked="checked"':''; ?>> Give the aggregator itself as the source of posts from an aggregator feed.</label></li>
<li><label><input type="radio" name="use_aggregator_source_data" value="yes"<?php echo ($use_aggregator_source_data=="yes")?' checked="checked"':''; ?>> Give the original source of the post as the source, not the aggregator.</label></li>
</ul>
<p class="setting-description">Some feeds (for example, those produced by FeedWordPress) aggregate content from several different sources, and include information about the original source of the post.
This setting controls what FeedWordPress will give as the source of posts from
such an aggregator feed.</p>
</td></tr>
</table>
<?php
	fwp_option_box_closer();
	fwp_linkedit_periodic_submit();
endif;

	fwp_option_box_opener(__('Comments & Pings'), 'commentstatus', 'postbox');
?>
<table class="form-table" cellspacing="2" cellpadding="5">
<tr><th scope="row"><?php print __('Comments') ?>:</th>
<td><ul class="options">
<?php if (is_object($link) and $link->found()) : ?>
<li><label><input type="radio" name="feed_comment_status" value="site-default"
<?php echo $status['comment']['site-default']; ?> /> Use <a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/syndication-options.php">site-wide setting</a>
(currently: <strong><?php echo $comment_status_global; ?></strong>)</label></li>
<?php endif; ?>

<li><label><input type="radio" name="feed_comment_status" value="open"<?php echo $status['comment']['open'] ?> /> Allow comments on syndicated posts</label></li>
<li><label><input type="radio" name="feed_comment_status" value="closed"<?php echo $status['comment']['closed']; ?> /> Don't allow comments on syndicated posts</label></li>
</ul></td></tr>

<tr><th scope="row"><?php print __('Pings') ?>:</th>
<td><ul class="options">
<?php if (is_object($link) and $link->found()) : ?>
<li><label><input type="radio" name="feed_ping_status" value="site-default"
<?php echo $status['ping']['site-default']; ?> /> Use <a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/syndication-options.php">site-wide setting</a>
(currently: <strong><?php echo $ping_status_global; ?></strong>)</label></li>
<?php endif; ?>

<li><label><input type="radio" name="feed_ping_status" value="open"<?php echo $status['ping']['open']; ?> /> Accept pings on syndicated posts</label></li>
<li><label><input type="radio" name="feed_ping_status" value="closed"<?php echo $status['ping']['closed']; ?> /> Don't accept pings on syndicated posts</label></li>
</ul></td></tr>
</table>
<?php
	fwp_option_box_closer();
	fwp_linkedit_periodic_submit();
?>
</div>
</div>

<p class="submit">
<input class="button-primary" type="submit" name="save" value="Save Changes" />
</p>

<script type="text/javascript">
	contextual_appearance('unfamiliar-author', 'unfamiliar-author-newuser', 'unfamiliar-author-default', 'newuser', 'inline');
<?php if (is_object($link) and $link->found()) : ?>
<?php 	for ($j=1; $j<=$i; $j++) : ?>
	contextual_appearance('author-rules-<?php echo $j; ?>', 'author-rules-<?php echo $j; ?>-newuser', 'author-rules-<?php echo $j; ?>-default', 'newuser', 'inline');
<?php 	endfor; ?>
	contextual_appearance('add-author-rule', 'add-author-rule-newuser', 'add-author-rule-default', 'newuser', 'inline');
	contextual_appearance('fix-mismatch-to', 'fix-mismatch-to-newuser', null, 'newuser', 'inline');
<?php else : ?>
	contextual_appearance('match-author-by-email', 'unless-null-email', null, 'yes', 'block', /*checkbox=*/ true);
<?php endif; ?>
</script>
</form>
</div> <!-- class="wrap" -->
<?php
} /* function fwp_posts_page () */

	fwp_posts_page();

