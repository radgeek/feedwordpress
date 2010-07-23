<?php
class FeedWordPressAdminPage {
	var $context;
	var $updated = false;
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

	function pagename ($context = NULL) {
		if (is_null($context)) :
			$context = 'default';
		endif;

		if (isset($this->pagenames[$context])) :
			$name = $this->pagenames[$context];
		elseif (isset($tis->pagenames['default'])) :
			$name = $this->pagenames['default'];
		else :
			$name = preg_replace('/FeedWordPress(.*)Page/', '$1', get_class($this));
		endif;
		return __($name);
	} /* FeedWordPressAdminPage::pagename () */

	function accept_POST ($post) {
		if ($this->save_requested_in($post)) : // User mashed Save Changes
			$this->save_settings($post);
		endif;
		do_action($this->dispatch.'_post', $post, $this);		
	}

	function save_settings ($post) {
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
		do_action($this->dispatch.'_save', $post, $this);
	} /* FeedWordPressAdminPage::save_settings () */

	function for_feed_settings () { return (is_object($this->link) and method_exists($this->link, 'found') and $this->link->found()); }
	function for_default_settings () { return !$this->for_feed_settings(); }
	function save_requested_in ($post) {
		return (isset($post['save']) or isset($post['submit']));
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
				$link_id = $_REQUEST['save_link_id'];
			endif;
		endforeach;
		
		if (is_null($link_id) and isset($_REQUEST['link_id'])) :
			$link_id = $_REQUEST['link_id'];
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
	<input type="hidden" name="<?php print esc_html($field); ?>" value="<?php print ($this->for_feed_settings() ? $this->link->id : '*'); ?>" />
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

	function display_feed_select_dropdown() {
		$links = FeedWordPress::syndicated_links();
		?>
		<p id="post-search">
		<select name="link_id" class="fwpfs" style="max-width: 20.0em;">
		  <option value="*"<?php if ($this->for_default_settings()) : ?> selected="selected"<?php endif; ?>>- defaults for all feeds -</option>
		<?php if ($links) : foreach ($links as $ddlink) : ?>
		  <option value="<?php print (int) $ddlink->link_id; ?>"<?php if (!is_null($this->link) and ($this->link->id==$ddlink->link_id)) : ?> selected="selected"<?php endif; ?>><?php print esc_html($ddlink->link_name); ?></option>
		<?php endforeach; endif; ?>
		</select>
		<input class="button" type="submit" name="go" value="<?php _e('Go') ?> &raquo;" />
		</p>
		<?php
	} /* FeedWordPressAdminPage::display_feed_select_dropdown() */

	function display_sheet_header ($pagename = 'Syndication', $all = false) {
		?>
		<div class="icon32"><img src="<?php print esc_html(WP_PLUGIN_URL.'/'.$GLOBALS['fwp_path'].'/feedwordpress.png'); ?>" alt="" /></div>
		<h2><?php print esc_html(__($pagename.($all ? '' : ' Settings'))); ?><?php if ($this->for_feed_settings()) : ?>: <?php echo esc_html($this->link->name()); ?><?php endif; ?></h2>
		<?php
	}

	function display_update_notice_if_updated ($pagename = 'Syndication', $mesg = NULL) {
		if ($this->updated) :
			if ($this->updated === true) :
				$mesg = $pagename . ' settings updated.';
			else :
				$mesg = $this->updated;
			endif;
		endif;
		
		if (!is_null($mesg)) :
			?>
			<div class="updated">
			<p><?php print esc_html($mesg); ?></p>
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

	function form_action () {
		global $fwp_path;
		return "admin.php?page=${fwp_path}/".basename($this->filename);
	} /* FeedWordPressAdminPage::form_action () */

	function update_message () {
		return NULL;
	}

	function display () {
		if (FeedWordPress::needs_upgrade()) :
			fwp_upgrade_page();
			return;
		endif;

		FeedWordPressCompatibility::validate_http_request(/*action=*/ $this->dispatch, /*capability=*/ 'manage_links');

		////////////////////////////////////////////////
		// Process POST request, if any ////////////////
		////////////////////////////////////////////////
		if (strtoupper($_SERVER['REQUEST_METHOD'])=='POST') :
			$this->accept_POST($GLOBALS['fwp_post']);
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
	
			fwp_add_meta_box(
				/*id=*/ $id,
				/*title=*/ $title,
				/*callback=*/ array(&$this, $method),
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
		if (function_exists('add_meta_box')) :
			add_action(
				FeedWordPressCompatibility::bottom_script_hook($this->filename),
				/*callback=*/ array($this, 'fix_toggles'),
				/*priority=*/ 10000
			);
			FeedWordPressSettingsUI::ajax_nonce_fields();
		endif;

		?>
		<div class="wrap" style="position:relative">
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
			$this->display_feed_select_dropdown();
			$this->display_settings_scope_message();
		endif;

		if (function_exists('do_meta_boxes')) :
			?>
			<div id="poststuff">
			<?php
		else :
			?>
			</div> <!-- class="wrap" -->
			<?php
		endif;

		if (!is_null($this->dispatch)) :
			$this->save_button();
		endif;
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
	}
	
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
	global $wp_db_version;
	if (isset($wp_db_version) and $wp_db_version >= FWP_SCHEMA_25) :
?>
	</div> <!-- class="inside" -->
	</div> <!-- class="stuffbox" -->
<?php
	else :
?>
	</div> <!-- class="wrap" -->
<?php
	endif;
}

function fwp_tags_box ($tags, $object) {
	if (!is_array($tags)) : $tags = array(); endif;
	
	$desc = "<p style=\"font-size:smaller;font-style:bold;margin:0\">Tag $object as...</p>";

	if (FeedWordPressCompatibility::test_version(FWP_SCHEMA_29)) : // WordPress 2.9+
		print $desc;
		$tax_name = 'post_tag';
	        $helps = __('Separate tags with commas.');
	        $box['title'] = __('Tags');
	?>
		<div class="tagsdiv" id="<?php echo $tax_name; ?>">
	        <div class="jaxtag">
	        <div class="nojs-tags hide-if-js">
	        <p><?php _e('Add or remove tags'); ?></p>
	        <textarea name="<?php echo "tax_input[$tax_name]"; ?>" class="the-tags" id="tax-input[<?php echo $tax_name; ?>]"><?php echo esc_attr(implode(",", $tags)); ?></textarea></div>
	
	        <div class="ajaxtag hide-if-no-js">
	                <label class="screen-reader-text" for="new-tag-<?php echo $tax_name; ?>"><?php echo $box['title']; ?></label>
	                <div class="taghint"><?php _e('Add new tag'); ?></div>
	                <input type="text" id="new-tag-<?php echo $tax_name; ?>" name="newtag[<?php echo $tax_name; ?>]" class="newtag form-input-tip" size="16" autocomplete="off" value="" />
	                <input type="button" class="button tagadd" value="<?php esc_attr_e('Add'); ?>" tabindex="3" />
	        </div></div>
	        <p class="howto"><?php echo $helps; ?></p>
	        <div class="tagchecklist"></div>
	        </div>
	        <p class="hide-if-no-js"><a href="#titlediv" class="tagcloud-link" id="link-<?php echo $tax_name; ?>"><?php printf( __('Choose from the most used tags in %s'), $box['title'] ); ?></a></p>
<?php
	elseif (FeedWordPressCompatibility::test_version(FWP_SCHEMA_28)) : // WordPress 2.8+
?>
		<?php print $desc; ?>
		<div class="tagsdiv" id="post_tag">
		<div class="jaxtag">
		 <div class="nojs-tags hide-if-js">
		  <p><?php _e('Add or remove tags'); ?></p>
		  <textarea name="tax_input[post_tag]" class="the-tags" id="tax-input[post_tag]"><?php echo implode(",", $tags); ?></textarea>
		 </div>
		
		 <span class="ajaxtag hide-if-no-js">
			<label class="screen-reader-text" for="new-tag-post_tag"><?php _e('Tags'); ?></label>
			<input type="text" id="new-tag-post_tag" name="newtag[post_tag]" class="newtag form-input-tip" size="16" autocomplete="off" value="<?php esc_attr_e('Add new tag'); ?>" />
			<input type="button" class="button tagadd" value="<?php esc_attr_e('Add'); ?>" />
		 </span>
		</div>
		<p class="howto"><?php echo __('Separate tags with commas.'); ?></p>
		<div class="tagchecklist"></div>
		</div>
		<p class="tagcloud-link hide-if-no-js"><a href="#titlediv" class="tagcloud-link" id="link-post_tag"><?php printf( __('Choose from the most used tags in %s'), 'Post Tags'); ?></a></p>
		</div>
		</div>
<?php
	else :
?>
		<?php print $desc; ?>
		<p id="jaxtag"><input type="text" name="tags_input" class="tags-input" id="tags-input" size="40" tabindex="3" value="<?php echo implode(",", $tags); ?>" /></p>
		<div id="tagchecklist"></div>
		</div>
		</div>
<?php
	endif;
}

function fwp_category_box ($checked, $object, $tags = array(), $prefix = '') {
	global $wp_db_version;

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
<div id="<?php print $idPrefix; ?>taxonomy-category" class="feedwordpress-category-div">
  <ul id="<?php print $idPrefix; ?>category-tabs" class="category-tabs">
    <li class="ui-tabs-selected tabs"><a href="#<?php print $idPrefix; ?>categories-all" tabindex="3"><?php _e( 'All posts' ); ?></a>
    <p style="font-size:smaller;font-style:bold;margin:0">Give <?php print $object; ?> these categories</p>
    </li>
  </ul>

<div id="<?php print $idPrefix; ?>categories-all" class="tabs-panel">
    <ul id="<?php print $idPrefix; ?>categorychecklist" class="list:category categorychecklist form-no-clear">
	<?php fwp_category_checklist(NULL, false, $checked, $prefix) ?>
    </ul>
</div>

<div id="<?php print $idPrefix; ?>category-adder" class="category-adder wp-hidden-children">
    <h4><a id="<?php print $idPrefix; ?>category-add-toggle" class="category-add-toggle" href="#<?php print $idPrefix; ?>category-add" class="hide-if-no-js" tabindex="3"><?php _e( '+ Add New Category' ); ?></a></h4>
    <p id="<?php print $idPrefix; ?>category-add" class="wp-hidden-child">
	<?php
	if (FeedWordPressCompatibility::test_version(FWP_SCHEMA_30)) :
		$newcat = 'newcategory'; // Well, thank God they added "egory" before WP 3.0 came out.
	else :
		$newcat = 'newcat';
	endif;
	?>

    <input type="text" name="<?php print $newcat; ?>" id="<?php print $idPrefix; ?>newcategory" class="newcategory form-required form-input-tip" value="<?php _e( 'New category name' ); ?>" tabindex="3" />
    <label class="screen-reader-text" for="<?php print $idPrefix; ?>newcategory-parent"><?php _e('Parent Category:'); ?></label>
    <?php wp_dropdown_categories( array( 
		'hide_empty' => 0,
		'id' => $idPrefix.'newcategory-parent',
		'class' => 'newcategory-parent',
		'name' => $newcat.'_parent',
		'orderby' => 'name',
		'hierarchical' => 1,
		'show_option_none' => __('Parent category'),
		'tab_index' => 3,
    ) ); ?>
	<input type="button" id="<?php print $idPrefix; ?>category-add-sumbit" class="add:<?php print $idPrefix; ?>categorychecklist:category-add add-categorychecklist-category-add button" value="<?php _e( 'Add' ); ?>" tabindex="3" />
	<?php /* wp_nonce_field currently doesn't let us set an id different from name, but we need a non-unique name and a unique id */ ?>
	<input type="hidden" id="_ajax_nonce<?php print esc_html($idSuffix); ?>" name="_ajax_nonce" value="<?php print wp_create_nonce('add-category'); ?>" />
	<input type="hidden" id="_ajax_nonce-add-category<?php print esc_html($idSuffix); ?>" name="_ajax_nonce-add-category" value="<?php print wp_create_nonce('add-category'); ?>" />
	<span id="<?php print $idPrefix; ?>category-ajax-response" class="category-ajax-response"></span>
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
}

function fwp_author_list () {
	global $wpdb;
	$ret = array();

	$users = $wpdb->get_results("SELECT * FROM $wpdb->users ORDER BY display_name");
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
		if (!FeedWordPressCompatibility::test_version(FWP_SCHEMA_29)) : // < 2.9
			wp_enqueue_script('thickbox'); // for fold-up boxes
		endif;
		wp_enqueue_script('admin-forms'); // for checkbox selection
	
		wp_register_script('feedwordpress-elements', WP_PLUGIN_URL.'/'.$fwp_path.'/feedwordpress-elements.js');
		wp_enqueue_script('feedwordpress-elements');
	}
	
	function instead_of_posts_box ($link_id = null) {
		if (!is_null($link_id)) :
			$from_this_feed = 'from this feed';
			$by_default = '';
			$id_param = "&amp;link_id=".$link_id;
		else :
			$from_this_feed = 'from syndicated feeds';
			$by_default = " by default";
			$id_param = "";
		endif;
?>
<p>Use the <a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/posts-page.php<?php print $id_param; ?>"><?php _e('Posts & Links'); ?></a>
settings page to set up how new posts <?php print $from_this_feed; ?> will be published<?php $by_default; ?>, whether they will accept
comments and pings, any custom fields that should be set on each post, etc.</p>
<?php
	} /* FeedWordPressSettingsUI::instead_of_posts_box () */
	
	function instead_of_authors_box ($link_id = null) {
		if (!is_null($link_id)) :
			$from_this_feed = 'from this feed';
			$by_default = '';
			$id_param = "&amp;link_id=".$link_id;
		else :
			$from_this_feed = 'from syndicated feeds';
			$by_default = " by default";
			$id_param = "";
		endif;

?>
<p>Use the <a
href="admin.php?page=<?php print $GLOBALS['fwp_path']
?>/authors-page.php<?php print $id_param; ?>"><?php _e('Authors');
?></a> settings page to set up how new posts
<?php print $from_this_feed; ?> will be assigned to
authors.</p>
<?php 
	} /* FeedWordPressSettingsUI::instead_of_authors_box () */
	
	function instead_of_categories_box ($link_id = null) {
		if (!is_null($link_id)) :
			$from_this_feed = 'from this feed';
			$by_default = '';
			$id_param = "&amp;link_id=".$link_id;
		else :
			$from_this_feed = 'from syndicated feeds';
			$by_default = " by default";
			$id_param = "";
		endif;
		
?>
<p>Use the <a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/categories-page.php<?php print $id_param; ?>"><?php _e('Categories'.FEEDWORDPRESS_AND_TAGS); ?></a>
settings page to set up how new posts <?php print $from_this_feed; ?> are assigned categories <?php if (FeedWordPressCompatibility::post_tags()) : ?>or tags<?php endif; ?><?php print $by_default; ?>.</p>
<?php
	} /* FeedWordPressSettingsUI::instead_of_categories_box () */

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
			<?php if (FeedWordPressCompatibility::test_version(FWP_SCHEMA_29)) : ?>
				if ( $('#post_tag').length ) {
					tagBox.init();
				}
			<?php endif; ?>
			
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
		?>
			<script type="text/javascript">
			jQuery(document).ready( function () {
				var inputBox = jQuery("#<?php print $id; ?>");
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

function fwp_add_meta_box ($id, $title, $callback, $page, $context = 'advanced', $priority = 'default', $callback_args = null) {
	if (function_exists('add_meta_box'))  :
		return add_meta_box($id, $title, $callback, $page, $context, $priority, $callback_args);
	else :
		/* Re-used as per terms of the GPL from add_meta_box() in WordPress 2.8.1 wp-admin/includes/template.php. */
		global $wp_meta_boxes;
	
		if ( !isset($wp_meta_boxes) )
			$wp_meta_boxes = array();
		if ( !isset($wp_meta_boxes[$page]) )
			$wp_meta_boxes[$page] = array();
		if ( !isset($wp_meta_boxes[$page][$context]) )
			$wp_meta_boxes[$page][$context] = array();
	
		foreach ( array_keys($wp_meta_boxes[$page]) as $a_context ) {
		foreach ( array('high', 'core', 'default', 'low') as $a_priority ) {
			if ( !isset($wp_meta_boxes[$page][$a_context][$a_priority][$id]) )
				continue;
	
			// If a core box was previously added or removed by a plugin, don't add.
			if ( 'core' == $priority ) {
				// If core box previously deleted, don't add
				if ( false === $wp_meta_boxes[$page][$a_context][$a_priority][$id] )
					return;
				// If box was added with default priority, give it core priority to maintain sort order
				if ( 'default' == $a_priority ) {
					$wp_meta_boxes[$page][$a_context]['core'][$id] = $wp_meta_boxes[$page][$a_context]['default'][$id];
					unset($wp_meta_boxes[$page][$a_context]['default'][$id]);
				}
				return;
			}
			// If no priority given and id already present, use existing priority
			if ( empty($priority) ) {
				$priority = $a_priority;
			// else if we're adding to the sorted priortiy, we don't know the title or callback. Glab them from the previously added context/priority.
			} elseif ( 'sorted' == $priority ) {
				$title = $wp_meta_boxes[$page][$a_context][$a_priority][$id]['title'];
				$callback = $wp_meta_boxes[$page][$a_context][$a_priority][$id]['callback'];
				$callback_args = $wp_meta_boxes[$page][$a_context][$a_priority][$id]['args'];
			}
			// An id can be in only one priority and one context
			if ( $priority != $a_priority || $context != $a_context )
				unset($wp_meta_boxes[$page][$a_context][$a_priority][$id]);
		}
		}
	
		if ( empty($priority) )
			$priority = 'low';
	
		if ( !isset($wp_meta_boxes[$page][$context][$priority]) )
			$wp_meta_boxes[$page][$context][$priority] = array();
	
		$wp_meta_boxes[$page][$context][$priority][$id] = array('id' => $id, 'title' => $title, 'callback' => $callback, 'args' => $callback_args);
	endif;
} /* function fwp_add_meta_box () */

function fwp_do_meta_boxes($page, $context, $object) {
	if (function_exists('do_meta_boxes')) :
		$ret = do_meta_boxes($page, $context, $object);
		
		// Avoid JavaScript error from WordPress 2.5 bug
?>
	<div style="display: none">
	<div id="tags-input"></div> <!-- avoid JS error from WP 2.5 bug -->
	</div>
<?php
		return $ret;
	else :
		/* Derived as per terms of the GPL from do_meta_boxes() in WordPress 2.8.1 wp-admin/includes/template.php. */
		global $wp_meta_boxes;
		static $already_sorted = false;
		
		//do_action('do_meta_boxes', $page, $context, $object);
	
		echo "<div id='$context-sortables' class='meta-box-sortables'>\n";
	
		$i = 0;
		do {
			if ( !isset($wp_meta_boxes) || !isset($wp_meta_boxes[$page]) || !isset($wp_meta_boxes[$page][$context]) )
				break;
	
			foreach ( array('high', 'sorted', 'core', 'default', 'low') as $priority ) {
				if ( isset($wp_meta_boxes[$page][$context][$priority]) ) {
					foreach ( (array) $wp_meta_boxes[$page][$context][$priority] as $box ) {
						if ( false == $box || ! $box['title'] )
							continue;
						$i++;
						fwp_option_box_opener($box['title'], $box['id'], 'postbox' /*. postbox_classes($box['id'], $page)*/);
						call_user_func($box['callback'], $object, $box);
						fwp_option_box_closer();
						
						if (is_object($object) and method_exists($object, 'interstitial')) :
							$object->interstitial();
						endif;
					}
				}
			}
		} while(0);
	
		echo "</div>";
	
		return $i;	
	endif;
} /* function fwp_do_meta_boxes() */

function fwp_remove_meta_box($id, $page, $context) {
	if (function_exists('remove_meta_box')) :
		return remove_meta_box($id, $page, $context);
	else :
		/* Re-used as per terms of the GPL from remove_meta_box() in WordPress 2.8.1 wp-admin/includes/template.php */
		global $wp_meta_boxes;
	
		if ( !isset($wp_meta_boxes) )
			$wp_meta_boxes = array();
		if ( !isset($wp_meta_boxes[$page]) )
			$wp_meta_boxes[$page] = array();
		if ( !isset($wp_meta_boxes[$page][$context]) )
			$wp_meta_boxes[$page][$context] = array();
	
		foreach ( array('high', 'core', 'default', 'low') as $priority )
			$wp_meta_boxes[$page][$context][$priority][$id] = false;
	endif;
} /* function fwp_remove_meta_box() */

function fwp_syndication_manage_page_links_table_rows ($links, $visible = 'Y') {
	global $fwp_path;
	
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
					$lastUpdated = fwp_time_elapsed($sLink->setting('update/last'));
				else :
					$lastUpdated = __('None yet');
				endif;

				// Prep: get last error timestamp, if any
				if (is_null($sLink->setting('update/error'))) :
					$errorsSince = '';
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

				$nextUpdate = "<div style='font-style:italic;size:0.9em'>Ready for next update ";
				if (isset($sLink->settings['update/ttl']) and is_numeric($sLink->settings['update/ttl'])) :
					if (isset($sLink->settings['update/timed']) and $sLink->settings['update/timed']=='automatically') :
						$next = $sLink->settings['update/last'] + ((int) $sLink->settings['update/ttl'] * 60);
						$nextUpdate .= fwp_time_elapsed($next);
						if (FEEDWORDPRESS_DEBUG) : $nextUpdate .= " [".(($next-time())/60)." minutes]"; endif;
					else :
						$nextUpdate .= "every ".$sLink->settings['update/ttl']." minute".(($sLink->settings['update/ttl']!=1)?"s":"");
					endif;
				else:
					$nextUpdate .= "as soon as possible";
				endif;
				$nextUpdate .= "</div>";

				unset($sLink);
				
				$alt_row = !$alt_row;
				
				if ($alt_row) :
					$trClass[] = 'alternate';
				endif;
				?>
	<tr<?php echo ((count($trClass) > 0) ? ' class="'.implode(" ", $trClass).'"':''); ?>>
	<th class="check-column" scope="row"><input type="checkbox" name="link_ids[]" value="<?php echo $link->link_id; ?>" /></th>
				<?php
				$hrefPrefix = "admin.php?link_id={$link->link_id}&amp;page=${fwp_path}/";
				$caption = (
					(strlen($link->link_rss) > 0)
					? __('Switch Feed')
					: $caption=__('Find Feed')
				);
				?>
	<td>
	<strong><a href="<?php print $hrefPrefix; ?>feeds-page.php"><?php print esc_html($link->link_name); ?></a></strong>
	<div class="row-actions"><?php if ($subscribed) : ?>
	<div><strong>Settings &gt;</strong>
	<a href="<?php print $hrefPrefix; ?>feeds-page.php"><?php _e('Feed'); ?></a>
	| <a href="<?php print $hrefPrefix; ?>posts-page.php"><?php _e('Posts'); ?></a>
	| <a href="<?php print $hrefPrefix; ?>authors-page.php"><?php _e('Authors'); ?></a>
	| <a href="<?php print $hrefPrefix; ?>categories-page.php"><?php print htmlspecialchars(__('Categories'.FEEDWORDPRESS_AND_TAGS)); ?></a></div>
	<?php endif; ?>

	<div><strong>Actions &gt;</strong>
	<?php if ($subscribed) : ?>
	<a href="<?php print $hrefPrefix; ?>syndication.php&amp;action=feedfinder"><?php echo $caption; ?></a>
	<?php else : ?>
	<a href="<?php print $hrefPrefix; ?>syndication.php&amp;action=<?php print FWP_RESUB_CHECKED; ?>"><?php _e('Re-subscribe'); ?></a>
	<?php endif; ?>
	| <a href="<?php print $hrefPrefix; ?>syndication.php&amp;action=Unsubscribe"><?php _e(($subscribed ? 'Unsubscribe' : 'Delete permanently')); ?></a>
	| <a href="<?php print esc_html($link->link_url); ?>"><?php _e('View')?></a></div>
	</div>
	</td>
				<?php if (strlen($link->link_rss) > 0): ?>
	<td><a href="<?php echo esc_html($link->link_rss); ?>"><?php echo esc_html(feedwordpress_display_url($link->link_rss, 32)); ?></a></td>
				<?php else: ?>
	<td class="feed-missing"><p><strong>no feed assigned</strong></p></td>
				<?php endif; ?>

	<td><?php print $lastUpdated; ?>
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

function fwp_syndication_manage_page_links_subsubsub ($sources, $showInactive) {
	global $fwp_path;
	$hrefPrefix = "admin.php?page=${fwp_path}/".basename(__FILE__);
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

