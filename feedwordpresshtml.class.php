<?php 
class FeedWordPressHTML {
	function attributeRegex ($tag, $attr) {
		return ":(
		<($tag)\s+[^>]*
		($attr)=
		)
		(
			\s*(\"|')
			(((?!\\5).)*)
			\\5([^>]*>)
		|
			\s*(((?!/>)[^\s>])*)
			([^>]*>)
		)
		:ix";
	} /* function FeedWordPressHTML::attributeRegex () */

	function attributeMatch ($matches) {
		$suffix = (isset($matches[11]) ? $matches[11] : $matches[8]);
		$value = (isset($matches[9]) ? $matches[9] : $matches[6]);

		return array(
		"tag" => $matches[2],
		"attribute" => $matches[3],
		"value" => $value,
		"quote" => $matches[5],
		"prefix" => $matches[1].$matches[5],
		"suffix" => $matches[5].$suffix,
		);
	} /* function FeedWordPressHTML::attributeMatch () */
} /* class FeedWordPressHTML */

