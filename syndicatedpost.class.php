<?php
require_once(dirname(__FILE__).'/feedtime.class.php');

/**
 * class SyndicatedPost: FeedWordPress uses to manage the conversion of
 * incoming items from the feed parser into posts for the WordPress
 * database. It contains several internal management methods primarily
 * of interest to someone working on the FeedWordPress source, as well
 * as some utility methods for extracting useful data from many
 * different feed formats, which may be useful to FeedWordPress users
 * who make use of feed data in PHP add-ons and filters.
 *
 * @version 2011.0831
 */
class SyndicatedPost {
	var $item = null;	// MagpieRSS representation
	var $entry = null;	// SimplePie_Item representation

	var $link = null;
	var $feed = null;
	var $feedmeta = null;
	
	var $xmlns = array ();

	var $post = array ();

	var $named = array ();
	var $preset_terms = array ();
	var $feed_terms = array ();
	
	var $_freshness = null;
	var $_wp_id = null;
	var $_wp_post = null;

	/**
	 * SyndicatedPost constructor: Given a feed item and the source from
	 * which it was taken, prepare a post that can be inserted into the
	 * WordPress database on request, or updated in place if it has already
	 * been syndicated.
	 *
	 * @param array $item The item syndicated from the feed.
	 * @param SyndicatedLink $source The feed it was syndicated from.
	 */
	function SyndicatedPost ($item, &$source) {
		global $wpdb;

		if (is_array($item)
		and isset($item['simplepie'])
		and isset($item['magpie'])) :
			$this->entry = $item['simplepie'];
			$this->item = $item['magpie'];
			$item = $item['magpie'];
		elseif (is_a($item, 'SimplePie_Item')) :
			$this->entry = $item;
			
			// convert to Magpie for compat purposes
			$mp = new MagpieFromSimplePie($source->simplepie, $this->entry);
			$this->item = $mp->get_item();
			
			// done with conversion object
			$mp = NULL; unset($mp);
		else :
			$this->item = $item;
		endif;

		$this->link =& $source;
		$this->feed = $source->magpie;
		$this->feedmeta = $source->settings;

		FeedWordPress::diagnostic('feed_items', 'Considering item ['.$this->guid().'] "'.$this->entry->get_title().'"');

		# Dealing with namespaces can get so fucking fucked.
		$this->xmlns['forward'] = $source->magpie->_XMLNS_FAMILIAR;
		$this->xmlns['reverse'] = array();
		foreach ($this->xmlns['forward'] as $url => $ns) :
			if (!isset($this->xmlns['reverse'][$ns])) :
				$this->xmlns['reverse'][$ns] = array();
			endif;
			$this->xmlns['reverse'][$ns][] = $url; 
		endforeach;
		
		// Fucking SimplePie.
		$this->xmlns['reverse']['rss'][] = '';

		# These globals were originally an ugly kludge around a bug in
		# apply_filters from WordPress 1.5. The bug was fixed in 1.5.1,
		# and I sure hope at this point that nobody writing filters for
		# FeedWordPress is still relying on them.
		#
		# Anyway, I hereby declare them DEPRECATED as of 8 February
		# 2010. I'll probably remove the globals within 1-2 releases in
		# the interests of code hygiene and memory usage. If you
		# currently use them in your filters, I advise you switch off to
		# accessing the public members SyndicatedPost::feed and
		# SyndicatedPost::feedmeta.

		global $fwp_channel, $fwp_feedmeta;
		$fwp_channel = $this->feed; $fwp_feedmeta = $this->feedmeta;

		// Trigger global syndicated_item filter.
		$changed = apply_filters('syndicated_item', $this->item, $this);
		$this->item = $changed;
		
		// Allow for feed-specific syndicated_item filters.
		$changed = apply_filters(
			"syndicated_item_".$source->uri(),
			$this->item,
			$this
		);
		$this->item = $changed;
		
		# Filters can halt further processing by returning NULL
		if (is_null($this->item)) :
			$this->post = NULL;
		else :
			# Note that nothing is run through $wpdb->escape() here.
			# That's deliberate. The escaping is done at the point
			# of insertion, not here, to avoid double-escaping and
			# to avoid screwing with syndicated_post filters

			$this->post['post_title'] = apply_filters(
				'syndicated_item_title',
				$this->entry->get_title(), $this
			);

			$this->named['author'] = apply_filters(
				'syndicated_item_author',
				$this->author(), $this
			);
			// This just gives us an alphanumeric name for the author.
			// We look up (or create) the numeric ID for the author
			// in SyndicatedPost::add().

			$this->post['post_content'] = apply_filters(
				'syndicated_item_content',
				$this->content(), $this
			);
			
			$excerpt = apply_filters('syndicated_item_excerpt', $this->excerpt(), $this);
			if (!empty($excerpt)):
				$this->post['post_excerpt'] = $excerpt;
			endif;

			// Dealing with timestamps in WordPress is so fucking fucked.
			$offset = (int) get_option('gmt_offset') * 60 * 60;
			$post_date_gmt = $this->published(array('default' => -1));
			$post_modified_gmt = $this->updated(array('default' => -1));

			$this->post['post_date_gmt'] = gmdate('Y-m-d H:i:s', $post_date_gmt);
			$this->post['post_date'] = gmdate('Y-m-d H:i:s', $post_date_gmt + $offset);
			$this->post['post_modified_gmt'] = gmdate('Y-m-d H:i:s', $post_modified_gmt);
			$this->post['post_modified'] = gmdate('Y-m-d H:i:s', $post_modified_gmt + $offset);

			// Use feed-level preferences or the global default.
			$this->post['post_status'] = $this->link->syndicated_status('post', 'publish');
			$this->post['comment_status'] = $this->link->syndicated_status('comment', 'closed');
			$this->post['ping_status'] = $this->link->syndicated_status('ping', 'closed');

			// Unique ID (hopefully a unique tag: URI); failing that, the permalink
			$this->post['guid'] = apply_filters('syndicated_item_guid', $this->guid(), $this);

			// User-supplied custom settings to apply to each post.
			// Do first so that FWP-generated custom settings will
			// overwrite if necessary; thus preventing any munging.
			$postMetaIn = $this->link->postmeta(array("parsed" => true));
			$postMetaOut = array();
			
			foreach ($postMetaIn as $key => $meta) :
				$postMetaOut[$key] = $meta->do_substitutions($this);
			endforeach;
			
			foreach ($postMetaOut as $key => $values) :
				$this->post['meta'][$key] = array();
				foreach ($values as $value) :
					$this->post['meta'][$key][] = apply_filters("syndicated_post_meta_{$key}", $value, $this);
				endforeach;
			endforeach;
			
			// RSS 2.0 / Atom 1.0 enclosure support
			$enclosures = $this->entry->get_enclosures();
			if (is_array($enclosures)) : foreach ($enclosures as $enclosure) :
				$this->post['meta']['enclosure'][] =
					apply_filters('syndicated_item_enclosure_url', $enclosure->get_link(), $this)."\n".
					apply_filters('syndicated_item_enclosure_length', $enclosure->get_length(), $this)."\n".
					apply_filters('syndicated_item_enclosure_type', $enclosure->get_type(), $this);
			endforeach; endif;

			// In case you want to point back to the blog this was
			// syndicated from.
			
			$sourcemeta['syndication_source'] = apply_filters(
				'syndicated_item_source_title',
				$this->link->name(),
				$this
			);
			$sourcemeta['syndication_source_uri'] = apply_filters(
				'syndicated_item_source_link',
				$this->link->homepage(),
				$this
			);
			$sourcemeta['syndication_source_id'] = apply_filters(
				'syndicated_item_source_id',
				$this->link->guid(),
				$this
			);
			
			// Make use of atom:source data, if present in an aggregated feed
			$entry_source = $this->source();
			if (!is_null($entry_source)) :
				foreach ($entry_source as $what => $value) :
					if (!is_null($value)) :
						if ($what=='title') : $key = 'syndication_source';
						elseif ($what=='feed') : $key = 'syndication_feed';
						else : $key = "syndication_source_${what}";
						endif;
						
						$sourcemeta["${key}_original"] = apply_filters(
							'syndicated_item_original_source_'.$what,
							$value,
							$this
						);
					endif;
				endforeach;
			endif;
			
			foreach ($sourcemeta as $meta_key => $value) :
				if (!is_null($value)) :
					$this->post['meta'][$meta_key] = $value;
				endif;
			endforeach;

			// Store information on human-readable and machine-readable comment URIs
			
			// Human-readable comment URI
			$commentLink = apply_filters('syndicated_item_comments', $this->comment_link(), $this);
			if (!is_null($commentLink)) : $this->post['meta']['rss:comments'] = $commentLink; endif;

			// Machine-readable content feed URI
			$commentFeed = apply_filters('syndicated_item_commentrss', $this->comment_feed(), $this);
			if (!is_null($commentFeed)) :	$this->post['meta']['wfw:commentRSS'] = $commentFeed; endif;
			// Yeah, yeah, now I know that it's supposed to be
			// wfw:commentRss. Oh well. Path dependence, sucka.

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
			$this->post['meta']['syndication_permalink'] = apply_filters('syndicated_item_link', $this->permalink());

			// Store a hash of the post content for checking whether something needs to be updated
			$this->post['meta']['syndication_item_hash'] = $this->update_hash();

			// Categories: start with default categories, if any.
			$cats = array();
			if ('no' != $this->link->setting('add/category', NULL, 'yes')) :
				$fc = get_option("feedwordpress_syndication_cats");
				if ($fc) :
					$cats = array_merge($cats, explode("\n", $fc));
				endif;
			endif;

			$fc = $this->link->setting('cats', NULL, array());
			if (is_array($fc)) :
				$cats = array_merge($cats, $fc);
			endif;
			$this->preset_terms['category'] = $cats;
			
			// Now add categories from the post, if we have 'em
			$cats = array();
			$post_cats = $this->entry->get_categories();
			if (is_array($post_cats)) : foreach ($post_cats as $cat) :
				$cat_name = $cat->get_term();
				if (!$cat_name) : $cat_name = $cat->get_label(); endif;
				
				if ($this->link->setting('cat_split', NULL, NULL)) :
					$pcre = "\007".$this->feedmeta['cat_split']."\007";
					$cats = array_merge(
						$cats,
						preg_split(
							$pcre,
							$cat_name,
							-1 /*=no limit*/,
							PREG_SPLIT_NO_EMPTY
						)
					);
				else :
					$cats[] = $cat_name;
				endif;
			endforeach; endif;

			$this->feed_terms['category'] = apply_filters('syndicated_item_categories', $cats, $this);
			
			// Tags: start with default tags, if any
			$tags = array();
			if ('no' != $this->link->setting('add/post_tag', NULL, 'yes')) :
				$ft = get_option("feedwordpress_syndication_tags", NULL);
				$tags = (is_null($ft) ? array() : explode(FEEDWORDPRESS_CAT_SEPARATOR, $ft));
			endif;
			
			$ft = $this->link->setting('tags', NULL, array());
			if (is_array($ft)) :
				$tags = array_merge($tags, $ft);
			endif;
			$this->preset_terms['post_tag'] = $tags;
			
			// Scan post for /a[@rel='tag'] and use as tags if present
			$tags = $this->inline_tags();
			$this->feed_terms['post_tag'] = apply_filters('syndicated_item_tags', $tags, $this);
			
			$taxonomies = $this->link->taxonomies();
			$feedTerms = $this->link->setting('terms', NULL, array());
			$globalTerms = get_option('feedwordpress_syndication_terms', array());

			$specials = array('category' => 'cats', 'post_tag' => 'tags');
			foreach ($taxonomies as $tax) :
				if (!isset($specials[$tax])) :
					$terms = array();
				
					// See if we should get the globals
					if ('no' != $this->link->setting("add/$tax", NULL, 'yes')) :
						if (isset($globalTerms[$tax])) :
							$terms = $globalTerms[$tax];
						endif;
					endif;
					
					// Now merge in the locals
					if (isset($feedTerms[$tax])) :
						$terms = array_merge($terms, $feedTerms[$tax]);
					endif;

					// That's all, folks.
					$this->preset_terms[$tax] = $terms;
				endif;
			endforeach;

			$this->post['post_type'] = apply_filters('syndicated_post_type', $this->link->setting('syndicated post type', 'syndicated_post_type', 'post'), $this);
		endif;
	} /* SyndicatedPost::SyndicatedPost() */

	#####################################
	#### EXTRACT DATA FROM FEED ITEM ####
	#####################################

	function substitution_function ($name) {
		$ret = NULL;
		
		switch ($name) :
		// Allowed PHP string functions
		case 'trim':
		case 'ltrim':
		case 'rtrim':
		case 'strtoupper':
		case 'strtolower':
		case 'urlencode':
		case 'urldecode':
			$ret = $name;
		endswitch;
		return $ret;
	}
	
	/**
	 * SyndicatedPost::query uses an XPath-like syntax to query arbitrary
	 * elements within the syndicated item.
	 *
	 * @param string $path
	 * @returns array of string values representing contents of matching
	 * elements or attributes
	 */
	 function query ($path) {
	 	$urlHash = array();

	 	// Allow {url} notation for namespaces. URLs will contain : and /, so...
	 	preg_match_all('/{([^}]+)}/', $path, $match, PREG_SET_ORDER);
	 	foreach ($match as $ref) :
	 	 	$urlHash[md5($ref[1])] = $ref[1];
		endforeach;
	
		foreach ($urlHash as $hash => $url) :
			$path = str_replace('{'.$url.'}', '{#'.$hash.'}', $path); 
		endforeach;

		$path = explode('/', $path);
		foreach ($path as $index => $node) :
			if (preg_match('/{#([^}]+)}/', $node, $ref)) :
				if (isset($urlHash[$ref[1]])) :
					$path[$index] = str_replace(
						'{#'.$ref[1].'}',
						'{'.$urlHash[$ref[1]].'}',
						$node
					);
				endif;
			endif;
		endforeach;

		// Start out with a get_item_tags query.
		$node = '';
		while (strlen($node)==0 and !is_null($node)) :
			$node = array_shift($path);
		endwhile;
		
		switch ($node) :
		case 'feed' :
		case 'channel' :
			$node = array_shift($path);
			$data = $this->get_feed_root_element();
			$data = array_merge($data, $this->get_feed_channel_elements());
			break;
		case 'item' :
			$node = array_shift($path);
		default :
			$data = array($this->entry->data);
			$method = NULL;
		endswitch;

		while (!is_null($node)) :
			if (strlen($node) > 0) :
				$matches = array();

				list($axis, $element) = $this->xpath_name_and_axis($node);

				foreach ($data as $datum) :
					if (!is_string($datum) and isset($datum[$axis])) :
						foreach ($datum[$axis] as $ns => $elements) :
							if (isset($elements[$element])) :
								// Potential match.
								// Check namespace.
								if (is_string($elements[$element])) : // Attribute
									$addenda = array($elements[$element]);
									$contexts = array($datum);
								else : // Element
									$addenda = $elements[$element];
									$contexts = $elements[$element];
								endif;
								
								foreach ($addenda as $index => $addendum) :
									$context = $contexts[$index];

									$namespaces = $this->xpath_possible_namespaces($node, $context);
									if (in_array($ns, $namespaces)) :
										$matches[] = $addendum;
									endif;
								endforeach;
							endif;
						endforeach;						
					endif;
				endforeach;
		
				$data = $matches;
			endif;
			$node = array_shift($path);
		endwhile;
	
		$matches = array();
		foreach ($data as $datum) :
			if (is_string($datum)) :
				$matches[] = $datum;
			elseif (isset($datum['data'])) :
				$matches[] = $datum['data'];
			endif;
		endforeach;
		return $matches;
	} /* SyndicatedPost::query() */

	function get_feed_root_element () {
		$matches = array();
		foreach ($this->link->simplepie->data['child'] as $ns => $root) :
			foreach ($root as $element => $data) :
				$matches = array_merge($matches, $data);
			endforeach;
		endforeach;
		return $matches;
	} /* SyndicatedPost::get_feed_root_element() */

	function get_feed_channel_elements () {
		$rss = array(
				SIMPLEPIE_NAMESPACE_RSS_090,
				SIMPLEPIE_NAMESPACE_RSS_10,
				'http://backend.userland.com/RSS2',
				SIMPLEPIE_NAMESPACE_RSS_20,
		);
		
		$matches = array();
		foreach ($rss as $ns) :
			$data = $this->link->simplepie->get_feed_tags($ns, 'channel');
			if (!is_null($data)) :
				$matches = array_merge($matches, $data);
			endif;
		endforeach;
		return $matches;
	} /* SyndicatedPost::get_feed_channel_elements() */

	function xpath_default_namespace () {
		// Get the default namespace.
		$type = $this->link->simplepie->get_type();
		if ($type & SIMPLEPIE_TYPE_ATOM_10) :
			$defaultNS = SIMPLEPIE_NAMESPACE_ATOM_10;
		elseif ($type & SIMPLEPIE_TYPE_ATOM_03) :
			$defaultNS = SIMPLEPIE_NAMESPACE_ATOM_03;
		elseif ($type & SIMPLEPIE_TYPE_RSS_090) :
			$defaultNS = SIMPLEPIE_NAMESPACE_RSS_090;
		elseif ($type & SIMPLEPIE_TYPE_RSS_10) :
			$defaultNS = SIMPLEPIE_NAMESPACE_RSS_10;
		elseif ($type & SIMPLEPIE_TYPE_RSS_20) :
			$defaultNS = SIMPLEPIE_NAMESPACE_RSS_20;
		else :
			$defaultNS = SIMPLEPIE_NAMESPACE_RSS_20;
		endif;
		return $defaultNS;	
	} /* SyndicatedPost::xpath_default_namespace() */

	function xpath_name_and_axis ($node) {
		$ns = NULL; $element = NULL;

		if (substr($node, 0, 1)=='@') :
			$axis = 'attribs'; $node = substr($node, 1);
		else :
			$axis = 'child';
		endif;

		if (preg_match('/^{([^}]*)}(.*)$/', $node, $ref)) :
			$element = $ref[2];
		elseif (strpos($node, ':') !== FALSE) :
			list($xmlns, $element) = explode(':', $node, 2);
		else :
			$element = $node;
		endif;
		return array($axis, $element);
	} /* SyndicatedPost::xpath_local_name () */

	function xpath_possible_namespaces ($node, $datum = array()) {
		$ns = NULL; $element = NULL;

		if (substr($node, 0, 1)=='@') :
			$attr = '@'; $node = substr($node, 1);
		else :
			$attr = '';
		endif;

		if (preg_match('/^{([^}]*)}(.*)$/', $node, $ref)) :
			$ns = array($ref[1]);
		elseif (strpos($node, ':') !== FALSE) :
			list($xmlns, $element) = explode(':', $node, 2);
			
			if (isset($this->xmlns['reverse'][$xmlns])) :
				$ns = $this->xmlns['reverse'][$xmlns];
			else :
				$ns = array($xmlns);
			endif;
			
			// Fucking SimplePie. For attributes in default xmlns.
			$defaultNS = $this->xpath_default_namespace();
			if (isset($this->xmlns['forward'][$defaultNS])
			and ($xmlns==$this->xmlns['forward'][$defaultNS])) :
				$ns[] = '';
			endif;
			
			if (isset($datum['xmlns'])) :
				if (isset($datum['xmlns'][$xmlns])) :
					$ns[] = $datum['xmlns'][$xmlns];
				endif;
			endif;
		else :
			// Often in SimplePie, the default namespace gets stored
			// as an empty string rather than a URL.
			$ns = array($this->xpath_default_namespace(), '');
		endif;
		return array_unique($ns);
	} /* SyndicatedPost::xpath_possible_namespaces() */

	function content () {
		$content = NULL;
		if (isset($this->item['atom_content'])) :
			$content = $this->item['atom_content'];
		elseif (isset($this->item['xhtml']['body'])) :
			$content = $this->item['xhtml']['body'];
		elseif (isset($this->item['xhtml']['div'])) :
			$content = $this->item['xhtml']['div'];
		elseif (isset($this->item['content']['encoded']) and $this->item['content']['encoded']):
			$content = $this->item['content']['encoded'];
		elseif (isset($this->item['description'])) :
			$content = $this->item['description'];
		endif;
		return $content;
	} /* SyndicatedPost::content() */

	function excerpt () {
		# Identify and sanitize excerpt: atom:summary, or rss:description
		$excerpt = $this->entry->get_description();
			
		# Many RSS feeds use rss:description, inadvisably, to
		# carry the entire post (typically with escaped HTML).
		# If that's what happened, we don't want the full
		# content for the excerpt.
		$content = $this->content();
		
		// Ignore whitespace, case, and tag cruft.
		$theExcerpt = preg_replace('/\s+/', '', strtolower(strip_tags($excerpt)));
		$theContent = preg_replace('/\s+/', '', strtolower(strip_tags($content)));

		if ( empty($excerpt) or $theExcerpt == $theContent ) :
			# If content is available, generate an excerpt.
			if ( strlen(trim($content)) > 0 ) :
				$excerpt = strip_tags($content);
				if (strlen($excerpt) > 255) :
					$excerpt = substr($excerpt,0,252).'...';
				endif;
			endif;
		endif;
		return $excerpt;
	} /* SyndicatedPost::excerpt() */

	function permalink () {
		// Handles explicit <link> elements and also RSS 2.0 cases with
		// <guid isPermaLink="true">, etc. Hooray!
		$permalink = $this->entry->get_link();
		return $permalink;
	}

	function created ($params = array()) {
		$unfiltered = false; $default = NULL;
		extract($params);
		
		$date = '';
		if (isset($this->item['dc']['created'])) :
			$date = $this->item['dc']['created'];
		elseif (isset($this->item['dcterms']['created'])) :
			$date = $this->item['dcterms']['created'];
		elseif (isset($this->item['created'])): // Atom 0.3
			$date = $this->item['created'];
		endif;

		$time = new FeedTime($date);
		$ts = $time->timestamp();
		if (!$unfiltered) :
			apply_filters('syndicated_item_created', $ts, $this);
		endif;
		return $ts;
	} /* SyndicatedPost::created() */

	function published ($params = array(), $default = NULL) {
		$fallback = true; $unfiltered = false;
		if (!is_array($params)) : // Old style
			$fallback = $params;
		else : // New style
			extract($params);
		endif;
		
		$date = '';
		$ts = null;

		# RSS is a fucking mess. Figure out whether we have a date in
		# <dc:date>, <issued>, <pubDate>, etc., and get it into Unix
		# epoch format for reformatting. If we can't find anything,
		# we'll use the last-updated time.
		if (isset($this->item['dc']['date'])):				// Dublin Core
			$date = $this->item['dc']['date'];
		elseif (isset($this->item['dcterms']['issued'])) :		// Dublin Core extensions
			$date = $this->item['dcterms']['issued'];
		elseif (isset($this->item['published'])) : 			// Atom 1.0
			$date = $this->item['published'];
		elseif (isset($this->item['issued'])): 				// Atom 0.3
			$date = $this->item['issued'];
		elseif (isset($this->item['pubdate'])):				// RSS 2.0
			$date = $this->item['pubdate'];
		endif;
		
		if (strlen($date) > 0) :
			$time = new FeedTime($date);
			$ts = $time->timestamp();
		elseif ($fallback) :						// Fall back to <updated> / <modified> if present
			$ts = $this->updated(/*fallback=*/ false, /*default=*/ $default);
		endif;
		
		# If everything failed, then default to the current time.
		if (is_null($ts)) :
			if (-1 == $default) :
				$ts = time();
			else :
				$ts = $default;
			endif;
		endif;
		
		if (!$unfiltered) :
			$ts = apply_filters('syndicated_item_published', $ts, $this);
		endif;
		return $ts;
	} /* SyndicatedPost::published() */

	function updated ($params = array(), $default = -1) {
		$fallback = true; $unfiltered = false;
		if (!is_array($params)) : // Old style
			$fallback = $params;
		else : // New style
			extract($params);
		endif;

		$date = '';
		$ts = null;

		# As far as I know, only dcterms and Atom have reliable ways to
		# specify when something was *modified* last. If neither is
		# available, then we'll try to get the time of publication.
		if (isset($this->item['dc']['modified'])) : 			// Not really correct
			$date = $this->item['dc']['modified'];
		elseif (isset($this->item['dcterms']['modified'])) :		// Dublin Core extensions
			$date = $this->item['dcterms']['modified'];
		elseif (isset($this->item['modified'])):			// Atom 0.3
			$date = $this->item['modified'];
		elseif (isset($this->item['updated'])):			// Atom 1.0
			$date = $this->item['updated'];
		endif;
		
		if (strlen($date) > 0) :
			$time = new FeedTime($date);
			$ts = $time->timestamp();
		elseif ($fallback) :						// Fall back to issued / dc:date
			$ts = $this->published(/*fallback=*/ false, /*default=*/ $default);
		endif;
		
		# If everything failed, then default to the current time.
		if (is_null($ts)) :
			if (-1 == $default) :
				$ts = time();
			else :
				$ts = $default;
			endif;
		endif;

		if (!$unfiltered) :
			apply_filters('syndicated_item_updated', $ts, $this);
		endif;
		return $ts;
	} /* SyndicatedPost::updated() */

	var $_hashes = array();
	function stored_hashes ($id = NULL) {
		if (is_null($id)) :
			$id = $this->wp_id();
		endif;

		if (!isset($this->_hashes[$id])) :
			$this->_hashes[$id] = get_post_custom_values(
				'syndication_item_hash', $id
			);
			if (is_null($this->_hashes[$id])) :
				$this->_hashes[$id] = array();
			endif;
		endif;
		return $this->_hashes[$id];
	}

	function update_hash ($hashed = true) {
		// Basis for tracking possible changes to item.
		$hash = array(
			"title" => $this->entry->get_title(),
			"link" => $this->permalink(),
			"content" => $this->content(),
			"excerpt" => $this->excerpt(),
		);
		
		if ($hashed) :
			$hash = md5(serialize($hash));
		endif;
		
		return $hash;
	} /* SyndicatedPost::update_hash() */

	/*static*/ function normalize_guid_prefix () {
		return trailingslashit(get_bloginfo('url')).'?guid=';
	}
	
	/*static*/ function normalize_guid ($guid) {
		$guid = trim($guid);
		if (preg_match('/^[0-9a-z]{32}$/i', $guid)) : // MD5
			$guid = SyndicatedPost::normalize_guid_prefix().strtolower($guid);
		elseif ((strlen(esc_url($guid)) == 0) or (esc_url($guid) != $guid)) :
			$guid = SyndicatedPost::normalize_guid_prefix().md5($guid);
		endif;
		$guid = trim($guid);
		return $guid;
	} /* SyndicatedPost::normalize_guid() */
	
	function guid () {
		$guid = null;
		if (isset($this->item['id'])):						// Atom 0.3 / 1.0
			$guid = $this->item['id'];
		elseif (isset($this->item['atom']['id'])) :		// Namespaced Atom
			$guid = $this->item['atom']['id'];
		elseif (isset($this->item['guid'])) :				// RSS 2.0
			$guid = $this->item['guid'];
		elseif (isset($this->item['dc']['identifier'])) :	// yeah, right
			$guid = $this->item['dc']['identifier'];
		endif;
		
		// Un-set or too long to use as-is. Generate a tag: URI.
		if (is_null($guid) or strlen($guid) > 250) :
			// In case we need to check this again
			$original_guid = $guid;
			
			// The feed does not seem to have provided us with a
			// usable unique identifier, so we'll have to cobble
			// together a tag: URI that might work for us. The base
			// of the URI will be the host name of the feed source ...
			$bits = parse_url($this->link->uri());
			$guid = 'tag:'.$bits['host'];

			// Some ill-mannered feeds (for example, certain feeds
			// coming from Google Calendar) have extraordinarily long
			// guids -- so long that they exceed the 255 character
			// width of the WordPress guid field. But if the string
			// gets clipped by MySQL, uniqueness tests will fail
			// forever after and the post will be endlessly
			// reduplicated. So, instead, Guids Of A Certain Length
			// are hashed down into a nice, manageable tag: URI.
			if (!is_null($original_guid)) :
				$guid .= ',2010-12-03:id.'.md5($original_guid);
			
			// If we have a date of creation, then we can use that
			// to uniquely identify the item. (On the other hand, if
			// the feed producer was consicentious enough to
			// generate dates of creation, she probably also was
			// conscientious enough to generate unique identifiers.)
			elseif (!is_null($this->created())) :
				$guid .= '://post.'.date('YmdHis', $this->created());
			
			// Otherwise, use both the URI of the item, *and* the
			// item's title. We have to use both because titles are
			// often not unique, and sometimes links aren't unique
			// either (e.g. Bitch (S)HITLIST, Mozilla Dot Org news,
			// some podcasts). But it's rare to have *both* the same
			// title *and* the same link for two different items. So
			// this is about the best we can do.
			else :
				$link = $this->permalink();
				if (is_null($link)) : $link = $this->link->uri(); endif;
				$guid .= '://'.md5($link.'/'.$this->item['title']);
			endif;
		endif;
		return $guid;
	} /* SyndicatedPost::guid() */
	
	function author () {
		$author = array ();
		
		$aa = $this->entry->get_authors();
		if (count($aa) > 0) :
			$a = reset($aa);

			$author = array(
			'name' => $a->get_name(),
			'email' => $a->get_email(),
			'uri' => $a->get_link(),
			);
		endif;

		if (FEEDWORDPRESS_COMPATIBILITY) :
			// Search through the MagpieRSS elements: Atom, Dublin Core, RSS
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
			endif;
		endif;
		
		if (!isset($author['name']) or is_null($author['name'])) :
			// Nothing found. Try some crappy defaults.
			if ($this->link->name()) :
				$author['name'] = $this->link->name();
			else :
				$url = parse_url($this->link->uri());
				$author['name'] = $url['host'];
			endif;
		endif;
		
		if (FEEDWORDPRESS_COMPATIBILITY) :
			if (isset($this->item['author_email'])):
				$author['email'] = $this->item['author_email'];
			elseif (isset($this->feed->channel['author_email'])) :
				$author['email'] = $this->feed->channel['author_email'];
			endif;
			
			if (isset($this->item['author_uri'])):
				$author['uri'] = $this->item['author_uri'];
			elseif (isset($this->item['author_url'])):
				$author['uri'] = $this->item['author_url'];
			elseif (isset($this->feed->channel['author_uri'])) :
				$author['uri'] = $this->item['author_uri'];
			elseif (isset($this->feed->channel['author_url'])) :
				$author['uri'] = $this->item['author_url'];
			elseif (isset($this->feed->channel['link'])) :
				$author['uri'] = $this->feed->channel['link'];
			endif;
		endif;
		
		return $author;
	} /* SyndicatedPost::author() */

	/**
	 * SyndicatedPost::inline_tags: Return a list of all the tags embedded
	 * in post content using the a[@rel="tag"] microformat.
	 *
	 * @since 2010.0630
	 * @return array of string values containing the name of each tag
	 */
	function inline_tags () {
		$tags = array();
		$content = $this->content();
		$pattern = FeedWordPressHTML::tagWithAttributeRegex('a', 'rel', 'tag');
		preg_match_all($pattern, $content, $refs, PREG_SET_ORDER);
		if (count($refs) > 0) :
			foreach ($refs as $ref) :
				$tag = FeedWordPressHTML::tagWithAttributeMatch($ref);
				$tags[] = $tag['content'];
			endforeach;
		endif;
		return $tags;
	}
	
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

	/**
	 * SyndicatedPost::enclosures: returns an array with any enclosures
	 * that may be attached to this syndicated item.
	 *
	 * @param string $type If you only want enclosures that match a certain
	 *	MIME type or group of MIME types, you can limit the enclosures
	 *	that will be returned to only those with a MIME type which
	 *	matches this regular expression.
	 * @return array
	 */
	function enclosures ($type = '/.*/') {
		$enclosures = array();

		if (isset($this->item['enclosure#'])) :
			// Loop through enclosure, enclosure#2, enclosure#3, ....
			for ($i = 1; $i <= $this->item['enclosure#']; $i++) :
				$eid = (($i > 1) ? "#{$id}" : "");

				// Does it match the type we want?
				if (preg_match($type, $this->item["enclosure{$eid}@type"])) :
					$enclosures[] = array(
						"url" => $this->item["enclosure{$eid}@url"],
						"type" => $this->item["enclosure{$eid}@type"],
						"length" => $this->item["enclosure{$eid}@length"],
					);
				endif;
			endfor;
		endif;
		return $enclosures;		
	} /* SyndicatedPost::enclosures() */

	function source ($what = NULL) {
		$ret = NULL;
		$source = $this->entry->get_source();
		if ($source) :
			$ret = array();
			$ret['title'] = $source->get_title();
			$ret['uri'] = $source->get_link();
			$ret['feed'] = $source->get_link(0, 'self');
			
			if ($id_tags = $source->get_source_tags(SIMPLEPIE_NAMESPACE_ATOM_10, 'id')) :
				$ret['id'] = $id_tags[0]['data'];
			elseif ($id_tags = $source->get_source_tags(SIMPLEPIE_NAMESPACE_ATOM_03, 'id')) :
				$ret['id'] = $id_tags[0]['data'];
			elseif ($id_tags = $source->get_source_tags(SIMPLEPIE_NAMESPACE_RSS_20, 'guid')) :
				$ret['id'] = $id_tags[0]['data'];
			elseif ($id_tags = $source->get_source_tags(SIMPLEPIE_NAMESPACE_RSS_10, 'guid')) :
				$ret['id'] = $id_tags[0]['data'];
			elseif ($id_tags = $source->get_source_tags(SIMPLEPIE_NAMESPACE_RSS_090, 'guid')) :
				$ret['id'] = $id_tags[0]['data'];
			endif;
		endif;
		
		if (!is_null($what) and is_scalar($what)) :
			$ret = $ret[$what];
		endif;
		return $ret;
	}
	
	function comment_link () {
		$url = null;
		
		// RSS 2.0 has a standard <comments> element:
		// "<comments> is an optional sub-element of <item>. If present,
		// it is the url of the comments page for the item."
		// <http://cyber.law.harvard.edu/rss/rss.html#ltcommentsgtSubelementOfLtitemgt>
		if (isset($this->item['comments'])) :
			$url = $this->item['comments'];
		endif;

		// The convention in Atom feeds is to use a standard <link>
		// element with @rel="replies" and @type="text/html".
		// Unfortunately, SimplePie_Item::get_links() allows us to filter
		// by the value of @rel, but not by the value of @type. *sigh*
		
		// Try Atom 1.0 first
		$linkElements = $this->entry->get_item_tags(SIMPLEPIE_NAMESPACE_ATOM_10, 'link');
		
		// Fall back and try Atom 0.3
		if (is_null($linkElements)) : $linkElements =  $this->entry->get_item_tags(SIMPLEPIE_NAMESPACE_ATOM_03, 'link'); endif;
		
		// Now loop through the elements, screening by @rel and @type
		if (is_array($linkElements)) : foreach ($linkElements as $link) :
			$rel = (isset($link['attribs']['']['rel']) ? $link['attribs']['']['rel'] : 'alternate');
			$type = (isset($link['attribs']['']['type']) ? $link['attribs']['']['type'] : NULL);
			$href = (isset($link['attribs']['']['href']) ? $link['attribs']['']['href'] : NULL);

			if (strtolower($rel)=='replies' and $type=='text/html' and !is_null($href)) :
				$url = $href;
			endif;
		endforeach; endif;

		return $url;
	}

	function comment_feed () {
		$feed = null;

		// Well Formed Web comment feeds extension for RSS 2.0
		// <http://www.sellsbrothers.com/spout/default.aspx?content=archive.htm#exposingRssComments>
		//
		// N.B.: Correct capitalization is wfw:commentRss, but
		// wfw:commentRSS is common in the wild (partly due to a typo in
		// the original spec). In any case, our item array is normalized
		// to all lowercase anyways.
		if (isset($this->item['wfw']['commentrss'])) :
			$feed = $this->item['wfw']['commentrss'];
		endif;

		// In Atom 1.0, the convention is to use a standard link element
		// with @rel="replies". Sometimes this is also used to pass a
		// link to the human-readable comments page, so we also need to
		// check link/@type for a feed MIME type.
		//
		// Which is why I'm not using the SimplePie_Item::get_links()
		// method here, incidentally: it doesn't allow you to filter by
		// @type. *sigh*
		if (isset($this->item['link_replies'])) :
			// There may be multiple <link rel="replies"> elements; feeds have a feed MIME type
			$N = isset($this->item['link_replies#']) ? $this->item['link_replies#'] : 1;
			for ($i = 1; $i <= $N; $i++) :
				$currentElement = 'link_replies'.(($i > 1) ? '#'.$i : '');
				if (isset($this->item[$currentElement.'@type'])
				and preg_match("\007application/(atom|rss|rdf)\+xml\007i", $this->item[$currentElement.'@type'])) :
					$feed = $this->item[$currentElement];
				endif;
			endfor;
		endif;
		return $feed;
	} /* SyndicatedPost::comment_feed() */

	##################################
	#### BUILT-IN CONTENT FILTERS ####
	##################################

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
		$url = SimplePie_Misc::absolutize_url($tag['value'], $this->_base);
		return $tag['prefix'] . $url . $tag['suffix'];
	} /* function SyndicatedPost::resolve_single_relative_uri() */

	function resolve_relative_uris ($content, $obj) {
		$set = $obj->link->setting('resolve relative', 'resolve_relative', 'yes');
		if ($set and $set != 'no') : 
			// Fallback: if we don't have anything better, use the
			// item link from the feed
			$obj->_base = $obj->permalink(); // Reset the base for resolving relative URIs

			// What we should do here, properly, is to use
			// SimplePie_Item::get_base() -- but that method is
			// currently broken. Or getting down and dirty in the
			// SimplePie representation of the content tags and
			// grabbing the xml_base member for the content element.
			// Maybe someday...

			foreach ($obj->uri_attrs as $pair) :
				list($tag, $attr) = $pair;
				$pattern = FeedWordPressHTML::attributeRegex($tag, $attr);
				$content = preg_replace_callback (
					$pattern,
					array($obj, 'resolve_single_relative_uri'),
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
				array($obj, 'strip_attribute_from_tag'),
				$content
			);
		endforeach;
		return $content;
	} /* SyndicatedPost::sanitize() */

	#####################
	#### POST STATUS ####
	#####################

	/**
	 * SyndicatedPost::filtered: check whether or not this post has been
	 * screened out by a registered filter.
	 *
	 * @return bool TRUE iff post has been filtered out by a previous filter
	 */
	function filtered () {
		return is_null($this->post);
	} /* SyndicatedPost::filtered() */

	/**
	 * SyndicatedPost::freshness: check whether post is a new post to be
	 * inserted, a previously syndicated post that needs to be updated to
	 * match the latest revision, or a previously syndicated post that is
	 * still up-to-date.
	 *
	 * @return int A status code representing the freshness of the post
	 *	0 = post already syndicated; no update needed
	 *	1 = post already syndicated, but needs to be updated to latest
	 *	2 = post has not yet been syndicated; needs to be created
	 */
	function freshness () {
		global $wpdb;

		if ($this->filtered()) : // This should never happen.
			FeedWordPress::critical_bug('SyndicatedPost', $this, __LINE__, __FILE__);
		endif;
		
		if (is_null($this->_freshness)) : // Not yet checked and cached.
			$guid = $this->post['guid'];
			$eguid = $wpdb->escape($this->post['guid']);

			$q = new WP_Query(array(
				'fields' => '_synfresh', // id, guid, post_modified_gmt
				'ignore_sticky_posts' => true,
				'guid' => $guid,
			));
			
			$old_post = NULL;
			if ($q->have_posts()) :
				while ($q->have_posts()) : $q->the_post();
					$old_post = $q->post;
				endwhile;
			endif;
			
			if (is_null($old_post)) : // No post with this guid
				FeedWordPress::diagnostic('feed_items:freshness', 'Item ['.$guid.'] "'.$this->entry->get_title().'" is a NEW POST.');
				$this->_wp_id = NULL;
				$this->_freshness = 2; // New content
			else :
				preg_match('/([0-9]+)-([0-9]+)-([0-9]+) ([0-9]+):([0-9]+):([0-9]+)/', $old_post->post_modified_gmt, $backref);

				$last_rev_ts = gmmktime($backref[4], $backref[5], $backref[6], $backref[2], $backref[3], $backref[1]);
				$updated_ts = $this->updated(/*fallback=*/ true, /*default=*/ NULL);
				
				// Check timestamps...
				$updated = (
					!is_null($updated_ts)
					and ($updated_ts > $last_rev_ts)
				);

				$updatedReason = NULL;
				if ($updated) :
					$updatedReason = preg_replace(
						"/\s+/", " ",
						'has been marked with a new timestamp ('
						.date('Y-m-d H:i:s', $updated_ts)
						." > "
						.date('Y-m-d H:i:s', $last_rev_ts)
						.')'
					);
				else :
					// Or the hash...
					$hash = $this->update_hash();
					$seen = $this->stored_hashes($old_post->ID);
					if (count($seen) > 0) :
						$updated = !in_array($hash, $seen); // Not seen yet?
					else :
						$updated = true; // Can't find syndication meta-data
					endif;
					
					if ($updated and FeedWordPress::diagnostic_on('feed_items:freshness:reasons')) :
						$updatedReason = ' has a not-yet-seen update hash: '
						.FeedWordPress::val($hash)
						.' not in {'
						.implode(", ", array_map(array('FeedWordPress', 'val'), $seen))
						.'}. Basis: '
						.FeedWordPress::val(array_keys($this->update_hash(false)));
					endif;
				endif;
				
				$frozen = false;
				if ($updated) : // Ignore if the post is frozen
					$frozen = ('yes' == $this->link->setting('freeze updates', 'freeze_updates', NULL));
					if (!$frozen) :
						$frozen_values = get_post_custom_values('_syndication_freeze_updates', $old_post->ID);
						$frozen = (count($frozen_values) > 0 and 'yes' == $frozen_values[0]);
						
						if ($frozen) :
							$updatedReason = ' IS BLOCKED FROM BEING UPDATED BY A UPDATE LOCK ON THIS POST, EVEN THOUGH IT '.$updatedReason;
						endif;
					else :
						$updatedReason = ' IS BLOCKED FROM BEING UPDATED BY A FEEDWORDPRESS UPDATE LOCK, EVEN THOUGH IT '.$updatedReason;
					endif;
				endif;
				$updated = ($updated and !$frozen);

				if ($updated) :
					FeedWordPress::diagnostic('feed_items:freshness', 'Item ['.$guid.'] "'.$this->entry->get_title().'" is an update of an existing post.');
					if (!is_null($updatedReason)) :
						$updatedReason = preg_replace('/\s+/', ' ', $updatedReason);
						FeedWordPress::diagnostic('feed_items:freshness:reasons', 'Item ['.$guid.'] "'.$this->entry->get_title().'" '.$updatedReason);
					endif;
					$this->_freshness = 1; // Updated content
					$this->_wp_id = $old_post->ID;
					$this->_wp_post = $old_post;

					// We want this to keep a running list of all the
					// processed update hashes.
					$this->post['meta']['syndication_item_hash'] = array_merge(
						$this->stored_hashes(),
						array($this->update_hash())
					);
				else :
					FeedWordPress::diagnostic('feed_items:freshness', 'Item ['.$guid.'] "'.$this->entry->get_title().'" is a duplicate of an existing post.');
					$this->_freshness = 0; // Same old, same old
					$this->_wp_id = $old_post->ID;
				endif;
			endif;
		endif;
		return $this->_freshness;
	}

	#################################################
	#### INTERNAL STORAGE AND MANAGEMENT METHODS ####
	#################################################

	function wp_id () {
		if ($this->filtered()) : // This should never happen.
			FeedWordPress::critical_bug('SyndicatedPost', $this, __LINE__, __FILE__);
		endif;
		
		if (is_null($this->_wp_id) and is_null($this->_freshness)) :
			$fresh = $this->freshness(); // sets WP DB id in the process
		endif;
		return $this->_wp_id;
	}

	function store () {
		global $wpdb;

		if ($this->filtered()) : // This should never happen.
			FeedWordPress::critical_bug('SyndicatedPost', $this, __LINE__, __FILE__);
		endif;
		
		$freshness = $this->freshness();
		if ($freshness > 0) :
			# -- Look up, or create, numeric ID for author
			$this->post['post_author'] = $this->author_id (
				$this->link->setting('unfamiliar author', 'unfamiliar_author', 'create')
			);

			if (is_null($this->post['post_author'])) :
				FeedWordPress::diagnostic('feed_items:rejected', 'Filtered out item ['.$this->guid().'] without syndication: no author available');
				$this->post = NULL;
			endif;
		endif;
		
		if (!$this->filtered() and $freshness > 0) :
			$consider = array(
				'category' => array('abbr' => 'cats', 'domain' => array('category', 'post_tag')),
				'post_tag' => array('abbr' => 'tags', 'domain' => array('post_tag')),
			);

			$termSet = array(); $valid = null;
			foreach ($consider as $what => $taxes) :
				if (!is_null($this->post)) : // Not filtered out yet
					# -- Look up, or create, numeric ID for categories
					$taxonomies = $this->link->setting("match/".$taxes['abbr'], 'match_'.$taxes['abbr'], $taxes['domain']);
	
					// Eliminate dummy variables
					$taxonomies = array_filter($taxonomies, 'remove_dummy_zero');
	
					$terms = $this->category_ids (
						$this->feed_terms[$what],
						$this->link->setting("unfamiliar {$what}", "unfamiliar_{$what}", 'create:'.$what),
						/*taxonomies=*/ $taxonomies,
						array(
						  'singleton' => false, // I don't like surprises
						  'filters' => true,
						)
					);
					
					if (is_null($terms) or is_null($termSet)) :
						// filtered out -- no matches
					else :
						$valid = true;

						// filter mode off, or at least one match
						foreach ($terms as $tax => $term_ids) :
							if (!isset($termSet[$tax])) :
								$termSet[$tax] = array();
							endif;
							$termSet[$tax] = array_merge($termSet[$tax], $term_ids);
						endforeach;
					endif;
				endif;
			endforeach;

			if (is_null($valid)) : // Plonked
				$this->post = NULL;
			else : // We can proceed
				$this->post['tax_input'] = array();
				foreach ($termSet as $tax => $term_ids) :
					if (!isset($this->post['tax_input'][$tax])) :
						$this->post['tax_input'][$tax] = array();
					endif;
					$this->post['tax_input'][$tax] = array_merge(
						$this->post['tax_input'][$tax],
						$term_ids
					);
				endforeach;

				// Now let's add on the feed and global presets
				foreach ($this->preset_terms as $tax => $term_ids) :
					if (!isset($this->post['tax_input'][$tax])) :
						$this->post['tax_input'][$tax] = array();
					endif;
					
					$this->post['tax_input'][$tax] = array_merge (
						$this->post['tax_input'][$tax],
						$this->category_ids (
						/*terms=*/ $term_ids,
						/*unfamiliar=*/ 'create:'.$tax, // These are presets; for those added in a tagbox editor, the tag may not yet exist
						/*taxonomies=*/ array($tax),
						array(
						  'singleton' => true,
						))
					);
				endforeach;
			endif;
		endif;
		
		if (!$this->filtered() and $freshness > 0) :
			// Filter some individual fields
			
			// If there already is a post slug (from syndication or by manual
			// editing) don't cause WP to overwrite it by sending in a NULL
			// post_name. Props Chris Fritz 2012-11-28.
			$post_name = (is_null($this->_wp_post) ? NULL : $this->_wp_post->post_name);			

			// Allow filters to set post slug. Props niska.
			$post_name = apply_filters('syndicated_post_slug', $post_name, $this);
			if (!empty($post_name)) :
				$this->post['post_name'] = $post_name;
			endif;
			
			$this->post = apply_filters('syndicated_post', $this->post, $this);
			
			// Allow for feed-specific syndicated_post filters.
			$this->post = apply_filters(
				"syndicated_post_".$this->link->uri(),
				$this->post,
				$this
			);
		endif;
		
		// Hook in early to make sure these get inserted if at all possible
		add_action(
			/*hook=*/ 'transition_post_status',
			/*callback=*/ array($this, 'add_rss_meta'),
			/*priority=*/ -10000, /* very early */
			/*arguments=*/ 3
		);

		$retval = array(1 => 'updated', 2 => 'new');
		
		$ret = false;
		if (!$this->filtered() and isset($retval[$freshness])) :			
			$diag = array(
				1 => 'Updating existing post # '.$this->wp_id().', "'.$this->post['post_title'].'"',
				2 => 'Inserting new post "'.$this->post['post_title'].'"',
			);
			FeedWordPress::diagnostic('syndicated_posts', $diag[$freshness]);

			$this->insert_post(/*update=*/ ($freshness == 1));
			
			$hook = array(	1 => 'update_syndicated_item', 2 => 'post_syndicated_item' );
			do_action($hook[$freshness], $this->wp_id(), $this);

			$ret = $retval[$freshness];
		endif;

		// If this is a legit, non-filtered post, tag it as found on the feed
		// regardless of fresh or stale status
		if (!$this->filtered()) :
			$key = '_feedwordpress_retire_me_' . $this->link->id;
			delete_post_meta($this->wp_id(), $key);
			
			$status = get_post_field('post_status', $this->wp_id());
			if ('fwpretired'==$status and $this->link->is_incremental()) :
				FeedWordPress::diagnostic('syndicated_posts', "Un-retiring previously retired post # ".$this->wp_id()." due to re-appearance on non-incremental feed."); 
				set_post_field('post_status', $this->post['post_status'], $this->wp_id());
				wp_transition_post_status($this->post['post_status'], $status, $old_status, $this->post);
			endif;
		endif;
		
		// Remove add_rss_meta hook
		remove_action(
			/*hook=*/ 'transition_post_status',
			/*callback=*/ array($this, 'add_rss_meta'),
			/*priority=*/ -10000, /* very early */
			/*arguments=*/ 3
		);

		return $ret;
	} /* function SyndicatedPost::store () */
	
	function insert_post ($update = false) {
		global $wpdb;

		$dbpost = $this->normalize_post(/*new=*/ true);
		if (!is_null($dbpost)) :
			$dbpost['post_pingback'] = false; // Tell WP 2.1 and 2.2 not to process for pingbacks
	
			// This is a ridiculous fucking kludge necessitated by WordPress 2.6 munging authorship meta-data
			add_action('_wp_put_post_revision', array($this, 'fix_revision_meta'));
				
			// Kludge to prevent kses filters from stripping the
			// content of posts when updating without a logged in
			// user who has `unfiltered_html` capability.
			$mungers = array('wp_filter_kses', 'wp_filter_post_kses');
			$removed = array();
			foreach ($mungers as $munger) :
				if (has_filter('content_save_pre', $munger)) :
					remove_filter('content_save_pre', $munger);
					$removed[] = $munger;
				endif;
			endforeach;
			
			if ($update and function_exists('get_post_field')) :
				// Don't munge status fields that the user may
				// have reset manually
				$doNotMunge = array('post_status', 'comment_status', 'ping_status');
				
				foreach ($doNotMunge as $field) :
					$dbpost[$field] = get_post_field($field, $this->wp_id());
				endforeach;
			endif;
			
			// WP3's wp_insert_post scans current_user_can() for the
			// tax_input, with no apparent way to override. Ugh.
			add_action(
			/*hook=*/ 'transition_post_status',
			/*callback=*/ array($this, 'add_terms'),
			/*priority=*/ -10001, /* very early */
			/*arguments=*/ 3
			);
			
			// WP3 appears to override whatever you give it for
			// post_modified. Ugh.
			add_action(
			/*hook=*/ 'transition_post_status',
			/*callback=*/ array($this, 'fix_post_modified_ts'),
			/*priority=*/ -10000, /* very early */
			/*arguments=*/ 3
			);

			if ($update) :
				$this->post['ID'] = $this->wp_id();
				$dbpost['ID'] = $this->post['ID'];
			endif;
			
			$this->_wp_id = wp_insert_post($dbpost, /*return wp_error=*/ true);

			remove_action(
			/*hook=*/ 'transition_post_status',
			/*callback=*/ array($this, 'add_terms'),
			/*priority=*/ -10001, /* very early */
			/*arguments=*/ 3
			);

			remove_action(
			/*hook=*/ 'transition_post_status',
			/*callback=*/ array($this, 'fix_post_modified_ts'),
			/*priority=*/ -10000, /* very early */
			/*arguments=*/ 3
			);

			// Turn off ridiculous fucking kludges #1 and #2
			remove_action('_wp_put_post_revision', array($this, 'fix_revision_meta'));
			foreach ($removed as $filter) :
				add_filter('content_save_pre', $filter);
			endforeach;
			
			$this->validate_post_id($dbpost, $update, array(__CLASS__, __FUNCTION__));
		endif;
	} /* function SyndicatedPost::insert_post () */
	
	function insert_new () {
		$this->insert_post(/*update=*/ false);
	} /* SyndicatedPost::insert_new() */

	function update_existing () {
		$this->insert_post(/*update=*/ true);
	} /* SyndicatedPost::update_existing() */

	/**
	 * SyndicatedPost::normalize_post()
	 *
	 * @param bool $new If true, this post is to be inserted anew. If false, it is an update of an existing post.
	 * @return array A normalized representation of the post ready to be inserted into the database or sent to the WordPress API functions
	 */
	function normalize_post ($new = true) {
		global $wpdb;

		$out = $this->post;

		$fullPost = $out['post_title'].$out['post_content'];
		$fullPost .= (isset($out['post_excerpt']) ? $out['post_excerpt'] : '');
		if (strlen($fullPost) < 1) :
			// FIXME: Option for filtering out empty posts
		endif;
		if (strlen($out['post_title'])==0) :
			$offset = (int) get_option('gmt_offset') * 60 * 60;
			if (isset($this->post['meta']['syndication_source'])) :
				$source_title = $this->post['meta']['syndication_source'];
			else :
				$feed_url = parse_url($this->post['meta']['syndication_feed']);
				$source_title = $feed_url['host'];
			endif;
			
			$out['post_title'] = $source_title
				.' '.gmdate('Y-m-d H:i:s', $this->published() + $offset);
			// FIXME: Option for what to fill a blank title with...
		endif;

		// Normalize the guid if necessary.
		$out['guid'] = SyndicatedPost::normalize_guid($out['guid']);

		// Why the fuck doesn't wp_insert_post already do this?
		foreach ($out as $key => $value) :
			if (is_string($value)) :
				$out[$key] = $wpdb->escape($value);
			else :
				$out[$key] = $value;
			endif;
		endforeach;

		return $out;
	}

	/**
	 * SyndicatedPost::validate_post_id()
	 *
	 * @param array $dbpost An array representing the post we attempted to insert or update
	 * @param mixed $ns A string or array representing the namespace (class, method) whence this method was called.
	 */
	function validate_post_id ($dbpost, $is_update, $ns) {
		if (is_array($ns)) : $ns = implode('::', $ns);
		else : $ns = (string) $ns; endif;

		// This should never happen.
		if (!is_numeric($this->_wp_id) or ($this->_wp_id == 0)) :
			$verb = ($is_update ? 'update existing' : 'insert new');
			$guid = $this->guid();
			$url = $this->permalink();
			$feed = $this->link->uri(array('add_params' => true));

			// wp_insert_post failed. Diagnostics, or barf up a critical bug
			// notice if we are in debug mode.
			$mesg = "Failed to $verb item [$guid]. WordPress API returned no valid post ID.\n"
				."\t\tID = ".serialize($this->_wp_id)."\n"
				."\t\tURL = ".FeedWordPress::val($url)
				."\t\tFeed = ".FeedWordPress::val($feed); 
			FeedWordPress::diagnostic('updated_feeds:errors', "WordPress API error: $mesg");
			FeedWordPress::diagnostic('feed_items:rejected', $mesg);

			$mesg = <<<EOM
The WordPress API returned an invalid post ID
			   when FeedWordPress tried to $verb item $guid
			   [URL: $url]
			   from the feed at $feed 

$ns::_wp_id 
EOM;
			FeedWordPress::noncritical_bug(
				/*message=*/ $mesg,
				/*var =*/ array(
					"\$this->_wp_id" => $this->_wp_id,
					"\$dbpost" => $dbpost,
				),
				/*line # =*/ __LINE__, /*filename=*/ __FILE__
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
	 * SyndicatedPost::add_terms() -- if FeedWordPress is processing an
	 * automatic update, that generally means that wp_insert_post() is being
	 * called under the user credentials of whoever is viewing the blog at
	 * the time -- which usually means no user at all. But wp_insert_post()
	 * checks current_user_can() before assigning any of the terms in a
	 * post's tax_input structure -- which is unfortunate, since
	 * current_user_can() always returns FALSE when there is no current user
	 * logged in. Meaning that automatic updates get no terms assigned.
	 *
	 * So, wp_insert_post() is not going to do the term assignments for us.
	 * If you want something done right....
	 *
	 * @param string $new_status Unused action parameter.
	 * @param string $old_status Unused action parameter.
	 * @param object $post The database record for the post just inserted.
	 */
	function add_terms ($new_status, $old_status, $post) {
		if ($new_status!='inherit') : // Bail if we are creating a revision.
			if ( is_array($this->post) and isset($this->post['tax_input']) and is_array($this->post['tax_input']) ) :
				foreach ($this->post['tax_input'] as $taxonomy => $terms) :
					if (is_array($terms)) :
						$terms = array_filter($terms); // strip out empties
					endif;
					wp_set_post_terms($post->ID, $terms, $taxonomy);
				endforeach;
			endif;
		endif;
	} /* SyndicatedPost::add_terms () */
	
	/**
	 * SyndicatedPost::fix_post_modified_ts() -- We would like to set
	 * post_modified and post_modified_gmt to reflect the value of
	 * <atom:updated> or equivalent elements on the feed. Unfortunately,
	 * wp_insert_post() refuses to acknowledge explicitly-set post_modified
	 * fields and overwrites them, either with the post_date (if new) or the
	 * current timestamp (if updated).
	 *
	 * So, wp_insert_post() is not going to do the last-modified assignments
	 * for us. If you want something done right....
	 *
	 * @param string $new_status Unused action parameter.
	 * @param string $old_status Unused action parameter.
	 * @param object $post The database record for the post just inserted.
	 */
	function fix_post_modified_ts ($new_status, $old_status, $post) {
		global $wpdb;
		if ($new_status!='inherit') : // Bail if we are creating a revision.
			$wpdb->update( $wpdb->posts, /*data=*/ array(
			'post_modified' => $this->post['post_modified'],
			'post_modified_gmt' => $this->post['post_modified_gmt'],
			), /*where=*/ array('ID' => $post->ID) );
		endif;
	} /* SyndicatedPost::fix_post_modified_ts () */
	
	/**
	 * SyndicatedPost::add_rss_meta: adds interesting meta-data to each entry
	 * using the space for custom keys. The set of keys and values to add is
	 * specified by the keys and values of $post['meta']. This is used to
	 * store anything that the WordPress user might want to access from a
	 * template concerning the post's original source that isn't provided
	 * for by standard WP meta-data (i.e., any interesting data about the
	 * syndicated post other than author, title, timestamp, categories, and
	 * guid). It's also used to hook into WordPress's support for
	 * enclosures.
	 *
	 * @param string $new_status Unused action parameter.
	 * @param string $old_status Unused action parameter.
	 * @param object $post The database record for the post just inserted.
	 */
	function add_rss_meta ($new_status, $old_status, $post) {
		global $wpdb;
		if ($new_status!='inherit') : // Bail if we are creating a revision.
			FeedWordPress::diagnostic('syndicated_posts:meta_data', 'Adding post meta-data: {'.implode(", ", array_keys($this->post['meta'])).'}');

			if ( is_array($this->post) and isset($this->post['meta']) and is_array($this->post['meta']) ) :
				$postId = $post->ID;
				
				// Aggregated posts should NOT send out pingbacks.
				// WordPress 2.1-2.2 claim you can tell them not to
				// using $post_pingback, but they don't listen, so we
				// make sure here.
				$result = $wpdb->query("
				DELETE FROM $wpdb->postmeta
				WHERE post_id='$postId' AND meta_key='_pingme'
				");
	
				foreach ( $this->post['meta'] as $key => $values ) :
					$eKey = $wpdb->escape($key);
	
					// If this is an update, clear out the old
					// values to avoid duplication.
					$result = $wpdb->query("
					DELETE FROM $wpdb->postmeta
					WHERE post_id='$postId' AND meta_key='$eKey'
					");
					
					// Allow for either a single value or an array
					if (!is_array($values)) $values = array($values);
					foreach ( $values as $value ) :
					FeedWordPress::diagnostic('syndicated_posts:meta_data', "Adding post meta-datum to post [$postId]: [$key] = ".FeedWordPress::val($value, /*no newlines=*/ true));
						add_post_meta($postId, $key, $value, /*unique=*/ false);
					endforeach;
				endforeach;
			endif;
		endif;
	} /* SyndicatedPost::add_rss_meta () */

	/**
	 * SyndicatedPost::author_id (): get the ID for an author name from
	 * the feed. Create the author if necessary.
	 *
	 * @param string $unfamiliar_author
	 *
	 * @return NULL|int The numeric ID of the author to attribute the post to
	 *	NULL if the post should be filtered out.
	 */
	function author_id ($unfamiliar_author = 'create') {
		global $wpdb;

		$a = $this->named['author'];

		$source = $this->source();
		$forbidden = apply_filters('feedwordpress_forbidden_author_names',
			array('admin', 'administrator', 'www', 'root'));
		
		// Prepare the list of candidates to try for author name: name from
		// feed, original source title (if any), immediate source title live
		// from feed, subscription title, prettied version of feed homepage URL,
		// prettied version of feed URL, or, failing all, use "unknown author"
		// as last resort

		$candidates = array();
		$candidates[] = $a['name'];
		if (!is_null($source)) : $candidates[] = $source['title']; endif;
		$candidates[] = $this->link->name(/*fromFeed=*/ true);
		$candidates[] = $this->link->name(/*fromFeed=*/ false);
		if (strlen($this->link->homepage()) > 0) : $candidates[] = feedwordpress_display_url($this->link->homepage()); endif;
		$candidates[] = feedwordpress_display_url($this->link->uri());
		$candidates[] = 'unknown author';
		
		// Pick the first one that works from the list, screening against empty
		// or forbidden names.
		
		$author = NULL;
		while (is_null($author) and ($candidate = each($candidates))) :
			if (!is_null($candidate['value'])
			and (strlen(trim($candidate['value'])) > 0)
			and !in_array(strtolower(trim($candidate['value'])), $forbidden)) :
				$author = $candidate['value'];
			endif;
		endwhile;
		
		$email = (isset($a['email']) ? $a['email'] : NULL);
		$authorUrl = (isset($a['uri']) ? $a['uri'] : NULL);

		
		$hostUrl = $this->link->homepage();
		if (is_null($hostUrl) or (strlen($hostUrl) < 0)) :
			$hostUrl = $this->link->uri();
		endif;

		$match_author_by_email = !('yes' == get_option("feedwordpress_do_not_match_author_by_email"));
		if ($match_author_by_email and !FeedWordPress::is_null_email($email)) :
			$test_email = $email;
		else :
			$test_email = NULL;
		endif;

		// Never can be too careful...
		$login = sanitize_user($author, /*strict=*/ true);

		// Possible for, e.g., foreign script author names
		if (strlen($login) < 1) :
			// No usable characters in author name for a login.
			// (Sometimes results from, e.g., foreign scripts.)
			//
			// We just need *something* in Western alphanumerics,
			// so let's try the domain name.
			//
			// Uniqueness will be guaranteed below if necessary.

			$url = parse_url($hostUrl);
			
			$login = sanitize_user($url['host'], /*strict=*/ true);
			if (strlen($login) < 1) :
				// This isn't working. Frak it.
				$login = 'syndicated';
			endif;
		endif;

		$login = apply_filters('pre_user_login', $login);

		$nice_author = sanitize_title($author);
		$nice_author = apply_filters('pre_user_nicename', $nice_author);

		$reg_author = $wpdb->escape(preg_quote($author));
		$author = $wpdb->escape($author);
		$email = $wpdb->escape($email);
		$test_email = $wpdb->escape($test_email);
		$authorUrl = $wpdb->escape($authorUrl);

		// Check for an existing author rule....
		if (isset($this->link->settings['map authors']['name']['*'])) :
			$author_rule = $this->link->settings['map authors']['name']['*'];
		elseif (isset($this->link->settings['map authors']['name'][strtolower(trim($author))])) :
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

			// First try the user core data table.
			$id = $wpdb->get_var(
			"SELECT ID FROM $wpdb->users
			WHERE TRIM(LCASE(display_name)) = TRIM(LCASE('$author'))
			OR TRIM(LCASE(user_login)) = TRIM(LCASE('$author'))
			OR (
				LENGTH(TRIM(LCASE(user_email))) > 0
				AND TRIM(LCASE(user_email)) = TRIM(LCASE('$test_email'))
			)");
	
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

			// ... if you don't find one, then do what you need to do
			if (is_null($id)) :
				if ($unfamiliar_author === 'create') :
					$userdata = array();

					// WordPress 3 is going to pitch a fit if we attempt to register
					// more than one user account with an empty e-mail address, so we
					// need *something* here. Ugh.
					if (strlen($email) == 0 or FeedWordPress::is_null_email($email)) :
						$url = parse_url($hostUrl);
						$email = $nice_author.'@'.$url['host'];
					endif;

					#-- user table data
					$userdata['ID'] = NULL; // new user
					$userdata['user_login'] = $login;
					$userdata['user_nicename'] = $nice_author;
					$userdata['user_pass'] = substr(md5(uniqid(microtime())), 0, 6); // just something random to lock it up
					$userdata['user_email'] = $email;
					$userdata['user_url'] = $authorUrl;
					$userdata['nickname'] = $author;
					$parts = preg_split('/\s+/', trim($author), 2);
					if (isset($parts[0])) : $userdata['first_name'] = $parts[0]; endif;
					if (isset($parts[1])) : $userdata['last_name'] = $parts[1]; endif;
					$userdata['display_name'] = $author;
					$userdata['role'] = 'contributor';
					
					do { // Keep trying until you get it right. Or until PHP crashes, I guess.
						$id = wp_insert_user($userdata);
						if (is_wp_error($id)) :
							$codes = $id->get_error_code();
							switch ($codes) :
							case 'empty_user_login' :
							case 'existing_user_login' :
								// Add a random disambiguator
								$userdata['user_login'] .= substr(md5(uniqid(microtime())), 0, 6);
								break;
							case 'existing_user_email' :
								// No disassemble!
								$parts = explode('@', $userdata['user_email'], 2);
								
								// Add a random disambiguator as a gmail-style username extension
								$parts[0] .= '+'.substr(md5(uniqid(microtime())), 0, 6);
								
								// Reassemble
								$userdata['user_email'] = $parts[0].'@'.$parts[1];
								break;
							endswitch;
						endif;
					} while (is_wp_error($id));
				elseif (is_numeric($unfamiliar_author) and get_userdata((int) $unfamiliar_author)) :
					$id = (int) $unfamiliar_author;
				elseif ($unfamiliar_author === 'default') :
					$id = 1;
				endif;
			endif;
		endif;

		if ($id) :
			$this->link->settings['map authors']['name'][strtolower(trim($author))] = $id;
			
			// Multisite: Check whether the author has been recorded
			// on *this* blog before. If not, put her down as a
			// Contributor for *this* blog.
			$user = new WP_User((int) $id);
			if (empty($user->roles)) :
				$user->add_role('contributor');
			endif;
		endif;
		return $id;	
	} /* function SyndicatedPost::author_id () */

	/**
	 * category_ids: look up (and create) category ids from a list of categories
	 *
	 * @param array $cats
	 * @param string $unfamiliar_category
	 * @param array|null $taxonomies
	 * @return array
	 */ 
	function category_ids ($cats, $unfamiliar_category = 'create', $taxonomies = NULL, $params = array()) {
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
			if (preg_match('/^{([^#}]*)#([0-9]+)}$/', $cat_name, $backref)) :
				$cat_id = (int) $backref[2];
				$tax = $backref[1];
				if (strlen($tax) < 1) :
					$tax = $catTax;
				endif;

				$term = term_exists($cat_id, $tax);
				if (!is_wp_error($term) and !!$term) :
					if (!isset($terms[$tax])) :
						$terms[$tax] = array();
					endif;
					$terms[$tax][] = $cat_id;
				endif;
			elseif (strlen($cat_name) > 0) :
				$familiar = false;
				foreach ($taxonomies as $tax) :
					if ($tax!='category' or strtolower($cat_name)!='uncategorized') :
						$term = term_exists($cat_name, $tax);
						if (!is_wp_error($term) and !!$term) :
							$familiar = true;
	
							if (is_array($term)) :
								$term_id = (int) $term['term_id'];
							else :
								$term_id = (int) $term;
							endif;
							
							if (!isset($terms[$tax])) :
								$terms[$tax] = array();
							endif;
							$terms[$tax][] = $term_id;
							break; // We're done here.
						endif;
					endif;
				endforeach;
				
				if (!$familiar) :
					if ('tag'==$unfamiliar_category) :
						$unfamiliar_category = 'create:post_tag';
					endif;
					
					if (preg_match('/^create(:(.*))?$/i', $unfamiliar_category, $ref)) :
						$tax = $catTax; // Default
						if (isset($ref[2]) and strlen($ref[2]) > 2) :
							$tax = $ref[2];
						endif;
						$term = wp_insert_term($cat_name, $tax);
						if (is_wp_error($term)) :
							FeedWordPress::noncritical_bug('term insertion problem', array('cat_name' => $cat_name, 'term' => $term, 'this' => $this), __LINE__, __FILE__);
						else :
							if (!isset($terms[$tax])) :
								$terms[$tax] = array();
							endif;
							$terms[$tax][] = (int) $term['term_id'];
						endif;
					endif;
				endif;
			endif;
		endforeach;

		$filtersOn = $allowFilters;
		if ($allowFilters) :
			$filters = array_filter(
				$this->link->setting('match/filter', 'match_filter', array()),
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
		return $terms;
	} // function SyndicatedPost::category_ids ()

} /* class SyndicatedPost */

