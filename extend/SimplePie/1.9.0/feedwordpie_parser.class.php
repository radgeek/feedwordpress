<?php
class FeedWordPie_Parser extends SimplePie_Parser {
    var $xmlns_stack = array();
    var $xmlns_current = array();

    function reset_parser(&$xml) {
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

        if (is_resource($xml)) {
            xml_parser_free($xml);
        }

        $xml = xml_parser_create_ns($this->encoding, $this->separator);
        xml_parser_set_option($xml, XML_OPTION_SKIP_WHITE, 1);
        xml_parser_set_option($xml, XML_OPTION_CASE_FOLDING, 0);
        xml_set_object($xml, $this);
        xml_set_character_data_handler($xml, 'cdata');
        xml_set_element_handler($xml, 'tag_open', 'tag_close');
        xml_set_start_namespace_decl_handler($xml, 'start_xmlns');
    }

    public function parse(string &$data, string $encoding, string $url = '') {
        $data = apply_filters('feedwordpress_parser_parse', $data, $encoding, $this, $url);

        if (strtoupper($encoding) === 'US-ASCII') {
            $this->encoding = 'UTF-8';
        } else {
            $this->encoding = $encoding;
        }

        // Strip BOM
        if (substr($data, 0, 4) === "\x00\x00\xFE\xFF" || substr($data, 0, 4) === "\xFF\xFE\x00\x00") {
            $data = substr($data, 4);
        } elseif (substr($data, 0, 2) === "\xFE\xFF" || substr($data, 0, 2) === "\xFF\xFE") {
            $data = substr($data, 2);
        } elseif (substr($data, 0, 3) === "\xEF\xBB\xBF") {
            $data = substr($data, 3);
        }

        if (substr($data, 0, 5) === '<?xml' && ($pos = strpos($data, '?>')) !== false) {
            $declaration = $this->registry->create('XML_Declaration_Parser', array(substr($data, 5, $pos - 5)));
            if ($declaration->parse()) {
                $data = substr($data, $pos + 2);
                $data = '<?xml version="' . $declaration->version . '" encoding="' . $encoding . '" standalone="' . (($declaration->standalone) ? 'yes' : 'no') . '"?>' . "\n" . self::declare_html_entities() . $data;
            }
        }

        $xml = xml_parser_create_ns($this->encoding, $this->separator);
        xml_parser_set_option($xml, XML_OPTION_SKIP_WHITE, 1);
        xml_parser_set_option($xml, XML_OPTION_CASE_FOLDING, 0);
        xml_set_object($xml, $this);
        xml_set_character_data_handler($xml, 'cdata');
        xml_set_element_handler($xml, 'tag_open', 'tag_close');
        xml_set_start_namespace_decl_handler($xml, 'start_xmlns');

        $results = $this->do_xml_parse_attempt($xml, $data);
        $parseResults = $results[0];

        if (!$parseResults) {
            $this->error_code = xml_get_error_code($xml);
            $this->error_string = xml_error_string($this->error_code);
            xml_parser_free($xml);
            return false;
        }

        xml_parser_free($xml);
        return true;
    }

    public function do_xml_parse_attempt($xml, $data) {
        xml_set_start_namespace_decl_handler($xml, 'start_xmlns');
        $parseResults = xml_parse($xml, $data, true);

        if (!$parseResults && (xml_get_error_code($xml) == 26)) {
            $data = $this->html_convert_entities($data);
            $this->reset_parser($xml);
            $parseResults = xml_parse($xml, $data, true);
        }

        return array($parseResults, $data);
    }

    function tag_open($parser, $tag, $attributes) {
        $ret = parent::tag_open($parser, $tag, $attributes);
        if ($this->current_xhtml_construct < 0) {
            $this->data['xmlns'] = $this->xmlns_current;
            $this->xmlns_stack[] = $this->xmlns_current;
        }
        return $ret;
    }

    function tag_close($parser, $tag) {
        if ($this->current_xhtml_construct < 0) {
            $this->xmlns_current = array_pop($this->xmlns_stack);
        }
        return parent::tag_close($parser, $tag);
    }

    function start_xmlns($parser, $prefix, $uri) {
        if (!$prefix) $prefix = '';
        if ($this->current_xhtml_construct < 0) {
            $this->xmlns_current[$prefix] = $uri;
        }
        return true;
    }

    public function html_convert_entities($string) {
        return preg_replace_callback('/&([a-zA-Z][a-zA-Z0-9]+);/S', array($this, 'convert_entity'), $string);
    }

    public function convert_entity($matches) {
        static $table = array('quot'=>'&#34;','amp'=>'&#38;','lt'=>'&#60;','gt'=>'&#62;','nbsp'=>'&#160;','copy'=>'&#169;','reg'=>'&#174;');
        return isset($table[$matches[1]]) ? $table[$matches[1]] : '';
    }

    public static function declare_html_entities() {
        return '<!DOCTYPE html [ <!ENTITY nbsp "&#x00A0;"> <!ENTITY copy "&#x00A9;"> <!ENTITY reg "&#x00AE;"> ]>';
    }
}