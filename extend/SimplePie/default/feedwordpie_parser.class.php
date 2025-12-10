<?php
class FeedWordPie_Parser extends SimplePie_Parser {
	
   /**
     * @return bool
     */
    public function parse(string &$data, string $encoding, string $url = '')
 	{
		
		$data = apply_filters('feedwordpress_parser_parse', $data, $encoding, $this, $url);

		return parent::parse( $data, $encoding, $url );

	} /* FeedWordPie_Parser::parse() */
	
} /* class FeedWordPie_Parser */


