<?php
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

namespace Causal\FileList\Controller;

use Causal\FileList\Domain\Repository\FileRepository;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Resource\FileCollectionRepository;
use TYPO3\CMS\Core\Resource\Folder;
use TYPO3\CMS\Core\Resource\FolderInterface;

/**
 * File controller.
 *
 * @category    Controller
 * @package     TYPO3
 * @subpackage  tx_filelist
 * @author      Xavier Perseguers <xavier@causal.ch>
 * @copyright   Causal Sàrl
 * @license     http://www.gnu.org/copyleft/gpl.html
 */
class FileController extends \TYPO3\CMS\Extbase\Mvc\Controller\ActionController
{

    const SORT_BY_NAME = 'NAME';
    const SORT_BY_DATE = 'DATE';
    const SORT_BY_SIZE = 'SIZE';

    const SORT_DIRECTION_ASC = 'ASC';
    const SORT_DIRECTION_DESC = 'DESC';

    /**
     * @var FileRepository
     */
    protected $fileRepository;

    /**
     * @var \TYPO3\CMS\Extbase\Service\TypoScriptService
     */
    protected $typoScriptService;

    /**
     * @param \Causal\FileList\Domain\Repository\FileRepository $fileRepository
     * @return void
     */
    public function injectFileRepository(FileRepository $fileRepository)
    {
        $this->fileRepository = $fileRepository;
    }

    /**
     * @param \TYPO3\CMS\Extbase\Service\TypoScriptService $typoScriptService
     * @return void
     */
    public function inject(\TYPO3\CMS\Extbase\Service\TypoScriptService $typoScriptService)
    {
        $this->typoScriptService = $typoScriptService;
    }

    /**
     * Handles stdWrap on various settings.
     *
     * @return void
     */
    public function initializeAction()
    {
        $settings = $this->typoScriptService->convertPlainArrayToTypoScriptArray($this->settings);
        $contentObject = $this->configurationManager->getContentObject();
        $keys = ['path', 'dateFormat', 'fileIconRootPath'];

        foreach ($keys as $key) {
            if (isset($settings[$key . '.'])) {
                $this->settings[$key] = $contentObject->stdWrap($settings[$key], $settings[$key . '.']);
            }
        }

        // Special handling for "root" which may be either a string/stdWrap or an array
        if (isset($settings['root.'])) {
            if (!empty($settings['root'])) {
                // This is a stdWrap
                $this->settings['root'] = $contentObject->stdWrap($settings['root'], $settings['root.']);
            } else {
                // Apply stdWrap on the subarray
                $this->settings['root'] = [];

                foreach ($settings['root.'] as $key => $root) {
                    if (substr($key, -1) !== '.') {
                        if (!isset($settings['root.'][$key . '.'])) {
                            $this->settings['root'][] = $root;
                        }
                        continue;
                    }
                    $value = isset($settings['root.'][substr($key, 0, -1)])
                        ? $settings['root.'][substr($key, 0, -1)]
                        : '';
                    $value = $contentObject->stdWrap($value, $settings['root.'][$key]);
                    $this->settings['root'][] = $value;
                }
            }
        }
        if (!is_array($this->settings['root'])) {
            $root = $this->settings['root'];
            $this->settings['root'] = [];
            if (!empty($root)) {
                $this->settings['root'][] = $root;
            }
        }
        foreach ($this->settings['root'] as $key => $value) {
            $this->settings['root'][$key] = $this->canonicalizeAndCheckFolderIdentifier($value);
        }
    }

    /**
     * Listing of files.
     *
     * @param string $path Optional path of the subdirectory to be listed
     * @return void|string
     */
    public function listAction($path = '')
    {
        $files = [];
        $subfolders = [];
        $parentFolder = null;
        $breadcrumb = [];

        try {
            $this->checkConfiguration();

            // Sanitize requested path
            if (!empty($path)) {
                $path = $this->canonicalizeAndCheckFolderIdentifier($path);
            }

            // Collect files and folders to be shown
            switch ($this->settings['mode']) {
                case 'FOLDER':
                    $this->populateFromFolder($path, $files, $subfolders, $parentFolder, $breadcrumb);
                    break;

                case 'FILE_COLLECTIONS':
                    $this->populateFromFileCollections($files);
                    break;
            }
        } catch (\Exception $e) {
            return $this->error($e->getMessage());
        }

        // Sort files and folders
        $files = $this->sortFiles($files);
        $subfolders = $this->sortFolders($subfolders);

        // Mark files as "new" if needed
        // BEWARE: This needs to be done at the end since it is using an internal method which
        //         may break other operations such as sorting
        if ((int)$this->settings['newDuration'] > 0) {
            $newTimestamp = $GLOBALS['EXEC_TIME'] - 86400 * (int)$this->settings['newDuration'];
            foreach ($files as &$file) {
                $properties = $file->getProperties();
                $properties['tx_filelist']['isNew'] = $properties['creation_date'] >= $newTimestamp;
                $file->updateProperties($properties);
            }
        }

        $this->view->assignMultiple([
            'isEmpty' => $parentFolder === null && empty($subfolders) && empty($files),
            'breadcrumb' => $breadcrumb,
            'parent' => $parentFolder,
            'folders' => $subfolders,
            'files' => $files,
        ]);
    }

    /**
     * Canonicalizes and (basically) checks a FAL folder identifier.
     *
     * @param string $identifier
     * @return string
     */
    protected function canonicalizeAndCheckFolderIdentifier($identifier)
    {
        $prefix = '';
        if (preg_match('/^file:(\d+):(.*)$/', $identifier, $matches)) {
            $prefix = 'file:' . $matches[1] . ':';
            $identifier = $matches[2];
        }

        $identifier = \TYPO3\CMS\Core\Utility\PathUtility::getCanonicalPath($identifier);
        $identifier = $prefix . rtrim($identifier, '/') . '/';

        return $identifier;
    }

    /**
     * Checks plugin configuration and security settings.
     *
     * @return void
     * @throws \RuntimeException
     */
    protected function checkConfiguration()
    {
        // Sanitize configuration
        if (!empty($this->settings['path'])) {
            $this->settings['path'] = $this->canonicalizeAndCheckFolderIdentifier($this->settings['path']);
        }

        // Security check
        if (!empty($this->settings['path']) && !$this->isWithinRoot($this->settings['path'])) {
            throw new \RuntimeException(sprintf('Could not open directory "%s"', $this->settings['path']), 1452143787);
        }
    }

    /**
     * Returns true if $path is within the allowed root.
     *
     * @param string $path
     * @return bool
     */
    protected function isWithinRoot($path)
    {
        // No root defined: true by definition, if not, we have to check each allowed root
        $success = empty($this->settings['root']);

        foreach ($this->settings['root'] as $root) {
            $success |= GeneralUtility::isFirstPartOfStr($path, $root);
            if ($success) break;
        }

        return $success;
    }

    /**
     * Populates files and folders from a configured root path.
     *
     * @param string $path Optional subpath of $this->settings['path']
     * @param \TYPO3\CMS\Core\Resource\File[] &$files
     * @param Folder[] &$subfolders
     * @param Folder &$parentFolder
     * @param array &$breadcrumb
     * @return void
     * @throws \InvalidArgumentException|\TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException
     */
    protected function populateFromFolder($path, array &$files, array &$subfolders, Folder &$parentFolder = null, array &$breadcrumb)
    {
        if (!(bool)$this->settings['includeSubfolders']) {
            // No way!
            $path = '';
        }

        $rootFolder = $this->fileRepository->getFolderByIdentifier($this->settings['path']);

        $folder = null;
        if (!empty($path) && preg_match('/^file:(\d+):(.*)$/', $this->settings['path'], $matches)) {
            $storageUid = (int)$matches[1];
            $rootIdentifier = $matches[2];
            // Security check before blindly accepting the requested folder's content
            if ($path === $rootIdentifier) {
                $path = '';
            }
            if (!empty($path) && GeneralUtility::isFirstPartOfStr($path, $rootIdentifier)) {
                $identifier = 'file:' . $storageUid . ':' . $path;
                $folder = $this->fileRepository->getFolderByIdentifier($identifier);
            } else {
                $path = '';
            }
        }
        if ($folder === null) {
            $folder = $rootFolder;
        }

        // Retrieve the list of files
        $files = $folder->getFiles();

        // In a subfolder, so retrieve parent folder
        if (!empty($path)) {
            $parentFolder = $folder->getParentFolder();
        }

        if ((bool)$this->settings['includeSubfolders']) {
            $tempSubfolders = $folder->getSubfolders();
            foreach ($tempSubfolders as $subfolder) {
                switch ($subfolder->getRole()) {
                    case FolderInterface::ROLE_RECYCLER:
                    case FolderInterface::ROLE_PROCESSING:
                    case FolderInterface::ROLE_TEMPORARY:
                        // Special folder, should not be shown in Frontend
                        break;
                    default:
                        $subfolders[] = $subfolder;
                }
            }

            // Prepare the breadcrumb data
            if (!empty($subfolders) || !empty($path)) {
                $f = $folder;
                while ($this->settings['path'] !== 'file:' . $f->getCombinedIdentifier()) {
                    array_unshift($breadcrumb, ['folder' => $f]);
                    $f = $f->getParentFolder();
                }
                array_unshift($breadcrumb, ['folder' => $rootFolder, 'isRoot' => true]);
                $breadcrumb[count($breadcrumb) - 1]['state'] = 'active';
            }
        }

        // Tag the page cache so that FAL signal operations may be listened to in
        // order to flush corresponding page cache
        $cacheTags = [
            'tx_filelist_folder_' . $folder->getHashedIdentifier(),
        ];
        $GLOBALS['TSFE']->addCacheTags($cacheTags);
    }

    /**
     * Populates files from a list of file collections.
     *
     * @param \TYPO3\CMS\Core\Resource\File[] $files
     * @return void
     * @throws \InvalidArgumentException|\TYPO3\CMS\Core\Resource\Exception\InsufficientFolderAccessPermissionsException
     */
    protected function populateFromFileCollections(array &$files)
    {
        $folders = [];

        /** @var FileCollectionRepository $fileCollectionRepository */
        $fileCollectionRepository = $this->objectManager->get(FileCollectionRepository::class);
        if (!empty($this->settings['path'])) {
            // Returned files needs to be within a given root path
            $folders[] = $this->fileRepository->getFolderByIdentifier($this->settings['path']);
        } else {
            foreach ($this->settings['root'] as $root) {
                $folders[] = $this->fileRepository->getFolderByIdentifier($root);
            }
        }

        $collectionUids = GeneralUtility::intExplode(',', $this->settings['file_collections'], true);
        foreach ($collectionUids as $uid) {
            $collection = $fileCollectionRepository->findByUid($uid);
            if ($collection !== null) {
                $collection->loadContents();
                /** @var \TYPO3\CMS\Core\Resource\File[] $collectionFiles */
                $collectionFiles = $collection->getItems();
                if (empty($folders)) {
                    $files += $collectionFiles;
                } else {
                    foreach ($collectionFiles as $file) {
                        $success = false;
                        foreach ($folders as $folder) {
                            if ($file->getStorage() === $folder->getStorage()) {
                                // TODO: Check if this is the correct way to filter out files with non-local storages
                                if (GeneralUtility::isFirstPartOfStr($file->getIdentifier(), $folder->getIdentifier())) {
                                    $success = true;
                                    break;
                                }
                            }
                        }
                        if ($success) {
                            $files[] = $file;
                        }
                    }
                }
            }
        }
    }

    /**
     * Sorts files.
     *
     * @param \TYPO3\CMS\Core\Resource\File[] $files
     * @return \TYPO3\CMS\Core\Resource\File[]
     */
    protected function sortFiles(array $files)
    {
        $orderedFiles = [];
        $key = '';

        foreach ($files as $file) {
            switch ($this->settings['orderBy']) {
                case static::SORT_BY_NAME:
                    $key = $file->getName();
                    break;
                case static::SORT_BY_DATE:
                    $key = $file->getProperty('modification_date');
                    break;
                case static::SORT_BY_SIZE:
                    $key = $file->getSize();
                    break;
            }
            $key .= TAB . $file->getUid();
            $orderedFiles[$key] = $file;
        }

        if ($this->settings['sortDirection'] === static::SORT_DIRECTION_ASC) {
            ksort($orderedFiles);
        } else {
            krsort($orderedFiles);
        }

        return $orderedFiles;
    }

    /**
     * Sorts folders.
     *
     * @param Folder[] $folders Array of folders, keys are the names of the corresponding folders
     * @return Folder[]
     */
    protected function sortFolders(array $folders)
    {
        if ($this->settings['orderBy'] === static::SORT_BY_NAME
            && $this->settings['sortDirection'] === static::SORT_DIRECTION_DESC
        ) {
            krsort($folders);
        } else {
            ksort($folders);
        }

        return $folders;
    }

    /**
     * Returns an error message for frontend output.
     *
     * @param string $string Error message input
     * @return string
     */
    protected function error($string)
    {
        return '
			<!-- ' . __CLASS__ . ' ERROR message: -->
			<div style="
					border: 2px red solid;
					background-color: yellow;
					color: black;
					text-align: center;
					padding: 20px 20px 20px 20px;
					margin: 20px 20px 20px 20px;
					">' .
        '<strong>' . __CLASS__ . ' ERROR:</strong><br /><br />' . nl2br(trim($string)) .
        '</div>';
    }

}
