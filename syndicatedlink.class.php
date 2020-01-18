<?php
# class SyndicatedLink: represents a syndication feed stored within the
# WordPress database
#
# To keep things compact and editable from within WordPress, we use all the
# links under a particular category in the WordPress "Blogroll" for the list of
# feeds to syndicate. "Contributors" is the category used by default; you can
# configure that under Options --> Syndication.
#
# Fields used are:
#
# *	link_rss: the URI of the Atom/RSS feed to syndicate
#
# *	link_notes: user-configurable options, with keys and values
#	like so:
#
#		key: value
#		cats: computers\nweb
#		feed/key: value
#
#	Keys that start with "feed/" are gleaned from the data supplied
#	by the feed itself, and will be overwritten with each update.
#
#	Values have linebreak characters escaped with C-style
#	backslashes (so, for example, a newline becomes "\n").
#
#	The value of `cats` is used as a newline-separated list of
#	default categories for any post coming from a particular feed.
#	(In the example above, any posts from this feed will be placed
#	in the "computers" and "web" categories--*in addition to* any
#	categories that may already be applied to the posts.)
#
#	Values of keys in link_notes are accessible from templates using
#	the function `get_feed_meta($key)` if this plugin is activated.

require_once(dirname(__FILE__).'/magpiefromsimplepie.class.php');
require_once(dirname(__FILE__).'/feedwordpressparsedpostmeta.class.php');

class SyndicatedLink {
	var $id = null;
	var $link = null;
	var $settings = array ();
	public $simplepie = null;
	var $magpie = null;

	public function __construct( $link ) {
		global $wpdb;

		if (is_object($link)) :
			$this->link = $link;
			$this->id = $link->link_id;
		else :
			$this->id = $link;
			$this->link = get_bookmark($link);
		endif;

		if (is_object($this->link)) :
			if (strlen($this->link->link_rss) > 0) :
				$this->get_settings_from_notes();
			endif;
		endif;

		add_filter('feedwordpress_update_complete', array($this, 'process_retirements'), 1000, 1);
	} /* SyndicatedLink::__construct () */

	public function found () {
		return is_object($this->link) and !is_wp_error($this->link);
	} /* SyndicatedLink::found () */

	public function id () {
		return (is_object($this->link) ? $this->link->link_id : NULL);
	}

	public function stale () {
		global $feedwordpress;

		$stale = true;
		if ($this->setting('update/hold')=='ping') :
			$stale = false; // don't update on any timed updates; pings only
		elseif ($this->setting('update/hold')=='next') :
			$stale = true; // update on the next timed update
		elseif ( !$this->setting('update/last') ) :
			$stale = true; // initial update
		elseif ($feedwordpress->force_update_all()) :
			$stale = true; // forced general updating
		else :
			$after = (
				(int) $this->setting('update/last')
				+ (int) $this->setting('update/fudge')
				+ ((int) $this->setting('update/ttl') * 60)
			);
			$stale = (time() >= $after);
		endif;
		return $stale;
	} /* SyndicatedLink::stale () */

	public function fetch () {
		$timeout = $this->setting('fetch timeout', 'feedwordpress_fetch_timeout', FEEDWORDPRESS_FETCH_TIMEOUT_DEFAULT);

		$this->simplepie = apply_filters(
			'syndicated_feed',
			FeedWordPress::fetch($this, array('timeout' => $timeout)),
			$this
		);

		// Filter compatibility mode
		if (is_wp_error($this->simplepie)) :
			$this->magpie = $this->simplepie;
		else :
			$this->magpie = new MagpieFromSimplePie($this->simplepie, NULL);
		endif;
	} /* SyndicatedLink::fetch () */
	
	public function live_posts () {
		if (!is_object($this->simplepie)) :
			$this->fetch();
		endif;

		if (is_object($this->simplepie) and method_exists($this->simplepie, 'get_items')) :
			$ret = apply_filters(
			'syndicated_feed_items',
			$this->simplepie->get_items(),
			$this
			);
		else :
			$ret = $this->simplepie;
		endif;
		return $ret;
	} /* SyndicatedLink::live_posts () */

	protected function pause_updates () {
		return ('yes'==$this->setting("update/pause", "update_pause", 'no'));
	} /* SyndicatedLink::pause_updates () */

	public function poll ($crash_ts = NULL) {
		global $wpdb;

		$url = $this->uri(array('add_params' => true, 'fetch' => true));
		FeedWordPress::diagnostic('updated_feeds', 'Polling feed ['.$url.']');
	
		$this->fetch();
	
		$new_count = NULL;

		$resume = ('yes'==$this->setting('update/unfinished'));
		if ($resume) :
			// pick up where we left off
			$processed = array_map('trim', explode("\n", $this->setting('update/processed')));
		else :
			// begin at the beginning
			$processed = array();
		endif;

		if (is_wp_error($this->simplepie)) :
			$new_count = $this->simplepie;
			// Error; establish an error setting.
			$theError = array();
			$theError['ts'] = time();
			$theError['since'] = time();
			$theError['object'] = $this->simplepie;

			$oldError = $this->setting('update/error', NULL, NULL);
			if (is_string($oldError)) :
				$oldError = unserialize($oldError);
			endif;

			if (!is_null($oldError)) :
				// Copy over the in-error-since timestamp
				$theError['since'] = $oldError['since'];

				// If this is a repeat error, then we should
				// take a step back before we try to fetch it
				// again.
				$this->update_setting('update/last', time(), NULL);
				$ttl = $this->automatic_ttl();
				$ttl = apply_filters('syndicated_feed_ttl', $ttl, $this);
				$ttl = apply_filters('syndicated_feed_ttl_from_error', $ttl, $this);
				$this->update_setting('update/ttl', $ttl, $this);
				$this->update_setting('update/timed', 'automatically');
			endif;

			do_action('syndicated_feed_error', $theError, $oldError, $this);

			$this->update_setting('update/error', serialize($theError));
			$this->save_settings(/*reload=*/ true);

		elseif (is_object($this->simplepie)) :

			// Success; clear out error setting, if any.
			$this->update_setting('update/error', NULL);

			$new_count = array('new' => 0, 'updated' => 0);

			# -- Update Link metadata live from feed
			$channel = $this->magpie->channel;

			if (!isset($channel['id'])) :
				$channel['id'] = $this->link->link_rss;
			endif;

			$update = array();
			if (!$this->hardcode('url') and isset($channel['link'])) :
				$update[] = "link_url = '".esc_sql($channel['link'])."'";
			endif;

			if (!$this->hardcode('name') and isset($channel['title'])) :
				$update[] = "link_name = '".esc_sql($channel['title'])."'";
			endif;

			if (!$this->hardcode('description')) :
				if (isset($channel['tagline'])) :
					$update[] = "link_description = '".esc_sql($channel['tagline'])."'";
				elseif (isset($channel['description'])) :
					$update[] = "link_description = '".esc_sql($channel['description'])."'";
				endif;
			endif;

			$this->update_setting('link/feed_type', $this->simplepie->get_type());

			$this->merge_settings($channel, 'feed/');

			$this->update_setting('update/last', time());
			list($ttl, $xml) = $this->ttl(/*return element=*/ true);

			if (!is_null($ttl)) :
				$this->update_setting('update/ttl', $ttl);
				$this->update_setting('update/xml', $xml);
				$this->update_setting('update/timed', 'feed');
			else :
				$ttl = $this->automatic_ttl();
				$this->update_setting('update/ttl', $ttl);
				$this->update_setting('update/xml', NULL);
				$this->update_setting('update/timed', 'automatically');
			endif;
			$this->update_setting('update/fudge', rand(0, ($ttl/3))*60);
			$this->update_setting('update/ttl', apply_filters(
				'syndicated_feed_ttl',
				$this->setting('update/ttl'),
				$this
			));

			if (!$this->setting('update/hold') != 'ping') :
				$this->update_setting('update/hold', 'scheduled');
			endif;

			$this->update_setting('update/unfinished', 'yes');

			$update[] = "link_notes = '".esc_sql($this->settings_to_notes())."'";

			$update_set = implode(',', $update);

			// Update the properties of the link from the feed information
			$result = $wpdb->query("
				UPDATE $wpdb->links
				SET $update_set
				WHERE link_id='$this->id'
			");
			do_action('update_syndicated_feed', $this->id, $this);

			# -- Add new posts from feed and update any updated posts
			$crashed = false;

			$posts = $this->live_posts();
			
			$this->magpie->originals = $posts;

			// If this is a complete feed, rather than an incremental feed, we
			// need to prepare to mark everything for presumptive retirement.
			if ($this->is_non_incremental()) :
				$q = new WP_Query(array(
				'fields' => '_synfrom',
				'post_status__not' => 'fwpretired',
				'ignore_sticky_posts' => true,
				'meta_key' => 'syndication_feed_id',
				'meta_value' => $this->id,
				));
				foreach ($q->posts as $p) :
					update_post_meta($p->ID, '_feedwordpress_retire_me_'.$this->id, '1');
				endforeach;
			endif;

			if (is_array($posts)) :
				foreach ($posts as $key => $item) :
					if (!$this->pause_updates()) :
						$post = new SyndicatedPost($item, $this);

						if (!$resume or !in_array(trim($post->guid()), $processed)) :
							$processed[] = $post->guid();
							if (!$post->filtered()) :
								$new = $post->store();
								if ( $new !== false ) $new_count[$new]++;
							endif;

							if (!is_null($crash_ts) and (time() > $crash_ts)) :
								$crashed = true;
								break;
							endif;
						endif;

						unset($post);
					endif;					
				endforeach;
			endif;

			if ('yes'==$this->setting('tombstones', 'tombstones', 'yes')) :
				// Check for use of Atom tombstones. Spec:
				// <http://tools.ietf.org/html/draft-snell-atompub-tombstones-18>
				$tombstones = $this->simplepie->get_feed_tags('http://purl.org/atompub/tombstones/1.0', 'deleted-entry');
				if (!is_null($tombstones) && count($tombstones) > 0) :
					foreach ($tombstones as $tombstone) :
						$ref = NULL;
						foreach (array('', 'http://purl.org/atompub/tombstones/1.0') as $ns) :
							if (isset($tombstone['attribs'][$ns])
							and isset($tombstone['attribs'][$ns]['ref'])) :
								$ref = $tombstone['attribs'][$ns]['ref'];
							endif;
						endforeach;

						$q = new WP_Query(array(
						'ignore_sticky_posts' => true,
						'guid' => $ref,
						'meta_key' => 'syndication_feed_id',
						'meta_value' => $this->id, // Only allow a feed to tombstone its own entries.
						));

						foreach ($q->posts as $p) :
							$old_status = $p->post_status;
							FeedWordPress::diagnostic('syndicated_posts', 'Retiring existing post # '.$p->ID.' "'.$p->post_title.'" due to Atom tombstone element in feed.');
							set_post_field('post_status', 'fwpretired', $p->ID);
							wp_transition_post_status('fwpretired', $old_status, $p);
						endforeach;

					endforeach;
				endif;
			endif;

			$suffix = ($crashed ? 'crashed' : 'completed');
			do_action('update_syndicated_feed_items', $this->id, $this);
			do_action("update_syndicated_feed_items_${suffix}", $this->id, $this);

			$this->update_setting('update/processed', $processed);
			if (!$crashed) :
				$this->update_setting('update/unfinished', 'no');
			endif;
			$this->update_setting('link/item count', count($posts));

			// Copy back any changes to feed settings made in the
			// course of updating (e.g. new author rules)
			$update_set = "link_notes = '".esc_sql($this->settings_to_notes())."'";

			// Update the properties of the link from the feed information
			$result = $wpdb->query("
			UPDATE $wpdb->links
			SET $update_set
			WHERE link_id='$this->id'
			");

			do_action("update_syndicated_feed_completed", $this->id, $this);
		endif;

		// All done; let's clean up.
		$this->magpie = NULL;

		// Avoid circular-reference memory leak in PHP < 5.3.
		// Cf. <http://simplepie.org/wiki/faq/i_m_getting_memory_leaks>
		if (method_exists($this->simplepie, '__destruct')) :
			$this->simplepie->__destruct();
		endif;
		$this->simplepie = NULL;

		return $new_count;
	} /* SyndicatedLink::poll() */

	public function process_retirements ($delta) {
		global $post;

		$q = new WP_Query(array(
		'fields' => '_synfrom',
		'post_status__not' => 'fwpretired',
		'ignore_sticky_posts' => true,
		'meta_key' => '_feedwordpress_retire_me_'.$this->id,
		'meta_value' => '1',
		));
		if ($q->have_posts()) :
			foreach ($q->posts as $p) :
				$old_status = $p->post_status;
				FeedWordPress::diagnostic('syndicated_posts', 'Retiring existing post # '.$p->ID.' "'.$p->post_title.'" due to absence from a non-incremental feed.');
				set_post_field('post_status', 'fwpretired', $p->ID);
				wp_transition_post_status('fwpretired', $old_status, $p);
				delete_post_meta($p->ID, '_feedwordpress_retire_me_'.$this->id);
			endforeach;
		endif;

		return $delta;
	} /* SyndicatedLink::process_retirements () */

	/**
	 * Updates the URL for the feed syndicated by this link.
	 *
	 * @param string $url The new feed URL to use for this source.
	 * @return bool TRUE on success, FALSE on failure.
	 */
	public function set_uri ($url) {
		global $wpdb;

		if ($this->found()) :
			// Update link_rss
			$result = $wpdb->query("
			UPDATE $wpdb->links
			SET
				link_rss = '".esc_sql($url)."'
			WHERE link_id = '".esc_sql($this->id)."'
			");

			$ret = ($result ? true : false);
		else :
			$ret = false;
		endif;
		return $ret;
	} /* SyndicatedLink::set_uri () */

	public function deactivate () {
		global $wpdb;

		$wpdb->query($wpdb->prepare("
		UPDATE $wpdb->links SET link_visible = 'N' WHERE link_id = %d
		", (int) $this->id));
	} /* SyndicatedLink::deactivate () */

	/**
	 * SyndicatedLink::delete() deletes a subscription from the WordPress links
	 * table. Any posts that were syndicated through that subscription will still
	 * be present in the wp_posts table; but postmeta fields that refer to the
	 * syndication feed's numeric id (which will no longer be valid) will be
	 * deleted. For most purposes, the posts remaining will be treated as if they
	 * were locally authored posts rather than syndicated posts.
	 *
	 * @global $wpdb
	 * @uses wpdb::query
	 */
	public function delete () {
		global $wpdb;

		$wpdb->query($wpdb->prepare("
		DELETE FROM $wpdb->postmeta WHERE meta_key='syndication_feed_id'
		AND meta_value = '%s'
		", $this->id));

		$wpdb->query($wpdb->prepare("
		DELETE FROM $wpdb->links WHERE link_id = %d
		", (int) $this->id));

		$this->id = NULL;
	} /* SyndicatedLink::delete () */

	/**
	 * SyndicatedLink::nuke() deletes a subscription AND all of the
	 * posts syndicated through that subscription.
	 *
	 * @global $wpdb
	 * @uses wpdb::get_col
	 * @uses wp_delete_post
	 * @uses SyndicatedLink::delete
	 */
	public function nuke () {
		global $wpdb;

		// Make a list of the items syndicated from this feed...
		$post_ids = $wpdb->get_col($wpdb->prepare("
		SELECT post_id FROM $wpdb->postmeta
		WHERE meta_key = 'syndication_feed_id'
		AND meta_value = '%s'
		", $this->id));

		// ... and kill them all
		if (count($post_ids) > 0) :
			foreach ($post_ids as $post_id) :
				// Force scrubbing of deleted post
				// rather than sending to Trashcan
				wp_delete_post(
					/*postid=*/ $post_id,
					/*force_delete=*/ true
				);
			endforeach;
		endif;

		$this->delete();
	} /* SyndicatedLink::nuke () */

	public function map_name_to_new_user ($name, $newuser_name) {
		global $wpdb;

		if (strlen($newuser_name) > 0) :
			$newuser_id = fwp_insert_new_user($newuser_name);
			if (is_numeric($newuser_id)) :
				if (is_null($name)) : // Unfamiliar author
					$this->update_setting('unfamiliar author', $newuser_id);
				else :
					$map = $this->setting('map authors');
					$map['name'][$name] = $newuser_id;
					$this->update_setting('map authors', $map);
				endif;
			else :
				// TODO: Add some error detection and reporting
			endif;
		else :
			// TODO: Add some error reporting
		endif;
	} /* SyndicatedLink::map_name_to_new_user () */

	protected function imploded_settings () {
		return array('cats', 'tags', 'match/cats', 'match/tags', 'match/filter');
	} /* SyndicatedLink::imploded_settings () */

	protected function get_settings_from_notes () {
		// Read off feed settings from link_notes
		$notes = explode("\n", $this->link->link_notes);
		foreach ($notes as $note):
			$pair = explode(": ", $note, 2);
			$key = (isset($pair[0]) ? $pair[0] : null);
			$value = (isset($pair[1]) ? $pair[1] : null);
			if (!is_null($key) and !is_null($value)) :
				// Unescape and trim() off the whitespace.
				// Thanks to Ray Lischner for pointing out the
				// need to trim off whitespace.
				$this->settings[$key] = stripcslashes (trim($value));
			endif;
		endforeach;

		// "Magic" feed settings
		$this->settings['link/uri'] = $this->link->link_rss;
		$this->settings['link/name'] = $this->link->link_name;
		$this->settings['link/id'] = $this->link->link_id;

		// `hardcode categories` and `unfamiliar categories` are
		// deprecated in favor of `unfamiliar category`
		if (
			isset($this->settings['unfamiliar categories'])
			and !isset($this->settings['unfamiliar category'])
		) :
			$this->settings['unfamiliar category'] = $this->settings['unfamiliar categories'];
		endif;
		if (
			FeedWordPress::affirmative($this->settings, 'hardcode categories')
			and !isset($this->settings['unfamiliar category'])
		) :
			$this->settings['unfamiliar category'] = 'default';
		endif;

		// Set this up automagically for del.icio.us
		$bits = parse_url($this->link->link_rss);
		$tagspacers = array('del.icio.us', 'feeds.delicious.com');
		if (!isset($this->settings['cat_split']) and in_array($bits['host'], $tagspacers)) :
			$this->settings['cat_split'] = '\s'; // Whitespace separates multiple tags in del.icio.us RSS feeds
		endif;

		// Simple lists
		foreach ($this->imploded_settings() as $what) :
			if (isset($this->settings[$what])):
				$this->settings[$what] = explode(
					FEEDWORDPRESS_CAT_SEPARATOR,
					$this->settings[$what]
				);
			endif;
		endforeach;

		if (isset($this->settings['terms'])) :
			// Look for new format
			$this->settings['terms'] = maybe_unserialize($this->settings['terms']);

			if (!is_array($this->settings['terms'])) :
				// Deal with old format instead. Ugh.

				// Split on two *or more* consecutive breaks
				// because in the old format, a taxonomy
				// without any associated terms would
				// produce tax_name#1\n\n\ntax_name#2\nterm,
				// and the naive split on the first \n\n
				// would screw up the tax_name#2 list.
				//
				// Props to David Morris for pointing this
				// out.

				$this->settings['terms'] = preg_split(
					"/".FEEDWORDPRESS_CAT_SEPARATOR."{2,}/",
					$this->settings['terms']
				);
				$terms = array();
				foreach ($this->settings['terms'] as $line) :
					$line = explode(FEEDWORDPRESS_CAT_SEPARATOR, $line);
					$tax = array_shift($line);
					$terms[$tax] = $line;
				endforeach;
				$this->settings['terms'] = $terms;
			endif;
		endif;

		if (isset($this->settings['map authors'])) :
			$author_rules = explode("\n\n", $this->settings['map authors']);
			$ma = array();
			foreach ($author_rules as $rule) :
				list($rule_type, $author_name, $author_action) = explode("\n", $rule);

				// Normalize for case and whitespace
				$rule_type = strtolower(trim($rule_type));
				$author_name = strtolower(trim($author_name));
				$author_action = strtolower(trim($author_action));

				$ma[$rule_type][$author_name] = $author_action;
			endforeach;
			$this->settings['map authors'] = $ma;
		endif;

	} /* SyndicatedLink::get_settings_from_notes () */

	protected function settings_to_notes () {
		$to_notes = $this->settings;

		unset($to_notes['link/id']); // Magic setting; don't save
		unset($to_notes['link/uri']); // Magic setting; don't save
		unset($to_notes['link/name']); // Magic setting; don't save
		unset($to_notes['hardcode categories']); // Deprecated
		unset($to_notes['unfamiliar categories']); // Deprecated

		// Collapse array settings
		if (isset($to_notes['update/processed']) and (is_array($to_notes['update/processed']))) :
			$to_notes['update/processed'] = implode("\n", $to_notes['update/processed']);
		endif;

		foreach ($this->imploded_settings() as $what) :
			if (isset($to_notes[$what]) and is_array($to_notes[$what])) :
				$to_notes[$what] = implode(
					FEEDWORDPRESS_CAT_SEPARATOR,
					$to_notes[$what]
				);
			endif;
		endforeach;

		if (isset($to_notes['terms']) and is_array($to_notes['terms'])) :
			// Serialize it.
			$to_notes['terms'] = serialize($to_notes['terms']);
		endif;

		// Collapse the author mapping rule structure back into a flat string
		if (isset($to_notes['map authors'])) :
			$ma = array();
			foreach ($to_notes['map authors'] as $rule_type => $author_rules) :
				foreach ($author_rules as $author_name => $author_action) :
					$ma[] = $rule_type."\n".$author_name."\n".$author_action;
				endforeach;
			endforeach;
			$to_notes['map authors'] = implode("\n\n", $ma);
		endif;

		$notes = '';
		foreach ($to_notes as $key => $value) :
			$notes .= $key . ": ". addcslashes($value, "\0..\37".'\\') . "\n";
		endforeach;
		return $notes;
	} /* SyndicatedLink::settings_to_notes () */

	public function save_settings ($reload = false) {
		global $wpdb;

		// Save channel-level meta-data
		foreach (array('link_name', 'link_description', 'link_url') as $what) :
			$alter[] = "{$what} = '".esc_sql($this->link->{$what})."'";
		endforeach;

		// Save settings to the notes field
		$alter[] = "link_notes = '".esc_sql($this->settings_to_notes())."'";

		// Update the properties of the link from settings changes, etc.
		$update_set = implode(", ", $alter);

		$result = $wpdb->query("
		UPDATE $wpdb->links
		SET $update_set
		WHERE link_id='$this->id'
		");

		if ($reload) :
			// force reload of link information from DB
			if (function_exists('clean_bookmark_cache')) :
				clean_bookmark_cache($this->id);
			endif;
		endif;
	} /* SyndicatedLink::save_settings () */

	/**
	 * Retrieves the value of a setting, allowing for a global setting to be
	 * used as a fallback, or a constant value, or both.
	 *
	 * @param string $name The link setting key
	 * @param mixed $fallback_global If the link setting is nonexistent or marked as a use-default value, fall back to the value of this global setting.
	 * @param mixed $fallback_value If the link setting and the global setting are nonexistent or marked as a use-default value, fall back to this constant value.
	 * @return bool TRUE on success, FALSE on failure.
	 */
	public function setting ($name, $fallback_global = NULL, $fallback_value = NULL, $default = 'default') {
		$ret = NULL;
		if (isset($this->settings[$name])) :
			$ret = $this->settings[$name];
		endif;

		$no_value = (
			is_null($ret)
			or (is_string($ret) and strtolower($ret)==$default)
		);

		if ($no_value and !is_null($fallback_global)) :
			// Avoid duplication of this correction
			$fallback_global = preg_replace('/^feedwordpress_/', '', $fallback_global);

			// Occasionally we'll get an array back. Convert it to a string
			if ( is_array($fallback_global) && sizeof($fallback_global) )
				$fallback_global = reset($fallback_global);

			if ( !empty($fallback_global) )
				$ret = get_option('feedwordpress_'.$fallback_global, /*default=*/ NULL);
		endif;

		$no_value = (
			is_null($ret)
			or (is_string($ret) and strtolower($ret)==$default)
		);

		if ($no_value and !is_null($fallback_value)) :
			$ret = $fallback_value;
		endif;
		return $ret;
	} /* SyndicatedLink::setting () */

	public function merge_settings ($data, $prefix, $separator = '/') {
		$dd = $this->flatten_array($data, $prefix, $separator);
		$this->settings = array_merge($this->settings, $dd);
	} /* SyndicatedLink::merge_settings () */

	public function update_setting ($name, $value, $default = 'default') {
		if (!is_null($value) and $value != $default) :
			$this->settings[$name] = $value;
		else : // Zap it.
			unset($this->settings[$name]);
		endif;
	} /* SyndicatedLink::update_setting () */

	public function is_non_incremental () {
		return ('complete'==$this->setting('update_incremental', 'update_incremental', 'incremental'));
	} /* SyndicatedLink::is_non_incremental () */

	public function get_feed_type () {
		$type_code = $this->setting('link/feed_type');
		
		// list derived from: <http://simplepie.org/api/class-SimplePie.html>, retrieved 2020/01/18
		$bitmasks = array(
			SIMPLEPIE_TYPE_RSS_090 => 'RSS 0.90',
			SIMPLEPIE_TYPE_RSS_091_NETSCAPE => 'RSS 0.91 (Netscape)',
			SIMPLEPIE_TYPE_RSS_091_USERLAND => 'RSS 0.91 (Userland)',
			SIMPLEPIE_TYPE_RSS_091 => 'RSS 0.91',
			SIMPLEPIE_TYPE_RSS_092 => 'RSS 0.92',
			SIMPLEPIE_TYPE_RSS_093 => 'RSS 0.93',
			SIMPLEPIE_TYPE_RSS_094 => 'RSS 0.94',
			SIMPLEPIE_TYPE_RSS_10 => 'RSS 1.0',
			SIMPLEPIE_TYPE_RSS_20 => 'RSS 2.0.x',
			SIMPLEPIE_TYPE_RSS_RDF => 'RDF-based RSS',
			SIMPLEPIE_TYPE_RSS_SYNDICATION => 'Non-RDF-based RSS',
			SIMPLEPIE_TYPE_RSS_ALL => 'Any version of RSS',
			SIMPLEPIE_TYPE_ATOM_03 => 'Atom 0.3',
			SIMPLEPIE_TYPE_ATOM_10 => 'Atom 1.0',
			SIMPLEPIE_TYPE_ATOM_ALL => 'Atom (any version)',
			SIMPLEPIE_TYPE_ALL => 'Supported Feed (unspecified format)',
		);

		$type = "Unknown or unsupported format";
		foreach ($bitmasks as $format_flag => $format_string) :
			if (is_numeric($format_flag)) : // Guard against failure of constants to be defined.
				if ($type_code & $format_flag) :
					$type = $format_string;
					break; // foreach
				endif;
			endif;
		endforeach;
		
		return $type;
	} /* SyndicatedLink::get_feed_type () */
	
	public function uri ($params = array()) {
		$params = wp_parse_args($params, array(
		'add_params' => false,
		'fetch' => false,
		));

		// Initialize $qp (= array for added query parameters, if any)
		$qp = array();
		
		$link_rss = (is_object($this->link) ? $this->link->link_rss : NULL); 
		
		// $link_rss stores the URI for the subscription as stored in the feed's record.
		// $uri stores the effective URI of the request including any/all added query parameters 
		$uri = $link_rss;
		if (!is_null($uri) and strlen($uri) > 0 and $params['add_params']) :
			$qp = maybe_unserialize($this->setting('query parameters', array()));

			// For high-tech HTTP feed request kung fu
			$qp = apply_filters('syndicated_feed_parameters', $qp, $uri, $this);

			// $qp is an array of key-value pairs stored as arrays of format [$key, $value]
			$q = array();
			if (is_array($qp) and count($qp) > 0) :
				foreach ($qp as $pair) :
					$q[] = urlencode($pair[0]).'='.urlencode($pair[1]);
				endforeach;

				// Are we appending to a URI that already has params?
				$sep = ((strpos($uri, "?")===false) ? '?' : '&');

				// Tack it on
				$uri .= $sep . implode("&", $q);
			endif;
		endif;

		// Do we have any filters that apply here?
		$uri = apply_filters('syndicated_link_uri', $uri, $link_rss, $qp, $params, $this);

		// Return the filtered link URI.		
		return $uri;
	} /* SyndicatedLink::uri () */

	public function username () {
		return $this->setting('http username', 'http_username', NULL);
	} /* SyndicatedLink::username () */

	public function password () {
		return $this->setting('http password', 'http_password', NULL);
	} /* SyndicatedLink::password () */

	public function authentication_method () {
		$auth = $this->setting('http auth method', NULL);
		if (('-' == $auth) or (strlen($auth)==0)) :
			$auth = NULL;
		endif;
		return $auth;
	} /* SyndicatedLink::authentication_method () */

	var $postmeta = array();
	public function postmeta ($params = array()) {
		$params = wp_parse_args($params, /*defaults=*/ array(
		"field" => NULL,
		"parsed" => false,
		"force" => false,
		));

		if ($params['force'] or !isset($this->postmeta[/*parsed = */ false])) :
			// First, get the global settings.
			$default_custom_settings = get_option('feedwordpress_custom_settings');
			if ($default_custom_settings and !is_array($default_custom_settings)) :
				$default_custom_settings = unserialize($default_custom_settings);
			endif;
			if (!is_array($default_custom_settings)) :
				$default_custom_settings = array();
			endif;

			// Next, get the settings for this particular feed.
			$custom_settings = $this->setting('postmeta', NULL, NULL);
			if ($custom_settings and !is_array($custom_settings)) :
				$custom_settings = unserialize($custom_settings);
			endif;
			if (!is_array($custom_settings)) :
				$custom_settings = array();
			endif;

			$this->postmeta[/*parsed=*/ false] = array_merge($default_custom_settings, $custom_settings);
			$this->postmeta[/*parsed=*/ true] = array();

			// Now, run through and parse them all.
			foreach ($this->postmeta[/*parsed=*/ false] as $key => $meta) :
				$meta = apply_filters("syndicated_link_post_meta_${key}_pre", $meta, $this);
				$this->postmeta[/*parsed=*/ false][$key] = $meta;
				$this->postmeta[/*parsed=*/ true][$key] = new FeedWordPressParsedPostMeta($meta);
			endforeach;
		endif;

		$ret = $this->postmeta[!!$params['parsed']];
		if (is_string($params['field'])) :
			$ret = $ret[$params['field']];
		endif;
		return $ret;
	} /* SyndicatedLink::postmeta () */

	public function property_cascade ($fromFeed, $link_field, $setting, $method) {
		$value = NULL;
		if ($fromFeed) :
			$value = $this->setting($setting, NULL, NULL, NULL);

			$s = $this->simplepie;
			$callable = (is_object($s) and method_exists($s, $method));
			if (is_null($value) and $callable) :
				$fallback = $s->{$method}();
			endif;
		else :
			$value = $this->link->{$link_field};
		endif;
		return $value;
	} /* SyndicatedLink::property_cascade () */

	public function homepage ($fromFeed = true) {
		return $this->property_cascade($fromFeed, 'link_url', 'feed/link', 'get_link');
	} /* SyndicatedLink::homepage () */

	public function name ($fromFeed = true) {
		return $this->property_cascade($fromFeed, 'link_name', 'feed/title', 'get_title');
	} /* SyndicatedLink::name () */

	public function guid () {
		$ret = $this->setting('feed/id', NULL, $this->uri());

		// If we can get it live from the feed, do so.
		if (is_object($this->simplepie)) :
			$search = array(
				array('', 'id'),
				array(SIMPLEPIE_NAMESPACE_ATOM_10, 'id'),
				array(SIMPLEPIE_NAMESPACE_ATOM_03, 'id'),
				array(SIMPLEPIE_NAMESPACE_RSS_20, 'guid'),
				array(SIMPLEPIE_NAMESPACE_DC_11, 'identifier'),
				array(SIMPLEPIE_NAMESPACE_DC_10, 'identifier'),
			);

			foreach ($search as $pair) :
				if ($id_tags = $this->simplepie->get_feed_tags($pair[0], $pair[1])) :
					$ret = $id_tags[0]['data'];
					break;
				elseif ($id_tags = $this->simplepie->get_channel_tags($pair[0], $pair[1])) :
					$ret = $id_tags[0]['data'];
					break;
				endif;
			endforeach;
		endif;
		return $ret;
	}

	public function links ($params = array()) {
		$params = wp_parse_args($params, array(
		"rel" => NULL,
		));
		
		$fLinks = array();
		$search = array(
			array('', 'link'),
			array(SIMPLEPIE_NAMESPACE_ATOM_10, 'link'),
			array(SIMPLEPIE_NAMESPACE_ATOM_03, 'link'),
		);
		
		foreach ($search as $pair) :
			if ($link_tags = $this->simplepie->get_feed_tags($pair[0], $pair[1])) :
				$fLinks = array_merge($fLinks, $link_tags);
			endif;
			if ($link_tags = $this->simplepie->get_channel_tags($pair[0], $pair[1])) :
				$fLinks = array_merge($fLinks, $link_tags);
			endif;
		endforeach;
		
		$ret = array();
		foreach ($fLinks as $link) :
			$filter = false;
			if (!is_null($params['rel'])) :
				$filter = true;

				if (isset($link['attribs'])) :
					// Get a list of NSes from the search
					foreach ($search as $pair) :
						$ns = $pair[0];
						
						if (isset($link['attribs'][$ns])
						and isset($link['attribs'][$ns]['rel'])
						) :
							$rel = strtolower(trim($link['attribs'][$ns]['rel']));
							$fRel = strtolower(trim($params['rel']));
					
							if ($rel == $fRel) :
								$filter = false;
							endif;
						endif;
					endforeach;
				endif;
			endif;
			
			if (!$filter) :
				$ret[] = $link;
			endif;
		endforeach;

		return $ret;
	}
	
	public function ttl ($return_element = false) {
		if (is_object($this->magpie)) :
			$channel = $this->magpie->channel;
		else :
			$channel = array();
		endif;

		if (isset($channel['ttl'])) :
			// "ttl stands for time to live. It's a number of
			// minutes that indicates how long a channel can be
			// cached before refreshing from the source."
			// <http://blogs.law.harvard.edu/tech/rss#ltttlgtSubelementOfLtchannelgt>
			$xml = 'rss:ttl';
			$ret = $channel['ttl'];
		elseif (isset($channel['sy']['updatefrequency']) or isset($channel['sy']['updateperiod'])) :
			$period_minutes = array (
				'hourly' => 60, /* minutes in an hour */
				'daily' => 1440, /* minutes in a day */
				'weekly' => 10080, /* minutes in a week */
				'monthly' => 43200, /* minutes in  a month */
				'yearly' => 525600, /* minutes in a year */
			);

			// "sy:updatePeriod: Describes the period over which the
			// channel format is updated. Acceptable values are:
			// hourly, daily, weekly, monthly, yearly. If omitted,
			// daily is assumed." <http://web.resource.org/rss/1.0/modules/syndication/>
			if (isset($channel['sy']['updateperiod'])) : $period = $channel['sy']['updateperiod'];
			else : $period = 'daily';
			endif;

			// "sy:updateFrequency: Used to describe the frequency
			// of updates in relation to the update period. A
			// positive integer indicates how many times in that
			// period the channel is updated. ... If omitted a value
			// of 1 is assumed." <http://web.resource.org/rss/1.0/modules/syndication/>
			if (isset($channel['sy']['updatefrequency'])) : $freq = (int) $channel['sy']['updatefrequency'];
			else : $freq = 1;
			endif;

			$xml = 'sy:updateFrequency';
			$ret = (int) ($period_minutes[$period] / $freq);
		else :
			$xml = NULL;
			$ret = NULL;
		endif;

		if ('yes'==$this->setting('update/minimum', 'update_minimum', 'no')) :
			$min = (int) $this->setting('update/window', 'update_window', DEFAULT_UPDATE_PERIOD);

			if ($min > $ret) :
				$ret = NULL;
			endif;
		endif;
		return ($return_element ? array($ret, $xml) : $ret);
	} /* SyndicatedLink::ttl() */

	public function automatic_ttl () {
		// spread out over a time interval for staggered updates
		$updateWindow = $this->setting('update/window', 'update_window', DEFAULT_UPDATE_PERIOD);
		if (!is_numeric($updateWindow) or ($updateWindow < 1)) :
			$updateWindow = DEFAULT_UPDATE_PERIOD;
		endif;

		// We get a fudge of 1/3 of window from elsewhere. We'll do some more
		// fudging here.
		$fudgedInterval = $updateWindow+rand(-($updateWindow/6), 5*($updateWindow/12));
		return apply_filters('syndicated_feed_automatic_ttl', $fudgedInterval, $this);
	} /* SyndicatedLink::automatic_ttl () */

	/**
	 * SyndicatedLink::flatten_array (): flatten an array. Useful for
	 * hierarchical and namespaced elements.
	 *
	 * Given an array which may contain array or object elements in it,
	 * return a "flattened" array: a one-dimensional array of scalars
	 * containing each of the scalar elements contained within the array
	 * structure. Thus, for example, if $a['b']['c']['d'] == 'e', then the
	 * returned array for FeedWordPress::flatten_array($a) will contain a key
	 * $a['feed/b/c/d'] with value 'e'.
	 *
	 * @param array $arr
	 * @param string $prefix
	 * @param string $separator
	 * @return array
	 */
	public function flatten_array ($arr, $prefix = 'feed/', $separator = '/') {
		$ret = array ();
		if (is_array($arr)) :
			foreach ($arr as $key => $value) :
				if (is_scalar($value)) :
					$ret[$prefix.$key] = $value;
				else :
					$ret = array_merge($ret, $this->flatten_array($value, $prefix.$key.$separator, $separator));
				endif;
			endforeach;
		endif;
		return $ret;
	} /* SyndicatedLink::flatten_array () */

	public function hardcode ($what) {

		$ret = $this->setting('hardcode '.$what, 'hardcode_'.$what, NULL);

		if ('yes' == $ret) :
			$ret = true;
		else :
			$ret = false;
		endif;
		return $ret;
	} /* SyndicatedLink::hardcode () */

	public function syndicated_status ($what, $default, $fallback = true) {
		global $wpdb;

		$g_set = ($fallback ? 'syndicated_' . $what . '_status' : NULL);
		$ret = $this->setting($what.' status', $g_set, $default);

		return esc_sql(trim(strtolower($ret)));
	} /* SyndicatedLink:syndicated_status () */

	public function taxonomies () {
		$post_type = $this->setting('syndicated post type', 'syndicated_post_type', 'post');
		return get_object_taxonomies(array('object_type' => $post_type), 'names');
	} /* SyndicatedLink::taxonomies () */

	/**
	 * category_ids: look up (and create) category ids from a list of
	 * categories
	 *
	 * @param array $cats
	 * @param string $unfamiliar_category
	 * @param array|null $taxonomies
	 * @return array
	 */
	public function category_ids ($post, $cats, $unfamiliar_category = 'create', $taxonomies = NULL, $params = array()) {
		$singleton = (isset($params['singleton']) ? $params['singleton'] : true);
		$allowFilters = (isset($params['filters']) ? $params['filters'] : false);

		$catTax = 'category';

		if (is_null($taxonomies)) :
			$taxonomies = array('category');
		endif;

		// We need to normalize whitespace because (1) trailing
		// whitespace can cause PHP and MySQL not to see eye to eye on
		// VARCHAR comparisons for some versions of MySQL (cf.
		// <http://dev.mysql.com/doc/mysql/en/char.html>), and (2)
		// because I doubt most people want to make a semantic
		// distinction between 'Computers' and 'Computers  '
		$cats = array_map('trim', $cats);

		$terms = array();
		foreach ($taxonomies as $tax) :
			$terms[$tax] = array();
		endforeach;

		foreach ($cats as $cat_name) :
			if (strlen(trim($cat_name)) < 1) :
				continue;
			endif;

			$oTerm = new SyndicatedPostTerm($cat_name, $taxonomies, $post);

			if ($oTerm->is_familiar()) :

				$tax = $oTerm->taxonomy();
				if (!isset($terms[$tax])) :
					$terms[$tax] = array();
				endif;
				$terms[$tax][] = $oTerm->id();

			else :

				if ('tag'==$unfamiliar_category) :
					$unfamiliar_category = 'create:post_tag';
				endif;

				if (preg_match('/^create(:(.*))?$/i', $unfamiliar_category, $ref)) :
					$tax = $catTax; // Default

					if (isset($ref[2])
					and strlen($ref[2]) > 2) :
						$tax = $ref[2];
					endif;

					$inserted = $oTerm->insert($tax);
					if (!is_null($inserted)) :
						if (!isset($terms[$tax])) :
							$terms[$tax] = array();
						endif;
						$terms[$tax][] = $inserted;
					else :

					endif; // !is_null($inserted)
				endif; // preg_match(...)

			endif; /* ($oTerm->is_familiar()) */
		endforeach;

		$filtersOn = $allowFilters;
		if ($allowFilters) :
			$filters = array_filter(
				$this->setting('match/filter', 'match_filter', array()),
				'remove_dummy_zero'
			);
			$filtersOn = ($filtersOn and is_array($filters) and (count($filters) > 0));
		endif;

		// Check for filter conditions
		foreach ($terms as $tax => $term_ids) :
			if ($filtersOn
			and (count($term_ids)==0)
			and in_array($tax, $filters)) :
				$terms = NULL; // Drop the post
				break;
			else :
				$terms[$tax] = array_unique($term_ids);
			endif;
		endforeach;

		if ($singleton and count($terms)==1) : // If we only searched one, just return the term IDs
			$terms = end($terms);
		endif;

		FeedWordPress::diagnostic(
			'syndicated_posts:categories',
			'Category: MAPPED term names '.json_encode($cats).' to IDs: '.json_encode($terms)
		);
		return $terms;
	} /* SyndicatedLink::category_ids () */
} /* class SyndicatedLink */

