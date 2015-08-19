<?php
namespace Dpn\DpnGlossary\Service;

/***************************************************************
 *  Copyright notice
 *
 *  (c) 2015 Daniel Dorndorf <dorndorf@dreipunktnull.com>, dreipunktnull
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

use Dpn\DpnGlossary\Domain\Model\Term;
use Dpn\DpnGlossary\Domain\Repository\TermRepository;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\QuerySettingsInterface;
use TYPO3\CMS\Extbase\Object\ObjectManager;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManager;
use TYPO3\CMS\Extbase\Configuration\ConfigurationManagerInterface;
use TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer;

/**
 *
 * @package dpn_glossary
 * @license http://www.gnu.org/licenses/gpl.html GNU General Public License, version 3 or later
 */
class WrapperService implements SingletonInterface {

	/**
	 * @var ContentObjectRenderer $cObj
	 */
	protected $cObj;

	/**
	 * @var array $terms
	 */
	protected $terms;

	/**
	 * @var array $tsConfig
	 */
	protected $tsConfig;

	/**
	 * @var array $settings
	 */
	protected $settings;

	/**
	 * @var TermRepository $termRepository
	 */
	protected $termRepository;

	/**
	 * @var integer $maxReplacementPerPage
	 */
	protected $maxReplacementPerPage;

	/**
	 * Boots up:
	 *  - objectManager to get class instances
	 *  - configuration manager for ts settings
	 *  - contentObjectRenderer for generating links etc.
	 *  - termRepository to get the Terms
	 *
	 * @return WrapperService
	 */
	public function __construct() {
		// Make instance of Object Manager
		/** @var ObjectManager $objectManager */
		$objectManager = GeneralUtility::makeInstance('TYPO3\CMS\Extbase\Object\ObjectManager');
		// Get Configuration Manager
		/** @var ConfigurationManager $configurationManager */
		$configurationManager = $objectManager->get('TYPO3\CMS\Extbase\Configuration\ConfigurationManager');
		// Inject Content Object Renderer
		$this->cObj = $objectManager->get('TYPO3\CMS\Frontend\ContentObject\ContentObjectRenderer');
		// Get Query Settings
		/** @var QuerySettingsInterface $querySettings */
		$querySettings = $objectManager->get('TYPO3\CMS\Extbase\Persistence\Generic\QuerySettingsInterface');
		// Get termRepository
		$this->termRepository = $objectManager->get('Dpn\DpnGlossary\Domain\Repository\TermRepository');
		// Get Typoscript Configuration
		$this->tsConfig = $configurationManager->getConfiguration(ConfigurationManagerInterface::CONFIGURATION_TYPE_FULL_TYPOSCRIPT);
		// Reduce TS config to plugin
		$this->tsConfig = $this->tsConfig['plugin.']['tx_dpnglossary.'];

		if (NULL === empty($this->tsConfig)) {
			// Save extension settings without ts dots
			$this->settings = GeneralUtility::removeDotsFromTS($this->tsConfig['settings.']);
			// Set StoragePid in the query settings object
			$querySettings->setStoragePageIds(GeneralUtility::trimExplode(',', $this->tsConfig['persistence.']['storagePid']));
			// Set current language uid
			$querySettings->setLanguageUid($GLOBALS['TSFE']->sys_language_uid);
			// Assign query settings object to repository
			$this->termRepository->setDefaultQuerySettings($querySettings);
		}
	}

	/**
	 * Main function called by hook 'contentPostProc-all'
	 *
	 * @return void
	 */
	public function contentParser() {
		// extract Pids which should be parsed
		$parsingPids = GeneralUtility::trimExplode(',', $this->settings['parsingPids']);
		// extract Pids which should NOT be parsed
		$excludePids = GeneralUtility::trimExplode(',', $this->settings['parsingExcludePidList']);
		// Get Tags which content should be parsed
		$tags = GeneralUtility::trimExplode(',', $this->settings['parsingTags']);
		// Remove "a" from parsingTags if it was added unknowingly
		if (TRUE === in_array('a', $tags)) {
			$tags = array_diff($tags, array('a'));
		}

		/*
		 * Abort if:
		 *  - Parsing tags are empty
		 *  - Page type is not 0
		 *  - tsConfig is empty
		 *  - no storagePid is set
		 *  - parsingPids doesn't contains 0 and
		 *    + current page is excluded
		 *    + current page is not whitelisted
		 *  - current page is the glossary detailpage
		 *  - current page is the glossary listpage
		 */
		if (
			TRUE === empty($tags)
			|| 0 !== $GLOBALS['TSFE']->type
			|| TRUE === empty($this->tsConfig)
			|| TRUE === empty($this->tsConfig['persistence.']['storagePid'])
			|| FALSE === in_array('0', $parsingPids)
			&& (TRUE === in_array($GLOBALS['TSFE']->id, $excludePids) || FALSE === in_array($GLOBALS['TSFE']->id, $parsingPids))
			|| $GLOBALS['TSFE']->id === intval($this->settings['detailPage'])
			|| $GLOBALS['TSFE']->id === intval($this->settings['listPage'])
		) {
			return;
		}

		// Get max number of replacements per page and term
		$this->maxReplacementPerPage = (int)$this->settings['maxReplacementPerPage'];
		// Tags which are not allowed as direct parent for a parsingTag
		$forbiddenParentTags = array_filter(GeneralUtility::trimExplode(',', $this->settings['forbiddenParentTags']));
		// Add "a" if unknowingly deleted to prevent errors
		if (FALSE === in_array('a', $forbiddenParentTags)) {
			$forbiddenParentTags[] = 'a';
		}

		//Find all terms
		$this->terms = $terms = $this->termRepository->findByNameLength();
		//Create new DOMDocument
		$DOM = new \DOMDocument();
		// Prevent crashes caused by HTML5 entities with internal errors
		libxml_use_internal_errors(true);
		// Load Page HTML in DOM and check if HTML is valid else abort
		// use XHTML tag for avoiding UTF-8 encoding problems
		if (FALSE === $DOM->loadHTML('<?xml encoding="UTF-8">' . $GLOBALS['TSFE']->content)) {
			return;
		}

		// remove unnecessary whitespaces in nodes (no visible whitespace)
		$DOM->preserveWhiteSpace = false;
		/** @var \DOMElement $DOMBody */
		$DOMBody = $DOM->getElementsByTagName('body')->item(0);
		// iterate over tags which are defined to be parsed
		foreach ($tags as $tag) {
			// extract the tags
			$DOMTags = $DOMBody->getElementsByTagName($tag);
			// call the nodereplacer for each node to parse its content
			/** @var \DOMNode $DOMTag */
			foreach ($DOMTags as $DOMTag) {
				// get parent tags from root tree string
				$parentTags = explode('/', preg_replace('#\[([^\]]*)\]#i', '', substr($DOMTag->parentNode->getNodePath(), 1)));
				// check if element is children of a forbidden parent
				if(FALSE === in_array($parentTags, $forbiddenParentTags)) {
					$this->nodeReplacer($DOMTag);
				}
			}
		}

		// set the parsed html page and remove XHTML tag which is not needed anymore
		$GLOBALS['TSFE']->content = str_replace('<?xml encoding="UTF-8">', '', $DOM->saveHTML());

	}

	/**
	 * Extract the DOMNodes html and
	 * replace it with the parsed html
	 * injected in a temp DOMDocument
	 *
	 * @param \DOMNode $DOMTag
	 * @return void
	 */
	protected function nodeReplacer(\DOMNode $DOMTag) {
		$tempDOM = new \DOMDocument();
		// use XHTML tag for avoiding UTF-8 encoding problems
		$tempDOM->loadHTML(
			'<?xml encoding="UTF-8">' .
			$this->htmlTagParser(
				$DOMTag->ownerDocument->saveHTML($DOMTag)
			)
		);
		// Replaces the original Node with the
		// new node containing the parsed content
		$DOMTag->parentNode->replaceChild(
			$DOMTag
				->ownerDocument
				->importNode(
					$tempDOM
						->getElementsByTagName('body')
						->item(0)->childNodes
						->item(0),
					TRUE
				),
			$DOMTag
		);
	}

	/**
	 * Extracts and replaces the
	 * inner content of the html tag
	 *
	 * @param string $html
	 * @return string
	 */
	protected function htmlTagParser($html) {
		// Start of content to be parsed
		$start = stripos($html, '>') + 1;
		// End of content to be parsed
		$end = strripos($html, '<');
		// Length of the content
		$length = $end - $start;
		// Paste everything between to textparser
		$parsed = $this->textParser(substr($html, $start, $length));
		// Replacing with parsed content
		$html = substr_replace($html, $parsed, $start, $length);

		return $html;
	}

	/**
	 * Parse the extracted html for terms with a regex
	 *
	 * @param string $text
	 * @return string
	 */
	protected function textParser($text) {
		$text = preg_replace('~\x{00a0}~siu', '&nbsp;', $text);
		// Iterate over terms and search matches for each of them
		/** @var Term $term */
		foreach ($this->terms as $term) {
			/*
			 * Regex Explanation:
			 * Group 1: (^|[\s\>[:punct:]])
			 *  ^         = can be begin of the string
			 *  \s        = can have space before term
			 *  \>        = can have a > before term (end of some tag)
			 *  [:punct:] = can have punctuation characters like .,?!& etc. before term
			 *
			 * Group 2: (' . preg_quote($term->getName()) . ')
			 *  The term to find, preg_quote() escapes special chars
			 *
			 * Group 3: ($|[\s\<[:punct:]])
			 *  Same as Group 1 but with end of string and < (start of some tag)
			 *
			 * Group 4: (?![^<]*>|[^<>]*<\/)
			 *  This Group protects any children element of the tag which should be parsed
			 *  ?!        = negative lookahead
			 *  [^<]*>    = match is between < & > and some other character
			 *              avoids parsing terms in self closing tags
			 *              example: <TERM> will work <TERM > not
			 *  [^<>]*<\/ = match is between some tag and tag ending
			 *              example: < or >TERM</>
			 *
			 * Flags:
			 * i = ignores camel case
			 */
			$regex = '#(^|[\s\>[:punct:]])(' . preg_quote($term->getName()) . ')($|[\s\<[:punct:]])(?![^<]*>|[^<>]*<\/)#i';

			// replace callback
			$callback = function($match) use ($term) {
				// Use term match to keep original camel case
				$term->setName($match[2]);
				// Wrap replacement with original chars
				return $match[1] . $this->termWrapper($term) . $match[3];
			};

			// Only call replace function if there are any matches
			if (1 === preg_match($regex, $text)) {
				// Use callback to keep allowed chars around the term and his camel case
				$text = preg_replace_callback($regex, $callback, $text, $this->maxReplacementPerPage);
			}
		}

		return $text;
	}

	/**
	 * Renders the wrapped term using the plugin settings
	 *
	 * @param \Dpn\DpnGlossary\Domain\Model\Term
	 * @return string
	 */
	protected function termWrapper(Term $term) {
		// get content object type
		$contentObjectType = $this->tsConfig['settings.']['termWraps'];
		// get term wrapping settings
		$wrapSettings = $this->tsConfig['settings.']['termWraps.'];
		// pass term data to the cObject pseudo constructor
		$this->cObj->start($term->toArray());
		// return the wrapped term
		return $this->cObj->cObjGetSingle($contentObjectType, $wrapSettings);
	}
}
