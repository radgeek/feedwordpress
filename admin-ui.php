<?php
/**
 * admin-ui.php: This is kind of a junk pile of utility functions mostly created to smooth
 * out interactions to make things show up, or behave correctly, within the WordPress admin
 * settings interface. Major chunks of this code that deal with making it easy for FWP,
 * add-on modules, etc. to create new settings panels have since been hived off into class
 * FeedWordPressAdminPage. Many of the functions that remain here were created to handle
 * compatibility across multiple, sometimes very old, versions of WordPress, many of which
 * are no longer supported anymore. It's likely that some of these functions will be
 * re-evaluated, re-organized, deprecated, or clipped out in the next few versions.
 * -cj 2017-10-27
 */

$dir = dirname(__FILE__);
require_once("${dir}/feedwordpressadminpage.class.php");
require_once("${dir}/feedwordpresssettingsui.class.php");

function fwp_update_set_results_message ($delta, $joiner = ';') {
	$mesg = array();
	if (isset($delta['new'])) : $mesg[] = ' '.$delta['new'].' new posts were syndicated'; endif;
	if (isset($delta['updated']) and ($delta['updated'] != 0)) : $mesg[] = ' '.$delta['updated'].' existing posts were updated'; endif;
	if (isset($delta['stored']) and ($delta['stored'] != 0)) : $mesg[] = ' '.$delta['stored'].' alternate versions of existing posts were stored for reference'; endif;

	if (!is_null($joiner)) :
		$mesg = implode($joiner, $mesg);
	endif;
	return $mesg;
} /* function fwp_update_set_results_message () */

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
	
	$oTax = get_taxonomy($params['taxonomy']);
	$oTaxLabels = get_taxonomy_labels($oTax);
	
	$disabled = (!current_user_can($oTax->cap->assign_terms) ? 'disabled="disabled"' : '');

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
    <p><?php echo $oTaxLabels->add_or_remove_items; ?></p>
	<textarea name="<?php echo $params['textarea_name']; ?>" class="the-tags" id="<?php echo $params['textarea_id']; ?>"><?php echo esc_attr(implode(",", $tags)); ?></textarea></div>

	<?php if ( current_user_can($oTax->cap->assign_terms) ) :?>
	<div class="ajaxtag hide-if-no-js">
		<label class="screen-reader-text" for="<?php echo $params['input_id']; ?>"><?php echo $params['box_title']; ?></label>
		<div class="taghint"><?php echo $oTaxLabels->add_new_item; ?></div>
		<p><input type="text" id="<?php print $params['input_id']; ?>" name="<?php print $params['input_name']; ?>" class="newtag form-input-tip" size="16" autocomplete="off" value="" />
		<input type="button" class="button tagadd" value="<?php esc_attr_e('Add'); ?>" tabindex="3" /></p>
	</div>
	<p class="howto"><?php echo esc_attr( $oTaxLabels->separate_items_with_commas ); ?></p>
	<?php endif; ?>
	</div>

	<div class="tagchecklist"></div>
</div>
<?php if ( current_user_can($oTax->cap->assign_terms) ) : ?>
<p class="hide-if-no-js"><a href="#titlediv" class="tagcloud-link" id="link-<?php echo $tax_name; ?>"><?php echo $oTaxLabels->choose_from_most_used; ?></a></p>
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
	
	$oTax = get_taxonomy($taxonomy);
	$oTaxLabels = get_taxonomy_labels($oTax);

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
    <p style="font-size:smaller;font-style:bold;margin:0">Give <?php print $object; ?> these <?php print $oTaxLabels->name; ?></p>
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

	$users = get_users();
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

function fwp_syndication_manage_page_links_table_rows ($links, $page, $visible = 'Y') {

	$fwp_syndicated_sources_columns = array(__('Name'), __('Feed'), __('Updated'));
	
	$subscribed = ('Y' == strtoupper($visible));
	if ($subscribed or (count($links) > 0)) :
	?>
	<table class="widefat<?php if (!$subscribed) : ?> unsubscribed<?php endif; ?>">
	<thead>
	<tr>
	<th class="check-column" scope="col"><input type="checkbox" /></th>
<?php
		foreach ($fwp_syndicated_sources_columns as $col) :
			print "\t<th scope='col'>${col}</th>\n";
		endforeach;
		print "</tr>\n";
		print "</thead>\n";
		print "\n";
		print "<tbody>\n";

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
				$feed_type = $sLink->get_feed_type();				
				if (is_null($sLink->setting('update/error'))) :
					$errorsSince = '';
					if (!is_null($sLink->setting('link/item count'))) :
						$N = $sLink->setting('link/item count');
						$fileSizeLines[] = sprintf((($N==1) ? __('%d item') : __('%d items')), $N) . ", " . $feed_type;
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
	<td><div><a href="<?php echo esc_html($link->link_rss); ?>"><?php echo esc_html(feedwordpress_display_url($link->link_rss, 32)); ?></a></div></td>
				<?php else: ?>
	<td class="feed-missing"><p><strong>no feed assigned</strong></p></td>
				<?php endif; ?>

	<td><div style="float: right; padding-left: 10px">
	<input type="submit" class="button" name="update_uri[<?php print esc_html($link->link_rss); ?>]" value="<?php _e('Update Now'); ?>" />
	</div>
	<?php
		print $lastUpdated;
		print $fileSize;
		print $errorsSince;
		print $nextUpdate;
	?>
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

