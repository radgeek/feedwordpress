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

class FeedWordPressFeedsPage extends FeedWordPressAdminPage {
	/**
	 * Constructs the Feeds page object
	 *
	 * @param mixed $link An object of class {@link SyndicatedLink} if created for one feed's settings, NULL if created for global default settings
	 */
	function FeedWordPressFeedsPage ($link = -1) {
		if (is_numeric($link) and -1 == $link) :
			$link = FeedWordPressAdminPage::submitted_link();
		endif;

		FeedWordPressAdminPage::FeedWordPressAdminPage('feedwordpressfeeds', $link);
	} /* FeedWordPressFeedsPage constructor */

	var $special_settings = array ( /* Regular expression syntax is OK here */
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
		'postmeta',
		'update/.*',
		'feed/.*',
		'link/.*',
	);

	/*static*/ function global_feeds_box ($page, $box = NULL) {
		global $wpdb;
		?>
		
		<table class="edit-form">
		<?php
		if ($page->for_default_settings()) :
			$automatic_updates = get_option('feedwordpress_automatic_updates');
			$update_time_limit = (int) get_option('feedwordpress_update_time_limit');
		?>

		<tr>
		<th scope="row">Updates:</th>
		<td><select id="automatic-updates-selector" name="automatic_updates" size="1" onchange="contextual_appearance('automatic-updates-selector', 'cron-job-explanation', null, 'no');">
		<option value="yes"<?php echo ($automatic_updates)?' selected="selected"':''; ?>>automatic updates on page loads</option>
		<option value="no"<?php echo (!$automatic_updates)?' selected="selected"':''; ?>>cron job or manual updates</option>
		</select>
		<div id="cron-job-explanation" class="setting-description">
		<p>If you want to use a cron job,
		you can perform scheduled updates by sending regularly-scheduled
		requests to <a href="<?php bloginfo('home'); ?>?update_feedwordpress=1"><code><?php bloginfo('home') ?>?update_feedwordpress=1</code></a>
		For example, inserting the following line in your crontab:</p>
		<pre><code>*/10 * * * * /usr/bin/curl --silent \
	<?php bloginfo('home'); ?>?update_feedwordpress=1</code></pre>
		<p class="setting-description">will check in every 10 minutes and
		check for updates on any feeds that are ready to be polled for
		updates.</p>
		</div>
		</td>
		</tr>
		
		<tr>
		<th scope="row"><?php print __('Time limit on updates'); ?>:</th>
		<td><select id="time-limit" name="update_time_limit" size="1" onchange="contextual_appearance('time-limit', 'time-limit-box', null, 'yes');">
		<option value="no"<?php echo ($update_time_limit>0)?'':' selected="selected"'; ?>>no time limit on updates</option>
		<option value="yes"<?php echo ($update_time_limit>0)?' selected="selected"':''; ?>>limit updates to no more than...</option>
		</select>
		<span id="time-limit-box"><label><input type="text" name="time_limit_seconds" value="<?php print $update_time_limit; ?>" size="5" /> seconds</label></span>
		</tr>

		<?php else : ?>

		<tr>
		<th scope="row"><?php _e('Last update') ?>:</th>
		<td><?php
			if (isset($page->link->settings['update/last'])) :
				echo fwp_time_elapsed($page->link->settings['update/last'])." ";
			else :
				echo " none yet";
			endif;
		?></td></tr>

		<tr><th><?php _e('Next update') ?>:</th>
		<td><?php
			$holdem = (isset($page->link->settings['update/hold']) ? $page->link->settings['update/hold'] : 'scheduled');
		?>
		<select name="update_schedule">
		<option value="scheduled"<?php echo ($holdem=='scheduled')?' selected="selected"':''; ?>>update on schedule <?php
			echo " (";
			if (isset($page->link->settings['update/ttl']) and is_numeric($page->link->settings['update/ttl'])) :
				if (isset($page->link->settings['update/timed']) and $page->link->settings['update/timed']=='automatically') :
					echo 'next: ';
					$next = $page->link->settings['update/last'] + ((int) $page->link->settings['update/ttl'] * 60);
					if (strftime('%x', time()) != strftime('%x', $next)) :
						echo strftime('%x', $next)." ";
					endif;
					echo strftime('%X', $page->link->settings['update/last']+((int) $page->link->settings['update/ttl']*60));
				else :
					echo "every ".$page->link->settings['update/ttl']." minute".(($page->link->settings['update/ttl']!=1)?"s":"");
				endif;
			else:
				echo "next scheduled update";
			endif;
			echo ")";
		?></option>
		<option value="next"<?php echo ($holdem=='next')?' selected="selected"':''; ?>>update ASAP</option>
		<option value="ping"<?php echo ($holdem=='ping')?' selected="selected"':''; ?>>update only when pinged</option>
		</select></td></tr>
		<?php endif; ?>
</table>
<script type="text/javascript">
contextual_appearance('time-limit', 'time-limit-box', null, 'yes');
contextual_appearance('time-limit', 'time-limit-box', null, 'yes');
</script>
<?php
	} /* FeedWordPressSyndicationOptionsPage::global_feeds_box() */

	function feed_information_box ($page, $box = NULL) {
		global $wpdb;
		if ($page->for_feed_settings()) :
			$info['name'] = wp_specialchars($page->link->link->link_name, 1);
			$info['description'] = wp_specialchars($page->link->link->link_description, 'both');
			$info['url'] = wp_specialchars($page->link->link->link_url, 1);
			$rss_url = $page->link->link->link_rss;

			$hardcode['name'] = $page->link->hardcode('name');
			$hardcode['description'] = $page->link->hardcode('description');
			$hardcode['url'] = $page->link->hardcode('url');
		else :
			$cat_id = FeedWordPress::link_category_id();
			if (FeedWordPressCompatibility::test_version(FWP_SCHEMA_21)) :
				$results = get_categories(array(
					"type" => 'link',
					"hide_empty" => false,	
				));
				
				// Guarantee that the Contributors category will be in the drop-down chooser, even if it is empty.
				$found_link_category_id = false;
				foreach ($results as $row) :
					// Normalize case
					if (!isset($row->cat_id)) : $row->cat_id = $row->cat_ID; endif;

					if ($row->cat_id == $cat_id) :	$found_link_category_id = true;	endif;
				endforeach;
				
				if (!$found_link_category_id) :	$results[] = get_category($cat_id); endif;
			else :
				$results = $wpdb->get_results("SELECT cat_id, cat_name, auto_toggle FROM $wpdb->linkcategories ORDER BY cat_id");
			endif;
	
			$info = array();
			$rss_url = null;

			$hardcode['name'] = get_option('feedwordpress_hardcode_name');
			$hardcode['description'] = get_option('feedwordpress_hardcode_description');
			$hardcode['url'] = get_option('feedwordpress_hardcode_url');
		endif;

		// Hey ho, let's go
		?>
		<style type="text/css">
		table.edit-form { width: 100%; }
		table.edit-form th { width: 25%; vertical-align: top; text-align: right; padding: 0.5em; }
		table.edit-form td { width: 75%; vertical-align: top; padding: 0.5em; }
		table.edit-form td ul.options { margin: 0; padding: 0; list-style: none; }
		</style>
	
		<table class="edit-form">

		<?php if ($page->for_feed_settings()) : ?>

		<tr>
		<th scope="row"><?php _e('Feed URL:') ?></th>
		<td><a href="<?php echo wp_specialchars($rss_url, 'both'); ?>"><?php echo wp_specialchars($rss_url, 'both'); ?></a>
		(<a href="<?php echo FEEDVALIDATOR_URI; ?>?url=<?php echo urlencode($rss_url); ?>"
		title="Check feed &lt;<?php echo wp_specialchars($rss_url, 'both'); ?>&gt; for validity">validate</a>)
		<input type="submit" name="feedfinder" value="switch &rarr;" style="font-size:smaller" /></td>
		</tr>

		<?php
		$rows = array(
			"name" => __('Link Name'),
			"description" => __('Short Description'),
			"url" => __('Homepage'),
		);
		foreach ($rows as $what => $label) :
			?>
			<tr>
			<th scope="row"><?php print $label ?></th>
			<td>
			<div id="basics-<?php print $what; ?>-edit"><input type="text" name="link<?php print $what; ?>"
			value="<?php echo $info[$what]; ?>" style="width: 95%" /></div>
			<div id="basics-<?php print $what; ?>-view">
			<?php if ($what=='url') : ?><a href="<?php print $info[$what]; ?>"><?php else : ?><strong><?php endif; ?>
			<?php print (strlen(trim($info[$what])) > 0) ? $info[$what] : '(none provided)'; ?>
			<?php if ($what=='url') : ?></a><?php else : ?></strong><?php endif; ?></div>
	
			<div>
			<label><input id="basics-hardcode-<?php print $what; ?>"
				type="radio" name="hardcode_<?php print $what; ?>" value="no"
				<?php echo (($hardcode[$what]=='yes')?'':' checked="checked"');?>
				onchange="contextual_appearance('basics-hardcode-<?php print $what; ?>', 'basics-<?php print $what; ?>-view', 'basics-<?php print $what; ?>-edit', 'no', 'block', /*checkbox=*/ true)"
			/> Update automatically from feed</label>
			<label><input type="radio" name="hardcode_<?php print $what; ?>" value="yes"
			<?php echo (($hardcode[$what]!='yes')?'':' checked="checked"');?>
			onchange="contextual_appearance('basics-hardcode-<?php print $what; ?>', 'basics-<?php print $what; ?>-view', 'basics-<?php print $what; ?>-edit', 'no', 'block', /*checkbox=*/ true)"
			/> Edit manually</label>
			</div>
			</td>
			</tr>
			<?php
		endforeach;
		?>

		<?php else : ?>

		<tr>
		<th scope="row">Syndicated Link category:</th>
		<td><p><select name="syndication_category" size="1">
		<?php
			foreach ($results as $row) :
				// Normalize case
				if (!isset($row->cat_id)) : $row->cat_id = $row->cat_ID; endif;

				echo "\n\t<option value=\"$row->cat_id\"";
				if ($row->cat_id == $cat_id) :
					echo " selected='selected'";
				endif;
				echo ">$row->cat_id: ".wp_specialchars($row->cat_name);
				echo "</option>\n";
			endforeach;
		?></select></p>
		<p class="setting-description">FeedWordPress will syndicate the
		links placed under this link category.</p>
		</td>
		</tr>
				
		<tr>
		<th scope="row">Link Names:</th>
		<td><label><input type="checkbox" name="hardcode_name" value="no"<?php echo (($hardcode['name']=='yes')?'':' checked="checked"');?>/> Update contributor titles automatically when the feed title changes</label></td>
		</tr>
		
		<tr>
		<th scope="row">Short descriptions:</th>
		<td><label><input type="checkbox" name="hardcode_description" value="no"<?php echo (($hardcode['description']=='yes')?'':' checked="checked"');?>/> Update contributor descriptions automatically when the feed tagline changes</label></td>
		</tr>
		
		<tr>
		<th scope="row">Homepages:</th>
		<td><label><input type="checkbox" name="hardcode_url" value="no"<?php echo (($hardcode['url']=='yes')?'':' checked="checked"');?>/> Update contributor homepages automatically when the feed link changes</label></td>
		</tr>

		<?php endif; ?>

		</table>
		<?php
	} /* FeedWordPressFeedsPage::feed_information_box() */

	function posts_box ($page, $box = NULL) {
		FeedWordPressSettingsUI::instead_of_posts_box($page->link->id);
	} /* FeedWordPressFeedsPage::posts_box() */

	function authors_box ($page, $box = NULL) {
		FeedWordPressSettingsUI::instead_of_authors_box($page->link->id);
	} /* FeedWordPressFeedsPage::authors_box() */
	
	function categories_box ($page, $box = NULL) {
		FeedWordPressSettingsUI::instead_of_categories_box($page->link->id);
	} /* FeedWordPressFeedsPage::categories_box() */

	function custom_settings_box ($page, $box = NULL) {
		?>
	<p class="setting-description">These custom settings are special fields for the <strong>feed</strong> you are
	syndicating, to be retrieved in templates using the <code>get_feed_meta()</code> function. They do not create
	custom fields on syndicated <strong>posts</strong>. If you want to create custom fields that are applied to each
	individual post from this feed, set up the settings in <a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/posts-page.php&amp;link_id=<?php print $page->link->id; ?>">Syndicated Posts</a>.</p>
	
	<div id="postcustomstuff">
	<table id="meta-list" cellpadding="3">
		<tr>
		<th>Key</th>
		<th>Value</th>
		<th>Action</th>
		</tr>
	
	<?php
		$i = 0;
		foreach ($page->link->settings as $key => $value) :
			if (!preg_match("\007^((".implode(')|(', $page->special_settings)."))$\007i", $key)) :
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
	</div> <!-- id="postcustomstuff" -->
		<?php
	}

	function display_feedfinder () {
		global $wpdb;
		global $FeedWordPressHTTPStatusMessages;
	
		$lookup = (isset($_REQUEST['lookup']) ? $_REQUEST['lookup'] : NULL);

		if ($this->for_feed_settings()) : // Existing feed?
			if (is_null($lookup)) : $lookup = $this->link->link->link_url; endif;
			$name = wp_specialchars($this->link->link->link_name, 'both');
		else: // Or a new subscription to add?
			$name = "Subscribe to <code>".wp_specialchars(feedwordpress_display_url($lookup))."</code>";
		endif;
		?>
		<style type="text/css">
		form.fieldset {
			clear: both;
		}
		.feed-sample { 
			float: right;
			background-color: #D0D0D0;
			color: black;
			width: 45%;
			font-size: 70%;
			border-left: 1px dotted #A0A0A0;
			padding-left: 0.5em;
			margin-left: 1.0em;					}
		</style>

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
					$feed_title = feedwordpress_display_url($lookup);
					$feed_link = $lookup;
				endif;
				?>
					<form action="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/syndication.php" method="post">
					<div><?php FeedWordPressCompatibility::stamp_nonce('feedwordpress_switchfeed'); ?></div>
					<fieldset>
					<legend><?php echo $rss->feed_type; ?> <?php echo $rss->feed_version; ?> feed</legend>
	
					<?php
					$this->stamp_link_id();
					
					// No feed specified = add new feed; we
					// need to pass along a starting title
					// and homepage URL for the new Link.
					if (!$this->for_feed_settings()):
						?>
						<input type="hidden" name="feed_title" value="<?php echo wp_specialchars($feed_title, 'both'); ?>" />
						<input type="hidden" name="feed_link" value="<?php echo wp_specialchars($feed_link, 'both'); ?>" />
						<?php
					endif;
					?>

					<input type="hidden" name="feed" value="<?php echo wp_specialchars($f, 'both'); ?>" />
					<input type="hidden" name="action" value="switchfeed" />

					<div>
					<div class="feed-sample">
					
					<?php
					if (count($rss->items) > 0):
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
						<?php
					else:
						?>
						<h3>No Items</h3>
						<p>FeedWordPress found no posts on this feed.</p>
						<?php
					endif;
					?>
					</div>
	
					<div>
					<h3>Feed Information</h3>
					<ul>
					<li><strong>Homepage:</strong> <a href="<?php echo $feed_link; ?>"><?php echo is_null($feed_title)?'<em>Unknown</em>':$feed_title; ?></a></li>
					<li><strong>Feed URL:</strong> <a href="<?php echo wp_specialchars($f, 'both'); ?>"><?php echo wp_specialchars($f, 'both'); ?></a> (<a title="Check feed &lt;<?php echo wp_specialchars($f, 'both'); ?>&gt; for validity" href="http://feedvalidator.org/check.cgi?url=<?php echo urlencode($f); ?>">validate</a>)</li>
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
			FeedWordPressCompatibility::stamp_nonce(get_class($this));
		?></div>
		<div class="wrap">
		<h2>Use another feed</h2>
		<div><label>Feed:</label>
		<input type="text" name="lookup" id="use-another-feed" value="URI" />
		<?php FeedWordPressSettingsUI::magic_input_tip_js('use-another-feed'); ?>
		<?php $this->stamp_link_id('link_id'); ?>
		<input type="hidden" name="action" value="feedfinder" /></div>
		<div class="submit"><input type="submit" value="Use this feed &raquo;" /></div>
		</div>
		</form>
		<?php
		return false; // Don't continue
	} /* WordPressFeedsPage::display_feedfinder() */

	function accept_POST ($post) {
		global $wpdb;
			
		// User mashed a Save Changes button
		if (isset($post['save']) or isset($post['submit'])) :
			if ($this->for_feed_settings()) :
				$alter = array ();
					
				// custom feed settings first
				foreach ($post['notes'] as $mn) :
					$mn['key0'] = trim($mn['key0']);
					$mn['key1'] = trim($mn['key1']);
					if (preg_match("\007^(("
							.implode(')|(',$this->special_settings)
							."))$\007i",
							$mn['key1'])) :
						$mn['key1'] = 'user/'.$mn['key1'];
					endif;

					if (strlen($mn['key0']) > 0) :
						unset($this->link->settings[$mn['key0']]); // out with the old
					endif;
					
					if (($mn['action']=='update') and (strlen($mn['key1']) > 0)) :
						$this->link->settings[$mn['key1']] = $mn['value']; // in with the new
					endif;
				endforeach;
				
				// now stuff through the web form
				// hardcoded feed info
				
				foreach (array('name', 'description', 'url') as $what) :
					// We have a checkbox for "No," so if it's unchecked, mark as "Yes."
					$this->link->settings["hardcode {$what}"] = (isset($fwp_post["hardcode_{$what}"]) ? $fwp_post["hardcode_{$what}"] : 'yes');
					if (FeedWordPress::affirmative($this->link->settings, "hardcode {$what}")) :
						$alter[] = "link_{$what} = '".$wpdb->escape($fwp_post['link'.$what])."'";
					endif;
				endforeach;
				
				// Update scheduling
				if (isset($post['update_schedule'])) :
					$this->link->settings['update/hold'] = $post['update_schedule'];
				endif;

				$alter[] = "link_notes = '".$wpdb->escape($this->link->settings_to_notes())."'";

				$alter_set = implode(", ", $alter);

				// issue update query
				$result = $wpdb->query("
				UPDATE $wpdb->links
				SET $alter_set
				WHERE link_id='{$this->link->id}'
				");
				$this->updated = true;

				// reload link information from DB
				if (function_exists('clean_bookmark_cache')) :
					clean_bookmark_cache($this->link->id);
				endif;
				$this->link = new SyndicatedLink($this->link->id);
			else :
				// Global
				update_option('feedwordpress_cat_id', $post['syndication_category']);
				update_option('feedwordpress_automatic_updates', ($post['automatic_updates']=='yes'));
				update_option('feedwordpress_update_time_limit', ($post['update_time_limit']=='yes')?(int) $post['time_limit_seconds']:0);

				foreach (array('name', 'description', 'url') as $what) :
					// We have a checkbox for "No," so if it's unchecked, mark as "Yes."
					$hardcode = (isset($post["hardcode_{$what}"]) ? $post["hardcode_{$what}"] : 'yes');
					update_option("feedwordpress_hardcode_{$what}", $hardcode);
				endforeach;
				
				$this->updated = true;
			endif;

		// Probably a "Go" button for the drop-down
		else :
			$this->updated = false;
		endif;
	} /* WordPressFeedsPage::accept_POST() */

	function display () {
		global $wpdb;
		global $fwp_post;
		global $post_source;

		// Allow overriding of normal source for FeedFinder, which may
		// be called from multiple points.
		if (isset($post_source) and !is_null($post_source)) :
			$source = $post_source;
		else :
			$source = get_class($this);
		endif;

		// If this is a POST, validate source and user credentials
		FeedWordPressCompatibility::validate_http_request(/*action=*/ $source, /*capability=*/ 'manage_links');

		if (isset($_REQUEST['feedfinder'])
		or (isset($_REQUEST['action']) and $_REQUEST['action']=='feedfinder')
		or (isset($_REQUEST['action']) and $_REQUEST['action']==FWP_SYNDICATE_NEW)) :
			return $this->display_feedfinder(); // re-route to Feed Finder page
		else :
			if (strtoupper($_SERVER['REQUEST_METHOD'])=='POST') :
				$this->accept_POST($fwp_post);
			endif;
			
			////////////////////////////////////////////////
			// Prepare settings page ///////////////////////
			////////////////////////////////////////////////
			
			$this->ajax_interface_js();
			$this->display_update_notice_if_updated('Syndicated feed');
			print '<div class="wrap">'."\n";

			if (function_exists('add_meta_box')) :
				add_action(
					FeedWordPressCompatibility::bottom_script_hook(__FILE__),
					/*callback=*/ array($this, 'fix_toggles'),
					/*priority=*/ 10000
				);
				FeedWordPressSettingsUI::ajax_nonce_fields();
			endif;
			?>
			<form style="position: relative" action="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/<?php echo basename(__FILE__); ?>" method="post">
			<div><?php
				$this->display_sheet_header('Feed');
				$this->display_feed_select_dropdown();
				$this->display_settings_scope_message();
			?></div>
			<div id="poststuff">
			<?php
				FeedWordPressCompatibility::stamp_nonce(get_class($this));
				$this->stamp_link_id();
				fwp_settings_form_single_submit();
			?>
			<div id="post-body">
			<?php
			////////////////////////////////////////////////
			// Display settings boxes //////////////////////
			////////////////////////////////////////////////
		
			$boxes_by_methods = array(
				'feed_information_box' => __('Feed Information'),
				'global_feeds_box' => __('Update Scheduling'),
				'posts_box' => __('Syndicated Posts, Links, Comments & Pings'),
				'authors_box' => __('Syndicated Authors'),
				'categories_box' => __('Categories'.FEEDWORDPRESS_AND_TAGS),
				'custom_settings_box' => __('Custom Feed Settings (for use in templates)'),
			);
			if ($this->for_default_settings()) :
				unset($boxes_by_methods['custom_settings_box']);
			endif;
		
			foreach ($boxes_by_methods as $method => $row) :
				if (is_array($row)) :
					$id = $row['id'];
					$title = $row['title'];
				else :
					$id = 'feedwordpress_'.$method;
					$title = $row;
				endif;
		
				fwp_add_meta_box(
					/*id=*/ $id,
					/*title=*/ $title,
					/*callback=*/ array(get_class($this), $method),
					/*page=*/ $this->meta_box_context(),
					/*context=*/ $this->meta_box_context()
				);
			endforeach;
			do_action('feedwordpress_admin_page_feeds_meta_boxes', $this);
			?>
			<div class="metabox-holder">
			<?php
				fwp_do_meta_boxes($this->meta_box_context(), $this->meta_box_context(), $this);
			?>
			</div> <!-- class="metabox-holder" -->
			</div> <!-- id="post-body" -->
			</div> <!-- id="poststuff" -->
	
			<div><?php fwp_settings_form_single_submit_closer(); ?></div>
			</form>
			</div> <!-- class="wrap" -->
	
			<script type="text/javascript">
				// contextual_appearance()
				var els = ['name', 'description', 'url'];
				for (var i = 0; i < els.length; i++) {
					contextual_appearance(
						/*item=*/ 'basics-hardcode-'+els[i],
						/*appear=*/ 'basics-'+els[i]+'-view',
						/*disappear=*/ 'basics-'+els[i]+'-edit',
						/*value=*/ 'no',
						/*visibleStyle=*/ 'block',
						/*checkbox=*/ true
					);
				} /* for */
			</script>
			<?php
		endif;
		return false; // Don't continue

	}
} /* class FeedWordPressFeedsPage */

	$feedsPage = new FeedWordPressFeedsPage;
	$feedsPage->display();

