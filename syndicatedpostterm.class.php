<?php
class SyndicatedPostTerm {
	private $term, $tax, $exists, $exists_in, $post;
	
	public function __construct ($term, $taxonomies, $post) {
		$catTax = 'category';
		
		$this->post = $post;

		// Default to these
		$this->term = NULL;
		$this->tax = NULL;
		$this->exists = NULL;
		$this->exists_in = NULL;
		
		if (preg_match('/^{([^#}]*)#([0-9]+)}$/', $term, $backref)) :
			$cat_id = (int) $backref[2];
			$tax = $backref[1];
			if (strlen($tax) < 1) :
				$tax = $catTax;
			endif;

			$aTerm = get_term($cat_id, $tax);
			if (!is_wp_error($aTerm) and !!$aTerm) :
				$this->exists = (array) $aTerm;
				$this->exists_in = $this->exists['taxonomy'];
				
				$this->term = $this->exists['name'];
				$this->tax = array($this->exists['taxonomy']);
			endif;
			
		else :
			$this->term = $term;
			$this->tax = $taxonomies;

			// Leave exists/exists_in empty until we search()
			
		endif;
		
		if (is_null($this->tax)) :
			$this->tax = array($catTax);
		endif;
	} /* SyndicatedPostTerm constructor */
	
	public function is_familiar () {
		$ex = $this->exists;
		if (is_null($this->exists)) :
			$ex = $this->search();
		endif;

		FeedWordPress::diagnostic(
			'syndicated_posts:categories',
			'Assigned category '.json_encode($this->term)
			  .' by feed; checking '.json_encode($this->tax)
			  . ' with result: '.json_encode($ex) 
		);
		return (!is_wp_error($ex) and !!$ex);
	} /* SyndicatedPostTerm::familiar () */
	
	protected function search () {
		
		// Initialize
		$found = null;
		
		// Either this is a numbered term code, which supplies the ID
		// and the taxonomy explicitly (e.g.: {category#2}; in which
		// case we have set $this->tax to a unit array containing only
		// the correct taxonomy, or else we have a term name and an
		// ordered array of taxonomies to search for that term name. In
		// either case, loop through and check each pair of term

		foreach ($this->tax as $tax) :
			
			if (!$this->is_forbidden_in($tax)) :
				
				$found = $this->fetch_record_in($tax); 
				if ($found) :
					// When TRUE, the term has been found
					// and is now stored in $this->exists
					
					// Save the taxonomy we found this in.
					$this->exists_in = $tax;
					
					break;
				endif;
				
			endif;
			
		endforeach;

		FeedWordPress::diagnostic(
			'syndicated_posts:categories:test',
			'CHECKED familiarity of term '
			  .json_encode($this->term)
			  .' across '.json_encode($this->tax)
			  . ' with result: '.json_encode($found) 
		);
		
		return $this->exists;

	} /* SyndicatedPostTerm::search () */
	
	protected function fetch_record_in ($tax) {
		$record = term_exists($this->term, $tax);
		
		FeedWordPress::diagnostic(
			'syndicated_posts:categories:test',
			'CHECK existence of '.$tax.': '
			  .json_encode($this->term)
			  .' with result: '.json_encode($record) 
		);
		
		$found = (!is_wp_error($record) and !!$record);
		
		if ($found) :
			$this->exists = $record;
		endif;
		
		return $found;
	} /* SyndicatedPostTerm::fetch_record_in() */

	public function is_forbidden_in ($tax = NULL) {
		$forbid = false; // Innocent until proven guilty.
		
		$term = $this->term;
		if (is_null($tax) and (count($this->tax) > 0)) :
			$tax = $this->tax[0];
		endif;
		
		if ($tax=='category' and strtolower($term)=='uncategorized') :
			$forbid = true;
		endif;
		
		// Run it through a filter.
		return apply_filters('syndicated_post_forbidden_term', $forbid, $term, $tax, $this->post);
	} /* SyndicatedPostTerm::is_forbidden_in () */

	public function taxonomy () {
		if (is_null($this->exists_in)) :
			$this->search();
		endif;
		
		return $this->exists_in;
	} /* SyndicatedPostTerm::taxonomy () */
	
	public function id () {
		$term_id = NULL;
		
		if (is_null($this->exists)) :
			$this->search();
		endif;
		
		$term = $this->exists;
		if (is_array($term) or is_object($term)) :
			
			// For hash tables of any sort, use the term_id member
			$term = (array) $term;
			$term_id = intval($term['term_id']);
			
		elseif (is_numeric($term)) :
		
			// For a straight numeric response, just return number
			$term_id = intval($term);
			
		endif;
		
		return $term_id;
	} /* SyndicatedPostTerm::id () */

	public function insert ($tax = NULL) {
		$ret = NULL;
		
		if (is_null($tax)) :
			if (count($this->tax) > 0) :
				$tax = $this->tax[0];
			endif;
		endif;
		
		if (!$this->is_forbidden_in($tax)) :
			$aTerm = wp_insert_term($this->term, $tax);
			if (is_wp_error($aTerm)) :
			
				// If debug mode is ON, this will halt us here.
				FeedWordPressDiagnostic::noncritical_bug(
					'term insertion problem', array(
						'term' => $this->term,
						'result' => $aTerm,
						'post' => $this->post, 
						'this' => $this
					), __LINE__, __FILE__
				);
			
				// Otherwise, we'll continue & return NULL...
				
			else :
				$this->exists = $aTerm;
				$this->exists_in = $tax;
				
				$ret = $this->id();
			endif;
			
			FeedWordPress::diagnostic(
				'syndicated_posts:categories',
				'CREATED unfamiliar '.$tax.': '.json_encode($this->term).' with result: '.json_encode($aTerm) 
			);
		else :
			FeedWordPress::diagnostic(
				'syndicated_posts:categories',
				'Category: DID NOT CREATE unfamiliar '.$tax.': '.json_encode($this->term).':'
				.' that '.$tax.' name is filtered out.'
			);
		endif;
		
		return $ret;
	} /* SyndicatedPostTerm::insert () */

} /* class SyndicatedPostTerm */

