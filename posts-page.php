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
	 */ 
	/*static*/ function publication_box ($page, $box = NULL) {
		$thesePosts = $page->these_posts_phrase();
		$postSelector = array(
		'publish' => "Publish %s immediately",
		'pending' => "Hold %s for review; mark as Pending",
		'draft' => "Save %s as drafts",
		'private' => "Save %s as private posts",
		);
		$labels = array();
		foreach ($postSelector as $index => $value) :
			$postSelector[$index] = sprintf(__($value), $thesePosts);
			$labels[$index] = __(str_replace(' %s', '', strtolower(strtok($value, ';'))));
		endforeach;
		
		$params = array(
		'input-name' => 'feed_post_status',
		'setting-default' => NULL,
		'global-setting-default' => 'publish',
		'labels' => $labels,
		);

		// Hey ho, let's go...
		?>
		<table id="syndicated-publication-form" class="edit-form narrow">
		<tr><th scope="row"><?php _e('New posts:'); ?></th>
		<td><?php
			$this->setting_radio_control(
				'post status', 'syndicated_post_status',
				$postSelector, $params
			);
		?>
		</td></tr>

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
		$thesePosts = $page->these_posts_phrase();

		if ($page->for_feed_settings()) :
			$formatting_filters = null;
			$url = preg_replace('|/+$|', '', $page->link->homepage());
		else :
			$formatting_filters = get_option('feedwordpress_formatting_filters', 'no');
			$url = 'http://example.com';
		endif;
		?>
		<table class="edit-form narrow">
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
		<td><p>If link or image in a syndicated post from <code><?php print $url; ?></code>
		refers to a partial URI like <code>/about</code>, where should
		the syndicated copy point to?</p>

		<?php
		$options = array(
			'yes' => 'Resolve the URI so it points to <code>'.$url.'</code><br/><small style="margin-left: 2.0em;"><code>/contact</code> is rewritten as <code>'.$url.'/contact</code></small>',
			'no' => 'Leave relative URIs unchanged, so they point to this site<br/><small style="margin-left: 2.0em;"><code>/contact</code> is left as <code>/contact</code></small>',
		);
		$params = array(
			'setting-default' => 'default',
			'global-setting-default' => 'yes',
			'labels' => array(
				'yes' => __('resolve relative URIs'),
				'no' => __('leave relative URIs unresolved'),
			),
			'default-input-value' => 'default',
		);

		$this->setting_radio_control(
			'resolve relative', 'resolve_relative',
			$options, $params
		);
		?>		
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
		$setting = array(
			'munge_permalink' => array(
				'yes' => __('The copy on the original website'),
				'no' => __('The local copy on this website'),
			),
		);

		if (!$page->for_feed_settings()) :
			$use_aggregator_source_data = get_option('feedwordpress_use_aggregator_source_data');
		endif;
		?>
		<table class="edit-form narrow">
		<tr><th  scope="row">Permalinks point to:</th>
		<td><?php
		
		$params = array(
			'setting-default' => 'default',
			'global-setting-default' => 'yes',
			'default-input-value' => 'default',
		);
		$this->setting_radio_control(
			'munge permalink', 'munge_permalink',
			$setting['munge_permalink'], $params
		);
		?>

		</td></tr>
		
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
		$whatsits = array(
			'comment' => array('label' => __('Comments'), 'accept' => 'Allow comments'),
			'ping' => array('label' => __('Pings'), 'accept' => 'Accept pings'),
		);
		$onThesePosts = 'on '.$page->these_posts_phrase();
		
		$mcflSettings = array(
			"yes" => __('Point to comment feeds from the original website (when provided by the syndicated feed)'),
			"no" => __('Point to local comment feeds on this website'),
		);
		$mcflParams = array(
			'setting-default' => 'default',
			'global-setting-default' => 'yes',
			'labels' => array(
				'yes' => __('comment feeds from the original website'),
				'no' => __('local comment feeds on this website')
			),
			'default-input-value' => 'default',
		);

		$settings = array(); $params = array();
		foreach ($whatsits as $what => $how) :
			$whatsits[$what]['default'] = FeedWordPress::syndicated_status($what, /*default=*/ 'closed');

			// Set up array for selector
			$settings[$what] = array(
				'open' => sprintf(__("{$how['accept']} %s"), __($onThesePosts)),
				'closed' => sprintf(__("Don't ".strtolower($how['accept'])." %s"), __($onThesePosts)),
			);
			$params[$what] = array(
			'input-name' => "feed_${what}_status",
			'setting-default' => NULL,
			'global-setting-default' => FeedWordPress::syndicated_status($what, /*default=*/ 'closed'),
			'labels' => array(
				'open' => strtolower(__($how['accept'])),
				'closed' => strtolower(__("Don't ".$how['accept'])),
				),
			);
		endforeach;

		// Hey ho, let's go...
		?>
		<table class="edit-form narrow">
		<?php foreach ($whatsits as $what => $how) : ?>
		  
		  <tr><th scope="row"><?php print $how['label']; ?>:</th>
		  <td><?php
		  	$this->setting_radio_control(
		  		"$what status", "syndicated_${what}_status",
		  		$settings[$what], $params[$what]
		  	);
		  ?></td></tr>
		  
		<?php endforeach; ?>
		
		  <tr><th scope="row"><?php _e('Comment feeds'); ?></th>
		  <td><p>When WordPress feeds and templates link to comments
		  feeds for <?php print $page->these_posts_phrase(); ?>, the
		  URLs for the feeds should...</p>
		  <?php
		  	$this->setting_radio_control(
		  		"munge comments feed links", "munge_comments_feed_links",
		  		$mcflSettings, $mcflParams
		  	);
		  ?></td></tr>
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
		$testerButton = '<br/><button id="xpath-test-%d"'
			.'class="xpath-test"'
			.'>test expression</button>';
		foreach ($custom_settings as $key => $value) : 
		?>
		  <tr style="vertical-align:top">
		    <th width="30%" scope="row"><input type="hidden" name="notes[<?php echo $i; ?>][key0]" value="<?php echo esc_html($key); ?>" />
		    <input id="notes-<?php echo $i; ?>-key" name="notes[<?php echo $i; ?>][key1]" value="<?php echo esc_html($key); ?>" /></th>
		    <td width="60%"><textarea rows="2" cols="40" id="notes-<?php echo $i; ?>-value" name="notes[<?php echo $i; ?>][value]"><?php echo esc_html($value); ?></textarea>
		    <?php print sprintf($testerButton, $i); ?></td>
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
		    <td><textarea name="notes[<?php echo $i; ?>][value]" rows="2" cols="40"></textarea><?php print sprintf($testerButton, $i); ?>
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
		
		// local: syndicated post type // default NULL
		// global: syndicated_post_type // default 'post'
		// default-input-value => 'default'
		
		// Get all custom post types
		$post_types = get_post_types(array(
		'_builtin' => false,
		), 'objects');

		$ul = array();
		$ul['post'] = __('Normal WordPress posts');
		foreach ($post_types as $post_type) :
			$ul[$post_type->name] = __($post_type->labels->name);
		endforeach;
		
		$params = array(
			'global-setting-default' => 'post',
			'default-input-value' => 'default',
		);
		
		// Hey, ho, let's go...
		?>
		<table class="edit-form narrow">
		<tbody>
		<tr><th><?php _e('Custom Post Types:'); ?></th>
		<td><p>Incoming syndicated posts should be stored in the
		posts database as...</p>
		<?php
			$this->setting_radio_control(
				'syndicated post type', 'syndicated_post_type',
				$ul, $params
			);
		?>
		</td></tr>
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

