<?php

$pwd = dirname(__file__);

$parser_source = $pwd . '/FlourishWikiParser.plex';
$parser_file   = $pwd . '/FlourishWikiParser.php';

if (!file_exists($parser_file) || filemtime($parser_source) > filemtime($parser_file)) {
	set_include_path(get_include_path() . PATH_SEPARATOR . $pwd . '/pear');
	include 'PHP/LexerGenerator.php';
	new PHP_LexerGenerator($parser_source, $parser_file);
}

include $pwd . '/WikiParser.php';
include $pwd . '/WikiPlugin.php';
include $pwd . '/ParserIterator.php';
include $pwd . '/FlourishWikiParser.php';
include $pwd . '/PluginCss.php';
include $pwd . '/PluginToc.php';
include $pwd . '/PluginInclude.php';

function stderr($string)
{
	static $fh = NULL;
	if ($fh === NULL) {
		$fh = fopen('php://stderr', 'w');
	}
	if (substr($string, -1) != "\n") {
		$string .= "\n";
	}
	fwrite($fh, $string);
}

if (count($argv) < 2) {
	stderr('No file name provided');
	exit(1);
}

$file = $argv[1];

if (!file_exists($file)) {
	stderr("File $file does not exist");
	exit(2);
}

$cache_data = array();
if (preg_match('#(^|/)(f[a-zA-Z0-9]+)\.wiki$#', $file)) {
	$page_name = preg_replace('#\.wiki$#', '', $file);
	$cache_data['flourish_class'] = basename($page_name);
}

$cache_data['__dir__'] = dirname(realpath($file)) . '/';
$markup = file_get_contents($file);
echo WikiParser::execute('Flourish', $markup, $cache_data);