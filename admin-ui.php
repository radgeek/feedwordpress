<?php
class FeedWordPressAdminPage {
	var $context;
	var $updated = false;
	var $mesg = NULL;

	var $link = NULL;
	var $dispatch = NULL;
	var $filename = NULL;
	var $pagenames = array();

	/**
	 * Construct the admin page object.
	 *
	 * @param mixed $link An object of class {@link SyndicatedLink} if created for one feed's settings, NULL if created for global default settings
	 */
	function FeedWordPressAdminPage ($page = 'feedwordpressadmin', $link = NULL) {
		$this->link = $link;

		// Set meta-box context name
		$this->context = $page;
		if ($this->for_feed_settings()) :
			$this->context .= 'forfeed';
		endif;
	} /* FeedWordPressAdminPage constructor */

	function pageslug () {
		$slug = preg_replace('/FeedWordPress(.*)Page/', '$1', get_class($this));
		return strtolower($slug);
	}
	
	function pagename ($context = NULL) {
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

	function accept_POST ($post) {
		if ($this->for_feed_settings() and $this->update_requested_in($post)) :
			$this->update_feed();
		elseif ($this->save_requested_in($post)) : // User mashed Save Changes
			$this->save_settings($post);
		endif;
		do_action($this->dispatch.'_post', $post, $this);		
	}

	function update_feed () {
		global $feedwordpress;

		add_action('feedwordpress_check_feed', 'update_feeds_mention');
		add_action('feedwordpress_check_feed_complete', 'update_feeds_finish', 10, 3);
		
		print '<div class="updated">';
		print "<ul>";
		$uri = $this->link->uri();
		$delta = $feedwordpress->update($uri);
		print "</ul>";

		if (!is_null($delta)) :
			$mesg = array();
			if (isset($delta['new'])) : $mesg[] = ' '.$delta['new'].' new posts were syndicated'; endif;
			if (isset($delta['updated'])) : $mesg[] = ' '.$delta['updated'].' existing posts were updated'; endif;
			echo "<p><strong>Update complete.</strong>".implode(' and', $mesg)."</p>";
			echo "\n"; flush();
		else :
			$uri = esc_html($uri);
			echo "<p><strong>Error:</strong> There was a problem updating <a href=\"$uri\">$uri</a></p>\n";
		endif;
		print "</div>\n";
		remove_action('feedwordpress_check_feed', 'update_feeds_mention');
		remove_action('feedwordpress_check_feed_complete', 'update_feeds_finish', 10, 3);
	}

	function save_settings ($post) {
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

	function for_feed_settings () { return (is_object($this->link) and method_exists($this->link, 'found') and $this->link->found()); }
	function for_default_settings () { return !$this->for_feed_settings(); }

	function setting ($names, $fallback_value = NULL, $params = array()) {
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

	function update_setting ($names, $value, $default = 'default') {
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

	function save_requested_in ($post) {
		return (isset($post['save']) or isset($post['submit']));
	}
	function update_requested_in ($post) {
		return (isset($post['update']) and (strlen($post['update']) > 0));
	}
	
	/*static*/ function submitted_link_id () {
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

	/*static*/ function submitted_link () {
		$link_id = FeedWordPressAdminPage::submitted_link_id();
		if (is_numeric($link_id) and $link_id) :
			$link = new SyndicatedLink($link_id);
		else :
			$link = NULL;
		endif;
		return $link;
	} /* FeedWordPressAdminPage::submitted_link () */

	function stamp_link_id ($field = null) {
		if (is_null($field)) : $field = 'save_link_id'; endif;
		?>
	<input type="hidden" name="<?php print esc_attr($field); ?>" value="<?php print ($this->for_feed_settings() ? $this->link->id : '*'); ?>" />
		<?php
	} /* FeedWordPressAdminPage::stamp_link_id () */

	function these_posts_phrase () {
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
	function meta_box_context () {
		return $this->context;
	} /* FeedWordPressAdminPage::meta_box_context () */
	
	/**
	 * Outputs JavaScript to fix AJAX toggles settings.
	 *
	 * @uses FeedWordPressAdminPage::meta_box_context()
	 */
	 function fix_toggles () {
	 	 FeedWordPressSettingsUI::fix_toggles_js($this->meta_box_context());
	 } /* FeedWordPressAdminPage::fix_toggles() */

	 function ajax_interface_js () {
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

	function admin_page_href ($page, $params = array(), $link = NULL) {
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

	function display_feed_settings_page_links ($params = array()) {
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
	
	function display_feed_select_dropdown() {
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

	function display_sheet_header ($pagename = 'Syndication', $all = false) {
		global $fwp_path;
		?>
		<div class="icon32"><img src="<?php print esc_html(WP_PLUGIN_URL.'/'.$fwp_path.'/feedwordpress.png'); ?>" alt="" /></div>
		<h2><?php print esc_html(__($pagename.($all ? '' : ' Settings'))); ?><?php if ($this->for_feed_settings()) : ?>: <?php echo esc_html($this->link->name(/*from feed=*/ false)); ?><?php endif; ?></h2>
		<?php
	}

	function display_update_notice_if_updated ($pagename = 'Syndication', $mesg = NULL) {
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

	function display_settings_scope_message () {
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

	function form_action ($filename = NULL) {
		global $fwp_path;
		
		if (is_null($filename)) :
			$filename = basename($this->filename);
		endif;
		return $this->admin_page_href($filename);
	} /* FeedWordPressAdminPage::form_action () */

	function update_message () {
		return $this->mesg;
	}

	function display () {
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
			fwp_do_meta_boxes($this->meta_box_context(), $this->meta_box_context(), $this);
		?>
		</div> <!-- class="metabox-holder" -->
		</div> <!-- id="post-body" -->
		<?php $this->close_sheet(); ?>
	<?php
	}

	function open_sheet ($header) {
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
	
	function close_sheet () {
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
	
	function setting_radio_control ($localName, $globalName, $options, $params = array()) {
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
	
	function save_button ($caption = NULL) {
		if (is_null($caption)) : $caption = __('Save Changes'); endif;
		?>
<p class="submit">
<input class="button-primary" type="submit" name="save" value="<?php print $caption; ?>" />
</p>
		<?php
	}
} /* class FeedWordPressAdminPage */

function fwp_authors_single_submit ($link = NULL) {
?>
<div class="submitbox" id="submitlink">
<div id="previewview">
</div>
<div class="inside">
</div>

<p class="submit">
<input type="submit" name="save" value="<?php _e('Save') ?>" />
</p>
</div>
<?php
}

function fwp_option_box_opener ($legend, $id, $class = "stuffbox") {
?>
<div id="<?php print $id; ?>" class="<?php print $class; ?>">
<h3><?php print htmlspecialchars($legend); ?></h3>
<div class="inside">
<?php
}

function fwp_option_box_closer () {
?>
	</div> <!-- class="inside" -->
	</div> <!-- class="stuffbox" -->
<?php
}

function fwp_tags_box ($tags, $object, $params = array()) {
	$params = wp_parse_args($params, array( // Default values
	'taxonomy' => 'post_tag',
	'textarea_name' => NULL,
	'textarea_id' => NULL,
	'input_id' => NULL,
	'input_name' => NULL,
	'id' => NULL,
	'box_title' => __('Post Tags'),
	));
	
	if (!is_array($tags)) : $tags = array(); endif;
	
	$tax_name = $params['taxonomy'];
	$taxonomy = get_taxonomy($params['taxonomy']);
	$disabled = (!current_user_can($taxonomy->cap->assign_terms) ? 'disabled="disabled"' : '');
	
	$desc = "<p style=\"font-size:smaller;font-style:bold;margin:0\">Tag $object as...</p>";

	if (is_null($params['textarea_name'])) :
		$params['textarea_name'] = "tax_input[$tax_name]";
	endif;
	if (is_null($params['textarea_id'])) :
		$params['textarea_id'] = "tax-input-${tax_name}";
	endif;
	if (is_null($params['input_id'])) :
		$params['input_id'] = "new-tag-${tax_name}";
	endif;
	if (is_null($params['input_name'])) :
		$params['input_name'] = "newtag[$tax_name]";
	endif;
	
	if (is_null($params['id'])) :
		$params['id'] = $tax_name;
	endif;
	
	print $desc;
	$helps = __('Separate tags with commas.');
	$box['title'] = __('Tags');
	?>
<div class="tagsdiv" id="<?php echo $params['id']; ?>">
	<div class="jaxtag">
	<div class="nojs-tags hide-if-js">
    <p><?php echo $taxonomy->labels->add_or_remove_items; ?></p>
	<textarea name="<?php echo $params['textarea_name']; ?>" class="the-tags" id="<?php echo $params['textarea_id']; ?>"><?php echo esc_attr(implode(",", $tags)); ?></textarea></div>
	
	<?php if ( current_user_can($taxonomy->cap->assign_terms) ) :?>
	<div class="ajaxtag hide-if-no-js">
		<label class="screen-reader-text" for="<?php echo $params['input_id']; ?>"><?php echo $params['box_title']; ?></label>
		<div class="taghint"><?php echo $taxonomy->labels->add_new_item; ?></div>
		<p><input type="text" id="<?php print $params['input_id']; ?>" name="<?php print $params['input_name']; ?>" class="newtag form-input-tip" size="16" autocomplete="off" value="" />
		<input type="button" class="button tagadd" value="<?php esc_attr_e('Add'); ?>" tabindex="3" /></p>
	</div>
	<p class="howto"><?php echo esc_attr( $taxonomy->labels->separate_items_with_commas ); ?></p>
	<?php endif; ?>
	</div>
	
	<div class="tagchecklist"></div>
</div>
<?php if ( current_user_can($taxonomy->cap->assign_terms) ) : ?>
<p class="hide-if-no-js"><a href="#titlediv" class="tagcloud-link" id="link-<?php echo $tax_name; ?>"><?php echo $taxonomy->labels->choose_from_most_used; ?></a></p>
<?php endif;

}

function fwp_category_box ($checked, $object, $tags = array(), $params = array()) {
	global $wp_db_version;

	if (is_string($params)) :
		$prefix = $params;
		$taxonomy = 'category';
	elseif (is_array($params)) :
		$prefix = (isset($params['prefix']) ? $params['prefix'] : '');
		$taxonomy = (isset($params['taxonomy']) ? $params['taxonomy'] : 'category');
	endif;
	$tax = get_taxonomy($taxonomy);

	if (strlen($prefix) > 0) :
		$idPrefix = $prefix.'-';
		$idSuffix = "-".$prefix;
		$namePrefix = $prefix . '_';
	else :
		$idPrefix = 'feedwordpress-';
		$idSuffix = "-feedwordpress";
		$namePrefix = 'feedwordpress_';
	endif;

?>
<div id="<?php print $idPrefix; ?>taxonomy-<?php print $taxonomy; ?>" class="feedwordpress-category-div">
  <ul id="<?php print $idPrefix; ?><?php print $taxonomy; ?>-tabs" class="category-tabs">
    <li class="ui-tabs-selected tabs"><a href="#<?php print $idPrefix; ?><?php print $taxonomy; ?>-all" tabindex="3"><?php _e( 'All posts' ); ?></a>
    <p style="font-size:smaller;font-style:bold;margin:0">Give <?php print $object; ?> these <?php print $tax->labels->name; ?></p>
    </li>
  </ul>

<div id="<?php print $idPrefix; ?><?php print $taxonomy; ?>-all" class="tabs-panel">
    <input type="hidden" value="0" name="tax_input[<?php print $taxonomy; ?>][]" />
    <ul id="<?php print $idPrefix; ?><?php print $taxonomy; ?>checklist" class="list:<?php print $taxonomy; ?> categorychecklist form-no-clear">
	<?php fwp_category_checklist(NULL, false, $checked, $params) ?>
    </ul>
</div>

<div id="<?php print $idPrefix; ?><?php print $taxonomy; ?>-adder" class="<?php print $taxonomy; ?>-adder wp-hidden-children">
    <h4><a id="<?php print $idPrefix; ?><?php print $taxonomy; ?>-add-toggle" class="category-add-toggle" href="#<?php print $idPrefix; ?><?php print $taxonomy; ?>-add" class="hide-if-no-js" tabindex="3"><?php _e( '+ Add New Category' ); ?></a></h4>
    <p id="<?php print $idPrefix; ?><?php print $taxonomy; ?>-add" class="category-add wp-hidden-child">
	<?php
	$newcat = 'new'.$taxonomy;
	
	?>
    <label class="screen-reader-text" for="<?php print $idPrefix; ?>new<?php print $taxonomy; ?>"><?php _e('Add New Category'); ?></label>
    <input
    	id="<?php print $idPrefix; ?>new<?php print $taxonomy; ?>"
    	class="new<?php print $taxonomy; ?> form-required form-input-tip"
    	aria-required="true"
    	tabindex="3"
    	type="text" name="<?php print $newcat; ?>" 
    	value="<?php _e( 'New category name' ); ?>"
    />
    <label class="screen-reader-text" for="<?php print $idPrefix; ?>new<?php print $taxonomy; ?>-parent"><?php _e('Parent Category:'); ?></label>
    <?php wp_dropdown_categories( array(
    	    	'taxonomy' => $taxonomy,
		'hide_empty' => 0,
		'id' => $idPrefix.'new'.$taxonomy.'-parent',
		'class' => 'new'.$taxonomy.'-parent',
		'name' => $newcat.'_parent',
		'orderby' => 'name',
		'hierarchical' => 1,
		'show_option_none' => __('Parent category'),
		'tab_index' => 3,
    ) ); ?>
	<input type="button" id="<?php print $idPrefix; ?><?php print $taxonomy; ?>-add-sumbit" class="add:<?php print $idPrefix; ?><?php print $taxonomy; ?>checklist:<?php print $idPrefix.$taxonomy; ?>-add add-categorychecklist-category-add button category-add-submit" value="<?php _e( 'Add' ); ?>" tabindex="3" />
	<?php /* wp_nonce_field currently doesn't let us set an id different from name, but we need a non-unique name and a unique id */ ?>
	<input type="hidden" id="_ajax_nonce<?php print esc_html($idSuffix); ?>" name="_ajax_nonce" value="<?php print wp_create_nonce('add-'.$taxonomy); ?>" />
	<input type="hidden" id="_ajax_nonce-add-<?php print $taxonomy; ?><?php print esc_html($idSuffix); ?>" name="_ajax_nonce-add-<?php print $taxonomy; ?>" value="<?php print wp_create_nonce('add-'.$taxonomy); ?>" />
	<span id="<?php print $idPrefix; ?><?php print $taxonomy; ?>-ajax-response" class="<?php print $taxonomy; ?>-ajax-response"></span>
    </p>
</div>

</div>
<?php
}

function update_feeds_mention ($feed) {
	echo "<li>Updating <cite>".$feed['link/name']."</cite> from &lt;<a href=\""
		.$feed['link/uri']."\">".$feed['link/uri']."</a>&gt; ...";
	flush();
}
function update_feeds_finish ($feed, $added, $dt) {
	if (is_wp_error($added)) :
		$mesgs = $added->get_error_messages();
		foreach ($mesgs as $mesg) :
			echo "<br/><strong>Feed error:</strong> <code>$mesg</code>";
		endforeach;
		echo "</li>\n";
	else :
		echo " completed in $dt second".(($dt==1)?'':'s')."</li>\n";
	endif;
	flush();
}

function fwp_author_list () {
	global $wpdb;
	$ret = array();

	$users = get_users_of_blog();
	if (is_array($users)) :
		foreach ($users as $user) :
			$id = (int) $user->ID;
			$ret[$id] = $user->display_name;
			if (strlen(trim($ret[$id])) == 0) :
				$ret[$id] = $user->user_login;
			endif;
		endforeach;
	endif;
	return $ret;
}

class FeedWordPressSettingsUI {
	function is_admin () {
		global $fwp_path;
		
		$admin_page = false; // Innocent until proven guilty
		if (isset($_REQUEST['page'])) :
			$admin_page = (
				is_admin()
				and preg_match("|^{$fwp_path}/|", $_REQUEST['page'])
			);
		endif;
		return $admin_page;
	}
	
	function admin_scripts () {
		global $fwp_path;
	
		wp_enqueue_script('post'); // for magic tag and category boxes
		wp_enqueue_script('admin-forms'); // for checkbox selection
	
		wp_register_script('feedwordpress-elements', WP_PLUGIN_URL.'/'.$fwp_path.'/feedwordpress-elements.js');
		wp_enqueue_script('feedwordpress-elements');
	}

	function admin_styles () {
		?>
		<style type="text/css">
		#feedwordpress-admin-feeds .link-rss-params-remove .x, .feedwordpress-admin .remove-it .x {
			background: url(<?php print admin_url('images/xit.gif') ?>) no-repeat scroll 0 0 transparent;
		}

		#feedwordpress-admin-feeds .link-rss-params-remove:hover .x, .feedwordpress-admin .remove-it:hover .x {
			background: url(<?php print admin_url('images/xit.gif') ?>) no-repeat scroll -10px 0 transparent;
		}

		.fwpfs {
			background-image: url(<?php print admin_url('images/fav.png'); ?>);
			background-repeat: repeat-x;
			background-position: left center;
			background-attachment: scroll;
		}
		.fwpfs.slide-down {
			background-image:url(<?php print admin_url('images/fav-top.png'); ?>);
			background-position:0 top;
			background-repeat:repeat-x;
		}
		
		.update-results {
			max-width: 100%;
			overflow: auto;
		}

		</style>
		<?php
	} /* FeedWordPressSettingsUI::admin_styles () */
	
	/*static*/ function ajax_nonce_fields () {
		if (function_exists('wp_nonce_field')) :
			echo "<form style='display: none' method='get' action=''>\n<p>\n";
			wp_nonce_field( 'closedpostboxes', 'closedpostboxesnonce', false );
			wp_nonce_field( 'meta-box-order', 'meta-box-order-nonce', false );
			echo "</p>\n</form>\n";
		endif;
	} /* FeedWordPressSettingsUI::ajax_nonce_fields () */

	/*static*/ function fix_toggles_js ($context) {
	?>
		<script type="text/javascript">
			jQuery(document).ready( function($) {	
			// In case someone got here first...
			$('.postbox h3, .postbox .handlediv').unbind('click');
			$('.postbox h3 a').unbind('click');
			$('.hide-postbox-tog').unbind('click');
			$('.columns-prefs input[type="radio"]').unbind('click');
			$('.meta-box-sortables').sortable('destroy');
				
			postboxes.add_postbox_toggles('<?php print $context; ?>');
			} );
		</script>
	<?php
	} /* FeedWordPressSettingsUI::fix_toggles_js () */
	
	function magic_input_tip_js ($id) {
			if (!preg_match('/^[.#]/', $id)) :
				$id = '#'.$id;
			endif;
		?>
			<script type="text/javascript">
			jQuery(document).ready( function () {
				var inputBox = jQuery("<?php print $id; ?>");
				var boxEl = inputBox.get(0);
				if (boxEl.value==boxEl.defaultValue) { inputBox.addClass('form-input-tip'); }
				inputBox.focus(function() {
					if ( this.value == this.defaultValue )
						jQuery(this).val( '' ).removeClass( 'form-input-tip' );
				});
				inputBox.blur(function() {
					if ( this.value == '' )
						jQuery(this).val( this.defaultValue ).addClass( 'form-input-tip' );
				});			
			} );
			</script>
		<?php
	} /* FeedWordPressSettingsUI::magic_input_tip_js () */
} /* class FeedWordPressSettingsUI */

function fwp_insert_new_user ($newuser_name) {
	global $wpdb;

	$ret = null;
	if (strlen($newuser_name) > 0) :
		$userdata = array();
		$userdata['ID'] = NULL;
		$userdata['user_login'] = apply_filters('pre_user_login', sanitize_user($newuser_name));
		$userdata['user_nicename'] = apply_filters('pre_user_nicename', sanitize_title($newuser_name));
		$userdata['display_name'] = $newuser_name;
		$userdata['user_pass'] = substr(md5(uniqid(microtime())), 0, 6); // just something random to lock it up
		
		$blahUrl = get_bloginfo('url'); $url = parse_url($blahUrl);
		$userdata['user_email'] = substr(md5(uniqid(microtime())), 0, 6).'@'.$url['host'];
		
		$newuser_id = wp_insert_user($userdata);
		$ret = $newuser_id; // Either a numeric ID or a WP_Error object
	else :
		// TODO: Add some error reporting
	endif;
	return $ret;
} /* fwp_insert_new_user () */

/**
 * fwp_add_meta_box
 *
 * This function is no longer necessary, since no versions of WordPress that FWP
 * still supports lack add_meta_box(). But I've left it in place for the time
 * being for add-on modules that may have used it in setting up their UI.
 */
function fwp_add_meta_box ($id, $title, $callback, $page, $context = 'advanced', $priority = 'default', $callback_args = null) {
	return add_meta_box($id, $title, $callback, $page, $context, $priority, $callback_args);
} /* function fwp_add_meta_box () */

function fwp_do_meta_boxes($page, $context, $object) {
	$ret = do_meta_boxes($page, $context, $object);
		
	// Avoid JavaScript error from WordPress 2.5 bug
?>
	<div style="display: none">
	<div id="tags-input"></div> <!-- avoid JS error from WP 2.5 bug -->
	</div>
<?php
	return $ret;
} /* function fwp_do_meta_boxes() */

function fwp_remove_meta_box($id, $page, $context) {
	return remove_meta_box($id, $page, $context);
} /* function fwp_remove_meta_box() */

function fwp_syndication_manage_page_links_table_rows ($links, $page, $visible = 'Y') {
	
	$subscribed = ('Y' == strtoupper($visible));
	if ($subscribed or (count($links) > 0)) :
	?>
	<table class="widefat<?php if (!$subscribed) : ?> unsubscribed<?php endif; ?>">
	<thead>
	<tr>
	<th class="check-column" scope="col"><input type="checkbox" /></th>
	<th scope="col"><?php _e('Name'); ?></th>
	<th scope="col"><?php _e('Feed'); ?></th>
	<th scope="col"><?php _e('Updated'); ?></th>
	</tr>
	</thead>

	<tbody>
<?php
		$alt_row = true; 
		if (count($links) > 0):
			foreach ($links as $link):
				$trClass = array();

				// Prep: Get last updated timestamp
				$sLink = new SyndicatedLink($link->link_id);
				if (!is_null($sLink->setting('update/last'))) :
					$lastUpdated = 'Last checked '. fwp_time_elapsed($sLink->setting('update/last'));
				else :
					$lastUpdated = __('None yet');
				endif;

				// Prep: get last error timestamp, if any
				$fileSizeLines = array();
				if (is_null($sLink->setting('update/error'))) :
					$errorsSince = '';
					if (!is_null($sLink->setting('link/item count'))) :
						$N = $sLink->setting('link/item count');	
						$fileSizeLines[] = sprintf((($N==1) ? __('%d item') : __('%d items')), $N);
					endif;

					if (!is_null($sLink->setting('link/filesize'))) :
						$fileSizeLines[] = size_format($sLink->setting('link/filesize')). ' total';
					endif;
				else :
					$trClass[] = 'feed-error';

					$theError = unserialize($sLink->setting('update/error'));
					
					$errorsSince = "<div class=\"returning-errors\">"
						."<p><strong>Returning errors</strong> since "
						.fwp_time_elapsed($theError['since'])
						."</p>"
						."<p>Most recent ("
						.fwp_time_elapsed($theError['ts'])
						."):<br/><code>"
						.implode("</code><br/><code>", $theError['object']->get_error_messages())
						."</code></p>"
						."</div>\n";
				endif;

				$nextUpdate = "<div style='max-width: 30.0em; font-size: 0.9em;'><div style='font-style:italic;'>";
				
				$ttl = $sLink->setting('update/ttl');
				if (is_numeric($ttl)) :
					$next = $sLink->setting('update/last') + $sLink->setting('update/fudge') + ((int) $ttl * 60);
					if ('automatically'==$sLink->setting('update/timed')) :
						if ($next < time()) :
							$nextUpdate .= 'Ready and waiting to be updated since ';
						else :
							$nextUpdate .= 'Scheduled for next update ';
						endif;
						$nextUpdate .= fwp_time_elapsed($next);
						if (FEEDWORDPRESS_DEBUG) : $nextUpdate .= " [".(($next-time())/60)." minutes]"; endif;
					else :
						$lastUpdated .= " &middot; Next ";
						if ($next < time()) :
							$lastUpdated .= 'ASAP';
						elseif ($next - time() < 60) :
							$lastUpdated .= fwp_time_elapsed($next);
						elseif ($next - time() < 60*60*24) :
							$lastUpdated .= gmdate('g:ia', $next + (get_option('gmt_offset') * 3600));
						else :
							$lastUpdated .= gmdate('F j', $next + (get_option('gmt_offset') * 3600));
						endif;
						
						$nextUpdate .= "Scheduled to be checked for updates every ".$ttl." minute".(($ttl!=1)?"s":"")."</div><div style='size:0.9em; margin-top: 0.5em'>	This update schedule was requested by the feed provider";
						if ($sLink->setting('update/xml')) :
							$nextUpdate .= " using a standard <code style=\"font-size: inherit; padding: 0; background: transparent\">&lt;".$sLink->setting('update/xml')."&gt;</code> element";
						endif;
						$nextUpdate .= ".";
					endif;
				else:
					$nextUpdate .= "Scheduled for update as soon as possible";
				endif;
				$nextUpdate .= "</div></div>";

				$fileSize = '';
				if (count($fileSizeLines) > 0) :
					$fileSize = '<div>'.implode(" / ", $fileSizeLines)."</div>";
				endif;
				
				unset($sLink);
				
				$alt_row = !$alt_row;
				
				if ($alt_row) :
					$trClass[] = 'alternate';
				endif;
				?>
	<tr<?php echo ((count($trClass) > 0) ? ' class="'.implode(" ", $trClass).'"':''); ?>>
	<th class="check-column" scope="row"><input type="checkbox" name="link_ids[]" value="<?php echo $link->link_id; ?>" /></th>
				<?php
				$caption = (
					(strlen($link->link_rss) > 0)
					? __('Switch Feed')
					: $caption=__('Find Feed')
				);
				?>
	<td>
	<strong><a href="<?php print $page->admin_page_href('feeds-page.php', array(), $link); ?>"><?php print esc_html($link->link_name); ?></a></strong>
	<div class="row-actions"><?php if ($subscribed) :
		$page->display_feed_settings_page_links(array(
			'before' => '<div><strong>Settings &gt;</strong> ',
			'after' => '</div>',
			'subscription' => $link,
		));
	endif; ?>

	<div><strong>Actions &gt;</strong>
	<?php if ($subscribed) : ?>
	<a href="<?php print $page->admin_page_href('syndication.php', array('action' => 'feedfinder'), $link); ?>"><?php echo $caption; ?></a>
	<?php else : ?>
	<a href="<?php print $page->admin_page_href('syndication.php', array('action' => FWP_RESUB_CHECKED), $link); ?>"><?php _e('Re-subscribe'); ?></a>
	<?php endif; ?>
	| <a href="<?php print $page->admin_page_href('syndication.php', array('action' => 'Unsubscribe'), $link); ?>"><?php _e(($subscribed ? 'Unsubscribe' : 'Delete permanently')); ?></a>
	| <a href="<?php print esc_html($link->link_url); ?>"><?php _e('View')?></a></div>
	</div>
	</td>
				<?php if (strlen($link->link_rss) > 0): ?>
	<td><a href="<?php echo esc_html($link->link_rss); ?>"><?php echo esc_html(feedwordpress_display_url($link->link_rss, 32)); ?></a></td>
				<?php else: ?>
	<td class="feed-missing"><p><strong>no feed assigned</strong></p></td>
				<?php endif; ?>

	<td><div style="float: right; padding-left: 10px">
	<input type="submit" class="button" name="update_uri[<?php print esc_html($link->link_rss); ?>]" value="<?php _e('Update Now'); ?>" />
	</div>
	<?php print $lastUpdated; ?>
	<?php print $fileSize; ?>
	<?php print $errorsSince; ?>
	<?php print $nextUpdate; ?>
	</td>
	</tr>
			<?php
			endforeach;
		else :
?>
<tr><td colspan="4"><p>There are no websites currently listed for syndication.</p></td></tr>
<?php
		endif;
?>
</tbody>
</table>
	<?php
	endif;
} /* function fwp_syndication_manage_page_links_table_rows () */

