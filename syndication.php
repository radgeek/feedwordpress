<?php
require_once(dirname(__FILE__) . '/admin-ui.php');

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
	FeedWordPressSettingsUI::fix_toggles_js('feedwordpresssyndication');
} /* feedwordpress_syndication_toggles() */

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
	if ($_REQUEST['action'] == 'feedfinder' or $_REQUEST['action']==FWP_SYNDICATE_NEW) : $cont = fwp_feedfinder_page();
	elseif ($_REQUEST['action'] == 'switchfeed') : $cont = fwp_switchfeed_page();
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
		add_action(
			FeedWordPressCompatibility::bottom_script_hook(__FILE__),
			/*callback=*/ 'feedwordpress_syndication_toggles',
			/*priority=*/ 10000
		);
		FeedWordPressSettingsUI::ajax_nonce_fields();
		
		if ($links) :
			fwp_add_meta_box(
				/*id=*/ 'feedwordpress_update_box',
				/*title=*/ __('Update feeds now'),
				/*callback=*/ 'fwp_syndication_manage_page_update_box',
				/*page=*/ 'feedwordpresssyndication',
				/*context =*/ 'feedwordpresssyndication'
			);
		endif;
		fwp_add_meta_box(
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
		fwp_do_meta_boxes('feedwordpresssyndication', 'feedwordpresssyndication', NULL);
	else :
		if ($links): // only display Update panel if there are some links to update....
			fwp_syndication_manage_page_update_box();
		endif;
		fwp_syndication_manage_page_links_box();
?>
		</div> <!-- class="wrap" -->

		<?php if (fwp_test_wp_version(0, FWP_SCHEMA_25)) : ?>
		<div class="wrap">
		<form action="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/<?php print basename(__FILE__); ?>" method="post">
		<div><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?></div>
		<h2>Add a new syndicated site:</h2>
		<div>
		<label for="add-uri">Website or feed:</label>
		<input type="text" name="lookup" id="add-uri" value="URI" size="64" />
		<input type="hidden" name="action" value="feedfinder" />
		</div>
		<div class="submit"><input type="submit" value="<?php print FWP_SYNDICATE_NEW; ?>" /></div>
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
	updates under <a href="admin.php?page=<?php print $GLOBALS['fwp_path']; ?>/feeds-page.php">Feed Settings<a></a>.</p>
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
	<?php FeedWordPressSettingsUI::magic_input_tip_js('add-uri'); ?>

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
<strong><a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/feeds-page.php&amp;link_id=<?php echo $link->link_id; ?>"><?php echo wp_specialchars($link->link_name, 'both'); ?></a></strong>
<div class="row-actions"><div><strong>Settings &gt;</strong>
<a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/feeds-page.php&amp;link_id=<?php echo $link->link_id; ?>"><?php _e('Feed'); ?></a>
| <a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/posts-page.php&amp;link_id=<?php echo $link->link_id; ?>"><?php _e('Posts'); ?></a>
| <a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/authors-page.php&amp;link_id=<?php echo $link->link_id; ?>"><?php _e('Authors'); ?></a>
| <a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/categories-page.php&amp;link_id=<?php echo $link->link_id; ?>"><?php print htmlspecialchars(__('Categories'.FEEDWORDPRESS_AND_TAGS)); ?></a></div>
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
	global $post_source;
	
	$post_source = 'feedwordpress_feeds';
	
	// With action=feedfinder, this goes directly to the feedfinder page
	include_once(dirname(__FILE__) . '/feeds-page.php');
	return false;
} /* function fwp_feedfinder_page () */

function fwp_switchfeed_page () {
	global $wpdb, $wp_db_version;
	global $fwp_post;

	// If this is a POST, validate source and user credentials
	FeedWordPressCompatibility::validate_http_request(/*action=*/ 'feedwordpress_switchfeed', /*capability=*/ 'manage_links');

	$changed = false;
	if (!isset($fwp_post['Cancel'])):
		if (isset($fwp_post['save_link_id']) and ($fwp_post['save_link_id']=='*')) :
			$changed = true;
			$link_id = FeedWordPress::syndicate_link($fwp_post['feed_title'], $fwp_post['feed_link'], $fwp_post['feed']);
			if ($link_id): ?>
<div class="updated"><p><a href="<?php print $fwp_post['feed_link']; ?>"><?php print wp_specialchars($fwp_post['feed_title'], 'both'); ?></a>
has been added as a contributing site, using the feed at
&lt;<a href="<?php print $fwp_post['feed']; ?>"><?php print wp_specialchars($fwp_post['feed'], 'both'); ?></a>&gt;.
| <a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/feeds-page.php&amp;link_id=<?php print $link_id; ?>">Configure settings</a>.</p></div>
<?php			else: ?>
<div class="updated"><p>There was a problem adding the feed. [SQL: <?php echo wp_specialchars(mysql_error(), 'both'); ?>]</p></div>
<?php			endif;
		elseif (isset($fwp_post['save_link_id'])):
			$existingLink = new SyndicatedLink($fwp_post['save_link_id']);
			$changed = $existingLink->set_uri($fwp_post['feed']);

			if ($changed):
				$home = $existingLink->homepage(/*from feed=*/ false);
				$name = $existingLink->name(/*from feed=*/ false);
				?> 
<div class="updated"><p>Feed for <a href="<?php echo wp_specialchars($home, 'both'); ?>"><?php echo wp_specialchars($name, 'both'); ?></a>
updated to &lt;<a href="<?php echo wp_specialchars($fwp_post['feed'], 'both'); ?>"><?php echo wp_specialchars($fwp_post['feed'], 'both'); ?></a>&gt;.</p></div>
				<?php
			endif;
		endif;
	endif;

	if (!$changed) :
		?>
<div class="updated"><p>Nothing was changed.</p></div>
		<?php
	endif;
	return true; // Continue
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

