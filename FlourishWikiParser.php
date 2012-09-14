<?php
class FlourishWikiParser extends WikiParser
{
	/**
	 * Finishes up parsing
	 * 
	 * @return void
	 */
	protected function finishParsing()
	{
		if (isset($this->cache['blockquote_content']) && strlen($this->cache['blockquote_content'])) {
			$this->handleBlockquote();
		}
	}
	
	/**
	 * Recursively parses a blockquote to allow for nested formatting
	 * 
	 * @return void
	 */
	protected function handleBlockquote()
	{
		$content = $this->cache['blockquote_content'];
		$this->cache['blockquote_content'] = '';
		
		$content_parser = new self($content, $this->data);
		$descendants = $content_parser->parse();
		
		foreach (new ParserIterator($descendants) as $decendant) {
			$decendant->index = $this->index_counter++;
		}
		$this->tip->children = array_merge($this->tip->children, $descendants->children);
	}
	
	/**
	 * Adds an image tag to the tree
	 * 
	 * @param string $string  The wiki image tag, e.g. {{src|Alt Attribute}}
	 * @return void
	 */
	protected function handleImage($string)
	{
		$parts = explode('|', substr($string, 2, -2), 2);
		$url   = trim($parts[0]);
		$alt   = isset($parts[1]) ? trim($parts[1]) : '';
		$this->openTag('img', array('src' => $url, 'alt' => $alt));
	}

	protected function handleBlockStart($string)
	{
		preg_match('#\{\{\{(\s*\#!([\w-]+)[ \t]*)?\n#', $string, $match);
		if (isset($match[2])) {
			$lang = $match[2];
			if ($lang != 'raw') {
				if ($lang == 'text/html') {
					$lang = 'html';
				}
				$lang = preg_replace('#[^a-z0-9\-_/]#i', '', $lang);
				$this->openTag('block', array('class' => 'block ' . $lang));
				$this->cache['block_encode'] = TRUE;
			} else {
				$this->openTag('raw');
				$this->cache['block_encode'] = FALSE;
			}
		} else {
			$this->openTag('block');
			$this->cache['block_encode'] = TRUE;
		}
	}

	protected function handleBlockEnd()
	{
		$this->closeTag('block');
	}
	
	protected function handleURL($string)
	{
		$this->openTag('link', array('href' => $string));
		$this->handleString($string);
		$this->closeTag('link');
	}
	
	protected function handleDomain($string)
	{
		$this->openTag('link', array('href' => 'http://' . $string));
		$this->handleString($string);
		$this->closeTag('link');
	}

	protected function handleFlourishClass($string)
	{
		if (isset($this->data['flourish_class']) && $string == $this->data['flourish_class']) {
			$this->handleString($string);
			return;
		}
		$this->openTag('link', array('href' => '/docs/' . $string));
		$this->handleString($string);
		$this->closeTag('link');
	}

	protected function handleFlourishMethod($string)
	{
		$method_name = preg_replace('#^::(.*)\(\)$#', '\1', $string);
		$class_name  = $this->data['flourish_class'];
		$this->openTag('link', array('href' => '/api/' . $class_name . '#' . $method_name));
		$this->openTag('mono');
		$this->handleString(preg_replace('#^::#', '', $string));
		$this->closeTag('mono');
		$this->closeTag('link');
	}

	protected function handleFlourishClassMethod($string)
	{
		$method_name = preg_replace('#^(f[a-zA-Z0-9]+)::(.*)\(\)$#', '\2', $string);
		$class_name  = preg_replace('#^(f[a-zA-Z0-9]+)::(.*)\(\)$#', '\1', $string);
		$this->openTag('link', array('href' => '/api/' . $class_name . '#' . $method_name));
		$this->handleString($string);
		$this->closeTag('link');
	}
	
	protected function handleEmail($string)
	{
		$this->openTag('link', array('href' => 'mailto:' . $string));
		$this->handleString($string);
		$this->closeTag('link');
	}
	
	protected function handleCell()
	{
		$current_row  = $this->cache['current_row'];
		$current_cell = $this->cache['current_cell'];
		$cell_row = $this->cache['current_cell_rows'][$current_cell];
		
		if ($cell_row < $this->cache['current_row']) {
			$this->cache['rows'][$cell_row][$current_cell]['rowspan'] = $this->cache['current_row'] - $cell_row + 1;
			$this->cache['rows'][$current_row][$current_cell] = array('content' => NULL, 'type' => 'placeholder', 'align' => NULL, 'rowspan' => 0, 'colspan' => 0, 'line' => NULL);
		}
		
		if ($this->cache['rows'][$cell_row][$current_cell]['type'] == 'temp') {
			$this->cache['rows'][$cell_row][$current_cell]['type'] = 'td';
		}
		
		if ($this->cache['table_line'] != $this->cache['rows'][$cell_row][$current_cell]['line']) {
			$this->cache['rows'][$cell_row][$current_cell]['content'] .= "\n";
			$this->cache['rows'][$cell_row][$current_cell]['line']++;
		}
		$this->cache['rows'][$cell_row][$current_cell]['content'] .= $this->value;
		
		// This removes trailing = from th cells
		if ($this->cache['rows'][$cell_row][$current_cell]['type'] == 'th' && $this->value == '=' && strlen($this->cache['rows'][$cell_row][$current_cell]['content'])) {
			$this->cache['rows'][$cell_row][$current_cell]['content'] = substr($this->cache['rows'][$cell_row][$current_cell]['content'], 0, -1) . ' ';
		}
	}
	
	protected function openParagraph()
	{
		if ($this->getOpenTagPos('p') === FALSE) {
			$this->openTag('p');
		} else {
			$this->handleString(' ');
		}
		$this->yypushstate(self::INLINESTATE);
	}
	
	protected function openPlugin()
	{
		$plugin_tag = $this->openTag('plugin');
		$this->cache['plugin_tag'] = $plugin_tag;
		$this->yypushstate(self::PLUGINSTATE);
	}
	
	protected function getTotalWidth($avg_widths, $j, $distance)
	{
		$total = 0;
		for ($i=0; $i<$distance; $i++) {
			$total += $avg_widths[$j+$i];
		}
		return $total + $distance - 1;
	}


	private $_yy_state = 1;
	private $_yy_stack = array();
	
	function yyreflectstack()
	{
		$reflector = new ReflectionClass(__CLASS__);
		$constants = $reflector->getConstants();
		$constants = array_flip($constants);
		$stack_names = array();
		foreach ($this->_yy_stack as $state_num) {
			$stack_names[] = $constants[$state_num];
		}
		$stack_names[] = $constants[$this->_yy_state];
		return $stack_names;
	}

	function yylex()
	{
		return $this->{'yylex' . $this->_yy_state}();
	}

	function yypushstate($state)
	{
		array_push($this->_yy_stack, $this->_yy_state);
		$this->_yy_state = $state;
	}

	function yypopstate()
	{
		$this->_yy_state = array_pop($this->_yy_stack);
	}

	function yybegin($state)
	{
		$this->_yy_state = $state;
	}



	function yylex1()
	{
		$tokenMap = array (
              1 => 0,
              2 => 0,
              3 => 1,
              5 => 1,
              7 => 2,
              10 => 0,
              11 => 0,
              12 => 2,
              15 => 0,
              16 => 1,
              18 => 1,
              20 => 1,
              22 => 0,
              23 => 0,
            );
		if ($this->counter >= strlen($this->input)) {
			return false; // end of input
		}
		$yy_global_pattern = "/^([ \t]*=+[ \t]*)|^([ \t]*-{4,}[ \t]*\n)|^((\\(\\(\\(|\\[\\[\\[)[ \t]*\n)|^((\\)\\)\\)|\\]\\]\\])[ \t]*\n)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(''')|^(##)|^([ \t]*([*#+-](\\d\\.)?|\\d\\.)[ \t]?)|^(;[ \t]*)|^(\\|-[|+-]*(?=\\|)|\\|=?(>|<|~(?!~))?)|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^((:+|>+))|^(\n+)|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])/";

		do {
			if (preg_match($yy_global_pattern, substr($this->input, $this->counter), $yymatches)) {
				$yysubmatches = $yymatches;
				$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
				if (!count($yymatches)) {
					throw new Exception('Error: lexing failed because a rule matched' .
						'an empty string.  Input "' . substr($this->input,
						$this->counter, 5) . '... state NEWLINESTATE');
				}
				next($yymatches); // skip global match
				$this->token = key($yymatches); // token number
				if ($tokenMap[$this->token]) {
					// extract sub-patterns for passing to lex function
					$yysubmatches = array_slice($yysubmatches, $this->token + 1,
						$tokenMap[$this->token]);
				} else {
					$yysubmatches = array();
				}
				$this->value = current($yymatches); // token value
				$r = $this->{'yy_r1_' . $this->token}($yysubmatches);
				if ($r === null) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					// accept this token
					return true;
				} elseif ($r === true) {
					// we have changed state
					// process this token in the new state
					return $this->yylex();
				} elseif ($r === false) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					if ($this->counter >= strlen($this->input)) {
						return false; // end of input
					}
					// skip this token
					continue;
				} else {                    $yy_yymore_patterns = array(
        1 => array(0, "^([ \t]*-{4,}[ \t]*\n)|^((\\(\\(\\(|\\[\\[\\[)[ \t]*\n)|^((\\)\\)\\)|\\]\\]\\])[ \t]*\n)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(''')|^(##)|^([ \t]*([*#+-](\\d\\.)?|\\d\\.)[ \t]?)|^(;[ \t]*)|^(\\|-[|+-]*(?=\\|)|\\|=?(>|<|~(?!~))?)|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^((:+|>+))|^(\n+)|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        2 => array(0, "^((\\(\\(\\(|\\[\\[\\[)[ \t]*\n)|^((\\)\\)\\)|\\]\\]\\])[ \t]*\n)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(''')|^(##)|^([ \t]*([*#+-](\\d\\.)?|\\d\\.)[ \t]?)|^(;[ \t]*)|^(\\|-[|+-]*(?=\\|)|\\|=?(>|<|~(?!~))?)|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^((:+|>+))|^(\n+)|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        3 => array(1, "^((\\)\\)\\)|\\]\\]\\])[ \t]*\n)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(''')|^(##)|^([ \t]*([*#+-](\\d\\.)?|\\d\\.)[ \t]?)|^(;[ \t]*)|^(\\|-[|+-]*(?=\\|)|\\|=?(>|<|~(?!~))?)|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^((:+|>+))|^(\n+)|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        5 => array(2, "^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(''')|^(##)|^([ \t]*([*#+-](\\d\\.)?|\\d\\.)[ \t]?)|^(;[ \t]*)|^(\\|-[|+-]*(?=\\|)|\\|=?(>|<|~(?!~))?)|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^((:+|>+))|^(\n+)|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        7 => array(4, "^(''')|^(##)|^([ \t]*([*#+-](\\d\\.)?|\\d\\.)[ \t]?)|^(;[ \t]*)|^(\\|-[|+-]*(?=\\|)|\\|=?(>|<|~(?!~))?)|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^((:+|>+))|^(\n+)|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        10 => array(4, "^(##)|^([ \t]*([*#+-](\\d\\.)?|\\d\\.)[ \t]?)|^(;[ \t]*)|^(\\|-[|+-]*(?=\\|)|\\|=?(>|<|~(?!~))?)|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^((:+|>+))|^(\n+)|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        11 => array(4, "^([ \t]*([*#+-](\\d\\.)?|\\d\\.)[ \t]?)|^(;[ \t]*)|^(\\|-[|+-]*(?=\\|)|\\|=?(>|<|~(?!~))?)|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^((:+|>+))|^(\n+)|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        12 => array(6, "^(;[ \t]*)|^(\\|-[|+-]*(?=\\|)|\\|=?(>|<|~(?!~))?)|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^((:+|>+))|^(\n+)|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        15 => array(6, "^(\\|-[|+-]*(?=\\|)|\\|=?(>|<|~(?!~))?)|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^((:+|>+))|^(\n+)|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        16 => array(7, "^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^((:+|>+))|^(\n+)|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        18 => array(8, "^((:+|>+))|^(\n+)|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        20 => array(9, "^(\n+)|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        22 => array(9, "^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        23 => array(9, ""),
    );

					// yymore is needed
					do {
						if (!strlen($yy_yymore_patterns[$this->token][1])) {
							throw new Exception('cannot do yymore for the last token');
						}
						$yysubmatches = array();
						if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
							  substr($this->input, $this->counter), $yymatches)) {
							$yysubmatches = $yymatches;
							$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
							next($yymatches); // skip global match
							$this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
							$this->value = current($yymatches); // token value
							$this->line = substr_count($this->value, "\n");
							if ($tokenMap[$this->token]) {
								// extract sub-patterns for passing to lex function
								$yysubmatches = array_slice($yysubmatches, $this->token + 1,
									$tokenMap[$this->token]);
							} else {
								$yysubmatches = array();
							}
						}
						$r = $this->{'yy_r1_' . $this->token}($yysubmatches);
					} while ($r !== null && !is_bool($r));
					if ($r === true) {
						// we have changed state
						// process this token in the new state
						return $this->yylex();
					} elseif ($r === false) {
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						if ($this->counter >= strlen($this->input)) {
							return false; // end of input
						}
						// skip this token
						continue;
					} else {
						// accept
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						return true;
					}
				}
			} else {
				throw new Exception('Unexpected input at line ' . $this->line .
					': ' . var_export(substr($this->input, $this->counter), TRUE) . "\nStack:\n" . print_r($this->yyreflectstack(), TRUE));
			}
			break;
		} while (true);

	} // end function


	const NEWLINESTATE = 1;
    function yy_r1_1($yy_subpatterns)
	{

	$this->openTag('heading');
	$this->tip->data[0] = $this->tip->data[1] = strlen(trim($this->value));
	$this->yypushstate(self::HEADINGSTATE);
    }
    function yy_r1_2($yy_subpatterns)
	{
 $this->openTag('hr');     }
    function yy_r1_3($yy_subpatterns)
	{
 $this->openTag('div');     }
    function yy_r1_5($yy_subpatterns)
	{
 $this->closeTag('div');     }
    function yy_r1_7($yy_subpatterns)
	{

	$this->handleBlockStart($this->value);
	$this->yypushstate(self::BLOCKSTATE);
    }
    function yy_r1_10($yy_subpatterns)
	{

	$this->openParagraph();
	return true;
    }
    function yy_r1_11($yy_subpatterns)
	{

	$this->openParagraph();
	return true;
    }
    function yy_r1_12($yy_subpatterns)
	{

	$value = str_replace("\t", "    ", $this->value);
	
	$this->cache['list_ws_stack'] = array();
	$this->cache['list_ws_stack'][] = strlen($value) - strlen(ltrim($value));
	
	$type = trim($value);
	$type = preg_replace('#\d+\.#', '#', $type);
	$this->openTag($type == '#' ? 'ol' : 'ul');
	$this->openTag('li');
	$this->yypushstate(self::LISTSTATE);
    }
    function yy_r1_15($yy_subpatterns)
	{

	$this->openTag('dl');
	$this->openTag('dt');
	$this->yypushstate(self::DEFLISTTERMSTATE);
    }
    function yy_r1_16($yy_subpatterns)
	{

	$this->openTag('table');
	$this->cache['table_line'] = -1;
	$this->yypushstate(self::TABLESTATE);
	return true;
    }
    function yy_r1_18($yy_subpatterns)
	{

	$this->openParagraph();
	return true;
    }
    function yy_r1_20($yy_subpatterns)
	{

	for ($i=0; $i < strlen($this->value); $i++) {
		$this->openTag('blockquote');
	}
	$this->cache['blockquote_depth'] = strlen($this->value);
	$this->cache['blockquote_content'] = '';
	$this->yypushstate(self::BLOCKQUOTESTATE);
    }
    function yy_r1_22($yy_subpatterns)
	{
 return FALSE;     }
    function yy_r1_23($yy_subpatterns)
	{

	$this->openParagraph();
	return true;
    }


	function yylex2()
	{
		$tokenMap = array (
              1 => 0,
              2 => 4,
              7 => 0,
              8 => 0,
              9 => 0,
              10 => 1,
              12 => 0,
              13 => 0,
              14 => 0,
              15 => 0,
              16 => 0,
              17 => 0,
              18 => 2,
              21 => 0,
              22 => 0,
              23 => 0,
              24 => 0,
            );
		if ($this->counter >= strlen($this->input)) {
			return false; // end of input
		}
		$yy_global_pattern = "/^(~.)|^(<<\\s*(?=\\w+(\\s+\\w+(=([a-zA-Z0-9]+|'(\\\\'|[^']+)*'|\"(?:\\\\\"|[^\"]+)*\"))?)*>>))|^(\\[)|^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\s*#[\w+_-]+)|^([ \t]*=+[ \t]*)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])/";

		do {
			if (preg_match($yy_global_pattern, substr($this->input, $this->counter), $yymatches)) {
				$yysubmatches = $yymatches;
				$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
				if (!count($yymatches)) {
					throw new Exception('Error: lexing failed because a rule matched' .
						'an empty string.  Input "' . substr($this->input,
						$this->counter, 5) . '... state HEADINGSTATE');
				}
				next($yymatches); // skip global match
				$this->token = key($yymatches); // token number
				if ($tokenMap[$this->token]) {
					// extract sub-patterns for passing to lex function
					$yysubmatches = array_slice($yysubmatches, $this->token + 1,
						$tokenMap[$this->token]);
				} else {
					$yysubmatches = array();
				}
				$this->value = current($yymatches); // token value
				$r = $this->{'yy_r2_' . $this->token}($yysubmatches);
				if ($r === null) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					// accept this token
					return true;
				} elseif ($r === true) {
					// we have changed state
					// process this token in the new state
					return $this->yylex();
				} elseif ($r === false) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					if ($this->counter >= strlen($this->input)) {
						return false; // end of input
					}
					// skip this token
					continue;
				} else {                    $yy_yymore_patterns = array(
        1 => array(0, "^(<<\\s*(?=\\w+(\\s+\\w+(=([a-zA-Z0-9]+|'(\\\\'|[^']+)*'|\"(?:\\\\\"|[^\"]+)*\"))?)*>>))|^(\\[)|^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\s*#[\w+_-]+)|^([ \t]*=+[ \t]*)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        2 => array(4, "^(\\[)|^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\s*#[\w+_-]+)|^([ \t]*=+[ \t]*)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        7 => array(4, "^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\s*#[\w+_-]+)|^([ \t]*=+[ \t]*)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        8 => array(4, "^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\s*#[\w+_-]+)|^([ \t]*=+[ \t]*)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        9 => array(4, "^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\s*#[\w+_-]+)|^([ \t]*=+[ \t]*)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        10 => array(5, "^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\s*#[\w+_-]+)|^([ \t]*=+[ \t]*)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        12 => array(5, "^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\s*#[\w+_-]+)|^([ \t]*=+[ \t]*)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        13 => array(5, "^(##)|^(\\^\\^)|^(,,)|^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\s*#[\w+_-]+)|^([ \t]*=+[ \t]*)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        14 => array(5, "^(\\^\\^)|^(,,)|^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\s*#[\w+_-]+)|^([ \t]*=+[ \t]*)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        15 => array(5, "^(,,)|^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\s*#[\w+_-]+)|^([ \t]*=+[ \t]*)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        16 => array(5, "^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\s*#[\w+_-]+)|^([ \t]*=+[ \t]*)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        17 => array(5, "^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\s*#[\w+_-]+)|^([ \t]*=+[ \t]*)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        18 => array(7, "^(\\s*#[\w+_-]+)|^([ \t]*=+[ \t]*)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        21 => array(7, "^([ \t]*=+[ \t]*)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        22 => array(7, "^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        23 => array(7, "^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        24 => array(7, ""),
    );

					// yymore is needed
					do {
						if (!strlen($yy_yymore_patterns[$this->token][1])) {
							throw new Exception('cannot do yymore for the last token');
						}
						$yysubmatches = array();
						if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
							  substr($this->input, $this->counter), $yymatches)) {
							$yysubmatches = $yymatches;
							$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
							next($yymatches); // skip global match
							$this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
							$this->value = current($yymatches); // token value
							$this->line = substr_count($this->value, "\n");
							if ($tokenMap[$this->token]) {
								// extract sub-patterns for passing to lex function
								$yysubmatches = array_slice($yysubmatches, $this->token + 1,
									$tokenMap[$this->token]);
							} else {
								$yysubmatches = array();
							}
						}
						$r = $this->{'yy_r2_' . $this->token}($yysubmatches);
					} while ($r !== null && !is_bool($r));
					if ($r === true) {
						// we have changed state
						// process this token in the new state
						return $this->yylex();
					} elseif ($r === false) {
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						if ($this->counter >= strlen($this->input)) {
							return false; // end of input
						}
						// skip this token
						continue;
					} else {
						// accept
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						return true;
					}
				}
			} else {
				throw new Exception('Unexpected input at line ' . $this->line .
					': ' . var_export(substr($this->input, $this->counter), TRUE) . "\nStack:\n" . print_r($this->yyreflectstack(), TRUE));
			}
			break;
		} while (true);

	} // end function


	const HEADINGSTATE = 2;
    function yy_r2_1($yy_subpatterns)
	{
 $this->handleString(substr($this->value, 1));     }
    function yy_r2_2($yy_subpatterns)
	{
 $this->openPlugin();     }
    function yy_r2_7($yy_subpatterns)
	{

	$this->openTag('link');
	$this->yypushstate(self::LINKSTATE);
    }
    function yy_r2_8($yy_subpatterns)
	{
 $this->handleURL($this->value);     }
    function yy_r2_9($yy_subpatterns)
	{
 $this->handleDomain($this->value);     }
    function yy_r2_10($yy_subpatterns)
	{
 $this->handleEmail($this->value);     }
    function yy_r2_12($yy_subpatterns)
	{
 $this->handleTag('bold');     }
    function yy_r2_13($yy_subpatterns)
	{
 $this->handleTag('italic');     }
    function yy_r2_14($yy_subpatterns)
	{
 $this->handleTag('mono');     }
    function yy_r2_15($yy_subpatterns)
	{
 $this->handleTag('sup');     }
    function yy_r2_16($yy_subpatterns)
	{
 $this->handleTag('sub');     }
    function yy_r2_17($yy_subpatterns)
	{
 $this->handleTag('underline');     }
    function yy_r2_18($yy_subpatterns)
	{

	$this->openTag('nowiki');
	$this->yypushstate(self::NOWIKISTATE);
    }
    function yy_r2_21($yy_subpatterns)
	{

	$this->tip->attr['id'] = substr(trim($this->value), 1);
    }
    function yy_r2_22($yy_subpatterns)
	{
 return false;     }
    function yy_r2_23($yy_subpatterns)
	{

	$this->generateId($this->tip);
	$this->closeTag('heading');
	$this->yypopstate();
    }
    function yy_r2_24($yy_subpatterns)
	{
 $this->handleString($this->value);     }


	function yylex3()
	{
		$tokenMap = array (
              1 => 0,
              2 => 4,
              7 => 0,
              8 => 0,
              9 => 1,
              11 => 0,
              12 => 0,
              13 => 0,
              14 => 0,
              15 => 0,
              16 => 0,
              17 => 0,
              18 => 1,
              20 => 1,
              22 => 2,
              25 => 0,
              26 => 2,
              29 => 2,
              32 => 0,
              33 => 0,
              34 => 0,
              35 => 0,
            );
		if ($this->counter >= strlen($this->input)) {
			return false; // end of input
		}
		$yy_global_pattern = "/^(~.)|^(<<\\s*(?=\\w+(\\s+\\w+(=([a-zA-Z0-9]+|'(\\\\'|[^']+)*'|\"(?:\\\\\"|[^\"]+)*\"))?)*>>))|^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n{2,})|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])/";

		do {
			if (preg_match($yy_global_pattern, substr($this->input, $this->counter), $yymatches)) {
				$yysubmatches = $yymatches;
				$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
				if (!count($yymatches)) {
					throw new Exception('Error: lexing failed because a rule matched' .
						'an empty string.  Input "' . substr($this->input,
						$this->counter, 5) . '... state INLINESTATE');
				}
				next($yymatches); // skip global match
				$this->token = key($yymatches); // token number
				if ($tokenMap[$this->token]) {
					// extract sub-patterns for passing to lex function
					$yysubmatches = array_slice($yysubmatches, $this->token + 1,
						$tokenMap[$this->token]);
				} else {
					$yysubmatches = array();
				}
				$this->value = current($yymatches); // token value
				$r = $this->{'yy_r3_' . $this->token}($yysubmatches);
				if ($r === null) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					// accept this token
					return true;
				} elseif ($r === true) {
					// we have changed state
					// process this token in the new state
					return $this->yylex();
				} elseif ($r === false) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					if ($this->counter >= strlen($this->input)) {
						return false; // end of input
					}
					// skip this token
					continue;
				} else {                    $yy_yymore_patterns = array(
        1 => array(0, "^(<<\\s*(?=\\w+(\\s+\\w+(=([a-zA-Z0-9]+|'(\\\\'|[^']+)*'|\"(?:\\\\\"|[^\"]+)*\"))?)*>>))|^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n{2,})|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        2 => array(4, "^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n{2,})|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        7 => array(4, "^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n{2,})|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        8 => array(4, "^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n{2,})|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        9 => array(5, "^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n{2,})|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        11 => array(5, "^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n{2,})|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        12 => array(5, "^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n{2,})|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        13 => array(5, "^(\\^\\^)|^(,,)|^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n{2,})|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        14 => array(5, "^(,,)|^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n{2,})|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        15 => array(5, "^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n{2,})|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        16 => array(5, "^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n{2,})|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        17 => array(5, "^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n{2,})|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        18 => array(6, "^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n{2,})|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        20 => array(7, "^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n{2,})|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        22 => array(9, "^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n{2,})|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        25 => array(9, "^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n{2,})|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        26 => array(11, "^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n{2,})|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        29 => array(13, "^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n{2,})|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        32 => array(13, "^(\n{2,})|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        33 => array(13, "^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        34 => array(13, "^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        35 => array(13, ""),
    );

					// yymore is needed
					do {
						if (!strlen($yy_yymore_patterns[$this->token][1])) {
							throw new Exception('cannot do yymore for the last token');
						}
						$yysubmatches = array();
						if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
							  substr($this->input, $this->counter), $yymatches)) {
							$yysubmatches = $yymatches;
							$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
							next($yymatches); // skip global match
							$this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
							$this->value = current($yymatches); // token value
							$this->line = substr_count($this->value, "\n");
							if ($tokenMap[$this->token]) {
								// extract sub-patterns for passing to lex function
								$yysubmatches = array_slice($yysubmatches, $this->token + 1,
									$tokenMap[$this->token]);
							} else {
								$yysubmatches = array();
							}
						}
						$r = $this->{'yy_r3_' . $this->token}($yysubmatches);
					} while ($r !== null && !is_bool($r));
					if ($r === true) {
						// we have changed state
						// process this token in the new state
						return $this->yylex();
					} elseif ($r === false) {
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						if ($this->counter >= strlen($this->input)) {
							return false; // end of input
						}
						// skip this token
						continue;
					} else {
						// accept
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						return true;
					}
				}
			} else {
				throw new Exception('Unexpected input at line ' . $this->line .
					': ' . var_export(substr($this->input, $this->counter), TRUE) . "\nStack:\n" . print_r($this->yyreflectstack(), TRUE));
			}
			break;
		} while (true);

	} // end function


	const INLINESTATE = 3;
    function yy_r3_1($yy_subpatterns)
	{
 $this->handleString(substr($this->value, 1));     }
    function yy_r3_2($yy_subpatterns)
	{
 $this->openPlugin();      }
    function yy_r3_7($yy_subpatterns)
	{
 $this->handleURL($this->value);     }
    function yy_r3_8($yy_subpatterns)
	{
 $this->handleDomain($this->value);     }
    function yy_r3_9($yy_subpatterns)
	{
 $this->handleEmail($this->value);     }
    function yy_r3_11($yy_subpatterns)
	{
 $this->handleTag('bold');     }
    function yy_r3_12($yy_subpatterns)
	{
 $this->handleTag('italic');     }
    function yy_r3_13($yy_subpatterns)
	{
 $this->handleTag('mono');     }
    function yy_r3_14($yy_subpatterns)
	{
 $this->handleTag('sup');     }
    function yy_r3_15($yy_subpatterns)
	{
 $this->handleTag('sub');     }
    function yy_r3_16($yy_subpatterns)
	{
 $this->handleTag('underline');     }
    function yy_r3_17($yy_subpatterns)
	{
 $this->openTag('br');     }
    function yy_r3_18($yy_subpatterns)
	{
 $this->handleFlourishClass($this->value);     }
    function yy_r3_20($yy_subpatterns)
	{
 $this->handleFlourishMethod($this->value);     }
    function yy_r3_22($yy_subpatterns)
	{
 $this->handleFlourishClassMethod($this->value);     }
    function yy_r3_25($yy_subpatterns)
	{

	$this->openTag('link');
	$this->yypushstate(self::LINKSTATE);
    }
    function yy_r3_26($yy_subpatterns)
	{

	$this->closeTag('p');
	$this->handleBlockStart($this->value);
	$this->yypushstate(self::BLOCKSTATE);
    }
    function yy_r3_29($yy_subpatterns)
	{

	$this->openTag('nowiki');
	$this->yypushstate(self::NOWIKISTATE);
    }
    function yy_r3_32($yy_subpatterns)
	{
 $this->handleImage($this->value);     }
    function yy_r3_33($yy_subpatterns)
	{
 $this->closeTag('p'); $this->yypopstate();     }
    function yy_r3_34($yy_subpatterns)
	{
 $this->yypopstate();     }
    function yy_r3_35($yy_subpatterns)
	{
 $this->handleString($this->value);     }


	function yylex4()
	{
		$tokenMap = array (
              1 => 0,
              2 => 0,
              3 => 1,
              5 => 0,
            );
		if ($this->counter >= strlen($this->input)) {
			return false; // end of input
		}
		$yy_global_pattern = "/^(~.)|^(\n{2,})|^(\n(:+|>+)?)|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])/";

		do {
			if (preg_match($yy_global_pattern, substr($this->input, $this->counter), $yymatches)) {
				$yysubmatches = $yymatches;
				$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
				if (!count($yymatches)) {
					throw new Exception('Error: lexing failed because a rule matched' .
						'an empty string.  Input "' . substr($this->input,
						$this->counter, 5) . '... state BLOCKQUOTESTATE');
				}
				next($yymatches); // skip global match
				$this->token = key($yymatches); // token number
				if ($tokenMap[$this->token]) {
					// extract sub-patterns for passing to lex function
					$yysubmatches = array_slice($yysubmatches, $this->token + 1,
						$tokenMap[$this->token]);
				} else {
					$yysubmatches = array();
				}
				$this->value = current($yymatches); // token value
				$r = $this->{'yy_r4_' . $this->token}($yysubmatches);
				if ($r === null) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					// accept this token
					return true;
				} elseif ($r === true) {
					// we have changed state
					// process this token in the new state
					return $this->yylex();
				} elseif ($r === false) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					if ($this->counter >= strlen($this->input)) {
						return false; // end of input
					}
					// skip this token
					continue;
				} else {                    $yy_yymore_patterns = array(
        1 => array(0, "^(\n{2,})|^(\n(:+|>+)?)|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        2 => array(0, "^(\n(:+|>+)?)|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        3 => array(1, "^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        5 => array(1, ""),
    );

					// yymore is needed
					do {
						if (!strlen($yy_yymore_patterns[$this->token][1])) {
							throw new Exception('cannot do yymore for the last token');
						}
						$yysubmatches = array();
						if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
							  substr($this->input, $this->counter), $yymatches)) {
							$yysubmatches = $yymatches;
							$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
							next($yymatches); // skip global match
							$this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
							$this->value = current($yymatches); // token value
							$this->line = substr_count($this->value, "\n");
							if ($tokenMap[$this->token]) {
								// extract sub-patterns for passing to lex function
								$yysubmatches = array_slice($yysubmatches, $this->token + 1,
									$tokenMap[$this->token]);
							} else {
								$yysubmatches = array();
							}
						}
						$r = $this->{'yy_r4_' . $this->token}($yysubmatches);
					} while ($r !== null && !is_bool($r));
					if ($r === true) {
						// we have changed state
						// process this token in the new state
						return $this->yylex();
					} elseif ($r === false) {
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						if ($this->counter >= strlen($this->input)) {
							return false; // end of input
						}
						// skip this token
						continue;
					} else {
						// accept
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						return true;
					}
				}
			} else {
				throw new Exception('Unexpected input at line ' . $this->line .
					': ' . var_export(substr($this->input, $this->counter), TRUE) . "\nStack:\n" . print_r($this->yyreflectstack(), TRUE));
			}
			break;
		} while (true);

	} // end function


	const BLOCKQUOTESTATE = 4;
    function yy_r4_1($yy_subpatterns)
	{
 $this->cache['blockquote_content'] .= $this->value;     }
    function yy_r4_2($yy_subpatterns)
	{

	$this->handleBlockquote();
	while ($this->cache['blockquote_depth']--) {
		$this->closeTag('blockquote');
	}
	unset($this->cache['blockquote_depth']);
	$this->yypopstate();
    }
    function yy_r4_3($yy_subpatterns)
	{

	$new_depth = trim($this->value) ? strlen(trim($this->value)) : $this->cache['blockquote_depth'];
	if ($new_depth > $this->cache['blockquote_depth']) {
		$this->handleBlockquote();
		while ($new_depth > $this->cache['blockquote_depth']) {
			$this->openTag('blockquote');
			$this->cache['blockquote_depth']++;
		}
	} elseif ($new_depth < $this->cache['blockquote_depth']) {
		$this->handleBlockquote();
		while ($new_depth < $this->cache['blockquote_depth']) {
			$this->closeTag('blockquote');
			$this->cache['blockquote_depth']--;
		}
	} else {
		$this->cache['blockquote_content'] .= "\n";
	}
    }
    function yy_r4_5($yy_subpatterns)
	{
	$this->cache['blockquote_content'] .= $this->value;     }


	function yylex5()
	{
		$tokenMap = array (
              1 => 0,
              2 => 0,
              3 => 1,
            );
		if ($this->counter >= strlen($this->input)) {
			return false; // end of input
		}
		$yy_global_pattern = "/^(\\s*\\]+)|^(\\s+)|^(((?!\\]| ).)+)/";

		do {
			if (preg_match($yy_global_pattern, substr($this->input, $this->counter), $yymatches)) {
				$yysubmatches = $yymatches;
				$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
				if (!count($yymatches)) {
					throw new Exception('Error: lexing failed because a rule matched' .
						'an empty string.  Input "' . substr($this->input,
						$this->counter, 5) . '... state LINKSTATE');
				}
				next($yymatches); // skip global match
				$this->token = key($yymatches); // token number
				if ($tokenMap[$this->token]) {
					// extract sub-patterns for passing to lex function
					$yysubmatches = array_slice($yysubmatches, $this->token + 1,
						$tokenMap[$this->token]);
				} else {
					$yysubmatches = array();
				}
				$this->value = current($yymatches); // token value
				$r = $this->{'yy_r5_' . $this->token}($yysubmatches);
				if ($r === null) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					// accept this token
					return true;
				} elseif ($r === true) {
					// we have changed state
					// process this token in the new state
					return $this->yylex();
				} elseif ($r === false) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					if ($this->counter >= strlen($this->input)) {
						return false; // end of input
					}
					// skip this token
					continue;
				} else {                    $yy_yymore_patterns = array(
        1 => array(0, "^(\\s+)|^(((?!\\]| ).)+)"),
        2 => array(0, "^(((?!\\]| ).)+)"),
        3 => array(1, ""),
    );

					// yymore is needed
					do {
						if (!strlen($yy_yymore_patterns[$this->token][1])) {
							throw new Exception('cannot do yymore for the last token');
						}
						$yysubmatches = array();
						if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
							  substr($this->input, $this->counter), $yymatches)) {
							$yysubmatches = $yymatches;
							$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
							next($yymatches); // skip global match
							$this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
							$this->value = current($yymatches); // token value
							$this->line = substr_count($this->value, "\n");
							if ($tokenMap[$this->token]) {
								// extract sub-patterns for passing to lex function
								$yysubmatches = array_slice($yysubmatches, $this->token + 1,
									$tokenMap[$this->token]);
							} else {
								$yysubmatches = array();
							}
						}
						$r = $this->{'yy_r5_' . $this->token}($yysubmatches);
					} while ($r !== null && !is_bool($r));
					if ($r === true) {
						// we have changed state
						// process this token in the new state
						return $this->yylex();
					} elseif ($r === false) {
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						if ($this->counter >= strlen($this->input)) {
							return false; // end of input
						}
						// skip this token
						continue;
					} else {
						// accept
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						return true;
					}
				}
			} else {
				throw new Exception('Unexpected input at line ' . $this->line .
					': ' . var_export(substr($this->input, $this->counter), TRUE) . "\nStack:\n" . print_r($this->yyreflectstack(), TRUE));
			}
			break;
		} while (true);

	} // end function


	const LINKSTATE = 5;
    function yy_r5_1($yy_subpatterns)
	{

	$this->handleString($this->tip->attr['href']);
	$this->handleString(substr($this->value, 0, -2));
	$this->closeTag('link');
	$this->yypopstate();
    }
    function yy_r5_2($yy_subpatterns)
	{

	$this->yypopstate();
	$this->yypushstate(self::LINKTEXTSTATE);
    }
    function yy_r5_3($yy_subpatterns)
	{
$this->tip->attr['href'] = $this->encode(trim($this->value));     }


	function yylex6()
	{
		$tokenMap = array (
              1 => 0,
              2 => 0,
              3 => 0,
              4 => 0,
              5 => 1,
              7 => 0,
              8 => 0,
              9 => 0,
              10 => 0,
              11 => 0,
              12 => 0,
              13 => 2,
              16 => 0,
              17 => 0,
              18 => 0,
            );
		if ($this->counter >= strlen($this->input)) {
			return false; // end of input
		}
		$yy_global_pattern = "/^(\\s*\\]+)|^(~.)|^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])/";

		do {
			if (preg_match($yy_global_pattern, substr($this->input, $this->counter), $yymatches)) {
				$yysubmatches = $yymatches;
				$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
				if (!count($yymatches)) {
					throw new Exception('Error: lexing failed because a rule matched' .
						'an empty string.  Input "' . substr($this->input,
						$this->counter, 5) . '... state LINKTEXTSTATE');
				}
				next($yymatches); // skip global match
				$this->token = key($yymatches); // token number
				if ($tokenMap[$this->token]) {
					// extract sub-patterns for passing to lex function
					$yysubmatches = array_slice($yysubmatches, $this->token + 1,
						$tokenMap[$this->token]);
				} else {
					$yysubmatches = array();
				}
				$this->value = current($yymatches); // token value
				$r = $this->{'yy_r6_' . $this->token}($yysubmatches);
				if ($r === null) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					// accept this token
					return true;
				} elseif ($r === true) {
					// we have changed state
					// process this token in the new state
					return $this->yylex();
				} elseif ($r === false) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					if ($this->counter >= strlen($this->input)) {
						return false; // end of input
					}
					// skip this token
					continue;
				} else {                    $yy_yymore_patterns = array(
        1 => array(0, "^(~.)|^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        2 => array(0, "^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        3 => array(0, "^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        4 => array(0, "^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        5 => array(1, "^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        7 => array(1, "^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        8 => array(1, "^(##)|^(\\^\\^)|^(,,)|^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        9 => array(1, "^(\\^\\^)|^(,,)|^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        10 => array(1, "^(,,)|^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        11 => array(1, "^(__)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        12 => array(1, "^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        13 => array(3, "^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        16 => array(3, "^(\n)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        17 => array(3, "^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        18 => array(3, ""),
    );

					// yymore is needed
					do {
						if (!strlen($yy_yymore_patterns[$this->token][1])) {
							throw new Exception('cannot do yymore for the last token');
						}
						$yysubmatches = array();
						if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
							  substr($this->input, $this->counter), $yymatches)) {
							$yysubmatches = $yymatches;
							$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
							next($yymatches); // skip global match
							$this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
							$this->value = current($yymatches); // token value
							$this->line = substr_count($this->value, "\n");
							if ($tokenMap[$this->token]) {
								// extract sub-patterns for passing to lex function
								$yysubmatches = array_slice($yysubmatches, $this->token + 1,
									$tokenMap[$this->token]);
							} else {
								$yysubmatches = array();
							}
						}
						$r = $this->{'yy_r6_' . $this->token}($yysubmatches);
					} while ($r !== null && !is_bool($r));
					if ($r === true) {
						// we have changed state
						// process this token in the new state
						return $this->yylex();
					} elseif ($r === false) {
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						if ($this->counter >= strlen($this->input)) {
							return false; // end of input
						}
						// skip this token
						continue;
					} else {
						// accept
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						return true;
					}
				}
			} else {
				throw new Exception('Unexpected input at line ' . $this->line .
					': ' . var_export(substr($this->input, $this->counter), TRUE) . "\nStack:\n" . print_r($this->yyreflectstack(), TRUE));
			}
			break;
		} while (true);

	} // end function


	const LINKTEXTSTATE = 6;
    function yy_r6_1($yy_subpatterns)
	{

	$this->handleString(rtrim(substr($this->value, 0, -2)));
	$this->closeTag('link');
	$this->yypopstate();
    }
    function yy_r6_2($yy_subpatterns)
	{
 $this->handleString(substr($this->value, 1));     }
    function yy_r6_3($yy_subpatterns)
	{
 $this->handleString($this->value);     }
    function yy_r6_4($yy_subpatterns)
	{
 $this->handleString($this->value);     }
    function yy_r6_5($yy_subpatterns)
	{
 $this->handleString($this->value);     }
    function yy_r6_7($yy_subpatterns)
	{
 $this->handleTag('bold');     }
    function yy_r6_8($yy_subpatterns)
	{
 $this->handleTag('italic');     }
    function yy_r6_9($yy_subpatterns)
	{
 $this->handleTag('mono');     }
    function yy_r6_10($yy_subpatterns)
	{
 $this->handleTag('sup');     }
    function yy_r6_11($yy_subpatterns)
	{
 $this->handleTag('sub');     }
    function yy_r6_12($yy_subpatterns)
	{
 $this->handleTag('underline');     }
    function yy_r6_13($yy_subpatterns)
	{

	$this->openTag('nowiki');
	$this->yypushstate(self::NOWIKISTATE);
    }
    function yy_r6_16($yy_subpatterns)
	{
 $this->handleImage($this->value);     }
    function yy_r6_17($yy_subpatterns)
	{
 $this->handleString($this->value);     }
    function yy_r6_18($yy_subpatterns)
	{
 $this->handleString($this->value);     }


	function yylex7()
	{
		$tokenMap = array (
              1 => 0,
            );
		if ($this->counter >= strlen($this->input)) {
			return false; // end of input
		}
		$yy_global_pattern = "/^([a-z]\\w*)/";

		do {
			if (preg_match($yy_global_pattern, substr($this->input, $this->counter), $yymatches)) {
				$yysubmatches = $yymatches;
				$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
				if (!count($yymatches)) {
					throw new Exception('Error: lexing failed because a rule matched' .
						'an empty string.  Input "' . substr($this->input,
						$this->counter, 5) . '... state PLUGINSTATE');
				}
				next($yymatches); // skip global match
				$this->token = key($yymatches); // token number
				if ($tokenMap[$this->token]) {
					// extract sub-patterns for passing to lex function
					$yysubmatches = array_slice($yysubmatches, $this->token + 1,
						$tokenMap[$this->token]);
				} else {
					$yysubmatches = array();
				}
				$this->value = current($yymatches); // token value
				$r = $this->{'yy_r7_' . $this->token}($yysubmatches);
				if ($r === null) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					// accept this token
					return true;
				} elseif ($r === true) {
					// we have changed state
					// process this token in the new state
					return $this->yylex();
				} elseif ($r === false) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					if ($this->counter >= strlen($this->input)) {
						return false; // end of input
					}
					// skip this token
					continue;
				} else {                    $yy_yymore_patterns = array(
        1 => array(0, ""),
    );

					// yymore is needed
					do {
						if (!strlen($yy_yymore_patterns[$this->token][1])) {
							throw new Exception('cannot do yymore for the last token');
						}
						$yysubmatches = array();
						if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
							  substr($this->input, $this->counter), $yymatches)) {
							$yysubmatches = $yymatches;
							$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
							next($yymatches); // skip global match
							$this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
							$this->value = current($yymatches); // token value
							$this->line = substr_count($this->value, "\n");
							if ($tokenMap[$this->token]) {
								// extract sub-patterns for passing to lex function
								$yysubmatches = array_slice($yysubmatches, $this->token + 1,
									$tokenMap[$this->token]);
							} else {
								$yysubmatches = array();
							}
						}
						$r = $this->{'yy_r7_' . $this->token}($yysubmatches);
					} while ($r !== null && !is_bool($r));
					if ($r === true) {
						// we have changed state
						// process this token in the new state
						return $this->yylex();
					} elseif ($r === false) {
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						if ($this->counter >= strlen($this->input)) {
							return false; // end of input
						}
						// skip this token
						continue;
					} else {
						// accept
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						return true;
					}
				}
			} else {
				throw new Exception('Unexpected input at line ' . $this->line .
					': ' . var_export(substr($this->input, $this->counter), TRUE) . "\nStack:\n" . print_r($this->yyreflectstack(), TRUE));
			}
			break;
		} while (true);

	} // end function


	const PLUGINSTATE = 7;
    function yy_r7_1($yy_subpatterns)
	{

	$this->cache['plugin_tag']->data[0] = $this->value;
	$this->cache['plugin_tag']->data[1] = array();
	$this->yypushstate(self::PLUGINATTRSTATE);
    }


	function yylex8()
	{
		$tokenMap = array (
              1 => 3,
              5 => 0,
            );
		if ($this->counter >= strlen($this->input)) {
			return false; // end of input
		}
		$yy_global_pattern = "/^(\\s+\\w+(=([a-zA-Z0-9]+|'(\\\\'|[^']+)*'|\"(?:\\\\\"|[^\"]+)*\"))?)|^(\\s*>>)/";

		do {
			if (preg_match($yy_global_pattern, substr($this->input, $this->counter), $yymatches)) {
				$yysubmatches = $yymatches;
				$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
				if (!count($yymatches)) {
					throw new Exception('Error: lexing failed because a rule matched' .
						'an empty string.  Input "' . substr($this->input,
						$this->counter, 5) . '... state PLUGINATTRSTATE');
				}
				next($yymatches); // skip global match
				$this->token = key($yymatches); // token number
				if ($tokenMap[$this->token]) {
					// extract sub-patterns for passing to lex function
					$yysubmatches = array_slice($yysubmatches, $this->token + 1,
						$tokenMap[$this->token]);
				} else {
					$yysubmatches = array();
				}
				$this->value = current($yymatches); // token value
				$r = $this->{'yy_r8_' . $this->token}($yysubmatches);
				if ($r === null) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					// accept this token
					return true;
				} elseif ($r === true) {
					// we have changed state
					// process this token in the new state
					return $this->yylex();
				} elseif ($r === false) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					if ($this->counter >= strlen($this->input)) {
						return false; // end of input
					}
					// skip this token
					continue;
				} else {                    $yy_yymore_patterns = array(
        1 => array(3, "^(\\s*>>)"),
        5 => array(3, ""),
    );

					// yymore is needed
					do {
						if (!strlen($yy_yymore_patterns[$this->token][1])) {
							throw new Exception('cannot do yymore for the last token');
						}
						$yysubmatches = array();
						if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
							  substr($this->input, $this->counter), $yymatches)) {
							$yysubmatches = $yymatches;
							$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
							next($yymatches); // skip global match
							$this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
							$this->value = current($yymatches); // token value
							$this->line = substr_count($this->value, "\n");
							if ($tokenMap[$this->token]) {
								// extract sub-patterns for passing to lex function
								$yysubmatches = array_slice($yysubmatches, $this->token + 1,
									$tokenMap[$this->token]);
							} else {
								$yysubmatches = array();
							}
						}
						$r = $this->{'yy_r8_' . $this->token}($yysubmatches);
					} while ($r !== null && !is_bool($r));
					if ($r === true) {
						// we have changed state
						// process this token in the new state
						return $this->yylex();
					} elseif ($r === false) {
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						if ($this->counter >= strlen($this->input)) {
							return false; // end of input
						}
						// skip this token
						continue;
					} else {
						// accept
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						return true;
					}
				}
			} else {
				throw new Exception('Unexpected input at line ' . $this->line .
					': ' . var_export(substr($this->input, $this->counter), TRUE) . "\nStack:\n" . print_r($this->yyreflectstack(), TRUE));
			}
			break;
		} while (true);

	} // end function


	const PLUGINATTRSTATE = 8;
    function yy_r8_1($yy_subpatterns)
	{

	preg_match('#\s+(\w+)(=([a-zA-Z0-9]+|\'(?:\\\'|[^\']+)*\'|"(?:\\"|[^"]+)*"))?#', $this->value, $match);
	if (isset($match[2]) && strlen($match[2])) {
		if ($match[3][0] == '"') {
			$value = str_replace('\\"', '"', substr($match[3], 1, -1));
		} elseif ($match[3][0] == "'") {
			$value = str_replace("\\'", "'", substr($match[3], 1, -1));
		} else {
			$value = $match[3];
		}
	} elseif (!isset($match[2]) || !strlen($match[2])) {
		$value = TRUE;
	}
	$this->cache['plugin_tag']->data[1][$match[1]] = $value;
    }
    function yy_r8_5($yy_subpatterns)
	{

	unset($this->cache['plugin_tag']);
	$this->yypopstate();
	$this->yypopstate();
    }


	function yylex9()
	{
		$tokenMap = array (
              1 => 1,
              3 => 0,
              4 => 1,
            );
		if ($this->counter >= strlen($this->input)) {
			return false; // end of input
		}
		$yy_global_pattern = "/^(((?!\\}\\}\\}|`).)+)|^(\n)|^((\\}\\}\\}+|`))/";

		do {
			if (preg_match($yy_global_pattern, substr($this->input, $this->counter), $yymatches)) {
				$yysubmatches = $yymatches;
				$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
				if (!count($yymatches)) {
					throw new Exception('Error: lexing failed because a rule matched' .
						'an empty string.  Input "' . substr($this->input,
						$this->counter, 5) . '... state NOWIKISTATE');
				}
				next($yymatches); // skip global match
				$this->token = key($yymatches); // token number
				if ($tokenMap[$this->token]) {
					// extract sub-patterns for passing to lex function
					$yysubmatches = array_slice($yysubmatches, $this->token + 1,
						$tokenMap[$this->token]);
				} else {
					$yysubmatches = array();
				}
				$this->value = current($yymatches); // token value
				$r = $this->{'yy_r9_' . $this->token}($yysubmatches);
				if ($r === null) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					// accept this token
					return true;
				} elseif ($r === true) {
					// we have changed state
					// process this token in the new state
					return $this->yylex();
				} elseif ($r === false) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					if ($this->counter >= strlen($this->input)) {
						return false; // end of input
					}
					// skip this token
					continue;
				} else {                    $yy_yymore_patterns = array(
        1 => array(1, "^(\n)|^((\\}\\}\\}+|`))"),
        3 => array(1, "^((\\}\\}\\}+|`))"),
        4 => array(2, ""),
    );

					// yymore is needed
					do {
						if (!strlen($yy_yymore_patterns[$this->token][1])) {
							throw new Exception('cannot do yymore for the last token');
						}
						$yysubmatches = array();
						if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
							  substr($this->input, $this->counter), $yymatches)) {
							$yysubmatches = $yymatches;
							$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
							next($yymatches); // skip global match
							$this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
							$this->value = current($yymatches); // token value
							$this->line = substr_count($this->value, "\n");
							if ($tokenMap[$this->token]) {
								// extract sub-patterns for passing to lex function
								$yysubmatches = array_slice($yysubmatches, $this->token + 1,
									$tokenMap[$this->token]);
							} else {
								$yysubmatches = array();
							}
						}
						$r = $this->{'yy_r9_' . $this->token}($yysubmatches);
					} while ($r !== null && !is_bool($r));
					if ($r === true) {
						// we have changed state
						// process this token in the new state
						return $this->yylex();
					} elseif ($r === false) {
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						if ($this->counter >= strlen($this->input)) {
							return false; // end of input
						}
						// skip this token
						continue;
					} else {
						// accept
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						return true;
					}
				}
			} else {
				throw new Exception('Unexpected input at line ' . $this->line .
					': ' . var_export(substr($this->input, $this->counter), TRUE) . "\nStack:\n" . print_r($this->yyreflectstack(), TRUE));
			}
			break;
		} while (true);

	} // end function


	const NOWIKISTATE = 9;
    function yy_r9_1($yy_subpatterns)
	{
 $this->handleString($this->value);     }
    function yy_r9_3($yy_subpatterns)
	{
 $this->handleString($this->value);     }
    function yy_r9_4($yy_subpatterns)
	{

	$this->handleString(substr($this->value, 0, -3));
	$this->closeTag('nowiki');
	$this->yypopstate();
    }


	function yylex10()
	{
		$tokenMap = array (
              1 => 0,
              2 => 0,
              3 => 0,
            );
		if ($this->counter >= strlen($this->input)) {
			return false; // end of input
		}
		$yy_global_pattern = "/^(\n\\}\\}\\})|^(\n)|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])/";

		do {
			if (preg_match($yy_global_pattern, substr($this->input, $this->counter), $yymatches)) {
				$yysubmatches = $yymatches;
				$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
				if (!count($yymatches)) {
					throw new Exception('Error: lexing failed because a rule matched' .
						'an empty string.  Input "' . substr($this->input,
						$this->counter, 5) . '... state BLOCKSTATE');
				}
				next($yymatches); // skip global match
				$this->token = key($yymatches); // token number
				if ($tokenMap[$this->token]) {
					// extract sub-patterns for passing to lex function
					$yysubmatches = array_slice($yysubmatches, $this->token + 1,
						$tokenMap[$this->token]);
				} else {
					$yysubmatches = array();
				}
				$this->value = current($yymatches); // token value
				$r = $this->{'yy_r10_' . $this->token}($yysubmatches);
				if ($r === null) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					// accept this token
					return true;
				} elseif ($r === true) {
					// we have changed state
					// process this token in the new state
					return $this->yylex();
				} elseif ($r === false) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					if ($this->counter >= strlen($this->input)) {
						return false; // end of input
					}
					// skip this token
					continue;
				} else {                    $yy_yymore_patterns = array(
        1 => array(0, "^(\n)|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        2 => array(0, "^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        3 => array(0, ""),
    );

					// yymore is needed
					do {
						if (!strlen($yy_yymore_patterns[$this->token][1])) {
							throw new Exception('cannot do yymore for the last token');
						}
						$yysubmatches = array();
						if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
							  substr($this->input, $this->counter), $yymatches)) {
							$yysubmatches = $yymatches;
							$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
							next($yymatches); // skip global match
							$this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
							$this->value = current($yymatches); // token value
							$this->line = substr_count($this->value, "\n");
							if ($tokenMap[$this->token]) {
								// extract sub-patterns for passing to lex function
								$yysubmatches = array_slice($yysubmatches, $this->token + 1,
									$tokenMap[$this->token]);
							} else {
								$yysubmatches = array();
							}
						}
						$r = $this->{'yy_r10_' . $this->token}($yysubmatches);
					} while ($r !== null && !is_bool($r));
					if ($r === true) {
						// we have changed state
						// process this token in the new state
						return $this->yylex();
					} elseif ($r === false) {
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						if ($this->counter >= strlen($this->input)) {
							return false; // end of input
						}
						// skip this token
						continue;
					} else {
						// accept
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						return true;
					}
				}
			} else {
				throw new Exception('Unexpected input at line ' . $this->line .
					': ' . var_export(substr($this->input, $this->counter), TRUE) . "\nStack:\n" . print_r($this->yyreflectstack(), TRUE));
			}
			break;
		} while (true);

	} // end function


	const BLOCKSTATE = 10;
    function yy_r10_1($yy_subpatterns)
	{

	$this->handleBlockEnd();
	$this->openTag('p');
	$this->yypopstate();
    }
    function yy_r10_2($yy_subpatterns)
	{
 $this->handleString($this->value, $this->cache['block_encode']);     }
    function yy_r10_3($yy_subpatterns)
	{
 $this->handleString($this->value, $this->cache['block_encode']);     }


	function yylex11()
	{
		$tokenMap = array (
              1 => 0,
              2 => 0,
              3 => 4,
              8 => 0,
              9 => 0,
              10 => 1,
              12 => 0,
              13 => 0,
              14 => 0,
              15 => 0,
              16 => 0,
              17 => 0,
              18 => 1,
              20 => 1,
              22 => 2,
              25 => 0,
              26 => 2,
              29 => 0,
              30 => 0,
            );
		if ($this->counter >= strlen($this->input)) {
			return false; // end of input
		}
		$yy_global_pattern = "/^(~.)|^(\n?[ \t]*:[ \t]*)|^(<<\\s*(?=\\w+(\\s+\\w+(=([a-zA-Z0-9]+|'(\\\\'|[^']+)*'|\"(?:\\\\\"|[^\"]+)*\"))?)*>>))|^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])/";

		do {
			if (preg_match($yy_global_pattern, substr($this->input, $this->counter), $yymatches)) {
				$yysubmatches = $yymatches;
				$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
				if (!count($yymatches)) {
					throw new Exception('Error: lexing failed because a rule matched' .
						'an empty string.  Input "' . substr($this->input,
						$this->counter, 5) . '... state DEFLISTTERMSTATE');
				}
				next($yymatches); // skip global match
				$this->token = key($yymatches); // token number
				if ($tokenMap[$this->token]) {
					// extract sub-patterns for passing to lex function
					$yysubmatches = array_slice($yysubmatches, $this->token + 1,
						$tokenMap[$this->token]);
				} else {
					$yysubmatches = array();
				}
				$this->value = current($yymatches); // token value
				$r = $this->{'yy_r11_' . $this->token}($yysubmatches);
				if ($r === null) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					// accept this token
					return true;
				} elseif ($r === true) {
					// we have changed state
					// process this token in the new state
					return $this->yylex();
				} elseif ($r === false) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					if ($this->counter >= strlen($this->input)) {
						return false; // end of input
					}
					// skip this token
					continue;
				} else {                    $yy_yymore_patterns = array(
        1 => array(0, "^(\n?[ \t]*:[ \t]*)|^(<<\\s*(?=\\w+(\\s+\\w+(=([a-zA-Z0-9]+|'(\\\\'|[^']+)*'|\"(?:\\\\\"|[^\"]+)*\"))?)*>>))|^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        2 => array(0, "^(<<\\s*(?=\\w+(\\s+\\w+(=([a-zA-Z0-9]+|'(\\\\'|[^']+)*'|\"(?:\\\\\"|[^\"]+)*\"))?)*>>))|^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        3 => array(4, "^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        8 => array(4, "^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        9 => array(4, "^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        10 => array(5, "^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        12 => array(5, "^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        13 => array(5, "^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        14 => array(5, "^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        15 => array(5, "^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        16 => array(5, "^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        17 => array(5, "^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        18 => array(6, "^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        20 => array(7, "^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        22 => array(9, "^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        25 => array(9, "^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        26 => array(11, "^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        29 => array(11, "^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        30 => array(11, ""),
    );

					// yymore is needed
					do {
						if (!strlen($yy_yymore_patterns[$this->token][1])) {
							throw new Exception('cannot do yymore for the last token');
						}
						$yysubmatches = array();
						if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
							  substr($this->input, $this->counter), $yymatches)) {
							$yysubmatches = $yymatches;
							$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
							next($yymatches); // skip global match
							$this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
							$this->value = current($yymatches); // token value
							$this->line = substr_count($this->value, "\n");
							if ($tokenMap[$this->token]) {
								// extract sub-patterns for passing to lex function
								$yysubmatches = array_slice($yysubmatches, $this->token + 1,
									$tokenMap[$this->token]);
							} else {
								$yysubmatches = array();
							}
						}
						$r = $this->{'yy_r11_' . $this->token}($yysubmatches);
					} while ($r !== null && !is_bool($r));
					if ($r === true) {
						// we have changed state
						// process this token in the new state
						return $this->yylex();
					} elseif ($r === false) {
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						if ($this->counter >= strlen($this->input)) {
							return false; // end of input
						}
						// skip this token
						continue;
					} else {
						// accept
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						return true;
					}
				}
			} else {
				throw new Exception('Unexpected input at line ' . $this->line .
					': ' . var_export(substr($this->input, $this->counter), TRUE) . "\nStack:\n" . print_r($this->yyreflectstack(), TRUE));
			}
			break;
		} while (true);

	} // end function


	const DEFLISTTERMSTATE = 11;
    function yy_r11_1($yy_subpatterns)
	{
 $this->handleString(substr($this->value, 1));     }
    function yy_r11_2($yy_subpatterns)
	{

	$this->closeTag('dt');
	$this->openTag('dd');
	$this->yypopstate();
	$this->yypushstate(self::DEFLISTDEFSTATE); 
    }
    function yy_r11_3($yy_subpatterns)
	{
 $this->openPlugin();     }
    function yy_r11_8($yy_subpatterns)
	{
 $this->handleURL($this->value);     }
    function yy_r11_9($yy_subpatterns)
	{
 $this->handleDomain($this->value);     }
    function yy_r11_10($yy_subpatterns)
	{
 $this->handleEmail($this->value);     }
    function yy_r11_12($yy_subpatterns)
	{
 $this->handleTag('bold');     }
    function yy_r11_13($yy_subpatterns)
	{
 $this->handleTag('italic');     }
    function yy_r11_14($yy_subpatterns)
	{
 $this->handleTag('mono');     }
    function yy_r11_15($yy_subpatterns)
	{
 $this->handleTag('sup');     }
    function yy_r11_16($yy_subpatterns)
	{
 $this->handleTag('sub');     }
    function yy_r11_17($yy_subpatterns)
	{
 $this->handleTag('underline');     }
    function yy_r11_18($yy_subpatterns)
	{
 $this->handleFlourishClass($this->value);     }
    function yy_r11_20($yy_subpatterns)
	{
 $this->handleFlourishMethod($this->value);     }
    function yy_r11_22($yy_subpatterns)
	{
 $this->handleFlourishClassMethod($this->value);     }
    function yy_r11_25($yy_subpatterns)
	{

	$this->openTag('link');
	$this->yypushstate(self::LINKSTATE);
    }
    function yy_r11_26($yy_subpatterns)
	{

	$this->openTag('nowiki');
	$this->yypushstate(self::NOWIKISTATE);
    }
    function yy_r11_29($yy_subpatterns)
	{
 $this->handleImage($this->value);     }
    function yy_r11_30($yy_subpatterns)
	{
	$this->handleString($this->value);     }


	function yylex12()
	{
		$tokenMap = array (
              1 => 0,
              2 => 0,
              3 => 0,
              4 => 0,
              5 => 4,
              10 => 0,
              11 => 0,
              12 => 0,
              13 => 1,
              15 => 0,
              16 => 0,
              17 => 0,
              18 => 0,
              19 => 0,
              20 => 0,
              21 => 1,
              23 => 1,
              25 => 2,
              28 => 0,
              29 => 2,
              32 => 2,
              35 => 0,
              36 => 0,
            );
		if ($this->counter >= strlen($this->input)) {
			return false; // end of input
		}
		$yy_global_pattern = "/^(~.)|^(\n[ \t]*;[ \t]*)|^([ \t]*\n[ \t]*:[ \t]*)|^(\n+)|^(<<\\s*(?=\\w+(\\s+\\w+(=([a-zA-Z0-9]+|'(\\\\'|[^']+)*'|\"(?:\\\\\"|[^\"]+)*\"))?)*>>))|^([ \t]*-{4,}[ \t]*\n)|^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])/";

		do {
			if (preg_match($yy_global_pattern, substr($this->input, $this->counter), $yymatches)) {
				$yysubmatches = $yymatches;
				$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
				if (!count($yymatches)) {
					throw new Exception('Error: lexing failed because a rule matched' .
						'an empty string.  Input "' . substr($this->input,
						$this->counter, 5) . '... state DEFLISTDEFSTATE');
				}
				next($yymatches); // skip global match
				$this->token = key($yymatches); // token number
				if ($tokenMap[$this->token]) {
					// extract sub-patterns for passing to lex function
					$yysubmatches = array_slice($yysubmatches, $this->token + 1,
						$tokenMap[$this->token]);
				} else {
					$yysubmatches = array();
				}
				$this->value = current($yymatches); // token value
				$r = $this->{'yy_r12_' . $this->token}($yysubmatches);
				if ($r === null) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					// accept this token
					return true;
				} elseif ($r === true) {
					// we have changed state
					// process this token in the new state
					return $this->yylex();
				} elseif ($r === false) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					if ($this->counter >= strlen($this->input)) {
						return false; // end of input
					}
					// skip this token
					continue;
				} else {                    $yy_yymore_patterns = array(
        1 => array(0, "^(\n[ \t]*;[ \t]*)|^([ \t]*\n[ \t]*:[ \t]*)|^(\n+)|^(<<\\s*(?=\\w+(\\s+\\w+(=([a-zA-Z0-9]+|'(\\\\'|[^']+)*'|\"(?:\\\\\"|[^\"]+)*\"))?)*>>))|^([ \t]*-{4,}[ \t]*\n)|^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        2 => array(0, "^([ \t]*\n[ \t]*:[ \t]*)|^(\n+)|^(<<\\s*(?=\\w+(\\s+\\w+(=([a-zA-Z0-9]+|'(\\\\'|[^']+)*'|\"(?:\\\\\"|[^\"]+)*\"))?)*>>))|^([ \t]*-{4,}[ \t]*\n)|^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        3 => array(0, "^(\n+)|^(<<\\s*(?=\\w+(\\s+\\w+(=([a-zA-Z0-9]+|'(\\\\'|[^']+)*'|\"(?:\\\\\"|[^\"]+)*\"))?)*>>))|^([ \t]*-{4,}[ \t]*\n)|^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        4 => array(0, "^(<<\\s*(?=\\w+(\\s+\\w+(=([a-zA-Z0-9]+|'(\\\\'|[^']+)*'|\"(?:\\\\\"|[^\"]+)*\"))?)*>>))|^([ \t]*-{4,}[ \t]*\n)|^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        5 => array(4, "^([ \t]*-{4,}[ \t]*\n)|^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        10 => array(4, "^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        11 => array(4, "^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        12 => array(4, "^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        13 => array(5, "^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        15 => array(5, "^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        16 => array(5, "^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        17 => array(5, "^(\\^\\^)|^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        18 => array(5, "^(,,)|^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        19 => array(5, "^(__)|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        20 => array(5, "^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        21 => array(6, "^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        23 => array(7, "^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        25 => array(9, "^(\\[)|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        28 => array(9, "^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        29 => array(11, "^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        32 => array(13, "^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        35 => array(13, "^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        36 => array(13, ""),
    );

					// yymore is needed
					do {
						if (!strlen($yy_yymore_patterns[$this->token][1])) {
							throw new Exception('cannot do yymore for the last token');
						}
						$yysubmatches = array();
						if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
							  substr($this->input, $this->counter), $yymatches)) {
							$yysubmatches = $yymatches;
							$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
							next($yymatches); // skip global match
							$this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
							$this->value = current($yymatches); // token value
							$this->line = substr_count($this->value, "\n");
							if ($tokenMap[$this->token]) {
								// extract sub-patterns for passing to lex function
								$yysubmatches = array_slice($yysubmatches, $this->token + 1,
									$tokenMap[$this->token]);
							} else {
								$yysubmatches = array();
							}
						}
						$r = $this->{'yy_r12_' . $this->token}($yysubmatches);
					} while ($r !== null && !is_bool($r));
					if ($r === true) {
						// we have changed state
						// process this token in the new state
						return $this->yylex();
					} elseif ($r === false) {
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						if ($this->counter >= strlen($this->input)) {
							return false; // end of input
						}
						// skip this token
						continue;
					} else {
						// accept
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						return true;
					}
				}
			} else {
				throw new Exception('Unexpected input at line ' . $this->line .
					': ' . var_export(substr($this->input, $this->counter), TRUE) . "\nStack:\n" . print_r($this->yyreflectstack(), TRUE));
			}
			break;
		} while (true);

	} // end function


	const DEFLISTDEFSTATE = 12;
    function yy_r12_1($yy_subpatterns)
	{
 $this->handleString(substr($this->value, 1));     }
    function yy_r12_2($yy_subpatterns)
	{

	$this->closeTag('dd');
	$this->openTag('dt');
	$this->yypopstate();
	$this->yypushstate(self::DEFLISTTERMSTATE);
    }
    function yy_r12_3($yy_subpatterns)
	{

	$this->closeTag('dd');
	$this->openTag('dd');
    }
    function yy_r12_4($yy_subpatterns)
	{

	$this->closeTag('dd');
	$this->closeTag('dl');
	$this->yypopstate();
    }
    function yy_r12_5($yy_subpatterns)
	{
 $this->openPlugin();     }
    function yy_r12_10($yy_subpatterns)
	{
 $this->openTag('hr');     }
    function yy_r12_11($yy_subpatterns)
	{
 $this->handleURL($this->value);     }
    function yy_r12_12($yy_subpatterns)
	{
 $this->handleDomain($this->value);     }
    function yy_r12_13($yy_subpatterns)
	{
 $this->handleEmail($this->value);     }
    function yy_r12_15($yy_subpatterns)
	{
 $this->handleTag('bold');     }
    function yy_r12_16($yy_subpatterns)
	{
 $this->handleTag('italic');     }
    function yy_r12_17($yy_subpatterns)
	{
 $this->handleTag('mono');     }
    function yy_r12_18($yy_subpatterns)
	{
 $this->handleTag('sup');     }
    function yy_r12_19($yy_subpatterns)
	{
 $this->handleTag('sub');     }
    function yy_r12_20($yy_subpatterns)
	{
 $this->handleTag('underline');     }
    function yy_r12_21($yy_subpatterns)
	{
 $this->handleFlourishClass($this->value);     }
    function yy_r12_23($yy_subpatterns)
	{
 $this->handleFlourishMethod($this->value);     }
    function yy_r12_25($yy_subpatterns)
	{
 $this->handleFlourishClassMethod($this->value);     }
    function yy_r12_28($yy_subpatterns)
	{

	$this->openTag('link');
	$this->yypushstate(self::LINKSTATE);
    }
    function yy_r12_29($yy_subpatterns)
	{

	$dd_pos = $this->getOpenTagPos('dd');
	$dd     = $this->stack[$dd_pos];
	
	$iter = new ParserIterator($dd, 'child');
	$found_inline = FALSE;
	foreach ($iter as $child_tag) {
		if ($child_tag->name != 'p' && $child_tag->name != 'block') {
			$found_inline = TRUE;
		}
	}
		
	if ($found_inline) {
		$this->wrapChildren($dd, 'p');
	}
	
	$this->handleBlockStart($this->value);
	$this->yypushstate(self::BLOCKSTATE);
    }
    function yy_r12_32($yy_subpatterns)
	{

	$this->openTag('nowiki');
	$this->yypushstate(self::NOWIKISTATE);
    }
    function yy_r12_35($yy_subpatterns)
	{
 $this->handleImage($this->value);     }
    function yy_r12_36($yy_subpatterns)
	{
	$this->handleString($this->value);     }


	function yylex13()
	{
		$tokenMap = array (
              1 => 0,
              2 => 4,
              7 => 0,
              8 => 0,
              9 => 0,
              10 => 1,
              12 => 0,
              13 => 0,
              14 => 0,
              15 => 0,
              16 => 0,
              17 => 0,
              18 => 0,
              19 => 1,
              21 => 1,
              23 => 2,
              26 => 0,
              27 => 2,
              30 => 2,
              33 => 0,
              34 => 0,
              35 => 0,
              36 => 2,
              39 => 0,
              40 => 0,
            );
		if ($this->counter >= strlen($this->input)) {
			return false; // end of input
		}
		$yy_global_pattern = "/^(~.)|^(<<\\s*(?=\\w+(\\s+\\w+(=([a-zA-Z0-9]+|'(\\\\'|[^']+)*'|\"(?:\\\\\"|[^\"]+)*\"))?)*>>))|^([ \t]*-{4,}[ \t]*\n)|^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n[ \t]+\n[ \t]+)|^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])/";

		do {
			if (preg_match($yy_global_pattern, substr($this->input, $this->counter), $yymatches)) {
				$yysubmatches = $yymatches;
				$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
				if (!count($yymatches)) {
					throw new Exception('Error: lexing failed because a rule matched' .
						'an empty string.  Input "' . substr($this->input,
						$this->counter, 5) . '... state LISTSTATE');
				}
				next($yymatches); // skip global match
				$this->token = key($yymatches); // token number
				if ($tokenMap[$this->token]) {
					// extract sub-patterns for passing to lex function
					$yysubmatches = array_slice($yysubmatches, $this->token + 1,
						$tokenMap[$this->token]);
				} else {
					$yysubmatches = array();
				}
				$this->value = current($yymatches); // token value
				$r = $this->{'yy_r13_' . $this->token}($yysubmatches);
				if ($r === null) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					// accept this token
					return true;
				} elseif ($r === true) {
					// we have changed state
					// process this token in the new state
					return $this->yylex();
				} elseif ($r === false) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					if ($this->counter >= strlen($this->input)) {
						return false; // end of input
					}
					// skip this token
					continue;
				} else {                    $yy_yymore_patterns = array(
        1 => array(0, "^(<<\\s*(?=\\w+(\\s+\\w+(=([a-zA-Z0-9]+|'(\\\\'|[^']+)*'|\"(?:\\\\\"|[^\"]+)*\"))?)*>>))|^([ \t]*-{4,}[ \t]*\n)|^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n[ \t]+\n[ \t]+)|^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        2 => array(4, "^([ \t]*-{4,}[ \t]*\n)|^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n[ \t]+\n[ \t]+)|^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        7 => array(4, "^(https?:\/\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])|^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n[ \t]+\n[ \t]+)|^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        8 => array(4, "^(www\\.(?:[a-zA-Z0-9\-]+\\.)+[a-zA-Z]{2,}(?:\/[a-zA-Z0-9%$\-_.+!*;\/?:@=&'#,]+[a-zA-Z0-9$\-_+!*;\/?:@=&'#,])?)|^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n[ \t]+\n[ \t]+)|^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        9 => array(4, "^(([a-zA-Z0-9\\\\.+'_\\\\-]+@(?:[a-zA-Z0-9\\\\-]+\\.)+[a-zA-Z]{2,}))|^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n[ \t]+\n[ \t]+)|^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        10 => array(5, "^(''')|^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n[ \t]+\n[ \t]+)|^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        12 => array(5, "^('')|^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n[ \t]+\n[ \t]+)|^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        13 => array(5, "^(##)|^(\\^\\^)|^(,,)|^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n[ \t]+\n[ \t]+)|^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        14 => array(5, "^(\\^\\^)|^(,,)|^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n[ \t]+\n[ \t]+)|^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        15 => array(5, "^(,,)|^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n[ \t]+\n[ \t]+)|^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        16 => array(5, "^(__)|^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n[ \t]+\n[ \t]+)|^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        17 => array(5, "^(\\\\\\\\|\\[\\[BR\\]\\])|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n[ \t]+\n[ \t]+)|^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        18 => array(5, "^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)(?![:a-zA-Z0-9_]))|^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n[ \t]+\n[ \t]+)|^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        19 => array(6, "^(::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n[ \t]+\n[ \t]+)|^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        21 => array(7, "^(\\b(f[A-Z0-9][a-zA-Z0-9_]*)::([a-zA-Z_][a-zA-Z0-9_]*)\\(\\))|^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n[ \t]+\n[ \t]+)|^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        23 => array(9, "^(\\[)|^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n[ \t]+\n[ \t]+)|^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        26 => array(9, "^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n[ \t]+\n[ \t]+)|^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        27 => array(11, "^(\\{\\{\\{(\\s*#!([\w-]+)[ \t]*)?\n)|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n[ \t]+\n[ \t]+)|^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        30 => array(13, "^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\n[ \t]+\n[ \t]+)|^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        33 => array(13, "^(\n[ \t]+\n[ \t]+)|^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        34 => array(13, "^(\n[ \t]+(?![ \t*#+-]|\\d+\\.))|^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        35 => array(13, "^(\n[ \t]*([*#+-]+(\\d+\\.)?|\\d+\\.)[ \t]?)|^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        36 => array(15, "^(\n+)|^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        39 => array(15, "^([a-zA-Z0-9]+|[^a-zA-Z0-9\n\r])"),
        40 => array(15, ""),
    );

					// yymore is needed
					do {
						if (!strlen($yy_yymore_patterns[$this->token][1])) {
							throw new Exception('cannot do yymore for the last token');
						}
						$yysubmatches = array();
						if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
							  substr($this->input, $this->counter), $yymatches)) {
							$yysubmatches = $yymatches;
							$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
							next($yymatches); // skip global match
							$this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
							$this->value = current($yymatches); // token value
							$this->line = substr_count($this->value, "\n");
							if ($tokenMap[$this->token]) {
								// extract sub-patterns for passing to lex function
								$yysubmatches = array_slice($yysubmatches, $this->token + 1,
									$tokenMap[$this->token]);
							} else {
								$yysubmatches = array();
							}
						}
						$r = $this->{'yy_r13_' . $this->token}($yysubmatches);
					} while ($r !== null && !is_bool($r));
					if ($r === true) {
						// we have changed state
						// process this token in the new state
						return $this->yylex();
					} elseif ($r === false) {
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						if ($this->counter >= strlen($this->input)) {
							return false; // end of input
						}
						// skip this token
						continue;
					} else {
						// accept
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						return true;
					}
				}
			} else {
				throw new Exception('Unexpected input at line ' . $this->line .
					': ' . var_export(substr($this->input, $this->counter), TRUE) . "\nStack:\n" . print_r($this->yyreflectstack(), TRUE));
			}
			break;
		} while (true);

	} // end function


	const LISTSTATE = 13;
    function yy_r13_1($yy_subpatterns)
	{
 $this->handleString(substr($this->value, 1));     }
    function yy_r13_2($yy_subpatterns)
	{
 $this->openPlugin();     }
    function yy_r13_7($yy_subpatterns)
	{
 $this->openTag('hr');     }
    function yy_r13_8($yy_subpatterns)
	{
 $this->handleURL($this->value);     }
    function yy_r13_9($yy_subpatterns)
	{
 $this->handleDomain($this->value);     }
    function yy_r13_10($yy_subpatterns)
	{
 $this->handleEmail($this->value);     }
    function yy_r13_12($yy_subpatterns)
	{
 $this->handleTag('bold');     }
    function yy_r13_13($yy_subpatterns)
	{
 $this->handleTag('italic');     }
    function yy_r13_14($yy_subpatterns)
	{
 $this->handleTag('mono');     }
    function yy_r13_15($yy_subpatterns)
	{
 $this->handleTag('sup');     }
    function yy_r13_16($yy_subpatterns)
	{
 $this->handleTag('sub');     }
    function yy_r13_17($yy_subpatterns)
	{
 $this->handleTag('underline');     }
    function yy_r13_18($yy_subpatterns)
	{
 $this->openTag('br');     }
    function yy_r13_19($yy_subpatterns)
	{
 $this->handleFlourishClass($this->value);     }
    function yy_r13_21($yy_subpatterns)
	{
 $this->handleFlourishMethod($this->value);     }
    function yy_r13_23($yy_subpatterns)
	{
 $this->handleFlourishClassMethod($this->value);     }
    function yy_r13_26($yy_subpatterns)
	{

	$this->openTag('link');
	$this->yypushstate(self::LINKSTATE);
    }
    function yy_r13_27($yy_subpatterns)
	{

	$this->openTag('nowiki');
	$this->yypushstate(self::NOWIKISTATE);
    }
    function yy_r13_30($yy_subpatterns)
	{

	$li_pos = $this->getOpenTagPos('li');
	$li     = $this->stack[$li_pos];
	
	$iter = new ParserIterator($li, 'child');
	$found_inline = FALSE;
	foreach ($iter as $child_tag) {
		if ($child_tag->name != 'p' && $child_tag->name != 'block') {
			$found_inline = TRUE;
		}
	}
		
	if ($found_inline) {
		$this->wrapChildren($li, 'p');
	}
	
	$this->handleBlockStart($this->value);
	$this->yypushstate(self::BLOCKSTATE);
    }
    function yy_r13_33($yy_subpatterns)
	{
 $this->handleImage($this->value);     }
    function yy_r13_34($yy_subpatterns)
	{

	$li_pos = $this->getOpenTagPos('li');
	$li     = $this->stack[$li_pos];
	
	$iter = new ParserIterator($li, 'child');
	$found_inline = FALSE;
	foreach ($iter as $child_tag) {
		if ($child_tag->name != 'p' && $child_tag->name != 'block') {
			$found_inline = TRUE;
		}
	}
		
	if ($found_inline) {
		$this->wrapChildren($li, 'p');
	}
	
	$this->closeTag('p');
	$this->openTag('p');
    }
    function yy_r13_35($yy_subpatterns)
	{
 $this->handleString(' ');     }
    function yy_r13_36($yy_subpatterns)
	{

	$value = substr($this->value, 1);
	$value = str_replace("\t", "    ", $value);
	
	$indent_level = strlen($value) - strlen(ltrim($value));
	
	$old_lists = '';
	foreach ($this->stack as $tag) {
		if ($tag->name == 'ul') {
			$old_lists .= '*';
		} elseif ($tag->name == 'ol') {
			$old_lists .= '#';
		}
	}
	
	$value = str_replace(array('-', '+'), '*', $value);
	$value = preg_replace('#\d+\.#', '#', $value);
	
	if (strlen(trim($value)) == 1) {
		if ($indent_level > end($this->cache['list_ws_stack'])) {
			$this->cache['list_ws_stack'][] = $indent_level;
			$value = $old_lists . trim($value);
		} elseif ($indent_level < end($this->cache['list_ws_stack'])) {
			$bullet_prefix = substr($old_lists, 0, -1);
			while ($indent_level < end($this->cache['list_ws_stack'])) {
				$bullet_prefix = substr($bullet_prefix, 0, -1);
				array_pop($this->cache['list_ws_stack']);
			}
			$value = $bullet_prefix . trim($value);
		} elseif (count($this->cache['list_ws_stack']) > 1) {
			$value = substr($old_lists, 0, -1) . trim($value);
		}
	}
	
	$new_lists = trim($value);
	
	$add = FALSE;
	while (strlen($new_lists) < strlen($old_lists) || ($old_lists && substr($new_lists, 0, strlen($old_lists)) != $old_lists)) {
		$type = substr($old_lists, -1);
		if ($type == '*') {
			$this->closeTag('li');
			$this->closeTag('ul');
		} elseif ($type == '#') {
			$this->closeTag('li');
			$this->closeTag('ol');
		}
		$old_lists = substr($old_lists, 0, -1);
	}
	
	$cur_lists = $old_lists;
	while ($cur_lists != $new_lists) {
		$add  = TRUE;
		$type = substr($new_lists, strlen($cur_lists), 1);
		if ($type == '*') {
			$this->openTag('ul');
			$this->openTag('li');
		} else {
			$this->openTag('ol');
			$this->openTag('li');
		}
		$cur_lists .= $type;
	}
	
	if (!$add) {
		$this->closeTag('li');
		$this->openTag('li');
	}
    }
    function yy_r13_39($yy_subpatterns)
	{

	do {
		$ul_pos = $this->getOpenTagPos('ul');
		$ol_pos = $this->getOpenTagPos('ol');
		$pos = $ul_pos !== FALSE || $ol_pos !== FALSE;
		if ($pos) {
			$this->closeTag('li');
			$this->closeTag($ul_pos > $ol_pos ? 'ul' : 'ol');
		}
	} while ($pos);
	
	unset($this->cache['list_ws_stack']);
	
	$this->yypopstate();
    }
    function yy_r13_40($yy_subpatterns)
	{
 $this->handleString($this->value);     }


	function yylex14()
	{
		$tokenMap = array (
              1 => 2,
              4 => 0,
            );
		if ($this->counter >= strlen($this->input)) {
			return false; // end of input
		}
		$yy_global_pattern = "/^((\\|-[|+-]*(?=\\|)|\\+-[|+-]*(?=\\+|\\|)|\\|=?(>|<|~(?!~))?))|^(\n+)/";

		do {
			if (preg_match($yy_global_pattern, substr($this->input, $this->counter), $yymatches)) {
				$yysubmatches = $yymatches;
				$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
				if (!count($yymatches)) {
					throw new Exception('Error: lexing failed because a rule matched' .
						'an empty string.  Input "' . substr($this->input,
						$this->counter, 5) . '... state TABLESTATE');
				}
				next($yymatches); // skip global match
				$this->token = key($yymatches); // token number
				if ($tokenMap[$this->token]) {
					// extract sub-patterns for passing to lex function
					$yysubmatches = array_slice($yysubmatches, $this->token + 1,
						$tokenMap[$this->token]);
				} else {
					$yysubmatches = array();
				}
				$this->value = current($yymatches); // token value
				$r = $this->{'yy_r14_' . $this->token}($yysubmatches);
				if ($r === null) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					// accept this token
					return true;
				} elseif ($r === true) {
					// we have changed state
					// process this token in the new state
					return $this->yylex();
				} elseif ($r === false) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					if ($this->counter >= strlen($this->input)) {
						return false; // end of input
					}
					// skip this token
					continue;
				} else {                    $yy_yymore_patterns = array(
        1 => array(2, "^(\n+)"),
        4 => array(2, ""),
    );

					// yymore is needed
					do {
						if (!strlen($yy_yymore_patterns[$this->token][1])) {
							throw new Exception('cannot do yymore for the last token');
						}
						$yysubmatches = array();
						if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
							  substr($this->input, $this->counter), $yymatches)) {
							$yysubmatches = $yymatches;
							$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
							next($yymatches); // skip global match
							$this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
							$this->value = current($yymatches); // token value
							$this->line = substr_count($this->value, "\n");
							if ($tokenMap[$this->token]) {
								// extract sub-patterns for passing to lex function
								$yysubmatches = array_slice($yysubmatches, $this->token + 1,
									$tokenMap[$this->token]);
							} else {
								$yysubmatches = array();
							}
						}
						$r = $this->{'yy_r14_' . $this->token}($yysubmatches);
					} while ($r !== null && !is_bool($r));
					if ($r === true) {
						// we have changed state
						// process this token in the new state
						return $this->yylex();
					} elseif ($r === false) {
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						if ($this->counter >= strlen($this->input)) {
							return false; // end of input
						}
						// skip this token
						continue;
					} else {
						// accept
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						return true;
					}
				}
			} else {
				throw new Exception('Unexpected input at line ' . $this->line .
					': ' . var_export(substr($this->input, $this->counter), TRUE) . "\nStack:\n" . print_r($this->yyreflectstack(), TRUE));
			}
			break;
		} while (true);

	} // end function


	const TABLESTATE = 14;
    function yy_r14_1($yy_subpatterns)
	{

	$this->cache['table_line']++;
	$row_border = strlen($this->value) > 1 && $this->value[1] == '-';
	if ($this->value[0] == '|' && !$row_border && empty($this->cache['in_large_row'])) {
		if (empty($this->cache['rows'])) {
			$this->cache['rows'] = array(0 => array());
			$this->cache['current_row'] = 0;
			$this->cache['current_cell_rows'] = array();
		} else {
			$this->cache['rows'][] = array();
			$this->cache['current_row']++;
			$this->cache['current_cell_rows'][0] = $this->cache['current_row'];
		}
	}
	
	$this->cache['current_cell'] = -1;
	$this->yypushstate(self::CELLSTATE);
	return TRUE;
    }
    function yy_r14_4($yy_subpatterns)
	{

	// At the end of the table, we get rid of the last row if it is empty
	$rows = $this->cache['rows'];
	$rows = array_filter($rows);
	$rows = array_values($rows);
	
	unset($this->cache['rows']);
	unset($this->cache['table_line']);
	unset($this->cache['current_row']);
	unset($this->cache['current_cell']);
	unset($this->cache['current_cell_rows']);
	unset($this->cache['in_large_row']);
	
	$max_columns = 0;
	$min_columns = 1000000;
	$max_rows    = count($rows);
	$same_widths = TRUE;
	
	// Remove completely empty cells to prevent mistakes with | and + in the
	// middle of row separator
	foreach ($rows as $i => $row) {
		foreach ($row as $j => $cell) {
			if ($rows[$i][$j]['type'] === 'temp') {
				unset($rows[$i][$j]);
			}
		}
	}
	
	for ($i=0; $i<$max_rows; $i++) {
		ksort($rows[$i]);
	}
	
	// Determine if we need to check for colspans
	foreach ($rows as $row) {
		$max_columns = max($max_columns, count($row));
		$min_columns = min($min_columns, count($row));
	}
	
	// If there appears to be a colspan going on, determine it
	if ($min_columns != $max_columns) {
		$avg_col_widths = array();
		$cols_factored  = 0;
		foreach ($rows as $i => $row) {
			// We don't use smaller rows to create the average since they probably have colspans
			if (count($row) != $max_columns) {
				continue;
			}
			
			foreach ($row as $j => $cell) {
				// Placeholders are skipped since they don't have any content
				if ($cell['type'] == 'placeholder') {
					continue;
				}
				
				$width = max(array_map('strlen', explode("\n", $cell['content'])));
				if (!$cols_factored) {
					$avg_col_widths[$j] = $width;
				} else {
					$avg_col_widths[$j] = (int) ((($cols_factored * $avg_col_widths[$j]) + $width)/($cols_factored + 1));
				}
			}
			$cols_factored++;
		}
		
		foreach ($rows as $i => $row) {
			// We don't need to adjust rows with all columns
			if (count($row) == $max_columns) {
				continue;
			}
			
			$total_span = 0;
			foreach ($row as $j => $cell) {
				if ($cell['type'] == 'placeholder') {
					continue;
				}
				
				$width = max(array_map('strlen', explode("\n", $cell['content'])));
				$closest_multiple = 0;
				$closest_width    = 100000;
				for ($k=1; $k<$max_columns+1-$j; $k++) {
					$width_of_multiple = $this->getTotalWidth($avg_col_widths, $j, $k);
					if (abs($width - $width_of_multiple) < abs($width - $closest_width)) {
						$closest_multiple = $k;
						$closest_width    = $width_of_multiple;
					}
				}
				$rows[$i][$j]['colspan'] = $closest_multiple;
				$total_span += $rows[$i][$j]['colspan'];
			}
			
			if (count($row)) {
				$l = 0;
				while ($total_span < $max_columns) {
					if (!isset($rows[$i][$l])) {
						print_r($rows[$i]);
						die;
					}
					if ($rows[$i][$l]['colspan'] > 1) {
						$rows[$i][$l]['colspan']++;
						$total_span++;
					}
					$l++;
					if ($l >= count($row)) {
						$l = 0;
					}
				}
				
				$m = 0;
				while ($total_span > $max_columns) {
					if ($rows[$i][$m]['colspan'] > 1) {
						$rows[$i][$m]['colspan']--;
						$total_span--;
					}
					$m++;
					if ($m >= count($row)) {
						$m = 0;
					}
				}
			}
		}
	}
	
	foreach ($rows as $row) {
		$this->openTag('tr');
		foreach ($row as $cell) {
			if ($cell['type'] == 'placeholder') {
				continue;
			}
			
			$this->openTag($cell['type']);
			if ($cell['rowspan'] != 1) {
				$this->tip->attr['rowspan'] = $cell['rowspan'];
			}
			if ($cell['colspan'] != 1) {
				$this->tip->attr['colspan'] = $cell['colspan'];
			}
			if ($cell['align'] != 'left') {
				$this->tip->attr['class'] = 'align_' . $cell['align'];
			}
			
			// This out-dents the content of the cell by the greatest common indent
			$content_lines = explode("\n", rtrim($cell['content'], "\n"));
			$min_indent = 100000;
			foreach ($content_lines as $content_line) {
				if ($content_line === '') {
					continue;
				}
				if (preg_match('#^[ \t]+(?=$|[^ \t])#', $content_line, $match)) {
					$min_indent = min($min_indent, strlen(str_replace("\t", "    ", $match[0])));
				} else {
					$min_indent = 0;
				}
			}
			
			$new_content_lines = array();
			foreach ($content_lines as $content_line) {
				$new_content_lines[] = rtrim(substr($content_line, $min_indent));
			}
			$content_lines = $new_content_lines;
			
			$content_parser = new self(join("\n", $content_lines), $this->data);
			$descendants = $content_parser->parse();
			if (count($descendants->children) == 1 && $descendants->children[0]->name == 'p') {
				$descendants = $descendants->children[0];
			}
			foreach (new ParserIterator($descendants) as $decendant) {
				$decendant->index = $this->index_counter++;
			}
			
			// Make sure all cells have some content
			if ($descendants->children == array()) {
				$descendants->children = array(
					(object) array(
						'name' => 'string',
						'data' => array('')
					)
				);
			}
			
			$this->tip->children = $descendants->children;
			
			$this->closeTag($cell['type']);
		}
		$this->closeTag('tr');
	}
	$this->closeTag('table');
	
	$this->yypopstate();
    }


	function yylex15()
	{
		$tokenMap = array (
              1 => 2,
              4 => 1,
              6 => 0,
              7 => 0,
              8 => 0,
              9 => 0,
              10 => 0,
              11 => 2,
              14 => 0,
            );
		if ($this->counter >= strlen($this->input)) {
			return false; // end of input
		}
		$yy_global_pattern = "/^((\\{\\{\\{|`)(?!(#![\w-]+)?[ \t]*\n))|^(\\[((?!\\]| ).)+(?: (?:(?!\\]).)+)?\\])|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\\|[ \t]*\n(?=\\||\\+))|^(\\|(?=[ \t]*\n(?!\\||\\+)))|^(\n(?=\\||\\+))|^(\n+)|^((\\|-[|+-]*(?=\\|)|\\+-[|+-]*(?=\\+|\\|)|\\|=?(>|<|~(?!~))?))|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])/";

		do {
			if (preg_match($yy_global_pattern, substr($this->input, $this->counter), $yymatches)) {
				$yysubmatches = $yymatches;
				$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
				if (!count($yymatches)) {
					throw new Exception('Error: lexing failed because a rule matched' .
						'an empty string.  Input "' . substr($this->input,
						$this->counter, 5) . '... state CELLSTATE');
				}
				next($yymatches); // skip global match
				$this->token = key($yymatches); // token number
				if ($tokenMap[$this->token]) {
					// extract sub-patterns for passing to lex function
					$yysubmatches = array_slice($yysubmatches, $this->token + 1,
						$tokenMap[$this->token]);
				} else {
					$yysubmatches = array();
				}
				$this->value = current($yymatches); // token value
				$r = $this->{'yy_r15_' . $this->token}($yysubmatches);
				if ($r === null) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					// accept this token
					return true;
				} elseif ($r === true) {
					// we have changed state
					// process this token in the new state
					return $this->yylex();
				} elseif ($r === false) {
					$this->counter += strlen($this->value);
					$this->line += substr_count($this->value, "\n");
					if ($this->counter >= strlen($this->input)) {
						return false; // end of input
					}
					// skip this token
					continue;
				} else {                    $yy_yymore_patterns = array(
        1 => array(2, "^(\\[((?!\\]| ).)+(?: (?:(?!\\]).)+)?\\])|^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\\|[ \t]*\n(?=\\||\\+))|^(\\|(?=[ \t]*\n(?!\\||\\+)))|^(\n(?=\\||\\+))|^(\n+)|^((\\|-[|+-]*(?=\\|)|\\+-[|+-]*(?=\\+|\\|)|\\|=?(>|<|~(?!~))?))|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        4 => array(3, "^(\\{\\{[^|]+(?:\\|(?:(?!\\}\\}).)+)?\\}\\}+)|^(\\|[ \t]*\n(?=\\||\\+))|^(\\|(?=[ \t]*\n(?!\\||\\+)))|^(\n(?=\\||\\+))|^(\n+)|^((\\|-[|+-]*(?=\\|)|\\+-[|+-]*(?=\\+|\\|)|\\|=?(>|<|~(?!~))?))|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        6 => array(3, "^(\\|[ \t]*\n(?=\\||\\+))|^(\\|(?=[ \t]*\n(?!\\||\\+)))|^(\n(?=\\||\\+))|^(\n+)|^((\\|-[|+-]*(?=\\|)|\\+-[|+-]*(?=\\+|\\|)|\\|=?(>|<|~(?!~))?))|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        7 => array(3, "^(\\|(?=[ \t]*\n(?!\\||\\+)))|^(\n(?=\\||\\+))|^(\n+)|^((\\|-[|+-]*(?=\\|)|\\+-[|+-]*(?=\\+|\\|)|\\|=?(>|<|~(?!~))?))|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        8 => array(3, "^(\n(?=\\||\\+))|^(\n+)|^((\\|-[|+-]*(?=\\|)|\\+-[|+-]*(?=\\+|\\|)|\\|=?(>|<|~(?!~))?))|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        9 => array(3, "^(\n+)|^((\\|-[|+-]*(?=\\|)|\\+-[|+-]*(?=\\+|\\|)|\\|=?(>|<|~(?!~))?))|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        10 => array(3, "^((\\|-[|+-]*(?=\\|)|\\+-[|+-]*(?=\\+|\\|)|\\|=?(>|<|~(?!~))?))|^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        11 => array(5, "^([a-zA-Z0-9 \t]+|[^a-zA-Z0-9 \t\n\r])"),
        14 => array(5, ""),
    );

					// yymore is needed
					do {
						if (!strlen($yy_yymore_patterns[$this->token][1])) {
							throw new Exception('cannot do yymore for the last token');
						}
						$yysubmatches = array();
						if (preg_match('/' . $yy_yymore_patterns[$this->token][1] . '/',
							  substr($this->input, $this->counter), $yymatches)) {
							$yysubmatches = $yymatches;
							$yymatches = array_filter($yymatches, 'strlen'); // remove empty sub-patterns
							next($yymatches); // skip global match
							$this->token += key($yymatches) + $yy_yymore_patterns[$this->token][0]; // token number
							$this->value = current($yymatches); // token value
							$this->line = substr_count($this->value, "\n");
							if ($tokenMap[$this->token]) {
								// extract sub-patterns for passing to lex function
								$yysubmatches = array_slice($yysubmatches, $this->token + 1,
									$tokenMap[$this->token]);
							} else {
								$yysubmatches = array();
							}
						}
						$r = $this->{'yy_r15_' . $this->token}($yysubmatches);
					} while ($r !== null && !is_bool($r));
					if ($r === true) {
						// we have changed state
						// process this token in the new state
						return $this->yylex();
					} elseif ($r === false) {
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						if ($this->counter >= strlen($this->input)) {
							return false; // end of input
						}
						// skip this token
						continue;
					} else {
						// accept
						$this->counter += strlen($this->value);
						$this->line += substr_count($this->value, "\n");
						return true;
					}
				}
			} else {
				throw new Exception('Unexpected input at line ' . $this->line .
					': ' . var_export(substr($this->input, $this->counter), TRUE) . "\nStack:\n" . print_r($this->yyreflectstack(), TRUE));
			}
			break;
		} while (true);

	} // end function


	const CELLSTATE = 15;
    function yy_r15_1($yy_subpatterns)
	{
 $this->handleCell();     }
    function yy_r15_4($yy_subpatterns)
	{
 $this->handleCell();     }
    function yy_r15_6($yy_subpatterns)
	{
 $this->handleCell();     }
    function yy_r15_7($yy_subpatterns)
	{
 $this->yypopstate();     }
    function yy_r15_8($yy_subpatterns)
	{
 $this->yypopstate();     }
    function yy_r15_9($yy_subpatterns)
	{
 $this->yypopstate();     }
    function yy_r15_10($yy_subpatterns)
	{

	$this->yypopstate();
	return TRUE;
    }
    function yy_r15_11($yy_subpatterns)
	{
	
	// Handle new rows
	$row_border = strlen($this->value) > 1 && $this->value[1] == '-';
	if ($row_border) {
		$current_cell = $this->cache['current_cell'] + 1;
		
		if (empty($this->cache['rows'])) {
			$this->cache['rows'] = array(0 => array());
			$this->cache['current_row'] = 0;
			$this->cache['current_cell'] = -1;
			$this->cache['current_cell_rows'] = array();
		} else {
			$this->cache['rows'][] = array();
			$this->cache['current_row']++;
			$this->cache['current_cell'] = -1;
		}
		
		$current_row  = $this->cache['current_row'];
		
		$leaving_large_row = $this->value[0] == '|' && isset($this->cache['in_large_row']);
		
		if (!$leaving_large_row) {
			$cell_markers = preg_match_all('#[|+]#', $this->value, $matches);
			for ($num=0; $num<$cell_markers; $num++) {
				$this->cache['rows'][$current_row][$current_cell+$num] = array('content' => '', 'type' => 'temp', 'align' => 'left', 'rowspan' => 1, 'colspan' => 1, 'line' => $this->cache['table_line']+1);
				$this->cache['current_cell_rows'][$current_cell+$num] = $current_row;
				$this->cache['current_cell']++;
			}
		}
		
		if ($leaving_large_row) {
			unset($this->cache['in_large_row']);
		} else {
			$this->cache['in_large_row'] = TRUE;
		}
		return;
	}
	
	$current_row  = $this->cache['current_row'];
	if ($current_row == 0 || $this->cache['current_cell'] < max(array_keys($this->cache['current_cell_rows']))) {
		$this->cache['current_cell']++;
	}
	$current_cell = $this->cache['current_cell'];
	
	if ($current_row == 0) {
		$this->cache['current_cell_rows'][$current_cell] = 0;
	}
	
	if (empty($this->cache['in_large_row'])) {
		$this->cache['rows'][$current_row][$current_cell] = array('content' => '', 'type' => 'td', 'align' => 'left', 'rowspan' => 1, 'colspan' => 1, 'line' => $this->cache['table_line']);
		$this->cache['current_cell_rows'][$current_cell] = $this->cache['current_row'];
	}
	
	// In this situation we have one or more formatting characters to parse
	if (strlen($this->value) > 1 && $this->value[1] != '-') {
		$format = substr($this->value, 1);
		$this->cache['rows'][$current_row][$current_cell]['content'] = str_pad('', strlen($format), ' ');
		if ($format[0] == '=') {
			$this->cache['rows'][$current_row][$current_cell]['type'] = 'th';
		}
		if (preg_match('#>$#', $format)) {
			$this->cache['rows'][$current_row][$current_cell]['align'] = 'right';
		} elseif (preg_match('#~$#', $format)) {
			$this->cache['rows'][$current_row][$current_cell]['align'] = 'center';
		}
	}
    }
    function yy_r15_14($yy_subpatterns)
	{
 $this->handleCell();     }

}