<?php
class PluginToc extends WikiPlugin
{
	public function executePhaseOne()
	{
		if (!isset($this->parameters['skip'])) {
			return FALSE;
		}
		
		$parent = end($this->parents);
		
		$parent->attr['toc-skip'] = TRUE;
		$this->parser->removeTag($parent, $this->tag);
		
		return TRUE;
	}
	
	public function executePhaseTwo()
	{
		$tag    = $this->tag;
		$parser = $this->parser;
		
		$bare = isset($this->parameters['bare']);

		$parser->changeTag($this->parents, $tag, 'div');
		
		$css_class = isset($this->parameters['class']) ? $this->parameters['class'] : 'sidebar';
		if (isset($tag->attr['class'])) {
			$tag->attr['class'] .= ' ';
		} else {
			$tag->attr['class'] = '';
		}
		$tag->attr['class'] .= $css_class;
		
		$new_heading = $parser->openTag('heading', array('toc-skip' => TRUE), array('2', '2'));
		$parser->handleString(isset($this->parameters['heading']) ? $this->parameters['heading'] : 'Contents');
		$parser->closeTag('heading');
		$parser->generateId($new_heading);
		
		$iter = new ParserIterator($this->parser, 'inclusive', 'heading');
		$last_level = 1;
		
		foreach ($iter as $heading) {
			if ($heading->data[0] == 1 || isset($heading->attr['toc-skip'])) {
				unset($heading->attr['toc-skip']);
				continue;
			}
			$level_changes = 0;
			while ($last_level > $heading->data[0]) {
				$parser->closeTag('li');
				$parser->closeTag('ul');
				$last_level--;
			}
			while ($last_level < $heading->data[0]) {
				// Enabling this will generate valid HTML, but the bullets
				// will look funny if one heading level is skipped
				/*if ($level_changes) {
					$parser->openTag('li');
				}*/
				$parser->openTag('ul');
				$last_level++;
				$level_changes++;
			}
			if (!$level_changes) {
				$parser->closeTag('li');
			}
			$parser->openTag('li');
			$parser->openTag('link', array('href' => '#' . $heading->attr['id']));
			$parser->handleString(rtrim($parser->captureText($heading)));
			$parser->closeTag('link');
		}
		while ($last_level > 1) {
			$parser->closeTag('li');
			$parser->closeTag('ul');
			$last_level--;
		}
		$parser->closeTag('ul');

		if ($bare) {
			$parser->unwrapChildren(end($this->parents), $tag);
		}
		
		return TRUE;
	}
}