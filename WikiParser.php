<?php
class WikiParser implements IteratorAggregate
{
	static public function execute($class, $content, $cache_data=array())
	{
		$class .= 'WikiParser';
		$parser = new $class($content, $cache_data);
		return $parser->render();
	}
	
	
	/**
	 * Provides a data store for temporary information used in parsing
	 */
	protected $cache = array();
	
	/**
	 * Internal character counter for PHP_LexerGenerator
	 * 
	 * @var integer
	 */
	protected $counter = 0;

	/**
	 * Provides a data store for user-provided information
	 */
	protected $data = array();
	
	/**
	 * The document that is being parsed
	 * 
	 * This is used by PHP_LexerGenerator
	 * 
	 * @var string
	 */
	protected $input = NULL;
	
	/**
	 * Internal line counter for PHP_LexerGenerator
	 * 
	 * @var integer
	 */
	protected $line = 1;
	
	/**
	 * The stack is an array tags in the tree that are the parent tags of the
	 * tip.
	 * 
	 * The tip is the last tag in the stack, which is what most manipulation
	 * will be happening with.
	 * 
	 * @var array
	 */
	protected $stack = array();
	
	/**
	 * The internal state tracker used by PHP_LexerGenerator
	 * 
	 * @var mixed
	 */
	protected $state = 1;
	
	/**
	 * Contains the definitions of each tag, with the name as the array
	 * key and the value being an associative array of information. Each
	 * tag should contain:
	 * 
	 *  - A 'type' key containing one of the following values:
	 *   - 'root': reserved for the root element of the tree
	 *   - 'block': a block-level tag, which closes all inline tags when ending
	 *   - 'inline': must be contained withing a block-level tag, does not close other inline tags
	 *   - 'self': a self-closing tag that is not added to the stack
	 * 
	 * Both 'root' and 'self' tags must have the following values:
	 *  - 'tag': The tag to print out for out, may contain any number of sprintf() placeholders
	 * 
	 * Both 'block' and 'inline' tags must have the following values:
	 *  - 'open': The opening tag for output, may contain any number of sprintf() placeholders
	 *  - 'close': The closing tag for output, may contain any number of sprintf() placeholders
	 * 
	 * Any tag may contain 'defaults' which defines an array of default values
	 * to use with sprintf().
	 * 
	 * When dealing with 'block' and 'inline' tags, the 'open' and 'close'
	 * string are combined together for sprintf(), so if there are two placeholders
	 * in 'open' and one in 'close', then there should be three values saved in
	 * the tree.
	 * 
	 * @var array
	 */
	static public $tags = array(
		'root' => array(
			'type' => 'root',
			'tag'  => ''
		),
		'plugin' => array(
			'type' => 'special',
			'tag'  => '%s%s'
		),
		'raw' => array(
			'type'  => 'block',
			'open'  => '%s',
			'close' => ''
		),
		'div' => array(
			'type'  => 'block',
			'open'  => "<div>",
			'close' => "</div>\n"
		),
		'span' => array(
			'type'  => 'inline',
			'open'  => '<span>',
			'close' => '</span>'
		),
		'string' => array(
			'type' => 'self',
			'tag'  => '%s'
		),
		'p' => array(
			'type'  => 'block',
			'open'  => "\n<p>\n",
			'close' => "\n</p>\n"
		),
		'heading' => array(
			'type'  => 'block',
			'open'  => "\n<h%s>",
			'close' => "</h%s>\n"
		),
		'block' => array(
			'type'  => 'block',
			'open'  => '<pre><code>',
			'close' => "</code></pre>\n"
		),
		'bold' => array(
			'type'  => 'inline',
			'open'  => '<strong>',
			'close' => '</strong>'
		),
		'italic' => array(
			'type'  => 'inline',
			'open'  => '<em>',
			'close' => '</em>'
		),
		'mono' => array(
			'type'  => 'inline',
			'open'  => '<code>',
			'close' => '</code>'
		),
		'nowiki' => array(
			'type'  => 'inline',
			'open'  => '<code>',
			'close' => '</code>'
		),
		'sub' => array(
			'type'  => 'inline',
			'open'  => '<sub>',
			'close' => '</sub>'
		),
		'sup' => array(
			'type'  => 'inline',
			'open'  => '<sup>',
			'close' => '</sup>'
		),
		'underline' => array(
			'type'  => 'inline',
			'open'  => '<span class="underline">',
			'close' => '</span>'
		),
		'br' => array(
			'type' => 'self',
			'tag'  => "<br />\n"
		),
		'link' => array(
			'type'  => 'inline',
			'open'  => '<a>',
			'close' => '</a>'
		),
		'img' => array(
			'type' => 'self',
			'tag'  => '<img />'
		),
		'hr' => array(
			'type' => 'self',
			'tag'  => "<hr />\n"
		),
		'ul' => array(
			'type'  => 'block',
			'open'  => "\n<ul>\n",
			'close' => "</ul>\n"
		),
		'ol' => array(
			'type'  => 'block',
			'open'  => "\n<ol>\n",
			'close' => "</ol>\n"
		),
		'li' => array(
			'type'  => 'block',
			'open'  => '<li>',
			'close' => "</li>\n"
		),
		'table' => array(
			'type'  => 'block',
			'open'  => "<table><tbody>\n",
			'close' => "</tbody></table>\n"
		),
		'tr' => array(
			'type'  => 'block',
			'open'  => "<tr>\n",
			'close' => "</tr>\n"
		),
		'th' => array(
			'type'  => 'block',
			'open'  => '<th>',
			'close' => "</th>\n"
		),
		'td' => array(
			'type'  => 'block',
			'open'  => '<td>',
			'close' => "</td>\n"
		),
		'dl' => array(
			'type'  => 'block',
			'open'  => '<dl>',
			'close' => '</dl>'
		),
		'dt' => array(
			'type'  => 'block',
			'open'  => '<dt>',
			'close' => '</dt>'
		),
		'dd' => array(
			'type'  => 'block',
			'open'  => '<dd>',
			'close' => '</dd>'
		),
		'blockquote' => array(
			'type'  => 'block',
			'open'  => '<blockquote>',
			'close' => '</blockquote>'
		)
	);
	
	/**
	 * The tip is the tag object in the tree that is represents the part of the
	 * document that is currently being parsed. This will move with each tag
	 * that is parsed.
	 * 
	 * The stack contains the parent tag objects to allow for moving back up
	 * the tree. The tip is always the last tag in the stack.
	 * 
	 * @var stdClass
	 */
	public $tip = NULL;
	
	/**
	 * The token number most recently parsed by PHP_LexerGenerator
	 * 
	 * @var integer
	 */
	public $token = NULL;
	
	/**
	 * Contains a tree-structure that represents how the content will be
	 * output as HTML. The structure is a root object with the members:
	 *  
	 *  - ->name: the special value 'root'
	 *  - ->children: a numerically-indexed array of parsed children tags
	 * 
	 * Each element in the children array will contain a stdClass object with
	 * the members:
	 * 
	 *  - ->name: This is the name of the tag - required
	 *  - ->children: If the tag is not a self-closing tag, it will have an array of chilren
	 *  - ->data: If the tag has any sprintf() placeholders, there will be an array to hold the values
	 *  - ->attr: An associative array of attributes for the tag
	 * 
	 * @var stdClass
	 */
	protected $tree;
	
	/**
	 * The value of the element most recently matched by PHP_LexerGenerator
	 * 
	 * @var string
	 */
	public $value = NULL;
	
	
	/**
	 * Takes the document to be parsed and initializes the parser
	 * 
	 * @param string $input  The document to parse
	 * @param array  $data   Extra user-supplied data for the parser
	 * @return WikiParser
	 */
	public function __construct($input, $data=array())
	{
		$this->data = $data;
		$this->input = $input;
		// Make sure we have newline since we can't ever match the end of the
		// input using regex
		if (substr($this->input, -1) != "\n") {
			$this->input .= "\n";
		}
		
		// Dynamically determine the number of placeholders for each tag so
		// that we don't need to figure it out each time
		foreach (self::$tags as $tag => $info) {
			self::$tags[$tag]['placeholders'] = 0;
			foreach ($info as $element => $value) {
				if (!in_array($element, array('tag', 'open', 'close'))) {
					continue;
				}
				
				$num = substr_count($value, '%s');
				
				if ($element == 'open') {
					self::$tags[$tag]['open_placeholders'] = $num;
				}
				if ($element == 'close') {
					self::$tags[$tag]['close_placeholders'] = $num;
				}
				if ($element == 'tag') {
					self::$tags[$tag]['tag_placeholders'] = $num;
				}
				self::$tags[$tag]['placeholders'] += $num;
			}
		}
	}


	/**
	 * Camelizes underscore_notation to UpperCamelCase
	 *
	 * @param string  $string  The string to camelize
	 * @return string  The camelized string
	 */
	public function camelize($string)
	{
		$string = ucfirst($string);
		return preg_replace_callback('#_([a-z0-9])#i', array('self', 'camelizeCallback'), $string);
	}


	/**
	 * A callback used by ::camelize() to handle converting underscore to camelCase
	 * 
	 * @param array $match  The regular expression match
	 * @return string  The value to replace the string with
	 */
	private function camelizeCallback($match)
	{
		return strtoupper($match[1]);
	}
	
	
	/**
	 * Gets all of the text under the tag specified
	 * 
	 * @param stdClass $parent_tag  The tag to get all text under
	 * @return string  The captured text
	 */
	public function captureText($parent_tag)
	{
		$iter = new ParserIterator($parent_tag, 'inclusive', array('string'));
		$text = '';
		foreach ($iter as $num => $tag) {
			$text .= $tag->data[0];
			if (end($iter->parent()->children) === $tag && self::$tags[$iter->parent()->name]['type'] == 'block') {
				$text .= ' ';
			}
		}
		return html_entity_decode($text, ENT_COMPAT, 'UTF-8');
	}
	
	
	/**
	 * Changes a tag from one name to another, handling switches from inline to block-level
	 * 
	 * @param array      $parents
	 * @param stdClass   $tag
	 * @param string     $new_name
	 * @param NULL|array $attr
	 * @param NULL|array $data
	 */
	public function changeTag($parents, $tag, $new_name, $attr=NULL, $data=NULL)
	{
		$old_name = $tag->name;
		$old_type = self::$tags[$tag->name]['type'];
		$new_type = self::$tags[$new_name]['type'];
		
		$tag->name = $new_name;
		
		if (in_array($new_type, array('block', 'special')) && !in_array($old_type, array('block', 'special'))) {
			
			// Create a sibling for the tag we are changing to contain all content
			// that existing after the tag we are changing
			$new_sibling = new stdClass;
			$new_sibling->name  = 'p';
			$new_sibling->attr  = array();
			$new_sibling->data  = array();
			$new_sibling->children = array();
			
			while ($block_tag = array_pop($parents)) {
				$parent_type = self::$tags[$block_tag->name]['type'];
				if ($parent_type == 'block' || $parent_type == 'root' || $parent_type == 'special') {
					break;
				}
			}
			
			// If the children are not in a p tag, they should be now since
			// there will be a new block-level tag and a p tag after that
			if ($block_tag->name != 'p') {
				$parents[] = $block_tag;
				$block_tag = $this->wrapChildren($block_tag, 'p');
			}
			
			// Loop through all tags after the tag we are changing and add them
			// to the new sibling and remove them from the block tag
			$found_tag  = FALSE;
			$last_depth = 1000000;
			$iter       = new ParserIterator($block_tag, 'all');
			foreach ($iter as $block_child) {
				if ($block_child === $tag) {
					$this->removeTag($iter->parent(), $block_child);
					$found_tag = TRUE;
					continue;
				}
				if (!$found_tag) { continue; }
				if ($iter->depth() <= $last_depth) {
					$new_sibling->children[] = $block_child;
					$this->removeTag($iter->parent(), $block_child);
					$last_depth = $iter->depth();
				}
			}
			
			$this->injectTag(end($parents), $block_tag, $tag);
			$this->injectTag(end($parents), $tag, $new_sibling);
			
		} elseif (in_array($old_type, array('block', 'special')) && !in_array($new_type, array('block', 'special'))) {
			throw new Exception('A tag can not be changed from a block-level tag to an inline-level or self-closing tag');
		}
		
		if ($attr !== NULL) {
			$tag->attr = $attr;
		}
		if ($data !== NULL) {
			$tag->data = $data;
		}
		
		if ($new_type == 'self') {
			unset($tag->children);
		}
		
		return $this;
	}
	
	
	/**
	 * Changes the tip of the parser for use when injecting tags into the middle
	 * of the tree
	 * 
	 * @param  array    $parents  The parent tags of the new tip
	 * @param  stdClass $tag      The tag to change the tip to
	 * @return array  The old parents and tag for use when restoring the tip
	 */
	public function changeTip($parents, $tag)
	{
		$output = array($this->stack, $this->tip);
		$this->stack = array_merge($parents, array($tag));
		$this->tip   = $tag;
		return $output;
	}
	
	
	/**
	 * Closes a tag, if open
	 * 
	 * @param string $name  The name of the tag to close
	 * @return void
	 */
	public function closeTag($name)
	{
		$pos = $this->getOpenTagPos($name);
		if ($pos === FALSE) {
			return;
		}
		
		// When closing a tag, we want to implicitly close all tags
		// that have been opened since. In the next section we will
		// re-open inline tags, but if any block level tags are
		// present, we won't re-open anything.
		$removed_tags = array_values(array_slice($this->stack, $pos));
		$trimmed_tags = array_values(array_slice($removed_tags, 1));
		$this->stack  = array_slice($this->stack, 0, $pos);
		$this->tip    = $this->stack[count($this->stack)-1];
		
		// This code allows markup to not be perfectly balanced, but
		// to created a balanaced tree by closing and then reopening
		// inline tags
		
		// First we check to make sure we only have inline tags, since
		// re-opening inline tags shouldn't happen if a block-level has
		// been closed
		$all_inline = TRUE;
		foreach ($trimmed_tags as $trimmed_tag) {
			if (self::$tags[$trimmed_tag->name]['type'] == 'block') {
				$all_inline = FALSE;
			}
		}
		
		// This takes every inline tag and reopens it, preserving data
		// so that think like links are identical
		if ($all_inline) {
			foreach ($trimmed_tags as $trimmed_tag) {
				$this->openTag($trimmed_tag->name);
				if (isset($trimmed_tag->data)) {
					$this->tip->data = $trimmed_tag->data;
				}
			}
		}
		
		
		// Loop through all removed tags and see if we are closing a tag that
		// is not self-closing and it has no children so we remove it since it
		// is empty and useless
		for ($j=count($removed_tags)-1; $j>=0; $j--) {
			$removed_tag = $removed_tags[$j];
			
			$type = self::$tags[$removed_tag->name]['type'];

			$not_self = $type == 'inline' || $type == 'block';
			$num_children = count($removed_tag->children);
			if ($not_self && (!$num_children || ($num_children == 1 && $removed_tag->children[0]->name == 'string' && trim($removed_tag->children[0]->data[0]) == ''))) {
				if ($j > 0) {
					$parent = $removed_tags[$j-1];
				} else {
					$parent = $this->tip;
				}
				$parent->children = array_slice($parent->children, 0, -1);
			}
		}
		
		return $this;
	}
	
	
	/**
	 * A shortcut for encoding special HTML character in UTF-8
	 * 
	 * @param string $value  The value to encode
	 * @return string  The encoding value
	 */
	public function encode($value)
	{
		return htmlspecialchars($value, ENT_COMPAT, 'UTF-8');
	}
	
	
	/**
	 * Generates an HTML id attribute for the tag specified if none exists
	 * 
	 * @param stdClass $tag  The tag to generate the id for
	 * @return void
	 */
	public function generateId($tag)
	{
		if (empty($tag->attr['id'])) {
			$text = $this->captureText($tag);
			$text = $this->makeFriendly($text);
			if ($text && preg_match('#^([0-9]+)(?=[^0-9])#', $text, $match)) {
				$new = array();
				for ($i=0; $i<strlen($match[0]); $i++) {
					$new[] = strtr(
						$match[0][$i],
						array(
							'0' => 'oh',    '1' => 'one',   '2' => 'two',
							'3' => 'three', '4' => 'four',  '5' => 'five',
							'6' => 'six',   '7' => 'seven', '8' => 'eight',
							'9' => 'nine'
						)
					);
				}
				$text = join('-', $new) . substr($text, strlen($match[0]));
			}
			$tag->attr['id'] = $text;
		}
		
		return $this;
	}


	/**
	 * Returns the parser data
	 * 
	 * @return array  The user-supplied parser data
	 */
	public function getData()
	{
		return $this->data;
	}
	
	
	/**
	 * Return an Iterator object for the parser
	 * 
	 * @return ParserIterator  The iterator for the parse tree
	 */
	public function getIterator()
	{
		return new ParserIterator($this);
	}
	
	
	/**
	 * Finds the position in the stack of a tag being opened
	 * 
	 * @param string $name  The name of the tag to find the open position of
	 * @return integer|FALSE  The position of the tag in the stack, or FALSE if not found
	 */
	protected function getOpenTagPos($name)
	{
		for ($i=count($this->stack)-1; $i >= 0; $i--) {
			if ($this->stack[$i]->name == $name) {
				return $i;
			}
		}
		return FALSE;
	}
	
	
	/**
	 * Returns the parse tree object
	 * 
	 * @return stdClass  The root element of the parse tree
	 */
	public function getTree()
	{
		return $this->tree;
	}
	
	
	/**
	 * Adds a string (non-tag text) to the tree, appending it to a previous
	 * string if possible
	 * 
	 * @param string  $string  The string to add to the tree
	 * @param boolean $encode  If the string should be encoded
	 * @return void
	 */
	public function handleString($string, $encode=TRUE)
	{
		if (!strlen($string)) {
			return $this;
		}

		if ($encode) {
			$string = $this->encode($string);
		}
		
		// If possible, the string is appended to the last tag if that is also
		// a string. This is necessary to prevent lots of excess nodes in the
		// tree, due to the way that the parsing is being done.
		if ($this->tip->children) {
			if (end($this->tip->children)->name == 'string') {
				end($this->tip->children)->data[0] .= $string;
				return $this;
			}
		}
		
		$this->openTag('string', NULL, array($string));
		
		return $this;
	}
	
	
	/**
	 * Handles opening, closing or adding a tag to the tree
	 * 
	 * @param string $name  The name of the tag to add
	 * @return void
	 */
	protected function handleTag($name)
	{
		$pos = $this->getOpenTagPos($name);
		if ($pos) {
			$this->closeTag($name);
		} else {
			$this->openTag($name);
		}
		
		return $this;
	}
	
	
	/**
	 * Injects a new tag as a child of the parent after the tag specified
	 * 
	 * @param stdClass $parent     The parent tag
	 * @param stdClass $after_tag  The tag to inject the new tag after
	 * @param stdClass $new_tag    The new tag to inject
	 */
	public function injectTag($parent, $after_tag, $new_tag)
	{
		$key = array_search($after_tag, $parent->children, TRUE);
		$parent->children = array_merge(
			array_slice($parent->children, 0, $key+1),
			array($new_tag),
			array_slice($parent->children, $key+1)
		);
		
		return $this;
	}
	
	
	protected function insertAttributes($output, $tag)
	{
		if (!empty($tag->attr)) {
			$attributes = '';
			foreach ($tag->attr as $attribute => $value) {
				$attributes .= ' ' . $attribute . '="' . $value . '"';
			}
			
			// This prevents issues with sprintf() incorrectly interpreting a literal % as part of a placeholder
			$attributes = str_replace('%', '%%', $attributes);
			
			if (preg_match('#[ /]*>.*$#Ds', $output, $match)) {
				$insert_pos = strlen($output) - strlen($match[0]);
				$output = substr($output, 0, $insert_pos) . $attributes . substr($output, $insert_pos);
			}
		}
		return $output;
	}


	/**
	 * Changes a string into a URL-friendly string
	 * 
	 * @param  string   $string      The string to convert
	 * @param  integer  $max_length  The maximum length of the friendly URL
	 * @param  string   $delimiter   The delimiter to use between words, defaults to `_`
	 * @return string  The URL-friendly version of the string
	 */
	private function makeFriendly($string, $max_length=NULL, $delimiter=NULL)
	{
		// This allows omitting the max length, but including a delimiter
		if ($max_length && !is_numeric($max_length)) {
			$delimiter  = $max_length;
			$max_length = NULL;
		}

		$string = trim($string);
		$string = str_replace("'", '', $string);

		if (!strlen($delimiter)) {
			$delimiter = '';
		}

		$delimiter_replacement = strtr($delimiter, array('\\' => '\\\\', '$' => '\\$'));
		$delimiter_regex       = preg_quote($delimiter, '#');

		$string = preg_replace('#[^a-zA-Z0-9\-_]+#', $delimiter_replacement, $string);
		//$string = preg_replace('#' . $delimiter_regex . '{2,}#', $delimiter_replacement, $string);
		//$string = preg_replace('#_-_#', '-', $string);
		//$string = preg_replace('#(^' . $delimiter_regex . '+|' . $delimiter_regex . '+$)#D', '', $string);
		
		$length = strlen($string);
		if ($max_length && $length > $max_length) {
			$last_pos = strrpos($string, $delimiter, ($length - $max_length - 1) * -1);
			if ($last_pos < ceil($max_length / 2)) {
				$last_pos = $max_length;
			}
			$string = substr($string, 0, $last_pos);
		}
		
		return $string;
	}
	
	
	/**
	 * Opens an HTML tag
	 * 
	 * @param  string $name  The name of the tag to open
	 * @param  array  $attr  The attributes for the new tag
	 * @param  array  $data  Data for self-closing tags
	 * @return stdClass  The tag that was just opened, even if it is self-closing
	 */
	public function openTag($name, $attr=array(), $data=array())
	{
		// Ensures that we always have a block-level tag before adding inline or self-closing tags
		if (!in_array(self::$tags[$name]['type'], array('block', 'special'))) {
			$found = FALSE;
			foreach ($this->stack as $stack_tag) {
				if (self::$tags[$stack_tag->name]['type'] == 'block') { $found = TRUE; break; }
			}
			if (!$found) {
				$this->openTag('p');
			}
		
		// If we are opening a block level tag and we just opened a paragraph, remove it
		} elseif (in_array(self::$tags[$name]['type'], array('block', 'special')) && $this->tip && $this->tip->name == 'p') {
			if (!$this->tip->children) {
				$this->stack = array_slice($this->stack, 0, -1);
				$this->tip   = end($this->stack);
				$this->tip->children = array_slice($this->tip->children, 0, -1);
			} elseif (self::$tags[$name]['type'] == 'block') {
				$this->closeTag('p');
			}
		}
		
		$self_tag = in_array(self::$tags[$name]['type'], array('self', 'special'));
		
		$new_tag = new stdClass();
		$new_tag->name  = $name;
		
		if (self::$tags[$name]['placeholders']) {
			for ($i=0; $i < self::$tags[$name]['placeholders']; $i++) {
				if (isset($data[$i])) {
					$new_tag->data[$i] = $data[$i];
				} elseif (isset(self::$tags[$name]['defaults'][$i])) {
					$new_tag->data[$i] = self::$tags[$name]['defaults'][$i];
				} else {
					$new_tag->data[$i] = '';
				}
			}
		}
		
		if ($name != 'string') {
			$new_tag->attr = $attr;
		}
		
		if (!$self_tag) {
			$new_tag->children = array();
		}
		
		$this->tip->children[] = $new_tag;
		if ($self_tag) {
			return $new_tag;
		}
		
		$this->tip     = end($this->tip->children);
		$this->stack[] = $this->tip;
		
		return $this;
	}
	
	
	/**
	 * Parses the wiki document into a tree representing the HTML
	 * 
	 * @return void
	 */
	public function parse()
	{
		$root = new stdClass();
		$root->name     = 'root';
		$root->children = array();
		$this->tree = $root;
		
		$this->tip      = $this->tree;
		$this->stack[0] = $this->tree;
		
		while ($this->yylex() !== FALSE);
		if (method_exists($this, 'finishParsing')) {
			$this->finishParsing();
		}
		while (end($this->stack) && end($this->stack)->name != 'root') {
			$this->closeTag(end($this->stack)->name);
		}
		return $this->tree;
	}
	
	
	/**
	 * Removes a tag from its parent
	 * 
	 * @param stdClass $parent  The parent tag
	 * @param stdClass $tag     The tag to remove
	 * @return void
	 */
	public function removeTag($parent, $tag)
	{
		$key = array_search($tag, $parent->children, TRUE);
		if ($key === FALSE) { return; }
		unset($parent->children[$key]);
		$parent->children = array_values($parent->children);
		
		return $this;
	}
	
	
	/**
	 * Renders the parse tree into HTML
	 * 
	 * @return string  The rendered HTML
	 */
	public function render()
	{
		if (!$this->tree) {
			$this->parse();
		}
		
		foreach (array('One', 'Two') as $phase) {
			do {
				$changed = FALSE;
				$iter = new ParserIterator($this, 'inclusive', 'plugin');
				foreach ($iter as $num => $tag) {
					$name       = $tag->data[0];
					$class      = 'Plugin' . $this->camelize($name);
					$parameters = $tag->data[1];
					if (class_exists($class, FALSE)) {
						$old_tip   = $this->tip;
						$old_stack = $this->stack;
						
						$this->tip   = $tag;
						$this->stack = array_merge($iter->parents(), array($tag));
						
						$object = new $class($this, $iter->parents(), $tag, $parameters);
						$method_name = 'executePhase' . $phase;
						if (method_exists($object, $method_name)) {
							$changed = $object->$method_name();
						}
						
						$this->tip   = $old_tip;
						$this->stack = $old_stack;
						
						// If anything has changed, we need to restart the parsing
						if ($changed) {
							break;
						}
						
					} else {
						$tag->name = 'span';
						$tag->attr = array('class' => 'parse_warning');
						$tag->data = array();
						
						$string = new stdClass();
						$string->name  = 'string';
						$string->attr  = array();
						$string->data  = 'Unknown plugin ' . $name;
						
						$tag->children = array($string);
					}
				}
			} while ($changed);
		}
		
		$output = '';
		$stack  = array();
		$tip    = NULL;
		$iter   = new ParserIterator($this);
		foreach ($iter as $num => $tag) {
			while ($iter->depth() <= count($stack)) {
				$close_tag = array_pop($stack);
				$value = self::$tags[$close_tag->name]['close'];
				if (self::$tags[$close_tag->name]['close_placeholders']) {
					$value = vsprintf($value, array_slice($close_tag->data, self::$tags[$close_tag->name]['open_placeholders']));
				}
				$output .= $value;
			}
			
			if (self::$tags[$tag->name]['type'] == 'self') {
				$value = self::$tags[$tag->name]['tag'];
				$value = $this->insertAttributes($value, $tag);
				if (!empty($tag->data)) {
					$value = vsprintf($value, $tag->data);
				}
				$output .= $value;
			
			} else {
				
				$value = self::$tags[$tag->name]['open'];
				$value = $this->insertAttributes($value, $tag);
				if (self::$tags[$tag->name]['open_placeholders']) {
					$value = vsprintf($value, array_slice($tag->data, 0, self::$tags[$tag->name]['open_placeholders']));
				}
				$output .= $value;
				$stack[] = $tag;
			}
		}
		while ($stack) {
			$close_tag = array_pop($stack);
			$value = WikiParser::$tags[$close_tag->name]['close'];
			if (WikiParser::$tags[$close_tag->name]['close_placeholders']) {
				$value = vsprintf($value, array_slice($close_tag->data, WikiParser::$tags[$close_tag->name]['open_placeholders']));
			}
			$output .= $value;
		}
		
		return $output;
	}


	/**
	 * Takes a tag and unwraps all children into its location in the tree
	 * 
	 * @param stdClass $parent         The parent of the tag
	 * @param stdClass $tag            The tag to unwrap the children of
	 * @return WikiParser   The parser, for the purpose of chaining
	 */
	public function unwrapChildren($parent, $tag)
	{
		$last_child = $tag;
		foreach ($tag->children as $child) {
			$this->injectTag($parent, $last_child, $child);
			$last_child = $child;
		}
		$this->removeTag($parent, $tag);
		
		return $this;
	}
	
	
	/**
	 * Takes a tag and wraps all children in another tag
	 * 
	 * @param stdClass $tag            The tag to wrap the children of
	 * @param string   $wrap_tag_name  The name of the tag to wrap the children in
	 * @return WikiParser  The parser, for the purpose of chaining
	 */
	public function wrapChildren($tag, $wrap_tag_name)
	{
		$old_tip = $this->tip;
		$this->openTag($wrap_tag_name);
		$wrap_tag = $this->tip;
		$this->tip = $old_tip;
		array_pop($this->tip->children);
		$wrap_tag->children = $tag->children;
		$tag->children = array($wrap_tag);
		
		return $this;
	}
}