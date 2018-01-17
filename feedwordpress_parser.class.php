<?php
class FeedWordPress_Parser extends SimplePie_Parser {
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
	
	public function parse (&$data, $encoding) {
		$data = apply_filters('feedwordpress_parser_parse', $data, $encoding, $this);
		
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
			$declaration = new SimplePie_XML_Declaration_Parser(substr($data, 5, $pos - 5));
			if ($declaration->parse())
			{
				$data = substr($data, $pos + 2);
				$data = '<?xml version="' . $declaration->version . '" encoding="' . $encoding . '" standalone="' . (($declaration->standalone) ? 'yes' : 'no') . '"?>' . $data;
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
		else
		{
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
							$tagName = "{$xml->namespaceURI}{$this->separator}{$xml->localName}";
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
							$tagName = "{$xml->namespaceURI}{$this->separator}{$xml->localName}";
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
								$attrName = "{$xml->namespaceURI}{$this->separator}{$xml->localName}";
							}
							else
							{
								$attrName = $xml->localName;
							}
							$attributes[$attrName] = $xml->value;
						}
						
						foreach ($attributes as $attr => $value) :
							list($ns, $local) = $this->split_ns($attr);
							if ($ns=='http://www.w3.org/2000/xmlns/') :
								if ('xmlns' == $local) : $local = false; endif;
								$this->start_xmlns(null, $local, $value);
							endif;
						endforeach;
						
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
			else
			{
				return true;
			}
		}
	} /* FeedWordPress_Parser::parse() */
	
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
	} /* FeedWordPress_Parser::start_xmlns() */

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
	} /* FeedWordPress_Parser::convert_entity() */

} /* class FeedWordPress_Parser */


