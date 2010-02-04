<?php
/**
 * class MagpieFromSimplePie: compatibility layer to prevent existing filters
 * from breaking.
 *
 * @since 2010.0203
 *
 */

class MagpieFromSimplePie {
	var $pie;
	var $original;
	var $item;

	var $_XMLNS_FAMILIAR = array (
    	'http://www.w3.org/2005/Atom' => 'atom' /* 1.0 */,
	'http://purl.org/atom/ns#' => 'atom' /* pre-1.0 */,
	'http://purl.org/rss/1.0/' => 'rss' /* 1.0 */,
	'http://backend.userland.com/RSS2' => 'rss' /* 2.0 */,
	'http://www.w3.org/1999/02/22-rdf-syntax-ns#' => 'rdf', 
	'http://www.w3.org/1999/xhtml' => 'xhtml',
	'http://purl.org/dc/elements/1.1/' => 'dc',
	'http://purl.org/dc/terms/' => 'dcterms',
	'http://purl.org/rss/1.0/modules/content/' => 'content',
	'http://purl.org/rss/1.0/modules/syndication/' => 'sy',
	'http://purl.org/rss/1.0/modules/taxonomy/' => 'taxo',
	'http://purl.org/rss/1.0/modules/dc/' => 'dc',
	'http://wellformedweb.org/CommentAPI/' => 'wfw',
	'http://webns.net/mvcb/' => 'admin',
	'http://purl.org/rss/1.0/modules/annotate/' => 'annotate',
	'http://xmlns.com/foaf/0.1/' => 'foaf',
	'http://madskills.com/public/xml/rss/module/trackback/' => 'trackback',
	'http://web.resource.org/cc/' => 'cc',
	'http://search.yahoo.com/mrss' => 'media',
	'http://search.yahoo.com/mrss/' => 'media',
	'http://video.search.yahoo.com/mrss' => 'media',
	'http://video.search.yahoo.com/mrss/' => 'media',
	'http://purl.org/syndication/thread/1.0' => 'thr',
	'http://purl.org/syndication/thread/1.0/' => 'thr',
	'http://www.w3.org/XML/1998/namespace' => 'xml',
	);

	/**
	 * MagpieFromSimplePie constructor
	 *
	 * @param SimplePie_Item $item The item to convert to Magpie array format.
	 * @param SimplePie $pie The feed from which the item came.
	 *
	 * @uses MagpieFromSimplePie::is_atom
	 * @uses MagpieFromSimplePie::processItemData
	 * @uses MagpieFromSimplePie::normalize 
	 */
	function MagpieFromSimplePie ($item, $pie) {
		$this->original = $item;
		$this->pie = $pie;

		$this->item = $this->processItemData($this->original->data);
		$this->normalize();		
	} /* MagpieFromSimplePie constructor */
	
	/**
	 * MagpieFromSimplePie::get_item: returns a MagpieRSS format array
	 * which is equivalent to the SimplePie_Item object from which this
	 * object was constructed.
	 * 
	 * @return array A MagpieRSS format array representing this feed item.
	 */
	function get_item () {
		return $this->item;
	} /* MagpieFromSimplePie::get_item */
	
	/**
	 * MagpieFromSimplePie::processItemData
	 *
	 * @param array $data
	 * @param array $path
	 * @return array
	 *
	 * @uses MagpieFromSimplePie::handleAttributes
	 * @uses MagpieFromSimplePie::handleChildren
	 */
	function processItemData ($data, $path = array()) {
		$ret = array();
		$tagPath = strtolower(implode('_', $path));
	
		if (strlen($tagPath) > 0 and isset($data['data']) and strlen($data['data']) > 0) :
			$ret[$tagPath] = $data['data'];
		endif;
	
		// Set up xml:base to be recorded in array
		if (isset($data['xml_base_explicit']) and $data['xml_base_explicit']) :
			$data['attribs']['']['xml:base'] = $data['xml_base'];
		endif;
		
		$ret = $this->handleAttributes($data, $path) + $ret;
		$ret = $this->handleChildren($data, $path) + $ret;
		return $ret;
	} /* MagpieFromSimplePie::processItemData() */

	/**
	 * MagpieFromSimplePie::handleAttributes
	 *
	 * @param array $data
	 * @param array $path
	 * @return array
	 */
	function handleAttributes ($data, $path) {
		$tagPath = strtolower(implode('_', $path));
		$ret = array();
		if (isset($data['attribs'])) : foreach ($data['attribs'] as $ns => $pairs) :
			if (isset($this->_XMLNS_FAMILIAR[$ns])) :
				$ns = $this->_XMLNS_FAMILIAR[$ns];
			endif;

			foreach ($pairs as $attr => $value) :
				$attr = strtolower($attr);
				if ($ns=='rdf' and $attr=='about') :
					$ret['about'] = $value;
				else :
					if (strlen($ns) > 0 and $this->is_namespaced($ns, /*attrib=*/ true)) :
						$attr = $ns.':'.$attr;
					endif;
	
					$ret[$tagPath.'@'.$attr] = $value;
					if (isset($ret[$tagPath.'@']) and strlen($ret[$tagPath.'@'])>0) :
						$ret[$tagPath.'@'] .= ',';
					else :
						$ret[$tagPath.'@'] = '';
					endif;
					$ret[$tagPath.'@'] .= $attr;
				endif;
			endforeach;
		endforeach; endif;
		return $ret;
	} /* MagpieFromSimplePie::handleAttributes() */
	
	/**
	 * MagpieFromSimplePie::handleChildren
	 *
	 * @param array $data
	 * @param array $path
	 * @return array
	 *
	 * @uses MagpieFromSimplePie::get_attrib
	 * @uses MagpieFromSimplePie::is_atom
	 * @uses MagpieFromSimplePie::increment_element
	 * @uses MagpieFromSimplePie::processItemData
	 */
	function handleChildren ($data, $path = array()) {
		$tagPath = strtolower(implode('_', $path));
		$ret = array();
		if (isset($data['child'])) : foreach ($data['child'] as $ns => $elements) :
			if (isset($this->_XMLNS_FAMILIAR[$ns])) :
				$ns = $this->_XMLNS_FAMILIAR[$ns];
			endif;

			foreach ($elements as $tag => $multi) : foreach ($multi as $element) :
				$copyOver = NULL;

				// Determine tag name; check #; increment #
				$childTag = strtolower($tag);
				if ('link'==$tag and 'atom'==$ns) :
					$rel = $this->get_attrib(
						/*ns=*/ array('', 'http://www.w3.org/2005/Atom'),
						/*attr=*/ 'rel',
						$element
					);
					if ($rel != 'alternate') :
						$childTag .= '_'.$rel;
					endif;
					$copyOver = $this->get_attrib(
						/*ns=*/ array('', 'http://www.w3.org/2005/Atom'),
						/*attr=*/ 'href',
						$element
					);
				elseif ('content'==$tag and $this->is_atom()) :
					$childTag = 'atom_'.$tag;
				endif;
					
				$childTag = $this->increment_element($ret, $childTag, $ns, $path);
				$childPath = $path; $childPath[] = $childTag; 

				if (!is_null($copyOver)) :
					$co = array();
					$co[implode('_', $childPath)] = $copyOver;
				else :
					$co = NULL;
				endif;

				$arr = $this->processItemData($element, $childPath);
				if ($co) :
					$arr = $co + $arr; // Left-hand overwrites right-hand
				endif;
					
				if ($this->is_namespaced($ns)) :
					if (!isset($ret[$ns])) : $ret[$ns] = array(); endif;
					$ret[$ns] = $arr + $ret[$ns];
				else :
					$ret = $arr + $ret;
				endif;
			endforeach; endforeach;
		endforeach; endif;
		return $ret;
	} /* MagpieFromSimplePie::handleChildren() */

	/**
	 * MagpieFromSimplePie::get_attrib
	 *
	 * @param array $namespaces
	 * @param string $attr
	 * @param array $element
	 * @param mixed $default
	 */
	function get_attrib ($namespaces, $attr, $element, $default = NULL) {
		$ret = $default;
		if (isset($element['attribs'])) :
			foreach ($namespaces as $ns) :
				if (isset($element['attribs'][$ns])
				and isset($element['attribs'][$ns][$attr])) :
					$ret = $element['attribs'][$ns][$attr];
					break;
				endif;
			endforeach;
		endif;
		return $ret;
	} /* MagpieFromSimplePie::get_attrib */

	/**
	 * MagpieFromSimplePie::normalize
	 *
	 * @uses MagpieFromSimplePie::is_atom
	 * @uses MagpieFromSimplePie::is_rss
	 * @uses MagpieFromSimplePie::normalize_element
	 * @uses MagpieFromSimplePie::normalize_author_inheritance
	 * @uses MagpieFromSimplePie::normalize_atom_person
	 * @uses MagpieFromSimplePie::normalize_enclosure
	 * @uses MagpieFromSimplePie::normalize_category
	 * @uses MagpieFromSimplePie::normalize_dc_subject
	 * @uses parse_w3cdtf
	 */
	function normalize () {
		// if atom populate rss fields and normalize 0.3 and 1.0 feeds
		if ( $this->is_atom() ) :
			$item = $this->item;
	
			// Atom 1.0 elements <=> Atom 0.3 elements
			if ($this->feed_version() < 1.0) :
				$this->normalize_element($this->item, 'modified', $this->item, 'updated');
				$this->normalize_element($this->item, 'issued', $this->item, 'published');
			else :
				$this->normalize_element($this->item, 'updated', $this->item, 'modified');
				$this->normalize_element($this->item, 'published', $this->item, 'issued');
			endif;

			$this->normalize_author_inheritance();

			// Atom elements to RSS elements
			$this->normalize_element($this->item, 'author', $this->item['dc'], 'creator', 'normalize_atom_person');
			$this->normalize_element($this->item, 'contributor', $this->item['dc'], 'contributor', 'normalize_atom_person');
			$this->normalize_element($this->item, 'summary', $this->item, 'description');
			$this->normalize_element($this->item, 'atom_content', $this->item['content'], 'encoded');
			$this->normalize_element($this->item, 'link_enclosure', $this->item, 'enclosure', 'normalize_enclosure');
		
			// Categories
			if ( isset($this->item['category#']) ) : // Atom 1.0 categories to dc:subject and RSS 2.0 categories
				$this->normalize_element($this->item, 'category', $this->item['dc'], 'subject', 'normalize_category');
			elseif ( isset($this->item['dc']['subject#']) ) : // dc:subject to Atom 1.0 and RSS 2.0 categories
				$this->normalize_element($this->item['dc'], 'subject', $this->item, 'category', 'normalize_dc_subject');
			endif;
		
			// Normalized item timestamp
			$atom_date = (isset($this->item['published']) ) ? $this->item['published'] : $this->item['updated'];
			if ( $atom_date ) :
				$date_timestamp = @parse_w3cdtf($atom_date);
			endif;
		
		elseif ( $this->is_rss() ) :
			// RSS elements to Atom elements
			$this->normalize_element($this->channel, 'description', $this->channel, 'tagline'); // Atom 0.3
			$this->normalize_element($this->channel, 'description', $this->channel, 'subtitle'); // Atom 1.0 (yay wordsmithing!)
			$this->normalize_element($this->image, 'url', $this->channel, 'logo');
        
			// RSS elements to Atom elements
			$this->normalize_element($this->item, 'description', $this->item, 'summary');
			$this->normalize_element($this->item, 'enclosure', $this->item, 'link_enclosure', 'normalize_enclosure');
			
			// Categories
			if ( isset($this->item['category#']) ) : // RSS 2.0 categories to dc:subject and Atom 1.0 categories
				$this->normalize_element($this->item, 'category', $this->item['dc'], 'subject', 'normalize_category');
			elseif ( isset($this->item['dc']['subject#']) ) : // dc:subject to Atom 1.0 and RSS 2.0 categories
				$this->normalize_element($this->item['dc'], 'subject', $this->item, 'category', 'normalize_dc_subject');
			endif;

			// Normalized item timestamp
			if (isset($this->item['pubdate'])) :
				$date_timestamp = @strtotime($this->item['pubdate']);
			elseif ( isset($this->item['dc']['date']) ) :
				$date_timestamp = @parse_w3cdtf($this->item['dc']['date']);
			endif;
		endif;

		if (is_numeric($date_timestamp) and $date_timestamp > 0) :
			$this->item['date_timestamp'] = $date_timestamp;
		endif;
	} /* MagpieFromSimplePie::normalize() */

	/**
	 * MagpieFromSimplePie::normalize_author_inheritance
	 *
	 * @uses SimplePie_Item::get_authors
	 * @uses SimplePie_Author::get_name
	 * @uses SimplePie_Author::get_link
	 * @uses SimplePie_Author::get_email
	 * @uses MagpieFromSimplePie::increment_element
	 */
	function normalize_author_inheritance () {
		// "If an atom:entry element does not contain
		// atom:author elements, then the atom:author elements
		// of the contained atom:source element are considered
		// to apply. In an Atom Feed Document, the atom:author
		// elements of the containing atom:feed element are
		// considered to apply to the entry if there are no
		// atom:author elements in the locations described
		// above." <http://atompub.org/2005/08/17/draft-ietf-atompub-format-11.html#rfc.section.4.2.1>
		if (!isset($this->item["author#"])) :
			$authors = $this->original->get_authors();
			foreach ($authors as $author) :
				$tag = $this->increment_element($this->item, 'author', 'atom', array());
				$this->item[$tag] = $this->item["{$tag}_name"] = $author->get_name();
				if ($author->get_link()) : $this->item["{$tag}_uri"] = $this->item["{$tag}_url"] = $author->get_link(); endif;
				if ($author->get_email()) : $this->item["{$tag}_email"] = $author->get_email(); endif;
			endforeach;
		endif;
	} /* MagpieFromSimplePie::normalize_author_inheritance() */
	
	/**
	 * MagpieFromSimplePie::normalize_element 
	 * Simplify the logic for normalize(). Makes sure that count of elements
	 * and each of multiple elements is normalized properly. If you need to
	 * mess with things like attributes or change formats or the like, pass
	 * it a callback to handle each element.
	 *
	 * @param array &$source
	 * @param string $from
	 * @param array &$dest
	 * @param string $to
	 * @param mixed $via
	 */
	function normalize_element (&$source, $from, &$dest, $to, $via = NULL) {
		 if (isset($source[$from]) or isset($source["{$from}#"])) :
		 	if (isset($source["{$from}#"])) :
		 		$n = $source["{$from}#"];
		 		$dest["{$to}#"] = $source["{$from}#"];
			else :
				$n = 1;
	 		endif;

	 		for ($i = 1; $i <= $n; $i++) :
	 			if (isset($via)) : // custom callback for ninja attacks
	 				$this->{$via}($source, $from, $dest, $to, $i);
				else : // just make it the same
					$from_id = $this->element_id($from, $i);
					$to_id = $this->element_id($to, $i);
					$dest[$to_id] = $source[$from_id];
				endif;
			endfor;
		endif;
	} /* MagpieFromSimplePie::normalize_element */

	/**
	 * MagpieFromSimplePie::normalize_enclosure
	 *
	 * @param array &$source
	 * @param string $from
	 * @param array &$dest
	 * @param string $to
	 * @param int $i
	 *
	 * @uses MagpieFromSimplePie::element_id
	 */
	function normalize_enclosure (&$source, $from, &$dest, $to, $i) {
		$id_from = $this->element_id($from, $i);
		$id_to = $this->element_id($to, $i);
		if (isset($source["{$id_from}@"])) :
			foreach (explode(',', $source["{$id_from}@"]) as $attr) :
				if ($from=='link_enclosure' and $attr=='href') : // from Atom
					$dest["{$id_to}@url"] = $source["{$id_from}@{$attr}"];
					$dest["{$id_to}"] = $source["{$id_from}@{$attr}"];
				elseif ($from=='enclosure' and $attr=='url') : // from RSS
					$dest["{$id_to}@href"] = $source["{$id_from}@{$attr}"];
					$dest["{$id_to}"] = $source["{$id_from}@{$attr}"];
				else :
					$dest["{$id_to}@{$attr}"] = $source["{$id_from}@{$attr}"];
				endif;
			endforeach;
		endif;
	} /* MagpieFromSimplePie::normalize_enclosure */

	/**
	 * MagpieFromSimplePie::normalize_atom_person
	 *
	 * @param array &$source
	 * @param string $person
	 * @param array &$dest
	 * @param string $to
	 * @param int $i
	 *
	 *
	 * @uses MagpieFromSimplePie::element_id
	 */
	function normalize_atom_person (&$source, $person, &$dest, $to, $i) {
	   	   $id = $this->element_id($person, $i);
	   	   $id_to = $this->element_id($to, $i);

	   	   // Atom 0.3 <=> Atom 1.0
	   	if ($this->feed_version() >= 1.0) :
	   		$used = 'uri'; $norm = 'url';
		else :
	   		$used = 'url'; $norm = 'uri';
	   	endif;

	   	if (isset($source["{$id}_{$used}"])) :
	   		$dest["{$id_to}_{$norm}"] = $source["{$id}_{$used}"];
	   	endif;

	   	// Atom to RSS 2.0 and Dublin Core
	   	// RSS 2.0 person strings should be valid e-mail addresses if possible.
	   	if (isset($source["{$id}_email"])) :
	   		$rss_author = $source["{$id}_email"];
	   	endif;
	   	if (isset($source["{$id}_name"])) :
	   		$rss_author = $source["{$id}_name"]
	   			. (isset($rss_author) ? " <$rss_author>" : '');
	   	endif;
	   	if (isset($rss_author)) :
			$source[$id] = $rss_author; // goes to top-level author or contributor
			$dest[$id_to] = $rss_author; // goes to dc:creator or dc:contributor
		endif;
	} /* MagpieFromSimplePie::normalize_atom_person */

	/**
	 * MagpieFromSimplePie::normalize_category: Normalize Atom 1.0 and
	 * RSS 2.0 categories to Dublin Core...
	 *
	 * @param array &$source
	 * @param string $from
	 * @param array &$dest
	 * @param string $to
	 * @param int $i
	 *
	 * @uses MagpieFromSimplePie::element_id
	 * @uses MagpieFromSimplePie::is_rss
	 */
	function normalize_category (&$source, $from, &$dest, $to, $i) {
		 $cat_id = $this->element_id($from, $i);
		 $dc_id = $this->element_id($to, $i);

		 // first normalize category elements: Atom 1.0 <=> RSS 2.0
		 if ( isset($source["{$cat_id}@term"]) ) : // category identifier
		 	$source[$cat_id] = $source["{$cat_id}@term"];
		elseif ( $this->is_rss() ) :
			$source["{$cat_id}@term"] = $source[$cat_id];
		endif;

		if ( isset($source["{$cat_id}@scheme"]) ) : // URI to taxonomy
			$source["{$cat_id}@domain"] = $source["{$cat_id}@scheme"];
		elseif ( isset($source["{$cat_id}@domain"]) ) :
			$source["{$cat_id}@scheme"] = $source["{$cat_id}@domain"];
		endif;

		// Now put the identifier into dc:subject
		$dest[$dc_id] = $source[$cat_id];
	} /* MagpieFromSimplePie::normalize_category */
    
	/**
	 * MagpieFromSimplePie::normalize_dc_subject: Normalize Dublin Core
	 * "subject" elements to Atom 1.0 and RSS 2.0 categories.
	 *
	 * @param array &$source
	 * @param string $from
	 * @param array &$dest
	 * @param string $to
	 * @param int $i
	 *
	 * @uses MagpieFromSimplePie::element_id
	 */
	function normalize_dc_subject (&$source, $from, &$dest, $to, $i) {
	  	  $dc_id = $this->element_id($from, $i);
	  	  $cat_id = $this->element_id($to, $i);

	  	  $dest[$cat_id] = $source[$dc_id];       // RSS 2.0
	  	  $dest["{$cat_id}@term"] = $source[$dc_id];  // Atom 1.0
	}

	/**
	 * MagpieFromSimplePie::element_id
	 * Magic ID function for multiple elemenets.
	 * Can be called as static MagpieRSS::element_id()
	 *
	 * @param string $el
	 * @param int $counter
	 * @return string
	 */
	function element_id ($el, $counter) {
		return $el . (($counter > 1) ? '#'.$counter : '');
	} /* MagpieFromSimplePie::element_id */

	/**
	 * MagpieFromSimplePie::increment_element
	 *
	 * @param array &$data
	 * @param string $childTag
	 * @param string $ns
	 * @param array $path
	 */
	function increment_element (&$data, $childTag, $ns, $path) {
		$counterIndex = strtolower(implode('_', array_merge($path, array($childTag.'#'))));
		if ($this->is_namespaced($ns)) :
			if (!isset($data[$ns])) : $data[$ns] = array(); endif;
			if (!isset($data[$ns][$counterIndex])) : $data[$ns][$counterIndex] = 0; endif;
			$data[$ns][$counterIndex] += 1;
			$N = $data[$ns][$counterIndex];
		else :
			if (!isset($data[$counterIndex])) : $data[$counterIndex] = 0; endif;
			$data[$counterIndex] += 1;
			$N = $data[$counterIndex];
		endif;
		
		if ($N > 1) :
			$childTag .= '#'.$N;
		endif;
		return $childTag;
	} /* MagpieFromSimplePie::increment_element */

	/**
	 * MagpieFromSimplePie::is_namespaced
	 *
	 * @param string $ns
	 * @return bool
	 *
	 * @uses MagpieFromSimplePie::is_atom
	 * @uses MagpieFromSimplePie::is_rdf
	 */
	function is_namespaced ($ns, $attribute = false) {
		// Atom vs. RSS
		if ($this->is_atom()) : $root = array('', 'atom');
		else : $root = array('', 'rss');
		endif;
		
		// RDF formats; namespaced in attribs but not in elements
		if (!$attribute and $this->is_rdf()) :
			$root[] = 'rdf';
		endif;

		return !in_array(strtolower($ns), $root);
	} /* MagpieFromSimplePie::is_namespaced */

	/**
	 * MagpieFromSimplePie::is_atom
	 *
	 * @return bool
	 */
	function is_atom () {
		return $this->pie->get_type() & SIMPLEPIE_TYPE_ATOM_ALL;
	} /* MagpieFromSimplePie::increment_element */
	
	/**
	 * MagpieFromSimplePie::is_rss
	 *
	 * @return bool
	 */
	function is_rss () {
		return $this->pie->get_type() & SIMPLEPIE_TYPE_RSS_ALL;
	} /* MagpieFromSimplePie::is_rss */
	
	/**
	 * MagpieFromSimplePie::is_rdf
	 *
	 * @return bool
	 */
	function is_rdf () {
		return $this->pie->get_type() & SIMPLEPIE_TYPE_RSS_RDF;
	} /* MagpieFromSimplePie::is_rdf */

	/**
	 * MagpieFromSimplePie::feed_version
	 *
	 * @return float
	 */
	function feed_version () {
		$map = array (
			SIMPLEPIE_TYPE_ATOM_10 => 1.0,
			SIMPLEPIE_TYPE_ATOM_03 => 0.3,
			SIMPLEPIE_TYPE_RSS_090 => 0.90,
			SIMPLEPIE_TYPE_RSS_091 => 0.91,
			SIMPLEPIE_TYPE_RSS_092 => 0.92,
			SIMPLEPIE_TYPE_RSS_093 => 0.93,
			SIMPLEPIE_TYPE_RSS_094 => 0.94,
			SIMPLEPIE_TYPE_RSS_10 => 1.0,
			SIMPLEPIE_TYPE_RSS_20 => 2.0,
		);

		$ret = NULL; $type = $this->pie->get_type();
		foreach ($map as $flag => $version) :
			if ($type & $flag) :
				$ret = $version;
				break;
			endif;
		endforeach;
		return $ret;
	} /* MagpieFromSimplePie::feed_version */

} /* class MagpieFromSimplePie */

