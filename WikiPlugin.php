<?php
/**
 * The basic functionality shared between wiki plugins
 */
abstract class WikiPlugin
{
	/**
	 * The array of parameters passed to the plugin
	 * 
	 * @var array
	 */
	protected $parameters;
	
	/**
	 * The parent tags of the current tag
	 * 
	 * @var array
	 */
	protected $parents;
	
	/**
	 * The parser object
	 * 
	 * @var WikiParser
	 */
	protected $parser;
	
	/**
	 * The tag that represents this plugin in the parse tree
	 * 
	 * @var stdClass
	 */
	protected $tag;
	
	/**
	 * Initializes the plugin
	 * 
	 * @param WikiParser $parser      The currently running wiki parser
	 * @param array      $parents     The parent tags of the $tag
	 * @param stdClass   $tag         The tag in the parse tree that represents this plugin - this can be freely modified
	 * @param array      $parameters  The parameters passed to the plugin via the wiki markup
	 * @return PluginCss
	 */
	public function __construct($parser, $parents, $tag, $parameters)
	{
		$this->parser     = $parser;
		$this->parents    = $parents;
		$this->tag        = $tag;
		$this->parameters = $parameters;
	}
}