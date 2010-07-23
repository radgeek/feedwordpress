<?php
require_once(dirname(__FILE__) . '/admin-ui.php');

class FeedWordPressCategoriesPage extends FeedWordPressAdminPage {
	function FeedWordPressCategoriesPage ($link = -1) {
		if (is_numeric($link) and -1 == $link) :
			$link = $this->submitted_link();
		endif;
		
		FeedWordPressAdminPage::FeedWordPressAdminPage('feedwordpresscategories', $link);
		$this->dispatch = 'feedwordpress_admin_page_categories';
		$this->pagenames = array(
			'default' => 'Categories'.FEEDWORDPRESS_AND_TAGS,
			'settings-update' => 'Syndicated Categories'.FEEDWORDPRESS_AND_TAGS,
			'open-sheet' => 'Categories'.FEEDWORDPRESS_AND_TAGS,
		);
		$this->filename = __FILE__;
	}
	
	/*static*/ function feed_categories_box ($page, $box = NULL) {

		$link = $page->link;

		$unfamiliar = array ('create'=>'','tag' => '', 'default'=>'','filter'=>'');
		if ($page->for_feed_settings()) :
			$unfamiliar['site-default'] = '';
			$ucKey = $link->setting("unfamiliar category", NULL, NULL);
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
		$globalCats = array_map('trim',
			preg_split(FEEDWORDPRESS_CAT_SEPARATOR_PATTERN, get_option('feedwordpress_syndication_cats'))
		);
			
		if ($page->for_feed_settings()) :
			$add_global_categories = $link->setting('add global categories', NULL, 'yes');
			$checked = array('yes' => '', 'no' => '');
			$checked[$add_global_categories] = ' checked="checked"';
			
			if (is_array($link->setting('cats', NULL, NULL))) : $cats = $link->settings['cats'];
			else : $cats = array();
			endif;
		else :
			$cats = $globalCats;
		endif;
		
		if ($page->for_feed_settings()) :
		?>
		<table class="twofer">
		<tbody>
		<tr>
		<td class="primary">
		<?php
		endif;
		
		$dogs = SyndicatedPost::category_ids($cats, /*unfamiliar=*/ NULL);
		fwp_category_box($dogs, 'all '.$page->these_posts_phrase());
		
		$globalDogs = SyndicatedPost::category_ids($globalCats, /*unfamiliar=*/ NULL);

		$siteWideHref = 'admin.php?page='.$GLOBALS['fwp_path'].'/'.basename(__FILE__);
		if ($page->for_feed_settings()) :
		?>
		</td>
		<td class="secondary">
		<h4>Site-wide Categories</h4>
		<?php if (count($globalCats) > 0) : ?>
		<ul class="current-setting">
		<?php foreach ($globalDogs as $dog) : ?>
		<li><?php $cat = get_term($dog, 'category'); print $cat->name; ?></li>
		<?php endforeach; ?>
		</ul>
		</div>
		<p>
		<?php else : ?>
		<p>Site-wide settings may also assign categories to syndicated
		posts.
		<?php endif; ?>
		Should <?php print $page->these_posts_phrase(); ?> be assigned
		these categories from the <a href="<?php print esc_html($siteWideHref); ?>">site-wide settings</a>, in
		addition to the feed-specific categories you set up here?</p>
		
		<ul class="settings">
		<li><p><label><input type="radio" name="add_global_categories" value="yes" <?php print $checked['yes']; ?> /> Yes. Place <?php print $page->these_posts_phrase(); ?> under all these categories.</label></p></li>
		<li><p><label><input type="radio" name="add_global_categories" value="no" <?php print $checked['no']; ?> /> No. Only use the categories I set up on the left. Do not ise the global defaults for <?php print $page->these_posts_phrase(); ?></label></p></li>
		</ul>
		</td>
		</tr>
		</tbody>
		</table>
		<?php
		endif;
	} /* FeedWordPressCategoriesPage::categories_box () */
	
	function tags_box ($page, $box = NULL) {
		$link = $page->link;
		if ($page->for_feed_settings()) :
			$tags = $link->setting('tags', NULL, NULL);
		else :
			$tags = array_map('trim',
				preg_split(FEEDWORDPRESS_CAT_SEPARATOR_PATTERN, get_option('feedwordpress_syndication_tags'))
			);
		endif;

		fwp_tags_box($tags, 'all '.$page->these_posts_phrase());
	} /* FeedWordPressCategoriesPage::tags_box () */
	
	function save_settings ($post) {
		$saveCats = array();
		if (isset($post['post_category'])) :
			foreach ($post['post_category'] as $cat_id) :
				$saveCats[] = '{#'.$cat_id.'}';
			endforeach;
		endif;
	
		// Different variable names to cope with different WordPress AJAX UIs
		$syndicatedTags = array();
		if (isset($post['tax_input']['post_tag'])) :
			$syndicatedTags = explode(",", $post['tax_input']['post_tag']);
		elseif (isset($post['tags_input'])) :
			$syndicatedTags = explode(",", $post['tags_input']);
		endif;
		$syndicatedTags = array_map('trim', $syndicatedTags);
	
		if ($this->for_feed_settings()) :
			// Categories
			if (!empty($saveCats)) : $this->link->settings['cats'] = $saveCats;
			else : unset($this->link->settings['cats']);
			endif;
	
			// Tags
			$this->link->settings['tags'] = $syndicatedTags;
	
			// Unfamiliar categories
			if (isset($post["unfamiliar_category"])) :
				if ('site-default'==$post["unfamiliar_category"]) :
					unset($this->link->settings["unfamiliar category"]);
				else :
					$this->link->settings["unfamiliar category"] = $post["unfamiliar_category"];
				endif;
			endif;
	
			// Category splitting regex
			if (isset($post['cat_split'])) :
				if (strlen(trim($post['cat_split'])) > 0) :
					$this->link->settings['cat_split'] = trim($post['cat_split']);
				else :
					unset($this->link->settings['cat_split']);
				endif;
			endif;
			
			if (isset($post['add_global_categories'])) :
				$this->link->settings['add global categories'] = $post['add_global_categories'];
			endif;

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
		endif;
		parent::save_settings($post);
	} /* FeedWordPressCategoriesPage::save_settings() */
	
	function display () {
		////////////////////////////////////////////////
		// Display settings boxes //////////////////////
		////////////////////////////////////////////////
	
		$this->boxes_by_methods = array(
			'feed_categories_box' => __('Feed Categories'.FEEDWORDPRESS_AND_TAGS),
			'categories_box' => array('title' => __('Categories'), 'id' => 'categorydiv'),
			'tags_box' => __('Tags'),
		);
		if (!FeedWordPressCompatibility::post_tags()) :
			unset($this->boxes_by_methods['tags_box']);
		endif;

		parent::display();	
	}
}

	$categoriesPage = new FeedWordPressCategoriesPage;
	$categoriesPage->display();

