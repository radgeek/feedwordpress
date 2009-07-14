<?php
require_once(dirname(__FILE__) . '/admin-ui.php');

class FeedWordPressPostsPage {
	var $link = NULL;

	/**
	 * Construct the posts page object.
	 *
	 * @param mixed $link An object of class {@link SyndicatedLink} if created for one feed's settings, NULL if created for global default settings
	 */
	function FeedWordPressPostsPage ($link = NULL) {
		$this->link = $link;
	} /* FeedWordPressPostsPage constructor */

	function for_feed_settings () { return (is_object($this->link) and method_exists($this->link, 'found') and $this->link->found()); }
	function for_default_settings () { return !$this->for_feed_settings(); }

	function these_posts_phrase () {
		if ($this->for_feed_settings()) :
			$phrase = __('posts from this feed');
		else :
			$phrase = __('syndicated posts');
		endif;
		return $phrase;
	}

	/**
	 * Provides a uniquely identifying name for the interface context for
	 * use with add_meta_box() and do_meta_boxes(),
	 *
	 * @return string the context name
	 *
	 * @see add_meta_box()
	 * @see do_meta_boxes()
	 */
	function meta_box_context () {
		$context = 'feedwordpresspostspage';
		if ($this->for_feed_settings()) :
			$context .= 'forfeed';
		endif;
		return $context;
	} /* FeedWordPressPostsPage::meta_box_context() */

	/**
	 * Outputs JavaScript to fix AJAX toggles settings.
	 *
	 * @uses FeedWordPressPostsPage::meta_box_context()
	 */
	 function fix_toggles () {
	 	 FeedWordPressSettingsUI::fix_toggles_js($this->meta_box_context());
	 } /* FeedWordPressPostsPage::fix_toggles() */
	
	/**
	 * Outputs "Publication" settings box.
	 *
	 * @since 2009.0713
	 * @param object $page of class FeedWordPressPostsPage tells us whether this is
	 *	a page for one feed's settings or for global defaults
	 * @param array $box
	 *
	 * @uses FeedWordPressPostsPage::these_posts_phrase()
	 * @uses FeedWordPress::syndicated_status()
	 * @uses SyndicatedLink::syndicaed_status()
	 * @uses SyndicatedPost::use_api()
	 * @uses fwp_option_box_opener()
	 * @uses fwp_option_box_closer()
	 */ 
	/*static*/ function publication_box ($page, $box = NULL) {
		global $fwp_path;
	
		$post_status_global = FeedWordPress::syndicated_status('post', /*default=*/ 'publish');
		$thesePosts = $page->these_posts_phrase();
	
		// Set up array for selector
		$setting = array(
			'publish' => array ('label' => "Publish %s immediately", 'checked' => ''),
			'draft' => array('label' => "Save %s as drafts", 'checked' => ''),
			'private' => array('label' => "Save %s as private posts", 'checked' => ''),
		);
		if (SyndicatedPost::use_api('post_status_pending')) :
			$setting['pending'] = array('label' => "Hold %s for review; mark as Pending", 'checked' => '');
		endif;
		if ($page->for_feed_settings()) :
			$href = $fwp_path.'/'.basename(__FILE__);
			$currently = str_replace('%s', '', strtolower(strtok($setting[$post_status_global]['label'], ';')));
			$setting['site-default'] = array('label' => "Use <a href=\"admin.php?page=${href}\">site-wide setting</a>", 'checked' => '');
			$setting['site-default']['label'] .= " (currently: <strong>${currently}</strong>)";
	
			$checked = $page->link->syndicated_status('post', 'site-default', /*fallback=*/ false);
		else :
			$checked = $post_status_global;
		endif;
	
		// Re-order appropriately
		$selector = array();
		$order = array(
			'site-default',
			'publish',
			'pending',
			'draft',
			'private',
		);
		foreach ($order as $line) :
			if (isset($setting[$line])) :
				$selector[$line] = $setting[$line];
			endif;
		endforeach;
		$selector[$checked]['checked'] = ' checked="checked"';
	
		// Hey ho, let's go...
		if (!function_exists('add_meta_box')) :
			fwp_option_box_opener('Publication', 'publicationdiv', 'postbox');
		endif;
		?>
		<style type="text/css">
		#syndicated-publication-form th { width: 27%; vertical-align: top; }
		#syndicated-publication-form td { width: 73%; vertical-align: top; }
		</style>
	
		<table id="syndicated-publication-form" class="form-table" cellspacing="2" cellpadding="5">
		<tr><th scope="row">Status for new posts:</th>
		<td><ul class="options">
		<?php foreach ($selector as $code => $li) : ?>
			<li><label><input type="radio" name="feed_post_status"
			value="<?php print $code; ?>"<?php print $li['checked']; ?> />
			<?php print str_replace('%s', $thesePosts, $li['label']); ?></label></li>
		<?php endforeach; ?>
		</ul></td>
		</tr>
		</table>
	
		<?php
		if (!function_exists('add_meta_box')) :
			fwp_option_box_closer();
		endif;
	} /* FeedWordPressPostsPage::publication_box () */
	
	/**
	 * Outputs "Formatting" settings box
	 *
	 * @since 2009.0713
	 * @param object $page of class FeedWordPressPostsPage tells us whether this is
	 *	a page for one feed's settings or for global defaults
	 * @param array $box
	 *
	 * @uses fwp_option_box_opener()
	 * @uses fwp_option_box_closer()
	 */ 
	function formatting_box ($page, $box = NULL) {
		$formatting_filters = get_option('feedwordpress_formatting_filters');

		if (!function_exists('add_meta_box')) :
			fwp_option_box_opener('Formatting', 'formattingdiv', 'postbox');
		endif;
		
		?>
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
		<?php
		
		if (!function_exists('add_meta_box')) :
			fwp_option_box_closer();
		endif;
	} /* FeedWordPressPostsPage::formatting_box() */
	
	/**
	 * Output "Links" settings box
	 *
	 * @since 2009.0713
	 * @param object $page of class FeedWordPressPostsPage tells us whether this is
	 *	a page for one feed's settings or for global defaults
	 * @param array $box
	 *
	 * @uses fwp_option_box_opener()
	 * @uses fwp_option_box_closer()
	 */
	/*static*/ function links_box ($page, $box = NULL) {
		$munge_permalink = get_option('feedwordpress_munge_permalink');
		$use_aggregator_source_data = get_option('feedwordpress_use_aggregator_source_data');

		if (!function_exists('add_meta_box')) :
			fwp_option_box_opener('Links', 'linksdiv', 'postbox');
		endif;
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
		if (!function_exists('add_meta_box')) :
			fwp_option_box_closer();
		endif;
	} /* FeedWordPressPostsPage::links_box() */

	/**
	 * Output "Comments & Pings" settings box
	 *
	 * @since 2009.0713
	 * @param object $page of class FeedWordPressPostsPage tells us whether this is
	 *	a page for one feed's settings or for global defaults
	 * @param array $box
	 *
	 * @uses fwp_option_box_opener()
	 * @uses fwp_option_box_closer()
	 */
	/*static*/ function comments_and_pings_box ($page, $box = NULL) {
		$setting = array();
		$selector = array();

		$whatsits = array(
			'comment' => array('label' => __('Comments'), 'accept' => 'Allow comments'),
			'ping' => array('label' => __('Pings'), 'accept' => 'Accept pings'),
		);
		$onThesePosts = 'on '.$page->these_posts_phrase();

		foreach ($whatsits as $what => $how) :
			$whatsits[$what]['default'] = FeedWordPress::syndicated_status($what, /*default=*/ 'closed');

			// Set up array for selector
			$setting = array(
				'open' => array ('label' => "{$how['accept']} %s", 'checked' => ''),
				'closed' => array('label' => "Don't ".strtolower($how['accept'])." %s", 'checked' => ''),
			);
			if ($page->for_feed_settings()) :
				$href = $fwp_path.'/'.basename(__FILE__);
				$currently = trim(str_replace('%s', '', strtolower(strtok($setting[$whatsits[$what]['default']]['label'], ';'))));
				$setting['site-default'] = array('label' => "Use <a href=\"admin.php?page=${href}\">site-wide setting</a>", 'checked' => '');
				$setting['site-default']['label'] .= " (currently: <strong>${currently}</strong>)";
		
				$checked = $page->link->syndicated_status($what, 'site-default', /*fallback=*/ false);
			else :
				$checked = $whatsits[$what]['default'];
			endif;

			// Re-order appropriately
			$selector[$what] = array();
			$order = array(
				'site-default',
				'open',
				'closed',
			);
			foreach ($order as $line) :
				if (isset($setting[$line])) :
					$selector[$what][$line] = $setting[$line];
				endif;
			endforeach;
			$selector[$what][$checked]['checked'] = ' checked="checked"';
		endforeach;

		// Hey ho, let's go...
		if (!function_exists('add_meta_box')) :
			fwp_option_box_opener(__('Comments & Pings'), 'commentstatus', 'postbox');
		endif;
		?>
		<table class="form-table" cellspacing="2" cellpadding="5">
		<?php foreach ($whatsits as $what => $how) : ?>
		  <tr><th scope="row"><?php print $how['label']; ?>:</th>
		  <td><ul class="options">
		  <?php foreach ($selector[$what] as $code => $li) : ?>
		    <li><label><input type="radio" name="feed_<?php print $what; ?>_status"
		    value="<?php print $code; ?>"<?php print $li['checked']; ?> />
		    <?php print trim(str_replace('%s', $onThesePosts, $li['label'])); ?></label></li>
		  <?php endforeach; ?>
		  </ul></td></tr>
		<?php endforeach; ?>
		</table>

		<?php
		if (!function_exists('add_meta_box')) :
			fwp_option_box_closer();
		endif;
	} /* FeedWordPressPostsPage::comments_and_pings_box() */
	
	/**
	 * Output "Custom Post Settings" settings box
	 *
	 * @since 2009.0713
	 * @param object $page of class FeedWordPressPostsPage tells us whether this is
	 *	a page for one feed's settings or for global defaults
	 * @param array $box
	 *
	 * @uses fwp_option_box_opener()
	 * @uses fwp_option_box_closer()
	 */
	/*static*/ function custom_post_settings_box ($page, $box = NULL) {
		if ($page->for_feed_settings()) :
			$custom_settings = $page->link->settings["postmeta"];
			if ($custom_settings and !is_array($custom_settings)) :
				$custom_settings = unserialize($custom_settings);
			endif;
			
			if (!is_array($custom_settings)) :
				$custom_settings = array();
			endif;
		else :
			$custom_settings = get_option('feedwordpress_custom_settings');
			if ($custom_settings and !is_array($custom_settings)) :
				$custom_settings = unserialize($custom_settings);
			endif;
	
			if (!is_array($custom_settings)) :
				$custom_settings = array();
			endif;
		endif;

		if (!function_exists('add_meta_box')) :
			fwp_option_box_opener('Custom Post Settings (to apply to each syndicated post)', 'postcustom', 'postbox');
		endif;

		?>
		<div id="postcustomstuff">
		<table id="meta-list" cellpadding="3">
		<tr>
		<th>Key</th>
		<th>Value</th>
		<th>Action</th>
		</tr>

		<?php
		$i = 0;
		foreach ($custom_settings as $key => $value) :
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
		if (!function_exists('add_meta_box')) :
			fwp_option_box_closer();
		endif;
	 } /* FeedWordPressPostsPage::custom_post_settings_box() */
}

function fwp_posts_page () {
	global $wpdb, $wp_db_version;

	FeedWordPressCompatibility::validate_http_request(/*action=*/ 'feedwordpress_posts_settings', /*capability=*/ 'manage_links');

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
	$postsPage = new FeedWordPressPostsPage($link);

	$mesg = null;

	////////////////////////////////////////////////
	// Process POST request, if any /////////////////
	////////////////////////////////////////////////
	if (isset($GLOBALS['fwp_post']['save']) or isset($GLOBALS['fwp_post']['submit'])) :
		// custom post settings
		foreach ($GLOBALS['fwp_post']['notes'] as $mn) :
			$mn['key0'] = trim($mn['key0']);
			$mn['key1'] = trim($mn['key1']);

			if (strlen($mn['key0']) > 0) :
				unset($custom_settings[$mn['key0']]); // out with the old
			endif;
			
			if (($mn['action']=='update') and (strlen($mn['key1']) > 0)) :
				$custom_settings[$mn['key1']] = $mn['value']; // in with the new
			endif;
		endforeach;

		if (is_object($link) and $link->found()) :
			$alter = array ();

			$link->settings['postmeta'] = serialize($custom_settings);

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
			
			update_option('feedwordpress_custom_settings', serialize($custom_settings));

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
		
		do_action('feedwordpress_admin_page_posts_save', $GLOBALS['fwp_post'], $postsPage);
	else :
		$updated_link = false;
	endif;
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
<div class="updated"><p>Syndicated posts settings updated.</p></div>
<?php elseif (!is_null($mesg)) : ?>
<div class="updated"><p><?php print wp_specialchars($mesg, 1); ?></p></div>
<?php endif; ?>

<div class="wrap">
<?php
if (function_exists('add_meta_box')) :
	add_action(
		FeedWordPressCompatibility::bottom_script_hook(__FILE__),
		/*callback=*/ array($postsPage, 'fix_toggles'),
		/*priority=*/ 10000
	);
	FeedWordPressSettingsUI::ajax_nonce_fields();
endif;
?>
<form style="position: relative" action="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/<?php echo basename(__FILE__); ?>" method="post">
<div><?php
	FeedWordPressCompatibility::stamp_nonce('feedwordpress_posts_settings');

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

<style type="text/css">
	table.edit-form th, table.form-table th { width: 27%; vertical-align: top; }
	table.edit-form td, table.form-table td { width: 73%; vertical-align: top; }
	ul.options { margin: 0; padding: 0; list-style: none; }
</style>

<?php $links = FeedWordPress::syndicated_links(); ?>
<?php if (fwp_test_wp_version(FWP_SCHEMA_27)) : ?>
	<div class="icon32"><img src="<?php print htmlspecialchars(WP_PLUGIN_URL.'/'.$GLOBALS['fwp_path'].'/feedwordpress.png'); ?>" alt="" /></div>
<?php endif; ?>

<h2>Syndicated Posts &amp; Links Settings<?php if (!is_null($link) and $link->found()) : ?>: <?php echo wp_specialchars($link->link->link_name, 1); ?><?php endif; ?></h2>

<?php 
if (fwp_test_wp_version(FWP_SCHEMA_27)) : // 2.7 or greater
?>
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
<?php endif; ?>
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
<?php fwp_linkedit_single_submit(); ?>
<div id="post-body">
<?php
$boxes_by_methods = array(
	'publication_box' => __('Publication'),
	'formatting_box' => __('Formatting'),
	'links_box' => __('Links'),
	'comments_and_pings_box' => __('Comments & Pings'),
	'custom_post_settings_box' => __('Custom Post Settings (to apply to each syndicated post)'),
);

// Feed-level settings don't exist for these.
if ($postsPage->for_feed_settings()) :
	unset($boxes_by_methods['formatting_box']);
	unset($boxes_by_methods['links_box']);
endif;

if (function_exists('add_meta_box')) :
	foreach ($boxes_by_methods as $method => $title) :
		add_meta_box(
			/*id=*/ 'feedwordpress_'.$method,
			/*title=*/ $title,
			/*callback=*/ array('FeedWordPressPostsPage', $method),
			/*page=*/ $postsPage->meta_box_context(),
			/*context=*/ $postsPage->meta_box_context()
		);
	endforeach;
	do_action('feedwordpress_admin_page_posts_meta_boxes', $postsPage)
?>
	<div class="metabox-holder">
<?php	do_meta_boxes($postsPage->meta_box_context(), $postsPage->meta_box_context(), $postsPage); ?>
	</div> <!-- class="metabox-holder" -->

	<div style="display: none">
	<div id="tags-input"></div> <!-- avoid JS error from WP 2.5 bug -->
	</div>
<?php
else :
	foreach ($boxes_by_methods as $method => $title) :
		$postsPage->{$method}($postsPage);
		fwp_linkedit_periodic_submit();
	endforeach;
?>
<?php endif; ?>
</div> <!-- id="post-body" -->
</div> <!-- id="poststuff" -->

<?php fwp_linkedit_single_submit_closer(); ?>
</form>
</div> <!-- class="wrap" -->

<?php
} /* function fwp_posts_page () */

	fwp_posts_page();

