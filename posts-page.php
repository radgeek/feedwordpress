<?php
require_once(dirname(__FILE__) . '/admin-ui.php');
require_once(dirname(__FILE__) . '/updatedpostscontrol.class.php');

class FeedWordPressPostsPage extends FeedWordPressAdminPage {
	var $link = NULL;
	var $updatedPosts = NULL;

	/**
	 * Construct the posts page object.
	 *
	 * @param mixed $link An object of class {@link SyndicatedLink} if created for one feed's settings, NULL if created for global default settings
	 */
	function FeedWordPressPostsPage ($link = -1) {
		if (is_numeric($link) and -1 == $link) :
			$link = FeedWordPressAdminPage::submitted_link();
		endif;

		FeedWordPressAdminPage::FeedWordPressAdminPage('feedwordpresspostspage', $link);
		$this->dispatch = 'feedwordpress_admin_page_posts';
		$this->filename = __FILE__;
		$this->updatedPosts = new UpdatedPostsControl($this);
		
		$this->pagenames = array(
			'default' => 'Posts',
			'settings-update' => 'Syndicated posts',
			'open-sheet' => 'Syndicated Posts & Links',
		);
	} /* FeedWordPressPostsPage constructor */

	function save_settings ($post) {
		// custom post settings
		$custom_settings = $this->custom_post_settings();

		foreach ($post['notes'] as $mn) :
			if (isset($mn['key0'])) :
				$mn['key0'] = trim($mn['key0']);
				if (strlen($mn['key0']) > 0) :
					unset($custom_settings[$mn['key0']]); // out with the old
				endif;
			endif;
				
			if (isset($mn['key1'])) :
				$mn['key1'] = trim($mn['key1']);

				if (($mn['action']=='update') and (strlen($mn['key1']) > 0)) :
					$custom_settings[$mn['key1']] = $mn['value']; // in with the new
				endif;
			endif;
		endforeach;

		$this->updatedPosts->accept_POST($post);
		if ($this->for_feed_settings()) :
			$alter = array ();

			$this->link->settings['postmeta'] = serialize($custom_settings);

			if (isset($post['resolve_relative'])) :
				$this->link->settings['resolve relative'] = $post['resolve_relative'];
			endif;
			if (isset($post['munge_permalink'])) :
				$this->link->settings['munge permalink'] = $post['munge_permalink'];
			endif;
			if (isset($post['munge_comments_feed_links'])) :
				$this->link->settings['munge comments feed links'] = $post['munge_comments_feed_links'];
			endif;

			// Post status, comment status, ping status
			foreach (array('post', 'comment', 'ping') as $what) :
				$sfield = "feed_{$what}_status";
				if (isset($post[$sfield])) :
					if ($post[$sfield]=='site-default') :
						unset($this->link->settings["{$what} status"]);
					else :
						$this->link->settings["{$what} status"] = $post[$sfield];
					endif;
				endif;
			endforeach;
			
			if (isset($post['syndicated_post_type'])) :
				if ($post['syndicated_post_type']=='default') :
					unset($this->link->settings['syndicated post type']);
				else :
					$this->link->settings['syndicated post type'] = $post['syndicated_post_type'];
				endif;
			endif;

		else :
			// update_option ...
			if (isset($post['feed_post_status'])) :
				update_option('feedwordpress_syndicated_post_status', $post['feed_post_status']);
			endif;

			update_option('feedwordpress_custom_settings', serialize($custom_settings));

			update_option('feedwordpress_munge_permalink', $_REQUEST['munge_permalink']);
			update_option('feedwordpress_use_aggregator_source_data', $_REQUEST['use_aggregator_source_data']);
			update_option('feedwordpress_formatting_filters', $_REQUEST['formatting_filters']);

			if (isset($post['resolve_relative'])) :
				update_option('feedwordpress_resolve_relative', $post['resolve_relative']);
			endif;
			if (isset($post['munge_comments_feed_links'])) :
				update_option('feedwordpress_munge_comments_feed_links', $post['munge_comments_feed_links']);
			endif;
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
			
			if (isset($post['syndicated_post_type'])) :
				update_option('feedwordpress_syndicated_post_type', $post['syndicated_post_type']);
			endif;
		endif;
		parent::save_settings($post);
	}

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
	 * @uses SyndicatedLink::syndicated_status()
	 * @uses SyndicatedPost::use_api()
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
			$setting['site-default']['label'] .= " <span class=\"current-setting\">Currently: <strong>${currently}</strong></span>";
	
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
		?>
		<style type="text/css">
		#syndicated-publication-form th { width: 27%; vertical-align: top; }
		#syndicated-publication-form td { width: 73%; vertical-align: top; }
		</style>
	
		<table id="syndicated-publication-form" class="form-table" cellspacing="2" cellpadding="5">
		<tr><th scope="row"><?php _e('New posts:'); ?></th>
		<td><ul class="options">
		<?php foreach ($selector as $code => $li) : ?>
			<li><label><input type="radio" name="feed_post_status"
			value="<?php print $code; ?>"<?php print $li['checked']; ?> />
			<?php print str_replace('%s', $thesePosts, $li['label']); ?></label></li>
		<?php endforeach; ?>
		</ul></td>
		</tr>

		<?php $page->updatedPosts->display(); ?>
		</table>
	
		<?php
	} /* FeedWordPressPostsPage::publication_box () */
	
	/**
	 * Outputs "Formatting" settings box
	 *
	 * @since 2009.0713
	 * @param object $page of class FeedWordPressPostsPage tells us whether this is
	 *	a page for one feed's settings or for global defaults
	 * @param array $box
	 *
	 */ 
	function formatting_box ($page, $box = NULL) {
		global $fwp_path;
		$thesePosts = $page->these_posts_phrase();
		$global_resolve_relative = get_option('feedwordpress_resolve_relative', 'yes');
		if ($page->for_feed_settings()) :
			$formatting_filters = null;
			$resolve_relative = $page->link->setting('resolve relative', NULL, 'default');
			$url = preg_replace('|/+$|', '', $page->link->homepage());
			$setting = array(
				'yes' => __('resolve relative URIs'),
				'no' => __('leave relative URIs unresolved'),
			);
			$href = $fwp_path.'/'.basename(__FILE__);
		else :
			$formatting_filters = get_option('feedwordpress_formatting_filters', 'no');
			$resolve_relative = $global_resolve_relative;
			$url = 'http://example.com';
		endif;
		?>
		<table class="form-table" cellspacing="2" cellpadding="5">
		<?php if (!is_null($formatting_filters)) : ?>

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

		<?php endif; ?>
		
		<tr><th scope="row">Relative URIs:</th>
		<td>If link or image in a syndicated post from <code><?php print $url; ?></code>
		refers to a partial URI like <code>/about</code>, where should
		the syndicated copy point to?</p>

		<ul>
		<?php if ($page->for_feed_settings()) : ?>
		<li><p><label><input type="radio" name="resolve_relative" value='default' <?php echo ($resolve_relative=='default')?' checked="checked"':''; ?>/> Use <a href="admin.php?page=<?php print $href; ?>">site-wide setting</a><br/>
		<span class="current-setting">Currently: <strong><?php print $setting[$global_resolve_relative]; ?></strong></span></label></p></li>
		<?php endif; ?>
		<li><p><label><input type="radio" name="resolve_relative" value="yes"<?php echo ($resolve_relative!='no' and $resolve_relative!='default')?' checked="checked"':''; ?>/> Resolve the URI so it points to <code><?php print $url; ?></code><br/>
		<small style="margin-left: 2.0em;"><code>/contact</code> is rewritten as <code><?php print $url; ?>/contact</code></label></small></p></li>
		<li><p><label><input type="radio" name="resolve_relative" value="no"<?php echo ($resolve_relative=='no')?' checked="checked"':''; ?>/> Leave relative URIs unchanged, so they point to this site<br/>
		<small style="margin-left: 2.0em;"><code>/contact</code> is left as <code>/contact</code></small></label></li>
		</ul>
		</td></tr>

		</table>
		<?php
	} /* FeedWordPressPostsPage::formatting_box() */
	
	/**
	 * Output "Links" settings box
	 *
	 * @since 2009.0713
	 * @param object $page of class FeedWordPressPostsPage tells us whether this is
	 *	a page for one feed's settings or for global defaults
	 * @param array $box
	 *
	 */
	/*static*/ function links_box ($page, $box = NULL) {
		global $fwp_path;

		$setting = array(
			'munge_permalink' => array(
				'yes' => __('The copy on the original website'),
				'no' => __('The local copy on this website'),
			),
		);

		$global_munge_permalink = get_option('feedwordpress_munge_permalink', NULL);
		if (is_null($global_munge_permalink)) :
			$global_munge_permalink = 'yes';
		endif;

		$checked = array(
			'munge_permalink' => array(
				'yes' => '',
				'no' => '',
			),
		);
		if ($page->for_feed_settings()) :
			$munge_permalink = $page->link->setting('munge permalink', NULL);
			
			$checked['munge_permalink']['default'] = '';
			if (is_null($munge_permalink)) :
				$checked['munge_permalink']['default'] = ' checked="checked"';
			else :
				$checked['munge_permalink'][$munge_permalink] = ' checked="checked"';
			endif;
			$href = $fwp_path.'/'.basename(__FILE__);
		else :
			$munge_permalink = $global_munge_permalink;
			$checked['munge_permalink'][$munge_permalink] = ' checked="checked"';

			$use_aggregator_source_data = get_option('feedwordpress_use_aggregator_source_data');
		endif;
		?>
		<table class="form-table" cellspacing="2" cellpadding="5">
		<tr><th  scope="row">Permalinks point to:</th>
		<td><ul class="options">	
		<?php if ($page->for_feed_settings()) : ?>
		<li><label><input type="radio" name="munge_permalink" value="default"<?php print $checked['munge_permalink']['default']; ?> /> Use <a href="admin.php?page=<?php print $href; ?>">site-wide setting</a>
		<span class="current-setting">Currently: <strong><?php print $setting['munge_permalink'][$global_munge_permalink]; ?></strong></span></label></li>
		<?php endif; ?>
		<li><label><input type="radio" name="munge_permalink" value="yes"<?php print $checked['munge_permalink']['yes']; ?> /> <?php print $setting['munge_permalink']['yes']; ?></label></li>
		<li><label><input type="radio" name="munge_permalink" value="no"<?php print $checked['munge_permalink']['no']; ?> /> <?php print $setting['munge_permalink']['no']; ?></label></li>
		</ul></td>
		</tr>
		
		<?php if (!$page->for_feed_settings()) : ?>
		<tr><th scope="row">Posts from aggregator feeds:</th>
		<td><ul class="options">
		<li><label><input type="radio" name="use_aggregator_source_data" value="no"<?php echo ($use_aggregator_source_data!="yes")?' checked="checked"':''; ?>> Give the aggregator itself as the source of posts from an aggregator feed.</label></li>
		<li><label><input type="radio" name="use_aggregator_source_data" value="yes"<?php echo ($use_aggregator_source_data=="yes")?' checked="checked"':''; ?>> Give the original source of the post as the source, not the aggregator.</label></li>
		</ul>
		<p class="setting-description">Some feeds (for example, those produced by FeedWordPress) aggregate content from several different sources, and include information about the original source of the post.
		This setting controls what FeedWordPress will give as the source of posts from
		such an aggregator feed.</p>
		</td></tr>
		<?php endif; ?>
		</table>

		<?php
	} /* FeedWordPressPostsPage::links_box() */

	/**
	 * Output "Comments & Pings" settings box
	 *
	 * @since 2009.0713
	 * @param object $page of class FeedWordPressPostsPage tells us whether this is
	 *	a page for one feed's settings or for global defaults
	 * @param array $box
	 *
	 */
	/*static*/ function comments_and_pings_box ($page, $box = NULL) {
		global $fwp_path;

		$setting = array();
		$selector = array();

		$whatsits = array(
			'comment' => array('label' => __('Comments'), 'accept' => 'Allow comments'),
			'ping' => array('label' => __('Pings'), 'accept' => 'Accept pings'),
		);
		$onThesePosts = 'on '.$page->these_posts_phrase();

		$selected = array(
			'munge_comments_feed_links' => array('yes' => '', 'no' => '')
		);
		
		$globalMungeCommentsFeedLinks = get_option('feedwordpress_munge_comments_feed_links', 'yes');
		if ($page->for_feed_settings()) :
			$selected['munge_comments_feed_links']['default'] = '';
			
			$sel =  $page->link->setting('munge comments feed links', NULL, 'default');
		else :
			$sel = $globalMungeCommentsFeedLinks;
		endif;
		$selected['munge_comments_feed_links'][$sel] = ' checked="checked"';
		
		if ($globalMungeCommentsFeedLinks != 'no') : $siteWide = __('comment feeds from the original website');
		else : $siteWide = __('local comment feeds on this website');
		endif;

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
				$setting['site-default']['label'] .= " <span class=\"current-setting\">Currently: <strong>${currently}</strong></span>";
		
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
		  <tr><th scope="row"><?php _e('Comment feeds'); ?></th>
		  <td><p>When WordPress feeds and templates link to comments
		  feeds for <?php print $page->these_posts_phrase(); ?>, the
		  URLs for the feeds should...</p>
		  <ul class="options">
		  <?php if ($page->for_feed_settings()) : ?>
		  <li><label><input type="radio" name="munge_comments_feed_links" value="default"<?php print $selected['munge_comments_feed_links']['default']; ?> /> Use <a href="admin.php?page=<?php print $href; ?>">site-wide setting</a>
		  <span class="current-setting">Currently: <strong><?php _e($siteWide); ?></strong></span></label></li>
		  <?php endif; ?>
		  <li><label><input type="radio" name="munge_comments_feed_links" value="yes"<?php print $selected['munge_comments_feed_links']['yes']; ?> /> <?php _e('Point to comment feeds from the original website (when provided by the syndicated feed)'); ?></label></li>
		  <li><label><input type="radio" name="munge_comments_feed_links" value="no"<?php print $selected['munge_comments_feed_links']['no']; ?> /> <?php _e('Point to local comment feeds on this website'); ?></label></li>
		  </ul></td></tr>
		</table>

		<?php
	} /* FeedWordPressPostsPage::comments_and_pings_box() */
	
	/*static*/ function custom_post_settings ($page = NULL) {
		if (is_null($page)) :
			$page = $this;
		endif;

		if ($page->for_feed_settings()) :
			$custom_settings = $page->link->setting("postmeta", NULL, array());
		else :
			$custom_settings = get_option('feedwordpress_custom_settings');
		endif;

		if ($custom_settings and !is_array($custom_settings)) :
			$custom_settings = unserialize($custom_settings);
		endif;
		
		if (!is_array($custom_settings)) :
			$custom_settings = array();
		endif;

		return $custom_settings;
	} /* FeedWordPressPostsPage::custom_post_settings() */
	
	/**
	 * Output "Custom Post Settings" settings box
	 *
	 * @since 2009.0713
	 * @param object $page of class FeedWordPressPostsPage tells us whether this is
	 *	a page for one feed's settings or for global defaults
	 * @param array $box
	 */
	/*static*/ function custom_post_settings_box ($page, $box = NULL) {
		$custom_settings = FeedWordPressPostsPage::custom_post_settings($page);
		?>
		<div id="postcustomstuff">
		<p>Custom fields can be used to add extra metadata to a post that you can <a href="http://codex.wordpress.org/Using_Custom_Fields">use in your theme</a>.</p>
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
		    <th width="30%" scope="row"><input type="hidden" name="notes[<?php echo $i; ?>][key0]" value="<?php echo esc_html($key); ?>" />
		    <input id="notes-<?php echo $i; ?>-key" name="notes[<?php echo $i; ?>][key1]" value="<?php echo esc_html($key); ?>" /></th>
		    <td width="60%"><textarea rows="2" cols="40" id="notes-<?php echo $i; ?>-value" name="notes[<?php echo $i; ?>][value]"><?php echo esc_html($value); ?></textarea></td>
		    <td width="10%"><select name="notes[<?php echo $i; ?>][action]">
		    <option value="update">save changes</option>
		    <option value="delete">delete this setting</option>
		    </select></td>
		  </tr>

		<?php
			$i++;
		endforeach;
		?>

		  <tr style="vertical-align: top">
		    <th scope="row"><input type="text" size="10" name="notes[<?php echo $i; ?>][key1]" value="" /></th>
		    <td><textarea name="notes[<?php echo $i; ?>][value]" rows="2" cols="40"></textarea>
		      <p>Enter a text value, or a path to a data element from the syndicated item.<br/>
		      For data elements, you can use an XPath-like syntax wrapped in <code>$( ... )</code>.<br/>
		      <code>hello</code> = the text value <code><span style="background-color: #30FFA0;">hello</span></code><br/>
		      <code>$(author/email)</code> = the contents of <code>&lt;author&gt;&lt;email&gt;<span style="background-color: #30FFA0">...</span>&lt;/email&gt;&lt;/author&gt;</code><br/>
		      <code>$(media:content/@url)</code> = the contents of <code>&lt;media:content url="<span style="background-color: #30FFA0">...</span>"&gt;...&lt;/media:content&gt;</code></p>
		    </td>
		    <td><em>add new setting...</em><input type="hidden" name="notes[<?php echo $i; ?>][action]" value="update" /></td>
		  </tr>
		</table>
		</div> <!-- id="postcustomstuff" -->

		<?php
	} /* FeedWordPressPostsPage::custom_post_settings_box() */

	function custom_post_types_box ($page, $box = NULL) {
		global $fwp_path;
		
		$global_syndicated_post_type = get_option('feedwordpress_syndicated_post_type', 'post');
		if ($page->for_feed_settings()) :
			$syndicated_post_type = $page->link->setting('syndicated post type', NULL, NULL);
			if (is_null($syndicated_post_type)) :
				$syndicated_post_type = 'default';
			endif;
		else :
			$syndicated_post_type = $global_syndicated_post_type;
			if (is_null($syndicated_post_type)) :
				$syndicated_post_type = 'post';
			endif;
		endif;

		// Get all custom post types
		$post_types = get_post_types(array(
		'_builtin' => false,
		), 'objects');

		$ul = array();
		$ul['post'] = array('label' => __('Normal WordPress posts'), 'checked' => '');
		foreach ($post_types as $post_type) :
			$ul[$post_type->name] = array('label' => __($post_type->labels->name), 'checked' => '');
		endforeach;
		
		if ($page->for_feed_settings()) :
			$href = 'admin.php?page='.$fwp_path.'/'.basename(__FILE__);
			$currently = $ul[$global_syndicated_post_type]['label'];
			$ul = array_merge(array(
				'default' => array(
					'label' => sprintf(
						__('Use <a href="%s">site-wide setting</a> <span class="current-setting">Currently: <strong>%s</strong></span>'),
						$href,
						$currently
					),
					'checked' => '',
				),
			), $ul);
		endif;
		$ul[$syndicated_post_type]['checked'] = ' checked="checked"';
		
		?>
		<table class="edit-form narrow">
		<tbody>
		<tr><th><?php _e('Custom Post Types:'); ?></th>
		<td><p>Incoming syndicated posts should be stored in the
		posts database as...</p>
		<ul class="options">
		<?php
		
			foreach ($ul as $post_type_name => $li) :
		?>
				<li><label><input
				type="radio" name="syndicated_post_type"
				value="<?php print $post_type_name; ?>"
				<?php print $li['checked']; ?>
				/>
				<?php print $li['label']; ?></label></li>
		<?php
			endforeach;
		?>
		
		</ul></td></tr>
		
		</tbody>
		</table>
		<?php
	} /* FeedWordPressPostsPage::custom_post_types_box() */
	
	function display () {
		$this->boxes_by_methods = array(
		'publication_box' => __('Syndicated Posts'),
		'links_box' => __('Links'),
		'formatting_box' => __('Formatting'),
		'comments_and_pings_box' => __('Comments & Pings'),
		'custom_post_settings_box' => __('Custom Post Settings (to apply to each syndicated post)'),
		'custom_post_types_box' => ('Custom Post Types (advanced database settings)'),
		);
		
		parent::display();
	 } /* FeedWordPressPostsPage::display () */
}

	$postsPage = new FeedWordPressPostsPage;
	$postsPage->display();

