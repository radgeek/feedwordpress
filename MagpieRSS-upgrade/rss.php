<?php
/* Project:	MagpieRSS: a simple RSS integration tool
 * File:	A compiled file for RSS syndication
 * Author:	Kellan Elliot-McCrea <kellan@protest.net>
 *		WordPress development team <http://www.wordpress.org/>
 *		Charles Johnson <technophilia@radgeek.com>
 * Version:	2010.0122
 * License:	GPL
 *
 * Provenance:
 *
 * This is a drop-in replacement for the `rss-functions.php` provided with the
 * WordPress 1.5 distribution, which upgrades the version of MagpieRSS from 0.51
 * to 0.8a. The update improves handling of character encoding, supports
 * multiple categories for posts (using <dc:subject> or <category>), supports
 * Atom 1.0, and implements many other useful features. The file is derived from
 * a combination of (1) the WordPress development team's modifications to
 * MagpieRSS 0.51 and (2) the latest bleeding-edge updates to the "official"
 * MagpieRSS software, including Kellan's original work and some substantial
 * updates by Charles Johnson. All possible through the magic of the GPL. Yay
 * for free software!
 *
 * Differences from the main branch of MagpieRSS:
 *
 * 1.	Everything in rss_parse.inc, rss_fetch.inc, rss_cache.inc, and
 *	rss_utils.inc is included in one file.
 *
 * 2.	MagpieRSS returns the WordPress version as the user agent, rather than
 *	Magpie
 *
 * 3.	class RSSCache is a modified version by WordPress developers, which
 * 	caches feeds in the WordPress database (in the options table), rather
 * 	than writing external files directly.
 *
 * 4.	There are two WordPress-specific functions, get_rss() and wp_rss()
 *
 * Differences from the version of MagpieRSS packaged with WordPress:
 *
 * 1.	Support for translation between multiple character encodings. Under
 *	PHP 5 this is very nicely handled by the XML parsing library. Under PHP
 *	4 we need to do a little bit of work ourselves, using either iconv or
 *	mb_convert_encoding if it is not one of the (extremely limited) number
 *	of character sets that PHP 4's XML module can handle natively.
 *
 * 2.	Numerous bug fixes.
 *
 * 3.	The parser class MagpieRSS has been substantially revised to better
 *	support popular features such as enclosures and multiple categories,
 *	and to support the new Atom 1.0 IETF standard. (Atom feeds are
 *	normalized so as to make the data available using terminology from
 *	either Atom 0.3 or Atom 1.0. Atom 0.3 backward-compatibility is provided
 *	to allow existing software to easily begin accepting Atom 1.0 data; new
 *	software SHOULD NOT depend on the 0.3 terminology, but rather use the
 *	normalization as a convenient way to keep supporting 0.3 feeds while
 *	they linger in the world.)
 *
 *	The upgraded MagpieRSS can also now handle some content constructs that
 *	had not been handled well by previous versions of Magpie (such as the
 *	use of namespaced XHTML in <xhtml:body> or <xhtml:div> elements to
 *	provide the full content of posts in RSS 2.0 feeds).
 *
 *	Unlike previous versions of MagpieRSS, this version can parse multiple
 *	instances of the same child element in item/entry and channel/feed
 *	containers. This is done using simple counters next to the element
 *	names: the first <category> element on an RSS item, for example, can be
 *	found in $item['category'] (thus preserving backward compatibility); the
 *	second in $item['category#2'], the third in $item['category#3'], and so
 *	on. The number of categories applied to the item can be found in
 *	$item['category#']
 *
 *	Also unlike previous versions of MagpieRSS, this version allows you to
 *	access the values of elements' attributes as well as the content they
 *	contain. This can be done using a simple syntax inspired by XPath: to
 *	access the type attribute of an RSS 2.0 enclosure, for example, you
 *	need only access `$item['enclosure@type']`. A comma-separated list of
 *	attributes for the enclosure element is stored in `$item['enclosure@']`.
 *	(This syntax interacts easily with the syntax for multiple categories;
 *	for example, the value of the `scheme` attribute for the fourth category
 *	element on a particular item is stored in `$item['category#4@scheme']`.)
 *
 *	Note also that this implementation IS NOT backward-compatible with the
 *	kludges that were used to hack in support for multiple categories and
 *	for enclosures in upgraded versions of MagpieRSS distributed with
 *	previous versions of FeedWordPress. If your hacks or filter plugins
 *	depended on the old way of doing things... well, I warned you that they
 *	might not be permanent. Sorry!
 */

define('RSS', 'RSS');
define('ATOM', 'Atom');

################################################################################
## WordPress: make some settings WordPress-appropriate #########################
################################################################################

define('MAGPIE_USER_AGENT', 'WordPress/' . $wp_version . '(+http://www.wordpress.org)');

$wp_encoding = get_option('blog_charset', /*default=*/ 'ISO-8859-1');
define('MAGPIE_OUTPUT_ENCODING', ($wp_encoding?$wp_encoding:'ISO-8859-1'));

################################################################################
## rss_parse.inc: from MagpieRSS 0.85 ##########################################
################################################################################

/**
* Hybrid parser, and object, takes RSS as a string and returns a simple object.
*
* see: rss_fetch.inc for a simpler interface with integrated caching support
*
*/
class MagpieRSS {
    var $parser;
    
    var $current_item   = array();  // item currently being parsed
    var $items          = array();  // collection of parsed items
    var $channel        = array();  // hash of channel fields
    var $textinput      = array();
    var $image          = array();
    var $feed_type;
    var $feed_version;
    var $encoding       = '';       // output encoding of parsed rss
    
    var $_source_encoding = '';     // only set if we have to parse xml prolog
    
    var $ERROR = "";
    var $WARNING = "";
    
    // define some constants
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
    );

    var $_XMLBASE_RESOLVE = array (
    	// Atom 0.3 and 1.0 xml:base support
	'atom' => array (
		'link' => array ('href' => true),
		'content' => array ('src' => true, '*xml' => true, '*html' => true),
		'summary' => array ('*xml' => true, '*html' => true),
		'title' => array ('*xml' => true, '*html' => true),
		'rights' => array ('*xml' => true, '*html' => true),
		'subtitle' => array ('*xml' => true, '*html' => true),
		'info' => array('*xml' => true, '*html' => true),
		'tagline' => array('*xml' => true, '*html' => true),
		'copyright' => array ('*xml' => true, '*html' => true),
		'generator' => array ('uri' => true, 'url' => true),
		'uri' => array ('*content' => true),
		'url' => array ('*content' => true),
		'icon' => array ('*content' => true),
		'logo' => array ('*content' => true),
	),
	
	// for inline namespaced XHTML
	'xhtml' => array (
		'a' => array ('href' => true),
		'applet' => array('codebase' => true),
		'area' => array('href' => true),
		'blockquote' => array('cite' => true),
		'body' => array('background' => true),
		'del' => array('cite' => true),
		'form' => array('action' => true),
		'frame' => array('longdesc' => true, 'src' => true),
		'iframe' => array('longdesc' => true, 'iframe' => true, 'src' => true),
		'head' => array('profile' => true),
		'img' => array('longdesc' => true, 'src' => true, 'usemap' => true),
		'input' => array('src' => true, 'usemap' => true),
		'ins' => array('cite' => true),
		'link' => array('href' => true),
		'object' => array('classid' => true, 'codebase' => true, 'data' => true, 'usemap' => true),
		'q' => array('cite' => true),
		'script' => array('src' => true),
	),
    );

    var $_ATOM_CONTENT_CONSTRUCTS = array(
        'content', 'summary', 'title', /* common */
    'info', 'tagline', 'copyright', /* Atom 0.3 */
        'rights', 'subtitle', /* Atom 1.0 */
    );
    var $_XHTML_CONTENT_CONSTRUCTS = array('body', 'div');
    var $_KNOWN_ENCODINGS    = array('UTF-8', 'US-ASCII', 'ISO-8859-1');

    // parser variables, useless if you're not a parser, treat as private
    var $stack    = array('element' => array (), 'ns' => array (), 'xmlns' => array (), 'xml:base' => array ()); // stack of XML data

    var $inchannel          = false;
    var $initem             = false;

    var $incontent          = array(); // non-empty if in namespaced XML content field
    var $xml_escape	    = false; // true when accepting namespaced XML
    var $exclude_top        = false; // true when Atom 1.0 type="xhtml"

    var $intextinput        = false;
    var $inimage            = false;
    var $root_namespaces    = array();
    var $current_namespace  = false;
    var $working_namespace_table = array();

    /**
     *  Set up XML parser, parse source, and return populated RSS object..
     *   
     *  @param string $source           string containing the RSS to be parsed
     *
     *  NOTE:  Probably a good idea to leave the encoding options alone unless
     *         you know what you're doing as PHP's character set support is
     *         a little weird.
     *
     *  NOTE:  A lot of this is unnecessary but harmless with PHP5 
     *
     *
     *  @param string $output_encoding  output the parsed RSS in this character 
     *                                  set defaults to ISO-8859-1 as this is PHP's
     *                                  default.
     *
     *                                  NOTE: might be changed to UTF-8 in future
     *                                  versions.
     *                               
     *  @param string $input_encoding   the character set of the incoming RSS source. 
     *                                  Leave blank and Magpie will try to figure it
     *                                  out.
     *                                  
     *                                   
     *  @param bool   $detect_encoding  if false Magpie won't attempt to detect
     *                                  source encoding. (caveat emptor)
     *
     */
    function MagpieRSS ($source, $output_encoding='ISO-8859-1', 
                        $input_encoding=null, $detect_encoding=true, $base_uri=null) 
    {   
        # if PHP xml isn't compiled in, die
        #
        if (!function_exists('xml_parser_create')) {
            $this->error( "Failed to load PHP's XML Extension. " . 
                          "http://www.php.net/manual/en/ref.xml.php",
                           E_USER_ERROR );
        }
        
        list($parser, $source) = $this->create_parser($source, 
                $output_encoding, $input_encoding, $detect_encoding);
        
        
        if (!is_resource($parser)) {
            $this->error( "Failed to create an instance of PHP's XML parser. " .
                          "http://www.php.net/manual/en/ref.xml.php",
                          E_USER_ERROR );
        }

        
        $this->parser = $parser;
        
        # pass in parser, and a reference to this object
        # setup handlers
        #
        xml_set_object( $this->parser, $this );
        xml_set_element_handler($this->parser, 
                'feed_start_element', 'feed_end_element' );
                        
        xml_set_character_data_handler( $this->parser, 'feed_cdata' ); 

	$this->stack['xml:base'] = array($base_uri);

        $status = xml_parse( $this->parser, $source );
        
        if (! $status ) {
            $errorcode = xml_get_error_code( $this->parser );
            if ( $errorcode != XML_ERROR_NONE ) {
                $xml_error = xml_error_string( $errorcode );
                $error_line = xml_get_current_line_number($this->parser);
                $error_col = xml_get_current_column_number($this->parser);
                $errormsg = "$xml_error at line $error_line, column $error_col";

                $this->error( $errormsg );
            }
        }
        
        xml_parser_free( $this->parser );

        $this->normalize();
    }
    
    function feed_start_element($p, $element, &$attributes) {
        $el = strtolower($element);

	$namespaces = end($this->stack['xmlns']);
	$baseuri = end($this->stack['xml:base']);

 	if (isset($attributes['xml:base'])) {
		$baseuri = Relative_URI::resolve($attributes['xml:base'], $baseuri);
	}
	array_push($this->stack['xml:base'], $baseuri);

	// scan for xml namespace declarations. ugly ugly ugly.
	// theoretically we could use xml_set_start_namespace_decl_handler and
	// xml_set_end_namespace_decl_handler to handle this more elegantly, but
	// support for these is buggy
	foreach ($attributes as $attr => $value) {
		if ( preg_match('/^xmlns(\:([A-Z_a-z].*))?$/', $attr, $match) ) {
			$ns = (isset($match[2]) ? $match[2] : '');
			$namespaces[$ns] = $value;
		}
	}

	array_push($this->stack['xmlns'], $namespaces);

        // check for a namespace, and split if found
        // Don't munge content tags
	$ns = $this->xmlns($element);
	if ( empty($this->incontent) ) {
		$el = strtolower($ns['element']);
		$this->current_namespace = $ns['effective'];
		array_push($this->stack['ns'], $ns['effective']);
	}

	$nsc = $ns['canonical']; $nse = $ns['element'];
 	if ( isset($this->_XMLBASE_RESOLVE[$nsc][$nse]) ) {
		if (isset($this->_XMLBASE_RESOLVE[$nsc][$nse]['*xml'])) {
			$attributes['xml:base'] = $baseuri;
		}
		foreach ($attributes as $key => $value) {
			if (isset($this->_XMLBASE_RESOLVE[$nsc][$nse][strtolower($key)])) {
				$attributes[$key] = Relative_URI::resolve($attributes[$key], $baseuri);
			}
		}
	}

        $attrs = array_change_key_case($attributes, CASE_LOWER);

        # if feed type isn't set, then this is first element of feed
        # identify feed from root element
        #
        if (!isset($this->feed_type) ) {
            if ( $el == 'rdf' ) {
                $this->feed_type = RSS;
		$this->root_namespaces = array('rss', 'rdf');
                $this->feed_version = '1.0';
            }
            elseif ( $el == 'rss' ) {
                $this->feed_type = RSS;
		$this->root_namespaces = array('rss');
                $this->feed_version = $attrs['version'];
            }
            elseif ( $el == 'feed' ) {
                $this->feed_type = ATOM;
		$this->root_namespaces = array('atom');
                if ($ns['uri'] == 'http://www.w3.org/2005/Atom') { // Atom 1.0
			$this->feed_version = '1.0';
                }
                else { // Atom 0.3, probably.
                    $this->feed_version = $attrs['version'];
                }
                $this->inchannel = true;
            }
            return;
        }

        // if we're inside a namespaced content construct, treat tags as text
        if ( !empty($this->incontent) ) 
        {
            if ((count($this->incontent) > 1) or !$this->exclude_top) {
		  if ($ns['effective']=='xhtml') {
			  $tag = $ns['element'];
		  }
		  else {
			  $tag = $element;
			  $xmlns = 'xmlns';
			  if (strlen($ns['prefix'])>0) {
				  $xmlns = $xmlns . ':' . $ns['prefix'];
			  }
			  $attributes[$xmlns] = $ns['uri']; // make sure it's visible
		  }

                 // if tags are inlined, then flatten
                $attrs_str = join(' ', 
                    array_map(array($this, 'map_attrs'), 
                    array_keys($attributes), 
                    array_values($attributes) )
                  );
                
                  if (strlen($attrs_str) > 0) { $attrs_str = ' '.$attrs_str; }
               $this->append_content( "<{$tag}{$attrs_str}>"  );
            }
            array_push($this->incontent, $ns); // stack for parsing content XML
        }

        elseif ( $el == 'channel' )  {
            $this->inchannel = true;
        }
    
        elseif ($el == 'item' or $el == 'entry' ) 
        {
            $this->initem = true;
            if ( isset($attrs['rdf:about']) ) {
                $this->current_item['about'] = $attrs['rdf:about']; 
            }
        }

        // if we're in the default namespace of an RSS feed,
        //  record textinput or image fields
        elseif ( 
            $this->feed_type == RSS and 
            $this->current_namespace == '' and 
            $el == 'textinput' ) 
        {
            $this->intextinput = true;
        }
        
        elseif (
            $this->feed_type == RSS and 
            $this->current_namespace == '' and 
            $el == 'image' ) 
        {
            $this->inimage = true;
        }
        
        // set stack[0] to current element
        else {
            // Atom support many links per containing element.
            // Magpie treats link elements of type rel='alternate'
            // as being equivalent to RSS's simple link element.

            $atom_link = false;
            if ( ($ns['canonical']=='atom') and $el == 'link') {
                $atom_link = true;
                if (isset($attrs['rel']) and $attrs['rel'] != 'alternate') {
                    $el = $el . "_" . $attrs['rel'];  // pseudo-element names for Atom link elements
                }
            }
            # handle atom content constructs
            elseif ( ($ns['canonical']=='atom') and in_array($el, $this->_ATOM_CONTENT_CONSTRUCTS) )
            {
                // avoid clashing w/ RSS mod_content
                if ($el == 'content' ) {
                    $el = 'atom_content';
                }

                // assume that everything accepts namespaced XML
                // (that will pass through some non-validating feeds;
                // but so what? this isn't a validating parser)
                $this->incontent = array();
                array_push($this->incontent, $ns); // start a stack
		
		$this->xml_escape = $this->accepts_namespaced_xml($attrs);

                if ( isset($attrs['type']) and trim(strtolower($attrs['type']))=='xhtml') {
                    $this->exclude_top = true;
                } else {
                    $this->exclude_top = false;
                }
            }
            # Handle inline XHTML body elements --CWJ
            elseif ($ns['effective']=='xhtml' and in_array($el, $this->_XHTML_CONTENT_CONSTRUCTS)) {
                $this->current_namespace = 'xhtml';
                $this->incontent = array();
                array_push($this->incontent, $ns); // start a stack

		$this->xml_escape = true;
                $this->exclude_top = false;
            }
            
            array_unshift($this->stack['element'], $el);
            $elpath = join('_', array_reverse($this->stack['element']));
            
            $n = $this->element_count($elpath);
            $this->element_count($elpath, $n+1);
            
            if ($n > 0) {
                array_shift($this->stack['element']);
                array_unshift($this->stack['element'], $el.'#'.($n+1));
                $elpath = join('_', array_reverse($this->stack['element']));
            }
            
            // this makes the baby Jesus cry, but we can't do it in normalize()
            // because we've made the element name for Atom links unpredictable
            // by tacking on the relation to the end. -CWJ
            if ($atom_link and isset($attrs['href'])) {
                $this->append($elpath, $attrs['href']);
            }
        
            // add attributes
            if (count($attrs) > 0) {
                $this->append($elpath.'@', join(',', array_keys($attrs)));
                foreach ($attrs as $attr => $value) {
                    $this->append($elpath.'@'.$attr, $value);
                }
            }
        }
    }

    function feed_cdata ($p, $text) {
        if ($this->incontent) {
		if ($this->xml_escape) { $text = htmlspecialchars($text, ENT_COMPAT, $this->encoding); }
		$this->append_content( $text );
        } else {
            $current_el = join('_', array_reverse($this->stack['element']));
            $this->append($current_el, $text);
        }
    }
    
    function feed_end_element ($p, $el) {
	    $closer = $this->xmlns($el);

	    if ( $this->incontent ) {
		    $opener = array_pop($this->incontent);

		    // balance tags properly
		    // note:  i don't think this is actually neccessary
		    if ($opener != $closer) {
			    array_push($this->incontent, $opener);
			    $this->append_content("<$el />");
		    } elseif ($this->incontent) { // are we in the content construct still?
			    if ((count($this->incontent) > 1) or !$this->exclude_top) {
				  if ($closer['effective']=='xhtml') {
					  $tag = $closer['element'];
				  }
				  else {
					  $tag = $el;
				  }
				  $this->append_content("</$tag>");
			    }
		    } else { // if we're done with the content construct, shift the opening of the content construct off the normal stack
			    array_shift( $this->stack['element'] );
		    }
	    }
	    elseif ($closer['effective'] == '') {
		    $el = strtolower($closer['element']);
		    if ( $el == 'item' or $el == 'entry' )  {
			    $this->items[] = $this->current_item;
			    $this->current_item = array();
			    $this->initem = false;
			    $this->current_category = 0;
		    }
		    elseif ($this->feed_type == RSS and $el == 'textinput' ) {
			    $this->intextinput = false;
		    }
		    elseif ($this->feed_type == RSS and $el == 'image' ) {
			    $this->inimage = false;
		    }
		    elseif ($el == 'channel' or $el == 'feed' ) {
			    $this->inchannel = false;
		    } else {
			    $nsc = $closer['canonical']; $nse = $closer['element'];
			    if (isset($this->_XMLBASE_RESOLVE[$nsc][$nse]['*content'])) {
				    // Resolve relative URI in content of tag
				    $this->dereference_current_element();
			    }
			    array_shift( $this->stack['element'] );
		    }
	    } else {
		    $nsc = $closer['canonical']; $nse = strtolower($closer['element']);
		    if (isset($this->_XMLBASE_RESOLVE[$nsc][$nse]['*content'])) {
			    // Resolve relative URI in content of tag
			    $this->dereference_current_element();
		    }
		    array_shift( $this->stack['element'] );
	    }
	    
	    if ( !$this->incontent ) { // Don't munge the namespace after finishing with elements in namespaced content constructs -CWJ
		    $this->current_namespace = array_pop($this->stack['ns']);
	    }
	    array_pop($this->stack['xmlns']);
	    array_pop($this->stack['xml:base']);
    }
    
	// Namespace handling functions
	function xmlns ($element) {
		$namespaces = end($this->stack['xmlns']);
		$ns = '';
		if ( strpos( $element, ':' ) ) {
			list($ns, $element) = split( ':', $element, 2);
		}
		
		$uri = (isset($namespaces[$ns]) ? $namespaces[$ns] : null);

		if (!is_null($uri)) {
			$canonical = (
				isset($this->_XMLNS_FAMILIAR[$uri])
				? $this->_XMLNS_FAMILIAR[$uri]
				: $uri
			);
		} else {
			$canonical = $ns;
		}

		if (in_array($canonical, $this->root_namespaces)) {
			$effective = '';
		} else {
			$effective = $canonical;
		}

		return array('effective' => $effective, 'canonical' => $canonical, 'prefix' => $ns, 'uri' => $uri, 'element' => $element);
	}

	// Utility functions for accessing data structure
	
	// for smart, namespace-aware methods...
    	function magpie_data ($el, $method, $text = NULL) {
		$ret = NULL;
		if ($el) {
			if (is_array($method)) {
				$el = $this->{$method['key']}($el);
				$method = $method['value'];
			}

			if ( $this->current_namespace ) {
				if ( $this->initem ) {
					$ret = $this->{$method} (
						$this->current_item[ $this->current_namespace ][ $el ],
						$text
					);
				}
				elseif ($this->inchannel) {
					$ret = $this->{$method} (
						$this->channel[ $this->current_namespace][ $el ],
						$text
					);
				}
				elseif ($this->intextinput) {
					$ret = $this->{$method} (
						$this->textinput[ $this->current_namespace][ $el ],
						$text
					);
				}
				elseif ($this->inimage) {
					$ret = $this->{$method} (
					$this->image[ $this->current_namespace ][ $el ], $text );
				}
			}
			else {
				if ( $this->initem ) {
					$ret = $this->{$method} (
					$this->current_item[ $el ], $text);
				}
				elseif ($this->intextinput) {
					$ret = $this->{$method} (
					$this->textinput[ $el ], $text );
				}
				elseif ($this->inimage) {
					$ret = $this->{$method} (
					$this->image[ $el ], $text );
				}
				elseif ($this->inchannel) {
					$ret = $this->{$method} (
					$this->channel[ $el ], $text );
				}
			}
		}
		return $ret;
	}
	
    function concat (&$str1, $str2="") {
        if (!isset($str1) ) {
            $str1="";
        }
        $str1 .= $str2;
    }

        function retrieve_value (&$el, $text /*ignore*/) {
	    return $el;
	}
	function replace_value (&$el, $text) {
		$el = $text;
	}
        function counter_key ($el) {
	    return $el.'#';
	}


    function append_content($text) {
	    $construct = reset($this->incontent);
	    $ns = $construct['effective'];
            
	    // Keeping data about parent elements is necessary to
	    // properly handle atom:source and its children elements
	    $tag = join('_', array_reverse($this->stack['element']));

	    if ( $this->initem ) {
		if ($ns) {
		    $this->concat( $this->current_item[$ns][$tag], $text );
		} else {
		    $this->concat( $this->current_item[$tag], $text );
		}
	    }
	    elseif ( $this->inchannel ) {
		if ($this->current_namespace) {
		    $this->concat( $this->channel[$ns][$tag], $text );
		} else {
		    $this->concat( $this->channel[$tag], $text );
		}
	    }
    }
    
    // smart append - field and namespace aware
    function append($el, $text) {
	$this->magpie_data($el, 'concat', $text);
    }

	function dereference_current_element () {
		$el = join('_', array_reverse($this->stack['element']));
		$base = end($this->stack['xml:base']);
		$uri = $this->magpie_data($el, 'retrieve_value');
		$this->magpie_data($el, 'replace_value', Relative_URI::resolve($uri, $base));
	}

    // smart count - field and namespace aware
    function element_count ($el, $set = NULL) {
	if (!is_null($set)) {
		$ret = $this->magpie_data($el, array('key' => 'counter_key', 'value' => 'replace_value'), $set);
	}
	$ret = $this->magpie_data($el, array('key' => 'counter_key', 'value' => 'retrieve_value'));
	return ($ret ? $ret : 0);
    }

    function normalize_enclosure (&$source, $from, &$dest, $to, $i) {
        $id_from = $this->element_id($from, $i);
        $id_to = $this->element_id($to, $i);
        if (isset($source["{$id_from}@"])) {
            foreach (explode(',', $source["{$id_from}@"]) as $attr) {
                if ($from=='link_enclosure' and $attr=='href') { // from Atom
                    $dest["{$id_to}@url"] = $source["{$id_from}@{$attr}"];
                    $dest["{$id_to}"] = $source["{$id_from}@{$attr}"];
                }
                elseif ($from=='enclosure' and $attr=='url') { // from RSS
                    $dest["{$id_to}@href"] = $source["{$id_from}@{$attr}"];
                    $dest["{$id_to}"] = $source["{$id_from}@{$attr}"];
                }
                else {
                    $dest["{$id_to}@{$attr}"] = $source["{$id_from}@{$attr}"];
                }
            }
        }
    }

    function normalize_atom_person (&$source, $person, &$dest, $to, $i) {
        $id = $this->element_id($person, $i);
        $id_to = $this->element_id($to, $i);

            // Atom 0.3 <=> Atom 1.0
        if ($this->feed_version >= 1.0) { $used = 'uri'; $norm = 'url'; }
        else { $used = 'url'; $norm = 'uri'; }

        if (isset($source["{$id}_{$used}"])) {
            $dest["{$id_to}_{$norm}"] = $source["{$id}_{$used}"];
        }

        // Atom to RSS 2.0 and Dublin Core
        // RSS 2.0 person strings should be valid e-mail addresses if possible.
        if (isset($source["{$id}_email"])) {
            $rss_author = $source["{$id}_email"];
        }
        if (isset($source["{$id}_name"])) {
            $rss_author = $source["{$id}_name"]
                . (isset($rss_author) ? " <$rss_author>" : '');
        }
        if (isset($rss_author)) {
            $source[$id] = $rss_author; // goes to top-level author or contributor
        $dest[$id_to] = $rss_author; // goes to dc:creator or dc:contributor
        }
    }

    // Normalize Atom 1.0 and RSS 2.0 categories to Dublin Core...
    function normalize_category (&$source, $from, &$dest, $to, $i) {
        $cat_id = $this->element_id($from, $i);
        $dc_id = $this->element_id($to, $i);

        // first normalize category elements: Atom 1.0 <=> RSS 2.0
        if ( isset($source["{$cat_id}@term"]) ) { // category identifier
            $source[$cat_id] = $source["{$cat_id}@term"];
        } elseif ( $this->feed_type == RSS ) {
            $source["{$cat_id}@term"] = $source[$cat_id];
        }
        
        if ( isset($source["{$cat_id}@scheme"]) ) { // URI to taxonomy
            $source["{$cat_id}@domain"] = $source["{$cat_id}@scheme"];
        } elseif ( isset($source["{$cat_id}@domain"]) ) {
            $source["{$cat_id}@scheme"] = $source["{$cat_id}@domain"];
        }

        // Now put the identifier into dc:subject
        $dest[$dc_id] = $source[$cat_id];
    }
    
    // ... or vice versa
    function normalize_dc_subject (&$source, $from, &$dest, $to, $i) {
        $dc_id = $this->element_id($from, $i);
        $cat_id = $this->element_id($to, $i);

        $dest[$cat_id] = $source[$dc_id];       // RSS 2.0
        $dest["{$cat_id}@term"] = $source[$dc_id];  // Atom 1.0
    }

    // simplify the logic for normalize(). Makes sure that count of elements and
    // each of multiple elements is normalized properly. If you need to mess
    // with things like attributes or change formats or the like, pass it a
    // callback to handle each element.
    function normalize_element (&$source, $from, &$dest, $to, $via = NULL) {
        if (isset($source[$from]) or isset($source["{$from}#"])) {
            if (isset($source["{$from}#"])) {
                $n = $source["{$from}#"];
                $dest["{$to}#"] = $source["{$from}#"];
            }
            else { $n = 1; }

            for ($i = 1; $i <= $n; $i++) {
                if (isset($via)) { // custom callback for ninja attacks
                    $this->{$via}($source, $from, $dest, $to, $i);
                }
                else { // just make it the same
                    $from_id = $this->element_id($from, $i);
                    $to_id = $this->element_id($to, $i);
                    $dest[$to_id] = $source[$from_id];
                }
            }
        }
    }

    function normalize () {
        // if atom populate rss fields and normalize 0.3 and 1.0 feeds
        if ( $this->is_atom() ) {
		// Atom 1.0 elements <=> Atom 0.3 elements (Thanks, o brilliant wordsmiths of the Atom 1.0 standard!)
		if ($this->feed_version < 1.0) {
			$this->normalize_element($this->channel, 'tagline', $this->channel, 'subtitle');
			$this->normalize_element($this->channel, 'copyright', $this->channel, 'rights');
			$this->normalize_element($this->channel, 'modified', $this->channel, 'updated');
		} else {
			$this->normalize_element($this->channel, 'subtitle', $this->channel, 'tagline');
			$this->normalize_element($this->channel, 'rights', $this->channel, 'copyright');
			$this->normalize_element($this->channel, 'updated', $this->channel, 'modified');
		}
		$this->normalize_element($this->channel, 'author', $this->channel['dc'], 'creator', 'normalize_atom_person');
		$this->normalize_element($this->channel, 'contributor', $this->channel['dc'], 'contributor', 'normalize_atom_person');

		// Atom elements to RSS elements
		$this->normalize_element($this->channel, 'subtitle', $this->channel, 'description');
        
		if ( isset($this->channel['logo']) ) {
			$this->normalize_element($this->channel, 'logo', $this->image, 'url');
			$this->normalize_element($this->channel, 'link', $this->image, 'link');
			$this->normalize_element($this->channel, 'title', $this->image, 'title');
		}

		for ( $i = 0; $i < count($this->items); $i++) {
			$item = $this->items[$i];

			// Atom 1.0 elements <=> Atom 0.3 elements
			if ($this->feed_version < 1.0) {
				$this->normalize_element($item, 'modified', $item, 'updated');
				$this->normalize_element($item, 'issued', $item, 'published');
			} else {
				$this->normalize_element($item, 'updated', $item, 'modified');
				$this->normalize_element($item, 'published', $item, 'issued');
			}

			// "If an atom:entry element does not contain
			// atom:author elements, then the atom:author elements
			// of the contained atom:source element are considered
			// to apply. In an Atom Feed Document, the atom:author
			// elements of the containing atom:feed element are
			// considered to apply to the entry if there are no
			// atom:author elements in the locations described
			// above." <http://atompub.org/2005/08/17/draft-ietf-atompub-format-11.html#rfc.section.4.2.1>
			if (!isset($item["author#"])) {
				if (isset($item["source_author#"])) { // from aggregation source
					$source = $item;
					$author = "source_author";
				} elseif (isset($this->channel["author#"])) { // from containing feed
					$source = $this->channel;
					$author = "author";
				} else {
					$author = null;
				}

				if (!is_null($author)) {
					$item["author#"] = $source["{$author}#"];
					for ($au = 1; $au <= $item["author#"]; $au++) {
						$id_to = $this->element_id('author', $au);
						$id_from = $this->element_id($author, $au);
			    
						$item[$id_to] = $source[$id_from];
						foreach (array('name', 'email', 'uri', 'url') as $what) {
							if (isset($source["{$id_from}_{$what}"])) {
								$item["{$id_to}_{$what}"] = $source["{$id_from}_{$what}"];
							}
						}
					}
				}
			}

		    // Atom elements to RSS elements
		    $this->normalize_element($item, 'author', $item['dc'], 'creator', 'normalize_atom_person');
		    $this->normalize_element($item, 'contributor', $item['dc'], 'contributor', 'normalize_atom_person');
		    $this->normalize_element($item, 'summary', $item, 'description');
		    $this->normalize_element($item, 'atom_content', $item['content'], 'encoded');
		    $this->normalize_element($item, 'link_enclosure', $item, 'enclosure', 'normalize_enclosure');
	
		    // Categories
		    if ( isset($item['category#']) ) { // Atom 1.0 categories to dc:subject and RSS 2.0 categories
			$this->normalize_element($item, 'category', $item['dc'], 'subject', 'normalize_category');
		    }
		    elseif ( isset($item['dc']['subject#']) ) { // dc:subject to Atom 1.0 and RSS 2.0 categories
			$this->normalize_element($item['dc'], 'subject', $item, 'category', 'normalize_dc_subject');
		    }
	
		    // Normalized item timestamp
		    $atom_date = (isset($item['published']) ) ? $item['published'] : $item['updated'];
		    if ( $atom_date ) {
			$epoch = @parse_w3cdtf($atom_date);
			if ($epoch and $epoch > 0) {
			    $item['date_timestamp'] = $epoch;
			}
		    }
	
		    $this->items[$i] = $item;
		}
        }
        elseif ( $this->is_rss() ) {
		// RSS elements to Atom elements
		$this->normalize_element($this->channel, 'description', $this->channel, 'tagline'); // Atom 0.3
		$this->normalize_element($this->channel, 'description', $this->channel, 'subtitle'); // Atom 1.0 (yay wordsmithing!)
		$this->normalize_element($this->image, 'url', $this->channel, 'logo');

		for ( $i = 0; $i < count($this->items); $i++) {
			$item = $this->items[$i];
        
			// RSS elements to Atom elements
			$this->normalize_element($item, 'description', $item, 'summary');
			$this->normalize_element($item, 'enclosure', $item, 'link_enclosure', 'normalize_enclosure');
			
			// Categories
			if ( isset($item['category#']) ) { // RSS 2.0 categories to dc:subject and Atom 1.0 categories
			    $this->normalize_element($item, 'category', $item['dc'], 'subject', 'normalize_category');
			}
			elseif ( isset($item['dc']['subject#']) ) { // dc:subject to Atom 1.0 and RSS 2.0 categories
			    $this->normalize_element($item['dc'], 'subject', $item, 'category', 'normalize_dc_subject');
			}

			// Normalized item timestamp
			if ( $this->is_rss() == '1.0' and isset($item['dc']['date']) ) {
			    $epoch = @parse_w3cdtf($item['dc']['date']);
			    if ($epoch and $epoch > 0) {
				$item['date_timestamp'] = $epoch;
			    }
			}
			elseif ( isset($item['pubdate']) ) {
			    $epoch = @strtotime($item['pubdate']);
			    if ($epoch > 0) {
				$item['date_timestamp'] = $epoch;
			    }
			}
			
			$this->items[$i] = $item;
		}
        }
    }
    
    
    function is_rss () {
        if ( $this->feed_type == RSS ) {
            return $this->feed_version; 
        }
        else {
            return false;
        }
    }
    
    function is_atom() {
        if ( $this->feed_type == ATOM ) {
            return $this->feed_version;
        }
        else {
            return false;
        }
    }

    /**
    * return XML parser, and possibly re-encoded source
    *
    */
    function create_parser($source, $out_enc, $in_enc, $detect) {
        if ( substr(phpversion(),0,1) == 5) {
            $parser = $this->php5_create_parser($in_enc, $detect);
        }
        else {
            list($parser, $source) = $this->php4_create_parser($source, $in_enc, $detect);
        }
        if ($out_enc) {
            $this->encoding = $out_enc;
            xml_parser_set_option($parser, XML_OPTION_TARGET_ENCODING, $out_enc);
        }
	xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, false);
        return array($parser, $source);
    }
    
    /**
    * Instantiate an XML parser under PHP5
    *
    * PHP5 will do a fine job of detecting input encoding
    * if passed an empty string as the encoding. 
    *
    * All hail libxml2!
    *
    */
    function php5_create_parser($in_enc, $detect) {
        // by default php5 does a fine job of detecting input encodings
        if(!$detect && $in_enc) {
            return xml_parser_create($in_enc);
        }
        else {
            return xml_parser_create('');
        }
    }
    
    /**
    * Instaniate an XML parser under PHP4
    *
    * Unfortunately PHP4's support for character encodings
    * and especially XML and character encodings sucks.  As
    * long as the documents you parse only contain characters
    * from the ISO-8859-1 character set (a superset of ASCII,
    * and a subset of UTF-8) you're fine.  However once you
    * step out of that comfy little world things get mad, bad,
    * and dangerous to know.
    *
    * The following code is based on SJM's work with FoF
    * @see http://minutillo.com/steve/weblog/2004/6/17/php-xml-and-character-encodings-a-tale-of-sadness-rage-and-data-loss
    *
    */
    function php4_create_parser($source, $in_enc, $detect) {
        if ( !$detect ) {
            return array(xml_parser_create($in_enc), $source);
        }
        
        if (!$in_enc) {
            if (preg_match('/<?xml.*encoding=[\'"](.*?)[\'"].*?>/m', $source, $m)) {
                $in_enc = strtoupper($m[1]);
                $this->source_encoding = $in_enc;
            }
            else {
                $in_enc = 'UTF-8';
            }
        }
        
        if ($this->known_encoding($in_enc)) {
            return array(xml_parser_create($in_enc), $source);
        }
        
        // the dectected encoding is not one of the simple encodings PHP knows
        
        // attempt to use the iconv extension to
        // cast the XML to a known encoding
        // @see http://php.net/iconv
       
        if (function_exists('iconv'))  {
            $encoded_source = iconv($in_enc,'UTF-8', $source);
            if ($encoded_source) {
                return array(xml_parser_create('UTF-8'), $encoded_source);
            }
        }
        
        // iconv didn't work, try mb_convert_encoding
        // @see http://php.net/mbstring
        if(function_exists('mb_convert_encoding')) {
            $encoded_source = mb_convert_encoding($source, 'UTF-8', $in_enc );
            if ($encoded_source) {
                return array(xml_parser_create('UTF-8'), $encoded_source);
            }
        }
        
        // else 
        $this->error("Feed is in an unsupported character encoding. ($in_enc) " .
                     "You may see strange artifacts, and mangled characters.",
                     E_USER_NOTICE);
            
        return array(xml_parser_create(), $source);
    }
    
    function known_encoding($enc) {
        $enc = strtoupper($enc);
        if ( in_array($enc, $this->_KNOWN_ENCODINGS) ) {
            return $enc;
        }
        else {
            return false;
        }
    }

    function error ($errormsg, $lvl=E_USER_WARNING) {
        // append PHP's error message if track_errors enabled
        if ( isset($php_errormsg) ) { 
            $errormsg .= " ($php_errormsg)";
        }
        if ( MAGPIE_DEBUG ) {
            trigger_error( $errormsg, $lvl);        
        }
        else {
            error_log( $errormsg, 0);
        }
        
        $notices = E_USER_NOTICE|E_NOTICE;
        if ( $lvl&$notices ) {
            $this->WARNING = $errormsg;
        } else {
            $this->ERROR = $errormsg;
        }
    }

    // magic ID function for multiple elemenets.
    // can be called as static MagpieRSS::element_id()
    function element_id ($el, $counter) {
        return $el . (($counter > 1) ? '#'.$counter : '');
    }

    	function map_attrs($k, $v) {
	    return $k.'="'.htmlspecialchars($v, ENT_COMPAT, $this->encoding).'"';
	}
	
	function accepts_namespaced_xml ($attrs) {
		$mode = (isset($attrs['mode']) ? trim(strtolower($attrs['mode'])) : 'xml');
		$type = (isset($attrs['type']) ? trim(strtolower($attrs['type'])) : null);
		if ($this->feed_type == ATOM and $this->feed_version < 1.0) {
			if ($mode=='xml' and preg_match(':[/+](html|xml)$:i', $type)) {
				$ret = true;
			} else {
				$ret = false;
			}
		} elseif ($this->feed_type == ATOM and $this->feed_version >= 1.0) {
			if ($type=='xhtml' or preg_match(':[/+]xml$:i', $type)) {
				$ret = true;
			} else {
				$ret = false;
			}
		} else {
			$ret = false; // Don't munge unless you're sure
		}
		return $ret;
	}
} // end class RSS


// patch to support medieval versions of PHP4.1.x, 
// courtesy, Ryan Currie, ryan@digibliss.com

if (!function_exists('array_change_key_case')) {
    define("CASE_UPPER",1);
    define("CASE_LOWER",0);


    function array_change_key_case($array,$case=CASE_LOWER) {
       if ($case==CASE_LOWER) $cmd='strtolower';
       elseif ($case==CASE_UPPER) $cmd='strtoupper';
       foreach($array as $key=>$value) {
               $output[$cmd($key)]=$value;
       }
       return $output;
    }

}

################################################################################
## WordPress: Load in Snoopy from wp-includes ##################################
################################################################################

if (!function_exists('wp_remote_request')) :
	require_once( dirname(__FILE__) . '/class-snoopy.php');
endif;

################################################################################
## rss_fetch.inc: from MagpieRSS 0.8a ##########################################
################################################################################

/*=======================================================================*\
    Function: fetch_rss: 
    Purpose:  return RSS object for the give url
              maintain the cache
    Input:    url of RSS file
    Output:   parsed RSS object (see rss_parse.inc)

    NOTES ON CACHEING:  
    If caching is on (MAGPIE_CACHE_ON) fetch_rss will first check the cache.
    
    NOTES ON RETRIEVING REMOTE FILES:
    If conditional gets are on (MAGPIE_CONDITIONAL_GET_ON) fetch_rss will
    return a cached object, and touch the cache object upon recieving a
    304.
    
    NOTES ON FAILED REQUESTS:
    If there is an HTTP error while fetching an RSS object, the cached
    version will be return, if it exists (and if MAGPIE_CACHE_FRESH_ONLY is off)
\*=======================================================================*/

define('MAGPIE_VERSION', '2010.0122');

$MAGPIE_ERROR = "";

function fetch_rss ($url) {
    // initialize constants
    init();
    
    if ( !isset($url) ) {
        error("fetch_rss called without a url");
        return false;
    }
    
    // if cache is disabled
    if ( !MAGPIE_CACHE_ON ) {
        // fetch file, and parse it
        $resp = _fetch_remote_file( $url );
        if ( is_success( $resp->status ) ) {
            return _response_to_rss( $resp, $url );
        }
        else {
            error("Failed to fetch $url and cache is off");
            return false;
        }
    } 
    // else cache is ON
    else {
        // Flow
        // 1. check cache
        // 2. if there is a hit, make sure its fresh
        // 3. if cached obj fails freshness check, fetch remote
        // 4. if remote fails, return stale object, or error
        
        $cache = new RSSCache( MAGPIE_CACHE_DIR, MAGPIE_CACHE_AGE );
        
        if (MAGPIE_DEBUG and $cache->ERROR) {
            debug($cache->ERROR, E_USER_WARNING);
        }
        
        
        $cache_status    = 0;       // response of check_cache
        $request_headers = array(); // HTTP headers to send with fetch
        $rss             = 0;       // parsed RSS object
        $errormsg        = 0;       // errors, if any
        
        // store parsed XML by desired output encoding
        // as character munging happens at parse time
        $cache_key       = $url . MAGPIE_OUTPUT_ENCODING;
        
        if (!$cache->ERROR) {
            // return cache HIT, MISS, or STALE
            $cache_status = $cache->check_cache( $cache_key);
        }
        
        // if object cached, and cache is fresh, return cached obj
        if ( $cache_status == 'HIT' ) {
            $rss = $cache->get( $cache_key );
            if ( isset($rss) and $rss ) {
                // should be cache age
                $rss->from_cache = 1;
                if ( MAGPIE_DEBUG > 1) {
                debug("MagpieRSS: Cache HIT", E_USER_NOTICE);
            }
                return $rss;
            }
        }
        
        // else attempt a conditional get
        
        // setup headers
        if ( $cache_status == 'STALE' ) {
            $rss = $cache->get( $cache_key );
            if ( $rss and isset($rss->etag) and $rss->last_modified ) {
                $request_headers['If-None-Match'] = $rss->etag;
                $request_headers['If-Last-Modified'] = $rss->last_modified;
            }
        }
        
        $resp = _fetch_remote_file( $url, $request_headers );
        
        if (isset($resp) and $resp) {
            if ($resp->status == '304' ) {
                // we have the most current copy
                if ( MAGPIE_DEBUG > 1) {
                    debug("Got 304 for $url");
                }
                // reset cache on 304 (at minutillo insistent prodding)
                $cache->set($cache_key, $rss);
                return $rss;
            }
            elseif ( is_success( $resp->status ) ) {
                $rss = _response_to_rss( $resp, $url );
                if ( $rss ) {
                    if (MAGPIE_DEBUG > 1) {
                        debug("Fetch successful");
                    }
                    // add object to cache
                    $cache->set( $cache_key, $rss );
                    return $rss;
                }
            }
            else {
                $errormsg = "Failed to fetch $url ";
                if ( $resp->status == '-100' ) {
                    $errormsg .= "(Request timed out after " . MAGPIE_FETCH_TIME_OUT . " seconds)";
                }
                elseif ( $resp->error ) {
                    # compensate for Snoopy's annoying habbit to tacking
                    # on '\n'
                    $http_error = substr($resp->error, 0, -2); 
                    $errormsg .= "(HTTP Error: $http_error)";
                }
                else {
                    $errormsg .=  "(HTTP Response: " . $resp->response_code .')';
                }
            }
        }
        else {
            $errormsg = "Unable to retrieve RSS file for unknown reasons.";
        }
        
        // else fetch failed
        debug("MagpieRSS fetch failed [$errormsg]");

        // attempt to return cached object
        if ($rss) {
            if ( MAGPIE_DEBUG ) {
                debug("Returning STALE object for $url");
            }
            return $rss;
        }
        
        // else we totally failed
        error( $errormsg ); 
        
        return false;
        
    } // end if ( !MAGPIE_CACHE_ON ) {
} // end fetch_rss()

/*=======================================================================*\
    Function:   error
    Purpose:    set MAGPIE_ERROR, and trigger error
\*=======================================================================*/

function error ($errormsg, $lvl=E_USER_WARNING) {
        global $MAGPIE_ERROR;
        
        // append PHP's error message if track_errors enabled
        if ( isset($php_errormsg) ) { 
            $errormsg .= " ($php_errormsg)";
        }
        if ( $errormsg ) {
            $errormsg = "MagpieRSS: $errormsg";
            $MAGPIE_ERROR = $errormsg;
	    if ( MAGPIE_DEBUG ) {
		    trigger_error( $errormsg, $lvl);
	    } else {
		    error_log($errormsg, 0);
	    }
        }
}

function debug ($debugmsg, $lvl=E_USER_NOTICE) {
    trigger_error("MagpieRSS [debug] $debugmsg", $lvl);
}
            
/*=======================================================================*\
    Function:   magpie_error
    Purpose:    accessor for the magpie error variable
\*=======================================================================*/
function magpie_error ($errormsg="") {
    global $MAGPIE_ERROR;
    
    if ( isset($errormsg) and $errormsg ) { 
        $MAGPIE_ERROR = $errormsg;
    }
    
    return $MAGPIE_ERROR;   
}

/*=======================================================================*\
    Function:   _fetch_remote_file
    Purpose:    retrieve an arbitrary remote file
    Input:      url of the remote file
                headers to send along with the request (optional)
    Output:     an HTTP response object (see Snoopy.class.inc)  
\*=======================================================================*/
function _fetch_remote_file ($url, $headers = "" ) {
	// Ensure that we have constants set up, since they are used below.
	init();

	// WordPress 2.7 has deprecated Snoopy. It's still there, for now, but
	// I'd rather not rely on it.
	if (function_exists('wp_remote_request')) :
		$resp = wp_remote_request($url, array(
			'headers' => $headers,
			'timeout' => MAGPIE_FETCH_TIME_OUT
		));

		if ( is_wp_error($resp) ) :
			$error = $resp->get_error_messages();

			$client = new stdClass;
			$client->status = 500;
			$client->response_code = 500;
			$client->error = implode(" / ", $error). "\n"; //\n = Snoopy compatibility
		else :
			$client = new stdClass;
			$client->status = $resp['response']['code'];
			$client->response_code = $resp['response']['code'];
			$client->headers = $resp['headers'];
			$client->results = $resp['body'];
		endif;
	else :
		// Snoopy is an HTTP client in PHP
		$client = new Snoopy();
		$client->agent = MAGPIE_USER_AGENT;
		$client->read_timeout = MAGPIE_FETCH_TIME_OUT;
		$client->use_gzip = MAGPIE_USE_GZIP;
		if (is_array($headers) ) {
			$client->rawheaders = $headers;
		}
		@$client->fetch($url);
	endif;
	return $client;
}

/*=======================================================================*\
    Function:   _response_to_rss
    Purpose:    parse an HTTP response object into an RSS object
    Input:      an HTTP response object (see Snoopy)
    Output:     parsed RSS object (see rss_parse)
\*=======================================================================*/
function _response_to_rss ($resp, $url = null) {
	$rss = new MagpieRSS( $resp->results, MAGPIE_OUTPUT_ENCODING, MAGPIE_INPUT_ENCODING, MAGPIE_DETECT_ENCODING, $url );

	// if RSS parsed successfully       
	if ( $rss and !$rss->ERROR) {
		$rss->http_status = $resp->status;

		// find Etag, and Last-Modified
		foreach($resp->headers as $index => $h) {
			if (is_string($index)) :
				$field = $index;
				$val = $h;
			elseif (strpos($h, ": ")) :
				list($field, $val) = explode(": ", $h, 2);
			else :
				$field = $h; $val = '';
			endif;

			$rss->header[$field] = $val;

			if ( $field == 'ETag' ) :
				$rss->etag = $val;
			elseif ( $field == 'Last-Modified' ) :
				$rss->last_modified = $val;
			endif;
		}

		return $rss;    
	} // else construct error message
	else {
		$errormsg = "Failed to parse RSS file.";

		if ($rss) {
			$errormsg .= " (" . $rss->ERROR . ")";
		}
		error($errormsg);
		
		return false;
	} // end if ($rss and !$rss->error)
}

/*=======================================================================*\
    Function:   init
    Purpose:    setup constants with default values
                check for user overrides
\*=======================================================================*/
function init () {
    if ( defined('MAGPIE_INITALIZED') ) {
        return;
    }
    else {
        define('MAGPIE_INITALIZED', true);
    }
    
    if ( !defined('MAGPIE_CACHE_ON') ) {
        define('MAGPIE_CACHE_ON', true);
    }

    if ( !defined('MAGPIE_CACHE_DIR') ) {
        define('MAGPIE_CACHE_DIR', './cache');
    }

    if ( !defined('MAGPIE_CACHE_AGE') ) {
        define('MAGPIE_CACHE_AGE', 60*60); // one hour
    }

    if ( !defined('MAGPIE_CACHE_FRESH_ONLY') ) {
        define('MAGPIE_CACHE_FRESH_ONLY', false);
    }

    if ( !defined('MAGPIE_OUTPUT_ENCODING') ) {
        define('MAGPIE_OUTPUT_ENCODING', 'ISO-8859-1');
    }
    
    if ( !defined('MAGPIE_INPUT_ENCODING') ) {
        define('MAGPIE_INPUT_ENCODING', null);
    }
    
    if ( !defined('MAGPIE_DETECT_ENCODING') ) {
        define('MAGPIE_DETECT_ENCODING', true);
    }
    
    if ( !defined('MAGPIE_DEBUG') ) {
        define('MAGPIE_DEBUG', 0);
    }
    
    if ( !defined('MAGPIE_USER_AGENT') ) {
        $ua = 'MagpieRSS/'. MAGPIE_VERSION . ' (+http://magpierss.sf.net';
        
        if ( MAGPIE_CACHE_ON ) {
            $ua = $ua . ')';
        }
        else {
            $ua = $ua . '; No cache)';
        }
        
        define('MAGPIE_USER_AGENT', $ua);
    }
    
    if ( !defined('MAGPIE_FETCH_TIME_OUT') ) {
        define('MAGPIE_FETCH_TIME_OUT', 5); // 5 second timeout
    }
    
    // use gzip encoding to fetch rss files if supported?
    if ( !defined('MAGPIE_USE_GZIP') ) {
        define('MAGPIE_USE_GZIP', true);    
    }
}

// NOTE: the following code should really be in Snoopy, or at least
// somewhere other then rss_fetch!

/*=======================================================================*\
    HTTP STATUS CODE PREDICATES
    These functions attempt to classify an HTTP status code
    based on RFC 2616 and RFC 2518.
    
    All of them take an HTTP status code as input, and return true or false

    All this code is adapted from LWP's HTTP::Status.
\*=======================================================================*/


/*=======================================================================*\
    Function:   is_info
    Purpose:    return true if Informational status code
\*=======================================================================*/
function is_info ($sc) { 
    return $sc >= 100 && $sc < 200; 
}

/*=======================================================================*\
    Function:   is_success
    Purpose:    return true if Successful status code
\*=======================================================================*/
function is_success ($sc) { 
    return $sc >= 200 && $sc < 300; 
}

/*=======================================================================*\
    Function:   is_redirect
    Purpose:    return true if Redirection status code
\*=======================================================================*/
function is_redirect ($sc) { 
    return $sc >= 300 && $sc < 400; 
}

/*=======================================================================*\
    Function:   is_error
    Purpose:    return true if Error status code
\*=======================================================================*/
function is_error ($sc) { 
    return $sc >= 400 && $sc < 600; 
}

/*=======================================================================*\
    Function:   is_client_error
    Purpose:    return true if Error status code, and its a client error
\*=======================================================================*/
function is_client_error ($sc) { 
    return $sc >= 400 && $sc < 500; 
}

/*=======================================================================*\
    Function:   is_client_error
    Purpose:    return true if Error status code, and its a server error
\*=======================================================================*/
function is_server_error ($sc) { 
    return $sc >= 500 && $sc < 600; 
}

################################################################################
## rss_cache.inc: from WordPress 1.5 ###########################################
################################################################################

class RSSCache {
	var $BASE_CACHE = 'wp-content/cache';	// where the cache files are stored
	var $MAX_AGE	= 43200;  		// when are files stale, default twelve hours
	var $ERROR 		= '';			// accumulate error messages
	
	function RSSCache ($base='', $age='') {
		if ( $base ) {
			$this->BASE_CACHE = $base;
		}
		if ( $age ) {
			$this->MAX_AGE = $age;
		}
	
	}
	
/*=======================================================================*\
	Function:	set
	Purpose:	add an item to the cache, keyed on url
	Input:		url from wich the rss file was fetched
	Output:		true on sucess	
\*=======================================================================*/
	function set ($url, $rss) {
		global $wpdb;
		$cache_option = 'rss_' . $this->file_name( $url );
		$cache_timestamp = 'rss_' . $this->file_name( $url ) . '_ts';
		
		if ( !$wpdb->get_var("SELECT option_name FROM $wpdb->options WHERE option_name = '$cache_option'") )
			add_option($cache_option, '', '', 'no');
		if ( !$wpdb->get_var("SELECT option_name FROM $wpdb->options WHERE option_name = '$cache_timestamp'") )
			add_option($cache_timestamp, '', '', 'no');
		
		update_option($cache_option, $rss);
		update_option($cache_timestamp, time() );
		
		return $cache_option;
	}
	
/*=======================================================================*\
	Function:	get
	Purpose:	fetch an item from the cache
	Input:		url from wich the rss file was fetched
	Output:		cached object on HIT, false on MISS	
\*=======================================================================*/	
	function get ($url) {
		$this->ERROR = "";
		$cache_option = 'rss_' . $this->file_name( $url );
		
		if ( ! get_option( $cache_option ) ) {
			$this->debug( 
				"Cache doesn't contain: $url (cache option: $cache_option)"
			);
			return 0;
		}
		
		$rss = get_option( $cache_option );
		
		// failsafe; seems to break at odd points in WP MU
		if (is_string($rss)) {
			$rss = $this->unserialize($rss);	
		}
		
		return $rss;
	}

/*=======================================================================*\
	Function:	check_cache
	Purpose:	check a url for membership in the cache
				and whether the object is older then MAX_AGE (ie. STALE)
	Input:		url from wich the rss file was fetched
	Output:		cached object on HIT, false on MISS	
\*=======================================================================*/		
	function check_cache ( $url ) {
		$this->ERROR = "";
		$cache_option = $this->file_name( $url );
		$cache_timestamp = 'rss_' . $this->file_name( $url ) . '_ts';

		if ( $mtime = get_option($cache_timestamp) ) {
			// find how long ago the file was added to the cache
			// and whether that is longer then MAX_AGE
			$age = time() - $mtime;
			if ( $this->MAX_AGE > $age ) {
				// object exists and is current
				return 'HIT';
			}
			else {
				// object exists but is old
				return 'STALE';
			}
		}
		else {
			// object does not exist
			return 'MISS';
		}
	}

/*=======================================================================*\
	Function:	serialize
\*=======================================================================*/		
	function serialize ( $rss ) {
		return serialize( $rss );
	}

/*=======================================================================*\
	Function:	unserialize
\*=======================================================================*/		
	function unserialize ( $data ) {
		return unserialize( $data );
	}
	
/*=======================================================================*\
	Function:	file_name
	Purpose:	map url to location in cache
	Input:		url from wich the rss file was fetched
	Output:		a file name
\*=======================================================================*/		
	function file_name ($url) {
		return md5( $url );
	}
	
/*=======================================================================*\
	Function:	error
	Purpose:	register error
\*=======================================================================*/			
	function error ($errormsg, $lvl=E_USER_WARNING) {
		// append PHP's error message if track_errors enabled
		if ( isset($php_errormsg) ) { 
			$errormsg .= " ($php_errormsg)";
		}
		$this->ERROR = $errormsg;
		if ( MAGPIE_DEBUG ) {
			trigger_error( $errormsg, $lvl);
		}
		else {
			error_log( $errormsg, 0);
		}
	}
			function debug ($debugmsg, $lvl=E_USER_NOTICE) {
		if ( MAGPIE_DEBUG ) {
			$this->error("MagpieRSS [debug] $debugmsg", $lvl);
		}
	}
}

################################################################################
## rss_utils.inc: from MagpieRSS 0.8a ##########################################
################################################################################

/*======================================================================*\
    Function: parse_w3cdtf
    Purpose:  parse a W3CDTF date into unix epoch

    NOTE: http://www.w3.org/TR/NOTE-datetime
\*======================================================================*/

function parse_w3cdtf ( $date_str ) {
    
    # regex to match wc3dtf
    $pat = "/^\s*(\d{4})(-(\d{2})(-(\d{2})(T(\d{2}):(\d{2})(:(\d{2})(\.\d+)?)?(?:([-+])(\d{2}):?(\d{2})|(Z))?)?)?)?\s*\$/";
    
    if ( preg_match( $pat, $date_str, $match ) ) {
        list( $year, $month, $day, $hours, $minutes, $seconds) = 
            array( $match[1], $match[3], $match[5], $match[7], $match[8], $match[10]);

        # W3C dates can omit the time, the day of the month, or even the month.
	# Fill in any blanks using information from the present moment. --CWJ
	$default['hr'] = (int) gmdate('H');
	$default['day'] = (int) gmdate('d');
	$default['month'] = (int) gmdate('m');

	if (is_null($hours)) : $hours = $default['hr']; $minutes = 0; $seconds = 0; endif;
	if (is_null($day)) : $day = $default['day']; endif;
	if (is_null($month)) : $month = $default['month']; endif;

        # calc epoch for current date assuming GMT
        $epoch = gmmktime( $hours, $minutes, $seconds, $month, $day, $year);
        
        $offset = 0;
        if ( $match[15] == 'Z' ) {
            # zulu time, aka GMT
        }
        else {
            list( $tz_mod, $tz_hour, $tz_min ) =
                array( $match[12], $match[13], $match[14]);
            
            # zero out the variables
            if ( ! $tz_hour ) { $tz_hour = 0; }
            if ( ! $tz_min ) { $tz_min = 0; }
        
            $offset_secs = (($tz_hour*60)+$tz_min)*60;
            
            # is timezone ahead of GMT?  then subtract offset
            #
            if ( $tz_mod == '+' ) {
                $offset_secs = $offset_secs * -1;
            }
            
            $offset = $offset_secs; 
        }
        $epoch = $epoch + $offset;
        return $epoch;
    }
    else {
        return -1;
    }
}

# Relative URI static class: PHP class for resolving relative URLs
#
# This class is derived (under the terms of the GPL) from URL Class 0.3 by
# Keyvan Minoukadeh <keyvan@k1m.com>, which is great but more than we need
# for MagpieRSS's purposes. The class has been stripped down to a single
# public method: Relative_URI::resolve($url, $base), which resolves the URI in
# $url relative to the URI in $base
#
# FeedWordPress also uses this class. So if we have it loaded in, don't load it
# again.
#
# -- Charles Johnson <technophilia@radgeek.com>
if (!class_exists('Relative_URI')) {
	class Relative_URI
	{
		// Resolve relative URI in $url against the base URI in $base. If $base
		// is not supplied, then we use the REQUEST_URI of this script.
		//
		// I'm hoping this method reflects RFC 2396 Section 5.2
		function resolve ($url, $base = NULL)
		{
			if (is_null($base)):
				$base = 'http://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
			endif;
	
			$base = Relative_URI::_encode(trim($base));
			$uri_parts = Relative_URI::_parse_url($base);
	
			$url = Relative_URI::_encode(trim($url));
			$parts = Relative_URI::_parse_url($url);
	
			$uri_parts['fragment'] = (isset($parts['fragment']) ? $parts['fragment'] : null);
			$uri_parts['query'] = (isset($parts['query']) ? $parts['query'] : null);
	
			// if path is empty, and scheme, host, and query are undefined,
			// the URL is referring the base URL
			
			if (($parts['path'] == '') && !isset($parts['scheme']) && !isset($parts['host']) && !isset($parts['query'])) {
				// If the URI is empty or only a fragment, return the base URI
				return $base . (isset($parts['fragment']) ? '#'.$parts['fragment'] : '');
			} elseif (isset($parts['scheme'])) {
				// If the scheme is set, then the URI is absolute.
				return $url;
			} elseif (isset($parts['host'])) {
				$uri_parts['host'] = $parts['host'];
				$uri_parts['path'] = $parts['path'];
			} else {
				// We have a relative path but not a host.
	
				// start ugly fix:
				// prepend slash to path if base host is set, base path is not set, and url path is not absolute
				if ($uri_parts['host'] && ($uri_parts['path'] == '')
				&& (strlen($parts['path']) > 0)
				&& (substr($parts['path'], 0, 1) != '/')) {
					$parts['path'] = '/'.$parts['path'];
				} // end ugly fix
				
				if (substr($parts['path'], 0, 1) == '/') {
					$uri_parts['path'] = $parts['path'];
				} else {
					// copy base path excluding any characters after the last (right-most) slash character
					$buffer = substr($uri_parts['path'], 0, (int)strrpos($uri_parts['path'], '/')+1);
					// append relative path
					$buffer .= $parts['path'];
					// remove "./" where "." is a complete path segment.
					$buffer = str_replace('/./', '/', $buffer);
					if (substr($buffer, 0, 2) == './') {
					    $buffer = substr($buffer, 2);
					}
					// if buffer ends with "." as a complete path segment, remove it
					if (substr($buffer, -2) == '/.') {
					    $buffer = substr($buffer, 0, -1);
					}
					// remove "<segment>/../" where <segment> is a complete path segment not equal to ".."
					$search_finished = false;
					$segment = explode('/', $buffer);
					while (!$search_finished) {
					    for ($x=0; $x+1 < count($segment);) {
						if (($segment[$x] != '') && ($segment[$x] != '..') && ($segment[$x+1] == '..')) {
						    if ($x+2 == count($segment)) $segment[] = '';
						    unset($segment[$x], $segment[$x+1]);
						    $segment = array_values($segment);
						    continue 2;
						} else {
						    $x++;
						}
					    }
					    $search_finished = true;
					}
					$buffer = (count($segment) == 1) ? '/' : implode('/', $segment);
					$uri_parts['path'] = $buffer;
	
				}
			}
	
			// If we've gotten to this point, we can try to put the pieces
			// back together.
			$ret = '';
			if (isset($uri_parts['scheme'])) $ret .= $uri_parts['scheme'].':';
			if (isset($uri_parts['user'])) {
				$ret .= $uri_parts['user'];
				if (isset($uri_parts['pass'])) $ret .= ':'.$uri_parts['parts'];
				$ret .= '@';
			}
			if (isset($uri_parts['host'])) {
				$ret .= '//'.$uri_parts['host'];
				if (isset($uri_parts['port'])) $ret .= ':'.$uri_parts['port'];
			}
			$ret .= $uri_parts['path'];
			if (isset($uri_parts['query'])) $ret .= '?'.$uri_parts['query'];
			if (isset($uri_parts['fragment'])) $ret .= '#'.$uri_parts['fragment'];
	
			return $ret;
	    }
	
	    /**
	    * Parse URL
	    *
	    * Regular expression grabbed from RFC 2396 Appendix B. 
	    * This is a replacement for PHPs builtin parse_url().
	    * @param string $url
	    * @access private
	    * @return array
	    */
	    function _parse_url($url)
	    {
		// I'm using this pattern instead of parse_url() as there's a few strings where parse_url() 
		// generates a warning.
		if (preg_match('!^(([^:/?#]+):)?(//([^/?#]*))?([^?#]*)(\?([^#]*))?(#(.*))?!', $url, $match)) {
		    $parts = array();
		    if ($match[1] != '') $parts['scheme'] = $match[2];
		    if ($match[3] != '') $parts['auth'] = $match[4];
		    // parse auth
		    if (isset($parts['auth'])) {
			// store user info
			if (($at_pos = strpos($parts['auth'], '@')) !== false) {
			    $userinfo = explode(':', substr($parts['auth'], 0, $at_pos), 2);
			    $parts['user'] = $userinfo[0];
			    if (isset($userinfo[1])) $parts['pass'] = $userinfo[1];
			    $parts['auth'] = substr($parts['auth'], $at_pos+1);
			}
			// get port number
			if ($port_pos = strrpos($parts['auth'], ':')) {
			    $parts['host'] = substr($parts['auth'], 0, $port_pos);
			    $parts['port'] = (int)substr($parts['auth'], $port_pos+1);
			    if ($parts['port'] < 1) $parts['port'] = null;
			} else {
			    $parts['host'] = $parts['auth'];
			}
		    }
		    unset($parts['auth']);
		    $parts['path'] = $match[5];
		    if (isset($match[6]) && ($match[6] != '')) $parts['query'] = $match[7];
		    if (isset($match[8]) && ($match[8] != '')) $parts['fragment'] = $match[9];
		    return $parts;
		}
		// shouldn't reach here
		return array('path'=>'');
	    }
	
	    function _encode($string)
	    {
		static $replace = array();
		if (!count($replace)) {
		    $find = array(32, 34, 60, 62, 123, 124, 125, 91, 92, 93, 94, 96, 127);
		    $find = array_merge(range(0, 31), $find);
		    $find = array_map('chr', $find);
		    foreach ($find as $char) {
			$replace[$char] = '%'.bin2hex($char);
		    }
		}
		// escape control characters and a few other characters
		$encoded = strtr($string, $replace);
		// remove any character outside the hex range: 21 - 7E (see www.asciitable.com)
		return preg_replace('/[^\x21-\x7e]/', '', $encoded);
	    }
	} // class Relative_URI
}

################################################################################
## WordPress: wp_rss(), get_rss() ##############################################
################################################################################

function wp_rss ($url, $num) {
	//ini_set("display_errors", false); uncomment to suppress php errors thrown if the feed is not returned.
	$num_items = $num;
	$rss = fetch_rss($url);
		if ( $rss ) {
			echo "<ul>";
			$rss->items = array_slice($rss->items, 0, $num_items);
				foreach ($rss->items as $item ) {
					echo "<li>\n";
					echo "<a href='$item[link]' title='$item[description]'>";
					echo htmlentities($item['title']);
					echo "</a><br />\n";
					echo "</li>\n";
				}		
			echo "</ul>";
	}
		else {
			echo "an error has occured the feed is probably down, try again later.";
	}
}

function get_rss ($uri, $num = 5) { // Like get posts, but for RSS
	$rss = fetch_rss($url);
	if ( $rss ) {
		$rss->items = array_slice($rss->items, 0, $num_items);
		foreach ($rss->items as $item ) {
			echo "<li>\n";
			echo "<a href='$item[link]' title='$item[description]'>";
			echo htmlentities($item['title']);
			echo "</a><br />\n";
			echo "</li>\n";
		}
		return $posts;
	} else {
		return false;
	}
}
?>