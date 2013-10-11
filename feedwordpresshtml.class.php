<?php
class FeedWordPressHTML {
	static function attributeRegex ($tag, $attr) {
		return ":(
		(<($tag)\s+[^>]*)
		($attr)=
		)
		(
			\s*(\"|')
			(((?!\\6).)*)
			\\6([^>]*>)
		|
			\s*(((?!/>)[^\s>])*)
			([^>]*>)
		)
		:ix";
	} /* function FeedWordPressHTML::attributeRegex () */

	static function attributeMatch ($matches) {
		for ($i = 0; $i <= 12; $i++) :
			if (!isset($matches[$i])) :
				$matches[$i] = '';
			endif;
		endfor;

		$suffix = $matches[12].$matches[9];
		$value = $matches[10].$matches[7];

		return array(
		"tag" => $matches[3],
		"attribute" => $matches[4],
		"value" => $value,
		"quote" => $matches[6],
		"prefix" => $matches[1].$matches[6],
		"suffix" => $matches[6].$suffix,
		"before_attribute" => $matches[2],
		"after_attribute" => $suffix,
		);
	} /* function FeedWordPressHTML::attributeMatch () */

	static function tagWithAttributeRegex ($tag, $attr, $value, $closing = true) {
		return ":(
		(<($tag)\s+[^>]*)
		($attr)=
		)
		(
			\s*(\"|')
			((((?!\\6).)*\s)*($value)(\s((?!\\6).)*)*)
			\\6([^>]*>)
		|
			\s*((?!/>)($value))
			([^>]*>)
		)".($closing ? "
		(((?!</($tag)>).)*)
		(</($tag)>)
		" : "")."
		:ix";
	} /* FeedWordPressHTML::tagWithAttributeRegex () */

	static function tagWithAttributeMatch ($matches, $closing = true) {
		for ($i = 0; $i <= 21; $i++) :
			if (!isset($matches[$i])) :
				$matches[$i] = '';
			endif;
		endfor;

		$suffix = $matches[16].$matches[13];
		$value = $matches[14].$matches[7];

		return array(
		"full" => $matches[0],
		"tag" => $matches[3],
		"attribute" => $matches[4],
		"value" => $value,
		"quote" => $matches[6],
		"prefix" => $matches[1].$matches[6],
		"suffix" => $matches[6].$suffix,
		"before_attribute" => $matches[2],
		"after_attribute" => $suffix,
		"open_tag" => $matches[1].$matches[6].$value.$matches[6].$suffix,
		"content" => ($closing ? $matches[17] : NULL),
		"close_tag" => ($closing ? $matches[20] : NULL),
		);

	} /* FeedWordPressHTML::tagWithAttributeMatch () */
} /* class FeedWordPressHTML */

