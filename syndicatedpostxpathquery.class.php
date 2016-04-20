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

		$path = explode('/', $path);
		foreach ($path as $index => $node) :
			if (preg_match('/{#([^}]+)}/', $node, $ref)) :
				if (isset($this->urlHash[$ref[1]])) :
					$path[$index] = str_replace(
						'{#'.$ref[1].'}',
						'{'.$this->urlHash[$ref[1]].'}',
						$node
					);
				endif;
			endif;
		endforeach;
		
		$this->parsedPath = $path;

	} /* SyndicatedPostXPathQuery::setPath() */
	
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
		"entry" => array(),
		"feed" => array(),
		"channel" => array(),
		));

		$this->feed_type = $r['type'];
		$this->xmlns = $r['xmlns'];
		
		// Start out with a get_item_tags query.
		$node = '';
		while (strlen($node)==0 and !is_null($node)) :
			$node = array_shift($path);
		endwhile;

		switch ($node) :
		case 'feed' :
		case 'channel' :
			$node = array_shift($path);
			$data = $r['feed'];
			$data = array_merge($data, $r['channel']);
			break;
		case 'item' :
			$node = array_shift($path);
		default :
			$data = array($r['entry']);
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
	# we need to implement wp_parse_args()...
endif;
