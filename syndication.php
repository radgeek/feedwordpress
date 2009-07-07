<?php
require_once(dirname(__FILE__) . '/admin-ui.php');
require_once(dirname(__FILE__) . '/magpiemocklink.class.php');
require_once(dirname(__FILE__) . '/feedfinder.class.php');

$FeedWordPressHTTPStatusMessages = array (
	200 => 'OK. FeedWordPress had no problems retrieving the content at this URL but the content does not seem to be a feed, and does not seem to include links to any feeds.',
	201 => 'Created',
	202 => 'Accepted',
	203 => 'Non-Authoritative information',
	204 => 'No Content',
	205 => 'Reset Content',
	206 => 'Partial Content',
	300 => 'Multiple Choices',
	301 => 'Moved Permanently',
	302 => 'Found',
	303 => 'See Other',
	304 => 'Not Modified',
	305 => 'Use Proxy',
	307 => 'Temporary Redirect',
	400 => 'Bad Request',
	401 => 'Unauthorized. This URL probably needs a username and password for you to access it.',
	402 => 'Payment Required',
	403 => 'Forbidden. The URL is not made available for the machine that FeedWordPress is running on.',
	404 => 'Not Found. There is nothing at this URL. Have you checked the address for typos?',
	405 => 'Method Not Allowed',
	406 => 'Not Acceptable',
	407 => 'Proxy Authentication Required',
	408 => 'Request Timeout',
	409 => 'Conflict',
	410 => 'Gone. This URL is no longer available on this server and no forwarding address is known.',
	411 => 'Length Required',
	412 => 'Precondition Failed',
	413 => 'Request Entity Too Large',
	414 => 'Request URI Too Long',
	415 => 'Unsupported Media Type',
	416 => 'Requested Range Not Satisfiable',
	417 => 'Expectation Failed',
	500 => 'Internal Server Error. Something unexpected went wrong with the configuration of the server that hosts this URL. You might try again later to see if this issue has been resolved.',
	501 => 'Not Implemented',
	502 => 'Bad Gateway',
	503 => 'Service Unavailable. The server is currently unable to handle the request due to a temporary overloading or maintenance of the server that hosts this URL. This is probably a temporary condition and you should try again later to see if the issue has been resolved.',
	504 => 'Gateway Timeout',
	505 => 'HTTP Version Not Supported',
);

################################################################################
## ADMIN MENU ADD-ONS: implement Dashboard management pages ####################
################################################################################

if (fwp_test_wp_version(0, FWP_SCHEMA_25)) :
	define('FWP_UPDATE_CHECKED', 'Update Checked Links');
	define('FWP_UNSUB_CHECKED', 'Unsubscribe from Checked Links');
	define('FWP_SYNDICATE_NEW', 'Syndicate »');
else :
	define('FWP_UPDATE_CHECKED', 'Update Checked');
	define('FWP_UNSUB_CHECKED', 'Unsubscribe');
	define('FWP_SYNDICATE_NEW', 'Syndicate »');
endif;

function feedwordpress_syndication_toggles () {
?>
		<script type="text/javascript">
			jQuery(document).ready( function($) {
				// In case someone got here first.
				$('.postbox h3, .postbox .handlediv').unbind('click');
				$('.postbox h3 a').unbind('click');
				$('.hide-postbox-tog').unbind('click');
				$('.columns-prefs input[type="radio"]').unbind('click');
				$('.meta-box-sortables').sortable('destroy');
				
				postboxes.add_postbox_toggles('feedwordpresssyndication');
			} );
		</script>
<?php
}

function fwp_dashboard_update_if_requested () {
	global $wpdb;

	if (isset($_POST['update']) or isset($_POST['action']) or isset($_POST['update_uri'])) :
		$fwp_update_invoke = 'post';
	else :
		$fwp_update_invoke = 'get';
	endif;

	$update_set = array();
	if (isset($_POST['link_ids']) and is_array($_POST['link_ids']) and ($_POST['action']==FWP_UPDATE_CHECKED)) :
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

	shuffle($update_set); // randomize order for load balancing purposes...
	if ($fwp_update_invoke != 'get' and count($update_set) > 0) : // Only do things with side-effects for HTTP POST or command line
		$feedwordpress =& new FeedWordPress;
		add_action('feedwordpress_check_feed', 'update_feeds_mention');
		add_action('feedwordpress_check_feed_complete', 'update_feeds_finish', 10, 3);

		$crash_dt = (int) get_option('feedwordpress_update_time_limit');
		if ($crash_dt > 0) :
			$crash_ts = time() + $crash_dt;
		else :
			$crash_ts = NULL;
		endif;

		if (fwp_test_wp_version(FWP_SCHEMA_25)) :
			echo "<div class=\"youare\">\n";
		else :
			echo "<div class=\"updated\">\n";
		endif;
		echo "<ul>\n";
		$tdelta = NULL;
		foreach ($update_set as $uri) :
			if (!is_null($crash_ts) and (time() > $crash_ts)) :
				echo "<li><p><strong>Further updates postponed:</strong> update time limit of ".$crash_dt." second".(($crash_dt==1)?"":"s")." exceeded.</p></li>";
				break;
			endif;

			if ($uri == '*') : $uri = NULL; endif;
			$delta = $feedwordpress->update($uri, $crash_ts);
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
	elseif (fwp_test_wp_version(FWP_SCHEMA_25)) :
?>
		<p class="youare">Check currently scheduled feeds for new and updated posts.</p>
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
	if ($_REQUEST['action'] == 'feedfinder' or $_REQUEST['action'] == FWP_SYNDICATE_NEW) : $cont = fwp_feedfinder_page();
	elseif ($_REQUEST['action'] == 'switchfeed') : $cont = fwp_switchfeed_page();
	elseif ($_REQUEST['action'] == 'linkedit') : $cont = fwp_linkedit_page();
	elseif ($_REQUEST['action'] == FWP_UNSUB_CHECKED or $_REQUEST['action'] == 'Unsubscribe') : $cont = fwp_multidelete_page();
	endif;
endif;

if ($cont):
?>
<?php
	$links = FeedWordPress::syndicated_links();
?>
	<div class="wrap">
<?php	if (fwp_test_wp_version(FWP_SCHEMA_27)) : ?>
	<div class="icon32"><img src="<?php print htmlspecialchars(WP_PLUGIN_URL.'/'.$GLOBALS['fwp_path'].'/feedwordpress.png'); ?>" alt="" /></div>
	<h2>Syndicated Sites</h2>
<?php 	endif;
	if (fwp_test_wp_version(0, FWP_SCHEMA_25)) :
		fwp_dashboard_update_if_requested();
	endif;

	if (fwp_test_wp_version(FWP_SCHEMA_27)) :
		if (fwp_test_wp_version(FWP_SCHEMA_27, FWP_SCHEMA_28)) :
			$hook_it = 'admin_footer';
		else : // WordPress 2.8+
			$hook_it = 'admin_footer-feedwordpress/syndication.php';
		endif;
		add_action($hook_it, 'feedwordpress_syndication_toggles', /*priority=*/ 10000);
		echo "<form style='display: none' method='get' action=''>\n<p>\n";
		wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false );
		wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false );
		echo "</p>\n</form>\n";
		
		if ($links) :
			add_meta_box(
				/*id=*/ 'feedwordpress_update_box',
				/*title=*/ __('Update feeds now'),
				/*callback=*/ 'fwp_syndication_manage_page_update_box',
				/*page=*/ 'feedwordpresssyndication',
				/*context =*/ 'feedwordpresssyndication'
			);
		endif;
		add_meta_box(
			/*id=*/ 'feedwordpress_feeds_box',
			/*title=*/ __('Syndicated sources'),
			/*callback=*/ 'fwp_syndication_manage_page_links_box',
			/*page=*/ 'feedwordpresssyndication',
			/*context =*/ 'feedwordpresssyndication'
		);
?>
	<div class="metabox-holder">		
	<div id="feedwordpresssyndication-sortables" class="meta-box-sortables ui-sortable">
<?php
		do_meta_boxes('feedwordpresssyndication', 'feedwordpresssyndication', NULL);
	else :
		if ($links): // only display Update panel if there are some links to update....
			fwp_syndication_manage_page_update_box();
		endif;
		fwp_syndication_manage_page_links_box();
?>
		</div> <!-- class="wrap" -->

		<?php if (fwp_test_wp_version(0, FWP_SCHEMA_25)) : ?>
		<div class="wrap">
		<form action="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/<?php echo basename(__FILE__); ?>" method="post">
		<div><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?></div>
		<h2>Add a new syndicated site:</h2>
		<div>
		<label for="add-uri">Website or feed:</label>
		<input type="text" name="lookup" id="add-uri" value="URI" size="64" />
		<input type="hidden" name="action" value="feedfinder" />
		</div>
		<div class="submit"><input type="submit" value="Syndicate &raquo;" /></div>
		</form>
		</div> <!-- class="wrap" -->
		<?php endif; ?>

		<div style="display: none">
		<div id="tags-input"></div> <!-- avoid JS error from WP 2.5 bug -->
		</div>
<?php
	endif;
endif;
} /* function fwp_syndication_manage_page () */

function fwp_syndication_manage_page_update_box ($object = NULL, $box = NULL) {
	$updateFeedsNow = __('Update feeds now');
?>
	<form action="" method="POST">
	<div><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?></div>
	<?php if (fwp_test_wp_version(FWP_SCHEMA_25, FWP_SCHEMA_27)) : ?>
		<div id="rightnow">
		<h3 class="reallynow"><span><?php print $updateFeedsNow; ?></span>
		<input type="hidden" name="update_uri" value="*" /><input style="float: right; border: none;" class="rbutton" type="submit" name="update" value="Update" />
		<br class="clear"/></h3>
	<?php elseif (fwp_test_wp_version(0, FWP_SCHEMA_25)) : ?>	
		<h2><?php print $updateFeedsNow; ?></h2>
	<?php endif; ?>

<?php
	if (fwp_test_wp_version(FWP_SCHEMA_25)) :
		fwp_dashboard_update_if_requested();
	else :
?>
<p>Check currently scheduled feeds for new and updated posts.</p>
<?php	endif; ?>

<?php 	if (!get_option('feedwordpress_automatic_updates')) : ?>
	<p class="youhave"><strong>Note:</strong> Automatic updates are currently turned
	<strong>off</strong>. New posts from your feeds will not be syndicated
	until you manually check for them here. You can turn on automatic
	updates under <a href="admin.php?page=<?php print $GLOBALS['fwp_path']; ?>/syndication-options.php">Syndication
	Options</a>.</p>
<?php 	endif; ?>

	<?php if (!fwp_test_wp_version(FWP_SCHEMA_25, FWP_SCHEMA_27)) : ?>
	<div class="submit"><input type="hidden" name="update_uri" value="*" /><input class="button-primary" type="submit" name="update" value="Update" /></div>
	<?php endif; ?>
	
	<?php if (fwp_test_wp_version(FWP_SCHEMA_27)) : ?>
		<br style="clear: both" />
	<?php elseif (fwp_test_wp_version(FWP_SCHEMA_25, FWP_SCHEMA_27)) : ?>
		</div> <!-- id="rightnow" -->
		</div> <!-- class="wrap" -->
	<?php elseif (fwp_test_wp_version(0, FWP_SCHEMA_25)) : ?>
		</div> <!-- class="wrap" -->
	<?php endif; ?>
	</form>
<?php
} /* function fwp_syndication_manage_page_update_box () */

function fwp_syndication_manage_page_links_box ($object = NULL, $box = NULL) {
	$links = FeedWordPress::syndicated_links();
?>
	<?php if (!fwp_test_wp_version(FWP_SCHEMA_27)) : ?>
		<div class="wrap">
		<h2>Syndicated Sites</h2>
	<?php endif; ?>

	<form id="syndicated-links" action="admin.php?page=<?php print $GLOBALS['fwp_path']; ?>/<?php echo basename(__FILE__); ?>" method="post">
	<div><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?></div>

<?php	$alt_row = true; ?>

	<?php if (fwp_test_wp_version(FWP_SCHEMA_25)) : ?>
	<div class="tablenav">
	<div class="alignright">
	<label for="add-uri">Add new source:</label>
	<input type="text" name="lookup" id="add-uri" value="Website or feed URI" />
<script type="text/javascript">
jQuery(document).ready( function () {
	var addUri = jQuery("#add-uri");
	var box = addUri.get(0);
	if (box.value==box.defaultValue) { addUri.addClass('form-input-tip'); }
	addUri.focus(function() {
		if ( this.value == this.defaultValue )
			jQuery(this).val( '' ).removeClass( 'form-input-tip' );
	});
	addUri.blur(function() {
		if ( this.value == '' )
			jQuery(this).val( this.defaultValue ).addClass( 'form-input-tip' );
	});

} );
</script>

	<input type="hidden" name="action" value="feedfinder" />
	<input type="submit" class="button-secondary" name="action" value="<?php print FWP_SYNDICATE_NEW; ?>" /></div>

<?php	if (count($links) > 0) : ?>
	<div class="alignleft">
	<input class="button-secondary" type="submit" name="action" value="<?php print FWP_UPDATE_CHECKED; ?>" />
	<input class="button-secondary delete" type="submit" class="delete" name="action" value="<?php print FWP_UNSUB_CHECKED; ?>" />
	</div>
<?php 	endif; ?>

	<br class="clear" />
	</div>
	<br class="clear" />
	
	<table class="widefat">
	<?php else : ?>
	<table width="100%" cellpadding="3" cellspacing="3">
	<?php endif; ?>
<thead>
<tr>
<th class="check-column" scope="col"><?php if (fwp_test_wp_version(FWP_SCHEMA_25)) : ?>
<input type="checkbox" <?php if (fwp_test_wp_version(FWP_SCHEMA_25, FWP_SCHEMA_26)) : ?>onclick="checkAll(document.getElementById('syndicated-links'));"<?php endif; ?> />
<?php endif; ?></th>
<th scope="col"><?php _e('Name'); ?></th>
<th scope="col"><?php _e('Feed'); ?></th>
<th scope="col"><?php _e('Updated'); ?></th>
</tr>
</thead>

<tbody>
<?php		if (count($links) > 0): foreach ($links as $link):
			$alt_row = !$alt_row; ?>
<tr<?php echo ($alt_row?' class="alternate"':''); ?>>
<th class="check-column" scope="row"><input type="checkbox" name="link_ids[]" value="<?php echo $link->link_id; ?>" /></th>
<?php
	if (strlen($link->link_rss) > 0):
		$caption=__('Switch Feed');
	else :
		$caption=__('Find Feed');
	endif;
?>
<td>
<strong><a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/<?php echo basename(__FILE__); ?>&amp;link_id=<?php echo $link->link_id; ?>&amp;action=linkedit"><?php echo wp_specialchars($link->link_name, 'both'); ?></a></strong>
<div class="row-actions"><div><strong>Settings &gt;</strong>
<a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/<?php echo basename(__FILE__); ?>&amp;link_id=<?php echo $link->link_id; ?>&amp;action=linkedit"><?php _e('Feed'); ?></a>
| <a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/posts-page.php&amp;link_id=<?php echo $link->link_id; ?>"><?php _e('Posts'); ?></a>
| <a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/authors-page.php&amp;link_id=<?php echo $link->link_id; ?>"><?php _e('Authors'); ?></a>
| <a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/categories-page.php&amp;link_id=<?php echo $link->link_id; ?>"><?php _e('Categories &amp; Tags'); ?></a></div>
<div><strong>Actions &gt;</strong>
<a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/<?php echo basename(__FILE__); ?>&amp;link_id=<?php echo $link->link_id; ?>&amp;action=feedfinder"><?php echo $caption; ?></a>
| <a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/<?php echo basename(__FILE__); ?>&amp;link_id=<?php echo $link->link_id; ?>&amp;action=Unsubscribe"><?php _e('Unsubscribe'); ?></a>
| <a href="<?php echo wp_specialchars($link->link_url, 'both'); ?>"><?php _e('View')?></a></div>
</div>
</td>
<?php 
			if (strlen($link->link_rss) > 0):
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
				<td><a href="<?php echo wp_specialchars($link->link_rss, 'both'); ?>"><?php echo wp_specialchars($display_uri, 'both'); ?></a></td>
			<?php else: ?>
				<td style="background-color:#FFFFD0"><p><strong>no
				feed assigned</strong></p></td>
			<?php endif; ?>

			<td><?php
			$sLink =& new SyndicatedLink($link->link_id);
			if (isset($sLink->settings['update/last'])) :
				print fwp_time_elapsed($sLink->settings['update/last']);
			else :
				_e('None yet');
			endif;

			print "<div style='font-style:italic;size:0.9em'>Ready for next update ";
			if (isset($sLink->settings['update/ttl']) and is_numeric($sLink->settings['update/ttl'])) :
				if (isset($sLink->settings['update/timed']) and $sLink->settings['update/timed']=='automatically') :
					$next = $sLink->settings['update/last'] + ((int) $sLink->settings['update/ttl'] * 60);
					print fwp_time_elapsed($next);
				else :
					echo "every ".$sLink->settings['update/ttl']." minute".(($sLink->settings['update/ttl']!=1)?"s":"");
				endif;
			else:
				echo "as soon as possible";
			endif;
			print "</div>";
?>
			</td>
		</tr>
<?php
			endforeach;
		else :
?>
<tr><td colspan="<?php print $span+2; ?>"><p>There are no websites currently listed for syndication.</p></td></tr>
<?php
		endif;
?>
</tbody>
</table>

<?php if (count($links) > 0 and fwp_test_wp_version(0, FWP_SCHEMA_25)) : ?>
<br/><hr/>
<div class="submit"><input type="submit" class="delete" name="action" value="<?php print FWP_UNSUB_CHECKED; ?>" />
<input type="submit" name="action" value="<?php print FWP_UPDATE_CHECKED; ?>" /></div>
<?php endif; ?>

	</form>
<?php
} /* function fwp_syndication_manage_page_links_box() */

function fwp_feedfinder_page () {
	global $wpdb;
	global $FeedWordPressHTTPStatusMessages;

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
		$name = "<code>".htmlspecialchars($lookup)."</code>";
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
			else :
				// Give us some sucky defaults
				$uri_bits = parse_url($lookup);
				$uri_bits['host'] = preg_replace('/^www\./i', '', $uri_bits['host']);
				$display_uri =
					(isset($uri_bits['user'])?$uri_bits['user'].'@':'')
					.(isset($uri_bits['host'])?$uri_bits['host']:'')
					.(isset($uri_bits['port'])?':'.$uri_bits['port']:'')
					.(isset($uri_bits['path'])?$uri_bits['path']:'')
					.(isset($uri_bits['query'])?'?'.$uri_bits['query']:'');
				if (strlen($display_uri) > 32) : $display_uri = substr($display_uri, 0, 32).'&#8230;'; endif;

				$feed_title = $display_uri;
				$feed_link = $lookup;
			endif;
?>
				<form action="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/<?php echo basename(__FILE__); ?>" method="post">
				<div><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_switchfeed'); ?></div>
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
					<?php
						// Prepare to display Sample Item
						$link =& new MagpieMockLink($rss, $f);
						$post =& new SyndicatedPost($rss->items[0], $link);
					?>
					<h3>Sample Item</h3>
					<ul>
					<li><strong>Title:</strong> <a href="<?php echo $post->post['meta']['syndication_permalink']; ?>"><?php echo $post->post['post_title']; ?></a></li>
					<li><strong>Date:</strong> <?php print date('d-M-y g:i:s a', $post->published()); ?></li>
					</ul>
					<div class="entry">
					<?php print $post->post['post_content']; ?>
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
<?php
		endforeach;
	else:
		print "<p><strong>".__('Error').":</strong> ".__("FeedWordPress couldn't find any feeds at").' <code><a href="'.htmlspecialchars($lookup).'">'.htmlspecialchars($lookup).'</a></code>';
		print ". ".__('Try another URL').".</p>";
		
		// Diagnostics
		$httpObject = _wp_http_get_object();
		$transports = $httpObject->_getTransport();
		print "<div class=\"updated\" style=\"margin-left: 3.0em; margin-right: 3.0em;\">\n";
		print "<h3>".__('Diagnostic information')."</h3>\n";
		if (!is_null($f->error())) :
			print "<h4>".__('HTTP request failure')."</h4>\n";
			print "<p>".$f->error()."</p>\n";
		else :
			print "<h4>".__('HTTP request completed')."</h4>\n";
			print "<p><strong>Status ".$f->status().":</strong> ".$FeedWordPressHTTPStatusMessages[$f->status()]."</p>\n";
		endif;
		print "<h4>".__('HTTP Transports available').":</h4>\n";
		print "<ol>\n";
		print "<li>".implode("</li>\n<li>", array_map('get_class', $transports))."</li>\n";
		print "</ol>\n";
		print "</div>\n";
	endif;
?>
	</div>

	<form action="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/<?php echo basename(__FILE__); ?>" method="post">
	<div><?php
		FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds');
	?></div>
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

	// If this is a POST, validate source and user credentials
	FeedWordPressCompatibility::validate_http_request(/*action=*/ 'feedwordpress_switchfeed', /*capability=*/ 'manage_links');

	if (!isset($_REQUEST['Cancel'])):
		if (isset($_POST['link_id']) and ($_POST['link_id']==0)):
			$link_id = FeedWordPress::syndicate_link($_REQUEST['feed_title'], $_REQUEST['feed_link'], $_REQUEST['feed']);
			if ($link_id): ?>
<div class="updated"><p><a href="<?php echo stripslashes($_REQUEST['feed_link']); ?>"><?php echo wp_specialchars(stripslashes($_REQUEST['feed_title']), 'both'); ?></a>
has been added as a contributing site, using the feed at &lt;<a href="<?php echo stripslashes($_REQUEST['feed']); ?>"><?php echo wp_specialchars(stripslashes($_REQUEST['feed']), 'both'); ?></a>&gt;.
| <a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/<?php echo basename(__FILE__); ?>&amp;link_id=<?php echo $link_id; ?>&amp;action=linkedit">Configure settings</a>.</div>
<?php			else: ?>
<div class="updated"><p>There was a problem adding the feed. [SQL: <?php echo wp_specialchars(mysql_error(), 'both'); ?>]</p></div>
<?php			endif;
		elseif (isset($_POST['link_id'])):
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
	global $wpdb, $wp_db_version;

	// If this is a POST, validate source and user credentials
	FeedWordPressCompatibility::validate_http_request(/*action=*/ 'feedwordpress_linkedit', /*capability=*/ 'manage_links');

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
		'unfamliar categories', /* Deprecated */
		'unfamiliar category',
		'map authors',
		'tags',
		'update/.*',
		'feed/.*',
		'link/.*',
	);

	if (isset($_REQUEST['feedfinder'])) :
		return fwp_feedfinder_page(); // re-route to Feed Finder page
	else :
		$link_id = (int) $_REQUEST['link_id'];
		$link =& new SyndicatedLink($link_id);

		if ($link->found()) :
			if (isset($GLOBALS['fwp_post']['save'])) :
				$alter = array ();
				
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
						unset($link->settings[$mn['key0']]); // out with the old
					endif;
					
					if (($mn['action']=='update') and (strlen($mn['key1']) > 0)) :
						$link->settings[$mn['key1']] = $mn['value']; // in with the new
					endif;
				endforeach;
				
				// now stuff through the web form
				// hardcoded feed info
				if (isset($GLOBALS['fwp_post']['hardcode_name'])) :
					$link->settings['hardcode name'] = $GLOBALS['fwp_post']['hardcode_name'];
					if (FeedWordPress::affirmative($link->settings, 'hardcode name')) :
						$alter[] = "link_name = '".$wpdb->escape($GLOBALS['fwp_post']['name'])."'";
					endif;
				endif;
				if (isset($GLOBALS['fwp_post']['hardcode_description'])) :
					$link->settings['hardcode description'] = $GLOBALS['fwp_post']['hardcode_description'];
					if (FeedWordPress::affirmative($link->settings, 'hardcode description')) :
						$alter[] = "link_description = '".$wpdb->escape($GLOBALS['fwp_post']['description'])."'";
					endif;
				endif;
				if (isset($GLOBALS['fwp_post']['hardcode_url'])) :
					$link->settings['hardcode url'] = $GLOBALS['fwp_post']['hardcode_url'];
					if (FeedWordPress::affirmative($link->settings, 'hardcode url')) :
						$alter[] = "link_url = '".$wpdb->escape($GLOBALS['fwp_post']['linkurl'])."'";
					endif;
				endif;
				
				// Update scheduling
				if (isset($GLOBALS['fwp_post']['update_schedule'])) :
					$link->settings['update/hold'] = $GLOBALS['fwp_post']['update_schedule'];
				endif;

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
				$updated_link = false;
			endif;

			$db_link = $link->link;
			$link_url = wp_specialchars($db_link->link_url, 1);
			$link_name = wp_specialchars($db_link->link_name, 1);
			$link_description = wp_specialchars($db_link->link_description, 'both');
			$link_rss_uri = wp_specialchars($db_link->link_rss, 'both');
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

<form action="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/<?php echo basename(__FILE__); ?>" method="post">
<div class="wrap">
<?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_linkedit'); ?>
<input type="hidden" name="link_id" value="<?php echo $link_id; ?>" />
<input type="hidden" name="action" value="linkedit" />
<input type="hidden" name="save" value="link" />

<?php if (fwp_test_wp_version(FWP_SCHEMA_27)) : ?>
	<div class="icon32"><img src="<?php print htmlspecialchars(WP_PLUGIN_URL.'/'.$GLOBALS['fwp_path'].'/feedwordpress.png'); ?>" alt="" /></div>
<?php endif; ?>

<h2><?php _e('Feed settings'); ?><?php if (!is_null($link) and $link->found()) : ?>: <?php echo wp_specialchars($link->link->link_name, 1); ?><?php endif; ?></h2>

<div id="poststuff">
<?php fwp_linkedit_single_submit($status); ?>

<div id="post-body">
<?php fwp_option_box_opener('Feed Information', 'feedinformationdiv'); ?>
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
		if (isset($link->settings['update/last'])) :
			echo fwp_time_elapsed($link->settings['update/last'])." ";
		else :
			echo " none yet";
		endif;
	?></td></tr>
	<tr><th width="20%">Next update:</th>
	<td colspan="2"><?php
		$holdem = (isset($link->settings['update/hold']) ? $link->settings['update/hold'] : 'scheduled');
	?>
	<select name="update_schedule">
	<option value="scheduled"<?php echo ($holdem=='scheduled')?' selected="selected"':''; ?>>update on schedule <?php
		echo " (";
		if (isset($link->settings['update/ttl']) and is_numeric($link->settings['update/ttl'])) :
			if (isset($link->settings['update/timed']) and $link->settings['update/timed']=='automatically') :
				echo 'next: ';
				$next = $link->settings['update/last'] + ((int) $link->settings['update/ttl'] * 60);
				if (strftime('%x', time()) != strftime('%x', $next)) :
					echo strftime('%x', $next)." ";
				endif;
				echo strftime('%X', $link->settings['update/last']+((int) $link->settings['update/ttl']*60));
			else :
				echo "every ".$link->settings['update/ttl']." minute".(($link->settings['update/ttl']!=1)?"s":"");
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
<?php fwp_option_box_closer(); ?>

<script type="text/javascript">
flip_hardcode('name');
flip_hardcode('description');
flip_hardcode('url');
</script>

<?php fwp_linkedit_periodic_submit(); ?>

<?php
	FeedWordPressSettingsUI::instead_of_posts_box($link_id);
	FeedWordPressSettingsUI::instead_of_authors_box($link_id);
	FeedWordPressSettingsUI::instead_of_categories_box($link_id);

	fwp_option_box_opener('Custom Feed Settings (for use in templates)', 'postcustom', 'postbox');
?>
<p class="setting-description">These custom settings are special fields for the <strong>feed</strong> you are
syndicating, to be retrieved in templates using the <code>get_feed_meta()</code> function. They do not create
custom fields on syndicated <strong>posts</strong>. If you want to create custom fields that are applied to each
individual post from this feed, set up the settings in <a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/posts-page.php&amp;link_id=<?php print $link_id; ?>">Syndicated Posts</a>.</p>

<div id="postcustomstuff">
<table id="meta-list" cellpadding="3">
	<tr>
	<th>Key</th>
	<th>Value</th>
	<th>Action</th>
	</tr>

<?php
	$i = 0;
	foreach ($link->settings as $key => $value) :
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
<?php fwp_option_box_closer(); ?>

<?php fwp_linkedit_periodic_submit(); ?>
<?php fwp_linkedit_single_submit_closer(); ?>
</div> <!-- id="post-body" -->
</div> <!-- id="poststuff" -->
</div>
	<?php
	endif;
	return false; // Don't continue
}

function fwp_multidelete_page () {
	global $wpdb;

	// If this is a POST, validate source and user credentials
	FeedWordPressCompatibility::validate_http_request(/*action=*/ 'feedwordpress_feeds', /*capability=*/ 'manage_links');

	$link_ids = (isset($_REQUEST['link_ids']) ? $_REQUEST['link_ids'] : array());
	if (isset($_REQUEST['link_id'])) : array_push($link_ids, $_REQUEST['link_id']); endif;

	if (isset($GLOBALS['fwp_post']['confirm']) and $GLOBALS['fwp_post']['confirm']=='Delete'):
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
<form action="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/<?php echo basename(__FILE__); ?>" method="post">
<div class="wrap">
<?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?>
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

	fwp_syndication_manage_page();

