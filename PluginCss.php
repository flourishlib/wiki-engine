<?php
class PluginCss extends WikiPlugin
{
	public function executePhaseOne()
	{
		$parents       = $this->parents;
		$parent        = end($parents);
		$tag           = $this->tag;
		
		// If the parent only contains the plugin, shift the variables all
		// up one level in the tree to get the desired effect
		if (count($parent->children) == 1) {
			$tag       = $parent;
			$parent    = prev($parents);
			array_pop($parents);
		}
		
		if (!isset($this->parameters['mode'])) {
			$this->parameters['mode'] = 'parent';
		}
		
		$last_key = FALSE;
		$target   = NULL;
		if ($this->parameters['mode'] == 'next') {
			foreach ($parent->children as $key => $child) {
				if ($last_key) {
					$target = $child;
					break;
				}
				if ($child === $tag) {
					$last_key = TRUE;
				}
			}
			
		} elseif ($this->parameters['mode'] == 'prev') {
			foreach ($parent->children as $key => $child) {
				if ($child === $tag) {
					$target = $parent->children[$last_key];
					break;
				}
				$last_key = $key;
			}
			
		} elseif ($this->parameters['mode'] == 'closest' && isset($this->parameters['parent'])) {
			do {
				$target = array_pop($parents);
			} while ($target && $target->name != $this->parameters['parent']);
			
		} else {
			$target = $parent;
		}
		
		if ($target) {
			// Add the CSS class
			if (isset($this->parameters['class'])) {
				if (isset($target->attr['class'])) {
					$target->attr['class'] .= ' ';
				} else {
					$target->attr['class'] = '';
				}
				$target->attr['class'] .= $this->parameters['class'];
			}
			if (isset($this->parameters['id'])) {
				if (isset($target->attr['id'])) {
					throw new fValidationException(
						'The id, %s, could not be applied because the parent tag already has an id',
						$this->parameters['id']
					);
				}
				$target->attr['id'] = $this->parameters['id'];
			}
			if (!isset($this->parameters['class']) && !isset($this->parameters['id'])) {
				throw new fValidationException(
					'Please specify either a class or id for the CSS plugin'
				);
			}
		}
		
		$this->parser->removeTag($parent, $tag);
		
		return TRUE;
	}
}