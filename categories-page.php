<?php
require_once(dirname(__FILE__) . '/admin-ui.php');

class FeedWordPressCategoriesPage extends FeedWordPressAdminPage {
	function FeedWordPressCategoriesPage ($link) {
		FeedWordPressAdminPage::FeedWordPressAdminPage('feedwordpresscategories', $link);
	}
	
	/*static*/ function feed_categories_box ($page, $box = NULL) {

		$link = $page->link;

		$unfamiliar = array ('create'=>'','tag' => '', 'default'=>'','filter'=>'');
		if ($page->for_feed_settings()) :
			$unfamiliar['site-default'] = '';
			$ucKey = $link->settings["unfamiliar category"];
			$ucDefault = 'site-default';
		else :
			$ucKey = FeedWordPress::on_unfamiliar('category');
			$ucDefault = 'create';
		endif;
	
		if (!is_string($ucKey) or !array_key_exists($ucKey, $unfamiliar)) :
			$ucKey = $ucDefault;
		endif;
		$unfamiliar[$ucKey] = ' checked="checked"';
		
		// Hey ho, let's go...
		?>
<table class="edit-form">
<tr>
<th scope="row">Unfamiliar categories:</th>
<td><p>When one of the categories on a syndicated post is a category that FeedWordPress has not encountered before ...</p>

<ul class="options">
<?php if ($page->for_feed_settings()) : ?>
<li><label><input type="radio" name="unfamiliar_category" value="site-default"<?php echo $unfamiliar['site-default']; ?> /> use the <a href="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/<?php print basename(__FILE__); ?>">site-wide setting</a>
(currently <strong><?php echo FeedWordPress::on_unfamiliar('category'); ?></strong>)</label></li>
<?php endif; ?>

<li><label><input type="radio" name="unfamiliar_category" value="create"<?php echo $unfamiliar['create']; ?> /> create a new category</label></li>

<?php if (FeedWordPressCompatibility::post_tags()) : ?>
<li><label><input type="radio" name="unfamiliar_category" value="tag"<?php echo $unfamiliar['tag']; ?>/> create a new tag</label></li>
<?php endif; ?>

<li><label><input type="radio" name="unfamiliar_category" value="default"<?php echo $unfamiliar['default']; ?> /> don't create new categories<?php if (fwp_test_wp_version(FWP_SCHEMA_23)) : ?> or tags<?php endif; ?></label></li>
<li><label><input type="radio" name="unfamiliar_category" value="filter"<?php echo $unfamiliar['filter']; ?> /> don't create new categories<?php if (fwp_test_wp_version(FWP_SCHEMA_23)) : ?> or tags<?php endif; ?> and don't syndicate posts unless they match at least one familiar category</label></li>
</ul></td>
</tr>

<?php if ($page->for_feed_settings()) : ?>
<tr>
<th scope="row">Multiple categories:</th>
<td> 
<input type="text" size="20" id="cat_split" name="cat_split" value="<?php if (isset($link->settings['cat_split'])) : echo htmlspecialchars($link->settings['cat_split']); endif; ?>" />
<p class="setting-description">Enter a <a href="http://us.php.net/manual/en/reference.pcre.pattern.syntax.php">Perl-compatible regular expression</a> here if the feed provides multiple
categories in a single category element. The regular expression should match
the characters used to separate one category from the next. If the feed uses
spaces (like <a href="http://del.icio.us/">del.icio.us</a>), use the pattern "\s".
If the feed does not provide multiple categories in a single element, leave this
blank.</p></td>
</tr>
<?php endif; ?>
</table>
		<?php
	} /* FeedWordPressCategoriesPage::feed_categories_box() */

	function categories_box ($page, $box = NULL) {
		$link = $page->link;
		if ($page->for_feed_settings()) :
			if (is_array($link->settings['cats'])) : $cats = $link->settings['cats'];
			else : $cats = array();
			endif;
		else :
			$cats = array_map('trim',
				preg_split(FEEDWORDPRESS_CAT_SEPARATOR_PATTERN, get_option('feedwordpress_syndication_cats'))
			);
		endif;
		$dogs = SyndicatedPost::category_ids($cats, /*unfamiliar=*/ NULL);

		fwp_category_box($dogs, 'all '.$page->these_posts_phrase());
	} /* FeedWordPressCategoriesPage::categories_box () */
	
	function tags_box ($page, $box = NULL) {
		$link = $page->link;
		if ($page->for_feed_settings()) :
			$tags = $link->settings['tags'];
		else :
			$tags = array_map('trim',
				preg_split(FEEDWORDPRESS_CAT_SEPARATOR_PATTERN, get_option('feedwordpress_syndication_tags'))
			);
		endif;

		fwp_tags_box($tags, 'all '.$page->these_posts_phrase());
	} /* FeedWordPressCategoriesPage::tags_box () */
}

function fwp_categories_page () {
	global $wpdb, $wp_db_version;

	FeedWordPressCompatibility::validate_http_request(/*action=*/ 'feedwordpress_categories_settings', /*capability=*/ 'manage_links');

	if (isset($GLOBALS['fwp_post']['save']) or isset($GLOBALS['fwp_post']['submit']) or isset($GLOBALS['fwp_post']['fix_mismatch'])) :
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
	$catsPage = new FeedWordPressCategoriesPage($link);

	$mesg = null;

	////////////////////////////////////////////////
	// Process POST request, if any /////////////////
	////////////////////////////////////////////////
	if (isset($GLOBALS['fwp_post']['save']) or isset($GLOBALS['fwp_post']['submit'])) :
		$saveCats = array();
		if (isset($GLOBALS['fwp_post']['post_category'])) :
			foreach ($GLOBALS['fwp_post']['post_category'] as $cat_id) :
				$saveCats[] = '{#'.$cat_id.'}';
			endforeach;
		endif;

		// Different variable names to cope with different WordPress AJAX UIs
		$syndicatedTags = array();
		if (isset($GLOBALS['fwp_post']['tax_input']['post_tag'])) :
			$syndicatedTags = explode(",", $GLOBALS['fwp_post']['tax_input']['post_tag']);
		elseif (isset($GLOBALS['fwp_post']['tags_input'])) :
			$syndicatedTags = explode(",", $GLOBALS['fwp_post']['tags_input']);
		endif;
		$syndicatedTags = array_map('trim', $syndicatedTags);

		if (is_object($link) and $link->found()) :
			$alter = array ();

			// Categories
			if (!empty($saveCats)) : $link->settings['cats'] = $saveCats;
			else : unset($link->settings['cats']);
			endif;

			// Tags
			$link->settings['tags'] = $syndicatedTags;

			// Unfamiliar categories
			if (isset($GLOBALS['fwp_post']["unfamiliar_category"])) :
				if ('site-default'==$GLOBALS['fwp_post']["unfamiliar_category"]) :
					unset($link->settings["unfamiliar category"]);
				else :
					$link->settings["unfamiliar category"] = $GLOBALS['fwp_post']["unfamiliar_category"];
				endif;
			endif;

			// Category spitting regex
			if (isset($GLOBALS['fwp_post']['cat_split'])) :
				if (strlen(trim($GLOBALS['fwp_post']['cat_split'])) > 0) :
					$link->settings['cat_split'] = trim($GLOBALS['fwp_post']['cat_split']);
				else :
					unset($link->settings['cat_split']);
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
			// Categories
			if (!empty($saveCats)) :
				update_option('feedwordpress_syndication_cats', implode(FEEDWORDPRESS_CAT_SEPARATOR, $saveCats));
			else :
				delete_option('feedwordpress_syndication_cats');
			endif;
	
			// Tags
			if (!empty($syndicatedTags)) :
				update_option('feedwordpress_syndication_tags', implode(FEEDWORDPRESS_CAT_SEPARATOR, $syndicatedTags));
			else :
				delete_option('feedwordpress_syndication_tags');
			endif;

			update_option('feedwordpress_unfamiliar_category', $_REQUEST['unfamiliar_category']);

			$updated_link = true;
		endif;
	else :
		$updated_link = false;
	endif;

	////////////////////////////////////////////////
	// Get defaults from database //////////////////
	////////////////////////////////////////////////
	
	$unfamiliar = array ('create'=>'','tag' => '', 'default'=>'','filter'=>'');
	if (is_object($link) and $link->found()) :
		$unfamiliar['site-default'] = '';
		$ucKey = $link->settings["unfamiliar category"];
		$ucDefault = 'site-default';

		if (is_array($link->settings['cats'])) : $cats = $link->settings['cats'];
		else : $cats = array();
		endif;

		$tags = $link->settings['tags'];
	else :
		$ucKey = FeedWordPress::on_unfamiliar('category');
		$ucDefault = 'create';

		$cats = array_map('trim',
			preg_split(FEEDWORDPRESS_CAT_SEPARATOR_PATTERN, get_option('feedwordpress_syndication_cats'))
		);
		$tags = array_map('trim',
			preg_split(FEEDWORDPRESS_CAT_SEPARATOR_PATTERN, get_option('feedwordpress_syndication_tags'))
		);
	endif;

	if (!is_string($ucKey) or !array_key_exists($ucKey, $unfamiliar)) :
		$ucKey = $ucDefault;
	endif;
	$unfamiliar[$ucKey] = ' checked="checked"';

	$dogs = SyndicatedPost::category_ids($cats, /*unfamiliar=*/ NULL);

	$catsPage->ajax_interface_js();

	if ($updated_link) : ?>
<div class="updated"><p>Syndicated categories and tags settings updated.</p></div>
<?php elseif (!is_null($mesg)) : ?>
<div class="updated"><p><?php print wp_specialchars($mesg, 1); ?></p></div>
<?php endif; ?>

<div class="wrap">
<?php
if (function_exists('add_meta_box')) :
	add_action(
		FeedWordPressCompatibility::bottom_script_hook(__FILE__),
		/*callback=*/ array($catsPage, 'fix_toggles'),
		/*priority=*/ 10000
	);
	FeedWordPressSettingsUI::ajax_nonce_fields();
endif;
?>
<form style="position: relative" action="admin.php?page=<?php print $GLOBALS['fwp_path'] ?>/<?php echo basename(__FILE__); ?>" method="post">
<div><?php
	FeedWordPressCompatibility::stamp_nonce('feedwordpress_categories_settings');

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

<?php $links = FeedWordPress::syndicated_links(); ?>
<?php if (fwp_test_wp_version(FWP_SCHEMA_27)) : ?>
	<div class="icon32"><img src="<?php print htmlspecialchars(WP_PLUGIN_URL.'/'.$GLOBALS['fwp_path'].'/feedwordpress.png'); ?>" alt="" /></div>
<?php endif; ?>
<h2>Categories<?php print htmlspecialchars(FEEDWORDPRESS_AND_TAGS); ?> Settings<?php if (!is_null($link) and $link->found()) : ?>: <?php echo wp_specialchars($link->link->link_name, 1); ?><?php endif; ?></h2>

<style type="text/css">
	table.edit-form th { width: 27%; vertical-align: top; }
	table.edit-form td { width: 73%; vertical-align: top; }
	table.edit-form td ul.options { margin: 0; padding: 0; list-style: none; }
</style>

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

<div id="poststuff">
<?php fwp_linkedit_single_submit(); ?>
<div id="post-body">
<?php
$boxes_by_methods = array(
	'feed_categories_box' => __('Feed Categories'.FEEDWORDPRESS_AND_TAGS),
	'categories_box' => array('title' => __('Categories'), 'id' => 'categorydiv'),
	'tags_box' => __('Tags'),
);

if (!FeedWordPressCompatibility::post_tags()) :
	unset($boxes_by_methods['tags_box']);
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
			/*callback=*/ array('FeedWordPressCategoriesPage', $method),
			/*page=*/ $catsPage->meta_box_context(),
			/*context=*/ $catsPage->meta_box_context()
		);
	endforeach;
	do_action('feedwordpress_admin_page_posts_meta_boxes', $catsPage);
?>
	<div class="metabox-holder">
<?php
	fwp_do_meta_boxes($catsPage->meta_box_context(), $catsPage->meta_box_context(), $catsPage);
?>
	</div> <!-- class="metabox-holder" -->
</div> <!-- id="post-body" -->
</div> <!-- id="poststuff" -->

<?php fwp_linkedit_single_submit_closer(); ?>

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
} /* function fwp_categories_page () */

	fwp_categories_page();

