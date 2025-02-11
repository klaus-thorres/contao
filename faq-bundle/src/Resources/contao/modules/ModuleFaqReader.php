<?php

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao;

use Contao\CoreBundle\Exception\PageNotFoundException;
use Contao\CoreBundle\Image\Studio\Studio;
use Contao\CoreBundle\Routing\ResponseContext\ResponseContextAccessor;
use Contao\CoreBundle\Routing\ResponseContext\WebpageResponseContext;
use Patchwork\Utf8;

/**
 * Class ModuleFaqReader
 *
 * @property Comments $Comments
 * @property string   $com_template
 * @property array    $faq_categories
 *
 * @author Leo Feyer <https://github.com/leofeyer>
 */
class ModuleFaqReader extends Module
{
	/**
	 * Template
	 * @var string
	 */
	protected $strTemplate = 'mod_faqreader';

	/**
	 * Display a wildcard in the back end
	 *
	 * @return string
	 */
	public function generate()
	{
		$request = System::getContainer()->get('request_stack')->getCurrentRequest();

		if ($request && System::getContainer()->get('contao.routing.scope_matcher')->isBackendRequest($request))
		{
			$objTemplate = new BackendTemplate('be_wildcard');
			$objTemplate->wildcard = '### ' . Utf8::strtoupper($GLOBALS['TL_LANG']['FMD']['faqreader'][0]) . ' ###';
			$objTemplate->title = $this->headline;
			$objTemplate->id = $this->id;
			$objTemplate->link = $this->name;
			$objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;

			return $objTemplate->parse();
		}

		// Set the item from the auto_item parameter
		if (!isset($_GET['items']) && isset($_GET['auto_item']) && Config::get('useAutoItem'))
		{
			Input::setGet('items', Input::get('auto_item'));
		}

		// Do not index or cache the page if no FAQ has been specified
		if (!Input::get('items'))
		{
			/** @var PageModel $objPage */
			global $objPage;

			$objPage->noSearch = 1;
			$objPage->cache = 0;

			return '';
		}

		$this->faq_categories = StringUtil::deserialize($this->faq_categories);

		// Do not index or cache the page if there are no categories
		if (empty($this->faq_categories) || !\is_array($this->faq_categories))
		{
			/** @var PageModel $objPage */
			global $objPage;

			$objPage->noSearch = 1;
			$objPage->cache = 0;

			return '';
		}

		return parent::generate();
	}

	/**
	 * Generate the module
	 */
	protected function compile()
	{
		/** @var PageModel $objPage */
		global $objPage;

		$this->Template->back = $GLOBALS['TL_LANG']['MSC']['goBack'];
		$this->Template->referer = 'javascript:history.go(-1)';

		$objFaq = FaqModel::findPublishedByParentAndIdOrAlias(Input::get('items'), $this->faq_categories);

		if (null === $objFaq)
		{
			throw new PageNotFoundException('Page not found: ' . Environment::get('uri'));
		}

		// Add the FAQ record to the template (see #221)
		$this->Template->faq = $objFaq->row();

		// Overwrite the page meta data (see #2853, #4955 and #87)
		$responseContext = System::getContainer()->get(ResponseContextAccessor::class)->getResponseContext();

		if ($responseContext instanceof WebpageResponseContext)
		{
			if ($objFaq->pageTitle)
			{
				$responseContext->setTitle($objFaq->pageTitle); // Already stored decoded
			}
			elseif ($objFaq->question)
			{
				$responseContext->setTitle(StringUtil::inputEncodedToPlainText($objFaq->question));
			}

			if ($objFaq->description)
			{
				$responseContext->setMetaDescription(StringUtil::inputEncodedToPlainText($objFaq->description));
			}
			elseif ($objFaq->question)
			{
				$responseContext->setMetaDescription(StringUtil::inputEncodedToPlainText($objFaq->question));
			}

			if ($objFaq->robots)
			{
				$responseContext->setMetaRobots($objFaq->robots);
			}
		}

		$this->Template->question = $objFaq->question;

		// Clean the RTE output
		$objFaq->answer = StringUtil::toHtml5($objFaq->answer);

		$this->Template->answer = StringUtil::encodeEmail($objFaq->answer);
		$this->Template->addImage = false;
		$this->Template->before = false;

		// Add image
		if ($objFaq->addImage)
		{
			$figure = System::getContainer()
				->get(Studio::class)
				->createFigureBuilder()
				->from($objFaq->singleSRC)
				->setSize($objFaq->size)
				->setMetadata($objFaq->getOverwriteMetadata())
				->enableLightbox((bool) $objFaq->fullsize)
				->buildIfResourceExists();

			if (null !== $figure)
			{
				$figure->applyLegacyTemplateData($this->Template, $objFaq->imagemargin, $objFaq->floating);
			}
		}

		$this->Template->enclosure = array();

		// Add enclosure
		if ($objFaq->addEnclosure)
		{
			$this->addEnclosuresToTemplate($this->Template, $objFaq->row());
		}

		$strAuthor = '';

		/** @var UserModel $objAuthor */
		if (($objAuthor = $objFaq->getRelated('author')) instanceof UserModel)
		{
			$strAuthor = $objAuthor->name;
		}

		$this->Template->info = sprintf($GLOBALS['TL_LANG']['MSC']['faqCreatedBy'], Date::parse($objPage->dateFormat, $objFaq->tstamp), $strAuthor);

		// Tag the FAQ (see #2137)
		if (System::getContainer()->has('fos_http_cache.http.symfony_response_tagger'))
		{
			$responseTagger = System::getContainer()->get('fos_http_cache.http.symfony_response_tagger');
			$responseTagger->addTags(array('contao.db.tl_faq.' . $objFaq->id));
		}

		$bundles = System::getContainer()->getParameter('kernel.bundles');

		// HOOK: comments extension required
		if ($objFaq->noComments || !isset($bundles['ContaoCommentsBundle']))
		{
			$this->Template->allowComments = false;

			return;
		}

		/** @var FaqCategoryModel $objCategory */
		$objCategory = $objFaq->getRelated('pid');
		$this->Template->allowComments = $objCategory->allowComments;

		// Comments are not allowed
		if (!$objCategory->allowComments)
		{
			return;
		}

		// Adjust the comments headline level
		$intHl = min((int) str_replace('h', '', $this->hl), 5);
		$this->Template->hlc = 'h' . ($intHl + 1);

		$this->import(Comments::class, 'Comments');
		$arrNotifies = array();

		// Notify the system administrator
		if ($objCategory->notify != 'notify_author')
		{
			$arrNotifies[] = $GLOBALS['TL_ADMIN_EMAIL'];
		}

		/** @var UserModel $objAuthor */
		if ($objCategory->notify != 'notify_admin' && ($objAuthor = $objFaq->getRelated('author')) instanceof UserModel && $objAuthor->email)
		{
			$arrNotifies[] = $objAuthor->email;
		}

		$objConfig = new \stdClass();

		$objConfig->perPage = $objCategory->perPage;
		$objConfig->order = $objCategory->sortOrder;
		$objConfig->template = $this->com_template;
		$objConfig->requireLogin = $objCategory->requireLogin;
		$objConfig->disableCaptcha = $objCategory->disableCaptcha;
		$objConfig->bbcode = $objCategory->bbcode;
		$objConfig->moderate = $objCategory->moderate;

		$this->Comments->addCommentsToTemplate($this->Template, $objConfig, 'tl_faq', $objFaq->id, $arrNotifies);
	}
}

class_alias(ModuleFaqReader::class, 'ModuleFaqReader');
