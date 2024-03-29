<?php
/**
 * @copyright 2011-2018 VisualEditor Team's Cite sub-team and others; see AUTHORS.txt
 * @license MIT
 */

namespace Cite\Hooks;

use ApiQuerySiteinfo;
use ExtensionRegistry;
use MediaWiki\Api\Hook\APIQuerySiteInfoGeneralInfoHook;
use MediaWiki\Config\Config;
use MediaWiki\EditPage\EditPage;
use MediaWiki\Hook\EditPage__showEditForm_initialHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\ResourceLoader\Hook\ResourceLoaderGetConfigVarsHook;
use MediaWiki\Revision\Hook\ContentHandlerDefaultModelForHook;
use MediaWiki\Title\Title;
use MediaWiki\User\Options\UserOptionsLookup;

/**
 * @license GPL-2.0-or-later
 * @phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName
 */
class CiteHooks implements
	ContentHandlerDefaultModelForHook,
	ResourceLoaderGetConfigVarsHook,
	APIQuerySiteInfoGeneralInfoHook,
	EditPage__showEditForm_initialHook
{

	private UserOptionsLookup $userOptionsLookup;

	public function __construct( UserOptionsLookup $userOptionsLookup ) {
		$this->userOptionsLookup = $userOptionsLookup;
	}

	/**
	 * Convert the content model of a message that is actually JSON to JSON. This
	 * only affects validation and UI when saving and editing, not loading the
	 * content.
	 *
	 * @param Title $title
	 * @param string &$model
	 */
	public function onContentHandlerDefaultModelFor( $title, &$model ) {
		if (
			$title->inNamespace( NS_MEDIAWIKI ) &&
			(
				$title->getText() == 'Visualeditor-cite-tool-definition.json' ||
				$title->getText() == 'Cite-tool-definition.json'
			)
		) {
			$model = CONTENT_MODEL_JSON;
		}
	}

	/**
	 * Adds extra variables to the global config
	 * @param array &$vars `[ variable name => value ]`
	 * @param string $skin
	 * @param Config $config
	 */
	public function onResourceLoaderGetConfigVars( array &$vars, $skin, Config $config ): void {
		$vars['wgCiteVisualEditorOtherGroup'] = $config->get( 'CiteVisualEditorOtherGroup' );
		$vars['wgCiteResponsiveReferences'] = $config->get( 'CiteResponsiveReferences' );
		$vars['wgCiteBookReferencing'] = $config->get( 'CiteBookReferencing' );
	}

	/**
	 * Hook: APIQuerySiteInfoGeneralInfo
	 *
	 * Expose configs via action=query&meta=siteinfo
	 *
	 * @param ApiQuerySiteinfo $module
	 * @param array &$results
	 */
	public function onAPIQuerySiteInfoGeneralInfo( $module, &$results ) {
		$results['citeresponsivereferences'] = $module->getConfig()->get( 'CiteResponsiveReferences' );
	}

	/**
	 * Hook: EditPage::showEditForm:initial
	 *
	 * Add the module for WikiEditor
	 *
	 * @param EditPage $editPage the current EditPage object.
	 * @param OutputPage $outputPage object.
	 */
	public function onEditPage__showEditForm_initial( $editPage, $outputPage ) {
		$extensionRegistry = ExtensionRegistry::getInstance();
		$allowedContentModels = array_merge(
			[ CONTENT_MODEL_WIKITEXT ],
			$extensionRegistry->getAttribute( 'CiteAllowedContentModels' )
		);
		if ( !in_array( $editPage->contentModel, $allowedContentModels ) ) {
			return;
		}

		$wikiEditorEnabled = $extensionRegistry->isLoaded( 'WikiEditor' );

		$user = $editPage->getContext()->getUser();

		if (
			$wikiEditorEnabled &&
			$this->userOptionsLookup->getBoolOption( $user, 'usebetatoolbar' )
		) {
			$outputPage->addModules( 'ext.cite.wikiEditor' );
		}
	}

}
