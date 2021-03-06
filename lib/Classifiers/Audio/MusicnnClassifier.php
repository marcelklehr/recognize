<?php
/*
 * Copyright (c) 2021. The Nextcloud Recognize contributors.
 *
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */

namespace OCA\Recognize\Classifiers\Audio;

use OCA\Recognize\Classifiers\Classifier;
use OCA\Recognize\Db\AudioMapper;
use OCA\Recognize\Service\Logger;
use OCA\Recognize\Service\TagManager;
use OCP\DB\Exception;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

class MusicnnClassifier extends Classifier {
	public const FILE_TIMEOUT = 40; // seconds
	public const FILE_PUREJS_TIMEOUT = 300; // seconds
	public const MODEL_DOWNLOAD_TIMEOUT = 180; // seconds
	public const MODEL_NAME = 'musicnn';


	private LoggerInterface $logger;
	private TagManager $tagManager;
	private IConfig $config;
	private IRootFolder $rootFolder;
	private AudioMapper $audioMapper;

	public function __construct(Logger $logger, IConfig $config, TagManager $tagManager, IRootFolder $rootFolder, AudioMapper $audioMapper) {
		parent::__construct($logger, $config);
		$this->logger = $logger;
		$this->config = $config;
		$this->tagManager = $tagManager;
		$this->rootFolder = $rootFolder;
		$this->audioMapper = $audioMapper;
	}

	/**
	 * @param \OCA\Recognize\Db\Audio[] $audios
	 * @return void
	 * @throws \OCP\Files\NotFoundException
	 */
	public function classify(array $inputAudios): void {
		$paths = [];
		$audios = [];
		foreach ($inputAudios as $audio) {
			$files = $this->rootFolder->getById($audio->getFileId());
			if (count($files) === 0) {
				continue;
			}
			$audios[] = $audio;
			$paths[] = $files[0]->getStorage()->getLocalFile($files[0]->getInternalPath());
		}
		if ($this->config->getAppValue('recognize', 'tensorflow.purejs', 'false') === 'true') {
			$timeout = count($paths) * self::FILE_PUREJS_TIMEOUT + self::MODEL_DOWNLOAD_TIMEOUT;
		} else {
			$timeout = count($paths) * self::FILE_TIMEOUT + self::MODEL_DOWNLOAD_TIMEOUT;
		}

		// Call out to node.js process
		$classifierProcess = $this->classifyFiles(self::MODEL_NAME, $paths, $timeout);

		foreach ($classifierProcess as $i => $results) {
			// assign tags
			$this->tagManager->assignTags($audios[$i]->getFileId(), $results);

			// Update processed status
			$audios[$i]->setProcessedMusicnn(true);
			try {
				$this->audioMapper->update($audios[$i]);
			} catch (Exception $e) {
				$this->logger->warning($e->getMessage(), ['exception' => $e]);
			}
		}
	}
}
