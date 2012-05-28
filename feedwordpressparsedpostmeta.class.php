<?php
class FeedWordPressParsedPostMeta {
	var $s, $ptr, $ptrEOS;
	var $delims, $delimCount, $delimPtr;
	var $paren;

	var $parsed = NULL;

	function __construct ($s) {
		$this->s = $s;
		$this->reset();
	}

	function reset () {
		$this->parsed = NULL;
		
		$this->ptr = 0;
		$this->paren = 0;
		$this->ptrEOS = strlen($this->s);
	
		$this->delimCount['CDATA'] = preg_match_all('/\$/', $this->s, $this->delims['CDATA'], PREG_OFFSET_CAPTURE);
		$this->delimPtr['CDATA'] = 0;

		$this->delimCount['EXPR'] = preg_match_all('/[()\s]/', $this->s, $this->delims['EXPR'], PREG_OFFSET_CAPTURE);
		$this->delimPtr['EXPR'] = 0;
	}

	function look () { return $this->s[$this->ptr]; }
	function drop () { $this->anchor = $this->ptr; }
	function take () { return substr($this->s, $this->anchor, ($this->ptr - $this->anchor)); }
	function EOS () { return ($this->ptr >= $this->ptrEOS); }

	function nextDelim ($which) {
		$N = $this->delimCount[$which]; $ptr = $this->delimPtr[$which];

		$next = -1;
		while ( ($ptr < $N) and ($next < $this->ptr) ) :
			$next = $this->delims[$which][0][$ptr][1];
			$ptr++;
		endwhile;
		$this->delimPtr[$which] = $ptr;

		if ($next < $this->ptr) :
			$next = $this->ptrEOS;
		endif;
		return $next;
	}
	
	function substitute_terms ($post, $piece, $values = NULL) {
		$terms = array();
		if ('EXPR'==$piece[0]) :
			// Parameter stored in $piece[1]
			$param = $piece[1];
			if (is_string($param)) :
				if (!isset($values[$param])) :
					$values[$param] = $post->query($param);
				endif;
				$term = $param;
				$results = $values[$param];
			else :
				list($results, $term, $values) = $this->substitute_terms($post, $piece[1], $values);
			endif;
			
			// Filtering function, if any, stored in $piece[2]
			if (isset($piece[2])) :
				$filter = $post->substitution_function(trim(strtolower($piece[2])));
				if (!is_null($filter)) :
					foreach ($results as $key => $result) :
						$results[$key] = $filter($result);
					endforeach;
				else :
					$results = array(
						"[[ERR: No such function (".$piece[2].")]]",
					);
				endif;
			endif;
			
		elseif ('CDATA'==$piece[0]) :
			// Literal string stored in $piece[1]
			$results = array($piece[1]);
			$term = NULL;
		endif;
		return array($results, $term, $values);
	}

	function do_substitutions ($post, $in = NULL, $scratchpad = NULL) {
		if (is_null($in)) :
			$in = $this->get();
		endif;
		
		if (count($in) > 0) :
			$out = array();
	
			// Init. results set if not already initialized.
			if (is_null($scratchpad)) :
				$scratchpad = array(array('', array()));
			endif;
			
			// Grab the first
			$piece = array_shift($in);

			foreach ($scratchpad as $key => $scratch) :
				$line = $scratch[0];
				$element_values = $scratch[1]; 

				switch ($piece[0]) :
				case 'CDATA' :
					$subs = array($piece[1]);
					$term = NULL;
					break;
				case 'EXPR' :
					list($subs, $term, $element_values) = $this->substitute_terms($post, $piece, $element_values);
					break;
				endswitch;

				$constrained_values = $element_values;
				foreach ($subs as $sub) :
					
					if (isset($term)) :
						$constrained_values[$term] = array($sub);
					endif;
				
					$out[] = array($line . $sub, $constrained_values);
				endforeach;
				
			endforeach;
			
			if (count($in) > 0) :
				$out = $this->do_substitutions($post, $in, $out);
			endif;
		else :
			$out = NULL;
		endif;

		// Now that we are done, strip out the namespace elements.
		if (is_array($out)) :
			foreach ($out as $idx => $line) :
				if (is_array($line)) :
					$out[$idx] = $line[0];
				endif;
			endforeach;
		endif;
		
		return $out;
	}
		
	function get ($idx = NULL) {
		$ret = $this->parse();
		if ($idx) : $ret = $ret[$idx]; endif;
		
		return $ret;
	}
	
	function parse () {
		if (is_null($this->parsed)) :
			$out = array();
			
			$this->reset();
			while (!$this->EOS()) :
				switch ($this->look()) :
				case '$' :
					$this->ptr++;
					$out[] = $this->EXPR();
					break;
				default :
					$out[] = $this->CDATA();
				endswitch;
			endwhile;
			
			$this->parsed = $out;
		endif;
		return $this->parsed;
	}

	function CDATA () {
		$this->drop();
		$this->ptr = $this->nextDelim('CDATA');
		return array(__FUNCTION__, $this->take());
	} /* FeedWordPressParsedPostMeta::CDATA() */

	function EXPR () {
		$ret = array(__FUNCTION__);
		$paren0 = $this->paren;
		$this->drop();
		
		$ptr0 = $this->ptr;

		$complete = false;
		while (!$this->EOS() and !$complete) :
			$tok = $this->look();
			switch ($tok) :
			case '(' :

				$fun = $this->take();

				// We're at the open paren; skip ahead past that.
				$this->ptr++;

				// And indent us one level in.
				$this->paren++;
				
				$delta = $this->EXPR();
				if (isset($delta[2])) :
					$ret[1] = $delta;
				else :
					$ret[1] = $delta[1];
				endif;

				if (strlen(trim($fun)) > 0) :
					$ret[2] = trim($fun);
				endif;

				// We should be stopped on either a close paren or on EOS.
				$this->ptr++;

				// A top level expression terminates
				// immediately with the closing of its
				// parens. Lower-level expressions may
				// have whitespace wrapper, etc.
				$complete = ($this->paren <= 0);

				$this->drop();
				break;

			case ')' :

				if ($this->paren > 0) :
					$this->paren--;

					$complete = ($this->paren <= $paren0);

					$fun = $this->take();
					if (strlen(trim($fun)) > 0) :
						$ret[1] = trim($fun);
					endif;

					$this->drop();
				else :
					$this->ptr++;
				endif;
				break;
			
			case '$' :
				if ($ptr0 == $this->ptr) :
					// This is an escaped literal $
					$this->ptr++;
					$ret = array('CDATA', $tok);
					$this->drop();
					$complete = true;
					break;
				endif;

			default :
				if (ctype_space($tok) and ($this->paren <= 0)) :
					// A loose $ should be treated as a literal $
					if ($ptr0 == $this->ptr) :
						$ret = array('CDATA', '$');
						$this->drop();
					endif;
					$complete = true;
				else :
					$next = $this->nextDelim('EXPR');

					// Skip ahead to the next potentially interesting character.
					if (!is_null($next)) :
						$this->ptr = $next;
					else :
						$this->ptr = $this->ptrEOS;
					endif;
				
				endif;
			endswitch;
		endwhile;

		$var = '';
		if ($this->anchor < $this->ptr) :
			$var = trim($this->take());
		endif;
		if (strlen($var) > 0) :
			$ret[1] = $var;
		endif;
		return $ret;
	} /* FeedWordPressParsedPostMeta::EXPR () */
} /* class FeedWordPressParsedPostMeta */

if (basename($_SERVER['SCRIPT_FILENAME'])==basename(__FILE__)) :
	$argv = $_SERVER['argv'];
	array_shift($argv);
	$N = reset($argv);
	if (is_numeric($N)) :
		array_shift($argv);
	else :
		$N = 1;
	endif;

	$t0 = microtime(/*float=*/ true);
	for ($i = 0; $i < $N; $i++) :
		$parse = new FeedWordPressParsedPostMeta(implode(" ", $argv));
		$voo = ($parse->parse());
		unset($parse);
	endfor;
	$t1 = microtime(/*float=*/ true);

	echo "RESULT: "; var_dump($voo);
	echo "ELAPSED TIME: "; print number_format(1000.0 * ($t1 - $t0)) . "ms\n";
	echo "CONSUMED MEMORY: ";  print number_format(memory_get_peak_usage() / 1024) . "KB\n";
endif;

