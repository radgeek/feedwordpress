<?php
class SyndicationDataQueries {
	function SyndicationDataQueries () {
		add_action('init', array(&$this, 'init'));
		add_filter('pre_get_posts', array(&$this, 'pre_get_posts'), 10, 1);
		add_filter('posts_search', array(&$this, 'posts_search'), 10, 2);
		add_filter('posts_fields', array(&$this, 'posts_fields'), 10, 2);
		add_filter('posts_request', array(&$this, 'posts_request'), 10, 2);
	}

	function init () {
		global $wp;
		$wp->add_query_var('guid');
	}

	function pre_get_posts (&$q) {
		if ($q->get('guid')) :
			$q->query_vars['post_type'] = get_post_types();
			$q->query_vars['post_status'] = implode(",", get_post_stati());
		endif;
		
		if ($q->get('fields') == '_synfresh') :
			$q->query_vars['cache_results'] = false; // Not suitable for caching.
		endif;
	}
	
	function posts_request ($sql, &$query) {
		if ($query->get('fields') == '_synfresh') :
			FeedWordPress::diagnostic('feed_items:freshness:sql', "SQL: ".$sql);
		endif;
		return $sql;
	}
	
	function posts_search ($search, &$query) {
		global $wpdb;
		if ($guid = $query->get('guid')) :
			if (strlen(trim($guid)) > 0) :
				$seek = array($guid);
				$md5Seek = array();
				
				// MD5 hashes
				if (preg_match('/^[0-9a-f]{32}$/i', $guid)) :
					$md5Seek = array($guid);
					$seek[] = SyndicatedPost::normalize_guid_prefix().$guid;
				endif;

				// URLs that are invalid, or that WordPress just doesn't like
				$nGuid = SyndicatedPost::normalize_guid($guid);
				if ($guid != $nGuid) :
					$seek[] = $nGuid;
				endif;
				
				// Assemble
				$guidMatch = "(guid = '".implode("') OR (guid = '", $seek)."')";
				if (count($md5Seek) > 0) :
					$guidMatch .= " OR (MD5(guid) = '".implode("') OR (MD5(guid) = '", $md5Seek)."')";
				endif;
				
				$search .= $wpdb->prepare(" AND ($guidMatch)");
			endif;
		endif;
		return $search;
	} /* SyndicationDataQueries::posts_where () */
	
	function posts_fields ($fields, &$query) {
		global $wpdb;
		if ($f = $query->get('fields')) :
			switch ($f) :
			case '_synfresh' :
				$fields = "{$wpdb->posts}.ID, {$wpdb->posts}.guid, {$wpdb->posts}.post_modified_gmt";
				break;
			default :
				// Do nothing.
			endswitch;
		endif;
		return $fields;
	} /* SyndicationDataQueries::posts_fields () */
}

$SDQ = new SyndicationDataQueries;

