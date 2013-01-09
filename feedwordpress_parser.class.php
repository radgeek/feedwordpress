<?php
class FeedWordPress_Parser extends SimplePie_Parser {
	function reset_parser (&$xml) {
		xml_parser_free($xml);
				
		$xml = xml_parser_create_ns($this->encoding, $this->separator);
		xml_parser_set_option($xml, XML_OPTION_SKIP_WHITE, 1);
		xml_parser_set_option($xml, XML_OPTION_CASE_FOLDING, 0);
		xml_set_object($xml, $this);
		xml_set_character_data_handler($xml, 'cdata');
		xml_set_element_handler($xml, 'tag_open', 'tag_close');
		xml_set_start_namespace_decl_handler($xml, 'start_xmlns');
	}
	
	function parse (&$data, $encoding) {
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
				$newData = substr($data, $endOfJunk);
				$newData = trim($newData);
				$this->reset_parser($xml);
			
				$parseResults = xml_parse($xml, $newData, true);
			endif;
			
			if (!$parseResults)
			{
				if (class_exists('DOMDocument')) :
					libxml_use_internal_errors(true);	
					$doc = new DOMDocument;
					$doc->loadHTML('<html>'.$data.'</html>');
					///*DBG*/ echo "<plaintext>";
					///*DBG*/ $dd = $doc->getElementsByTagName('html');
					///*DBG*/ for ($i = 0; $i < $dd->length; $i++) :
					///*DBG*/		var_dump($dd->item($i)->nodeName);
					///*DBG*/ endfor;
					///*DBG*/ var_dump($doc);
					libxml_use_internal_errors(false);
					///*DBG*/ die;
				endif;
				
				$this->error_code = xml_get_error_code($xml);
				$this->error_string = xml_error_string($this->error_code);
				///*DBG*/ echo "WOOGA WOOGA"; var_dump($this->error_string); die;
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
}

