<?php
class PluginVar extends WikiPlugin
{
	public function executePhaseOne()
	{
		$parent = end($this->parents);
		$data = $this->parser->getData();

		$var_name = 'var:' . $this->parameters['name'];
		$value = isset($data[$var_name]) ? $this->parser->encode($data[$var_name]) : '';

		$this->parser->changeTag(
			$parent,
			$this->tag,
			'string',
			NULL,
			array(0 => $value)
		);
		
		return TRUE;
	}
}