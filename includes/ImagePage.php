<?php
/**
 * Class for viewing MediaWiki file description pages
 *
 * @ingroup Media
 */
class ImagePage extends Article {

	/**
	 * @var File
	 */
	private $displayImg;
	/**
	 * @var FileRepo
	 */
	private $repo;
	private $fileLoaded;

	var $mExtraDescription = false;

	/**
	 * @param $title Title
	 * @return WikiFilePage
	 */
	protected function newPage( Title $title ) {
		// Overload mPage with a file-specific page
		return new WikiFilePage( $title );
	}

	/**
	 * Constructor from a page id
	 * @param $id Int article ID to load
	 * @return ImagePage|null
	 */
	public static function newFromID( $id ) {
		$t = Title::newFromID( $id );
		# @todo FIXME: Doesn't inherit right
		return $t == null ? null : new self( $t );
		# return $t == null ? null : new static( $t ); // PHP 5.3
	}

	/**
	 * @param $file File:
	 * @return void
	 */
	public function setFile( $file ) {
		$this->mPage->setFile( $file );
		$this->displayImg = $file;
		$this->fileLoaded = true;
	}

	protected function loadFile() {
		if ( $this->fileLoaded ) {
			return;
		}
		$this->fileLoaded = true;

		$this->displayImg = $img = false;
		wfRunHooks( 'ImagePageFindFile', array( $this, &$img, &$this->displayImg ) );
		if ( !$img ) { // not set by hook?
			$img = wfFindFile( $this->getTitle() );
			if ( !$img ) {
				$img = wfLocalFile( $this->getTitle() );
			}
		}
		$this->mPage->setFile( $img );
		if ( !$this->displayImg ) { // not set by hook?
			$this->displayImg = $img;
		}
		$this->repo = $img->getRepo();
	}

	/**
	 * Handler for action=render
	 * Include body text only; none of the image extras
	 */
	public function render() {
		$this->getContext()->setArticleBodyOnly( true );
		parent::view();
	}

	public function view() {
		global $wgShowEXIF;

		$out = $this->getContext()->getOutput();
		$request = $this->getContext()->getRequest();
		$diff = $request->getVal( 'diff' );
		$diffOnly = $request->getBool( 'diffonly', $this->getContext()->getUser()->getOption( 'diffonly' ) );

		if ( $this->getTitle()->getNamespace() != NS_FILE || ( isset( $diff ) && $diffOnly ) ) {
			parent::view();
			return;
		}

		$this->loadFile();

		if ( $this->getTitle()->getNamespace() == NS_FILE && $this->mPage->getFile()->getRedirected() ) {
			if ( $this->getTitle()->getDBkey() == $this->mPage->getFile()->getName() || isset( $diff ) ) {
				// mTitle is the same as the redirect target so ask Article
				// to perform the redirect for us.
				$request->setVal( 'diffonly', 'true' );
				parent::view();
				return;
			} else {
				// mTitle is not the same as the redirect target so it is
				// probably the redirect page itself. Fake the redirect symbol
				$out->setPageTitle( $this->getTitle()->getPrefixedText() );
				$out->addHTML( $this->viewRedirect( Title::makeTitle( NS_FILE, $this->mPage->getFile()->getName() ),
					/* $appendSubtitle */ true, /* $forceKnown */ true ) );
				$this->mPage->doViewUpdates( $this->getContext()->getUser() );
				return;
			}
		}

		if ( $wgShowEXIF && $this->displayImg->exists() ) {
			// @todo FIXME: Bad interface, see note on MediaHandler::formatMetadata().
			$formattedMetadata = $this->displayImg->formatMetadata();
			$showmeta = $formattedMetadata !== false;
		} else {
			$showmeta = false;
		}

		if ( !$diff && $this->displayImg->exists() ) {
			$out->addHTML( $this->showTOC( $showmeta ) );
		}

		if ( !$diff ) {
			$this->openShowImage();
		}

		# No need to display noarticletext, we use our own message, output in openShowImage()
		if ( $this->mPage->getID() ) {
			# NS_FILE is in the user language, but this section (the actual wikitext)
			# should be in page content language
			$pageLang = $this->getTitle()->getPageLanguage();
			$out->addHTML( Xml::openElement( 'div', array( 'id' => 'mw-imagepage-content',
				'lang' => $pageLang->getCode(), 'dir' => $pageLang->getDir(),
				'class' => 'mw-content-'.$pageLang->getDir() ) ) );
			parent::view();
			$out->addHTML( Xml::closeElement( 'div' ) );
		} else {
			# Just need to set the right headers
			$out->setArticleFlag( true );
			$out->setPageTitle( $this->getTitle()->getPrefixedText() );
			$this->mPage->doViewUpdates( $this->getContext()->getUser() );
		}

		# Show shared description, if needed
		if ( $this->mExtraDescription ) {
			$fol = wfMessage( 'shareddescriptionfollows' );
			if ( !$fol->isDisabled() ) {
				$out->addWikiText( $fol->plain() );
			}
			$out->addHTML( '<div id="shared-image-desc">' . $this->mExtraDescription . "</div>\n" );
		}

		$this->closeShowImage();
		$this->imageHistory();
		// TODO: Cleanup the following

		$out->addHTML( Xml::element( 'h2',
			array( 'id' => 'filelinks' ),
			wfMsg( 'imagelinks' ) ) . "\n" );
		$this->imageDupes();
		# @todo FIXME: For some freaky reason, we can't redirect to foreign images.
		# Yet we return metadata about the target. Definitely an issue in the FileRepo
		$this->imageLinks();

		# Allow extensions to add something after the image links
		$html = '';
		wfRunHooks( 'ImagePageAfterImageLinks', array( $this, &$html ) );
		if ( $html ) {
			$out->addHTML( $html );
		}

		if ( $showmeta ) {
			$out->addHTML( Xml::element( 'h2', array( 'id' => 'metadata' ), wfMsg( 'metadata' ) ) . "\n" );
			$out->addWikiText( $this->makeMetadataTable( $formattedMetadata ) );
			$out->addModules( array( 'mediawiki.action.view.metadata' ) );
		}

		// Add remote Filepage.css
		if( !$this->repo->isLocal() ) {
			$css = $this->repo->getDescriptionStylesheetUrl();
			if ( $css ) {
				$out->addStyle( $css );
			}
		}
		// always show the local local Filepage.css, bug 29277
		$out->addModuleStyles( 'filepage' );
	}

	/**
	 * @return File
	 */
	public function getDisplayedFile() {
		$this->loadFile();
		return $this->displayImg;
	}

	/**
	 * Create the TOC
	 *
	 * @param $metadata Boolean: whether or not to show the metadata link
	 * @return String
	 */
	protected function showTOC( $metadata ) {
		$r = array(
			'<li><a href="#file">' . wfMsgHtml( 'file-anchor-link' ) . '</a></li>',
			'<li><a href="#filehistory">' . wfMsgHtml( 'filehist' ) . '</a></li>',
			'<li><a href="#filelinks">' . wfMsgHtml( 'imagelinks' ) . '</a></li>',
		);
		if ( $metadata ) {
			$r[] = '<li><a href="#metadata">' . wfMsgHtml( 'metadata' ) . '</a></li>';
		}

		wfRunHooks( 'ImagePageShowTOC', array( $this, &$r ) );

		return '<ul id="filetoc">' . implode( "\n", $r ) . '</ul>';
	}

	/**
	 * Make a table with metadata to be shown in the output page.
	 *
	 * @todo FIXME: Bad interface, see note on MediaHandler::formatMetadata().
	 *
	 * @param $metadata Array: the array containing the EXIF data
	 * @return String The metadata table. This is treated as Wikitext (!)
	 */
	protected function makeMetadataTable( $metadata ) {
		$r = "<div class=\"mw-imagepage-section-metadata\">";
		$r .= wfMsgNoTrans( 'metadata-help' );
		$r .= "<table id=\"mw_metadata\" class=\"mw_metadata\">\n";
		foreach ( $metadata as $type => $stuff ) {
			foreach ( $stuff as $v ) {
				# @todo FIXME: Why is this using escapeId for a class?!
				$class = Sanitizer::escapeId( $v['id'] );
				if ( $type == 'collapsed' ) {
					$class .= ' collapsable';
				}
				$r .= "<tr class=\"$class\">\n";
				$r .= "<th>{$v['name']}</th>\n";
				$r .= "<td>{$v['value']}</td>\n</tr>";
			}
		}
		$r .= "</table>\n</div>\n";
		return $r;
	}

	/**
	 * Overloading Article's getContent method.
	 *
	 * Omit noarticletext if sharedupload; text will be fetched from the
	 * shared upload server if possible.
	 * @return string
	 */
	public function getContent() {
		$this->loadFile();
		if ( $this->mPage->getFile() && !$this->mPage->getFile()->isLocal() && 0 == $this->getID() ) {
			return '';
		}
		return parent::getContent();
	}

	protected function openShowImage() {
		global $wgImageLimits, $wgEnableUploads, $wgSend404Code;

		$this->loadFile();
		$out = $this->getContext()->getOutput();
		$user = $this->getContext()->getUser();
		$lang = $this->getContext()->getLanguage();
		$dirmark = $lang->getDirMarkEntity();
		$request = $this->getContext()->getRequest();

		$sizeSel = intval( $user->getOption( 'imagesize' ) );
		if ( !isset( $wgImageLimits[$sizeSel] ) ) {
			$sizeSel = User::getDefaultOption( 'imagesize' );

			// The user offset might still be incorrect, specially if
			// $wgImageLimits got changed (see bug #8858).
			if ( !isset( $wgImageLimits[$sizeSel] ) ) {
				// Default to the first offset in $wgImageLimits
				$sizeSel = 0;
			}
		}
		$max = $wgImageLimits[$sizeSel];
		$maxWidth = $max[0];
		$maxHeight = $max[1];

		if ( $this->displayImg->exists() ) {
			# image
			$page = $request->getIntOrNull( 'page' );
			if ( is_null( $page ) ) {
				$params = array();
				$page = 1;
			} else {
				$params = array( 'page' => $page );
			}
			$width_orig = $this->displayImg->getWidth( $page );
			$width = $width_orig;
			$height_orig = $this->displayImg->getHeight( $page );
			$height = $height_orig;

			$longDesc = wfMsg( 'parentheses', $this->displayImg->getLongDesc() );

			wfRunHooks( 'ImageOpenShowImageInlineBefore', array( &$this, &$out ) );

			if ( $this->displayImg->allowInlineDisplay() ) {
				# image

				# "Download high res version" link below the image
				# $msgsize = wfMsgHtml( 'file-info-size', $width_orig, $height_orig, Linker::formatSize( $this->displayImg->getSize() ), $mime );
				# We'll show a thumbnail of this image
				if ( $width > $maxWidth || $height > $maxHeight ) {
					# Calculate the thumbnail size.
					# First case, the limiting factor is the width, not the height.
					if ( $width / $height >= $maxWidth / $maxHeight ) {
						$height = round( $height * $maxWidth / $width );
						$width = $maxWidth;
						# Note that $height <= $maxHeight now.
					} else {
						$newwidth = floor( $width * $maxHeight / $height );
						$height = round( $height * $newwidth / $width );
						$width = $newwidth;
						# Note that $height <= $maxHeight now, but might not be identical
						# because of rounding.
					}
					$msgbig  = wfMsgHtml( 'show-big-image' );
					$otherSizes = array();
					foreach ( $wgImageLimits as $size ) {
						if ( $size[0] < $width_orig && $size[1] < $height_orig &&
								$size[0] != $width && $size[1] != $height ) {
							$otherSizes[] = $this->makeSizeLink( $params, $size[0], $size[1] );
						}
					}
					$msgsmall = wfMessage( 'show-big-image-preview' )->
						rawParams( $this->makeSizeLink( $params, $width, $height ) )->
						parse();
					if ( count( $otherSizes ) && $this->displayImg->getRepo()->canTransformVia404() ) {
						$msgsmall .= ' ' .
						Html::rawElement( 'span', array( 'class' => 'mw-filepage-other-resolutions' ),
							wfMessage( 'show-big-image-other' )->rawParams( $lang->pipeList( $otherSizes ) )->
							params( count( $otherSizes ) )->parse()
						);
					}
				} elseif ( $width == 0 && $height == 0 ){
					# Some sort of audio file that doesn't have dimensions
					# Don't output a no hi res message for such a file
					$msgsmall = '';
				} else {
					# Image is small enough to show full size on image page
					$msgsmall = wfMessage( 'file-nohires' )->parse();
				}

				$params['width'] = $width;
				$params['height'] = $height;
				$thumbnail = $this->displayImg->transform( $params );

				$showLink = true;
				$anchorclose = Html::rawElement( 'div', array( 'class' => 'mw-filepage-resolutioninfo' ), $msgsmall );

				$isMulti = $this->displayImg->isMultipage() && $this->displayImg->pageCount() > 1;
				if ( $isMulti ) {
					$out->addHTML( '<table class="multipageimage"><tr><td>' );
				}

				if ( $thumbnail ) {
					$options = array(
						'alt' => $this->displayImg->getTitle()->getPrefixedText(),
						'file-link' => true,
					);
					$out->addHTML( '<div class="fullImageLink" id="file">' .
						$thumbnail->toHtml( $options ) .
						$anchorclose . "</div>\n" );
				}

				if ( $isMulti ) {
					$count = $this->displayImg->pageCount();

					if ( $page > 1 ) {
						$label = $out->parse( wfMsg( 'imgmultipageprev' ), false );
						$link = Linker::link(
							$this->getTitle(),
							$label,
							array(),
							array( 'page' => $page - 1 ),
							array( 'known', 'noclasses' )
						);
						$thumb1 = Linker::makeThumbLinkObj( $this->getTitle(), $this->displayImg, $link, $label, 'none',
							array( 'page' => $page - 1 ) );
					} else {
						$thumb1 = '';
					}

					if ( $page < $count ) {
						$label = wfMsg( 'imgmultipagenext' );
						$link = Linker::link(
							$this->getTitle(),
							$label,
							array(),
							array( 'page' => $page + 1 ),
							array( 'known', 'noclasses' )
						);
						$thumb2 = Linker::makeThumbLinkObj( $this->getTitle(), $this->displayImg, $link, $label, 'none',
							array( 'page' => $page + 1 ) );
					} else {
						$thumb2 = '';
					}

					global $wgScript;

					$formParams = array(
						'name' => 'pageselector',
						'action' => $wgScript,
						'onchange' => 'document.pageselector.submit();',
					);
					$options = array();
					for ( $i = 1; $i <= $count; $i++ ) {
						$options[] = Xml::option( $lang->formatNum( $i ), $i, $i == $page );
					}
					$select = Xml::tags( 'select',
						array( 'id' => 'pageselector', 'name' => 'page' ),
						implode( "\n", $options ) );

					$out->addHTML(
						'</td><td><div class="multipageimagenavbox">' .
						Xml::openElement( 'form', $formParams ) .
						Html::hidden( 'title', $this->getTitle()->getPrefixedDBkey() ) .
						wfMsgExt( 'imgmultigoto', array( 'parseinline', 'replaceafter' ), $select ) .
						Xml::submitButton( wfMsg( 'imgmultigo' ) ) .
						Xml::closeElement( 'form' ) .
						"<hr />$thumb1\n$thumb2<br style=\"clear: both\" /></div></td></tr></table>"
					);
				}
			} else {
				# if direct link is allowed but it's not a renderable image, show an icon.
				if ( $this->displayImg->isSafeFile() ) {
					$icon = $this->displayImg->iconThumb();

					$out->addHTML( '<div class="fullImageLink" id="file">' .
						$icon->toHtml( array( 'file-link' => true ) ) .
						"</div>\n" );
				}

				$showLink = true;
			}

			if ( $showLink ) {
				$filename = wfEscapeWikiText( $this->displayImg->getName() );
				$linktext = $filename;
				if ( isset( $msgbig ) ) {
					$linktext = wfEscapeWikiText( $msgbig );
				}
				$medialink = "[[Media:$filename|$linktext]]";

				if ( !$this->displayImg->isSafeFile() ) {
					$warning = wfMsgNoTrans( 'mediawarning' );
					// dirmark is needed here to separate the file name, which
					// most likely ends in Latin characters, from the description,
					// which may begin with the file type. In RTL environment
					// this will get messy.
					// The dirmark, however, must not be immediately adjacent
					// to the filename, because it can get copied with it.
					// See bug 25277.
					$out->addWikiText( <<<EOT
<div class="fullMedia"><span class="dangerousLink">{$medialink}</span> $dirmark<span class="fileInfo">$longDesc</span></div>
<div class="mediaWarning">$warning</div>
EOT
						);
				} else {
					$out->addWikiText( <<<EOT
<div class="fullMedia">{$medialink} {$dirmark}<span class="fileInfo">$longDesc</span>
</div>
EOT
					);
				}
			}

			if ( !$this->displayImg->isLocal() ) {
				$this->printSharedImageText();
			}
		} else {
			# Image does not exist
			if ( !$this->getID() ) {
				# No article exists either
				# Show deletion log to be consistent with normal articles
				LogEventsList::showLogExtract(
					$out,
					array( 'delete', 'move' ),
					$this->getTitle()->getPrefixedText(),
					'',
					array(  'lim' => 10,
						'conds' => array( "log_action != 'revision'" ),
						'showIfEmpty' => false,
						'msgKey' => array( 'moveddeleted-notice' )
					)
				);
			}

			if ( $wgEnableUploads && $user->isAllowed( 'upload' ) ) {
				// Only show an upload link if the user can upload
				$uploadTitle = SpecialPage::getTitleFor( 'Upload' );
				$nofile = array(
					'filepage-nofile-link',
					$uploadTitle->getFullURL( array( 'wpDestFile' => $this->mPage->getFile()->getName() ) )
				);
			} else {
				$nofile = 'filepage-nofile';
			}
			// Note, if there is an image description page, but
			// no image, then this setRobotPolicy is overriden
			// by Article::View().
			$out->setRobotPolicy( 'noindex,nofollow' );
			$out->wrapWikiMsg( "<div id='mw-imagepage-nofile' class='plainlinks'>\n$1\n</div>", $nofile );
			if ( !$this->getID() && $wgSend404Code ) {
				// If there is no image, no shared image, and no description page,
				// output a 404, to be consistent with articles.
				$request->response()->header( 'HTTP/1.1 404 Not Found' );
			}
		}
		$out->setFileVersion( $this->displayImg );
	}

	/**
	 * Creates an thumbnail of specified size and returns an HTML link to it
	 * @param $params array Scaler parameters
	 * @param $width int
	 * @param $height int
	 * @return string
	 */
	private function makeSizeLink( $params, $width, $height ) {
		$params['width'] = $width;
		$params['height'] = $height;
		$thumbnail = $this->displayImg->transform( $params );
		if ( $thumbnail && !$thumbnail->isError() ) {
			return Html::rawElement( 'a', array(
				'href' => $thumbnail->getUrl(),
				'class' => 'mw-thumbnail-link'
				), wfMessage( 'show-big-image-size' )->numParams(
					$thumbnail->getWidth(), $thumbnail->getHeight()
				)->parse() );
		} else {
			return '';
		}
	}

	/**
	 * Show a notice that the file is from a shared repository
	 */
	protected function printSharedImageText() {
		$out = $this->getContext()->getOutput();
		$this->loadFile();

		$descUrl = $this->mPage->getFile()->getDescriptionUrl();
		$descText = $this->mPage->getFile()->getDescriptionText();

		/* Add canonical to head if there is no local page for this shared file */
		if( $descUrl && $this->mPage->getID() == 0 ) {
			$out->addLink( array( 'rel' => 'canonical', 'href' => $descUrl ) );
		}

		$wrap = "<div class=\"sharedUploadNotice\">\n$1\n</div>\n";
		$repo = $this->mPage->getFile()->getRepo()->getDisplayName();

		if ( $descUrl && $descText && wfMsgNoTrans( 'sharedupload-desc-here' ) !== '-'  ) {
			$out->wrapWikiMsg( $wrap, array( 'sharedupload-desc-here', $repo, $descUrl ) );
		} elseif ( $descUrl && wfMsgNoTrans( 'sharedupload-desc-there' ) !== '-' ) {
			$out->wrapWikiMsg( $wrap, array( 'sharedupload-desc-there', $repo, $descUrl ) );
		} else {
			$out->wrapWikiMsg( $wrap, array( 'sharedupload', $repo ), ''/*BACKCOMPAT*/ );
		}

		if ( $descText ) {
			$this->mExtraDescription = $descText;
		}
	}

	public function getUploadUrl() {
		$this->loadFile();
		$uploadTitle = SpecialPage::getTitleFor( 'Upload' );
		return $uploadTitle->getFullURL( array(
			'wpDestFile' => $this->mPage->getFile()->getName(),
			'wpForReUpload' => 1
		 ) );
	}

	/**
	 * Print out the various links at the bottom of the image page, e.g. reupload,
	 * external editing (and instructions link) etc.
	 */
	protected function uploadLinksBox() {
		global $wgEnableUploads, $wgUseExternalEditor;

		if ( !$wgEnableUploads ) {
			return;
		}

		$this->loadFile();
		if ( !$this->mPage->getFile()->isLocal() ) {
			return;
		}

		$out = $this->getContext()->getOutput();
		$out->addHTML( "<br /><ul>\n" );

		# "Upload a new version of this file" link
		if ( UploadBase::userCanReUpload( $this->getContext()->getUser(), $this->mPage->getFile()->name ) ) {
			$ulink = Linker::makeExternalLink( $this->getUploadUrl(), wfMsg( 'uploadnewversion-linktext' ) );
			$out->addHTML( "<li id=\"mw-imagepage-reupload-link\"><div class=\"plainlinks\">{$ulink}</div></li>\n" );
		}

		# External editing link
		if ( $wgUseExternalEditor ) {
			$elink = Linker::link(
				$this->getTitle(),
				wfMsgHtml( 'edit-externally' ),
				array(),
				array(
					'action' => 'edit',
					'externaledit' => 'true',
					'mode' => 'file'
				),
				array( 'known', 'noclasses' )
			);
			$out->addHTML(
				'<li id="mw-imagepage-edit-external">' . $elink . ' <small>' .
				wfMsgExt( 'edit-externally-help', array( 'parseinline' ) ) .
				"</small></li>\n"
			);
		}

		$out->addHTML( "</ul>\n" );
	}

	protected function closeShowImage() { } # For overloading

	/**
	 * If the page we've just displayed is in the "Image" namespace,
	 * we follow it with an upload history of the image and its usage.
	 */
	protected function imageHistory() {
		$this->loadFile();
		$out = $this->getContext()->getOutput();
		$pager = new ImageHistoryPseudoPager( $this );
		$out->addHTML( $pager->getBody() );
		$out->preventClickjacking( $pager->getPreventClickjacking() );

		$this->mPage->getFile()->resetHistory(); // free db resources

		# Exist check because we don't want to show this on pages where an image
		# doesn't exist along with the noimage message, that would suck. -ævar
		if ( $this->mPage->getFile()->exists() ) {
			$this->uploadLinksBox();
		}
	}

	/**
	 * @param $target
	 * @param $limit
	 * @return ResultWrapper
	 */
	protected function queryImageLinks( $target, $limit ) {
		$dbr = wfGetDB( DB_SLAVE );

		return $dbr->select(
			array( 'imagelinks', 'page' ),
			array( 'page_namespace', 'page_title', 'page_is_redirect', 'il_to' ),
			array( 'il_to' => $target, 'il_from = page_id' ),
			__METHOD__,
			array( 'LIMIT' => $limit + 1, 'ORDER BY' => 'il_from', )
		);
	}

	protected function imageLinks() {
		$limit = 100;

		$out = $this->getContext()->getOutput();
		$res = $this->queryImageLinks( $this->getTitle()->getDbKey(), $limit + 1);
		$rows = array();
		$redirects = array();
		foreach ( $res as $row ) {
			if ( $row->page_is_redirect ) {
				$redirects[$row->page_title] = array();
			}
			$rows[] = $row;
		}
		$count = count( $rows );

		$hasMore = $count > $limit;
		if ( !$hasMore && count( $redirects ) ) {
			$res = $this->queryImageLinks( array_keys( $redirects ),
				$limit - count( $rows ) + 1 );
			foreach ( $res as $row ) {
				$redirects[$row->il_to][] = $row;
				$count++;
			}
			$hasMore = ( $res->numRows() + count( $rows ) ) > $limit;
		}

		if ( $count == 0 ) {
			$out->wrapWikiMsg(
				Html::rawElement( 'div',
					array( 'id' => 'mw-imagepage-nolinkstoimage' ), "\n$1\n" ),
				'nolinkstoimage'
			);
			return;
		}

		$out->addHTML( "<div id='mw-imagepage-section-linkstoimage'>\n" );
		if ( !$hasMore ) {
			$out->addWikiMsg( 'linkstoimage', $count );
		} else {
			// More links than the limit. Add a link to [[Special:Whatlinkshere]]
			$out->addWikiMsg( 'linkstoimage-more',
				$this->getContext()->getLanguage()->formatNum( $limit ),
				$this->getTitle()->getPrefixedDBkey()
			);
		}

		$out->addHTML(
			Html::openElement( 'ul',
				array( 'class' => 'mw-imagepage-linkstoimage' ) ) . "\n"
		);
		$count = 0;

		// Sort the list by namespace:title
		usort( $rows, array( $this, 'compare' ) );

		// Create links for every element
		$currentCount = 0;
		foreach( $rows as $element ) {
			$currentCount++;
			if ( $currentCount > $limit ) {
				break;
			}

			$link = Linker::linkKnown( Title::makeTitle( $element->page_namespace, $element->page_title ) );
			if ( !isset( $redirects[$element->page_title] ) ) {
				$liContents = $link;
			} else {
				$ul = "<ul class='mw-imagepage-redirectstofile'>\n";
				foreach ( $redirects[$element->page_title] as $row ) {
					$currentCount++;
					if ( $currentCount > $limit ) {
						break;
					}

					$link2 = Linker::linkKnown( Title::makeTitle( $row->page_namespace, $row->page_title ) );
					$ul .= Html::rawElement(
						'li',
						array( 'id' => 'mw-imagepage-linkstoimage-ns' . $element->page_namespace ),
						$link2
						) . "\n";
				}
				$ul .= '</ul>';
				$liContents = wfMessage( 'linkstoimage-redirect' )->rawParams(
					$link, $ul )->parse();
			}
			$out->addHTML( Html::rawElement(
					'li',
					array( 'id' => 'mw-imagepage-linkstoimage-ns' . $element->page_namespace ),
					$liContents
				) . "\n"
			);

		};
		$out->addHTML( Html::closeElement( 'ul' ) . "\n" );
		$res->free();

		// Add a links to [[Special:Whatlinkshere]]
		if ( $count > $limit ) {
			$out->addWikiMsg( 'morelinkstoimage', $this->getTitle()->getPrefixedDBkey() );
		}
		$out->addHTML( Html::closeElement( 'div' ) . "\n" );
	}

	protected function imageDupes() {
		$this->loadFile();
		$out = $this->getContext()->getOutput();

		$dupes = $this->mPage->getDuplicates();
		if ( count( $dupes ) == 0 ) {
			return;
		}

		$out->addHTML( "<div id='mw-imagepage-section-duplicates'>\n" );
		$out->addWikiMsg( 'duplicatesoffile',
			$this->getContext()->getLanguage()->formatNum( count( $dupes ) ), $this->getTitle()->getDBkey()
		);
		$out->addHTML( "<ul class='mw-imagepage-duplicates'>\n" );

		/**
		 * @var $file File
		 */
		foreach ( $dupes as $file ) {
			$fromSrc = '';
			if ( $file->isLocal() ) {
				$link = Linker::link(
					$file->getTitle(),
					null,
					array(),
					array(),
					array( 'known', 'noclasses' )
				);
			} else {
				$link = Linker::makeExternalLink( $file->getDescriptionUrl(),
					$file->getTitle()->getPrefixedText() );
				$fromSrc = wfMsg( 'shared-repo-from', $file->getRepo()->getDisplayName() );
			}
			$out->addHTML( "<li>{$link} {$fromSrc}</li>\n" );
		}
		$out->addHTML( "</ul></div>\n" );
	}

	/**
	 * Delete the file, or an earlier version of it
	 */
	public function delete() {
		$file = $this->mPage->getFile();
		if ( !$file->exists() || !$file->isLocal() || $file->getRedirected() ) {
			// Standard article deletion
			parent::delete();
			return;
		}

		$deleter = new FileDeleteForm( $file );
		$deleter->execute();
	}

	/**
	 * Display an error with a wikitext description
	 *
	 * @param $description String
	 */
	function showError( $description ) {
		$out = $this->getContext()->getOutput();
		$out->setPageTitle( wfMessage( 'internalerror' ) );
		$out->setRobotPolicy( 'noindex,nofollow' );
		$out->setArticleRelated( false );
		$out->enableClientCache( false );
		$out->addWikiText( $description );
	}

	/**
	 * Callback for usort() to do link sorts by (namespace, title)
	 * Function copied from Title::compare()
	 *
	 * @param $a object page to compare with
	 * @param $b object page to compare with
	 * @return Integer: result of string comparison, or namespace comparison
	 */
	protected function compare( $a, $b ) {
		if ( $a->page_namespace == $b->page_namespace ) {
			return strcmp( $a->page_title, $b->page_title );
		} else {
			return $a->page_namespace - $b->page_namespace;
		}
	}
}

/**
 * Builds the image revision log shown on image pages
 *
 * @ingroup Media
 */
class ImageHistoryList extends ContextSource {

	/**
	 * @var Title
	 */
	protected $title;

	/**
	 * @var File
	 */
	protected $img;

	/**
	 * @var ImagePage
	 */
	protected $imagePage;

	/**
	 * @var File
	 */
	protected $current;

	protected $repo, $showThumb;
	protected $preventClickjacking = false;

	/**
	 * @param ImagePage $imagePage
	 */
	public function __construct( $imagePage ) {
		global $wgShowArchiveThumbnails;
		$this->current = $imagePage->getFile();
		$this->img = $imagePage->getDisplayedFile();
		$this->title = $imagePage->getTitle();
		$this->imagePage = $imagePage;
		$this->showThumb = $wgShowArchiveThumbnails && $this->img->canRender();
		$this->setContext( $imagePage->getContext() );
	}

	/**
	 * @return ImagePage
	 */
	public function getImagePage() {
		return $this->imagePage;
	}

	/**
	 * @return File
	 */
	public function getFile() {
		return $this->img;
	}

	/**
	 * @param $navLinks string
	 * @return string
	 */
	public function beginImageHistoryList( $navLinks = '' ) {
		return Xml::element( 'h2', array( 'id' => 'filehistory' ), wfMsg( 'filehist' ) ) . "\n"
			. "<div id=\"mw-imagepage-section-filehistory\">\n"
			. $this->getOutput()->parse( wfMsgNoTrans( 'filehist-help' ) )
			. $navLinks . "\n"
			. Xml::openElement( 'table', array( 'class' => 'wikitable filehistory' ) ) . "\n"
			. '<tr><td></td>'
			. ( $this->current->isLocal() && ( $this->getUser()->isAllowedAny( 'delete', 'deletedhistory' ) ) ? '<td></td>' : '' )
			. '<th>' . wfMsgHtml( 'filehist-datetime' ) . '</th>'
			. ( $this->showThumb ? '<th>' . wfMsgHtml( 'filehist-thumb' ) . '</th>' : '' )
			. '<th>' . wfMsgHtml( 'filehist-dimensions' ) . '</th>'
			. '<th>' . wfMsgHtml( 'filehist-user' ) . '</th>'
			. '<th>' . wfMsgHtml( 'filehist-comment' ) . '</th>'
			. "</tr>\n";
	}

	/**
	 * @param $navLinks string
	 * @return string
	 */
	public function endImageHistoryList( $navLinks = '' ) {
		return "</table>\n$navLinks\n</div>\n";
	}

	/**
	 * @param $iscur
	 * @param $file File
	 * @return string
	 */
	public function imageHistoryLine( $iscur, $file ) {
		global $wgContLang;

		$user = $this->getUser();
		$lang = $this->getLanguage();
		$timestamp = wfTimestamp( TS_MW, $file->getTimestamp() );
		$img = $iscur ? $file->getName() : $file->getArchiveName();
		$userId = $file->getUser( 'id' );
		$userText = $file->getUser( 'text' );
		$description = $file->getDescription();

		$local = $this->current->isLocal();
		$row = $selected = '';

		// Deletion link
		if ( $local && ( $user->isAllowedAny( 'delete', 'deletedhistory' ) ) ) {
			$row .= '<td>';
			# Link to remove from history
			if ( $user->isAllowed( 'delete' ) ) {
				$q = array( 'action' => 'delete' );
				if ( !$iscur ) {
					$q['oldimage'] = $img;
				}
				$row .= Linker::link(
					$this->title,
					wfMsgHtml( $iscur ? 'filehist-deleteall' : 'filehist-deleteone' ),
					array(), $q, array( 'known' )
				);
			}
			# Link to hide content. Don't show useless link to people who cannot hide revisions.
			$canHide = $user->isAllowed( 'deleterevision' );
			if ( $canHide || ( $user->isAllowed( 'deletedhistory' ) && $file->getVisibility() ) ) {
				if ( $user->isAllowed( 'delete' ) ) {
					$row .= '<br />';
				}
				// If file is top revision or locked from this user, don't link
				if ( $iscur || !$file->userCan( File::DELETED_RESTRICTED ) ) {
					$del = Linker::revDeleteLinkDisabled( $canHide );
				} else {
					list( $ts, ) = explode( '!', $img, 2 );
					$query = array(
						'type'   => 'oldimage',
						'target' => $this->title->getPrefixedText(),
						'ids'    => $ts,
					);
					$del = Linker::revDeleteLink( $query,
						$file->isDeleted( File::DELETED_RESTRICTED ), $canHide );
				}
				$row .= $del;
			}
			$row .= '</td>';
		}

		// Reversion link/current indicator
		$row .= '<td>';
		if ( $iscur ) {
			$row .= wfMsgHtml( 'filehist-current' );
		} elseif ( $local && $user->isLoggedIn() && $this->title->userCan( 'edit' ) ) {
			if ( $file->isDeleted( File::DELETED_FILE ) ) {
				$row .= wfMsgHtml( 'filehist-revert' );
			} else {
				$row .= Linker::link(
					$this->title,
					wfMsgHtml( 'filehist-revert' ),
					array(),
					array(
						'action' => 'revert',
						'oldimage' => $img,
						'wpEditToken' => $user->getEditToken( $img )
					),
					array( 'known', 'noclasses' )
				);
			}
		}
		$row .= '</td>';

		// Date/time and image link
		if ( $file->getTimestamp() === $this->img->getTimestamp() ) {
			$selected = "class='filehistory-selected'";
		}
		$row .= "<td $selected style='white-space: nowrap;'>";
		if ( !$file->userCan( File::DELETED_FILE ) ) {
			# Don't link to unviewable files
			$row .= '<span class="history-deleted">' . $lang->timeanddate( $timestamp, true ) . '</span>';
		} elseif ( $file->isDeleted( File::DELETED_FILE ) ) {
			if ( $local ) {
				$this->preventClickjacking();
				$revdel = SpecialPage::getTitleFor( 'Revisiondelete' );
				# Make a link to review the image
				$url = Linker::link(
					$revdel,
					$lang->timeanddate( $timestamp, true ),
					array(),
					array(
						'target' => $this->title->getPrefixedText(),
						'file' => $img,
						'token' => $user->getEditToken( $img )
					),
					array( 'known', 'noclasses' )
				);
			} else {
				$url = $lang->timeanddate( $timestamp, true );
			}
			$row .= '<span class="history-deleted">' . $url . '</span>';
		} else {
			$url = $iscur ? $this->current->getUrl() : $this->current->getArchiveUrl( $img );
			$row .= Xml::element( 'a', array( 'href' => $url ), $lang->timeanddate( $timestamp, true ) );
		}
		$row .= "</td>";

		// Thumbnail
		if ( $this->showThumb ) {
			$row .= '<td>' . $this->getThumbForLine( $file ) . '</td>';
		}

		// Image dimensions + size
		$row .= '<td>';
		$row .= htmlspecialchars( $file->getDimensionsString() );
		$row .= ' <span style="white-space: nowrap;">(' . Linker::formatSize( $file->getSize() ) . ')</span>';
		$row .= '</td>';

		// Uploading user
		$row .= '<td>';
		// Hide deleted usernames
		if ( $file->isDeleted( File::DELETED_USER ) ) {
			$row .= '<span class="history-deleted">' . wfMsgHtml( 'rev-deleted-user' ) . '</span>';
		} else {
			if ( $local ) {
				$row .= Linker::userLink( $userId, $userText ) . ' <span style="white-space: nowrap;">' .
				Linker::userToolLinks( $userId, $userText ) . '</span>';
			} else {
				$row .= htmlspecialchars( $userText );
			}
		}
		$row .= '</td>';

		// Don't show deleted descriptions
		if ( $file->isDeleted( File::DELETED_COMMENT ) ) {
			$row .= '<td><span class="history-deleted">' . wfMsgHtml( 'rev-deleted-comment' ) . '</span></td>';
		} else {
			$row .= '<td dir="' . $wgContLang->getDir() . '">' . Linker::formatComment( $description, $this->title ) . '</td>';
		}

		$rowClass = null;
		wfRunHooks( 'ImagePageFileHistoryLine', array( $this, $file, &$row, &$rowClass ) );
		$classAttr = $rowClass ? " class='$rowClass'" : '';

		return "<tr{$classAttr}>{$row}</tr>\n";
	}

	/**
	 * @param $file File
	 * @return string
	 */
	protected function getThumbForLine( $file ) {
		$lang = $this->getLanguage();
		if ( $file->allowInlineDisplay() && $file->userCan( File::DELETED_FILE ) && !$file->isDeleted( File::DELETED_FILE ) ) {
			$params = array(
				'width' => '120',
				'height' => '120',
			);
			$timestamp = wfTimestamp( TS_MW, $file->getTimestamp() );

			$thumbnail = $file->transform( $params );
			$options = array(
				'alt' => wfMsg( 'filehist-thumbtext',
					$lang->timeanddate( $timestamp, true ),
					$lang->date( $timestamp, true ),
					$lang->time( $timestamp, true ) ),
				'file-link' => true,
			);

			if ( !$thumbnail ) {
				return wfMsgHtml( 'filehist-nothumb' );
			}

			return $thumbnail->toHtml( $options );
		} else {
			return wfMsgHtml( 'filehist-nothumb' );
		}
	}

	/**
	 * @param $enable bool
	 */
	protected function preventClickjacking( $enable = true ) {
		$this->preventClickjacking = $enable;
	}

	/**
	 * @return bool
	 */
	public function getPreventClickjacking() {
		return $this->preventClickjacking;
	}
}

class ImageHistoryPseudoPager extends ReverseChronologicalPager {
	protected $preventClickjacking = false;

	/**
	 * @var File
	 */
	protected $mImg;

	/**
	 * @var Title
	 */
	protected $mTitle;

	/**
	 * @param ImagePage $imagePage
	 */
	function __construct( $imagePage ) {
		parent::__construct();
		$this->mImagePage = $imagePage;
		$this->mTitle = clone ( $imagePage->getTitle() );
		$this->mTitle->setFragment( '#filehistory' );
		$this->mImg = null;
		$this->mHist = array();
		$this->mRange = array( 0, 0 ); // display range
	}

	/**
	 * @return Title
	 */
	function getTitle() {
		return $this->mTitle;
	}

	function getQueryInfo() {
		return false;
	}

	/**
	 * @return string
	 */
	function getIndexField() {
		return '';
	}

	/**
	 * @param $row object
	 * @return string
	 */
	function formatRow( $row ) {
		return '';
	}

	/**
	 * @return string
	 */
	function getBody() {
		$s = '';
		$this->doQuery();
		if ( count( $this->mHist ) ) {
			$list = new ImageHistoryList( $this->mImagePage );
			# Generate prev/next links
			$navLink = $this->getNavigationBar();
			$s = $list->beginImageHistoryList( $navLink );
			// Skip rows there just for paging links
			for ( $i = $this->mRange[0]; $i <= $this->mRange[1]; $i++ ) {
				$file = $this->mHist[$i];
				$s .= $list->imageHistoryLine( !$file->isOld(), $file );
			}
			$s .= $list->endImageHistoryList( $navLink );

			if ( $list->getPreventClickjacking() ) {
				$this->preventClickjacking();
			}
		}
		return $s;
	}

	function doQuery() {
		if ( $this->mQueryDone ) {
			return;
		}
		$this->mImg = $this->mImagePage->getFile(); // ensure loading
		if ( !$this->mImg->exists() ) {
			return;
		}
		$queryLimit = $this->mLimit + 1; // limit plus extra row
		if ( $this->mIsBackwards ) {
			// Fetch the file history
			$this->mHist = $this->mImg->getHistory( $queryLimit, null, $this->mOffset, false );
			// The current rev may not meet the offset/limit
			$numRows = count( $this->mHist );
			if ( $numRows <= $this->mLimit && $this->mImg->getTimestamp() > $this->mOffset ) {
				$this->mHist = array_merge( array( $this->mImg ), $this->mHist );
			}
		} else {
			// The current rev may not meet the offset
			if ( !$this->mOffset || $this->mImg->getTimestamp() < $this->mOffset ) {
				$this->mHist[] = $this->mImg;
			}
			// Old image versions (fetch extra row for nav links)
			$oiLimit = count( $this->mHist ) ? $this->mLimit : $this->mLimit + 1;
			// Fetch the file history
			$this->mHist = array_merge( $this->mHist,
				$this->mImg->getHistory( $oiLimit, $this->mOffset, null, false ) );
		}
		$numRows = count( $this->mHist ); // Total number of query results
		if ( $numRows ) {
			# Index value of top item in the list
			$firstIndex = $this->mIsBackwards ?
				$this->mHist[$numRows - 1]->getTimestamp() : $this->mHist[0]->getTimestamp();
			# Discard the extra result row if there is one
			if ( $numRows > $this->mLimit && $numRows > 1 ) {
				if ( $this->mIsBackwards ) {
					# Index value of item past the index
					$this->mPastTheEndIndex = $this->mHist[0]->getTimestamp();
					# Index value of bottom item in the list
					$lastIndex = $this->mHist[1]->getTimestamp();
					# Display range
					$this->mRange = array( 1, $numRows - 1 );
				} else {
					# Index value of item past the index
					$this->mPastTheEndIndex = $this->mHist[$numRows - 1]->getTimestamp();
					# Index value of bottom item in the list
					$lastIndex = $this->mHist[$numRows - 2]->getTimestamp();
					# Display range
					$this->mRange = array( 0, $numRows - 2 );
				}
			} else {
				# Setting indexes to an empty string means that they will be
				# omitted if they would otherwise appear in URLs. It just so
				# happens that this  is the right thing to do in the standard
				# UI, in all the relevant cases.
				$this->mPastTheEndIndex = '';
				# Index value of bottom item in the list
				$lastIndex = $this->mIsBackwards ?
					$this->mHist[0]->getTimestamp() : $this->mHist[$numRows - 1]->getTimestamp();
				# Display range
				$this->mRange = array( 0, $numRows - 1 );
			}
		} else {
			$firstIndex = '';
			$lastIndex = '';
			$this->mPastTheEndIndex = '';
		}
		if ( $this->mIsBackwards ) {
			$this->mIsFirst = ( $numRows < $queryLimit );
			$this->mIsLast = ( $this->mOffset == '' );
			$this->mLastShown = $firstIndex;
			$this->mFirstShown = $lastIndex;
		} else {
			$this->mIsFirst = ( $this->mOffset == '' );
			$this->mIsLast = ( $numRows < $queryLimit );
			$this->mLastShown = $lastIndex;
			$this->mFirstShown = $firstIndex;
		}
		$this->mQueryDone = true;
	}

	/**
	 * @param $enable bool
	 */
	protected function preventClickjacking( $enable = true ) {
		$this->preventClickjacking = $enable;
	}

	/**
	 * @return bool
	 */
	public function getPreventClickjacking() {
		return $this->preventClickjacking;
	}

}
