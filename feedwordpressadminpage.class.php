<?php
/**
 * class FeedWordPressAdminPage
 *
 * Handles a lot of the interface-code related jots and tittles for putting together
 * admin / settings pages within FeedWordPress, like Syndication > Posts & Links,
 * Syndication > Feeds & Updates, etc., whether for setting global defaults, or for
 * settings on particular feeds.
 *
 */
class FeedWordPressAdminPage {
	protected $context;
	protected $updated = false;
	protected $mesg = NULL;

	var $link = NULL;
	var $dispatch = NULL;
	var $filename = NULL;
	var $pagenames = array();

	/**
	 * Construct the admin page object.
	 *
	 * @param mixed $link An object of class {@link SyndicatedLink} if created for one feed's settings, NULL if created for global default settings
	 */
	public function __construct( $page = 'feedwordpressadmin', $link = NULL ) {
		$this->link = $link;

		// Set meta-box context name
		$this->context = $page;
		if ($this->for_feed_settings()) :
			$this->context .= 'forfeed';
		endif;
	} /* FeedWordPressAdminPage constructor */

	public function pageslug () {
		$slug = preg_replace('/FeedWordPress(.*)Page/', '$1', get_class($this));
		return strtolower($slug);
	}

	public function pagename ($context = NULL) {
		if (is_null($context)) :
			$context = 'default';
		endif;

		if (isset($this->pagenames[$context])) :
			$name = $this->pagenames[$context];
		elseif (isset($tis->pagenames['default'])) :
			$name = $this->pagenames['default'];
		else :
			$name = $this->pageslug();
		endif;
		return __($name);
	} /* FeedWordPressAdminPage::pagename () */

	public function accept_POST ($post) {
		if ($this->for_feed_settings() and $this->update_requested_in($post)) :
			$this->update_feed();
		elseif ($this->save_requested_in($post)) : // User mashed Save Changes
			$this->save_settings($post);
		endif;
		do_action($this->dispatch.'_post', $post, $this);
	}

	public function update_feed () {
		global $feedwordpress;

		add_action('feedwordpress_check_feed', 'update_feeds_mention');
		add_action('feedwordpress_check_feed_complete', 'update_feeds_finish', 10, 3);

		$link = $this->link;
		
		print '<div class="updated">';
		print "<ul>";
		$uri = $this->link->uri();
		$displayUrl = $uri;

		// check for effects of an effective-url filter
		$effectiveUrl = $link->uri(array('fetch' => true));
		if ($uri != $effectiveUrl) : $displayUrl .= ' | ' . $effectiveUrl; endif;

		$delta = $feedwordpress->update($uri);
		print "</ul>";

		if (!is_null($delta)) :
			echo "<p><strong>Update complete.</strong>".fwp_update_set_results_message($delta)."</p>";
			echo "\n"; flush();
		else :
			$effectiveUrl  = esc_html($effectiveUrl);
			echo "<p><strong>Error:</strong> There was a problem updating <a href=\"$effectiveUrl\">$displayUrl</a></p>\n";
		endif;
		print "</div>\n";
		remove_action('feedwordpress_check_feed', 'update_feeds_mention');
		remove_action('feedwordpress_check_feed_complete', 'update_feeds_finish', 10, 3);
	}

	public function save_settings ($post) {
		do_action($this->dispatch.'_save', $post, $this);

		if ($this->for_feed_settings()) :
			// Save settings
			$this->link->save_settings(/*reload=*/ true);
			$this->updated = true;

			// Reset, reload
			$link_id = $this->link->id;
			unset($this->link);
			$this->link = new SyndicatedLink($link_id);
		else :
			$this->updated = true;
		endif;
	} /* FeedWordPressAdminPage::save_settings () */

	public function for_feed_settings () { return (is_object($this->link) and method_exists($this->link, 'found') and $this->link->found()); }
	public function for_default_settings () { return !$this->for_feed_settings(); }

	public function setting ($names, $fallback_value = NULL, $params = array()) {
		if (!is_array($params)) :
			$params = array('default' => $params);
		endif;
		$params = shortcode_atts(array(
		'default' => 'default',
		'fallback' => true,
		), $params);

		if (is_string($names)) :
			$feed_name = $names;
			$global_name = 'feedwordpress_'.preg_replace('![\s/]+!', '_', $names);
		else :
			$feed_name = $names['feed'];
			$global_name = 'feedwordpress_'.$names['global'];
		endif;

		if ($this->for_feed_settings()) : // Check feed-specific setting first; fall back to global
			if (!$params['fallback']) : $global_name = NULL; endif;
			$ret = $this->link->setting($feed_name, $global_name, $fallback_value, $params['default']);
		else : // Check global setting
			$ret = get_option($global_name, $fallback_value);
		endif;
		return $ret;
	}

	public function update_setting ($names, $value, $default = 'default') {
		if (is_string($names)) :
			$feed_name = $names;
			$global_name = 'feedwordpress_'.preg_replace('![\s/]+!', '_', $names);
		else :
			$feed_name = $names['feed'];
			$global_name = 'feedwordpress_'.$names['global'];
		endif;

		if ($this->for_feed_settings()) : // Update feed-specific setting
			$this->link->update_setting($feed_name, $value, $default);
		else : // Update global setting
			update_option($global_name, $value);
		endif;
	} /* FeedWordPressAdminPage::update_setting () */

	public function save_requested_in ($post) {
		return (isset($post['save']) or isset($post['submit']));
	}
	public function update_requested_in ($post) {
		return (isset($post['update']) and (strlen($post['update']) > 0));
	}

	public function submitted_link_id () {
		global $fwp_post;

		// Presume global unless we get a specific link ID
		$link_id = NULL;

		$submit_buttons = array(
			'save',
			'submit',
			'fix_mismatch',
			'feedfinder',
		);
		foreach ($submit_buttons as $field) :
			if (isset($fwp_post[$field])) :
				$link_id = MyPHP::request('save_link_id');
			endif;
		endforeach;

		if (is_null($link_id) and isset($_REQUEST['link_id'])) :
			$link_id = MyPHP::request('link_id');
		endif;

		return $link_id;
	} /* FeedWordPressAdminPage::submitted_link_id() */

	public function submitted_link () {
		$link_id = $this->submitted_link_id();
		if (is_numeric($link_id) and $link_id) :
			$link = new SyndicatedLink($link_id);
		else :
			$link = NULL;
		endif;
		return $link;
	} /* FeedWordPressAdminPage::submitted_link () */

	public function stamp_link_id ($field = null) {
		if (is_null($field)) : $field = 'save_link_id'; endif;
		?>
	<input type="hidden" name="<?php print esc_attr($field); ?>" value="<?php print ($this->for_feed_settings() ? $this->link->id : '*'); ?>" />
		<?php
	} /* FeedWordPressAdminPage::stamp_link_id () */

	public function these_posts_phrase () {
		if ($this->for_feed_settings()) :
			$phrase = __('posts from this feed');
		else :
			$phrase = __('syndicated posts');
		endif;
		return $phrase;
	} /* FeedWordPressAdminPage::these_posts_phrase() */

	/**
	 * Provides a uniquely identifying name for the interface context for
	 * use with add_meta_box() and do_meta_boxes(),
	 *
	 * @return string the context name
	 *
	 * @see add_meta_box()
	 * @see do_meta_boxes()
	 */
	public function meta_box_context () {
		return $this->context;
	} /* FeedWordPressAdminPage::meta_box_context () */

	/**
	 * Outputs JavaScript to fix AJAX toggles settings.
	 *
	 * @uses FeedWordPressAdminPage::meta_box_context()
	 */
	 public function fix_toggles () {
	 	 FeedWordPressSettingsUI::fix_toggles_js($this->meta_box_context());
	 } /* FeedWordPressAdminPage::fix_toggles() */

	 public function ajax_interface_js () {
?>
	function contextual_appearance (item, appear, disappear, value, visibleStyle, checkbox) {
		if (typeof(visibleStyle)=='undefined') visibleStyle = 'block';

		var rollup=document.getElementById(item);
		if (rollup) {
			if ((checkbox && rollup.checked) || (!checkbox && value==rollup.value)) {
				jQuery('#'+disappear).hide();
				jQuery('#'+appear).show(600);
			} else {
				jQuery('#'+appear).hide();
				jQuery('#'+disappear).show(600);
			}
		}
	}
<?php
	} /* FeedWordPressAdminPage::ajax_interface_js () */

	public function admin_page_href ($page, $params = array(), $link = NULL) {
		global $fwp_path;

		// Merge in the page's filename
		$params = array_merge($params, array('page' => $fwp_path.'/'.$page));

		// If there is a link ID provided, then merge that in too.
		if (!is_null($link)) :
			$link_id = NULL;
			if (is_object($link)) :
				if (method_exists($link, 'found')) :
					// Is this a SyndicatedLink object?
					if ($link->found()) :
						$link_id = $link->link->link_id;
					endif;
				else :
					// Is this a wp_links table record?
					$link_id = $link->link_id;
				endif;
			else :
				// Is this just a numeric ID?
				$link_id = $link;
			endif;

			if (!is_null($link_id)) :
				$params = array_merge($params, array('link_id' => $link_id));
			endif;
		endif;

		return MyPHP::url(admin_url('admin.php'), $params);
	} /* FeedWordPressAdminPage::admin_page_href () */

	public function display_feed_settings_page_links ($params = array()) {
		global $fwp_path;

		$params = wp_parse_args($params, array(
			'before' => '',
			'between' => ' | ',
			'after' => '',
			'long' => false,
			'subscription' => $this->link,
		));
		$sub = $params['subscription'];

		$links = array(
			"Feed" => array('page' => 'feeds-page.php', 'long' => 'Feeds & Updates'),
			"Posts" => array('page' => 'posts-page.php', 'long' => 'Posts & Links'),
			"Authors" => array('page' => 'authors-page.php', 'long' => 'Authors'),
			'Categories' => array('page' => 'categories-page.php', 'long' => 'Categories & Tags'),
		);

		$link_id = NULL;
		if (is_object($sub)) :
			if (method_exists($sub, 'found')) :
				if ($sub->found()) :
					$link_id = $sub->link->link_id;
				endif;
			else :
				$link_id = $sub->link_id;
			endif;
		endif;

		print $params['before']; $first = true;
		foreach ($links as $label => $link) :
			if (!$first) :	print $params['between']; endif;

			if (isset($link['url'])) : MyPHP::url($link['url'], array("link_id" => $link_id));
			else : $url = $this->admin_page_href($link['page'], array(), $sub);
			endif;
			$url = esc_html($url);

			if ($link['page']==basename($this->filename)) :
				print "<strong>";
			else :
				print "<a href=\"${url}\">";
			endif;

			if ($params['long']) : print esc_html(__($link['long']));
			else : print esc_html(__($label));
			endif;

			if ($link['page']==basename($this->filename)) :
				print "</strong>";
			else :
				print "</a>";
			endif;

			$first = false;
		endforeach;
		print $params['after'];
	} /* FeedWordPressAdminPage::display_feed_settings_page_links */

	public function display_feed_select_dropdown() {
		$links = FeedWordPress::syndicated_links();

		?>
		<div id="fwpfs-container"><ul class="subsubsub">
		<li><select name="link_id" class="fwpfs" style="max-width: 20.0em;">
		  <option value="*"<?php if ($this->for_default_settings()) : ?> selected="selected"<?php endif; ?>>- defaults for all feeds -</option>
		<?php if ($links) : foreach ($links as $ddlink) : ?>
		  <option value="<?php print (int) $ddlink->link_id; ?>"<?php if (!is_null($this->link) and ($this->link->id==$ddlink->link_id)) : ?> selected="selected"<?php endif; ?>><?php print esc_html($ddlink->link_name); ?></option>
		<?php endforeach; endif; ?>
		</select>
		<input id="fwpfs-button" class="button" type="submit" name="go" value="<?php _e('Go') ?> &raquo;" /></li>

		<?php
		$this->display_feed_settings_page_links(array(
			'before' => '<li>',
			'between' => "</li>\n<li>",
			'after' => '</li>',
			'subscription' => $this->link,
		));

		if ($this->for_feed_settings()) :
		?>
		<li><input class="button" type="submit" name="update" value="Update Now" /></li>
		<?php
		endif;
		?>
		</ul>
		</div>
		<?php
	} /* FeedWordPressAdminPage::display_feed_select_dropdown() */

	public function display_sheet_header ($pagename = 'Syndication', $all = false) {
		?>
		<div class="icon32"><img src="<?php print esc_attr( plugins_url( 'feedwordpress.png', __FILE__ ) ); ?>" alt="" /></div>
		<h2><?php print esc_html(__($pagename.($all ? '' : ' Settings'))); ?><?php if ($this->for_feed_settings()) : ?>: <?php echo esc_html($this->link->name(/*from feed=*/ false)); ?><?php endif; ?></h2>
		<?php
	}

	public function display_update_notice_if_updated ($pagename = 'Syndication', $mesg = NULL) {
		if (!is_null($mesg)) :
			$this->mesg = $mesg;
		endif;

		if ($this->updated) :
			if ($this->updated === true) :
				$this->mesg = $pagename . ' settings updated.';
			else :
				$this->mesg = $this->updated;
			endif;
		endif;

		if (!is_null($this->mesg)) :
			?>
			<div class="updated">
			<p><?php print esc_html($this->mesg); ?></p>
			</div>
			<?php
		endif;
	} /* FeedWordPressAdminPage::display_update_notice_if_updated() */

	public function display_settings_scope_message () {
		if ($this->for_feed_settings()) :
		?>
	<p>These settings only affect posts syndicated from
	<strong><?php echo esc_html($this->link->link->link_name); ?></strong>.</p>
		<?php
		else :
		?>
	<p>These settings affect posts syndicated from any feed unless they are overridden
	by settings for that specific feed.</p>
		<?php
		endif;
	} /* FeedWordPressAdminPage::display_settings_scope_message () */

	/*static*/ function has_link () { return true; }

	public function form_action ($filename = NULL) {
		if (is_null($filename)) :
			$filename = basename($this->filename);
		endif;
		return $this->admin_page_href($filename);
	} /* FeedWordPressAdminPage::form_action () */

	public function update_message () {
		return $this->mesg;
	}

	public function display () {
		global $fwp_post;

		if (FeedWordPress::needs_upgrade()) :
			fwp_upgrade_page();
			return;
		endif;

		FeedWordPressCompatibility::validate_http_request(/*action=*/ $this->dispatch, /*capability=*/ 'manage_links');

		////////////////////////////////////////////////
		// Process POST request, if any ////////////////
		////////////////////////////////////////////////
		if (strtoupper($_SERVER['REQUEST_METHOD'])=='POST') :
			$this->accept_POST($fwp_post);
		else :
			$this->updated = false;
		endif;

		////////////////////////////////////////////////
		// Prepare settings page ///////////////////////
		////////////////////////////////////////////////

		$this->display_update_notice_if_updated(
			$this->pagename('settings-update'),
			$this->update_message()
		);

		$this->open_sheet($this->pagename('open-sheet'));
		?>
		<div id="post-body">
		<?php
		foreach ($this->boxes_by_methods as $method => $row) :
			if (is_array($row)) :
				$id = $row['id'];
				$title = $row['title'];
			else :
				$id = 'feedwordpress_'.$method;
				$title = $row;
			endif;

			add_meta_box(
				/*id=*/ $id,
				/*title=*/ $title,
				/*callback=*/ array($this, $method),
				/*page=*/ $this->meta_box_context(),
				/*context=*/ $this->meta_box_context()
			);
		endforeach;
		do_action($this->dispatch.'_meta_boxes', $this);
		?>
		<div class="metabox-holder">
		<?php
			do_meta_boxes($this->meta_box_context(), $this->meta_box_context(), $this);
		?>
		</div> <!-- class="metabox-holder" -->
		</div> <!-- id="post-body" -->
		<?php $this->close_sheet(); ?>
	<?php
	}

	public function open_sheet ($header) {
		// Set up prepatory AJAX stuff
		?>
		<script type="text/javascript">
		<?php
		$this->ajax_interface_js();
		?>
		</script>

		<?php
		add_action(
			FeedWordPressCompatibility::bottom_script_hook($this->filename),
			/*callback=*/ array($this, 'fix_toggles'),
			/*priority=*/ 10000
		);
		FeedWordPressSettingsUI::ajax_nonce_fields();

		?>
		<div class="wrap feedwordpress-admin" id="feedwordpress-admin-<?php print $this->pageslug(); ?>">
		<?php
		if (!is_null($header)) :
			$this->display_sheet_header($header);
		endif;

		if (!is_null($this->dispatch)) :
			?>
			<form action="<?php print $this->form_action(); ?>" method="post">
			<div><?php
				FeedWordPressCompatibility::stamp_nonce($this->dispatch);
				$this->stamp_link_id();
			?></div>
			<?php
		endif;

		if ($this->has_link()) :
			$this->display_settings_scope_message();
		endif;

		?><div class="tablenav"><?php
		if (!is_null($this->dispatch)) :
			?><div class="alignright"><?php
			$this->save_button();
			?></div><?php
		endif;

		if ($this->has_link()) :
			$this->display_feed_select_dropdown();
		endif;
		?>
		</div>

		<div id="poststuff">
		<?php
	} /* FeedWordPressAdminPage::open_sheet () */

	public function close_sheet () {
		?>

		</div> <!-- id="poststuff" -->
		<?php
		if (!is_null($this->dispatch)) :
			$this->save_button();
			print "</form>\n";
		endif;
		?>
		</div> <!-- class="wrap" -->

		<?php
	} /* FeedWordPressAdminPage::close_sheet () */

	public function setting_radio_control ($localName, $globalName, $options, $params = array()) {
		global $fwp_path;

		if (isset($params['filename'])) : $filename = $params['filename'];
		else : $filename = basename($this->filename);
		endif;

		if (isset($params['site-wide-url'])) : $href = $params['site-wide-url'];
		else : 	$href = $this->admin_page_href($filename);
		endif;

		if (isset($params['setting-default'])) : $settingDefault = $params['setting-default'];
		else : $settingDefault = NULL;
		endif;

		if (isset($params['global-setting-default'])) : $globalSettingDefault = $params['global-setting-default'];
		else : $globalSettingDefault = $settingDefault;
		endif;

		$globalSetting = get_option('feedwordpress_'.$globalName, $globalSettingDefault);
		if ($this->for_feed_settings()) :
			$setting = $this->link->setting($localName, NULL, $settingDefault);
		else :
			$setting = $globalSetting;
		endif;

		if (isset($params['offer-site-wide'])) : $offerSiteWide = $params['offer-site-wide'];
		else : $offerSiteWide = $this->for_feed_settings();
		endif;

		// This allows us to provide an alternative set of human-readable
		// labels for each potential value. For use in Currently: line.
		if (isset($params['labels'])) : $labels = $params['labels'];
		elseif (is_callable($options)) : $labels = NULL;
		else : $labels = $options;
		endif;

		if (isset($params['input-name'])) : $inputName = $params['input-name'];
		else : $inputName = $globalName;
		endif;

		if (isset($params['default-input-id'])) : $defaultInputId = $params['default-input-id'];
		else : $defaultInputId = NULL;
		endif;

		if (isset($params['default-input-id-no'])) : $defaultInputIdNo = $params['default-input-id-no'];
		elseif (!is_null($defaultInputId)) : $defaultInputIdNo = $defaultInputId.'-no';
		else : $defaultInputIdNo = NULL;
		endif;

		// This allows us to either include the site-default setting as
		// one of the options within the radio box, or else as a simple
		// yes/no toggle that controls whether or not to check another
		// set of inputs.
		if (isset($params['default-input-name'])) : $defaultInputName = $params['default-input-name'];
		else : $defaultInputName = $inputName;
		endif;

		if ($defaultInputName != $inputName) :
			$defaultInputValue = 'yes';
		else :
			$defaultInputValue = (
				isset($params['default-input-value'])
				? $params['default-input-value']
				: 'site-default'
			);
		endif;

		$settingDefaulted = (is_null($setting) or ($settingDefault === $setting));

		if (!is_callable($options)) :
			$checked = array();
			if ($settingDefaulted) :
				$checked[$defaultInputValue] = ' checked="checked"';
			endif;

			foreach ($options as $value => $label) :
				if ($setting == $value) :
					$checked[$value] = ' checked="checked"';
				else :
					$checked[$value] = '';
				endif;
			endforeach;
		endif;

		$defaulted = array();
		if ($defaultInputName != $inputName) :
			$defaulted['yes'] = ($settingDefaulted ? ' checked="checked"' : '');
			$defaulted['no'] = ($settingDefaulted ? '' : ' checked="checked"');
		else :
			$defaulted['yes'] = (isset($checked[$defaultInputValue]) ? $checked[$defaultInputValue] : '');
		endif;

		if (isset($params['defaulted'])) :
			$defaulted['yes'] = ($params['defaulted'] ? ' checked="checked"' : '');
			$defaulted['no'] = ($params['defaulted'] ? '' : ' checked="checked"');
		endif;

		if ($offerSiteWide) :
			?>
			<table class="twofer">
			<tbody>
			<tr><td class="equals first inactive">
			<ul class="options">
			<li><label><input type="radio"
				name="<?php print $defaultInputName; ?>"
				value="<?php print $defaultInputValue; ?>"
				<?php if (!is_null($defaultInputId)) : ?>id="<?php print $defaultInputId; ?>" <?php endif; ?>
				<?php print $defaulted['yes']; ?> />
			Use the site-wide setting</label>
			<span class="current-setting">Currently:
			<strong><?php if (is_callable($labels)) :
				print call_user_func($labels, $globalSetting, $defaulted, $params);
			elseif (is_null($labels)) :
				print $globalSetting;
			else :
				print $labels[$globalSetting];
			endif;  ?></strong> (<a href="<?php print $href; ?>">change</a>)</span></li>
			</ul></td>

			<td class="equals second inactive">
			<?php if ($defaultInputName != $inputName) : ?>
				<ul class="options">
				<li><label><input type="radio"
					name="<?php print $defaultInputName; ?>"
					value="no"
					<?php if (!is_null($defaultInputIdNo)) : ?>id="<?php print $defaultInputIdNo; ?>" <?php endif; ?>
					<?php print $defaulted['no']; ?> />
				<?php _e('Do something different with this feed.'); ?></label>
			<?php endif;
		endif;

		// Let's spit out the controls here.
		if (is_callable($options)) :
			// Method call to print out options list
			call_user_func($options, $setting, $defaulted, $params);
		else :
			?>
			<ul class="options">
			<?php foreach ($options as $value => $label) : ?>
			<li><label><input type="radio" name="<?php print $inputName; ?>"
				value="<?php print $value; ?>"
				<?php print $checked[$value]; ?> />
			<?php print $label; ?></label></li>
			<?php endforeach; ?>
			</ul> <!-- class="options" -->
			<?php
		endif;

		if ($offerSiteWide) :
			if ($defaultInputName != $inputName) :
				// Close the <li> and <ul class="options"> we opened above
			?>
				</li>
				</ul> <!-- class="options" -->
			<?php
			endif;

			// Close off the twofer table that we opened up above.
			?>
			</td></tr>
			</tbody>
			</table>
			<?php
		endif;
	} /* FeedWordPressAdminPage::setting_radio_control () */

	public function save_button ($caption = NULL) {
		if (is_null($caption)) : $caption = __('Save Changes'); endif;
		?>
<p class="submit">
<input class="button-primary" type="submit" name="save" value="<?php print $caption; ?>" />
</p>
		<?php
	}
} /* class FeedWordPressAdminPage */

