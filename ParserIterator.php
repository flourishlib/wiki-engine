<?php
class ParserIterator implements Iterator
{
	/**
	 * A counter for ::key()
	 * 
	 * @var integer
	 */
	private $counter;
	
	/**
	 * A stack of the key in the children array that points to the tip
	 * 
	 * @var array
	 */
	private $key_stack;
	
	/**
	 * A list of tag names to use with 'inclusive' and 'exclusive' iterations
	 * 
	 * @var array
	 */
	private $list;
	
	/**
	 * A stack of the parent tags to the current tag
	 * 
	 * @var array
	 */
	private $parent_stack;
	
	/**
	 * The parser object
	 * 
	 * @var object
	 */
	private $parser;
	
	/**
	 * The type of iteration to perform:
	 * 
	 *  - 'all' tags
	 *  - 'block' level tags
	 *  - 'inclusive' list of tags, defined by $this->list
	 *  - 'exclusive' list of tags, defined by $this->list
	 * 
	 * @var string
	 */
	private $type;
	
	/**
	 * The reference to the current point in the parser tree
	 * 
	 * @var stdClass
	 */
	private $tip;
	
	
	/**
	 * Creates an iterator that does a depth-first traversal of the parse tree
	 * 
	 * @param object $tree  The stdClass object representing the root of the tree to parse, this is not included in the iteration
	 * @param string $type  If 'all' tags should be iterated, 'block' level tags, direct 'child' tags, an 'inclusive' list of tags names or an 'exclusive' list of tag names
	 * @param array  $list  The list of tags to include or exclude based on the $type
	 * @return ParserIterator
	 */
	public function __construct($tree, $type='all', $list=array())
	{
		$this->list = (array) $list;
		$this->tree = $tree instanceof WikiParser ? $tree->getTree() : $tree;
		$this->type = $type;
	}
	
	
	/**
	 * Returns the current tag in the iteration of the parse tree
	 * 
	 * @return stdClass  The current tag object
	 */
	public function current()
	{
		return $this->tip;
	}
	
	
	/**
	 * Returns the depth in the tree of the current tag
	 * 
	 * @return integer  The depth of the current tag
	 */
	public function depth()
	{
		return count($this->parent_stack);
	}

	
	/**
	 * Returns the number of the current tag in the context of the current iteration
	 * 
	 * @return integer  The tag number
	 */
	public function key()
	{
		return $this->counter;
	}

	
	/**
	 * Moves to the next tag in the parse tree
	 * 
	 * @return stdClass  The next tag in the parse tree
	 */
	public function next()
	{
		$this->counter++;
		
		$found_child = FALSE;
		if (!empty($this->tip->children) && ($this->type != 'child' || !$this->parent_stack)) {
			$key = 0;
			if ($this->type == 'block') {
				while ($key < count($this->tip->children)) {
					if (WikiParser::$tags[$this->tip->children[$key]->name]['type'] == 'block') {
						$found_child = TRUE;
						break;
					}
					$key++;
				}
			} else {
				$found_child = TRUE;
			}
			
			if ($found_child) {
				$this->key_stack[]    = $key;
				$this->parent_stack[] = $this->tip;
				$this->tip            = $this->tip->children[$key];
			}
		}
		
		if (!$found_child) {
			if ($parent = $this->parent()) {
				array_pop($this->parent_stack);
			}
			while (($key = array_pop($this->key_stack)) !== FALSE) {
				$key += 1;
				
				if (isset($parent->children[$key])) {
					$found = FALSE;
					if ($this->type == 'block') {
						while ($key < count($parent->children)) {
							if (WikiParser::$tags[$parent->children[$key]->name]['type'] == 'block') {
								$found = TRUE;
								break;
							}
							$key++;
						}
					} else {
						$found = TRUE;
					}
					
					if ($found) {
						$this->key_stack[]    = $key;
						$this->parent_stack[] = $parent;
						$this->tip            = $parent->children[$key];
						break;
					}
				}
				
				if (!$parent = $this->parent()) {
					break;
				} else {
					array_pop($this->parent_stack);
				}
			}
			if (!$parent) {
				$this->tip = NULL;
			}
		}
		
		while ($this->type == 'inclusive' && $this->current() && !in_array($this->current()->name, $this->list)) {
			$this->next();
		}
		
		while ($this->type == 'exclusive' && $this->current() && in_array($this->current()->name, $this->list)) {
			$this->next();
		}
		
		return $this->tip;	
	}
	
	
	/**
	 * Gets the parent tag of the current tag - does not alter iteration
	 * 
	 * @return stdClass  The parent tag of the current tag
	 */
	public function parent()
	{
		if (!$this->parent_stack) {
			return FALSE;
		}
		
		return $this->parent_stack[count($this->parent_stack)-1];
	}
	
	
	/**
	 * Gets the array of parent tags of the current tag - does not alter iteration
	 * 
	 * @return array  The parent tags of the current tag
	 */
	public function parents()
	{
		if (!$this->parent_stack) {
			return array();
		}
		
		return $this->parent_stack;
	}
	
	
	/**
	 * Resets the iterator to the root of the parse tree
	 * 
	 * @return void
	 */
	public function rewind()
	{
		$this->counter      = -1;
		$this->key_stack    = array();
		$this->parent_stack = array();
		$this->tip          = $this->tree;
		$this->next();
	}
	

	/**
	 * Indicates if the iterator can be moved forward by ::next()
	 * 
	 * @return boolean  If the iterator has another tag
	 */
	public function valid()
	{
		if ($this->tip) {
			return TRUE;
		}
		
		return FALSE;
	}
}
