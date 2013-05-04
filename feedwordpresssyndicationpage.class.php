<?php
require_once(dirname(__FILE__) . '/admin-ui.php');
require_once(dirname(__FILE__) . '/feedfinder.class.php');

################################################################################
## ADMIN MENU ADD-ONS: implement Dashboard management pages ####################
################################################################################

define('FWP_UPDATE_CHECKED', 'Update Checked');
define('FWP_UNSUB_CHECKED', 'Unsubscribe');
define('FWP_DELETE_CHECKED', 'Delete');
define('FWP_RESUB_CHECKED', 'Re-subscribe');
define('FWP_SYNDICATE_NEW', 'Add →');
define('FWP_UNSUB_FULL', 'Unsubscribe from selected feeds →');
define('FWP_CANCEL_BUTTON', '× Cancel');
define('FWP_CHECK_FOR_UPDATES', 'Update');

class FeedWordPressSyndicationPage extends FeedWordPressAdminPage {
	function FeedWordPressSyndicationPage ($filename = NULL) {
		FeedWordPressAdminPage::FeedWordPressAdminPage('feedwordpresssyndication', /*link=*/ NULL);

		// No over-arching form element
		$this->dispatch = NULL;
		if (is_null($filename)) :
			$this->filename = __FILE__;
		else :
			$this->filename = $filename;
		endif;
	} /* FeedWordPressSyndicationPage constructor */

	function has_link () { return false; }

	var $_sources = NULL;

	function sources ($visibility = 'Y') {
		if (is_null($this->_sources)) :
			$links = FeedWordPress::syndicated_links(array("hide_invisible" => false));
			$this->_sources = array("Y" => array(), "N" => array());
			foreach ($links as $link) :
				$this->_sources[$link->link_visible][] = $link;
			endforeach;
		endif;
		$ret = (
			array_key_exists($visibility, $this->_sources)
			? $this->_sources[$visibility]
			: $this->_sources
		);
		return $ret;
	} /* FeedWordPressSyndicationPage::sources() */

	function visibility_toggle () {
		$sources = $this->sources('*');

		$defaultVisibility = 'Y';
		if ((count($this->sources('N')) > 0)
		and (count($this->sources('Y'))==0)) :
			$defaultVisibility = 'N';
		endif;
		
		$visibility = (
			isset($_REQUEST['visibility'])
			? $_REQUEST['visibility']
			: $defaultVisibility
		);
		
		return $visibility;
	} /* FeedWordPressSyndicationPage::visibility_toggle() */

	function show_inactive () {
		return ($this->visibility_toggle() == 'N');
	}

	function updates_requested () {
		global $wpdb;

		if (isset($_POST['update']) or isset($_POST['action']) or isset($_POST['update_uri'])) :
			// Only do things with side-effects for HTTP POST or command line
			$fwp_update_invoke = 'post';
		else :
			$fwp_update_invoke = 'get';
		endif;

		$update_set = array();
		if ($fwp_update_invoke != 'get') :
			if (is_array(MyPHP::post('link_ids'))
			and (MyPHP::post('action')==FWP_UPDATE_CHECKED)) :
				$targets = $wpdb->get_results("
				SELECT * FROM $wpdb->links
				WHERE link_id IN (".implode(",",$_POST['link_ids']).")
				");
				if (is_array($targets)) :
					foreach ($targets as $target) :
						$update_set[] = $target->link_rss;
					endforeach;
				else : // This should never happen
					FeedWordPress::critical_bug('fwp_syndication_manage_page::targets', $targets, __LINE__, __FILE__);
				endif;
			elseif (!is_null(MyPHP::post('update_uri'))) :
				$targets = MyPHP::post('update_uri');
				if (!is_array($targets)) :
					$targets = array($targets);
				endif;
				
				$first = each($targets);
				if (!is_numeric($first['key'])) : // URLs in keys
					$targets = array_keys($targets);
				endif;
				$update_set = $targets;
			endif;
		endif;
		return $update_set;
	}

	function accept_multiadd () {
		global $fwp_post;

		if (isset($fwp_post['cancel']) and $fwp_post['cancel']==__(FWP_CANCEL_BUTTON)) :
			return true; // Continue ....
		endif;
		
		// If this is a POST, validate source and user credentials
		FeedWordPressCompatibility::validate_http_request(/*action=*/ 'feedwordpress_feeds', /*capability=*/ 'manage_links');

		$in = (isset($fwp_post['multilookup']) ? $fwp_post['multilookup'] : '')
			.(isset($fwp_post['opml_lookup']) ? $fwp_post['opml_lookup'] : '');
		if (isset($fwp_post['confirm']) and $fwp_post['confirm']=='multiadd') :
			$chex = $fwp_post['multilookup'];
			$added = array(); $errors = array();
			foreach ($chex as $feed) :
				if (isset($feed['add']) and $feed['add']=='yes') :
					// Then, add in the URL.
					$link_id = FeedWordPress::syndicate_link(
						$feed['title'],
						$feed['link'],
						$feed['url']
					);
					if ($link_id and !is_wp_error($link_id)):
						$added[] = $link_id; 
					else :
						$errors[] = array($feed['url'], $link_id);
					endif;
				endif;
			endforeach;
			
			print "<div class='updated'>\n";
			print "<p>Added ".count($added)." new syndicated sources.</p>";
			if (count($errors) > 0) :
				print "<p>FeedWordPress encountered errors trying to add the following sources:</p>
				<ul>\n";
				foreach ($errors as $err) :
					$url = $err[0];
					$short = esc_html(feedwordpress_display_url($url));
					$url = esc_html($url);
					$wp = $err[1];
					if (is_wp_error($err[1])) :
						$error = $err[1];
						$mesg = " (<code>".$error->get_error_messages()."</code>)";
					else :
						$mesg = '';
					endif;
					print "<li><a href='$url'>$short</a>$mesg</li>\n";
				endforeach;
				print "</ul>\n";
			endif;
			print "</div>\n";

		elseif (is_array($in) or strlen($in) > 0) :
			add_meta_box(
				/*id=*/ 'feedwordpress_multiadd_box',
				/*title=*/ __('Add Feeds'),
				/*callback=*/ array($this, 'multiadd_box'),
				/*page=*/ $this->meta_box_context(),
				/*context =*/ $this->meta_box_context()
			);
		endif;
		return true; // Continue...
	}
	
	function display_multiadd_line ($line) {
		$short_feed = esc_html(feedwordpress_display_url($line['feed']));
		$feed = esc_html($line['feed']);
		$link = esc_html($line['link']);
		$title = esc_html($line['title']);
		$checked = $line['checked'];
		$i = esc_html($line['i']);
		
		print "<li><label><input type='checkbox' name='multilookup[$i][add]' value='yes' $checked />
			$title</label> &middot; <a href='$feed'>$short_feed</a>";

		if (isset($line['extra'])) :
			print " &middot; ".esc_html($line['extra']);
		endif;
		
		print "<input type='hidden' name='multilookup[$i][url]' value='$feed' />
			<input type='hidden' name='multilookup[$i][link]' value='$link' />
			<input type='hidden' name='multilookup[$i][title]' value='$title' />
			</li>\n";
		
		flush();
	}

	function multiadd_box ($page, $box = NULL) {
		global $fwp_post;

		$localData = NULL;

		if (isset($_FILES['opml_upload']['name']) and
		(strlen($_FILES['opml_upload']['name']) > 0)) :
			$in = 'tag:localhost';
			
			/*FIXME: check whether $_FILES['opml_upload']['error'] === UPLOAD_ERR_OK or not...*/ 
			$localData = file_get_contents($_FILES['opml_upload']['tmp_name']);
			$merge_all = true;
		elseif (isset($fwp_post['multilookup'])) :
			$in = $fwp_post['multilookup'];
			$merge_all = false;
		elseif (isset($fwp_post['opml_lookup'])) :
			$in = $fwp_post['opml_lookup'];
			$merge_all = true;
		else :
			$in = '';
			$merge_all = false;
		endif;
		
		if (strlen($in) > 0) :
			$lines = preg_split(
				"/\s+/",
				$in,
				/*no limit soldier*/ -1,
				PREG_SPLIT_NO_EMPTY
			);

			$i = 0;
			?>
			<form id="multiadd-form" action="<?php print $this->form_action(); ?>" method="post">
			<div><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?>
			<input type="hidden" name="multiadd" value="<?php print FWP_SYNDICATE_NEW; ?>" />
			<input type="hidden" name="confirm" value="multiadd" />

			<input type="hidden" name="multiadd" value="<?php print FWP_SYNDICATE_NEW; ?>" />
			<input type="hidden" name="confirm" value="multiadd" /></div>

			<div id="multiadd-status">
			<p><img src="<?php print esc_url ( admin_url( 'images/wpspin_light.gif' ) ); ?>" alt="" />
			Looking up feed information...</p>
			</div>

			<div id="multiadd-buttons">
			<input type="submit" class="button" name="cancel" value="<?php _e(FWP_CANCEL_BUTTON); ?>" />
			<input type="submit" class="button-primary" value="<?php print _e('Subscribe to selected sources →'); ?>" />
			</div>
			
			<p><?php _e('Here are the feeds that FeedWordPress has discovered from the addresses that you provided. To opt out of a subscription, unmark the checkbox next to the feed.'); ?></p>
			
			<?php
			print "<ul id=\"multiadd-list\">\n"; flush();
			foreach ($lines as $line) :
				$url = trim($line);
				if (strlen($url) > 0) :
					// First, use FeedFinder to check the URL.
					if (is_null($localData)) :
						$finder = new FeedFinder($url, /*verify=*/ false, /*fallbacks=*/ 1);
					else :
						$finder = new FeedFinder('tag:localhost', /*verify=*/ false, /*fallbacks=*/ 1);
						$finder->upload_data($localData);
					endif;
					
					$feeds = array_values(
						array_unique(
							$finder->find()
						)
					);

					$found = false;
					if (count($feeds) > 0) :
						foreach ($feeds as $feed) :
							$pie = FeedWordPress::fetch($feed);
							if (!is_wp_error($pie)) :
								$found = true;
								
								$short_feed = esc_html(feedwordpress_display_url($feed));
								$feed = esc_html($feed); 
								$title = esc_html($pie->get_title());
								$checked = ' checked="checked"';
								$link = esc_html($pie->get_link());
		
								$this->display_multiadd_line(array(
								'feed' => $feed,
								'title' => $pie->get_title(),
								'link' => $pie->get_link(),
								'checked' => ' checked="checked"',
								'i' => $i,
								));
								
								$i++; // Increment field counter
								
								if (!$merge_all) : // Break out after first find
									break;
								endif;					
							endif;
						endforeach;
					endif;
					
					if (!$found) :
						$this->display_multiadd_line(array(
							'feed' => $url,
							'title' => feedwordpress_display_url($url),
							'extra' => " [FeedWordPress couldn't detect any feeds for this URL.]",
							'link' => NULL,
							'checked' => '',
							'i' => $i,
						));
						$i++; // Increment field counter
					endif;
				endif;
			endforeach;
			print "</ul>\n";
			?>
			</form>
			
			<script type="text/javascript">
				jQuery(document).ready( function () {
					// Hide it now that we're done.
					jQuery('#multiadd-status').fadeOut(500 /*ms*/);
				} );
			</script>
			<?php
		endif;
		
		$this->_sources = NULL; // Force reload of sources list
		return true; // Continue
	}

	function display () {
		global $wpdb;
		global $fwp_post;
		
		if (FeedWordPress::needs_upgrade()) :
			fwp_upgrade_page();
			return;
		endif;
		
		$cont = true;
		$dispatcher = array(
			"feedfinder" => 'fwp_feedfinder_page',
			FWP_SYNDICATE_NEW => 'fwp_feedfinder_page',
			"switchfeed" => 'fwp_switchfeed_page',
			FWP_UNSUB_CHECKED => 'multidelete_page',
			FWP_DELETE_CHECKED => 'multidelete_page',
			'Unsubscribe' => 'multidelete_page',
			FWP_RESUB_CHECKED => 'multiundelete_page',
		);
		
		$act = MyPHP::request('action');
		if (isset($dispatcher[$act])) :
			$method = $dispatcher[$act];
			if (method_exists($this, $method)) :
				$cont = $this->{$method}();
			else :
				$cont = call_user_func($method);
			endif;
		elseif (isset($fwp_post['multiadd']) and $fwp_post['multiadd']==FWP_SYNDICATE_NEW) :
			$cont = $this->accept_multiadd($fwp_post);
		endif;
		
		if ($cont):
			$links = $this->sources('Y');
			$potential_updates = (!$this->show_inactive() and (count($this->sources('Y')) > 0));

			$this->open_sheet('Syndicated Sites');
			?>
			<div id="post-body">
			<?php
			if ($potential_updates
			or (count($this->updates_requested()) > 0)) :
				add_meta_box(
					/*id=*/ 'feedwordpress_update_box',
					/*title=*/ __('Update feeds now'),
					/*callback=*/ 'fwp_syndication_manage_page_update_box',
					/*page=*/ $this->meta_box_context(),
					/*context =*/ $this->meta_box_context()
				);
			endif;
			add_meta_box(
				/*id=*/ 'feedwordpress_feeds_box',
				/*title=*/ __('Syndicated sources'),
				/*callback=*/ array($this, 'syndicated_sources_box'),
				/*page=*/ $this->meta_box_context(),
				/*context =*/ $this->meta_box_context()
			);

			do_action('feedwordpress_admin_page_syndication_meta_boxes', $this);
		?>
			<div class="metabox-holder">		
			<?php
				fwp_do_meta_boxes($this->meta_box_context(), $this->meta_box_context(), $this);
			?>
			</div> <!-- class="metabox-holder" -->
			</div> <!-- id="post-body" -->
		
			<?php $this->close_sheet(/*dispatch=*/ NULL); ?>
		
			<div style="display: none">
			<div id="tags-input"></div> <!-- avoid JS error from WP 2.5 bug -->
			</div>
		<?php
		endif;
	} /* FeedWordPressSyndicationPage::display () */

	function dashboard_box ($page, $box = NULL) {
		global $fwp_path;

		$links = FeedWordPress::syndicated_links(array("hide_invisible" => false));
		$sources = $this->sources('*');

		$visibility = 'Y';
		$hrefPrefix = $this->form_action();
		$activeHref = $hrefPrefix.'&visibility=Y';
		$inactiveHref = $hrefPrefix.'&visibility=N';
		
		$lastUpdate = get_option('feedwordpress_last_update_all', NULL);
		$automatic_updates = get_option('feedwordpress_automatic_updates', NULL);
		
		if ('init'==$automatic_updates) :
			$update_setting = 'automatically before page loads';
		elseif ('shutdown'==$automatic_updates) :
			$update_setting = 'automatically after page loads';
		else :
			$update_setting = 'using a cron job or manual check-ins';
		endif;
		
		// Hey ho, let's go...
		?>
		<div style="float: left; background: #F5F5F5; padding-top: 5px; padding-right: 5px;"><a href="<?php print $this->form_action(); ?>"><img src="<?php print esc_html(WP_PLUGIN_URL."/${fwp_path}/feedwordpress.png"); ?>" alt="" /></a></div>

		<p class="info" style="margin-bottom: 0px; border-bottom: 1px dotted black;">Managed by <a href="http://feedwordpress.radgeek.com/">FeedWordPress</a>
		<?php print FEEDWORDPRESS_VERSION; ?>.</p>
		<?php if (FEEDWORDPRESS_BLEG) : ?>
		<p class="info" style="margin-top: 0px; font-style: italic; font-size: 75%; color: #666;">If you find this tool useful for your daily work, you can
		contribute to ongoing support and development with
		<a href="http://feedwordpress.radgeek.com/donate/">a modest donation</a>.</p>
		<br style="clear: left;" />
		<?php endif; ?>

		<div class="feedwordpress-actions">
		<h4>Updates</h4>
		<ul class="options">
		<li><strong>Scheduled:</strong> <?php print $update_setting; ?>
		(<a href="<?php print $this->form_action('feeds-page.php'); ?>">change setting</a>)</li>

		<li><?php if (!is_null($lastUpdate)) : ?>
		<strong>Last checked:</strong> <?php print fwp_time_elapsed($lastUpdate); ?>
		<?php else : ?>
		<strong>Last checked:</strong> none yet
		<?php endif; ?>	</li>

		</ul>
		</div>
		
		<div class="feedwordpress-stats">
		<h4>Subscriptions</h4>
		<table>
		<tbody>
		<tr class="first">
		<td class="first b b-active"><a href="<?php print esc_html($activeHref); ?>"><?php print count($sources['Y']); ?></a></td>
		<td class="t active"><a href="<?php print esc_html($activeHref); ?>">Active</a></td>
		</tr>
		
		<tr>
		<td class="b b-inactive"><a href="<?php print esc_html($inactiveHref); ?>"><?php print count($sources['N']); ?></a></td>
		<td class="t inactive"><a href="<?php print esc_html($inactiveHref); ?>">Inactive</a></td>
		</tr>
		</table>
		</div>

		<div id="add-single-uri">
			<?php if (count($sources['Y']) > 0) : ?>
			<form id="check-for-updates" action="<?php print $this->form_action(); ?>" method="POST">
			<div class="container"><input type="submit" class="button-primary" name"update" value="<?php print FWP_CHECK_FOR_UPDATES; ?>" />
			<?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?>
			<input type="hidden" name="update_uri" value="*" /></div>
			</form>
			<?php endif; ?>
		
		  <form id="syndicated-links" action="<?php print $hrefPrefix; ?>&amp;visibility=<?php print $visibility; ?>" method="post">
		  <div class="container"><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?>
		  <label for="add-uri">Add:
		  <input type="text" name="lookup" id="add-uri" placeholder="Source URL"
		  value="Source URL" style="width: 55%;" /></label>
		
		  <?php FeedWordPressSettingsUI::magic_input_tip_js('add-uri'); ?>
		  <input type="hidden" name="action" value="<?php print FWP_SYNDICATE_NEW; ?>" />
		  <input style="vertical-align: middle;" type="image" src="<?php print WP_PLUGIN_URL.'/'.$fwp_path; ?>/plus.png" alt="<?php print FWP_SYNDICATE_NEW; ?>" /></div>
		  </form>
		</div> <!-- id="add-single-uri" -->
		
		<br style="clear: both;" />
		
		<?php
	} /* FeedWordPressSyndicationPage::dashboard_box () */
	
	function syndicated_sources_box ($page, $box = NULL) {
		global $fwp_path;

		$links = FeedWordPress::syndicated_links(array("hide_invisible" => false));
		$sources = $this->sources('*');

		$visibility = $this->visibility_toggle();
		$showInactive = $this->show_inactive();

		$hrefPrefix = $this->form_action();
		?>
		<div><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?></div>
		<div class="tablenav">

		<div id="add-multiple-uri" class="hide-if-js">
		<form action="<?php print $hrefPrefix; ?>&amp;visibility=<?php print $visibility; ?>" method="post">
		  <div><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?></div>
		  <h4>Add Multiple Sources</h4>
		  <div>Enter one feed or website URL per line. If a URL links to a website which provides multiple feeds, FeedWordPress will use the first one listed.</div>
		  <div><textarea name="multilookup" rows="8" cols="60"
		  style="vertical-align: top"></textarea></div>
		  <div style="border-top: 1px dotted black; padding-top: 10px">
		  <div class="alignright"><input type="submit" class="button-primary" name="multiadd" value="<?php print FWP_SYNDICATE_NEW; ?>" /></div>
		  <div class="alignleft"><input type="button" class="button-secondary" name="action" value="<?php print FWP_CANCEL_BUTTON; ?>" id="turn-off-multiple-sources" /></div>
		  </div>
		</form>
		</div> <!-- id="add-multiple-uri" -->

		<div id="upload-opml" style="float: right" class="hide-if-js">
		<h4>Import source list</h4>
		<p>You can import a list of sources in OPML format, either by providing
		a URL for the OPML document, or by uploading a copy from your
		computer.</p>
		
		<form enctype="multipart/form-data" action="<?php print $hrefPrefix; ?>&amp;visibility=<?php print $visibility; ?>" method="post">
		  <div><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?><input type="hidden" name="MAX_FILE_SIZE" value="100000" /></div>
		<div style="clear: both"><label for="opml-lookup" style="float: left; width: 8.0em; margin-top: 5px;">From URL:</label> <input type="text" id="opml-lookup" name="opml_lookup" value="OPML document" /></div>
		<div style="clear: both"><label for="opml-upload" style="float: left; width: 8.0em; margin-top: 5px;">From file:</label> <input type="file" id="opml-upload" name="opml_upload" /></div>
		
		<div style="border-top: 1px dotted black; padding-top: 10px">
		<div class="alignright"><input type="submit" class="button-primary" name="action" value="<?php print FWP_SYNDICATE_NEW; ?>" /></div>
		<div class="alignleft"><input type="button" class="button-secondary" name="action" value="<?php print FWP_CANCEL_BUTTON; ?>" id="turn-off-opml-upload" /></div>
		</div>
		</form>
		</div> <!-- id="upload-opml" -->
	
		<div id="add-single-uri" class="alignright">
		  <form id="syndicated-links" action="<?php print $hrefPrefix; ?>&amp;visibility=<?php print $visibility; ?>" method="post">
		  <div><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?></div>
		  <ul class="subsubsub">
		  <li><label for="add-uri">New source:</label>
		  <input type="text" name="lookup" id="add-uri" value="Website or feed URI" />
		
		  <?php FeedWordPressSettingsUI::magic_input_tip_js('add-uri'); FeedWordPressSettingsUI::magic_input_tip_js('opml-lookup'); ?>
		
		  <input type="hidden" name="action" value="feedfinder" />
		  <input type="submit" class="button-secondary" name="action" value="<?php print FWP_SYNDICATE_NEW; ?>" />
		  <div style="text-align: right; margin-right: 2.0em"><a id="turn-on-multiple-sources" href="#add-multiple-uri"><img style="vertical-align: middle" src="<?php print WP_PLUGIN_URL.'/'.$fwp_path; ?>/down.png" alt="" /> add multiple</a>
		  <span class="screen-reader-text"> or </span>
		  <a id="turn-on-opml-upload" href="#upload-opml"><img src="<?php print WP_PLUGIN_URL.'/'.$fwp_path; ?>/plus.png" alt="" style="vertical-align: middle" /> import source list</a></div>
		  </li>
		  </ul>
		  </form>
		</div> <!-- class="alignright" -->

		<div class="alignleft">
		<?php
		if (count($sources[$visibility]) > 0) :
			$this->manage_page_links_subsubsub($sources, $showInactive);
		endif;
		?>
		</div> <!-- class="alignleft" -->

		</div> <!-- class="tablenav" -->
		
		<form id="syndicated-links" action="<?php print $hrefPrefix; ?>&amp;visibility=<?php print $visibility; ?>" method="post">
		<div><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?></div>
		
		<?php if ($showInactive) : ?>
		<div style="clear: right" class="alignright">
		<p style="font-size: smaller; font-style: italic">FeedWordPress used to syndicate
		posts from these sources, but you have unsubscribed from them.</p>
		</div>
		<?php
		endif;
		?>

		<?php
		if (count($sources[$visibility]) > 0) :
			$this->display_button_bar($showInactive);
		else :
			$this->manage_page_links_subsubsub($sources, $showInactive);
		endif;
		
		fwp_syndication_manage_page_links_table_rows($sources[$visibility], $this, $visibility);
		$this->display_button_bar($showInactive);
		?>
		</form>
		<?php
	} /* FeedWordPressSyndicationPage::syndicated_sources_box() */

	function manage_page_links_subsubsub ($sources, $showInactive) {
		$hrefPrefix = $this->admin_page_href("syndication.php");
?>
	<ul class="subsubsub">
	<li><a <?php if (!$showInactive) : ?>class="current" <?php endif; ?>href="<?php print $hrefPrefix; ?>&amp;visibility=Y">Subscribed
	<span class="count">(<?php print count($sources['Y']); ?>)</span></a></li>
	<?php if ($showInactive or (count($sources['N']) > 0)) : ?>
	<li><a <?php if ($showInactive) : ?>class="current" <?php endif; ?>href="<?php print $hrefPrefix; ?>&amp;visibility=N">Inactive</a>
	<span class="count">(<?php print count($sources['N']); ?>)</span></a></li>
	<?php endif; ?>

	</ul> <!-- class="subsubsub" -->
<?php
	}

	function display_button_bar ($showInactive) {
		?>
		<div style="clear: left" class="alignleft">
		<?php if ($showInactive) : ?>
		<input class="button-secondary" type="submit" name="action" value="<?php print FWP_RESUB_CHECKED; ?>" />
		<input class="button-secondary" type="submit" name="action" value="<?php print FWP_DELETE_CHECKED; ?>" />
		<?php else : ?>
		<input class="button-secondary" type="submit" name="action" value="<?php print FWP_UPDATE_CHECKED; ?>" />
		<input class="button-secondary delete" type="submit" name="action" value="<?php print FWP_UNSUB_CHECKED; ?>" />
		<?php endif ; ?>
		</div> <!-- class="alignleft" -->
		
		<br class="clear" />
		<?php
	}
	
	function bleg_thanks ($page, $box = NULL) {
		?>
		<div class="donation-thanks">
		<h4>Thank you!</h4>
		<p><strong>Thank you</strong> for your contribution to <a href="http://feedwordpress.radgeek.com/">FeedWordPress</a> development.
		Your generous gifts make ongoing support and development for
		FeedWordPress possible.</p>
		<p>If you have any questions about FeedWordPress, or if there
		is anything I can do to help make FeedWordPress more useful for
		you, please <a href="http://feedwordpress.radgeek.com/contact">contact me</a>
		and let me know what you're thinking about.</p>
		<p class="signature">&#8212;<a href="http://radgeek.com/">Charles Johnson</a>, Developer, <a href="http://feedwordpress.radgeek.com/">FeedWordPress</a>.</p>
		</div>
		<?php
	} /* FeedWordPressSyndicationPage::bleg_thanks () */

	function bleg_box ($page, $box = NULL) {
		?>
<div class="donation-form">
<h4>Keep FeedWordPress improving</h4>
<form action="https://www.paypal.com/cgi-bin/webscr" accept-charset="UTF-8" method="post"><div>
<p><a href="http://feedwordpress.radgeek.com/">FeedWordPress</a> makes syndication
simple and empowers you to stream content from all over the web into your
WordPress hub. That's got to be worth a few lattes. If you're finding FWP useful,
<a href="http://feedwordpress.radgeek.com/donate/">a modest gift</a>
is the best way to support steady progress on development, enhancements,
support, and documentation.</p>
<div class="donate">
<input type="hidden" name="business" value="commerce@radgeek.com"  />
<input type="hidden" name="cmd" value="_xclick"  />
<input type="hidden" name="item_name" value="FeedWordPress donation"  />
<input type="hidden" name="no_shipping" value="1"  />
<input type="hidden" name="return" value="<?php print esc_attr($this->admin_page_href(basename($this->filename), array('paid' => 'yes'))); ?>"  />
<input type="hidden" name="currency_code" value="USD" />
<input type="hidden" name="notify_url" value="http://feedwordpress.radgeek.com/ipn/donation"  />
<input type="hidden" name="custom" value="1"  />
<input type="image" name="submit" src="https://www.paypal.com/en_GB/i/btn/btn_donate_SM.gif" alt="Donate through PayPal" />
</div>
</div></form>

<p>You can make a gift online (or
<a href="http://feedwordpress.radgeek.com/donation">set up an automatic
regular donation</a>) using an existing PayPal account or any major credit card.</p>

<div class="sod-off">
<form style="text-align: center" action="<?php print $this->form_action(); ?>" method="POST"><div>
<input class="button" type="submit" name="maybe_later" value="Maybe Later" />
<input class="button" type="submit" name="go_away" value="Dismiss" />
</div></form>
</div>
</div> <!-- class="donation-form" -->
		<?php
	} /* FeedWordPressSyndicationPage::bleg_box () */

	/**
	 * Override the default display of a save-settings button and replace
	 * it with nothing.
	 */
	function interstitial () {
		/* NOOP */
	} /* FeedWordPressSyndicationPage::interstitial() */
	
	function multidelete_page () {
		global $wpdb;
		
		// If this is a POST, validate source and user credentials
		FeedWordPressCompatibility::validate_http_request(/*action=*/ 'feedwordpress_feeds', /*capability=*/ 'manage_links');
	
		if (MyPHP::post('submit')==FWP_CANCEL_BUTTON) :
			return true; // Continue without further ado.
		endif;
		
		$link_ids = (isset($_REQUEST['link_ids']) ? $_REQUEST['link_ids'] : array());
		if (isset($_REQUEST['link_id'])) : array_push($link_ids, $_REQUEST['link_id']); endif;
	
		if (MyPHP::post('confirm')=='Delete'):
			if ( is_array(MyPHP::post('link_action')) ) :
				$actions = MyPHP::post('link_action');
			else :
				$actions = array();
			endif;
	
			$do_it = array(
				'hide' => array(),
				'nuke' => array(),
				'delete' => array(),
			);
	
			foreach ($actions as $link_id => $what) :
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
						// Force scrubbing of deleted post
						// rather than sending to Trashcan
						wp_delete_post(
							/*postid=*/ $post_id,
							/*force_delete=*/ true
						);
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
	<form action="<?php print $this->form_action(); ?>" method="post">
	<div class="wrap">
	<?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?>
	<input type="hidden" name="action" value="Unsubscribe" />
	<input type="hidden" name="confirm" value="Delete" />
	
	<h2>Unsubscribe from Syndicated Links:</h2>
	<?php	foreach ($targets as $link) :
			$subscribed = ('Y' == strtoupper($link->link_visible));
			$link_url = esc_html($link->link_url);
			$link_name = esc_html($link->link_name);
			$link_description = esc_html($link->link_description);
			$link_rss = esc_html($link->link_rss);
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
	<?php if ($subscribed) : ?>
	<li><input type="radio" id="hide-<?php echo $link->link_id; ?>"
	name="link_action[<?php echo $link->link_id; ?>]" value="hide" checked="checked" />
	<label for="hide-<?php echo $link->link_id; ?>">Turn off the subscription for this
	syndicated link<br/><span style="font-size:smaller">(Keep the feed information
	and all the posts from this feed in the database, but don't syndicate any
	new posts from the feed.)</span></label></li>
	<?php endif; ?>
	<li><input type="radio" id="nuke-<?php echo $link->link_id; ?>"<?php if (!$subscribed) : ?> checked="checked"<?php endif; ?>
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
	<input type="submit" name="submit" value="<?php _e(FWP_CANCEL_BUTTON); ?>" /> 
	<input class="delete" type="submit" name="submit" value="<?php _e(FWP_UNSUB_FULL) ?>" />
	</div>
	</div>
	<?php
			return false; // Don't continue on to Syndicated Sites listing
		endif;
	} /* FeedWordPressSyndicationPage::multidelete_page() */
	
	function multiundelete_page () {
		global $wpdb;
	
		// If this is a POST, validate source and user credentials
		FeedWordPressCompatibility::validate_http_request(/*action=*/ 'feedwordpress_feeds', /*capability=*/ 'manage_links');
	
		$link_ids = (isset($_REQUEST['link_ids']) ? $_REQUEST['link_ids'] : array());
		if (isset($_REQUEST['link_id'])) : array_push($link_ids, $_REQUEST['link_id']); endif;
	
		if (MyPHP::post('confirm')=='Undelete'):
			if ( is_array(MyPHP::post('link_action')) ) :
				$actions = MyPHP::post('link_action');
			else :
				$actions = array();
			endif;
	
			$do_it = array(
				'unhide' => array(),
			);
	
			foreach ($actions as $link_id => $what) :
				$do_it[$what][] = $link_id;
			endforeach;
	
			$alter = array();
			if (count($do_it['unhide']) > 0) :
				$unhiddem = "(".implode(', ', $do_it['unhide']).")";
				$alter[] = "
				UPDATE $wpdb->links
				SET link_visible = 'Y'
				WHERE link_id IN {$unhiddem}
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
					echo "re-subscribe request. [SQL: ".implode('; ', $errs)."]";
				else :
					echo "Your re-subscribe request(s) have been processed.";
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
	<form action="<?php print $this->form_action(); ?>" method="post">
	<div class="wrap">
	<?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?>
	<input type="hidden" name="action" value="<?php print FWP_RESUB_CHECKED; ?>" />
	<input type="hidden" name="confirm" value="Undelete" />
	
	<h2>Re-subscribe to Syndicated Links:</h2>
	<?php
		foreach ($targets as $link) :
			$subscribed = ('Y' == strtoupper($link->link_visible));
			$link_url = esc_html($link->link_url);
			$link_name = esc_html($link->link_name);
			$link_description = esc_html($link->link_description);
			$link_rss = esc_html($link->link_rss);
			
			if (!$subscribed) :
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
	<li><input type="radio" id="unhide-<?php echo $link->link_id; ?>"
	name="link_action[<?php echo $link->link_id; ?>]" value="unhide" checked="checked" />
	<label for="unhide-<?php echo $link->link_id; ?>">Turn back on the subscription
	for this syndication source.</label></li>
	<li><input type="radio" id="nothing-<?php echo $link->link_id; ?>"
	name="link_action[<?php echo $link->link_id; ?>]" value="nothing" />
	<label for="nothing-<?php echo $link->link_id; ?>">Leave this feed as it is.
	I changed my mind.</label></li>
	</ul>
	</table>
	</fieldset>
	<?php
			endif;
		endforeach;
	?>
	
	<div class="submit">
	<input class="button-primary delete" type="submit" name="submit" value="<?php _e('Re-subscribe to selected feeds &raquo;') ?>" />
	</div>
	</div>
	<?php
			return false; // Don't continue on to Syndicated Sites listing
		endif;
	} /* FeedWordPressSyndicationPage::multiundelete_page() */



} /* class FeedWordPressSyndicationPage */

function fwp_dashboard_update_if_requested ($object) {
	global $wpdb;

	$update_set = $object->updates_requested();

	if (count($update_set) > 0) :
		shuffle($update_set); // randomize order for load balancing purposes...

		$feedwordpress = new FeedWordPress;
		add_action('feedwordpress_check_feed', 'update_feeds_mention');
		add_action('feedwordpress_check_feed_complete', 'update_feeds_finish', 10, 3);

		$crash_ts = $feedwordpress->crash_ts();

		echo "<div class=\"update-results\">\n";
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
				$display_uri = esc_html(feedwordpress_display_url($uri));
				$uri = esc_html($uri);
				echo "<li><p><strong>Error:</strong> There was a problem updating <code><a href=\"$uri\">${display_uri}</a></code></p></li>\n";
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
}

define('FEEDWORDPRESS_BLEG_MAYBE_LATER_OFFSET', (60 /*sec/min*/ * 60 /*min/hour*/ * 24 /*hour/day*/ * 31 /*days*/));
define('FEEDWORDPRESS_BLEG_ALREADY_PAID_OFFSET', (60 /*sec/min*/ * 60 /*min/hour*/ * 24 /*hour/day*/ * 183 /*days*/));
function fwp_syndication_manage_page_update_box ($object = NULL, $box = NULL) {
	$bleg_box_hidden = null;
	if (isset($_POST['maybe_later'])) :
		$bleg_box_hidden = time() + FEEDWORDPRESS_BLEG_MAYBE_LATER_OFFSET; 
	elseif (isset($_REQUEST['paid']) and $_REQUEST['paid'])  :
		$bleg_box_hidden = time() + FEEDWORDPRESS_BLEG_ALREADY_PAID_OFFSET; 
	elseif (isset($_POST['go_away'])) :
		$bleg_box_hidden = 'permanent';
	endif;

	if (!is_null($bleg_box_hidden)) :
		update_option('feedwordpress_bleg_box_hidden', $bleg_box_hidden);
	else :
		$bleg_box_hidden = get_option('feedwordpress_bleg_box_hidden');
	endif;
?>
	<?php
	$bleg_box_ready = (FEEDWORDPRESS_BLEG and (
		!$bleg_box_hidden
		or (is_numeric($bleg_box_hidden) and $bleg_box_hidden < time())
	));
	
	if (isset($_REQUEST['paid']) and $_REQUEST['paid']) :
		$object->bleg_thanks($subject, $box);
	elseif ($bleg_box_ready) :
		$object->bleg_box($object, $box);
	endif;
	?>

	<form
		action="<?php print $object->form_action(); ?>"
		method="POST"
		class="update-form<?php if ($bleg_box_ready) : ?> with-donation<?php endif; ?>"
	>
	<div><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_feeds'); ?></div>
	<p>Check currently scheduled feeds for new and updated posts.</p>

	<?php
	fwp_dashboard_update_if_requested($object);

	if (!get_option('feedwordpress_automatic_updates')) :
	?>
		<p class="heads-up"><strong>Note:</strong> Automatic updates are currently turned
		<strong>off</strong>. New posts from your feeds will not be syndicated
		until you manually check for them here. You can turn on automatic
		updates under <a href="<?php print $object->admin_page_href('feeds-page.php'); ?>">Feed &amp; Update Settings<a></a>.</p>
	<?php 
	endif;
	?>

	<div class="submit"><?php if ($object->show_inactive()) : ?>
	<?php foreach ($object->updates_requested() as $req) : ?>
	<input type="hidden" name="update_uri[]" value="<?php print esc_html($req); ?>" />
	<?php endforeach; ?>
	<?php else : ?>
	<input type="hidden" name="update_uri" value="*" />
	<?php endif; ?>
	<input class="button-primary" type="submit" name="update" value="<?php _e(FWP_CHECK_FOR_UPDATES); ?>" /></div>
	
	<br style="clear: both" />
	</form>
<?php
} /* function fwp_syndication_manage_page_update_box () */

function fwp_feedfinder_page () {
	global $post_source, $fwp_post, $syndicationPage;

	if (isset($fwp_post['opml_lookup']) or isset($_FILES['opml_upload'])) :
		$syndicationPage->accept_multiadd();
		return true;
	else :
		$post_source = 'feedwordpress_feeds';
		
		// With action=feedfinder, this goes directly to the feedfinder page
		include_once(dirname(__FILE__) . '/feeds-page.php');
		return false;
	endif;
} /* function fwp_feedfinder_page () */

function fwp_switchfeed_page () {
	global $wpdb, $wp_db_version;
	global $fwp_post, $fwp_path;

	// If this is a POST, validate source and user credentials
	FeedWordPressCompatibility::validate_http_request(/*action=*/ 'feedwordpress_switchfeed', /*capability=*/ 'manage_links');

	$changed = false;
	if (!isset($fwp_post['Cancel'])):
		if (isset($fwp_post['save_link_id']) and ($fwp_post['save_link_id']=='*')) :
			$changed = true;
			$link_id = FeedWordPress::syndicate_link($fwp_post['feed_title'], $fwp_post['feed_link'], $fwp_post['feed']);
			if ($link_id):
				$existingLink = new SyndicatedLink($link_id);
				
			?>
<div class="updated"><p><a href="<?php print $fwp_post['feed_link']; ?>"><?php print esc_html($fwp_post['feed_title']); ?></a>
has been added as a contributing site, using the feed at
&lt;<a href="<?php print $fwp_post['feed']; ?>"><?php print esc_html($fwp_post['feed']); ?></a>&gt;.
| <a href="admin.php?page=<?php print $fwp_path; ?>/feeds-page.php&amp;link_id=<?php print $link_id; ?>">Configure settings</a>.</p></div>
<?php			else: ?>
<div class="updated"><p>There was a problem adding the feed. [SQL: <?php echo esc_html(mysql_error()); ?>]</p></div>
<?php			endif;
		elseif (isset($fwp_post['save_link_id'])):
			$existingLink = new SyndicatedLink($fwp_post['save_link_id']);
			$changed = $existingLink->set_uri($fwp_post['feed']);

			if ($changed):
				$home = $existingLink->homepage(/*from feed=*/ false);
				$name = $existingLink->name(/*from feed=*/ false);
				?> 
<div class="updated"><p>Feed for <a href="<?php echo esc_html($home); ?>"><?php echo esc_html($name); ?></a>
updated to &lt;<a href="<?php echo esc_html($fwp_post['feed']); ?>"><?php echo esc_html($fwp_post['feed']); ?></a>&gt;.</p></div>
				<?php
			endif;
		endif;
	endif;

	if (isset($existingLink)) :
		$auth = MyPHP::post('link_rss_auth_method');
		if (!is_null($auth) and (strlen($auth) > 0) and ($auth != '-')) :
			$existingLink->update_setting('http auth method', $auth);
			$existingLink->update_setting('http username',
				MyPHP::post('link_rss_username')
			);
			$existingLink->update_setting('http password',
				MyPHP::post('link_rss_password')
			);
		else :
			$existingLink->update_setting('http auth method', NULL);
			$existingLink->update_setting('http username', NULL);
			$existingLink->update_setting('http password', NULL);
		endif;
		do_action('feedwordpress_admin_switchfeed', $fwp_post['feed'], $existingLink); 
		$existingLink->save_settings(/*reload=*/ true);
	endif;
	
	if (!$changed) :
		?>
<div class="updated"><p>Nothing was changed.</p></div>
		<?php
	endif;
	return true; // Continue
}

