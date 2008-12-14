<?php
require_once(dirname(__FILE__) . '/admin-ui.php');

function fwp_authors_page () {
	global $wpdb, $wp_db_version;

	check_admin_referer(); // Make sure we arrived here from the Dashboard
	
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

	$mesg = null;
	if (!current_user_can('manage_links')) :
		die (__("Cheatin' uh ?"));
	else :
		if (isset($GLOBALS['fwp_post']['fix_mismatch'])) :
			if ('newuser'==$GLOBALS['fwp_post']['fix_mismatch_to']) :
				$newuser_name = trim($GLOBALS['fwp_post']['fix_mismatch_to_newuser']);
				if (strlen($newuser_name) > 0) :
					$userdata = array();
					$userdata['ID'] = NULL;
					
					$userdata['user_login'] = sanitize_user($newuser_name);
					$userdata['user_login'] = apply_filters('pre_user_login', $userdata['user_login']);
					
					$userdata['user_nicename'] = sanitize_title($newuser_name);
					$userdata['user_nicename'] = apply_filters('pre_user_nicename', $userdata['user_nicename']);
					
					$userdata['display_name'] = $wpdb->escape($newuser_name);

					$newuser_id = wp_insert_user($userdata);
					if (is_numeric($newuser_id)) :
						$fix_mismatch_to_id = $newuser_id;
					else :
						// TODO: Add some error detection and reporting
					endif;
				else :
					// TODO: Add some error reporting
				endif;			
			else :
				$fix_mismatch_to_id = $GLOBALS['fwp_post']['fix_mismatch_to'];
			endif;
			$fix_mismatch_from_id = (int) $GLOBALS['fwp_post']['fix_mismatch_from'];
			if (is_numeric($fix_mismatch_from_id)) :
				// Make a list of all the items by this author syndicated from this feed...
				$post_ids = $wpdb->get_col("
				SELECT {$wpdb->posts}.id
				FROM {$wpdb->posts}, {$wpdb->postmeta}
				WHERE ({$wpdb->posts}.id = {$wpdb->postmeta}.post_id)
				AND {$wpdb->postmeta}.meta_key = 'syndication_feed_id'
				AND {$wpdb->postmeta}.meta_value = '{$link_id}'
				AND {$wpdb->posts}.post_author = '{$fix_mismatch_from_id}'
				");
				
				if (count($post_ids) > 0) :
					// Re-assign them all to the correct author
					if (is_numeric($fix_mismatch_to_id)) : // re-assign to a particular user
						$post_set = "(".implode(",", $post_ids).")";
						
						// Getting the revisions too, if there are any
						if (fwp_test_wp_version(FWP_SCHEMA_26)) :
							$parent_in_clause = "OR {$wpdb->posts}.post_parent IN $post_set";
						else :
							$parent_in_clause = '';
						endif;
						
						$wpdb->query("
						UPDATE {$wpdb->posts}
						SET post_author='{$fix_mismatch_to_id}'
						WHERE ({$wpdb->posts}.id IN $post_set
						$parent_in_clause)
						");
						$mesg = "Re-assigned ".count($post_ids)." post".((count($post_ids)==1)?'':'s').".";

					// ... and kill them all
					elseif ($fix_mismatch_to_id=='filter') :
						foreach ($post_ids as $post_id) :
							wp_delete_post($post_id);
						endforeach;						
						$mesg = "Deleted ".count($post_ids)." post".((count($post_ids)==1)?'':'s').".";
					endif;
				else :
					$mesg = "Couldn't find any posts that matched your criteria.";
				endif;
			endif;
		elseif (isset($GLOBALS['fwp_post']['save'])) :
			if (is_object($link) and $link->found()) :
				$alter = array ();
	
				// Unfamiliar author rule
				if (isset($GLOBALS['fwp_post']["unfamiliar_author"])) :
					if ('site-default'==$GLOBALS['fwp_post']["unfamiliar_author"]) :
						unset($link->settings["unfamiliar author"]);
					elseif ('newuser'==$GLOBALS['fwp_post']["unfamiliar_author"]) :
						$newuser_name = trim($GLOBALS['fwp_post']["unfamiliar_author_newuser"]);
						$link->map_name_to_new_user(/*name=*/ NULL, $newuser_name);
					else :
						$link->settings["unfamiliar author"] = $GLOBALS['fwp_post']["unfamiliar_author"];
					endif;
				endif;
				
				// Handle author mapping rules
				if (isset($GLOBALS['fwp_post']['author_rules_name']) and isset($GLOBALS['fwp_post']['author_rules_action'])) :
					unset($link->settings['map authors']);
					foreach ($GLOBALS['fwp_post']['author_rules_name'] as $key => $name) :
						// Normalize for case and whitespace
						$name = strtolower(trim($name));
						$author_action = strtolower(trim($GLOBALS['fwp_post']['author_rules_action'][$key]));
						
						if (strlen($name) > 0) :
							if ('newuser' == $author_action) :
								$newuser_name = trim($GLOBALS['fwp_post']['author_rules_newuser'][$key]);
								$link->map_name_to_new_user($name, $newuser_name);
							else :
								$link->settings['map authors']['name'][$name] = $author_action;
							endif;
						endif;
					endforeach;
				endif;
	
				if (isset($GLOBALS['fwp_post']['add_author_rule_name']) and isset($GLOBALS['fwp_post']['add_author_rule_action'])) :
					$name = strtolower(trim($GLOBALS['fwp_post']['add_author_rule_name']));
					$author_action = strtolower(trim($GLOBALS['fwp_post']['add_author_rule_action']));
					if (strlen($name) > 0) :
						if ('newuser' == $author_action) :
							$newuser_name = trim($GLOBALS['fwp_post']['add_author_rule_newuser']);
							$link->map_name_to_new_user($name, $newuser_name);
						else :
							$link->settings['map authors']['name'][$name] = $author_action;
						endif;
					endif;
				endif;
	
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
				if ('newuser'==$GLOBALS['fwp_post']['unfamiliar_author']) :
					$newuser_name = trim($GLOBALS['fwp_post']['unfamiliar_author_newuser']);
					if (strlen($newuser_name) > 0) :
						$userdata = array();
						$userdata['ID'] = NULL;
						
						$userdata['user_login'] = sanitize_user($newuser_name);
						$userdata['user_login'] = apply_filters('pre_user_login', $userdata['user_login']);
						
						$userdata['user_nicename'] = sanitize_title($newuser_name);
						$userdata['user_nicename'] = apply_filters('pre_user_nicename', $userdata['user_nicename']);
						
						$userdata['display_name'] = $wpdb->escape($newuser_name);
	
						$newuser_id = wp_insert_user($userdata);
						if (is_numeric($newuser_id)) :
							update_option('feedwordpress_unfamiliar_author', $newuser_id);
						else :
							// TODO: Add some error detection and reporting
						endif;
					else :
						// TODO: Add some error reporting
					endif;			
				else :
					update_option('feedwordpress_unfamiliar_author', $GLOBALS['fwp_post']['unfamiliar_author']);
				endif;
	
				if (isset($GLOBALS['fwp_post']['match_author_by_email']) and $GLOBALS['fwp_post']['match_author_by_email']=='yes') :
					update_option('feedwordpress_do_not_match_author_by_email', 'no');
				else :
					update_option('feedwordpress_do_not_match_author_by_email', 'yes');
				endif;
	
				if (isset($GLOBALS['fwp_post']['null_emails'])) :
					update_option('feedwordpress_null_email_set', $GLOBALS['fwp_post']['null_emails']);
				endif;
				
				$updated_link = true;
			endif;
		else :
			$updated_link = false;
		endif;

		$unfamiliar = array ('create' => '','default' => '','filter' => '');

		if (is_object($link) and $link->found()) :
			if (is_string($link->settings["unfamiliar author"])) :
				$key = $link->settings["unfamiliar author"];
			else:
				$key = 'site-default';
			endif;
		else :
			$key = FeedWordPress::on_unfamiliar('author');
		endif;

		$unfamiliar[$key] = ' selected="selected"';

		$match_author_by_email = !('yes' == get_option("feedwordpress_do_not_match_author_by_email"));
		$null_emails = FeedWordPress::null_email_set();
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
<div class="updated"><p>Syndicated author settings updated.</p></div>
<?php elseif (!is_null($mesg)) : ?>
<div class="updated"><p><?php print wp_specialchars($mesg, 1); ?></p></div>
<?php endif; ?>

<div class="wrap">
<form style="position: relative" action="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/<?php echo basename(__FILE__); ?>" method="post">
<?php if (is_numeric($link_id) and $link_id) : ?>
<input type="hidden" name="save_link_id" value="<?php echo $link_id; ?>" />
<?php else : ?>
<input type="hidden" name="save_link_id" value="*" />
<?php endif; ?>

<?php $links = FeedWordPress::syndicated_links(); ?>
<?php if (fwp_test_wp_version(FWP_SCHEMA_27)) : ?>
	<div class="icon32"><img src="<?php print htmlspecialchars(WP_PLUGIN_URL.'/'.$GLOBALS['fwp_path'].'/feedwordpress.png'); ?>" alt="" /></div>
<?php endif; ?>
<h2>Syndicated Author Settings<?php if (!is_null($link) and $link->found()) : ?>: <?php echo wp_specialchars($link->link->link_name, 1); ?><?php endif; ?></h2>
<?php if (fwp_test_wp_version(FWP_SCHEMA_27)) : ?>
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
<?php endif; /* else : */?>
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

<?php
	$authorlist = fwp_author_list();
?>
<table class="form-table">
<tbody>
<tr>
  <th>New authors</th>
  <td><span>Authors who haven't been syndicated before</span>
  <select style="max-width: 27.0em" id="unfamiliar-author" name="unfamiliar_author" onchange="contextual_appearance('unfamiliar-author', 'unfamiliar-author-newuser', 'unfamiliar-author-default', 'newuser', 'inline');">
<?php if (is_object($link) and $link->found()) : ?>
    <option value="site-default"<?php print $unfamiliar['site-default']; ?>>are handled according to the default for all feeds</option>
<?php endif; ?>
    <option value="create"<?php $unfamiliar['create']; ?>>will have a new author account created for them</option>
    <?php foreach ($authorlist as $author_id => $author_name) : ?>
      <option value="<?php echo $author_id; ?>"<?php print $unfamiliar[$author_id]; ?>>will have their posts attributed to <?php echo $author_name; ?></option>
    <?php endforeach; ?>
    <option value="newuser">will have their posts attributed to a new user...</option>
    <option value="filter"<?php print $unfamiliar['filter'] ?>>get filtered out</option>
  </select>

  <span id="unfamiliar-author-newuser">named <input type="text" name="unfamiliar_author_newuser" value="" /></span></p>
  </td>
</tr>

<?php
if (is_object($link) and $link->found()) :
?>
<tr><th>Syndicated authors</th>
<td>For attributing posts by specific authors. Blank out a name to delete the rule. Fill in a new name at the bottom to create a new rule.</p>
<table style="width: 100%">
<?php
	if (isset($link->settings['map authors'])) :
?>
<?php
		$i=0;
		foreach ($link->settings['map authors'] as $author_rules) :
			foreach ($author_rules as $author_name => $author_action) :
				$i++; 
?>
<tr>
<th style="text-align: left; width: 15.0em">Posts by <input type="text" name="author_rules_name[]" value="<?php echo htmlspecialchars($author_name); ?>" size="11" /></th>
  <td>
  <select id="author-rules-<?php echo $i; ?>" name="author_rules_action[]" onchange="contextual_appearance('author-rules-<?php echo $i; ?>', 'author-rules-<?php echo $i; ?>-newuser', 'author-rules-<?php echo $i; ?>-default', 'newuser', 'inline');">
    <?php foreach ($authorlist as $local_author_id => $local_author_name) : ?>
    <option value="<?php echo $local_author_id; ?>"<?php if ($local_author_id==$author_action) : echo ' selected="selected"'; endif; ?>>are assigned to <?php echo $local_author_name; ?></option>
    <?php endforeach; ?>
    <option value="newuser">will be assigned to a new user...</option>
    <option value="filter"<?php if ('filter'==$author_action) : echo ' selected="selected"'; endif; ?>>get filtered out</option>
  </select>
  
  <span id="author-rules-<?php echo $i; ?>-newuser">named <input type="text" name="author_rules_newuser[]" value="" /></span>
  </td>
</tr>
<?php 			endforeach;
		endforeach;
	endif;
?>

<tr>
<th style="text-align: left; width: 15.0em">Posts by <input type="text" name="add_author_rule_name" size="11" /></th>
  <td>
    <select id="add-author-rule" name="add_author_rule_action" onchange="contextual_appearance('add-author-rule', 'add-author-rule-newuser', 'add-author-rule-default', 'newuser', 'inline');">
      <?php foreach ($authorlist as $author_id => $author_name) : ?>
      <option value="<?php echo $author_id; ?>">are assigned to <?php echo $author_name; ?></option>
      <?php endforeach; ?>
      <option value="newuser">will be assigned to a new user...</option>
      <option value="filter">get filtered out</option>
    </select>
   
   <span id="add-author-rule-newuser">named <input type="text" name="add_author_rule_newuser" value="" /></span>
   </td>
</tr>
</table>
</td>
</tr>
<?php endif; ?>

<?php if (!(is_object($link) and $link->found())) : ?>
<tr>
<th scope="row">Matching Authors</th>
<td><ul style="list-style: none; margin: 0; padding: 0;">
<li><div><label><input id="match-author-by-email" type="checkbox" name="match_author_by_email" value="yes" <?php if ($match_author_by_email) : ?>checked="checked" <?php endif; ?>onchange="contextual_appearance('match-author-by-email', 'unless-null-email', null, 'yes', 'block', /*checkbox=*/ true);" /> Treat syndicated authors with the same e-mail address as the same author.</label></div>
<div id="unless-null-email">
<p>Unless the e-mail address is one of the following anonymous e-mail addresses:</p>
<textarea name="null_emails" rows="3" style="width: 100%">
<?php print implode("\n", $null_emails); ?>
</textarea>
</div></li>
</ul></td>
</tr>
<?php else : ?>
<th scope="row">Fixing mis-matched authors:</th>
<td><p style="margin: 0.5em 0px">Take all the posts from this feed attributed to
<select name="fix_mismatch_from">
<?php foreach ($authorlist as $author_id => $author_name) : ?>
      <option value="<?php echo $author_id; ?>"><?php echo $author_name; ?></option>
<?php endforeach; ?>
</select>
and instead
<select id="fix-mismatch-to" name="fix_mismatch_to" onchange="contextual_appearance('fix-mismatch-to', 'fix-mismatch-to-newuser', null, 'newuser', 'inline');">
<?php foreach ($authorlist as $author_id => $author_name) : ?>
      <option value="<?php echo $author_id; ?>">re-assign them to <?php echo $author_name; ?></option>
<?php endforeach; ?>
      <option value="newuser">re-assign them to a new user...</option>
      <option value="filter">delete them</option>
</select>

   <span id="fix-mismatch-to-newuser">named <input type="text" name="fix_mismatch_to_newuser" value="" /></span>
   <input type="submit" class="button" name="fix_mismatch" value="Fix it!" />
   </td>
</td>
<?php endif; ?>
</tbody>
</table>

<p class="submit">
<input class="button-primary" type="submit" name="save" value="Save Changes" />
</p>

<script type="text/javascript">
	contextual_appearance('unfamiliar-author', 'unfamiliar-author-newuser', 'unfamiliar-author-default', 'newuser', 'inline');
<?php if (is_object($link) and $link->found()) : ?>
<?php 	for ($j=1; $j<=$i; $j++) : ?>
	contextual_appearance('author-rules-<?php echo $j; ?>', 'author-rules-<?php echo $j; ?>-newuser', 'author-rules-<?php echo $j; ?>-default', 'newuser', 'inline');
<?php 	endfor; ?>
	contextual_appearance('add-author-rule', 'add-author-rule-newuser', 'add-author-rule-default', 'newuser', 'inline');
	contextual_appearance('fix-mismatch-to', 'fix-mismatch-to-newuser', null, 'newuser', 'inline');
<?php else : ?>
	contextual_appearance('match-author-by-email', 'unless-null-email', null, 'yes', 'block', /*checkbox=*/ true);
<?php endif; ?>
</script>
</form>
</div> <!-- class="wrap" -->
	<?php
	endif;
} /* function fwp_authors_page () */

	fwp_authors_page();

