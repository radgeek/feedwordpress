<?php
class FeedWordPie_Parser extends SimplePie_Parser {
	function reset_parser (&$xml) {
		// reset members
		$this->namespace = array('');
		$this->element = array('');
		$this->xml_base = array('');
		$this->xml_base_explicit = array(false);
		$this->xml_lang = array('');
		$this->data = array();
		$this->datas = array(array());
		$this->current_xhtml_construct = -1;
		$this->xmlns_stack = array();
		$this->xmlns_current = array();

		// reset libxml parser
		xml_parser_free($xml);
				
		$xml = xml_parser_create_ns($this->encoding, $this->separator);
		xml_parser_set_option($xml, XML_OPTION_SKIP_WHITE, 1);
		xml_parser_set_option($xml, XML_OPTION_CASE_FOLDING, 0);
		xml_set_object($xml, $this);
		xml_set_character_data_handler($xml, 'cdata');
		xml_set_element_handler($xml, 'tag_open', 'tag_close');
		xml_set_start_namespace_decl_handler($xml, 'start_xmlns');
	}
	
	public function parse (&$data, $encoding, $url = '')
	{
		$data = apply_filters('feedwordpress_parser_parse', $data, $encoding, $this, $url);
		
		if (class_exists('DOMXpath') && function_exists('Mf2\parse')) {
			$doc = new DOMDocument();
			@$doc->loadHTML($data);
			$xpath = new DOMXpath($doc);
			// Check for both h-feed and h-entry, as both a feed with no entries
			// and a list of entries without an h-feed wrapper are both valid.
			$query = '//*[contains(concat(" ", @class, " "), " h-feed ") or '.
				'contains(concat(" ", @class, " "), " h-entry ")]';
			$result = $xpath->query($query);
			if ($result->length !== 0) {
				return $this->parse_microformats($data, $url);
			}
		}
		
		// Use UTF-8 if we get passed US-ASCII, as every US-ASCII character is a UTF-8 character
		if (strtoupper($encoding) === 'US-ASCII')
		{
			$this->encoding = 'UTF-8';
		}
		else
		{
			$this->encoding = $encoding;
		}

		// Strip BOM:
		// UTF-32 Big Endian BOM
		if (substr($data, 0, 4) === "\x00\x00\xFE\xFF")
		{
			$data = substr($data, 4);
		}
		// UTF-32 Little Endian BOM
		elseif (substr($data, 0, 4) === "\xFF\xFE\x00\x00")
		{
			$data = substr($data, 4);
		}
		// UTF-16 Big Endian BOM
		elseif (substr($data, 0, 2) === "\xFE\xFF")
		{
			$data = substr($data, 2);
		}
		// UTF-16 Little Endian BOM
		elseif (substr($data, 0, 2) === "\xFF\xFE")
		{
			$data = substr($data, 2);
		}
		// UTF-8 BOM
		elseif (substr($data, 0, 3) === "\xEF\xBB\xBF")
		{
			$data = substr($data, 3);
		}

		if (substr($data, 0, 5) === '<?xml' && strspn(substr($data, 5, 1), "\x09\x0A\x0D\x20") && ($pos = strpos($data, '?>')) !== false)
		{
			$declaration = $this->registry->create('XML_Declaration_Parser', array(substr($data, 5, $pos - 5)));
			if ($declaration->parse())
			{
				$data = substr($data, $pos + 2);
				$data = '<?xml version="' . $declaration->version . '" encoding="' . $encoding . '" standalone="' . (($declaration->standalone) ? 'yes' : 'no') . '"?>' ."\n". $this->declare_html_entities() . $data;
			}
			else
			{
				$this->error_string = 'SimplePie bug! Please report this!';
				return false;
			}
		}

		$return = true;

		static $xml_is_sane = null;
		if ($xml_is_sane === null)
		{
			$parser_check = xml_parser_create();
			xml_parse_into_struct($parser_check, '<foo>&amp;</foo>', $values);
			xml_parser_free($parser_check);
			$xml_is_sane = isset($values[0]['value']);
		}

		// Create the parser
		if ($xml_is_sane)
		{
			$xml = xml_parser_create_ns($this->encoding, $this->separator);
			xml_parser_set_option($xml, XML_OPTION_SKIP_WHITE, 1);
			xml_parser_set_option($xml, XML_OPTION_CASE_FOLDING, 0);
			xml_set_object($xml, $this);
			xml_set_character_data_handler($xml, 'cdata');
			xml_set_element_handler($xml, 'tag_open', 'tag_close');

			// Parse!
			$results = $this->do_xml_parse_attempt($xml, $data);
			$parseResults = $results[0];
			$data = $results[1];

			if (!$parseResults) {
				$this->error_code = xml_get_error_code($xml);
				$this->error_string = xml_error_string($this->error_code);
				$return = false;
			}
			$this->current_line = xml_get_current_line_number($xml);
			$this->current_column = xml_get_current_column_number($xml);
			$this->current_byte = xml_get_current_byte_index($xml);
			xml_parser_free($xml);
			return $return;
		}

		libxml_clear_errors();
		$xml = new XMLReader();
		$xml->xml($data);
		while (@$xml->read())
		{
			switch ($xml->nodeType)
			{

				case constant('XMLReader::END_ELEMENT'):
					if ($xml->namespaceURI !== '')
					{
						$tagName = $xml->namespaceURI . $this->separator . $xml->localName;
					}
					else
					{
						$tagName = $xml->localName;
					}
					$this->tag_close(null, $tagName);
					break;
				case constant('XMLReader::ELEMENT'):
					$empty = $xml->isEmptyElement;
					if ($xml->namespaceURI !== '')
					{
						$tagName = $xml->namespaceURI . $this->separator . $xml->localName;
					}
					else
					{
						$tagName = $xml->localName;
					}
					$attributes = array();
					while ($xml->moveToNextAttribute())
					{
						if ($xml->namespaceURI !== '')
						{
							$attrName = $xml->namespaceURI . $this->separator . $xml->localName;
						}
						else
						{
							$attrName = $xml->localName;
						}
						$attributes[$attrName] = $xml->value;
					}
					
					$this->do_scan_attributes_namespaces($attributes);
					
					$this->tag_open(null, $tagName, $attributes);
					if ($empty)
					{
						$this->tag_close(null, $tagName);
					}
					break;
				case constant('XMLReader::TEXT'):

				case constant('XMLReader::CDATA'):
					$this->cdata(null, $xml->value);
					break;
			}
		}
		if ($error = libxml_get_last_error())
		{
			$this->error_code = $error->code;
			$this->error_string = $error->message;
			$this->current_line = $error->line;
			$this->current_column = $error->column;
			return false;
		}

		return true;
	} /* FeedWordPie_Parser::parse() */
	
	public function do_xml_parse_attempt ($xml, $data) {
		xml_set_start_namespace_decl_handler($xml, 'start_xmlns');

		// Parse!
		$parseResults = xml_parse($xml, $data, true);
		$endOfJunk = strpos($data, '<?xml');
		if (!$parseResults and $endOfJunk > 0) :
			// There is some junk before the feed prolog. Try to get rid of it.
			$data = substr($data, $endOfJunk);
			$data = trim($data);
			$this->reset_parser($xml);
			
			$parseResults = xml_parse($xml, $data, true);
		endif;
			
		$badEntity = (xml_get_error_code($xml) == 26);
		if (!$parseResults and $badEntity) :
			// There was an entity that libxml couldn't understand.
			// Chances are, it was a stray HTML entity. So let's try
			// converting all the named HTML entities to numeric XML
			// entities and starting over.
			$data = $this->html_convert_entities($data);
			$this->reset_parser($xml);

			$parseResults = xml_parse($xml, $data, true);
		endif;

		$result = array(
			$parseResults,
			$data
		);
		return $result;
			
	}

	public function do_scan_attributes_namespaces ($attributes) {
		foreach ($attributes as $attr => $value) :
			list($ns, $local) = $this->split_ns($attr);
			if ($ns=='http://www.w3.org/2000/xmlns/') :
				if ('xmlns' == $local) : $local = false; endif;
				$this->start_xmlns(null, $local, $value);
			endif;
		endforeach;
	}
	
	var $xmlns_stack = array();
	var $xmlns_current = array();
	function tag_open ($parser, $tag, $attributes) {
		$ret = parent::tag_open($parser, $tag, $attributes);
		if ($this->current_xhtml_construct < 0) :
			$this->data['xmlns'] = $this->xmlns_current;
			$this->xmlns_stack[] = $this->xmlns_current;
		endif;
		return $ret;
	}
	
	function tag_close($parser, $tag) {
		if ($this->current_xhtml_construct < 0) :
			$this->xmlns_current = array_pop($this->xmlns_stack);
		endif;
		$ret = parent::tag_close($parser, $tag);
		return $ret;
	}
	
	function start_xmlns ($parser, $prefix, $uri) {
		if (!$prefix) :
			$prefix = '';
		endif;
		if ($this->current_xhtml_construct < 0) :
			$this->xmlns_current[$prefix] = $uri;
		endif;
		
		return true;
	} /* FeedWordPie_Parser::start_xmlns() */

	/* html_convert_entities($string) -- convert named HTML entities to 
 	 * XML-compatible numeric entities. Adapted from code by @inanimatt:
	 * https://gist.github.com/inanimatt/879249
	 */
	public function html_convert_entities($string) {
		return preg_replace_callback('/&([a-zA-Z][a-zA-Z0-9]+);/S', 
			array($this, 'convert_entity'), $string);
	}

	/* Swap HTML named entity with its numeric equivalent. If the entity
 	 * isn't in the lookup table, this function returns a blank, which
	 * destroys the character in the output - this is probably the 
	 * desired behaviour when producing XML. Adapted from code by @inanimatt:
	 * https://gist.github.com/inanimatt/879249
	 */
	public function convert_entity($matches) {
		static $table = array(
			'quot'     => '&#34;',
			'amp'      => '&#38;',
			'lt'       => '&#60;',
			'gt'       => '&#62;',
			'OElig'    => '&#338;',
			'oelig'    => '&#339;',
			'Scaron'   => '&#352;',
			'scaron'   => '&#353;',
			'Yuml'     => '&#376;',
			'circ'     => '&#710;',
			'tilde'    => '&#732;',
			'ensp'     => '&#8194;',
			'emsp'     => '&#8195;',
			'thinsp'   => '&#8201;',
			'zwnj'     => '&#8204;',
			'zwj'      => '&#8205;',
			'lrm'      => '&#8206;',
			'rlm'      => '&#8207;',
			'ndash'    => '&#8211;',
			'mdash'    => '&#8212;',
			'lsquo'    => '&#8216;',
			'rsquo'    => '&#8217;',
			'sbquo'    => '&#8218;',
			'ldquo'    => '&#8220;',
			'rdquo'    => '&#8221;',
			'bdquo'    => '&#8222;',
			'dagger'   => '&#8224;',
			'Dagger'   => '&#8225;',
			'permil'   => '&#8240;',
			'lsaquo'   => '&#8249;',
			'rsaquo'   => '&#8250;',
			'euro'     => '&#8364;',
			'fnof'     => '&#402;',
			'Alpha'    => '&#913;',
			'Beta'     => '&#914;',
			'Gamma'    => '&#915;',
			'Delta'    => '&#916;',
			'Epsilon'  => '&#917;',
			'Zeta'     => '&#918;',
			'Eta'      => '&#919;',
			'Theta'    => '&#920;',
			'Iota'     => '&#921;',
			'Kappa'    => '&#922;',
			'Lambda'   => '&#923;',
			'Mu'       => '&#924;',
			'Nu'       => '&#925;',
			'Xi'       => '&#926;',
			'Omicron'  => '&#927;',
			'Pi'       => '&#928;',
			'Rho'      => '&#929;',
			'Sigma'    => '&#931;',
			'Tau'      => '&#932;',
			'Upsilon'  => '&#933;',
			'Phi'      => '&#934;',
			'Chi'      => '&#935;',
			'Psi'      => '&#936;',
			'Omega'    => '&#937;',
			'alpha'    => '&#945;',
			'beta'     => '&#946;',
			'gamma'    => '&#947;',
			'delta'    => '&#948;',
			'epsilon'  => '&#949;',
			'zeta'     => '&#950;',
			'eta'      => '&#951;',
			'theta'    => '&#952;',
			'iota'     => '&#953;',
			'kappa'    => '&#954;',
			'lambda'   => '&#955;',
			'mu'       => '&#956;',
			'nu'       => '&#957;',
			'xi'       => '&#958;',
			'omicron'  => '&#959;',
			'pi'       => '&#960;',
			'rho'      => '&#961;',
			'sigmaf'   => '&#962;',
			'sigma'    => '&#963;',
			'tau'      => '&#964;',
			'upsilon'  => '&#965;',
			'phi'      => '&#966;',
			'chi'      => '&#967;',
			'psi'      => '&#968;',
			'omega'    => '&#969;',
			'thetasym' => '&#977;',
			'upsih'    => '&#978;',
			'piv'      => '&#982;',
			'bull'     => '&#8226;',
			'hellip'   => '&#8230;',
			'prime'    => '&#8242;',
			'Prime'    => '&#8243;',
			'oline'    => '&#8254;',
			'frasl'    => '&#8260;',
			'weierp'   => '&#8472;',
			'image'    => '&#8465;',
			'real'     => '&#8476;',
			'trade'    => '&#8482;',
			'alefsym'  => '&#8501;',
			'larr'     => '&#8592;',
			'uarr'     => '&#8593;',
			'rarr'     => '&#8594;',
			'darr'     => '&#8595;',
			'harr'     => '&#8596;',
			'crarr'    => '&#8629;',
			'lArr'     => '&#8656;',
			'uArr'     => '&#8657;',
			'rArr'     => '&#8658;',
			'dArr'     => '&#8659;',
			'hArr'     => '&#8660;',
			'forall'   => '&#8704;',
			'part'     => '&#8706;',
			'exist'    => '&#8707;',
			'empty'    => '&#8709;',
			'nabla'    => '&#8711;',
			'isin'     => '&#8712;',
			'notin'    => '&#8713;',
			'ni'       => '&#8715;',
			'prod'     => '&#8719;',
			'sum'      => '&#8721;',
			'minus'    => '&#8722;',
			'lowast'   => '&#8727;',
			'radic'    => '&#8730;',
			'prop'     => '&#8733;',
			'infin'    => '&#8734;',
			'ang'      => '&#8736;',
			'and'      => '&#8743;',
			'or'       => '&#8744;',
			'cap'      => '&#8745;',
			'cup'      => '&#8746;',
			'int'      => '&#8747;',
			'there4'   => '&#8756;',
			'sim'      => '&#8764;',
			'cong'     => '&#8773;',
			'asymp'    => '&#8776;',
			'ne'       => '&#8800;',
			'equiv'    => '&#8801;',
			'le'       => '&#8804;',
			'ge'       => '&#8805;',
			'sub'      => '&#8834;',
			'sup'      => '&#8835;',
			'nsub'     => '&#8836;',
			'sube'     => '&#8838;',
			'supe'     => '&#8839;',
			'oplus'    => '&#8853;',
			'otimes'   => '&#8855;',
			'perp'     => '&#8869;',
			'sdot'     => '&#8901;',
			'lceil'    => '&#8968;',
			'rceil'    => '&#8969;',
			'lfloor'   => '&#8970;',
			'rfloor'   => '&#8971;',
			'lang'     => '&#9001;',
			'rang'     => '&#9002;',
			'loz'      => '&#9674;',
			'spades'   => '&#9824;',
			'clubs'    => '&#9827;',
			'hearts'   => '&#9829;',
			'diams'    => '&#9830;',
			'nbsp'     => '&#160;',
			'iexcl'    => '&#161;',
			'cent'     => '&#162;',
			'pound'    => '&#163;',
			'curren'   => '&#164;',
			'yen'      => '&#165;',
			'brvbar'   => '&#166;',
			'sect'     => '&#167;',
			'uml'      => '&#168;',
			'copy'     => '&#169;',
			'ordf'     => '&#170;',
			'laquo'    => '&#171;',
			'not'      => '&#172;',
			'shy'      => '&#173;',
			'reg'      => '&#174;',
			'macr'     => '&#175;',
			'deg'      => '&#176;',
			'plusmn'   => '&#177;',
			'sup2'     => '&#178;',
			'sup3'     => '&#179;',
			'acute'    => '&#180;',
			'micro'    => '&#181;',
			'para'     => '&#182;',
			'middot'   => '&#183;',
			'cedil'    => '&#184;',
			'sup1'     => '&#185;',
			'ordm'     => '&#186;',
			'raquo'    => '&#187;',
			'frac14'   => '&#188;',
			'frac12'   => '&#189;',
			'frac34'   => '&#190;',
			'iquest'   => '&#191;',
			'Agrave'   => '&#192;',
			'Aacute'   => '&#193;',
			'Acirc'    => '&#194;',
			'Atilde'   => '&#195;',
			'Auml'     => '&#196;',
			'Aring'    => '&#197;',
			'AElig'    => '&#198;',
			'Ccedil'   => '&#199;',
			'Egrave'   => '&#200;',
			'Eacute'   => '&#201;',
			'Ecirc'    => '&#202;',
			'Euml'     => '&#203;',
			'Igrave'   => '&#204;',
			'Iacute'   => '&#205;',
			'Icirc'    => '&#206;',
			'Iuml'     => '&#207;',
			'ETH'      => '&#208;',
			'Ntilde'   => '&#209;',
			'Ograve'   => '&#210;',
			'Oacute'   => '&#211;',
			'Ocirc'    => '&#212;',
			'Otilde'   => '&#213;',
			'Ouml'     => '&#214;',
			'times'    => '&#215;',
			'Oslash'   => '&#216;',
			'Ugrave'   => '&#217;',
			'Uacute'   => '&#218;',
			'Ucirc'    => '&#219;',
			'Uuml'     => '&#220;',
			'Yacute'   => '&#221;',
			'THORN'    => '&#222;',
			'szlig'    => '&#223;',
			'agrave'   => '&#224;',
			'aacute'   => '&#225;',
			'acirc'    => '&#226;',
			'atilde'   => '&#227;',
			'auml'     => '&#228;',
			'aring'    => '&#229;',
			'aelig'    => '&#230;',
			'ccedil'   => '&#231;',
			'egrave'   => '&#232;',
			'eacute'   => '&#233;',
			'ecirc'    => '&#234;',
			'euml'     => '&#235;',
			'igrave'   => '&#236;',
			'iacute'   => '&#237;',
			'icirc'    => '&#238;',
			'iuml'     => '&#239;',
			'eth'      => '&#240;',
			'ntilde'   => '&#241;',
			'ograve'   => '&#242;',
			'oacute'   => '&#243;',
			'ocirc'    => '&#244;',
			'otilde'   => '&#245;',
			'ouml'     => '&#246;',
			'divide'   => '&#247;',
			'oslash'   => '&#248;',
			'ugrave'   => '&#249;',
			'uacute'   => '&#250;',
			'ucirc'    => '&#251;',
			'uuml'     => '&#252;',
			'yacute'   => '&#253;',
			'thorn'    => '&#254;',
			'yuml'     => '&#255;'
		);
		// Entity not found? Destroy it.
		return isset($table[$matches[1]]) ? $table[$matches[1]] : '';
	} /* FeedWordPie_Parser::convert_entity() */

	private function declare_html_entities() {
		// This is required because the RSS specification says that entity-encoded
		// html is allowed, but the xml specification says they must be declared.
		return '<!DOCTYPE html [ <!ENTITY nbsp "&#x00A0;"> <!ENTITY iexcl "&#x00A1;"> <!ENTITY cent "&#x00A2;"> <!ENTITY pound "&#x00A3;"> <!ENTITY curren "&#x00A4;"> <!ENTITY yen "&#x00A5;"> <!ENTITY brvbar "&#x00A6;"> <!ENTITY sect "&#x00A7;"> <!ENTITY uml "&#x00A8;"> <!ENTITY copy "&#x00A9;"> <!ENTITY ordf "&#x00AA;"> <!ENTITY laquo "&#x00AB;"> <!ENTITY not "&#x00AC;"> <!ENTITY shy "&#x00AD;"> <!ENTITY reg "&#x00AE;"> <!ENTITY macr "&#x00AF;"> <!ENTITY deg "&#x00B0;"> <!ENTITY plusmn "&#x00B1;"> <!ENTITY sup2 "&#x00B2;"> <!ENTITY sup3 "&#x00B3;"> <!ENTITY acute "&#x00B4;"> <!ENTITY micro "&#x00B5;"> <!ENTITY para "&#x00B6;"> <!ENTITY middot "&#x00B7;"> <!ENTITY cedil "&#x00B8;"> <!ENTITY sup1 "&#x00B9;"> <!ENTITY ordm "&#x00BA;"> <!ENTITY raquo "&#x00BB;"> <!ENTITY frac14 "&#x00BC;"> <!ENTITY frac12 "&#x00BD;"> <!ENTITY frac34 "&#x00BE;"> <!ENTITY iquest "&#x00BF;"> <!ENTITY Agrave "&#x00C0;"> <!ENTITY Aacute "&#x00C1;"> <!ENTITY Acirc "&#x00C2;"> <!ENTITY Atilde "&#x00C3;"> <!ENTITY Auml "&#x00C4;"> <!ENTITY Aring "&#x00C5;"> <!ENTITY AElig "&#x00C6;"> <!ENTITY Ccedil "&#x00C7;"> <!ENTITY Egrave "&#x00C8;"> <!ENTITY Eacute "&#x00C9;"> <!ENTITY Ecirc "&#x00CA;"> <!ENTITY Euml "&#x00CB;"> <!ENTITY Igrave "&#x00CC;"> <!ENTITY Iacute "&#x00CD;"> <!ENTITY Icirc "&#x00CE;"> <!ENTITY Iuml "&#x00CF;"> <!ENTITY ETH "&#x00D0;"> <!ENTITY Ntilde "&#x00D1;"> <!ENTITY Ograve "&#x00D2;"> <!ENTITY Oacute "&#x00D3;"> <!ENTITY Ocirc "&#x00D4;"> <!ENTITY Otilde "&#x00D5;"> <!ENTITY Ouml "&#x00D6;"> <!ENTITY times "&#x00D7;"> <!ENTITY Oslash "&#x00D8;"> <!ENTITY Ugrave "&#x00D9;"> <!ENTITY Uacute "&#x00DA;"> <!ENTITY Ucirc "&#x00DB;"> <!ENTITY Uuml "&#x00DC;"> <!ENTITY Yacute "&#x00DD;"> <!ENTITY THORN "&#x00DE;"> <!ENTITY szlig "&#x00DF;"> <!ENTITY agrave "&#x00E0;"> <!ENTITY aacute "&#x00E1;"> <!ENTITY acirc "&#x00E2;"> <!ENTITY atilde "&#x00E3;"> <!ENTITY auml "&#x00E4;"> <!ENTITY aring "&#x00E5;"> <!ENTITY aelig "&#x00E6;"> <!ENTITY ccedil "&#x00E7;"> <!ENTITY egrave "&#x00E8;"> <!ENTITY eacute "&#x00E9;"> <!ENTITY ecirc "&#x00EA;"> <!ENTITY euml "&#x00EB;"> <!ENTITY igrave "&#x00EC;"> <!ENTITY iacute "&#x00ED;"> <!ENTITY icirc "&#x00EE;"> <!ENTITY iuml "&#x00EF;"> <!ENTITY eth "&#x00F0;"> <!ENTITY ntilde "&#x00F1;"> <!ENTITY ograve "&#x00F2;"> <!ENTITY oacute "&#x00F3;"> <!ENTITY ocirc "&#x00F4;"> <!ENTITY otilde "&#x00F5;"> <!ENTITY ouml "&#x00F6;"> <!ENTITY divide "&#x00F7;"> <!ENTITY oslash "&#x00F8;"> <!ENTITY ugrave "&#x00F9;"> <!ENTITY uacute "&#x00FA;"> <!ENTITY ucirc "&#x00FB;"> <!ENTITY uuml "&#x00FC;"> <!ENTITY yacute "&#x00FD;"> <!ENTITY thorn "&#x00FE;"> <!ENTITY yuml "&#x00FF;"> <!ENTITY OElig "&#x0152;"> <!ENTITY oelig "&#x0153;"> <!ENTITY Scaron "&#x0160;"> <!ENTITY scaron "&#x0161;"> <!ENTITY Yuml "&#x0178;"> <!ENTITY fnof "&#x0192;"> <!ENTITY circ "&#x02C6;"> <!ENTITY tilde "&#x02DC;"> <!ENTITY Alpha "&#x0391;"> <!ENTITY Beta "&#x0392;"> <!ENTITY Gamma "&#x0393;"> <!ENTITY Epsilon "&#x0395;"> <!ENTITY Zeta "&#x0396;"> <!ENTITY Eta "&#x0397;"> <!ENTITY Theta "&#x0398;"> <!ENTITY Iota "&#x0399;"> <!ENTITY Kappa "&#x039A;"> <!ENTITY Lambda "&#x039B;"> <!ENTITY Mu "&#x039C;"> <!ENTITY Nu "&#x039D;"> <!ENTITY Xi "&#x039E;"> <!ENTITY Omicron "&#x039F;"> <!ENTITY Pi "&#x03A0;"> <!ENTITY Rho "&#x03A1;"> <!ENTITY Sigma "&#x03A3;"> <!ENTITY Tau "&#x03A4;"> <!ENTITY Upsilon "&#x03A5;"> <!ENTITY Phi "&#x03A6;"> <!ENTITY Chi "&#x03A7;"> <!ENTITY Psi "&#x03A8;"> <!ENTITY Omega "&#x03A9;"> <!ENTITY alpha "&#x03B1;"> <!ENTITY beta "&#x03B2;"> <!ENTITY gamma "&#x03B3;"> <!ENTITY delta "&#x03B4;"> <!ENTITY epsilon "&#x03B5;"> <!ENTITY zeta "&#x03B6;"> <!ENTITY eta "&#x03B7;"> <!ENTITY theta "&#x03B8;"> <!ENTITY iota "&#x03B9;"> <!ENTITY kappa "&#x03BA;"> <!ENTITY lambda "&#x03BB;"> <!ENTITY mu "&#x03BC;"> <!ENTITY nu "&#x03BD;"> <!ENTITY xi "&#x03BE;"> <!ENTITY omicron "&#x03BF;"> <!ENTITY pi "&#x03C0;"> <!ENTITY rho "&#x03C1;"> <!ENTITY sigmaf "&#x03C2;"> <!ENTITY sigma "&#x03C3;"> <!ENTITY tau "&#x03C4;"> <!ENTITY upsilon "&#x03C5;"> <!ENTITY phi "&#x03C6;"> <!ENTITY chi "&#x03C7;"> <!ENTITY psi "&#x03C8;"> <!ENTITY omega "&#x03C9;"> <!ENTITY thetasym "&#x03D1;"> <!ENTITY upsih "&#x03D2;"> <!ENTITY piv "&#x03D6;"> <!ENTITY ensp "&#x2002;"> <!ENTITY emsp "&#x2003;"> <!ENTITY thinsp "&#x2009;"> <!ENTITY zwnj "&#x200C;"> <!ENTITY zwj "&#x200D;"> <!ENTITY lrm "&#x200E;"> <!ENTITY rlm "&#x200F;"> <!ENTITY ndash "&#x2013;"> <!ENTITY mdash "&#x2014;"> <!ENTITY lsquo "&#x2018;"> <!ENTITY rsquo "&#x2019;"> <!ENTITY sbquo "&#x201A;"> <!ENTITY ldquo "&#x201C;"> <!ENTITY rdquo "&#x201D;"> <!ENTITY bdquo "&#x201E;"> <!ENTITY dagger "&#x2020;"> <!ENTITY Dagger "&#x2021;"> <!ENTITY bull "&#x2022;"> <!ENTITY hellip "&#x2026;"> <!ENTITY permil "&#x2030;"> <!ENTITY prime "&#x2032;"> <!ENTITY Prime "&#x2033;"> <!ENTITY lsaquo "&#x2039;"> <!ENTITY rsaquo "&#x203A;"> <!ENTITY oline "&#x203E;"> <!ENTITY frasl "&#x2044;"> <!ENTITY euro "&#x20AC;"> <!ENTITY image "&#x2111;"> <!ENTITY weierp "&#x2118;"> <!ENTITY real "&#x211C;"> <!ENTITY trade "&#x2122;"> <!ENTITY alefsym "&#x2135;"> <!ENTITY larr "&#x2190;"> <!ENTITY uarr "&#x2191;"> <!ENTITY rarr "&#x2192;"> <!ENTITY darr "&#x2193;"> <!ENTITY harr "&#x2194;"> <!ENTITY crarr "&#x21B5;"> <!ENTITY lArr "&#x21D0;"> <!ENTITY uArr "&#x21D1;"> <!ENTITY rArr "&#x21D2;"> <!ENTITY dArr "&#x21D3;"> <!ENTITY hArr "&#x21D4;"> <!ENTITY forall "&#x2200;"> <!ENTITY part "&#x2202;"> <!ENTITY exist "&#x2203;"> <!ENTITY empty "&#x2205;"> <!ENTITY nabla "&#x2207;"> <!ENTITY isin "&#x2208;"> <!ENTITY notin "&#x2209;"> <!ENTITY ni "&#x220B;"> <!ENTITY prod "&#x220F;"> <!ENTITY sum "&#x2211;"> <!ENTITY minus "&#x2212;"> <!ENTITY lowast "&#x2217;"> <!ENTITY radic "&#x221A;"> <!ENTITY prop "&#x221D;"> <!ENTITY infin "&#x221E;"> <!ENTITY ang "&#x2220;"> <!ENTITY and "&#x2227;"> <!ENTITY or "&#x2228;"> <!ENTITY cap "&#x2229;"> <!ENTITY cup "&#x222A;"> <!ENTITY int "&#x222B;"> <!ENTITY there4 "&#x2234;"> <!ENTITY sim "&#x223C;"> <!ENTITY cong "&#x2245;"> <!ENTITY asymp "&#x2248;"> <!ENTITY ne "&#x2260;"> <!ENTITY equiv "&#x2261;"> <!ENTITY le "&#x2264;"> <!ENTITY ge "&#x2265;"> <!ENTITY sub "&#x2282;"> <!ENTITY sup "&#x2283;"> <!ENTITY nsub "&#x2284;"> <!ENTITY sube "&#x2286;"> <!ENTITY supe "&#x2287;"> <!ENTITY oplus "&#x2295;"> <!ENTITY otimes "&#x2297;"> <!ENTITY perp "&#x22A5;"> <!ENTITY sdot "&#x22C5;"> <!ENTITY lceil "&#x2308;"> <!ENTITY rceil "&#x2309;"> <!ENTITY lfloor "&#x230A;"> <!ENTITY rfloor "&#x230B;"> <!ENTITY lang "&#x2329;"> <!ENTITY rang "&#x232A;"> <!ENTITY loz "&#x25CA;"> <!ENTITY spades "&#x2660;"> <!ENTITY clubs "&#x2663;"> <!ENTITY hearts "&#x2665;"> <!ENTITY diams "&#x2666;"> ]>';
	}
} /* class FeedWordPie_Parser */


