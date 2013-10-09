<?php

class SyndicationDataQueries {
	function SyndicationDataQueries () {
		add_action('init', array($this, 'init'));
		add_action('parse_query', array($this, 'parse_query'), 10, 1);
		add_filter('posts_search', array($this, 'posts_search'), 10, 2);
		add_filter('posts_where', array($this, 'posts_where'), 10, 2);
		add_filter('posts_fields', array($this, 'posts_fields'), 10, 2);
		add_filter('posts_request', array($this, 'posts_request'), 10, 2);
	}

	function init () {
		global $wp;
		$wp->add_query_var('guid');
	}

	function parse_query (&$q) {
		if ($q->get('guid')) :
			$q->is_single = false;	// Causes nasty side-effects.
			$q->is_singular = true;	// Doesn't?
		endif;

		$ff = $q->get('fields');
		if ($ff == '_synfresh' or $ff == '_synfrom') :
			$q->query_vars['cache_results'] = false; // Not suitable.
		endif;
	} /* SyndicationDataQueries::parse_query () */

	function pre_get_posts (&$q) {
		//
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

				// MD5 hashes
				if (preg_match('/^[0-9a-f]{32}$/i', $guid)) :
					$seek[] = SyndicatedPost::normalize_guid_prefix().$guid;
				endif;

				// Invalid URIs, URIs that WordPress just doesn't like, and URIs
				// that WordPress decides to munge.
				$nGuid = SyndicatedPost::normalize_guid($guid);
				if ($guid != $nGuid) :
					$seek[] = $nGuid;
				endif;

				// Escape to prevent frak-ups, injections, etc.
				$seek = array_map('esc_sql', $seek);

				// Assemble
				$guidMatch = "(guid = '".implode("') OR (guid = '", $seek)."')";
				$search .= " AND ($guidMatch)";
			endif;
		endif;

		if ($query->get('fields')=='_synfresh') :
			// Ugly hack to ensure we ONLY check by guid in syndicated freshness
			// checks -- for reasons of both performance and correctness. Pitch:
			$search .= " -- '";
		elseif ($query->get('fields')=='_synfrom') :
			$search .= " AND ({$wpdb->postmeta}.meta_key = '".$query->get('meta_key')."' AND {$wpdb->postmeta}.meta_value = '".$query->get('meta_value')."') -- '";
		endif;
		return $search;
	} /* SyndicationDataQueries::posts_search () */

	function posts_where ($where, &$q) {
		global $wpdb;

		// Ugly hack to ensure we ONLY check by guid in syndicated freshness
		// checks -- for reasons of both performance and correctness. Catch:
		if (strpos($where, " -- '") !== false) :
			$bits = explode(" -- '", $where, 2);
			$where = $bits[0];
		endif;

		if ($psn = $q->get('post_status__not')) :
			$where .= " AND ({$wpdb->posts}.post_status <> '".esc_sql($psn)."')";
		endif;

		return $where;
	} /* SyndicationDataQueries::post_where () */

	function posts_fields ($fields, &$query) {
		global $wpdb;
		if ($f = $query->get('fields')) :
			switch ($f) :
			case '_synfresh' :
				$fields = "{$wpdb->posts}.ID, {$wpdb->posts}.guid, {$wpdb->posts}.post_modified_gmt, {$wpdb->posts}.post_name";
				break;
			case '_synfrom' :
				$fields = "{$wpdb->posts}.ID, {$wpdb->posts}.guid, {$wpdb->posts}.post_title, {$wpdb->postmeta}.meta_value";
				break;
			default :
				// Do nothing.
			endswitch;
		endif;
		return $fields;
	} /* SyndicationDataQueries::posts_fields () */
}

$SDQ = new SyndicationDataQueries;

