<?php
class SyndicatedPost {
	var $item = null;
	
	var $link = null;
	var $feed = null;
	var $feedmeta = null;
	
	var $post = array ();

	var $_freshness = null;
	var $_wp_id = null;

	function SyndicatedPost ($item, $link) {
		global $wpdb;

		$this->link = $link;
		$feedmeta = $link->settings;
		$feed = $link->magpie;

		# This is ugly as all hell. I'd like to use apply_filters()'s
		# alleged support for a variable argument count, but this seems
		# to have been broken in WordPress 1.5. It'll be fixed somehow
		# in WP 1.5.1, but I'm aiming at WP 1.5 compatibility across
		# the board here.
		#
		# Cf.: <http://mosquito.wordpress.org/view.php?id=901>
		global $fwp_channel, $fwp_feedmeta;
		$fwp_channel = $feed; $fwp_feedmeta = $feedmeta;

		$this->feed = $feed;
		$this->feedmeta = $feedmeta;

		$this->item = $item;
		$this->item = apply_filters('syndicated_item', $this->item, $this);

		# Filters can halt further processing by returning NULL
		if (is_null($this->item)) :
			$this->post = NULL;
		else :
			# Note that nothing is run through $wpdb->escape() here.
			# That's deliberate. The escaping is done at the point
			# of insertion, not here, to avoid double-escaping and
			# to avoid screwing with syndicated_post filters

			$this->post['post_title'] = apply_filters('syndicated_item_title', $this->item['title'], $this);

			// This just gives us an alphanumeric representation of
			// the author. We will look up (or create) the numeric
			// ID for the author in SyndicatedPost::add()
			$this->post['named']['author'] = apply_filters('syndicated_item_author', $this->author(), $this);

			# Identify content and sanitize it.
			# ---------------------------------
			if (isset($this->item['atom_content'])) :
				$content = $this->item['atom_content'];
			elseif (isset($this->item['xhtml']['body'])) :
				$content = $this->item['xhtml']['body'];
			elseif (isset($this->item['xhtml']['div'])) :
				$content = $this->item['xhtml']['div'];
			elseif (isset($this->item['content']['encoded']) and $this->item['content']['encoded']):
				$content = $this->item['content']['encoded'];
			else:
				$content = $this->item['description'];
			endif;
			$this->post['post_content'] = apply_filters('syndicated_item_content', $content, $this);

			# Identify and sanitize excerpt
			$excerpt = NULL;
			if ( isset($this->item['description']) and $this->item['description'] ) :
				$excerpt = $this->item['description'];
			elseif ( isset($content) and $content ) :
				$excerpt = strip_tags($content);
				if (strlen($excerpt) > 255) :
					$excerpt = substr($excerpt,0,252).'...';
				endif;
			endif;
			$excerpt = apply_filters('syndicated_item_excerpt', $excerpt, $this); 

			if (!is_null($excerpt)):
				$this->post['post_excerpt'] = $excerpt;
			endif;
			
			// This is unnecessary if we use wp_insert_post
			if (!$this->use_api('wp_insert_post')) :
				$this->post['post_name'] = sanitize_title($this->post['post_title']);
			endif;

			$this->post['epoch']['issued'] = apply_filters('syndicated_item_published', $this->published(), $this);
			$this->post['epoch']['created'] = apply_filters('syndicated_item_created', $this->created(), $this);
			$this->post['epoch']['modified'] = apply_filters('syndicated_item_updated', $this->updated(), $this);

			// Dealing with timestamps in WordPress is so fucking fucked.
			$offset = (int) get_option('gmt_offset') * 60 * 60;
			$this->post['post_date'] = gmdate('Y-m-d H:i:s', $this->published() + $offset);
			$this->post['post_modified'] = gmdate('Y-m-d H:i:s', $this->updated() + $offset);
			$this->post['post_date_gmt'] = gmdate('Y-m-d H:i:s', $this->published());
			$this->post['post_modified_gmt'] = gmdate('Y-m-d H:i:s', $this->updated());

			// Use feed-level preferences or the global default.
			$this->post['post_status'] = $this->link->syndicated_status('post', 'publish');
			$this->post['comment_status'] = $this->link->syndicated_status('comment', 'closed');
			$this->post['ping_status'] = $this->link->syndicated_status('ping', 'closed');

			// Unique ID (hopefully a unique tag: URI); failing that, the permalink
			$this->post['guid'] = apply_filters('syndicated_item_guid', $this->guid(), $this);

			// User-supplied custom settings to apply to each post. Do first so that FWP-generated custom settings will overwrite if necessary; thus preventing any munging
			$default_custom_settings = get_option('feedwordpress_custom_settings');
			if ($default_custom_settings and !is_array($default_custom_settings)) :
				$default_custom_settings = unserialize($default_custom_settings);
			endif;
			if (!is_array($default_custom_settings)) :
				$default_custom_settings = array();
			endif;
			
			$custom_settings = (isset($this->link->settings['postmeta']) ? $this->link->settings['postmeta'] : null);
			if ($custom_settings and !is_array($custom_settings)) :
				$custom_settings = unserialize($custom_settings);
			endif;
			if (!is_array($custom_settings)) :
				$custom_settings = array();
			endif;
			$this->post['meta'] = array_merge($default_custom_settings, $custom_settings);

			// RSS 2.0 / Atom 1.0 enclosure support
			if ( isset($this->item['enclosure#']) ) :
				for ($i = 1; $i <= $this->item['enclosure#']; $i++) :
					$eid = (($i > 1) ? "#{$id}" : "");
					$this->post['meta']['enclosure'][] =
						apply_filters('syndicated_item_enclosure_url', $this->item["enclosure{$eid}@url"], $this)."\n".
						apply_filters('syndicated_item_enclosure_length', $this->item["enclosure{$eid}@length"], $this)."\n".
						apply_filters('syndicated_item_enclosure_type', $this->item["enclosure{$eid}@type"], $this);
				endfor;
			endif;

			// In case you want to point back to the blog this was syndicated from
			if (isset($this->feed->channel['title'])) :
				$this->post['meta']['syndication_source'] = apply_filters('syndicated_item_source_title', $this->feed->channel['title'], $this);
			endif;

			if (isset($this->feed->channel['link'])) :
				$this->post['meta']['syndication_source_uri'] = apply_filters('syndicated_item_source_link', $this->feed->channel['link'], $this);
			endif;
			
			// Make use of atom:source data, if present in an aggregated feed
			if (isset($this->item['source_title'])) :
				$this->post['meta']['syndication_source_original'] = $this->item['source_title'];
			endif;

			if (isset($this->item['source_link'])) :
				$this->post['meta']['syndication_source_uri_original'] = $this->item['source_link'];
			endif;
			
			if (isset($this->item['source_id'])) :
				$this->post['meta']['syndication_source_id_original'] = $this->item['source_id'];
			endif;

			// Store information on human-readable and machine-readable comment URIs
			if (isset($this->item['comments'])) :
				$this->post['meta']['rss:comments'] = apply_filters('syndicated_item_comments', $this->item['comments']);
			endif;
			
			// RSS 2.0 comment feeds extension
			if (isset($this->item['wfw']['commentrss'])) :
				$this->post['meta']['wfw:commentRSS'] = apply_filters('syndicated_item_commentrss', $this->item['wfw']['commentrss']);
			endif;

			// Atom 1.0 comment feeds link-rel
			if (isset($this->item['link_replies'])) :
				// There may be multiple <link rel="replies"> elements; feeds have a feed MIME type
				$N = isset($this->item['link_replies#']) ? $this->item['link_replies#'] : 1;
				for ($i = 1; $i <= $N; $i++) :
					$currentElement = 'link_replies'.(($i > 1) ? '#'.$i : '');
					if (isset($this->item[$currentElement.'@type'])
					and preg_match("\007application/(atom|rss|rdf)\+xml\007i", $this->item[$currentElement.'@type'])) :
						$this->post['meta']['wfw:commentRSS'] = apply_filters('syndicated_item_commentrss', $this->item[$currentElement]);
					endif;
				endfor;
			endif;

			// Store information to identify the feed that this came from
			if (isset($this->feedmeta['link/uri'])) :
				$this->post['meta']['syndication_feed'] = $this->feedmeta['link/uri'];
			endif;
			if (isset($this->feedmeta['link/id'])) :
				$this->post['meta']['syndication_feed_id'] = $this->feedmeta['link/id'];
			endif;

			if (isset($this->item['source_link_self'])) :
				$this->post['meta']['syndication_feed_original'] = $this->item['source_link_self'];
			endif;

			// In case you want to know the external permalink...
			if (isset($this->item['link'])) :
				$permalink = $this->item['link'];

			// No <link> element. See if this feed has <guid isPermalink="true"> ....
			elseif (isset($this->item['guid'])) :
				if (isset($this->item['guid@ispermalink']) and strtolower(trim($this->item['guid@ispermalink'])) != 'false') :
					$permalink = $this->item['guid'];
				endif;
			endif;

			$this->post['meta']['syndication_permalink'] = apply_filters('syndicated_item_link', $permalink);

			// Store a hash of the post content for checking whether something needs to be updated
			$this->post['meta']['syndication_item_hash'] = $this->update_hash();

			// Feed-by-feed options for author and category creation
			$this->post['named']['unfamiliar']['author'] = (isset($this->feedmeta['unfamiliar author']) ? $this->feedmeta['unfamiliar author'] : null);
			$this->post['named']['unfamiliar']['category'] = (isset($this->feedmeta['unfamiliar category']) ? $this->feedmeta['unfamiliar category'] : null);

			// Categories: start with default categories, if any
			$fc = get_option("feedwordpress_syndication_cats");
			if ($fc) :
				$this->post['named']['preset/category'] = explode("\n", $fc);
			else :
				$this->post['named']['preset/category'] = array();
			endif;

			if (isset($this->feedmeta['cats']) and is_array($this->feedmeta['cats'])) :
				$this->post['named']['preset/category'] = array_merge($this->post['named']['preset/category'], $this->feedmeta['cats']);
			endif;

			// Now add categories from the post, if we have 'em
			$this->post['named']['category'] = array();
			if ( isset($this->item['category#']) ) :
				for ($i = 1; $i <= $this->item['category#']; $i++) :
					$cat_idx = (($i > 1) ? "#{$i}" : "");
					$cat = $this->item["category{$cat_idx}"];

					if ( isset($this->feedmeta['cat_split']) and strlen($this->feedmeta['cat_split']) > 0) :
						$pcre = "\007".$this->feedmeta['cat_split']."\007";
						$this->post['named']['category'] = array_merge($this->post['named']['category'], preg_split($pcre, $cat, -1 /*=no limit*/, PREG_SPLIT_NO_EMPTY));
					else :
						$this->post['named']['category'][] = $cat;
					endif;
				endfor;
			endif;
			$this->post['named']['category'] = apply_filters('syndicated_item_categories', $this->post['named']['category'], $this);
			
			// Tags: start with default tags, if any
			$ft = get_option("feedwordpress_syndication_tags");
			if ($ft) :
				$this->post['tags_input'] = explode(FEEDWORDPRESS_CAT_SEPARATOR, $ft);
			else :
				$this->post['tags_input'] = array();
			endif;
			
			if (isset($this->feedmeta['tags']) and is_array($this->feedmeta['tags'])) :
				$this->post['tags_input'] = array_merge($this->post['tags_input'], $this->feedmeta['tags']);
			endif;
			$this->post['tags_input'] = apply_filters('syndicated_item_tags', $this->post['tags_input'], $this);
		endif;
	} // SyndicatedPost::SyndicatedPost()

	function filtered () {
		return is_null($this->post);
	}

	function freshness () {
		global $wpdb;

		if ($this->filtered()) : // This should never happen.
			FeedWordPress::critical_bug('SyndicatedPost', $this, __LINE__);
		endif;
		
		if (is_null($this->_freshness)) :
			$guid = $wpdb->escape($this->guid());

			$result = $wpdb->get_row("
			SELECT id, guid, post_modified_gmt
			FROM $wpdb->posts WHERE guid='$guid'
			");

			if (!$result) :
				$this->_freshness = 2; // New content
			else:
				$stored_update_hashes = get_post_custom_values('syndication_item_hash', $result->id);
				if (count($stored_update_hashes) > 0) :
					$stored_update_hash = $stored_update_hashes[0];
					$update_hash_changed = ($stored_update_hash != $this->update_hash());
				else :
					$update_hash_changed = false;
				endif;

				preg_match('/([0-9]+)-([0-9]+)-([0-9]+) ([0-9]+):([0-9]+):([0-9]+)/', $result->post_modified_gmt, $backref);

				$last_rev_ts = gmmktime($backref[4], $backref[5], $backref[6], $backref[2], $backref[3], $backref[1]);
				$updated_ts = $this->updated(/*fallback=*/ true, /*default=*/ NULL);
				
				$frozen_values = get_post_custom_values('_syndication_freeze_updates', $result->id);
				$frozen_post = (count($frozen_values) > 0 and 'yes' == $frozen_values[0]);
				$frozen_feed = ('yes' == $this->link->setting('freeze updates', 'freeze_updates', NULL));

				// Check timestamps...
				$updated = (
					!is_null($updated_ts)
					and ($updated_ts > $last_rev_ts)
				);
				
			
				// Or the hash...
				$updated = ($updated or $update_hash_changed);
				
				// But only if the post is not frozen.
				$updated = (
					$updated
					and !$frozen_post
					and !$frozen_feed
				); 
				
				if ($updated) :
					$this->_freshness = 1; // Updated content
					$this->_wp_id = $result->id;
				else :
					$this->_freshness = 0; // Same old, same old
					$this->_wp_id = $result->id;
				endif;
			endif;
		endif;
		return $this->_freshness;
	}

	function wp_id () {
		if ($this->filtered()) : // This should never happen.
			FeedWordPress::critical_bug('SyndicatedPost', $this, __LINE__);
		endif;
		
		if (is_null($this->_wp_id) and is_null($this->_freshness)) :
			$fresh = $this->freshness(); // sets WP DB id in the process
		endif;
		return $this->_wp_id;
	}

	function store () {
		global $wpdb;

		if ($this->filtered()) : // This should never happen.
			FeedWordPress::critical_bug('SyndicatedPost', $this, __LINE__);
		endif;
		
		$freshness = $this->freshness();
		if ($freshness > 0) :
			# -- Look up, or create, numeric ID for author
			$this->post['post_author'] = $this->author_id (
				FeedWordPress::on_unfamiliar('author', $this->post['named']['unfamiliar']['author'])
			);

			if (is_null($this->post['post_author'])) :
				$this->post = NULL;
			endif;
		endif;
		
		if (!$this->filtered() and $freshness > 0) :
			# -- Look up, or create, numeric ID for categories
			list($pcats, $ptags) = $this->category_ids (
				$this->post['named']['category'],
				FeedWordPress::on_unfamiliar('category', $this->post['named']['unfamiliar']['category']),
				/*tags_too=*/ true
			);

			$this->post['post_category'] = $pcats;
			$this->post['tags_input'] = array_merge($this->post['tags_input'], $ptags);

			if (is_null($this->post['post_category'])) :
				// filter mode on, no matching categories; drop the post
				$this->post = NULL;
			else :
				// filter mode off or at least one match; now add on the feed and global presets
				$this->post['post_category'] = array_merge (
					$this->post['post_category'],
					$this->category_ids (
						$this->post['named']['preset/category'],
						'default'
					)
				);

				if (count($this->post['post_category']) < 1) :
					$this->post['post_category'][] = 1; // Default to category 1 ("Uncategorized" / "General") if nothing else
				endif;
			endif;
		endif;
		
		if (!$this->filtered() and $freshness > 0) :
			unset($this->post['named']);
			$this->post = apply_filters('syndicated_post', $this->post, $this);
		endif;
		
		if (!$this->filtered() and $freshness == 2) :
			// The item has not yet been added. So let's add it.
			$this->insert_new();
			$this->add_rss_meta();
			do_action('post_syndicated_item', $this->wp_id(), $this);

			$ret = 'new';
		elseif (!$this->filtered() and $freshness == 1) :
			$this->post['ID'] = $this->wp_id();
			$this->update_existing();
			$this->add_rss_meta();
			do_action('update_syndicated_item', $this->wp_id(), $this);

			$ret = 'updated';			
		else :
			$ret = false;
		endif;
		
		return $ret;
	} // function SyndicatedPost::store ()
	
	function insert_new () {
		global $wpdb, $wp_db_version;

		$dbpost = $this->normalize_post(/*new=*/ true);
		if (!is_null($dbpost)) :
			if ($this->use_api('wp_insert_post')) :
				$dbpost['post_pingback'] = false; // Tell WP 2.1 and 2.2 not to process for pingbacks
	
				// This is a ridiculous fucking kludge necessitated by WordPress 2.6 munging authorship meta-data
				add_action('_wp_put_post_revision', array($this, 'fix_revision_meta'));
				
				// Kludge to prevent kses filters from stripping the
				// content of posts when updating without a logged in
				// user who has `unfiltered_html` capability.
				add_filter('content_save_pre', array($this, 'avoid_kses_munge'), 11);
				
				$this->_wp_id = wp_insert_post($dbpost);
	
				// Turn off ridiculous fucking kludges #1 and #2
				remove_action('_wp_put_post_revision', array($this, 'fix_revision_meta'));
				remove_filter('content_save_pre', array($this, 'avoid_kses_munge'), 11);
	
				$this->validate_post_id($dbpost, array(__CLASS__, __FUNCTION__));
	
				// Unfortunately, as of WordPress 2.3, wp_insert_post()
				// *still* offers no way to use a guid of your choice,
				// and munges your post modified timestamp, too.
				$result = $wpdb->query("
					UPDATE $wpdb->posts
					SET
						guid='{$dbpost['guid']}',
						post_modified='{$dbpost['post_modified']}',
						post_modified_gmt='{$dbpost['post_modified_gmt']}'
					WHERE ID='{$this->_wp_id}'
				");
			else :
				# The right way to do this is the above. But, alas,
				# in earlier versions of WordPress, wp_insert_post has
				# too much behavior (mainly related to pings) that can't
				# be overridden. In WordPress 1.5, it's enough of a
				# resource hog to make PHP segfault after inserting
				# 50-100 posts. This can get pretty annoying, especially
				# if you are trying to update your feeds for the first
				# time.
	
				$result = $wpdb->query("
				INSERT INTO $wpdb->posts
				SET
					guid = '{$dbpost['guid']}',
					post_author = '{$dbpost['post_author']}',
					post_date = '{$dbpost['post_date']}',
					post_date_gmt = '{$dbpost['post_date_gmt']}',
					post_content = '{$dbpost['post_content']}',"
					.(isset($dbpost['post_excerpt']) ? "post_excerpt = '{$dbpost['post_excerpt']}'," : "")."
					post_title = '{$dbpost['post_title']}',
					post_name = '{$dbpost['post_name']}',
					post_modified = '{$dbpost['post_modified']}',
					post_modified_gmt = '{$dbpost['post_modified_gmt']}',
					comment_status = '{$dbpost['comment_status']}',
					ping_status = '{$dbpost['ping_status']}',
					post_status = '{$dbpost['post_status']}'
				");
				$this->_wp_id = $wpdb->insert_id;
	
				$this->validate_post_id($dbpost, array(__CLASS__, __FUNCTION__));
	
				// WordPress 1.5.x - 2.0.x
				wp_set_post_cats('1', $this->wp_id(), $this->post['post_category']);
		
				// Since we are not going through official channels, we need to
				// manually tell WordPress that we've published a new post.
				// We need to make sure to do this in order for FeedWordPress
				// to play well  with the staticize-reloaded plugin (something
				// that a large aggregator website is going to *want* to be
				// able to use).
				do_action('publish_post', $this->_wp_id);
			endif;
		endif;
	} /* SyndicatedPost::insert_new() */

	function update_existing () {
		global $wpdb;

		// Why the fuck doesn't wp_insert_post already do this?
		$dbpost = $this->normalize_post(/*new=*/ false);
		if (!is_null($dbpost)) :
			if ($this->use_api('wp_insert_post')) :
				$dbpost['post_pingback'] = false; // Tell WP 2.1 and 2.2 not to process for pingbacks
	
				// This is a ridiculous fucking kludge necessitated by WordPress 2.6 munging authorship meta-data
				add_action('_wp_put_post_revision', array($this, 'fix_revision_meta'));
	
				// Kludge to prevent kses filters from stripping the
				// content of posts when updating without a logged in
				// user who has `unfiltered_html` capability.
				add_filter('content_save_pre', array($this, 'avoid_kses_munge'), 11);

				// Don't munge status fields that the user may have reset manually
				if (function_exists('get_post_field')) :
					$doNotMunge = array('post_status', 'comment_status', 'ping_status');
					foreach ($doNotMunge as $field) :
						$dbpost[$field] = get_post_field($field, $this->wp_id());
					endforeach;
				endif;

				$this->_wp_id = wp_insert_post($dbpost);
	
				// Turn off ridiculous fucking kludges #1 and #2
				remove_action('_wp_put_post_revision', array($this, 'fix_revision_meta'));
				remove_filter('content_save_pre', array($this, 'avoid_kses_munge'), 11);
	
				$this->validate_post_id($dbpost, array(__CLASS__, __FUNCTION__));
	
				// Unfortunately, as of WordPress 2.3, wp_insert_post()
				// munges your post modified timestamp.
				$result = $wpdb->query("
					UPDATE $wpdb->posts
					SET
						post_modified='{$dbpost['post_modified']}',
						post_modified_gmt='{$dbpost['post_modified_gmt']}'
					WHERE ID='{$this->_wp_id}'
				");
			else :
	
				$result = $wpdb->query("
				UPDATE $wpdb->posts
				SET
					post_author = '{$dbpost['post_author']}',
					post_content = '{$dbpost['post_content']}',"
					.(isset($dbpost['post_excerpt']) ? "post_excerpt = '{$dbpost['post_excerpt']}'," : "")."
					post_title = '{$dbpost['post_title']}',
					post_name = '{$dbpost['post_name']}',
					post_modified = '{$dbpost['post_modified']}',
					post_modified_gmt = '{$dbpost['post_modified_gmt']}'
				WHERE guid='{$dbpost['guid']}'
				");
		
				// WordPress 2.1.x and up
				if (function_exists('wp_set_post_categories')) :
					wp_set_post_categories($this->wp_id(), $this->post['post_category']);
				// WordPress 1.5.x - 2.0.x
				elseif (function_exists('wp_set_post_cats')) :
					wp_set_post_cats('1', $this->wp_id(), $this->post['post_category']);
				// This should never happen.
				else :
					FeedWordPress::critical_bug(__CLASS__.'::'.__FUNCTION.'(): no post categorizing function', array("dbpost" => $dbpost, "this" => $this), __LINE__);
				endif;
		
				// Since we are not going through official channels, we need to
				// manually tell WordPress that we've published a new post.
				// We need to make sure to do this in order for FeedWordPress
				// to play well  with the staticize-reloaded plugin (something
				// that a large aggregator website is going to *want* to be
				// able to use).
				do_action('edit_post', $this->post['ID']);
			endif;
		endif;
	} /* SyndicatedPost::update_existing() */

	/**
	 * SyndicatedPost::normalize_post()
	 *
	 * @param bool $new If true, this post is to be inserted anew. If false, it is an update of an existing post.
	 * @return array A normalized representation of the post ready to be inserted into the database or sent to the WordPress API functions
	 */
	function normalize_post ($new = true) {
		global $wpdb;

		$out = array();

		// Why the fuck doesn't wp_insert_post already do this?
		foreach ($this->post as $key => $value) :
			if (is_string($value)) :
				$out[$key] = $wpdb->escape($value);
			else :
				$out[$key] = $value;
			endif;
		endforeach;

		if (strlen($out['post_title'].$out['post_content'].$out['post_excerpt']) == 0) :
			// FIXME: Option for filtering out empty posts
		endif;
		if (strlen($out['post_title'])==0) :
			$offset = (int) get_option('gmt_offset') * 60 * 60;
			$out['post_title'] =
				$this->post['meta']['syndication_source']
				.' '.gmdate('Y-m-d H:i:s', $this->published() + $offset);
			// FIXME: Option for what to fill a blank title with...
		endif;

		return $out;
	}

	/**
	 * SyndicatedPost::validate_post_id()
	 *
	 * @param array $dbpost An array representing the post we attempted to insert or update
	 * @param mixed $ns A string or array representing the namespace (class, method) whence this method was called.
	 */
	function validate_post_id ($dbpost, $ns) {
		if (is_array($ns)) : $ns = implode('::', $ns);
		else : $ns = (string) $ns; endif;
		
		// This should never happen.
		if (!is_numeric($this->_wp_id) or ($this->_wp_id == 0)) :
			FeedWordPress::critical_bug(
				/*name=*/ $ns.'::_wp_id',
				/*var =*/ array(
					"\$this->_wp_id" => $this->_wp_id,
					"\$dbpost" => $dbpost,
					"\$this" => $this
				),
				/*line # =*/ __LINE__
			);
		endif;
	} /* SyndicatedPost::validate_post_id() */
	
	/**
	 * SyndicatedPost::fix_revision_meta() - Fixes the way WP 2.6+ fucks up
	 * meta-data (authorship, etc.) when storing revisions of an updated
	 * syndicated post.
	 *
	 * In their infinite wisdom, the WordPress coders have made it completely
	 * impossible for a plugin that uses wp_insert_post() to set certain
	 * meta-data (such as the author) when you store an old revision of an
	 * updated post. Instead, it uses the WordPress defaults (= currently
	 * active user ID if the process is running with a user logged in, or
	 * = #0 if there is no user logged in). This results in bogus authorship
	 * data for revisions that are syndicated from off the feed, unless we
	 * use a ridiculous kludge like this to end-run the munging of meta-data
	 * by _wp_put_post_revision.
	 *
	 * @param int $revision_id The revision ID to fix up meta-data
	 */
	function fix_revision_meta ($revision_id) {
		global $wpdb;
		
		$post_author = (int) $this->post['post_author'];
		
		$revision_id = (int) $revision_id;
		$wpdb->query("
		UPDATE $wpdb->posts
		SET post_author={$this->post['post_author']}
		WHERE post_type = 'revision' AND ID='$revision_id'
		");
	} /* SyndicatedPost::fix_revision_meta () */

	/**
	 * SyndicatedPost::avoid_kses_munge() -- If FeedWordPress is processing
	 * an automatic update, that generally means that wp_insert_post() is
	 * being called under the user credentials of whoever is viewing the
	 * blog at the time -- usually meaning no user at all. But if WordPress
	 * gets a wp_insert_post() when current_user_can('unfiltered_html') is
	 * false, it will run the content of the post through a kses function
	 * that strips out lots of HTML tags -- notably <object> and some others.
	 * This causes problems for syndicating (for example) feeds that contain
	 * YouTube videos. It also produces an unexpected asymmetry between
	 * automatically-initiated updates and updates initiated manually from
	 * the WordPress Dashboard (which are usually initiated under the
	 * credentials of a logged-in admin, and so don't get run through the
	 * kses function). So, to avoid the whole mess, what we do here is
	 * just forcibly disable the kses munging for a single syndicated post,
	 * by restoring the contents of the `post_content` field.
	 *
	 * @param string $content The content of the post, after other filters have gotten to it
	 * @return string The original content of the post, before other filters had a chance to munge it.
	 */
	 function avoid_kses_munge ($content) {
		 global $wpdb;
		 return $wpdb->escape($this->post['post_content']);
	 }
	 
	// SyndicatedPost::add_rss_meta: adds interesting meta-data to each entry
	// using the space for custom keys. The set of keys and values to add is
	// specified by the keys and values of $post['meta']. This is used to
	// store anything that the WordPress user might want to access from a
	// template concerning the post's original source that isn't provided
	// for by standard WP meta-data (i.e., any interesting data about the
	// syndicated post other than author, title, timestamp, categories, and
	// guid). It's also used to hook into WordPress's support for
	// enclosures.
	function add_rss_meta () {
		global $wpdb;
		if ( is_array($this->post) and isset($this->post['meta']) and is_array($this->post['meta']) ) :
			$postId = $this->wp_id();
			
			// Aggregated posts should NOT send out pingbacks.
			// WordPress 2.1-2.2 claim you can tell them not to
			// using $post_pingback, but they don't listen, so we
			// make sure here.
			$result = $wpdb->query("
			DELETE FROM $wpdb->postmeta
			WHERE post_id='$postId' AND meta_key='_pingme'
			");

			foreach ( $this->post['meta'] as $key => $values ) :

				$key = $wpdb->escape($key);

				// If this is an update, clear out the old
				// values to avoid duplication.
				$result = $wpdb->query("
				DELETE FROM $wpdb->postmeta
				WHERE post_id='$postId' AND meta_key='$key'
				");

				// Allow for either a single value or an array
				if (!is_array($values)) $values = array($values);
				foreach ( $values as $value ) :
					$value = $wpdb->escape($value);
					$result = $wpdb->query("
					INSERT INTO $wpdb->postmeta
					SET
						post_id='$postId',
						meta_key='$key',
						meta_value='$value'
					");
					if (!$result) :
						$err = mysql_error();
						if (FEEDWORDPRESS_DEBUG) :
							echo "[DEBUG:".date('Y-m-d H:i:S')."][feedwordpress]: post metadata insertion FAILED for field '$key' := '$value': [$err]";
						endif;
					endif;
				endforeach;
			endforeach;
		endif;
	} /* SyndicatedPost::add_rss_meta () */

	// SyndicatedPost::author_id (): get the ID for an author name from
	// the feed. Create the author if necessary.
	function author_id ($unfamiliar_author = 'create') {
		global $wpdb;

		$a = $this->author();
		$author = $a['name'];
		$email = (isset($a['email']) ? $a['email'] : NULL);
		$url = (isset($a['uri']) ? $a['uri'] : NULL);

		$match_author_by_email = !('yes' == get_option("feedwordpress_do_not_match_author_by_email"));
		if ($match_author_by_email and !FeedWordPress::is_null_email($email)) :
			$test_email = $email;
		else :
			$test_email = NULL;
		endif;

		// Never can be too careful...
		$login = sanitize_user($author, /*strict=*/ true);
		$login = apply_filters('pre_user_login', $login);

		$nice_author = sanitize_title($author);
		$nice_author = apply_filters('pre_user_nicename', $nice_author);

		$reg_author = $wpdb->escape(preg_quote($author));
		$author = $wpdb->escape($author);
		$email = $wpdb->escape($email);
		$test_email = $wpdb->escape($test_email);
		$url = $wpdb->escape($url);

		// Check for an existing author rule....
		if (isset($this->link->settings['map authors']['name'][strtolower(trim($author))])) :
			$author_rule = $this->link->settings['map authors']['name'][strtolower(trim($author))];
		else :
			$author_rule = NULL;
		endif;

		// User name is mapped to a particular author. If that author ID exists, use it.
		if (is_numeric($author_rule) and get_userdata((int) $author_rule)) :
			$id = (int) $author_rule;

		// User name is filtered out
		elseif ('filter' == $author_rule) :
			$id = NULL;
		
		else :
			// Check the database for an existing author record that might fit

			#-- WordPress 2.0+
			if (fwp_test_wp_version(FWP_SCHEMA_HAS_USERMETA)) :

				// First try the user core data table.
				$id = $wpdb->get_var(
				"SELECT ID FROM $wpdb->users
				WHERE
					TRIM(LCASE(user_login)) = TRIM(LCASE('$login'))
					OR (
						LENGTH(TRIM(LCASE(user_email))) > 0
						AND TRIM(LCASE(user_email)) = TRIM(LCASE('$test_email'))
					)
					OR TRIM(LCASE(user_nicename)) = TRIM(LCASE('$nice_author'))
				");
	
				// If that fails, look for aliases in the user meta data table
				if (is_null($id)) :
					$id = $wpdb->get_var(
					"SELECT user_id FROM $wpdb->usermeta
					WHERE
						(meta_key = 'description' AND TRIM(LCASE(meta_value)) = TRIM(LCASE('$author')))
						OR (
							meta_key = 'description'
							AND TRIM(LCASE(meta_value))
							RLIKE CONCAT(
								'(^|\\n)a\\.?k\\.?a\\.?( |\\t)*:?( |\\t)*',
								TRIM(LCASE('$reg_author')),
								'( |\\t|\\r)*(\\n|\$)'
							)
						)
					");
				endif;

			#-- WordPress 1.5.x
			else :
				$id = $wpdb->get_var(
				"SELECT ID from $wpdb->users
				 WHERE
					TRIM(LCASE(user_login)) = TRIM(LCASE('$login')) OR
					(
						LENGTH(TRIM(LCASE(user_email))) > 0
						AND TRIM(LCASE(user_email)) = TRIM(LCASE('$test_email'))
					) OR
					TRIM(LCASE(user_firstname)) = TRIM(LCASE('$author')) OR
					TRIM(LCASE(user_nickname)) = TRIM(LCASE('$author')) OR
					TRIM(LCASE(user_nicename)) = TRIM(LCASE('$nice_author')) OR
					TRIM(LCASE(user_description)) = TRIM(LCASE('$author')) OR
					(
						LOWER(user_description)
						RLIKE CONCAT(
							'(^|\\n)a\\.?k\\.?a\\.?( |\\t)*:?( |\\t)*',
							LCASE('$reg_author'),
							'( |\\t|\\r)*(\\n|\$)'
						)
					)
				");

			endif;

			// ... if you don't find one, then do what you need to do
			if (is_null($id)) :
				if ($unfamiliar_author === 'create') :
					$userdata = array();

					#-- user table data
					$userdata['ID'] = NULL; // new user
					$userdata['user_login'] = $login;
					$userdata['user_nicename'] = $nice_author;
					$userdata['user_pass'] = substr(md5(uniqid(microtime())), 0, 6); // just something random to lock it up
					$userdata['user_email'] = $email;
					$userdata['user_url'] = $url;
					$userdata['display_name'] = $author;
	
					$id = wp_insert_user($userdata);
				elseif (is_numeric($unfamiliar_author) and get_userdata((int) $unfamiliar_author)) :
					$id = (int) $unfamiliar_author;
				elseif ($unfamiliar_author === 'default') :
					$id = 1;
				endif;
			endif;
		endif;

		if ($id) :
			$this->link->settings['map authors']['name'][strtolower(trim($author))] = $id;
		endif;
		return $id;	
	} // function SyndicatedPost::author_id ()

	// look up (and create) category ids from a list of categories
	function category_ids ($cats, $unfamiliar_category = 'create', $tags_too = false) {
		global $wpdb;

		// We need to normalize whitespace because (1) trailing
		// whitespace can cause PHP and MySQL not to see eye to eye on
		// VARCHAR comparisons for some versions of MySQL (cf.
		// <http://dev.mysql.com/doc/mysql/en/char.html>), and (2)
		// because I doubt most people want to make a semantic
		// distinction between 'Computers' and 'Computers  '
		$cats = array_map('trim', $cats);

		$tags = array();

		$cat_ids = array ();
		foreach ($cats as $cat_name) :
			if (preg_match('/^{#([0-9]+)}$/', $cat_name, $backref)) :
				$cat_id = (int) $backref[1];
				if (function_exists('is_term') and is_term($cat_id, 'category')) :
					$cat_ids[] = $cat_id;
				elseif (get_category($cat_id)) :
					$cat_ids[] = $cat_id;
				endif;
			elseif (strlen($cat_name) > 0) :
				$esc = $wpdb->escape($cat_name);
				$resc = $wpdb->escape(preg_quote($cat_name));
				
				// WordPress 2.3+
				if (function_exists('is_term')) :
					$cat_id = is_term($cat_name, 'category');
					if ($cat_id) :
						$cat_ids[] = $cat_id['term_id'];
					// There must be a better way to do this...
					elseif ($results = $wpdb->get_results(
						"SELECT	term_id
						FROM $wpdb->term_taxonomy
						WHERE
							LOWER(description) RLIKE
							CONCAT('(^|\\n)a\\.?k\\.?a\\.?( |\\t)*:?( |\\t)*', LOWER('{$resc}'), '( |\\t|\\r)*(\\n|\$)')"
					)) :
						foreach ($results AS $term) :
							$cat_ids[] = (int) $term->term_id;
						endforeach;
					elseif ('tag'==$unfamiliar_category) :
						$tags[] = $cat_name;
					elseif ('create'===$unfamiliar_category) :
						$term = wp_insert_term($cat_name, 'category');
						if (is_wp_error($term)) :
							FeedWordPress::noncritical_bug('term insertion problem', array('cat_name' => $cat_name, 'term' => $term, 'this' => $this), __LINE__);
						else :
							$cat_ids[] = $term['term_id'];
						endif;
					endif;
				
				// WordPress 1.5.x - 2.2.x
				else :
					$results = $wpdb->get_results(
					"SELECT cat_ID
					FROM $wpdb->categories
					WHERE
					  (LOWER(cat_name) = LOWER('$esc'))
					  OR (LOWER(category_description)
					  RLIKE CONCAT('(^|\\n)a\\.?k\\.?a\\.?( |\\t)*:?( |\\t)*', LOWER('{$resc}'), '( |\\t|\\r)*(\\n|\$)'))
					");
					if ($results) :
						foreach  ($results as $term) :
							$cat_ids[] = (int) $term->cat_ID;
						endforeach;
					elseif ('create'===$unfamiliar_category) :
						if (function_exists('wp_insert_category')) :
							$cat_id = wp_insert_category(array('cat_name' => $esc));
						// And into the database we go.
						else :
							$nice_kitty = sanitize_title($cat_name);
							$wpdb->query(sprintf("
								INSERT INTO $wpdb->categories
								SET
								  cat_name='%s',
								  category_nicename='%s'
								", $esc, $nice_kitty
							));
							$cat_id = $wpdb->insert_id;
						endif;
						$cat_ids[] = $cat_id;
					endif;
				endif;
			endif;
		endforeach;

		if ((count($cat_ids) == 0) and ($unfamiliar_category === 'filter')) :
			$cat_ids = NULL; // Drop the post
		else :
			$cat_ids = array_unique($cat_ids);
		endif;
		
		if ($tags_too) : $ret = array($cat_ids, $tags);
		else : $ret = $cat_ids;
		endif;

		return $ret;
	} // function SyndicatedPost::category_ids ()

	function use_api ($tag) {
		global $wp_db_version;
		switch ($tag) :
		case 'wp_insert_post':
			// Before 2.2, wp_insert_post does too much of the wrong stuff to use it
			// In 1.5 it was such a resource hog it would make PHP segfault on big updates
			$ret = (isset($wp_db_version) and $wp_db_version > FWP_SCHEMA_21);
			break;
		case 'post_status_pending':
			$ret = (isset($wp_db_version) and $wp_db_version > FWP_SCHEMA_23);
			break;
		endswitch;
		return $ret;		
	} // function SyndicatedPost::use_api ()

	#### EXTRACT DATA FROM FEED ITEM ####

	function created () {
		$epoch = null;
		if (isset($this->item['dc']['created'])) :
			$epoch = @parse_w3cdtf($this->item['dc']['created']);
		elseif (isset($this->item['dcterms']['created'])) :
			$epoch = @parse_w3cdtf($this->item['dcterms']['created']);
		elseif (isset($this->item['created'])): // Atom 0.3
			$epoch = @parse_w3cdtf($this->item['created']);
		endif;
		return $epoch;
	}
	function published ($fallback = true) {
		$epoch = null;

		# RSS is a fucking mess. Figure out whether we have a date in
		# <dc:date>, <issued>, <pubDate>, etc., and get it into Unix
		# epoch format for reformatting. If we can't find anything,
		# we'll use the last-updated time.
		if (isset($this->item['dc']['date'])):				// Dublin Core
			$epoch = @parse_w3cdtf($this->item['dc']['date']);
		elseif (isset($this->item['dcterms']['issued'])) :		// Dublin Core extensions
			$epoch = @parse_w3cdtf($this->item['dcterms']['issued']);
		elseif (isset($this->item['published'])) : 			// Atom 1.0
			$epoch = @parse_w3cdtf($this->item['published']);
		elseif (isset($this->item['issued'])): 				// Atom 0.3
			$epoch = @parse_w3cdtf($this->item['issued']);
		elseif (isset($this->item['pubdate'])): 			// RSS 2.0
			$epoch = strtotime($this->item['pubdate']);
		elseif ($fallback) :						// Fall back to <updated> / <modified> if present
			$epoch = $this->updated(/*fallback=*/ false);
		endif;
		
		# If everything failed, then default to the current time.
		if (is_null($epoch)) :
			if (-1 == $default) :
				$epoch = time();
			else :
				$epoch = $default;
			endif;
		endif;
		
		return $epoch;
	}
	function updated ($fallback = true, $default = -1) {
		$epoch = null;

		# As far as I know, only dcterms and Atom have reliable ways to
		# specify when something was *modified* last. If neither is
		# available, then we'll try to get the time of publication.
		if (isset($this->item['dc']['modified'])) : 			// Not really correct
			$epoch = @parse_w3cdtf($this->item['dc']['modified']);
		elseif (isset($this->item['dcterms']['modified'])) :		// Dublin Core extensions
			$epoch = @parse_w3cdtf($this->item['dcterms']['modified']);
		elseif (isset($this->item['modified'])):			// Atom 0.3
			$epoch = @parse_w3cdtf($this->item['modified']);
		elseif (isset($this->item['updated'])):				// Atom 1.0
			$epoch = @parse_w3cdtf($this->item['updated']);
		elseif ($fallback) :						// Fall back to issued / dc:date
			$epoch = $this->published(/*fallback=*/ false, /*default=*/ $default);
		endif;
		
		# If everything failed, then default to the current time.
		if (is_null($epoch)) :
			if (-1 == $default) :
				$epoch = time();
			else :
				$epoch = $default;
			endif;
		endif;

		return $epoch;
	}

	function update_hash () {
		return md5(serialize($this->item));
	}

	function guid () {
		$guid = null;
		if (isset($this->item['id'])): 			// Atom 0.3 / 1.0
			$guid = $this->item['id'];
		elseif (isset($this->item['atom']['id'])) :	// Namespaced Atom
			$guid = $this->item['atom']['id'];
		elseif (isset($this->item['guid'])) :		// RSS 2.0
			$guid = $this->item['guid'];
		elseif (isset($this->item['dc']['identifier'])) :// yeah, right
			$guid = $this->item['dc']['identifier'];
		else :
			// The feed does not seem to have provided us with a
			// unique identifier, so we'll have to cobble together
			// a tag: URI that might work for us. The base of the
			// URI will be the host name of the feed source ...
			$bits = parse_url($this->feedmeta['link/uri']);
			$guid = 'tag:'.$bits['host'];

			// If we have a date of creation, then we can use that
			// to uniquely identify the item. (On the other hand, if
			// the feed producer was consicentious enough to
			// generate dates of creation, she probably also was
			// conscientious enough to generate unique identifiers.)
			if (!is_null($this->created())) :
				$guid .= '://post.'.date('YmdHis', $this->created());
			
			// Otherwise, use both the URI of the item, *and* the
			// item's title. We have to use both because titles are
			// often not unique, and sometimes links aren't unique
			// either (e.g. Bitch (S)HITLIST, Mozilla Dot Org news,
			// some podcasts). But it's rare to have *both* the same
			// title *and* the same link for two different items. So
			// this is about the best we can do.
			else :
				$guid .= '://'.md5($this->item['link'].'/'.$this->item['title']);
			endif;
		endif;
		return $guid;
	}
	
	function author () {
		$author = array ();
		
		if (isset($this->item['author_name'])):
			$author['name'] = $this->item['author_name'];
		elseif (isset($this->item['dc']['creator'])):
			$author['name'] = $this->item['dc']['creator'];
		elseif (isset($this->item['dc']['contributor'])):
			$author['name'] = $this->item['dc']['contributor'];
		elseif (isset($this->feed->channel['dc']['creator'])) :
			$author['name'] = $this->feed->channel['dc']['creator'];
		elseif (isset($this->feed->channel['dc']['contributor'])) :
			$author['name'] = $this->feed->channel['dc']['contributor'];
		elseif (isset($this->feed->channel['author_name'])) :
			$author['name'] = $this->feed->channel['author_name'];
		elseif ($this->feed->is_rss() and isset($this->item['author'])) :
			// The author element in RSS is allegedly an
			// e-mail address, but lots of people don't use
			// it that way. So let's make of it what we can.
			$author = parse_email_with_realname($this->item['author']);
			
			if (!isset($author['name'])) :
				if (isset($author['email'])) :
					$author['name'] = $author['email'];
				else :
					$author['name'] = $this->feed->channel['title'];
				endif;
			endif;
		else :
			$author['name'] = $this->feed->channel['title'];
		endif;
		
		if (isset($this->item['author_email'])):
			$author['email'] = $this->item['author_email'];
		elseif (isset($this->feed->channel['author_email'])) :
			$author['email'] = $this->feed->channel['author_email'];
		endif;
		
		if (isset($this->item['author_url'])):
			$author['uri'] = $this->item['author_url'];
		elseif (isset($this->feed->channel['author_url'])) :
			$author['uri'] = $this->item['author_url'];
		else:
			$author['uri'] = $this->feed->channel['link'];
		endif;

		return $author;
	} // SyndicatedPost::author()

	/**
	 * SyndicatedPost::isTaggedAs: Test whether a feed item is
	 * tagged / categorized with a given string. Case and leading and
	 * trailing whitespace are ignored.
	 *
	 * @param string $tag Tag to check for
	 *
	 * @return bool Whether or not at least one of the categories / tags on 
	 *	$this->item is set to $tag (modulo case and leading and trailing
	 * 	whitespace)
	 */
	function isTaggedAs ($tag) {
		$desiredTag = strtolower(trim($tag)); // Normalize case and whitespace

		// Check to see if this is tagged with $tag
		$currentCategory = 'category';
		$currentCategoryNumber = 1;

		// If we have the new MagpieRSS, the number of category elements
		// on this item is stored under index "category#".
		if (isset($this->item['category#'])) :
			$numberOfCategories = (int) $this->item['category#'];
		
		// We REALLY shouldn't have the old and busted MagpieRSS, but in
		// case we do, it doesn't support multiple categories, but there
		// might still be a single value under the "category" index.
		elseif (isset($this->item['category'])) :
			$numberOfCategories = 1;

		// No standard category or tag elements on this feed item.
		else :
			$numberOfCategories = 0;

		endif;

		$isSoTagged = false; // Innocent until proven guilty

		// Loop through category elements; if there are multiple
		// elements, they are indexed as category, category#2,
		// category#3, ... category#N
		while ($currentCategoryNumber <= $numberOfCategories) :
			if ($desiredTag == strtolower(trim($this->item[$currentCategory]))) :
				$isSoTagged = true; // Got it!
				break;
			endif;

			$currentCategoryNumber += 1;
			$currentCategory = 'category#'.$currentCategoryNumber;
		endwhile;

		return $isSoTagged;
	} /* SyndicatedPost::isTaggedAs() */

	var $uri_attrs = array (
		array('a', 'href'),
		array('applet', 'codebase'),
		array('area', 'href'),
		array('blockquote', 'cite'),
		array('body', 'background'),
		array('del', 'cite'),
		array('form', 'action'),
		array('frame', 'longdesc'),
		array('frame', 'src'),
		array('iframe', 'longdesc'),
		array('iframe', 'src'),
		array('head', 'profile'),
		array('img', 'longdesc'),
		array('img', 'src'),
		array('img', 'usemap'),
		array('input', 'src'),
		array('input', 'usemap'),
		array('ins', 'cite'),
		array('link', 'href'),
		array('object', 'classid'),
		array('object', 'codebase'),
		array('object', 'data'),
		array('object', 'usemap'),
		array('q', 'cite'),
		array('script', 'src')
	); /* var SyndicatedPost::$uri_attrs */

	var $_base = null;

	function resolve_single_relative_uri ($refs) {
		$tag = FeedWordPressHTML::attributeMatch($refs);
		$url = Relative_URI::resolve($tag['value'], $this->_base);
		return $tag['prefix'] . $url . $tag['suffix'];
	} /* function SyndicatedPost::resolve_single_relative_uri() */

	function resolve_relative_uris ($content, $obj) {
		$set = $obj->link->setting('resolve relative', 'resolve_relative', 'yes');
		if ($set and $set != 'no') : 
			# The MagpieRSS upgrade has some `xml:base` support baked in.
			# However, sometimes people do silly things, like putting
			# relative URIs out on a production RSS 2.0 feed or other feeds
			# with no good support for `xml:base`. So we'll do our best to
			# try to catch any remaining relative URIs and resolve them as
			# best we can.
			$obj->_base = $obj->item['link']; // Reset the base for resolving relative URIs
	
			foreach ($obj->uri_attrs as $pair) :
				list($tag, $attr) = $pair;
				$pattern = FeedWordPressHTML::attributeRegex($tag, $attr);
				$content = preg_replace_callback (
					$pattern,
					array(&$obj, 'resolve_single_relative_uri'),
					$content
				);
			endforeach;
		endif;
		
		return $content;
	} /* function SyndicatedPost::resolve_relative_uris () */

	var $strip_attrs = array (
		array('[a-z]+', 'target'),
//		array('[a-z]+', 'style'),
//		array('[a-z]+', 'on[a-z]+'),
	);

	function strip_attribute_from_tag ($refs) {
		$tag = FeedWordPressHTML::attributeMatch($refs);
		return $tag['before_attribute'].$tag['after_attribute'];
	}

	function sanitize_content ($content, $obj) {
		# This kind of sucks. I intend to replace it with
		# lib_filter sometime soon.
		foreach ($obj->strip_attrs as $pair):
			list($tag,$attr) = $pair;
			$pattern = FeedWordPressHTML::attributeRegex($tag, $attr);

			$content = preg_replace_callback (
				$pattern,
				array(&$obj, 'strip_attribute_from_tag'),
				$content
			);
		endforeach;
		return $content;
	}
} // class SyndicatedPost

