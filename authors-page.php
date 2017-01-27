<?php
require_once(dirname(__FILE__) . '/admin-ui.php');

class FeedWordPressAuthorsPage extends FeedWordPressAdminPage {
	var $authorlist = NULL;
	var $rule_count = 0;
	
	public function __construct( $link = -1 ) {
		if (is_numeric($link) and -1 == $link) :
			$link = $this->submitted_link();
		endif;

		parent::__construct('feedwordpressauthors', $link);
		$this->refresh_author_list();
		$this->dispatch = 'feedwordpress_author_settings';
		$this->filename = __FILE__;

		$this->pagenames = array(
			'default' => 'Authors',
			'settings-update' => 'Syndicated Authors',
			'open-sheet' => 'Syndicated Author',
		);
	}
	
	function refresh_author_list () {
		$this->authorlist = fwp_author_list();

		// Case-insensitive "natural" alphanumeric sort. Preserves key/value associations.
		if (function_exists('natcasesort')) : natcasesort($this->authorlist); endif;
	}
	
	/*static*/ function syndicated_authors_box ($page, $box = NULL) {
		$link = $page->link;
		$unfamiliar = array ('create' => '','default' => '','filter' => '');

		if ($page->for_feed_settings()) :
			$key = $this->link->setting('unfamiliar author', NULL, 'site-default');
			$unfamiliar['site-default'] = '';
		else :
			$key = FeedWordPress::on_unfamiliar('author');
		endif;
		$unfamiliar[$key] = ' selected="selected"';

		$match_author_by_email = !('yes' == get_option("feedwordpress_do_not_match_author_by_email"));
		$null_emails = FeedWordPress::null_email_set();

		// Hey ho, let's go...
		?>
<table class="form-table">
<?php
if ($page->for_default_settings()) :
?>
<tr><th>Unmatched authors</th>
<td><span>Authors who haven&#8217;t been syndicated before</span>
  <select style="max-width: 27.0em" id="unfamiliar-author" name="unfamiliar_author" onchange="contextual_appearance('unfamiliar-author', 'unfamiliar-author-newuser', 'unfamiliar-author-default', 'newuser', 'inline');">
    <option value="create"<?php print $unfamiliar['create']; ?>>will have a new author account created for them</option>
    <?php foreach ($page->authorlist as $author_id => $author_name) : ?>
      <option value="<?php echo $author_id; ?>"<?php print (isset($unfamiliar[$author_id]) ? $unfamiliar[$author_id] : ''); ?>>will have their posts attributed to <?php echo $author_name; ?></option>
    <?php endforeach; ?>
    <option value="newuser">will have their posts attributed to a new user...</option>
    <option value="filter"<?php print $unfamiliar['filter'] ?>>get filtered out</option>
  </select>

  <span id="unfamiliar-author-newuser">named <input type="text" name="unfamiliar_author_newuser" value="" /></span></p>
  </td>
</tr>

<?php
endif;

if ($page->for_feed_settings()) :
	$map = $this->link->setting('map authors', NULL, array());
?>
<tr><th>Matching authors</th>
<td><p>How should FeedWordPress attribute posts from this feed to WordPress
authors?</p>
<ul class="settings">
<li><p><input type="radio" name="author_rules_name[all]" value="*"
<?php if (isset($map['name']['*'])) : $author_action = $map['name']['*']; ?>
	checked="checked"
<?php
	else :
		$author_action = NULL;
	endif; ?>
	/> All posts syndicated
from this feed <select class="author-rules" id="author-rules-all"
name="author_rules_action[all]" onchange="contextual_appearance('author-rules-all', 'author-rules-all-newuser', 'author-rules-all-default', 'newuser', 'inline');">
    <?php foreach ($page->authorlist as $local_author_id => $local_author_name) : ?>
    <option value="<?php echo $local_author_id; ?>"<?php if ($local_author_id==$author_action) : echo ' selected="selected"'; endif; ?>>are assigned to <?php echo $local_author_name; ?></option>
    <?php endforeach; ?>
    <option value="newuser">will be assigned to a new user...</option>
    <option value="filter"<?php if ('filter'==$author_action) : echo ' selected="selected"'; endif; ?>>get filtered out</option>
  </select>
  <span class="author-rules-newuser" id="author-rules-all-newuser">named
  <input type="text" name="author_rules_newuser[all]" value="" /></span></p></li>
<li><p><input type="radio" name="author_rules_name[all]" value=""
<?php if (!isset($map['name']['*'])) : ?>
	checked="checked"
<?php endif; ?>
/> Attribute posts to authors based on automatic mapping rules. (Blank out a
name to delete the rule. Fill in a new name at the bottom to create a new rule.)</p>

<table style="width: 100%">
<?php
	if (isset($this->link->settings['map authors'])) :
?>
<?php
		$page->rule_count=0;
		foreach ($this->link->settings['map authors'] as $author_rules) :
			foreach ($author_rules as $author_name => $author_action) :
				if ($author_name != '*') :
					$page->rule_count++; 
?>
<tr>
<th style="text-align: left; width: 15.0em">Posts by <input type="text" name="author_rules_name[]" value="<?php echo htmlspecialchars($author_name); ?>" size="11" /></th>
  <td>
  <select class="author-rules" id="author-rules-<?php echo $page->rule_count; ?>" name="author_rules_action[]" onchange="contextual_appearance('author-rules-<?php echo $page->rule_count; ?>', 'author-rules-<?php echo $page->rule_count; ?>-newuser', 'author-rules-<?php echo $page->rule_count; ?>-default', 'newuser', 'inline');">
    <?php foreach ($page->authorlist as $local_author_id => $local_author_name) : ?>
    <option value="<?php echo $local_author_id; ?>"<?php if ($local_author_id==$author_action) : echo ' selected="selected"'; endif; ?>>are assigned to <?php echo $local_author_name; ?></option>
    <?php endforeach; ?>
    <option value="newuser">will be assigned to a new user...</option>
    <option value="filter"<?php if ('filter'==$author_action) : echo ' selected="selected"'; endif; ?>>get filtered out</option>
  </select>
  
  <span class="author-rules-newuser" id="author-rules-<?php echo $page->rule_count; ?>-newuser">named <input type="text" name="author_rules_newuser[]" value="" /></span>
  </td>
</tr>
<?php
				endif;
			endforeach;
		endforeach;
	endif;
?>

<tr>
<th style="text-align: left; width: 15.0em">Posts by <input type="text" name="add_author_rule_name" size="11" /></th>
  <td>
    <select id="add-author-rule" name="add_author_rule_action" onchange="contextual_appearance('add-author-rule', 'add-author-rule-newuser', 'add-author-rule-default', 'newuser', 'inline');">
      <?php foreach ($page->authorlist as $author_id => $author_name) : ?>
      <option value="<?php echo $author_id; ?>">are assigned to <?php echo $author_name; ?></option>
      <?php endforeach; ?>
      <option value="newuser">will be assigned to a new user...</option>
      <option value="filter">get filtered out</option>
    </select>
   
   <span id="add-author-rule-newuser">named <input type="text" name="add_author_rule_newuser" value="" /></span>
   </td>
</tr>

<tr>
<th style="text-align: left; width: 15.0em">Unmatched authors</th>
<td>
<span>Authors who haven't been syndicated before</span>
  <select style="max-width: 27.0em" id="unfamiliar-author" name="unfamiliar_author" onchange="contextual_appearance('unfamiliar-author', 'unfamiliar-author-newuser', 'unfamiliar-author-default', 'newuser', 'inline');">
<?php if ($page->for_feed_settings()) : ?>
    <option value="site-default"<?php print $unfamiliar['site-default']; ?>>are handled according to the default for all feeds</option>
<?php endif; ?>
    <option value="create"<?php print $unfamiliar['create']; ?>>will have a new author account created for them</option>
    <?php foreach ($page->authorlist as $author_id => $author_name) : ?>
      <option value="<?php echo $author_id; ?>"<?php print (isset($unfamiliar[$author_id]) ? $unfamiliar[$author_id] : ''); ?>>will have their posts attributed to <?php echo $author_name; ?></option>
    <?php endforeach; ?>
    <option value="newuser">will have their posts attributed to a user named ...</option>
    <option value="filter"<?php print $unfamiliar['filter'] ?>>get filtered out</option>
  </select>

  <span id="unfamiliar-author-newuser"><input type="text" name="unfamiliar_author_newuser" value="" /></span></p>
</td>
</tr>
</table></li>
</ul>

</td>
</tr>
<?php endif; ?>

<?php if ($page->for_default_settings()) : ?>
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
<?php endif; ?>
</tbody>
</table>
		<?php
	} /* FeedWordPressAuthorsPage::syndicated_authors_box () */
	
	/*static*/ function fix_authors_box ($page, $box = NULL) {
		?>
		<table class="form-table">
		<tbody>
		<tr>
		<th scope="row">Fixing mis-matched authors:</th>
		<td><p style="margin: 0.5em 0px">Take all the posts from this feed attributed to
		<select name="fix_mismatch_from">
		<?php foreach ($page->authorlist as $author_id => $author_name) : ?>
		      <option value="<?php echo $author_id; ?>"><?php echo $author_name; ?></option>
		<?php endforeach; ?>
		</select>
		and instead
		<select id="fix-mismatch-to" name="fix_mismatch_to" onchange="contextual_appearance('fix-mismatch-to', 'fix-mismatch-to-newuser', null, 'newuser', 'inline');">
		<?php foreach ($page->authorlist as $author_id => $author_name) : ?>
		      <option value="<?php echo $author_id; ?>">re-assign them to <?php echo $author_name; ?></option>
		<?php endforeach; ?>
		      <option value="newuser">re-assign them to a new user...</option>
		      <option value="filter">delete them</option>
		</select>

		   <span id="fix-mismatch-to-newuser">named <input type="text" name="fix_mismatch_to_newuser" value="" /></span>
		   <input type="submit" class="button" name="fix_mismatch" value="Fix it!" />
		</td>
		</tr>
		</tbody>
		</table>
		<?php
	} /* FeedWordPressAuthorsPage::fix_authors_box () */

	function display () {
		$this->boxes_by_methods = array(
		'syndicated_authors_box' => __('Syndicated Authors'),
		'fix_authors_box' => __('Reassign Authors'),
		);
		if ($this->for_default_settings()) :
			unset($this->boxes_by_methods['fix_authors_box']);
		endif;

		parent::display();
		?>
<script type="text/javascript">
	contextual_appearance('unfamiliar-author', 'unfamiliar-author-newuser', 'unfamiliar-author-default', 'newuser', 'inline');
</script>

<?php 		if ($this->for_feed_settings()) : ?>
<script type="text/javascript">
	jQuery('.author-rules').each ( function () {
		contextual_appearance(this.id, this.id+'-newuser', this.id+'-default', 'newuser', 'inline');
	} );

	contextual_appearance('add-author-rule', 'add-author-rule-newuser', 'add-author-rule-default', 'newuser', 'inline');
	contextual_appearance('fix-mismatch-to', 'fix-mismatch-to-newuser', null, 'newuser', 'inline');
</script>
<?php 		else : ?>
<script type="text/javascript">
	contextual_appearance('match-author-by-email', 'unless-null-email', null, 'yes', 'block', /*checkbox=*/ true);
</script>
<?php 		endif;
	} /* FeedWordPressAuthorsPage::display () */

	function accept_POST ($post) {
		if (isset($post['fix_mismatch']) and (strlen($post['fix_mismatch']) > 0)) :
			$this->fix_mismatch($post);
		else :
			parent::accept_POST($post);
		endif;
	}

	function fix_mismatch ($post) {
		global $wpdb;

		if ('newuser'==$post['fix_mismatch_to']) :
			$newuser_name = trim($post['fix_mismatch_to_newuser']);
			$to = fwp_insert_new_user($newuser_name);
		else :
			$to = $post['fix_mismatch_to'];
		endif;

		$from = (int) $post['fix_mismatch_from'];
		if (is_numeric($from)) :
			// Make a list of all the items by this author
			// syndicated from this feed...
			$post_ids = $wpdb->get_col("
			SELECT {$wpdb->posts}.id
			FROM {$wpdb->posts}, {$wpdb->postmeta}
			WHERE ({$wpdb->posts}.id = {$wpdb->postmeta}.post_id)
			AND {$wpdb->postmeta}.meta_key = 'syndication_feed_id'
			AND {$wpdb->postmeta}.meta_value = '{$this->link->id}'
			AND {$wpdb->posts}.post_author = '{$from}'
			");
			
			if (count($post_ids) > 0) :
				$N = count($post_ids);
				$posts = 'post'.(($N==1) ? '' : 's');

				// Re-assign them all to the correct author
				if (is_numeric($to)) : // re-assign to a particular user
					$post_set = "(".implode(",", $post_ids).")";
					
					// Getting the revisions too, if there are any
					$parent_in_clause = "OR {$wpdb->posts}.post_parent IN $post_set";
					
					$wpdb->query("
					UPDATE {$wpdb->posts}
					SET post_author='{$to}'
					WHERE ({$wpdb->posts}.id IN $post_set
					$parent_in_clause)
					");
					$this->mesg = sprintf(__("Re-assigned %d ${posts}."), $N);

				// ... and kill them all
				elseif ('filter'==$to) :
					foreach ($post_ids as $post_id) :
						wp_delete_post($post_id);
					endforeach;
			
					$this->mesg = sprintf(__("Deleted %d ${posts}."), $N);
				endif;
			else :
				$this->mesg = __("Couldn't find any posts that matched your criteria.");
			endif;
		endif;
		$this->updated = false;
	}

	function save_settings ($post) {

		if ($this->for_feed_settings()) :
			$alter = array ();

			// Unfamiliar author rule
			if (isset($post["unfamiliar_author"])) :
				if ('newuser'==$post['unfamiliar_author']) :
					$new_name = trim($post["unfamiliar_author_newuser"]);
					$this->link->map_name_to_new_user(/*name=*/ NULL, $new_name);
				else :
					$this->link->update_setting(
						"unfamiliar author",
						$post['unfamiliar_author'],
						'site-default'
					);
				endif;
			endif;
			
			// Handle author mapping rules
			if (isset($post['author_rules_name'])
			and isset($post['author_rules_action'])) :
				if (isset($post['author_rules_name']['all'])) :
					if (strlen($post['author_rules_name']['all']) > 0) :
						$post['author_rules_name'] = array(
							'all' => $post['author_rules_name']['all'],
						);
						
						// Erase all the rest.
					endif;
				endif;
				
				unset($this->link->settings['map authors']);
				foreach ($post['author_rules_name'] as $key => $name) :
					// Normalize for case and whitespace
					$name = strtolower(trim($name));
					$author_action = strtolower(trim($post['author_rules_action'][$key]));
					
					if (strlen($name) > 0) :
						if ('newuser' == $author_action) :
							$new_name = trim($post['author_rules_newuser'][$key]);
							$this->link->map_name_to_new_user($name, $new_name);
						else :
							$this->link->settings['map authors']['name'][$name] = $author_action;
						endif;
					endif;
				endforeach;
			endif;

			if (isset($post['add_author_rule_name'])
			and isset($post['add_author_rule_action'])) :
				$name = strtolower(trim($post['add_author_rule_name']));
				$author_action = strtolower(trim($post['add_author_rule_action']));
				
				if (strlen($name) > 0) :
					if ('newuser' == $author_action) :
						$new_name = trim($post['add_author_rule_newuser']);
						$this->link->map_name_to_new_user($name, $new_name);
					else :
						$this->link->settings['map authors']['name'][$name] = $author_action;
					endif;
				endif;
			endif;
		else :
			if ('newuser'==$post['unfamiliar_author']) :
				$new_name = trim($post['unfamiliar_author_newuser']);
				$new_id = fwp_insert_new_user($new_name);
				if (is_numeric($new_id)) :
					update_option('feedwordpress_unfamiliar_author', $new_id);
				else :
					// TODO: Add some error detection and reporting
					// Put WP_Error stuff into $this->mesg ?
				endif;
			else :
				update_option('feedwordpress_unfamiliar_author', $post['unfamiliar_author']);
			endif;

			update_option('feedwordpress_do_not_match_author_by_email',
				(isset($post['match_author_by_email'])
				 and 'yes'==$post['match_author_by_email'])
				? 'no'
				: 'yes'
			);

			if (isset($post['null_emails'])) :
				update_option('feedwordpress_null_email_set', $post['null_emails']);
			endif;
		endif;

		parent::save_settings($post);
		$this->refresh_author_list();
	}
} /* class FeedWordPressAuthorsPage */

	$authorsPage = new FeedWordPressAuthorsPage;
	$authorsPage->display();

