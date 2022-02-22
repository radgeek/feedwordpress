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
		$unfamiliar[$key] = true;

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
    <option value="create"<?php fwp_selected_flag($unfamiliar, 'create'); ?>>will have a new author account created for them</option>
    <?php foreach ($page->authorlist as $author_id => $author_name) :
		if (!isset($unfamiliar[$author_id])) : $unfamiliar[$author_id] = false; endif;
	?>
      <option value="<?php echo esc_attr($author_id); ?>"<?php fwp_selected_flag($unfamiliar, $author_id); ?>>will have their posts attributed to <?php echo esc_html($author_name); ?></option>
    <?php endforeach; ?>
    <option value="newuser">will have their posts attributed to a new user...</option>
    <option value="filter"<?php fwp_selected_flag($unfamiliar, 'filter'); ?>>get filtered out</option>
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
    <option value="<?php echo esc_attr($local_author_id); ?>"<?php fwp_selected_flag($local_author_id==$author_action); ?>>are assigned to <?php echo esc_html($local_author_name); ?></option>
    <?php endforeach; ?>
    <option value="newuser">will be assigned to a new user...</option>
    <option value="filter"<?php fwp_selected_flag('filter'==$author_action); ?>>get filtered out</option>
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
					$authorRulesId = sanitize_html_class(sprintf("author-rules-%d", $page->rule_count));
?>
<tr>
<th style="text-align: left; width: 15.0em">Posts by <input type="text" name="author_rules_name[]" value="<?php echo esc_attr($author_name); ?>" size="11" /></th>
  <td>
  <select class="author-rules" id="<?php echo esc_attr($authorRulesId); ?>" name="author_rules_action[]" onchange="contextual_appearance('<?php echo esc_attr($authorRulesId); ?>', '<?php echo esc_attr($authorRulesId); ?>-newuser', '<?php echo esc_attr($authorRulesId); ?>-default', 'newuser', 'inline');">
    <?php foreach ($page->authorlist as $local_author_id => $local_author_name) : ?>
    <option value="<?php echo esc_attr($local_author_id); ?>"<?php fwp_selected_flag($local_author_id==$author_action); ?>>are assigned to <?php echo esc_attr($local_author_name); ?></option>
    <?php endforeach; ?>
    <option value="newuser">will be assigned to a new user...</option>
    <option value="filter"<?php fwp_selected_flag('filter'==$author_action); ?>>get filtered out</option>
  </select>
  
  <span class="author-rules-newuser" id="<?php echo esc_attr($authorRulesId); ?>-newuser">named <input type="text" name="author_rules_newuser[]" value="" /></span>
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
      <option value="<?php echo esc_attr($author_id); ?>">are assigned to <?php echo esc_html($author_name); ?></option>
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
    <option value="site-default"<?php fwp_selected_flag($unfamiliar, 'site-default'); ?>>are handled according to the default for all feeds</option>
<?php endif; ?>
    <option value="create"<?php fwp_selected_flag($unfamiliar, 'create'); ?>>will have a new author account created for them</option>
    <?php foreach ($page->authorlist as $author_id => $author_name) : ?>
      <option value="<?php echo esc_attr($author_id); ?>"<?php fwp_selected_flag($unfamiliar, $author_id); ?>>will have their posts attributed to <?php echo esc_html($author_name); ?></option>
    <?php endforeach; ?>
    <option value="newuser">will have their posts attributed to a user named ...</option>
    <option value="filter"<?php fwp_selected_flag($unfamiliar, 'filter'); ?>>get filtered out</option>
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
<li><div><label><input id="match-author-by-email" type="checkbox" name="match_author_by_email" value="yes" <?php fwp_selected_flag($match_author_by_email, null, "checked"); ?> onchange="contextual_appearance('match-author-by-email', 'unless-null-email', null, 'yes', 'block', /*checkbox=*/ true);" /> Treat syndicated authors with the same e-mail address as the same author.</label></div>
<div id="unless-null-email">
<p>Unless the e-mail address is one of the following anonymous e-mail addresses:</p>
<textarea name="null_emails" rows="3" style="width: 100%">
<?php print esc_html(implode("\n", $null_emails)); ?>
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
		      <option value="<?php echo esc_attr($author_id); ?>"><?php echo esc_html($author_name); ?></option>
		<?php endforeach; ?>
		</select>
		and instead
		<select id="fix-mismatch-to" name="fix_mismatch_to" onchange="contextual_appearance('fix-mismatch-to', 'fix-mismatch-to-newuser', null, 'newuser', 'inline');">
		<?php foreach ($page->authorlist as $author_id => $author_name) : ?>
		      <option value="<?php echo esc_attr($author_id); ?>">re-assign them to <?php echo esc_html($author_name); ?></option>
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

	public function fix_mismatch_requested () {
		$fix = FeedWordPress::post( 'fix_mismatch' );
		return ( ! is_null( $fix ) && strlen( $fix ) > 0 );
	}
	
	function accept_POST () {
		if ( self::fix_mismatch_requested() ) :
			$this->fix_mismatch();
		else :
			parent::accept_POST();
		endif;
	}

	function fix_mismatch () {
		global $wpdb;

		$to = FeedWordPress::post( 'fix_mismatch_to' );
		if ( 'newuser' === $fix_to ) :
			$newuser_name = trim( FeedWordPress::post('fix_mismatch_to_newuser', '' ) );
			$to = fwp_insert_new_user( $newuser_name );
		endif;

		$from = (int) FeedWordPress::post( 'fix_mismatch_from' );
		if ( is_numeric( $from ) ) :

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

	function save_settings () {

		if ($this->for_feed_settings()) :
			$alter = array ();

			// Unfamiliar author rule
			$unfamiliar_author = FeedWordPress::post( 'unfamiliar_author' );
			if ( ! is_null( $unfamiliar_author ) ) :
				if ( 'newuser' == $unfamiliar_author ) :
					$new_name = trim( FeedWordPress::post( "unfamiliar_author_newuser" ) );
					$this->link->map_name_to_new_user(/*name=*/ null, $new_name);
				else :
					$this->link->update_setting(
						"unfamiliar author",
						$unfamiliar_author,
						'site-default'
					);
				endif;
			endif;
			
			// Handle author mapping rules
			$author_rules_name = FeedWordPress::post( 'author_rules_name' );
			$author_rules_action = FeedWordPress::post('author_rules_action' );
			if ( ! ( is_null( $author_rules_name ) || is_null( $author_rules_action ) ) ) :
				if ( isset( $author_rules_name['all'] ) ) :
					if ( strlen( $author_rules_name['all']) > 0 ) :
						$author_rules_name = array(
							'all' => $author_rules_name['all'],
						);
						
						// Erase all the rest.
					endif;
				endif;
				
				unset($this->link->settings['map authors']);
				foreach ($author_rules_name as $key => $name) :
					// Normalize for case and whitespace
					$name = strtolower( trim( $name ) );
					$author_action = strtolower( trim( $author_rules_action[$key] ) );

					$author_rules_newuser = FeedWordPress::post('author_rules_newuser' );
					if ( strlen($name) > 0 ) :
						if ( 'newuser' == $author_action ) :
							$new_name = trim( $author_rules_newuser[ $key ] );
							$this->link->map_name_to_new_user($name, $new_name);
						else :
							$this->link->settings['map authors']['name'][$name] = $author_action;
						endif;
					endif;
				endforeach;
			endif;

			$name = FeedWordPress::post( 'add_author_rule_name');
			$author_action = FeedWordPress::post( 'add_author_rule_action' );
			if ( ! ( is_null( $name ) || is_null( $author_action ) ) ) :
				$name = strtolower( trim( $name ) );
				$author_action = strtolower( trim( $author_action ) );

				if (strlen($name) > 0) :
					if ('newuser' == $author_action) :
						$new_name = trim( FeedWordPress::post( 'add_author_rule_newuser' ));
						$this->link->map_name_to_new_user($name, $new_name);
					else :
						$this->link->settings['map authors']['name'][$name] = $author_action;
					endif;
				endif;
			endif;

		else :

			$unfamiliar_author = FeedWordPress::post( 'unfamiliar_author' );
			if ( 'newuser' === $unfamiliar_author ) :
				$new_name = FeedWordPress::post( 'unfamiliar_author_newuser' );
				$new_id = fwp_insert_new_user( trim( $new_name ) );
				if ( is_numeric( $new_id ) ) :
					update_option( 'feedwordpress_unfamiliar_author', $new_id );
				else :
					// TODO: Add some error detection and reporting
					// Put WP_Error stuff into $this->mesg ?
				endif;
			else :
				update_option( 'feedwordpress_unfamiliar_author', $unfamiliar_author );
			endif;

			$by_email = FeedWordPress::post( 'match_author_by_email' );
			update_option(
				'feedwordpress_do_not_match_author_by_email',
				( isset( $by_email ) && 'yes' == $by_email ) ? 'no' : 'yes'
			);

			$null_emails = FeedWordPress::post( 'null_emails', null, 'textarea' );
			if ( ! is_null( $null_emails ) ) :
				update_option( 'feedwordpress_null_email_set', $null_emails );
			endif;
		endif;

		parent::save_settings();
		$this->refresh_author_list();
	}
} /* class FeedWordPressAuthorsPage */

	$authorsPage = new FeedWordPressAuthorsPage;
	$authorsPage->display();

