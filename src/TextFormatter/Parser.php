<?php

/**
* @package   s9e\Toolkit
* @copyright Copyright (c) 2010 The s9e Authors
* @license   http://www.opensource.org/licenses/mit-license.php The MIT License
*/
namespace s9e\Toolkit\TextFormatter;

use RuntimeException,
    XMLWriter;

class Parser
{
	/**
	* Start tag, e.g. [b]
	* -- becomes <B><st>[b]</st>
	*/
	const START_TAG = 1;

	/**
	* End tag, e.g. [/b]
	* -- becomes <et>[/b]</et></B>
	*/
	const END_TAG = 2;

	/**
	* Self-closing tag, e.g. [img="http://..." /]
	* -- becomes <IMG>[img="http://..." /]</IMG>
	*
	* NOTE: SELF_CLOSING_TAG = START_TAG | END_TAG
	*/
	const SELF_CLOSING_TAG = 3;

	/**
	* Characters that are removed by the trim_* config directives
	* @link http://docs.php.net/manual/en/function.trim.php
	*/
	const TRIM_CHARLIST = " \n\r\t\0\x0B";

	//==============================================================================================
	// Application stuff
	//==============================================================================================

	/**
	* @var array Logged messages, reinitialized whenever a text is parsed
	*/
	protected $log;

	/**
	* @var array Tags config
	*/
	protected $tagsConfig;

	/**
	* @var array Plugins config
	*/
	protected $pluginsConfig;

	/**
	* @var array Filters config
	*/
	protected $filtersConfig;

	/**
	* @var array Array of PluginParser instances
	*/
	protected $pluginParsers = array();

	//==============================================================================================
	// Per-formatting vars
	//==============================================================================================

	/**
	* @var string Text being parsed
	*/
	protected $text;

	/**
	* @var integer Cursor position during parsing
	*/
	protected $pos;

	/**
	* @var array  Unprocessed tags, in reverse order
	*/
	protected $tagStack;

	/**
	* @var array  Processed tags, in document order
	*/
	protected $tags;

	/**
	* @var array  Tag currently being processed, used in processTags()
	*/
	protected $currentTag;

	/**
	* @var string Name of the attribute currently being validated, used in processTags()
	*/
	protected $currentAttribute;

	//==============================================================================================
	// Public stuff
	//==============================================================================================

	/**
	* Constructor
	*
	* @param  array $config The config array returned by ConfigBuilder->getParserConfig()
	* @return void
	*/
	public function __construct(array $config)
	{
		$this->filtersConfig = $config['filters'];
		$this->pluginsConfig = $config['plugins'];
		$this->tagsConfig    = $config['tags'];
	}

	/**
	* Return the tags' config
	*
	* @return array
	*/
	public function getTagsConfig()
	{
		return $this->tagsConfig;
	}

	/**
	* Return the message log
	*
	* @return array 2D array, first dimension is the message type: "debug", "warning" or "error"
	*/
	public function getLog()
	{
		return $this->log;
	}

	/**
	* Clear this instance's properties
	*
	* Used internally at the beginning of a new parsing. I suppose some memory-obsessive users will
	* appreciate to be able to do it whenever they feel like it
	*
	* @return void
	*/
	public function clear()
	{
		$this->log      = array();
		$this->tagStack = array();
		$this->tags     = array();

		unset($this->text, $this->currentTag, $this->currentAttribute);
	}

	/**
	* Parse given text, return the default (XML) representation
	*
	* @param  string $text Text to parse
	* @return string       XML representation
	*/
	public function parse($text)
	{
		$this->clear();
		$this->text = $text;
		$this->prepareTags();

		return $this->output();
	}

	/**
	* Add a message to the error log
	*
	* @param  string $type  Message type: debug, warning or error
	* @param  array  $entry Log info
	* @return void
	*/
	public function log($type, array $entry)
	{
		if (isset($this->currentTag))
		{
			$entry['tagName'] = $this->currentTag['name'];

			if (isset($this->currentAttribute))
			{
				$entry['attrName'] = $this->currentAttribute;
			}

			if (!isset($entry['pos']))
			{
				$entry['pos'] = $this->currentTag['pos'];
			}
		}

		$this->log[$type][] = $entry;
	}

	/**
	* Filter a var according to the configuration's filters
	*
	* Used internally but made public so that developers can test in advance whether a var would be
	* invalid. It mostly relies on PHP's own ext/filter extension but individual filters can be
	* overwritten by the config
	*
	* @param  mixed  $attrVal  Attribute value to be filtered/sanitized
	* @param  array  $attrConf Attribute configuration
	* @return mixed            The sanitized value of this attribute, or false if it was invalid
	*/
	public function filter($attrVal, array $attrConf)
	{
		$attrType = $attrConf['type'];

		if (isset($this->filtersConfig[$attrType]['callback']))
		{
			return call_user_func(
				$this->filtersConfig[$attrType]['callback'],
				$attrVal,
				$attrConf
			);
		}

		switch ($attrType)
		{
			case 'url':
				$attrVal = filter_var($attrVal, \FILTER_VALIDATE_URL);

				if (!$attrVal)
				{
					return false;
				}

				$p = parse_url($attrVal);

				if (!preg_match($this->filtersConfig['url']['allowedSchemes'], $p['scheme']))
				{
					$this->log('error', array(
						'msg'    => 'URL scheme %s is not allowed',
						'params' => array($p['scheme'])
					));
					return false;
				}

				if (isset($this->filtersConfig['url']['disallowedHosts'])
				 && preg_match($this->filtersConfig['url']['disallowedHosts'], $p['host']))
				{
					$this->log('error', array(
						'msg'    => 'URL host %s is not allowed',
						'params' => array($p['host'])
					));
					return false;
				}

				/**
				* We URL-encode quotes just in case someone would want to use the URL in some
				* Javascript thingy
				*/
				return str_replace(array("'", '"'), array('%27', '%22'), $attrVal);

			case 'identifier':
			case 'id':
				return filter_var($attrVal, \FILTER_VALIDATE_REGEXP, array(
					'options' => array('regexp' => '#^[a-zA-Z0-9-_]+$#D')
				));

			case 'simpletext':
				return filter_var($attrVal, \FILTER_VALIDATE_REGEXP, array(
					'options' => array('regexp' => '#^[a-zA-Z0-9\\-+.,_ ]+$#D')
				));

			case 'text':
				return (string) $attrVal;

			case 'email':
				return filter_var($attrVal, \FILTER_VALIDATE_EMAIL);

			case 'int':
			case 'integer':
				return filter_var($attrVal, \FILTER_VALIDATE_INT);

			case 'float':
				return filter_var($attrVal, \FILTER_VALIDATE_FLOAT);

			case 'number':
			case 'uint':
				return filter_var($attrVal, \FILTER_VALIDATE_INT, array(
					'options' => array('min_range' => 0)
				));

			case 'range':
				$attrVal = filter_var($attrVal, \FILTER_VALIDATE_INT);

				if ($attrVal === false)
				{
					return false;
				}

				if ($attrVal < $attrConf['min'])
				{
					$this->log('warning', array(
						'msg'    => 'Minimum range value adjusted to %s',
						'params' => array($attrConf['min'])
					));
					return $attrConf['min'];
				}

				if ($attrVal > $attrConf['max'])
				{
					$this->log('warning', array(
						'msg'    => 'Maximum range value adjusted to %s',
						'params' => array($attrConf['max'])
					));
					return $attrConf['max'];
				}

				return $attrVal;


			case 'color':
				return filter_var($attrVal, \FILTER_VALIDATE_REGEXP, array(
					'options' => array('regexp' => '/^(?:#[0-9a-f]{3,6}|[a-z]+)$/Di')
				));

			case 'regexp':
				if (!preg_match($attrConf['regexp'], $attrVal, $match))
				{
					return false;
				}

				if (isset($attrConf['replace']))
				{
					/**
					* Even numbers of backslashes are replaced by half their number of backslashes.
					* A dollar sign followed by a number is replaced by the corresponding $match,
					* unless it's preceded by a backslash.
					*/
					return preg_replace_callback(
						'#(?:\\\\\\\\)+|(\\\\)?\\$([0-9]+)#',
						function($m) use ($match)
						{
							if (!isset($m[2]))
							{
								return stripslashes($m[0]);
							}

							return ($m[1]) ? '$' . $m[2] : $match[$m[2]];
						},
						$attrConf['replace']
					);
				}

				return $attrVal;

			default:
				$this->log('debug', array(
					'msg'    => 'Unknown filter %s',
					'params' => array($attrType)
				));
				return false;
		}
	}

	//==============================================================================================
	// The big cheese
	//==============================================================================================

	/**
	* Capture tabs and process them
	*
	* That's the main loop. It execute all the passes to capture this text's tags, clean them up
	* then apply rules and stuff
	*
	* @return void
	*/
	protected function prepareTags()
	{
		/**
		* Capture all tags
		*/
		$this->executePasses();

		/**
		* Normalize tag names and remove unknown tags
		*/
		$this->normalizeTags();

		/**
		* Sort them by position and precedence
		*/
		$this->sortTags();

		/**
		* Remove overlapping tags, filter invalid tags, apply tag rules and stuff
		*/
		$this->processTags();
	}

	/**
	* Default output format
	*
	* The purpose of this method is to be overwritten by child classes that want to output the
	* parsed text in their own format
	*
	* @return string XML representation of the parsed text
	*/
	protected function output()
	{
		return $this->asXML();
	}

	/**
	* Generate a XML representation of the text after parsing has completed
	*
	* @return string
	*/
	protected function asXML()
	{
		$xml = new XMLWriter;
		$xml->openMemory();

		if (empty($this->tags))
		{
			$xml->writeElement('pt', $this->text);

			return trim($xml->outputMemory(true));
		}

		$xml->startElement('rt');
		$pos = 0;
		foreach ($this->tags as $tag)
		{
			/**
			* Append the text that's between last tag and this one
			*/
			$xml->text(substr($this->text, $pos, $tag['pos'] - $pos));

			/**
			* Capture the part of the text that belongs to this tag then move the cursor past
			* current tag
			*/
			$tagText = substr($this->text, $tag['pos'], $tag['len']);
			$pos = $tag['pos'] + $tag['len'];

			$wsBefore = $wsAfter = '';

			if ($tag['trimBefore'])
			{
				$wsBefore = substr($tagText, 0, $tag['trimBefore']);
				$tagText  = substr($tagText, $tag['trimBefore']);
			}

			if ($tag['trimAfter'])
			{
				$wsAfter = substr($tagText, -$tag['trimAfter']);
				$tagText = substr($tagText, 0, -$tag['trimAfter']);
			}

			if ($wsBefore > '')
			{
				$xml->writeElement('i', $wsBefore);
			}

			if ($tag['type'] & self::START_TAG)
			{
				$xml->startElement($tag['name']);

				if (!empty($tag['attrs']))
				{
					foreach ($tag['attrs'] as $k => $v)
					{
						$xml->writeAttribute($k, $v);
					}
				}

				if ($tagText > '')
				{
					if ($tag['type'] & self::END_TAG)
					{
						$xml->text($tagText);
						$xml->endElement();
					}
					else
					{
						$xml->writeElement('st', $tagText);
					}
				}
			}
			else
			{
				if ($tagText > '')
				{
					$xml->writeElement('et', $tagText);
				}
				$xml->endElement();
			}

			if ($wsAfter > '')
			{
				$xml->writeElement('i', $wsAfter);
			}
		}

		/**
		* Append the rest of the text, past the last tag
		*/
		if ($pos < strlen($this->text))
		{
			$xml->text(substr($this->text, $pos));
		}

		$xml->endDocument();

		return trim($xml->outputMemory(true));
	}


	//==========================================================================
	// Internal stuff
	//==========================================================================

	/**
	* Append a tag to the list of processed tags
	*
	* @param  array $tag
	* @return void
	*/
	protected function appendTag(array $tag)
	{
		$offset = 0;

		if (!empty($this->tags))
		{
			/**
			* The left boundary is right after the last tag
			*/
			$parentTag = end($this->tags);
			$offset  = $parentTag['pos'] + $parentTag['len'];
		}

		/**
		* Add the info related to whitespace trimming. We have to do that here for several reasons:
		*
		*  1. We have to account for tags that are automatically closed (e.g. closeParent)
		*  2. If we do that before sorting the tags, there are some cases where multiple tags would
		*     attempt to claim the same whitespace
		*  3. If we do that after the sort, the order may become incorrect since leading whitespace
		*     becomes part of the tag, therefore changing its position
		*/
		$this->addTrimmingInfoToTag($tag, $offset);

		$this->tags[] = $tag;
	}

	/**
	* Add trimming info to a tag
	*
	* For tags where one of the trim* directive is set, the "pos" and "len" attributes are adjusted
	* to comprise the surrounding whitespace and two attributes, "trimBefore" and "trimAfter" are
	* added.
	*
	* Note that whitespace that is part of what a pass defines as a tag is left untouched.
	*
	* @param  array &$tag    Tag to which we add trimming info
	* @param  int    $offset Leftmost boundary when looking for whitespace before a tag
	* @return void
	*/
	protected function addTrimmingInfoToTag(array &$tag, $offset)
	{
		$tag += array(
			'trimBefore' => 0,
			'trimAfter'  => 0
		);

		$tagConfig = $this->tagsConfig[$tag['name']];

		/**
		* Original: "  [b]  -text-  [/b]  "
		* Matches:  "XX[b]  -text-XX[/b]  "
		*/
		if (($tag['type']  &  self::START_TAG && !empty($tagConfig['trimBefore']))
		 || ($tag['type'] === self::END_TAG   && !empty($tagConfig['rtrimContent'])))
		{
			$spn = strspn(
				strrev(substr($this->text, $offset, $tag['pos'] - $offset)),
				self::TRIM_CHARLIST
			);

			$tag['trimBefore'] += $spn;
			$tag['len']        += $spn;
			$tag['pos']        -= $spn;
		}

		/**
		* Move the cursor past the tag
		*/
		$offset = $tag['pos'] + $tag['len'];

		/**
		* Original: "  [b]  -text-  [/b]  "
		* Matches:  "  [b]XX-text-  [/b]XX"
		*/
		if (($tag['type'] === self::START_TAG && !empty($tagConfig['ltrimContent']))
		 || ($tag['type']  &  self::END_TAG   && !empty($tagConfig['trimAfter'])))
		{
			$spn = strspn($this->text, self::TRIM_CHARLIST, $offset);

			$tag['trimAfter'] += $spn;
			$tag['len']       += $spn;
		}
	}

	/**
	* Execute all the plugins and store their tags
	*
	* @return void
	*/
	protected function executePasses()
	{
		$this->tagStack = array();

		$pass = 0;
		foreach ($this->pluginsConfig as $pluginName => $pluginConfig)
		{
			$matches = array();
			if (isset($pluginConfig['regexp']))
			{
				/**
				* Some plugins have several regexps in an array, others have a single regexp as a
				* string. We convert the latter to an array so that we can iterate over it.
				*/
				$isArray = is_array($pluginConfig['regexp']);
				$regexps = ($isArray) ? $pluginConfig['regexp'] : array($pluginConfig['regexp']);

				/**
				* @var bool If true, skip the rest of the regexps
				*/
				$skip = false;

				$cnt = 0;
				foreach ($regexps as $k => $regexp)
				{
					$matches[$k] = array();

					if ($skip)
					{
						continue;
					}

					$_cnt = preg_match_all(
						$regexp,
						$this->text,
						$matches[$k],
						\PREG_SET_ORDER | \PREG_OFFSET_CAPTURE
					);

					if (!$_cnt)
					{
						continue;
					}

					$cnt += $_cnt;

					if ($cnt > $pluginConfig['regexpLimit'])
					{
						if ($pluginConfig['regexpLimitAction'] === 'abort')
						{
							throw new RuntimeException($pluginName . ' limit exceeded');
						}
						else
						{
							$limit   = $pluginConfig['regexpLimit'] + $_cnt - $cnt;
							$msgType = ($pluginConfig['regexpLimitAction'] === 'ignore')
							         ? 'debug'
							         : 'warning';

							$matches[$k] = array_slice($matches[$k], 0, $limit);

							$this->log($msgType, array(
								'msg'    => $pluginName . ' limit exceeded. Only the first %s matches will be processed',
								'params' => array($pluginConfig['regexpLimit'])
							));

							$skip = true;
						}
					}
				}

				if (!$cnt)
				{
					/**
					* No matches? skip this plugin
					*/
					continue;
				}

				if (!$isArray)
				{
					$matches = $matches[0];
				}
			}

			if (!isset($pluginConfig['parserClassName']))
			{
				$pluginConfig['parserClassName'] =
					__NAMESPACE__ . '\\Plugins\\' . $pluginName . 'Parser';

				$pluginConfig['parserFilepath'] =
					__DIR__ . '/Plugins/' . $pluginName . 'Parser.php';
			}

			/**
			* Check whether an instance is ready, the class exists or if we have to load it
			*/
			if (!isset($this->pluginParsers[$pluginName]))
			{
				$useAutoload = !isset($pluginConfig['parserFilepath']);

				if (!class_exists($pluginConfig['parserClassName'], $useAutoload)
				 && isset($pluginConfig['parserFilepath']))
				{
					/**
					* Check for the PluginParser class
					*/
					if (!class_exists(__NAMESPACE__ . '\\PluginParser'))
					{
						include __DIR__ . '/PluginParser.php';
					}

					include $pluginConfig['parserFilepath'];
				}

				$className = $pluginConfig['parserClassName'];

				$this->pluginParsers[$pluginName] = new $className($this, $pluginConfig);
			}

			$tags = $this->pluginParsers[$pluginName]->getTags($this->text, $matches);

			foreach ($tags as $tag)
			{
				if (!isset($tag['suffix']))
				{
					/**
					* Add a suffix to tags that don't have one so that closing tags from a plugin
					* do not close tags opened by another plugin. Those special suffixes use the
					* minus character - instead of the colon : so that they don't get mixed up with
					* user-supplied suffixes used in BBCodes
					*/
					$tag['suffix'] = '-' . $pluginName;
				}

				if (!isset($tag['attrs']))
				{
					$tag['attrs'] = array();
				}

				$tag['pluginName'] = $pluginName;
				$this->tagStack[]  = $tag;
			}
		}
	}

	/**
	* Normalize tag names and remove unknown tags
	*
	* @return void
	*/
	protected function normalizeTags()
	{
		foreach ($this->tagStack as $k => &$tag)
		{
			/**
			* Normalize the tag name
			*/
			$tag['name'] = strtoupper($tag['name']);

			if (!isset($this->tagsConfig[$tag['name']]))
			{
				$this->log('debug', array(
					'pos'    => $tag['pos'],
					'msg'    => 'Removed unknown tag %1$s from plugin %2$s',
					'params' => array($tag['name'], $tag['pluginName'])
				));

				unset($this->tagStack[$k]);
				continue;
			}
		}
	}

	/**
	* Process the captured tags
	*
	* Removes overlapping tags, filter tags with invalid attributes, tags used in illegal places,
	* applies rules
	*
	* @return void
	*/
	protected function processTags()
	{
		if (empty($this->tagStack))
		{
			return;
		}

		//======================================================================
		// Time to get serious
		//======================================================================

		/**
		* @var array Open tags
		*/
		$tagStack = array();

		/**
		* @var array List of allowed tags in current context
		*/
		$allowed = array_combine(array_keys($this->tagsConfig), array_keys($this->tagsConfig));

		/**
		* @var array Number of times each tag has been used
		*/
		$cntTotal = array_fill_keys($allowed, 0);

		/**
		* @var array Number of open tags for each tagName
		*/
		$cntOpen = $cntTotal;

		/**
		* @var array Keeps track of open tags (tags carry their suffix)
		*/
		$openTags = array();

		$pos = 0;
		do
		{
			$this->currentTag = array_pop($this->tagStack);

			if ($pos > $this->currentTag['pos'])
			{
				$this->log('debug', array(
					'pos' => $this->currentTag['pos'],
					'msg' => 'Tag skipped'
				));
				continue;
			}

			$tagName   = $this->currentTag['name'];
			$tagConfig = $this->tagsConfig[$tagName];
			$suffix    = (isset($this->currentTag['suffix'])) ? $this->currentTag['suffix'] : '';

			//==================================================================
			// Start tag
			//==================================================================

			if ($this->currentTag['type'] & self::START_TAG)
			{
				//==============================================================
				// Check that this tag is allowed here
				//==============================================================

				if (!empty($tagConfig['rules']['closeParent']))
				{
					/**
					* Oh, wait, we may have to close its parent first
					*/
					$parentTag = end($tagStack);

					foreach ($tagConfig['rules']['closeParent'] as $parentTagName)
					{
						if ($parentTag['name'] === $parentTagName)
						{
							/**
							* So we do have to close that parent. First we reinsert current tag... 
							*/
							$this->tagStack[] = $this->currentTag;

							/**
							* ...then we create a new end tag which we put on top of the stack
							*/
							$this->currentTag = array(
								'pos'    => $this->currentTag['pos'],
								'name'   => $parentTagName,
								'suffix' => $parentTag['suffix'],
								'len'    => 0,
								'type'   => self::END_TAG
							);

							$this->addTrimmingInfoToTag($this->currentTag, $pos);
							$this->tagStack[] = $this->currentTag;

							continue 2;
						}
					}
				}

				if ($tagConfig['nestingLimit'] <= $cntOpen[$tagName]
				 || $tagConfig['tagLimit']     <= $cntTotal[$tagName])
				{
					continue;
				}

				if (!isset($allowed[$tagName]))
				{
					$this->log('debug', array(
						'pos'    => $this->currentTag['pos'],
						'msg'    => 'Tag %s is not allowed in this context',
						'params' => array($tagName)
					));
					continue;
				}

				if (isset($tagConfig['rules']['requireParent']))
				{
					$parentTag = end($tagStack);

					if (!$parentTag
					 || $parentTag['name'] !== $tagConfig['rules']['requireParent'])
					{
						$this->log('error', array(
							'pos'    => $this->currentTag['pos'],
							'msg'    => 'Tag %1$s requires %2$s as parent',
							'params' => array($tagName, $tagConfig['rules']['requireParent'])
						));

						continue;
					}
				}

				if (isset($tagConfig['rules']['requireAscendant']))
				{
					foreach ($tagConfig['rules']['requireAscendant'] as $ascendant)
					{
						if (empty($cntOpen[$ascendant]))
						{
							$this->log('debug', array(
								'pos'    => $this->currentTag['pos'],
								'msg'    => 'Tag %1$s requires %2$s as ascendant',
								'params' => array($tagName, $ascendant)
							));
							continue 2;
						}
					}
				}

				if (isset($tagConfig['attrs']))
				{
					/**
					* Add default values
					*/
					$missingAttrs = array_diff_key($tagConfig['attrs'], $this->currentTag['attrs']);

					foreach ($missingAttrs as $attrName => $attrConf)
					{
						if (isset($attrConf['default']))
						{
							$this->currentTag['attrs'][$attrName] = $attrConf['default'];
						}
					}

					/**
					* Filter attributes
					*/
					$this->filterCurrentAttributes();

					/**
					* Check for missing required attributes
					*/
					$missingAttrs = array_diff_key($tagConfig['attrs'], $this->currentTag['attrs']);

					foreach ($missingAttrs as $attrName => $attrConf)
					{
						if (empty($attrConf['isRequired']))
						{
							continue;
						}

						$this->log('error', array(
							'pos'    => $this->currentTag['pos'],
							'msg'    => 'Missing attribute %s',
							'params' => array($attrName)
						));

						continue 2;
					}

					/**
					* Sort attributes alphabetically. Can be useful if someone wants to process the
					* output using regexps
					*/
					ksort($this->currentTag['attrs']);
				}

				//==============================================================
				// Ok, so we have a valid BBCode
				//==============================================================

				$this->appendTag($this->currentTag);

				$pos = $this->currentTag['pos'] + $this->currentTag['len'];

				++$cntTotal[$tagName];

				if ($this->currentTag['type'] & self::END_TAG)
				{
					continue;
				}

				++$cntOpen[$tagName];

				if (isset($openTags[$tagName . $suffix]))
				{
					++$openTags[$tagName . $suffix];
				}
				else
				{
					$openTags[$tagName . $suffix] = 1;
				}

				$tagStack[] = array(
					'name' => $tagName,
					'suffix'	=> $suffix,
					'allowed'   => $allowed
				);
				$allowed = array_intersect_key($allowed, $tagConfig['allow']);
			}

			//==================================================================
			// End tag
			//==================================================================

			if ($this->currentTag['type'] & self::END_TAG)
			{
				if (empty($openTags[$tagName . $suffix]))
				{
					/**
					* This is an end tag but there's no matching start tag
					*/
					$this->log('debug', array(
						'pos'    => $this->currentTag['pos'],
						'msg'    => 'Could not find a matching start tag for BBCode %s',
						'params' => array($tagName . $suffix)
					));
					continue;
				}

				$pos = $this->currentTag['pos'] + $this->currentTag['len'];

				do
				{
					$cur     = array_pop($tagStack);
					$allowed = $cur['allowed'];

					--$cntOpen[$cur['name']];
					--$openTags[$cur['name'] . $cur['suffix']];

					if ($cur['name'] !== $tagName)
					{
						$this->appendTag(array(
							'name' => $cur['name'],
							'pos'  => $this->currentTag['pos'],
							'len'  => 0,
							'type' => self::END_TAG
						));
					}
					break;
				}
				while (1);

				$this->appendTag($this->currentTag);
			}
		}
		while (!empty($this->tagStack));
	}

	/**
	* Sort tags by position and precedence
	*
	* @return void
	*/
	protected function sortTags()
	{
		/**
		* Sort by pos descending, tag type ascending (OPEN, CLOSE, SELF), plugin name
		*/
		usort($this->tagStack, function($a, $b)
		{
			return ($b['pos'] - $a['pos'])
			    ?: ($a['type'] - $b['type'])
			    ?: strcmp($a['pluginName'], $b['pluginName']);
		});
	}

	/**
	* 
	*
	* @return void
	*/
	protected function filterCurrentAttributes()
	{
		$tagConfig = $this->tagsConfig[$this->currentTag['name']];

		/**
		* Tag-level pre-filter
		*/
		if (isset($tagConfig['preFilter']))
		{
			foreach ($tagConfig['preFilter'] as $callback)
			{
				$this->currentTag['attrs'] =
					call_user_func($callback, $this->currentTag['attrs']);
			}
		}

		/**
		* Filter each attribute
		*/
		foreach ($this->currentTag['attrs'] as $attrName => &$attrVal)
		{
			$this->currentAttribute = $attrName;

			$attrConf    = $this->tagsConfig[$this->currentTag['name']]['attrs'][$attrName];
			$filteredVal = $attrVal;

			// execute pre-filter callbacks
			if (!empty($attrConf['preFilter']))
			{
				foreach ($attrConf['preFilter'] as $callback)
				{
					$filteredVal = call_user_func($callback, $filteredVal);
				}
			}

			// filter the value
			$filteredVal = $this->filter($filteredVal, $attrConf);

			// execute post-filter callbacks if the value was valid
			if ($filteredVal !== false
			 && !empty($attrConf['postFilter']))
			{
				foreach ($attrConf['postFilter'] as $callback)
				{
					$filteredVal = call_user_func($callback, $filteredVal);
				}
			}

			if ($filteredVal === false)
			{
				/**
				* Bad attribute value
				*/
				$this->log('error', array(
					'pos'    => $this->currentTag['pos'],
					'msg'    => 'Invalid attribute %s',
					'params' => array($attrName)
				));

				if (isset($attrConf['default']))
				{
					/**
					* Use the default value
					*/
					$filteredVal = $attrConf['default'];

					$this->log('debug', array(
						'pos'    => $this->currentTag['pos'],
						'msg'    => 'Using default value %1$s for attribute %2$s',
						'params' => array($attrConf['default'], $attrName)
					));
				}
				else
				{
					/**
					* Remove the attribute altogether
					*/
					unset($this->currentTag['attrs'][$attrName]);

					if ($attrConf['isRequired'])
					{
						continue;
					}
				}
			}
			elseif ((string) $filteredVal !== (string) $attrVal)
			{
				$this->log('debug', array(
					'pos'    => $this->currentTag['pos'],
					'msg'    => 'Attribute value was altered by the filter '
							  . '(attrName: $1%s, attrVal: $2%s, filteredVal: $3%s)',
					'params' => array($attrName, $attrVal, $filteredVal)
				));
			}

			$attrVal = (string) $filteredVal;
		}
		unset($attrVal, $this->currentAttribute);

		/**
		* Tag-level post-filter
		*/
		if (isset($tagConfig['postFilter']))
		{
			foreach ($tagConfig['postFilter'] as $callback)
			{
				$this->currentTag['attrs'] =
					call_user_func($callback, $this->currentTag['attrs']);
			}
		}
	}
}