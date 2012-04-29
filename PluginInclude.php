<?php
class PluginInclude extends WikiPlugin
{
	public function executePhaseOne()
	{
		$parent = end($this->parents);

		$data = $this->parser->getData();
		$path = $this->parameters['path'];
		if (!preg_match('#^(/|[a-z]:\\\\|https?://)#i', $path)) {
			$path = $data['__dir__'] . $path;
		}
		$markup = file_get_contents($path);

		$is_html = preg_match('#\.html?$#i', $path);

		$vars = array();
		foreach ($this->parameters as $key => $value) {
			if ($key == 'path') {
				continue;
			}
			if ($is_html) {
				$markup = str_replace(
					'{{ ' . $key . ' }}',
					$this->parser->encode($value),
					$markup
				);
			} else {
				$vars['var:' . $key] = $value;
			}
		}
		$vars = array_merge($this->parser->getData(), $vars);

		$tag = $this->tag;

		if ($is_html) {
			// Cleanup unused replacements
			$markup = preg_replace('#(?<!\{)\{\{\s+\w+\s+\}\}(?!\})#', '', $markup);
			$this->parser->changeTag(
				$parent,
				$tag,
				'raw',
				NULL,
				array(0 => $markup)
			);

		} else {
			$parser = new FlourishWikiParser($markup, $vars);
			$parser->parse();

			// Graft the include's children under the current parent
			$tree = $parser->getTree();
			$last_child = $tag;
			foreach ($tree->children as $child) {
				$this->parser->injectTag($parent, $last_child, $child);
				$last_child = $child;
			}
			
			$this->parser->removeTag($parent, $tag);
		}
		
		return TRUE;
	}
}