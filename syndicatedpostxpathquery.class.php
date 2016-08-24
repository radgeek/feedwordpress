<?php
/**
 * class SyndicatedPostXPathQuery: implements an XPath-like syntax used to query
 * arbitrary elements within the syndicated item.
 *
 */
class SyndicatedPostXPathQuery {
	private $path;
	private $parsedPath;
	private $feed_type;
	private $xmlns;
	private $urlHash = array();
	
	/**
	 * SyndicatedPostXPathQuery::__construct
	 *
	 * @param array $args
	 * @uses wp_parse_args
	 *
	 */
	public function __construct ($args = array()) {
		if (is_string($args)) :
			$args = array("path" => $args);
		endif;
		
		$args = wp_parse_args($args, array(
		"path" => "",
		));

		$this->setPath($args['path']);
	} /* SyndicatedPostXPathQuery::__construct() */
	
	/**
	 * SyndicatedPostXPathQuery::getPath
	 *
	 * @param array $args
	 * @return mixed
	 */
	public function getPath ($args = array()) {
			$args = wp_parse_args($args, array(
				"parsed" => false,
			));

			return ($args['parsed'] ? $this->parsedPath : $this->path);
	} /* SyndicatedPostXPathQuery::getPath () */
	
	/**
	 * SyndicatedPostXPathQuery::setPath
	 *
	 * @param string $path
	 */
	public function setPath ($path) {
		$this->urlHash = array();
		
		$this->path = $path;

	 	// Allow {url} notation for namespaces. URLs will contain : and /, so...
	 	preg_match_all('/{([^}]+)}/', $path, $match, PREG_SET_ORDER);
	 	foreach ($match as $ref) :
	 	 	$this->urlHash[md5($ref[1])] = $ref[1];
		endforeach;

		foreach ($this->urlHash as $hash => $url) :
			$path = str_replace('{'.$url.'}', '{#'.$hash.'}', $path);
		endforeach;

		$path = $this->parsePath(/*cur=*/ $path, /*orig=*/ $path);
		
		$this->parsedPath = $path;

	} /* SyndicatedPostXPathQuery::setPath() */
	
	/**
	 * SyndicatedPostXPathQuery::snipSlug
	 *
	 * @return string
	 */
	protected function snipSlug ($path, $start, $n) {
		$slug = substr($path, $start, ($n-$start));
		if (strlen($slug) > 0) :
			if (preg_match('/{#([^}]+)}/', $slug, $ref)) :
				if (isset($this->urlHash[$ref[1]])) :
					$slug = str_replace(
						'{#'.$ref[1].'}',
						'{'.$this->urlHash[$ref[1]].'}',
						$slug
					);
				endif;
			endif;
		endif;
		return $slug;
	} /* SyndicatedPostXPathQuery::snipSlug () */
	
	/**
	 * SyndicatedPostXPathQuery::parsePath ()
	 *
	 * @param mixed $path
	 * @param mixed $rootPath
	 * @return array|object
	 */
	public function parsePath ($path, $rootPath) {
		if (is_array($path)) :
			// This looks like it's already been parsed.
			$pp = $path;
		else :
			$pp = array();
			
			// Okay let's parse this thing.
			$n = 0; $start = 0; $state = 'slug';
			while ($state != '$') :
			switch ($state) :
			case 'slash' :
				$slug = $this->snipSlug($path, $start, $n);
				if (strlen($slug) > 0) :
					$pp[] = $slug;
				endif;

				$n++;
				// don't include the slash in our next slug
				$start = $n;
				
				$state = (($n < strlen($path)) ? 'slug' : '$');
				
				break;
			case 'brackets' :

				// first, snip off what we've consumed so far
				$slug = $this->snipSlug($path, $start, $n);
				if (strlen($slug) > 0) :
					$pp[] = $slug;
				endif;

				// now, chase the ]
				$depth = 1;
				$n++; $start = $n;
				
				// find the end of the [square-bracketed] expression
				while ($depth > 0 and $n != '') :
					$tok = ((strlen($path) > $n) ? $path[$n] : '');
					switch ($tok) :
					case '' :
						// ERROR STATE: syntax error
						$depth = -1;
						$state = 'syntax-error';
						break;
					case '[' :
						$depth++;
						break;
					case ']' :
						$depth--;
						break;
					default :
						// NOOP
					endswitch;
					$n++;
				endwhile;

				if ($state != 'syntax-error') :
					$bracketed = substr($path, $start, ($n-$start)-1);

					// recursive parsing
					$oFilter = new stdClass;
					$oFilter->verb = 'has';
					$oFilter->query = $this->parsePath($bracketed, $rootPath);
					$pp[] = $oFilter;
				
					$start = $n;
					
					$state = 'slash-expected';
				endif;
				break;
				
			case 'slash-expected' :
				$tok = ((strlen($path) > $n) ? $path[$n] : '');
				if ($tok == '/' or $tok == '') :
					$state = 'slash';
				else :
					$state = 'syntax-error';
				endif;
				break;
			case 'syntax-error' :
				$pp = new WP_Error('xpath', __("Syntax error", "feedwordpress"));
				$state = '$';
				break;
			case 'slug' :
			default :
				$tok = ((strlen($path) > $n) ? $path[$n] : '');
				switch ($tok) :
				case '' :
				case '/' :
					$state = 'slash';
					break;
				case '[' :
					$state = 'brackets';
					break;
				default :
					$n++;
				endswitch;
			endswitch;
			endwhile;
		endif;
		return $pp;
	} /* SyndicatedPostXPathQuery::parsePath() */
	
	/**
	 * SyndicatedPostXPathQuery::match
	 *
	 * @param string $path
	 * @return array
	 */
	public function match ($r = array()) {
		$path = $this->parsedPath;
		
		$r = wp_parse_args($r, array(
		"type" => SIMPLEPIE_TYPE_ATOM_10,
		"xmlns" => array(),
		"map" => array(),
		"context" => array(),
		"parent" => array(),
		"format" => "string",
		));

		$this->feed_type = $r['type'];
		$this->xmlns = $r['xmlns'];
		
		// Start out with a get_item_tags query.
		$node = '';
		while (strlen($node)==0 and !is_null($node)) :
			$node = array_shift($path);
		endwhile;

		if (is_string($node) and isset($r['map'][$node])) :
			$data = $r['map'][$node];
			$node = array_shift($path);
		else :
			$data = $r['map']['/'];
		endif;

		$matches = $data;
		while (!is_null($node)) :
			if (is_object($node) OR strlen($node) > 0) :
				list($axis, $element) = $this->xpath_name_and_axis($node);
				if ('self'==$axis) :
					if (is_object($element) and property_exists($element, 'verb')) :

						$subq = new self(array("path" => $element->query));
						$result = $subq->match(array(
							"type" => $r['type'],
							"xmlns" => $r['xmlns'],
							"map" => array(
								"/" => $matches,
							),
							"context" => $matches,
							"parent" => $r['parent'],
							"format" => "object",
						));
						
						// when format = 'object' we should get back
						// a sparse array of arrays, with indices = indices
						// from the input array, each element = an array of
						// one or more matching elements
	
						if ($element->verb = 'has' and is_array($result)) :

							$results = array();
							foreach (array_keys($result) as $a) :
								$results[$a] = $matches[$a];
							endforeach;
							
							$matches = $results;
							$data = $matches;
						endif;
												
					elseif (is_numeric($node)) :

						// according to W3C, sequence starts at position 1, not 0
						// so subtract 1 to line up with PHP array starting at 0
						$idx = intval($element) - 1;
						if (isset($matches[$idx])) :
							$data = array($idx => $matches[$idx]);
						else :
							$data = array();
						endif;
						
						$matches = array($idx => $data);
					endif;
					
				else :
					$matches = array();

					foreach ($data as $idx => $datum) :
						if (!is_string($datum) and isset($datum[$axis])) :
							foreach ($datum[$axis] as $ns => $elements) :
								if (isset($elements[$element])) :
									// Potential match.
									// Check namespace.
									if (is_string($elements[$element])) : // Attribute
										$addenda = array($elements[$element]);
										$contexts = array($datum);
									
									// Element
									else :
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
			endif;
			$node = array_shift($path);
		endwhile;

		$matches = array();
		foreach ($data as $idx => $datum) :
			if ($r['format'] == 'string') :
				if (is_string($datum)) :
					$matches[] = $datum;
				elseif (isset($datum['data'])) :
					$matches[] = $datum['data'];
				endif;
			else :
				$matches[$idx] = $datum;
			endif;
		endforeach;
		
		return $matches;
	} /* SyndicatedPostXPathQuery::match() */
	
	public function xpath_default_namespace () {
		// Get the default namespace.
		$type = $this->feed_type;
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
	} /* SyndicatedPostXPathQuery::xpath_default_namespace() */

	public function xpath_name_and_axis ($node) {
		$ns = NULL; $element = NULL;

		$axis = 'child'; // "In effect, `child` is the default axis."
		if (is_object($node) and property_exists($node, 'verb')):
			if ('has'==$node->verb) :
				$axis = 'self';
			endif;
		elseif (strpos($node, '::') !== false) :
			list($axis, $node) = explode("::", $node, 2);
			if ($axis=='attribute') :
				$axis = 'attribs'; // map from W3C to SimplePie's idiosyncratic notation
			endif;
		elseif (substr($node, 0, 1)=='@') :
			$axis = 'attribs'; $node = substr($node, 1);
		elseif (is_numeric($node)) :
			$axis = 'self';
		elseif (substr($node, 0, 1)=='/') :
			// FIXME: properly, we should check for // and if we have it,
			// treat that as short for /descendent-or-self::node()/
			$axis = 'child'; $node = substr($node, 1);
		else :
			// NOOP
		endif;

		if (is_string($node) and preg_match('/^{([^}]*)}(.*)$/', $node, $ref)) :
			$element = $ref[2];
		elseif (is_string($node) and strpos($node, ':') !== FALSE) :
			list($xmlns, $element) = explode(':', $node, 2);
		else :
			$element = $node;
		endif;
		return array($axis, $element);
	} /* SyndicatedPostXPathQuery::xpath_name_and_axis () */

	public function xpath_possible_namespaces ($node, $datum = array()) {
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
	} /* SyndicatedPostXPathQuery::xpath_possible_namespaces() */

} /* class SyndicatedPostXPathQuery */

// When called directly, run through and perform some tests.
if (basename($_SERVER['SCRIPT_FILENAME'])==basename(__FILE__)) :
	# some day when I am a grown-up developer I might include
	# some test cases in this here section
	# we need to implement wp_parse_args(), __(), and class WP_Error ...
	#function wp_parse_args ($r, $defaults) {
	#	return array_merge($defaults, $r);
	#}
	#function __($text, $domain) {
	#	return $text;
	#}
	#class WP_Error {
	#	public function __construct ( $slug, $message ) {
	#		/*DBG*/ echo $slug;
	#		/*DBG*/ echo ": ";
	#		/*DBG*/ echo $message;
	#	}
	#}
	#
	#header("Content-type: text/plain");
	#
	#$spxq = new SyndicatedPostXPathQuery(array("path" => $_REQUEST['p']));
	#
	#var_dump($spxq);
endif;
