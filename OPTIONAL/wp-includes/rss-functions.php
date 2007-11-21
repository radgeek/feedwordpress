<?php
/* Project:	MagpieRSS: a simple RSS integration tool
 * File:	A compiled file for RSS syndication
 * Author:	Kellan Elliot-McCrea <kellan@protest.net>
 *		WordPress development team <http://www.wordpress.org/>
 *		Charles Johnson <technophilia@radgeek.com>
 * Version:	0.7wp
 * License:	GPL
 *
 * Provenance:
 *
 * This is a drop-in replacement for the `rss-functions.php` provided with the
 * WordPress 1.5 distribution, which upgrades the version of MagpieRSS from 0.51
 * to a modification of 0.7. In addition to improved handling of character
 * encoding and other updates, this branch of MagpieRSS 0.7 also supports
 * multiple categorization of posts (using <dc:subject> or <category>). The
 * file is, therefore, derived from four sources: (1) Kellan's MagpieRSS 0.51,
 * (2) the WordPress development team's modifications to MagpieRSS 0.51,
 * (3) Kellan's MagpieRSS 0.7, and (4) Charles Johnson's modifications to
 * MagpieRSS 0.7. All possible because of the GPL. Yay for free software!
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
 * 5.	New cases added to MagpieRSS::feed_start_element(),
 * 	MagpieRSS::feed_end_element(), and MagpieRSS::normalize() to handle
 *	multiple categories correctly.
 */

define('RSS', 'RSS');
define('ATOM', 'Atom');
define('MAGPIE_USER_AGENT', 'WordPress/' . $wp_version);

# UPDATED: rss_parse.inc: class MagpieRSS, function map_attrs
# --- cut here ---
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
    
    var $_CONTENT_CONSTRUCTS = array('content', 'summary', 'info', 'title', 'tagline', 'copyright');
    var $_KNOWN_ENCODINGS    = array('UTF-8', 'US-ASCII', 'ISO-8859-1');

    // parser variables, useless if you're not a parser, treat as private
    var $stack              = array(); // parser stack
    var $inchannel          = false;
    var $initem             = false;
    var $incontent          = false; // if in Atom <content mode="xml"> field 
    var $intextinput        = false;
    var $inimage            = false;
    var $current_namespace  = false;
    
    var $incategory         = false;
    var $current_category   = 0;

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
                        $input_encoding=null, $detect_encoding=true) 
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
    
    function feed_start_element($p, $element, &$attrs) {
        $el = $element = strtolower($element);
        $attrs = array_change_key_case($attrs, CASE_LOWER);
        
        // check for a namespace, and split if found
        $ns = false;
        if ( strpos( $element, ':' ) ) {
            list($ns, $el) = split( ':', $element, 2); 
        }
        if ( $ns and $ns != 'rdf' ) {
            $this->current_namespace = $ns;
        }
            
        # if feed type isn't set, then this is first element of feed
        # identify feed from root element
        #
        if (!isset($this->feed_type) ) {
            if ( $el == 'rdf' ) {
                $this->feed_type = RSS;
                $this->feed_version = '1.0';
            }
            elseif ( $el == 'rss' ) {
                $this->feed_type = RSS;
                $this->feed_version = $attrs['version'];
            }
            elseif ( $el == 'feed' ) {
                $this->feed_type = ATOM;
                $this->feed_version = $attrs['version'];
                $this->inchannel = true;
            }
            return;
        }
    
        if ( $el == 'channel' ) 
        {
            $this->inchannel = true;
        }
        elseif ($el == 'item' or $el == 'entry' ) 
        {
            $this->initem = true;
            if ( isset($attrs['rdf:about']) ) {
                $this->current_item['about'] = $attrs['rdf:about']; 
            }
        }
        
	elseif ($this->initem and ($el == 'category' or ($this->current_namespace == 'dc' and $el == 'subject'))) {
            $this->incategory = true;
            array_unshift( $this->stack, $el );
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
        
        # handle atom content constructs
        elseif ( $this->feed_type == ATOM and in_array($el, $this->_CONTENT_CONSTRUCTS) )
        {
            // avoid clashing w/ RSS mod_content
            if ($el == 'content' ) {
                $el = 'atom_content';
            }
            
            $this->incontent = $el;
            
            
        }

        // if inside an Atom content construct (e.g. content or summary) field treat tags as text
        elseif ($this->feed_type == ATOM and $this->incontent ) 
        {
            // if tags are inlined, then flatten
            $attrs_str = join(' ', 
                    array_map('map_attrs', 
                    array_keys($attrs), 
                    array_values($attrs) ) );
            
            $this->append_content( "<$element $attrs_str>"  );
                    
            array_unshift( $this->stack, $el );
        }
        
        // Atom support many links per containging element.
        // Magpie treats link elements of type rel='alternate'
        // as being equivalent to RSS's simple link element.
        //
        elseif ($this->feed_type == ATOM and $el == 'link' ) 
        {
            if ( isset($attrs['rel']) and $attrs['rel'] == 'alternate' ) 
            {
                $link_el = 'link';
            }
            else {
                $link_el = 'link_' . $attrs['rel'];
            }
            
            $this->append($link_el, $attrs['href']);
        }

        // set stack[0] to current element
        else {
            array_unshift($this->stack, $el);
        }
    }
    

    
    function feed_cdata ($p, $text) {
        if ($this->feed_type == ATOM and $this->incontent) 
        {
            $this->append_content( $text );
        }
        else {
            $current_el = join('_', array_reverse($this->stack));
            $this->append($current_el, $text);
        }
    }
    
    function feed_end_element ($p, $el) {
        $el = strtolower($el);

        if ( $el == 'item' or $el == 'entry' ) 
        {
            $this->items[] = $this->current_item;
            $this->current_item = array();
            $this->initem = false;

	    $this->current_category = 0;
        }
	elseif ($this->initem and ($el == 'category' or $el == 'dc:subject')) {
            $this->incategory = false;
            $this->current_category = $this->current_category + 1;
            array_shift( $this->stack );
	}
        elseif ($this->feed_type == RSS and $this->current_namespace == '' and $el == 'textinput' ) 
        {
            $this->intextinput = false;
        }
        elseif ($this->feed_type == RSS and $this->current_namespace == '' and $el == 'image' ) 
        {
            $this->inimage = false;
        }
        elseif ($this->feed_type == ATOM and in_array($el, $this->_CONTENT_CONSTRUCTS) )
        {   
            $this->incontent = false;
        }
        elseif ($el == 'channel' or $el == 'feed' ) 
        {
            $this->inchannel = false;
        }
        elseif ($this->feed_type == ATOM and $this->incontent  ) {
            // balance tags properly
            // note:  i don't think this is actually neccessary
            if ( $this->stack[0] == $el ) 
            {
                $this->append_content("</$el>");
            }
            else {
                $this->append_content("<$el />");
            }

            array_shift( $this->stack );
        }
        else {
            array_shift( $this->stack );
        }
        
        $this->current_namespace = false;
    }
    
    function concat (&$str1, $str2="") {
        if (!isset($str1) ) {
            $str1="";
        }
        $str1 .= $str2;
    }
    
    
    
    function append_content($text) {
        if ( $this->initem ) {
            $this->concat( $this->current_item[ $this->incontent ], $text );
        }
        elseif ( $this->inchannel ) {
            $this->concat( $this->channel[ $this->incontent ], $text );
        }
    }
    
    // smart append - field and namespace aware
    function append($el, $text) {
        if (!$el) {
            return;
        }
        if ( $this->current_namespace ) 
        {
            if ( $this->incategory ) {
                $this->concat( $this->current_item['categories'][$this->current_category], $text );
            }
            elseif ( $this->initem ) {
                $this->concat(
                    $this->current_item[ $this->current_namespace ][ $el ], $text );
            }
            elseif ($this->inchannel) {
                $this->concat(
                    $this->channel[ $this->current_namespace][ $el ], $text );
            }
            elseif ($this->intextinput) {
                $this->concat(
                    $this->textinput[ $this->current_namespace][ $el ], $text );
            }
            elseif ($this->inimage) {
                $this->concat(
                    $this->image[ $this->current_namespace ][ $el ], $text );
            }
        }
        else {
            if ( $this->incategory ) {
                $this->concat( $this->current_item['categories'][$this->current_category], $text );
            }
            elseif ( $this->initem ) {
                $this->concat(
                    $this->current_item[ $el ], $text);
            }
            elseif ($this->intextinput) {
                $this->concat(
                    $this->textinput[ $el ], $text );
            }
            elseif ($this->inimage) {
                $this->concat(
                    $this->image[ $el ], $text );
            }
            elseif ($this->inchannel) {
                $this->concat(
                    $this->channel[ $el ], $text );
            }
            
        }
    }
    
    function normalize () {
        // if atom populate rss fields
        if ( $this->is_atom() ) {
            $this->channel['description'] = $this->channel['tagline'];
            for ( $i = 0; $i < count($this->items); $i++) {
                $item = $this->items[$i];
                if ( isset($item['summary']) )
                    $item['description'] = $item['summary'];
                if ( isset($item['atom_content']))
                    $item['content']['encoded'] = $item['atom_content'];
                
                $atom_date = (isset($item['issued']) ) ? $item['issued'] : $item['modified'];
                if ( $atom_date ) {
                    $epoch = @parse_w3cdtf($item['modified']);
                    if ($epoch and $epoch > 0) {
                        $item['date_timestamp'] = $epoch;
                    }
                }

                if ( is_array($item['categories']) ) {
                    $item['category'] = $item['categories'][0];
                    $item['dc']['subjects'] = $item['categories'];
                    $item['dc']['subject'] = $item['category'];
                }
                
                $this->items[$i] = $item;
            }       
        }
        elseif ( $this->is_rss() ) {
            $this->channel['tagline'] = $this->channel['description'];
            for ( $i = 0; $i < count($this->items); $i++) {
                $item = $this->items[$i];
                if ( isset($item['description']))
                    $item['summary'] = $item['description'];
                if ( isset($item['content']['encoded'] ) )
                    $item['atom_content'] = $item['content']['encoded'];
                
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
                
                if ( is_array($item['categories']) ) {
                    $item['category'] = $item['categories'][0];
                    $item['dc']['subjects'] = $item['categories'];
                    $item['dc']['subject'] = $item['category'];
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
        if ( $php_errormsg ) { 
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
} // end class RSS

function map_attrs($k, $v) {
    return "$k=\"$v\"";
}
# ---- cut here ----

require_once( dirname(__FILE__) . '/class-snoopy.php');

# -- UPDATED from rss_fetch.inc: fetch_rss, error, debug, magpie_error
# --- cut here ---
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
            return _response_to_rss( $resp );
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
            if ( $rss and $rss->etag and $rss->last_modified ) {
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
                $rss = _response_to_rss( $resp );
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
            trigger_error( $errormsg, $lvl);                
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
# --- cut here ---

# UPDATED FROM: rss_fetch.inc: _fetch_remote_file, _response_to_rss, init
# --- cut here ---
/*=======================================================================*\
    Function:   _fetch_remote_file
    Purpose:    retrieve an arbitrary remote file
    Input:      url of the remote file
                headers to send along with the request (optional)
    Output:     an HTTP response object (see Snoopy.class.inc)  
\*=======================================================================*/
function _fetch_remote_file ($url, $headers = "" ) {
    // Snoopy is an HTTP client in PHP
    $client = new Snoopy();
    $client->agent = MAGPIE_USER_AGENT;
    $client->read_timeout = MAGPIE_FETCH_TIME_OUT;
    $client->use_gzip = MAGPIE_USE_GZIP;
    if (is_array($headers) ) {
        $client->rawheaders = $headers;
    }
    
    @$client->fetch($url);
    return $client;

}

/*=======================================================================*\
    Function:   _response_to_rss
    Purpose:    parse an HTTP response object into an RSS object
    Input:      an HTTP response object (see Snoopy)
    Output:     parsed RSS object (see rss_parse)
\*=======================================================================*/
function _response_to_rss ($resp) {
    $rss = new MagpieRSS( $resp->results, MAGPIE_OUTPUT_ENCODING, MAGPIE_INPUT_ENCODING, MAGPIE_DETECT_ENCODING );
    
    // if RSS parsed successfully       
    if ( $rss and !$rss->ERROR) {
        
        // find Etag, and Last-Modified
        foreach($resp->headers as $h) {
            // 2003-03-02 - Nicola Asuni (www.tecnick.com) - fixed bug "Undefined offset: 1"
            if (strpos($h, ": ")) {
                list($field, $val) = explode(": ", $h, 2);
            }
            else {
                $field = $h;
                $val = "";
            }
            
            if ( $field == 'ETag' ) {
                $rss->etag = $val;
            }
            
            if ( $field == 'Last-Modified' ) {
                $rss->last_modified = $val;
            }
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
        # WORDPRESS MODIFICATION: send WordPress as user-agent
	# --- cut here ---
	$ua = 'WordPress/'. $wp_version . ' (+http://www.wordpress.org';
	# --- cut here ---
	
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
# --- cut here ---

function is_info ($sc) { 
	return $sc >= 100 && $sc < 200; 
}

function is_success ($sc) { 
	return $sc >= 200 && $sc < 300; 
}

function is_redirect ($sc) { 
	return $sc >= 300 && $sc < 400; 
}

function is_error ($sc) { 
	return $sc >= 400 && $sc < 600; 
}

function is_client_error ($sc) { 
	return $sc >= 400 && $sc < 500; 
}

function is_server_error ($sc) { 
	return $sc >= 500 && $sc < 600; 
}

# WORDPRESS-SPECIFIC: class RSSCache (modified to use WP database)
# --- cut here ---
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
# --- cut here ---

function parse_w3cdtf ( $date_str ) {
	
	# regex to match wc3dtf
	$pat = "/(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(:(\d{2}))?(?:([-+])(\d{2}):?(\d{2})|(Z))?/";
	
	if ( preg_match( $pat, $date_str, $match ) ) {
		list( $year, $month, $day, $hours, $minutes, $seconds) = 
			array( $match[1], $match[2], $match[3], $match[4], $match[5], $match[6]);
		
		# calc epoch for current date assuming GMT
		$epoch = gmmktime( $hours, $minutes, $seconds, $month, $day, $year);
		
		$offset = 0;
		if ( $match[10] == 'Z' ) {
			# zulu time, aka GMT
		}
		else {
			list( $tz_mod, $tz_hour, $tz_min ) =
				array( $match[8], $match[9], $match[10]);
			
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

# WORDPRESS-SPECIFIC: wp_rss (), get_rss ()
# --- cut here ---
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
# --- cut here ---
?>