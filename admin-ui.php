<?php
function fwp_linkedit_single_submit ($status = NULL) {
	if (fwp_test_wp_version(FWP_SCHEMA_25, FWP_SCHEMA_27)) :
?>
<div class="submitbox" id="submitlink">
<div id="previewview"></div>
<div class="inside">
<?php if (!is_null($status)) : ?>
	<p><strong>Publication</strong></p>
	<p>When a new post is syndicated from this feed...</p>
	<select name="feed_post_status">
	<option value="site-default" <?php echo $status['post']['site-default']; ?>> Use
	Syndication Options setting</option>
	<option value="publish" <?php echo $status['post']['publish']; ?>>Publish it immediately</option>
	
	<?php if (SyndicatedPost::use_api('post_status_pending')) : ?>
	<option value="pending" <?php echo $status['post']['pending']; ?>>Hold it Pending Review</option>
	<?php endif; ?>
	
	<option value="draft" <?php echo $status['post']['draft']; ?>>Save it as a draft</option>
	<option value="private" <?php echo $status['post']['private']; ?>>Keep it private</option>
	</select>
<?php endif; ?>
</div>

<p class="submit">
<input type="submit" name="submit" value="<?php _e('Save') ?>" />
</p>
</div>
<?php
	endif;
}

function fwp_linkedit_periodic_submit ($caption = NULL) {
	if (!fwp_test_wp_version(FWP_SCHEMA_25)) :
		if (is_null($caption)) : $caption = __('Save Changes &raquo;'); endif;
?>
<p class="submit">
<input type="submit" name="submit" value="<?php print $caption; ?>" />
</p>
<?php
	endif;
}

function fwp_linkedit_single_submit_closer ($caption = NULL) {
	if (fwp_test_wp_version(FWP_SCHEMA_27)) :
		if (is_null($caption)) : $caption = __('Save Changes'); endif;
?>
<p class="submit">
<input class="button-primary" type="submit" name="submit" value="<?php print $caption; ?>" />
</p>
<?php
	endif;
}

function fwp_authors_single_submit ($link = NULL) {
	global $wp_db_version;
	
	if (fwp_test_wp_version(FWP_SCHEMA_25)) :
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
	endif;
}

function fwp_option_box_opener ($legend, $id, $class = "stuffbox") {
	global $wp_db_version;
	if (isset($wp_db_version) and $wp_db_version >= FWP_SCHEMA_25) :
?>
<div id="<?php print $id; ?>" class="<?php print $class; ?>">
<h3><?php print htmlspecialchars($legend); ?></h3>
<div class="inside">
<?php
	else :
?>
<fieldset class="options"><legend><?php print htmlspecialchars($legend); ?></legend>
<?php
	endif;
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
</fieldset>
<?php
	endif;
}

function fwp_tags_box ($tags) {
	if (!is_array($tags)) : $tags = array(); endif;
?>
<div id="tagsdiv" class="postbox">
	<h3><?php _e('Tags') ?></h3>
	<p style="font-size:smaller;font-style:bold;margin:0">Place <?php print $object; ?> under...</p>
	<div class="inside">
	<p id="jaxtag"><input type="text" name="tags_input" class="tags-input" id="tags-input" size="40" tabindex="3" value="<?php echo implode(",", $tags); ?>" /></p>
	<div id="tagchecklist"></div>
 	</div>
</div>
<?php
}

function fwp_category_box ($checked, $object, $tags = array()) {
	global $wp_db_version;

	if (fwp_test_wp_version(FWP_SCHEMA_25)) : // WordPress 2.5.x
?>
<div id="category-adder" class="wp-hidden-children">
    <h4><a id="category-add-toggle" href="#category-add" class="hide-if-no-js" tabindex="3"><?php _e( '+ Add New Category' ); ?></a></h4>
    <p id="category-add" class="wp-hidden-child">
	<input type="text" name="newcat" id="newcat" class="form-required form-input-tip" value="<?php _e( 'New category name' ); ?>" tabindex="3" />
	<?php wp_dropdown_categories( array( 'hide_empty' => 0, 'name' => 'newcat_parent', 'orderby' => 'name', 'hierarchical' => 1, 'show_option_none' => __('Parent category'), 'tab_index' => 3 ) ); ?>
	<input type="button" id="category-add-sumbit" class="add:categorychecklist:category-add button" value="<?php _e( 'Add' ); ?>" tabindex="3" />
	<?php wp_nonce_field( 'add-category', '_ajax_nonce', false ); ?>
	<span id="category-ajax-response"></span>
    </p>
</div>

<ul id="category-tabs">
	<li class="ui-tabs-selected"><a href="#categories-all" tabindex="3"><?php _e( 'All posts' ); ?></a>
        <p style="font-size:smaller;font-style:bold;margin:0">Give <?php print $object; ?> these categories</p>
</li>
</ul>

<div id="categories-all" class="ui-tabs-panel">
    <ul id="categorychecklist" class="list:category categorychecklist form-no-clear">
	<?php fwp_category_checklist(NULL, false, $checked) ?>
    </ul>
</div>
<?php
	elseif (fwp_test_wp_version(FWP_SCHEMA_20)) : // WordPress 2.x
?>
		<div id="moremeta">
		<div id="grabit" class="dbx-group">
			<fieldset id="categorydiv" class="dbx-box">
			<h3 class="dbx-handle"><?php _e('Categories') ?></h3>
			<div class="dbx-content">
			<p style="font-size:smaller;font-style:bold;margin:0">Place <?php print $object; ?> under...</p>
			<p id="jaxcat"></p>
			<div id="categorychecklist"><?php fwp_category_checklist(NULL, false, $checked); ?></div>
			</div>
			</fieldset>
		</div>
		</div>
<?php
	else : // WordPress 1.5
?>
		<fieldset style="width: 60%;">
		<legend><?php _e('Categories') ?></legend>
		<p style="font-size:smaller;font-style:bold;margin:0">Place <?php print $object; ?> under...</p>
		<div style="height: 10em; overflow: scroll;"><?php fwp_category_checklist(NULL, false, $checked); ?></div>
		</fieldset>
<?php
	endif;
}

function update_feeds_mention ($feed) {
	echo "<li>Updating <cite>".$feed['link/name']."</cite> from &lt;<a href=\""
		.$feed['link/uri']."\">".$feed['link/uri']."</a>&gt; ...";
	flush();
}
function update_feeds_finish ($feed, $added, $dt) {
	echo " completed in $dt second".(($dt==1)?'':'s')."</li>\n";
}

function fwp_author_list () {
	global $wpdb;
	$ret = array();

	// display_name introduced in WP 2.0
	if (fwp_test_wp_version(FWP_SCHEMA_20)) :
		$name_column = 'display_name';
	else :
		$name_column = 'user_nickname';
	endif;

	$users = $wpdb->get_results("SELECT * FROM $wpdb->users ORDER BY {$name_column}");
	if (is_array($users)) :
		foreach ($users as $user) :
			$id = (int) $user->ID;
			$ret[$id] = $user->{$name_column};
			if (strlen(trim($ret[$id])) == 0) :
				$ret[$id] = $user->user_login;
			endif;
		endforeach;
	endif;
	return $ret;
}

