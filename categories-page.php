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
			'default' => 'Categories & Tags',
			'settings-update' => 'Syndicated Categories & Tags',
			'open-sheet' => 'Categories & Tags',
		);
		$this->filename = __FILE__;
	}

	function unfamiliar_category_label ($name) {
		if (preg_match('/^create:(.*)$/', $name, $refs)) :
			$tax = get_taxonomy($refs[1]);
			$name = sprintf(__('Create new %s to match them'), $tax->labels->name);
		endif;
		return $name;
	}


	function feed_categories_box ($page, $box = NULL) {
		$link = $page->link;

		$globalPostType = get_option('feedwordpress_syndicated_post_type', 'post');
		if ($this->for_feed_settings()) :
			$post_type = $link->setting('syndicated post type', 'syndicated_post_type', 'post');
		else :
			$post_type = $globalPostType;
		endif;
		$taxonomies = get_object_taxonomies(array('object_type' => $post_type), 'names');

		$unmatched = array('category' => array(), 'post_tag' => array());
		$matchUl = array('cats' => array(), 'tags' => array(), 'filter' => array());
		$tagLikeTaxonomies = array();
		foreach ($taxonomies as $tax) :
			$taxonomy = get_taxonomy($tax);

			if (!$taxonomy->hierarchical) :
				$tagLikeTaxonomies[] = $tax;
			endif;

			$name = 'create:'.$tax;
			foreach (array('category', 'post_tag') as $what) :
				$unmatched[$what][$name] = array(
					'label' => $this->unfamiliar_category_label($name),
				);
				$unmatchedRadio[$what][$name] = '';
			endforeach;

			foreach (array('cats', 'tags', 'filter') as $what) :
				$matchUl[$what][$tax] = array(
				'checked' => '',
				'labels' => $taxonomy->labels,
				);
			endforeach;
		endforeach;

		foreach ($unmatched as $what => $um) :
			$unmatched[$what]['null'] = array('label' => __('Don\'t create any matching terms'));
			$unmatchedRadio[$what]['null'] = '';
		endforeach;

		$globalUnmatched = array(
			'category' => FeedWordPress::on_unfamiliar('category'),
			'post_tag' => FeedWordPress::on_unfamiliar('post_tag'),
		);
		foreach ($globalUnmatched as $what => $value) :
			if ($value=='create') : $value = 'create:category'; endif;
			if ($value=='tag') : $value = 'create:post_tag'; endif;
			$globalUnmatched[$what] = $value;
		endforeach;

		$globalMatch['cats'] = get_option('feedwordpress_match_cats', $taxonomies);
		$globalMatch['tags'] = get_option('feedwordpress_match_tags', $tagLikeTaxonomies);
		$globalMatch['filter'] = get_option('feedwordpress_match_filter', array());

		$globalMatchLabels = array();
		$nothingDoing = array('cats' => "won't try to match", 'tags' => "won't try to match", "filter" => "won't filter");

		foreach ($globalMatch as $what => $domain) :
			$labels = array(); $domain = array_filter($domain, 'remove_dummy_zero');
			foreach ($domain as $tax) :
				$tax = get_taxonomy($tax);
				$labels[] = $tax->labels->name;
			endforeach;

			if (count($labels) > 0) :
				$globalMatchLabels[$what] = implode(", ", $labels);
			else :
				$globalMatchLabels[$what] = $nothingDoing[$what];
			endif;
		endforeach;

		if ($this->for_feed_settings()) :
			$href = $this->admin_page_href(basename(__FILE__));

			foreach ($unmatched as $what => $um) :
				// Is the global default setting appropriate to this post type?
				$GUC = $globalUnmatched[$what];
				if (isset($um[$GUC])) :
					// Yup. Let's add a site-default option
					$currently = $um[$GUC]['label'];
					$defaultLi = array(
					'site-default' => array(
						'label' => sprintf(
							__('Use the <a href="%s">site-wide setting</a> <span class="current-setting">Currently: <strong>%s</strong></span>'),
							$href,
							$currently
						),
					), );
					$unmatchedColumns[$what] = array(
						$defaultLi,
					);
					$unmatchedDefault[$what] = 'site-default';
					$unmatchedRadio[$what]['site-default'] = '';
				else :
					$opts = array_keys($unmatched[$what]);
					$unmatchedDefault[$what] = $opts[0];
					$unmatchedColumns[$what] = array();
				endif;

				$ucKey[$what] = $link->setting("unfamiliar $what", NULL, NULL);
			endforeach;

			$match['cats'] = $this->link->setting('match/cats', NULL, NULL);
			$match['tags'] = $this->link->setting('match/tags', NULL, NULL);
			$match['filter'] = $this->link->setting('match/filter', NULL, NULL);
		else :
			foreach ($unmatched as $what => $um) :
				$ucKey[$what] = FeedWordPress::on_unfamiliar($what);
			endforeach;

			$match = $globalMatch;
		endif;

		foreach ($ucKey as $what => $uck) :
			if ($uck == 'tag') : $uck = 'create:post_tag'; endif;
			if ($uck == 'create') : $uck = 'create:category'; endif;

			if (!is_string($uck)) :
				$uck = $unmatchedDefault[$what];
			endif;
			$ucKey[$what] = $uck;

			if (!array_key_exists($uck, $unmatchedRadio[$what])) :
				$obsoleteLi = array(
					$uck => array(
					'label' => ' <span style="font-style: italic; color: #777;">'.$this->unfamiliar_category_label($uck).'</span> <span style="background-color: #ffff90; color: black;">(This setting is no longer applicable to the type of post syndicated from this feed!)</span><p>Please change this one of the following settings:</p>',
					),
				);
				$unmatched[$what] = array_merge($obsoleteLi, $unmatched[$what]);
				$unmatchedRadio[$what][$uck] = ' disabled="disabled"';
			endif;

			$unmatchedRadio[$what][$uck] .= ' checked="checked"';

			$unmatchedColumns[$what][] = $unmatched[$what];
		endforeach;

		$defaulted = array();
		foreach ($match as $what => $set) :
			$defaulted[$what] = false;
			if (is_null($set) or (count($set) < 1)) :
				$defaulted[$what] = true;
				if ($this->for_feed_settings()) :
					$set = $globalMatch[$what];
					$match[$what] = $globalMatch[$what];
				endif;
			endif;

			if (!$defaulted[$what] or $this->for_feed_settings()) :
				foreach ($set as $against) :
					if (array_key_exists($against, $matchUl[$what])) :
						$matchUl[$what][$against]['checked'] = ' checked="checked"';
					endif;
				endforeach;
			endif;
		endforeach;

		// Hey ho, let's go...
		$offerSiteWideSettings = ($page->for_feed_settings() and ($post_type==$globalPostType));
		?>
<table class="edit-form narrow">
<tr>
<th scope="row">Match feed categories:</th>
<td><input type="hidden" name="match_categories[cats][]" value="0" />
<?php if ($offerSiteWideSettings) : ?>
	<table class="twofer">
	<tbody>
	<tr><td class="equals first <?php if ($defaulted['cats']) : ?>active<?php else: ?>inactive<?php endif; ?>"><p><label><input type="radio" name="match_default[cats]"
value="yes" <?php if ($defaulted['cats']) : ?> checked="checked"<?php endif; ?> />
Use the <a href="<?php print $href; ?>">site-wide setting</a>
<span class="current-setting">Currently: <strong><?php print $globalMatchLabels['cats']; ?></strong></span></label></p></td>
	<td class="equals second <?php if ($defaulted['cats']) : ?>inactive<?php else: ?>active<?php endif; ?>"><p><label><input type="radio" name="match_default[cats]"
value="no" <?php if (!$defaulted['cats']) : ?> checked="checked"<?php endif; ?> />
Do something different with this feed.</label>
<?php else : ?>
	<p>
<?php endif; ?>
When a feed provides categories for a post, try to match those categories
locally with:</p>
<ul class="options compact">
<?php foreach ($matchUl['cats'] as $name => $li) : ?>
	<li><label><input type="checkbox"
	name="match_categories[cats][]" value="<?php print $name; ?>"
	<?php print $li['checked']; ?> /> <?php $l = $li['labels']; print $l->name; ?></label></li>
<?php endforeach; ?>
</ul>
<?php if ($offerSiteWideSettings) : ?>
	</td></tr>
	</tbody>
	</table>
<?php endif; ?>
</td>
</tr>

<tr>
<th scope="row">Unmatched categories:</th>
<td><p>When <?php print $this->these_posts_phrase(); ?> have categories on
the feed that don't have any local matches yet...</p>

<?php	if (count($unmatchedColumns['category']) > 1) : ?>
	<table class="twofer">
<?php	else : ?>
	<table style="width: 100%">
<?php	endif; ?>
	<tbody>
	<tr>
	<?php foreach ($unmatchedColumns['category'] as $index => $column) : ?>
		<td class="equals <?php print (($index == 0) ? 'first' : 'second'); ?> inactive"><ul class="options">
		<?php foreach ($column as $name => $li) : ?>
			<li><label><input type="radio" name="unfamiliar_category" value="<?php print $name; ?>"<?php print $unmatchedRadio['category'][$name]; ?> /> <?php print $li['label']; ?></label></li>
		<?php endforeach; ?>
		</ul></td>
	<?php endforeach; ?>
	</tr>
	</tbody>
	</table>
</td></tr>

<tr>
<th scope="row">Match inline tags:
<p class="setting-description">Applies only to inline tags marked
as links in the text of syndicated posts, using the
<code>&lt;a rel="tag"&gt;...&lt;/a&gt;</code> microformat.
Most feeds with "tags" just treat them as normal feed categories,
like those handled above.</p>
</th>
<td><input type="hidden" name="match_categories[tags][]" value="0" />
<?php if ($offerSiteWideSettings) : ?>
	<table class="twofer">
	<tbody>
	<tr><td class="equals first <?php if ($defaulted['tags']) : ?>active<?php else: ?>inactive<?php endif; ?>"><p><label><input type="radio" name="match_default[tags]"
value="yes" <?php if ($defaulted['tags']) : ?> checked="checked"<?php endif; ?> />
Use the <a href="<?php print $href; ?>">site-wide setting</a>
<span class="current-setting">Currently: <strong><?php print $globalMatchLabels['tags']; ?></strong></span></label></p>
</td>
	<td class="equals second <?php if ($defaulted['tags']) : ?>inactive<?php else: ?>active<?php endif; ?>"><p><label><input type="radio" name="match_default[tags]"
value="no" <?php if (!$defaulted['tags']) : ?> checked="checked"<?php endif; ?> />
Do something different with this feed.</label>
<?php else : ?>
	<p>
<?php endif; ?>
When a feed provides tags inline in a post, try to match those tags
locally with:</p>
<ul class="options compact">
<?php foreach ($matchUl['tags'] as $name => $li) : ?>
	<li><label><input type="checkbox"
	name="match_categories[tags][]" value="<?php print $name; ?>"
	<?php print $li['checked']; ?> /> <?php $l = $li['labels']; print $l->name; ?></label></li>
<?php endforeach; ?>
</ul>
<?php if ($offerSiteWideSettings) : ?>
	</td></tr>
	</tbody>
	</table>
<?php endif; ?>
</td>
</tr>

<tr>
<th scope="row">Unmatched inline tags:</th>
<td><p>When the text of <?php print $this->these_posts_phrase(); ?> contains
inline tags that don't have any local matches yet...</p>

<?php	if (count($unmatchedColumns['post_tag']) > 1) : ?>
	<table class="twofer">
<?php	else : ?>
	<table style="width: 100%">
<?php	endif; ?>
	<tbody>
	<tr>
	<?php foreach ($unmatchedColumns['post_tag'] as $index => $column) : ?>
		<td class="equals <?php print (($index == 0) ? 'first' : 'second'); ?> inactive"><ul class="options">
		<?php foreach ($column as $name => $li) : ?>
			<li><label><input type="radio" name="unfamiliar_post_tag" value="<?php print $name; ?>"<?php print $unmatchedRadio['post_tag'][$name]; ?> /> <?php print $li['label']; ?></label></li>
		<?php endforeach; ?>
		</ul></td>
	<?php endforeach; ?>
	</tr>
	</tbody>
	</table>

</td></tr>

<tr>
<th scope="row">Filter:</th>
<td><input type="hidden" name="match_categories[filter][]" value="0" />
<?php if ($offerSiteWideSettings) : ?>
	<table class="twofer">
	<tbody>
	<tr>
	<td class="equals first <?php if ($defaulted['filter']) : ?>active<?php else: ?>inactive<?php endif; ?>">
	<p><label><input type="radio" name="match_default[filter]"
value="yes" <?php if ($defaulted['filter']) : ?> checked="checked"<?php endif; ?> />
Use the <a href="<?php print $href; ?>">site-wide setting</a>
<span class="current-setting">Currently: <strong><?php print $globalMatchLabels['filter']; ?></strong></span></label></p>
	</td>
	<td class="equals second <?php if ($defaulted['filter']) : ?>inactive<?php else: ?>active<?php endif; ?>">
	<p><label><input type="radio" name="match_default[filter]"
value="no" <?php if (!$defaulted['filter']) : ?> checked="checked"<?php endif; ?> />
Do something different with this feed:</label></p>
<div style="margin-left: 3.0em;">
<?php endif; ?>

<ul class="options">
<?php foreach ($matchUl['filter'] as $tax => $li) : ?>
<li><label><input type="checkbox" name="match_categories[filter][]" value="<?php print $tax; ?>"
<?php print $li['checked']; ?> /> Don't syndicate posts unless they match at
least one local <strong><?php $l = $li['labels']; print $l->singular_name; ?></strong></label></li>
<?php endforeach; ?>
</ul>

<?php if ($offerSiteWideSettings) : ?>
	</div>
	</td></tr>
	</tbody>
	</table>
<?php endif; ?>
</td>
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

	function term_option_map () {
		return array(
			'category' => 'feedwordpress_syndication_cats',
			'post_tag' => 'feedwordpress_syndication_tags',
		);
	}
	function term_setting_map () {
		return array(
			'category' => 'cats',
			'post_tag' => 'tags',
		);
	}

	function categories_box ($page, $box = NULL) {
		$link = $page->link;
		$dummy = null;
		$syndicatedlink = new SyndicatedLink($dummy);

		if ($this->for_feed_settings()) :
			$post_type = $link->setting('syndicated post type', 'syndicated_post_type', 'post');
		else :
			$post_type = get_option('feedwordpress_syndicated_post_type', 'post');
		endif;
		$taxonomies = get_object_taxonomies(array('object_type' => $post_type), 'names');

		$option_map = $this->term_option_map();
		$setting_map = $this->term_setting_map();
		$globalTax = get_option('feedwordpress_syndication_terms', array());
		if ($page->for_feed_settings()) :
			$terms = $link->setting('terms', NULL, array());
		endif;

		?>
		<table class="edit-form narrow">
		<tbody>
		<?php
		foreach ($taxonomies as $tax) :
			$taxonomy = get_taxonomy($tax);
			?>
			<tr><th><?php print $taxonomy->labels->name; ?></th>
			<td><?php
			if (isset($option_map[$tax])) :
				$option = $option_map[$tax];
				$globalCats = preg_split(FEEDWORDPRESS_CAT_SEPARATOR_PATTERN, get_option($option));
			elseif (isset($globalTax[$tax])) :
				$globalCats = $globalTax[$tax];
			else :
				$globalCats = array();
			endif;
			$globalCats = array_map('trim', $globalCats);

			if ($page->for_feed_settings()) :
				$add_global_categories = $link->setting("add/$tax", NULL, 'yes');
				$checked = array('yes' => '', 'no' => '');
				$checked[$add_global_categories] = ' checked="checked"';

				if (isset($setting_map[$tax])) :
					$setting = $setting_map[$tax];
					$cats = $link->setting($setting, NULL, NULL);
					if (is_null($cats)) : $cats = array(); endif;
				elseif (isset($terms[$tax])) :
					$cats = $terms[$tax];
				else :
					$cats = array();
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

			$dogs = $syndicatedlink->category_ids(/*post=*/ NULL, $cats, /*unfamiliar=*/ NULL, /*taxonomies=*/ array($tax));
			
			if ($taxonomy->hierarchical) : // Use a category-style checkbox
				fwp_category_box($dogs, 'all '.$page->these_posts_phrase(), /*tags=*/ array(), /*params=*/ array('taxonomy' => $tax));
			else : // Use a tag-style edit box
				fwp_tags_box($cats, 'all '.$page->these_posts_phrase(), /*params=*/ array('taxonomy' => $tax));
			endif;

			$globalDogs = $syndicatedlink->category_ids(/*post=*/ NULL, $globalCats, /*unfamiliar=*/ 'create:'.$tax, /*taxonomies=*/ array($tax));

			$siteWideHref = $this->admin_page_href(basename(__FILE__));

			if ($page->for_feed_settings()) :
			?>
			</td>
			<td class="secondary">
			<h4>Site-wide <?php print $taxonomy->labels->name; ?></h4>
			<?php if (count($globalCats) > 0) : ?>
			  <ul class="current-setting">
			  <?php foreach ($globalDogs as $dog) : ?>
			    <li><?php $cat = get_term($dog, $tax); print $cat->name; ?></li>
			  <?php endforeach; ?>
			  </ul>
			  </div>
			  <p>
			<?php else : ?>
			  <p>Site-wide settings may also assign categories to syndicated
			posts.
			<?php endif; ?>
			Should <?php print $page->these_posts_phrase(); ?> be assigned
			these <?php print $taxonomy->labels->name; ?> from the <a href="<?php print esc_html($siteWideHref); ?>">site-wide settings</a>, in
			addition to the feed-specific <?php print $taxonomy->labels->name; ?> you set up here?</p>

			<ul class="settings">
			<li><p><label><input type="radio" name="add_global[<?php print $tax; ?>]" value="yes" <?php print $checked['yes']; ?> /> Yes. Place <?php print $page->these_posts_phrase(); ?> under all these categories.</label></p></li>
			<li><p><label><input type="radio" name="add_global[<?php print $tax; ?>]" value="no" <?php print $checked['no']; ?> /> No. Only use the categories I set up on the left. Do not use the global defaults for <?php print $page->these_posts_phrase(); ?></label></p></li>
			</ul>
			</td>
			</tr>
			</tbody>
			</table>
			<?php
			endif;
			?>
			</td>
			</tr>
			<?php
		endforeach;
		?>
		</tbody>
		</table>
		<?php
	} /* FeedWordPressCategoriesPage::categories_box () */

	function save_settings ($post) {
		if (isset($post['match_categories'])) :
			foreach ($post['match_categories'] as $what => $set) :
				// Defaulting is controlled by a separate radio button
				if ($this->for_feed_settings()
				and isset($post['match_default'])
				and isset($post['match_default'][$what])
				and $post['match_default'][$what]=='yes') :
					$set = NULL; // Defaulted!
				endif;

				$this->update_setting("match/$what", $set, NULL);
			endforeach;
		endif;
		$optionMap = $this->term_option_map();
		$settingMap = $this->term_setting_map();

		$saveTerms = array(); $separateSaveTerms = array('category' => array(), 'post_tag' => array());

		if (!isset($post['tax_input'])) : $post['tax_input'] = array(); endif;

		// Merge in data from older-notation category check boxes
		if (isset($post['post_category'])) :
			// Just merging in for processing below.
			$post['tax_input']['category'] = array_merge(
				(isset($post['tax_input']['category']) ? $post['tax_input']['category'] : array()),
				$post['post_category']
			);
		endif;

		// Process data from term tag boxes and check boxes
		foreach ($post['tax_input'] as $tax => $terms) :
			$saveTerms[$tax] = array();
			if (is_array($terms)) : // Numeric IDs from checklist
				foreach ($terms as $term) :
					if ($term) :
						$saveTerms[$tax][] = '{'.$tax.'#'.$term.'}';
					endif;
				endforeach;
			else : // String from tag input
				$saveTerms[$tax] = explode(",", $terms);
			endif;
			$saveTerms[$tax] = array_map('trim', $saveTerms[$tax]);

			if (isset($optionMap[$tax])) :
				$separateSaveTerms[$tax] = $saveTerms[$tax];
				unset($saveTerms[$tax]);
			endif;
		endforeach;

		if (isset($post['post_category'])) :
			foreach ($post['post_category'] as $cat) :
				$separateSaveTerms['category'][] = '{category#'.$cat.'}';
			endforeach;
		endif;

		// Unmatched categories and tags
		foreach (array('category', 'post_tag') as $what) :
			if (isset($post["unfamiliar_{$what}"])) :
				$this->update_setting(
					"unfamiliar {$what}",
					$post["unfamiliar_{$what}"],
					'site-default'
				);
			endif;
		endforeach;

		// Categories and Tags
		foreach ($separateSaveTerms as $tax => $terms) :
			if ($this->for_feed_settings()) :
				$this->link->update_setting($settingMap[$tax], $terms, array());
			else :
				if (!empty($terms)) :
					update_option($optionMap[$tax], implode(FEEDWORDPRESS_CAT_SEPARATOR, $terms));
				else :
					delete_option($optionMap[$tax]);
				endif;
			endif;
		endforeach;

		// Other terms
		$this->update_setting(array('feed'=>'terms', 'global'=>'syndication_terms'), $saveTerms, array());

		if ($this->for_feed_settings()) :
			// Category splitting regex
			if (isset($post['cat_split'])) :
				$this->link->update_setting('cat_split', trim($post['cat_split']), '');
			endif;

			// Treat global terms (cats, tags, etc.) as additional,
			// or as defaults to be overridden and replaced?
			if (isset($post['add_global'])) :
				foreach ($post['add_global'] as $what => $value) :
					$this->link->update_setting("add/$what", $value);
				endforeach;
			endif;
		endif;
		parent::save_settings($post);
	} /* FeedWordPressCategoriesPage::save_settings() */

	function display () {
		////////////////////////////////////////////////
		// Display settings boxes //////////////////////
		////////////////////////////////////////////////

		$this->boxes_by_methods = array(
			'feed_categories_box' => __('Feed Categories & Tags'),
			'categories_box' => array('title' => __('Categories'), 'id' => 'categorydiv'),
		);

		parent::display();
	}
}

	$categoriesPage = new FeedWordPressCategoriesPage;
	$categoriesPage->display();

