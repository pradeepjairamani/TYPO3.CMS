<?php
namespace TYPO3\CMS\Backend\Controller\File;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Resource\DuplicationBehavior;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Utility\File\ExtendedFileUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Gateway for TCE (TYPO3 Core Engine) file-handling through POST forms.
 * This script serves as the file administration part of the TYPO3 Core Engine.
 * Basically it includes two libraries which are used to manipulate files on the server.
 * Before TYPO3 4.3, it was located in typo3/tce_file.php and redirected back to a
 * $redirectURL. Since 4.3 this class is also used for accessing via AJAX
 */
class FileController
{
    /**
     * Array of file-operations.
     *
     * @var array
     */
    protected $file;

    /**
     * Clipboard operations array
     *
     * @var array
     */
    protected $CB;

    /**
     * Defines behaviour when uploading files with names that already exist; possible values are
     * the values of the \TYPO3\CMS\Core\Resource\DuplicationBehavior enumeration
     *
     * @var \TYPO3\CMS\Core\Resource\DuplicationBehavior
     */
    protected $overwriteExistingFiles;

    /**
     * The page where the user should be redirected after everything is done
     *
     * @var string
     */
    protected $redirect;

    /**
     * Internal, dynamic:
     * File processor object
     *
     * @var ExtendedFileUtility
     */
    protected $fileProcessor;

    /**
     * The result array from the file processor
     *
     * @var array
     */
    protected $fileData;

    /**
     * Constructor
     */
    public function __construct()
    {
        $GLOBALS['SOBE'] = $this;
        $this->init();
    }

    /**
     * Registering incoming data
     */
    protected function init()
    {
        // Set the GPvars from outside
        $this->file = GeneralUtility::_GP('data');
        if ($this->file === null) {
            // This happens in clipboard mode only
            $this->redirect = GeneralUtility::sanitizeLocalUrl(GeneralUtility::_GP('redirect'));
        } else {
            $mode = key($this->file);
            $elementKey = key($this->file[$mode]);
            $this->redirect = GeneralUtility::sanitizeLocalUrl($this->file[$mode][$elementKey]['redirect']);
        }
        $this->CB = GeneralUtility::_GP('CB');

        if (isset($this->file['rename'][0]['conflictMode'])) {
            $conflictMode = $this->file['rename'][0]['conflictMode'];
            unset($this->file['rename'][0]['conflictMode']);
            $this->overwriteExistingFiles = DuplicationBehavior::cast($conflictMode);
        } else {
            $this->overwriteExistingFiles = DuplicationBehavior::cast(GeneralUtility::_GP('overwriteExistingFiles'));
        }
        $this->initClipboard();
        $this->fileProcessor = GeneralUtility::makeInstance(ExtendedFileUtility::class);
    }

    /**
     * Initialize the Clipboard. This will fetch the data about files to paste/delete if such an action has been sent.
     */
    public function initClipboard()
    {
        if (is_array($this->CB)) {
            $clipObj = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Clipboard\Clipboard::class);
            $clipObj->initializeClipboard();
            if ($this->CB['paste']) {
                $clipObj->setCurrentPad($this->CB['pad']);
                $this->file = $clipObj->makePasteCmdArray_file($this->CB['paste'], $this->file);
            }
            if ($this->CB['delete']) {
                $clipObj->setCurrentPad($this->CB['pad']);
                $this->file = $clipObj->makeDeleteCmdArray_file($this->file);
            }
        }
    }

    /**
     * Performing the file admin action:
     * Initializes the objects, setting permissions, sending data to object.
     */
    public function main()
    {
        // Initializing:
        $this->fileProcessor->setActionPermissions();
        $this->fileProcessor->setExistingFilesConflictMode($this->overwriteExistingFiles);
        $this->fileProcessor->start($this->file);
        $this->fileData = $this->fileProcessor->processData();
    }

    /**
     * Redirecting the user after the processing has been done.
     * Might also display error messages directly, if any.
     */
    public function finish()
    {
        BackendUtility::setUpdateSignal('updateFolderTree');
        if ($this->redirect) {
            \TYPO3\CMS\Core\Utility\HttpUtility::redirect($this->redirect);
        }
    }

    /**
     * Injects the request object for the current request or subrequest
     * As this controller goes only through the main() method, it just redirects to the given URL afterwards.
     *
     * @param ServerRequestInterface $request the current request
     * @param ResponseInterface $response
     * @return ResponseInterface the response with the content
     */
    public function mainAction(ServerRequestInterface $request, ResponseInterface $response)
    {
        $this->main();

        BackendUtility::setUpdateSignal('updateFolderTree');

        // go and edit the new created file
        if ($request->getParsedBody()['edit']) {
            /** @var \TYPO3\CMS\Core\Resource\File $file */
            $file = $this->fileData['newfile'][0];
            $properties = $file->getProperties();
            $urlParameters = [
                'target' =>  $properties['storage'] . ':' . $properties['identifier']
            ];
            if ($this->redirect) {
                $urlParameters['returnUrl'] = $this->redirect;
            }
            /** @var \TYPO3\CMS\Backend\Routing\UriBuilder $uriBuilder */
            $uriBuilder = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Routing\UriBuilder::class);
            $this->redirect = (string)$uriBuilder->buildUriFromRoute('file_edit', $urlParameters);
        }
        if ($this->redirect) {
            return $response
                    ->withHeader('Location', GeneralUtility::locationHeaderUrl($this->redirect))
                    ->withStatus(303);
        }
        // empty response
        return $response;
    }

    /**
     * Handles the actual process from within the ajaxExec function
     * therefore, it does exactly the same as the real typo3/tce_file.php
     * but without calling the "finish" method, thus makes it simpler to deal with the
     * actual return value
     *
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @return ResponseInterface
     */
    public function processAjaxRequest(ServerRequestInterface $request, ResponseInterface $response)
    {
        $this->main();
        $errors = $this->fileProcessor->getErrorMessages();
        if (!empty($errors)) {
            $response->getBody()->write('<t3err>' . implode(',', $errors) . '</t3err>');
            $response = $response
                ->withHeader('Content-Type', 'text/html; charset=utf-8')
                ->withStatus(500, '(AJAX)');
        } else {
            $flatResult = [];
            foreach ($this->fileData as $action => $results) {
                foreach ($results as $result) {
                    if (is_array($result)) {
                        foreach ($result as $subResult) {
                            $flatResult[$action][] = $this->flattenResultDataValue($subResult);
                        }
                    } else {
                        $flatResult[$action][] = $this->flattenResultDataValue($result);
                    }
                }
            }
            return GeneralUtility::makeInstance(JsonResponse::class)->setPayload($flatResult);
        }
        return $response;
    }

    /**
     * Ajax entry point to check if a file exists in a folder
     *
     * @param ServerRequestInterface $request
     * @return ResponseInterface
     */
    public function fileExistsInFolderAction(ServerRequestInterface $request)
    {
        $fileName = $request->getParsedBody()['fileName'] ?? $request->getQueryParams()['fileName'];
        $fileTarget = $request->getParsedBody()['fileTarget'] ?? $request->getQueryParams()['fileTarget'];

        /** @var \TYPO3\CMS\Core\Resource\ResourceFactory $fileFactory */
        $fileFactory = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Resource\ResourceFactory::class);
        /** @var Folder $fileTargetObject */
        $fileTargetObject = $fileFactory->retrieveFileOrFolderObject($fileTarget);
        $processedFileName = $fileTargetObject->getStorage()->sanitizeFileName($fileName, $fileTargetObject);

        $result = [];
        if ($fileTargetObject->hasFile($processedFileName)) {
            $result = $this->flattenResultDataValue($fileTargetObject->getStorage()->getFileInFolder($processedFileName, $fileTargetObject));
        }
        return GeneralUtility::makeInstance(JsonResponse::class)->setPayload($result);
    }

    /**
     * Flatten result value from FileProcessor
     *
     * The value can be a File, Folder or boolean
     *
     * @param bool|\TYPO3\CMS\Core\Resource\File|\TYPO3\CMS\Core\Resource\Folder $result
     * @return bool|string|array
     */
    protected function flattenResultDataValue($result)
    {
        if ($result instanceof \TYPO3\CMS\Core\Resource\File) {
            $thumbUrl = '';
            if (GeneralUtility::inList($GLOBALS['TYPO3_CONF_VARS']['GFX']['imagefile_ext'], $result->getExtension())) {
                $processedFile = $result->process(\TYPO3\CMS\Core\Resource\ProcessedFile::CONTEXT_IMAGEPREVIEW, []);
                if ($processedFile) {
                    $thumbUrl = $processedFile->getPublicUrl(true);
                }
            }
            $iconFactory = GeneralUtility::makeInstance(IconFactory::class);
            $result = array_merge(
                $result->toArray(),
                [
                    'date' => BackendUtility::date($result->getModificationTime()),
                    'icon' => $iconFactory->getIconForFileExtension($result->getExtension(), Icon::SIZE_SMALL)->render(),
                    'thumbUrl' => $thumbUrl
                ]
            );
        } elseif ($result instanceof \TYPO3\CMS\Core\Resource\Folder) {
            $result = $result->getIdentifier();
        }

        return $result;
    }

    /**
     * Returns the current BE user.
     *
     * @return \TYPO3\CMS\Core\Authentication\BackendUserAuthentication
     */
    protected function getBackendUser()
    {
        return $GLOBALS['BE_USER'];
    }
}
