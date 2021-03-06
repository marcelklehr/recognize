<?php
/*
 * Copyright (c) 2021. The Nextcloud Recognize contributors.
 *
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */

namespace OCA\Recognize\Classifiers\Video;

use OCA\Recognize\Classifiers\Classifier;
use OCA\Recognize\Db\VideoMapper;
use OCA\Recognize\Service\Logger;
use OCA\Recognize\Service\TagManager;
use OCP\DB\Exception;
use OCP\Files\IRootFolder;
use OCP\IConfig;

class MovinetClassifier extends Classifier {
	public const VIDEO_TIMEOUT = 480; // seconds
	public const MODEL_DOWNLOAD_TIMEOUT = 180; // seconds
	public const MODEL_NAME = 'movinet';

	private Logger $logger;
	private TagManager $tagManager;
	private IConfig $config;
	private IRootFolder $rootFolder;
	private VideoMapper $videoMapper;

	public function __construct(Logger $logger, IConfig $config, TagManager $tagManager, IRootFolder $rootFolder, VideoMapper $videoMapper) {
		parent::__construct($logger, $config);
		$this->logger = $logger;
		$this->config = $config;
		$this->tagManager = $tagManager;
		$this->rootFolder = $rootFolder;
		$this->videoMapper = $videoMapper;
	}
	/**
	 * @param \OCA\Recognize\Db\Video[] $videos
	 * @return void
	 * @throws \OCP\Files\NotFoundException
	 */
	public function classify(array $inputVideos): void {
		$paths = [];
		$videos = [];
		foreach ($inputVideos as $video) {
			$files = $this->rootFolder->getById($video->getFileId());
			if (count($files) === 0) {
				continue;
			}
			$videos[] = $video;
			$paths[] = $files[0]->getStorage()->getLocalFile($files[0]->getInternalPath());
		}

		if ($this->config->getAppValue('recognize', 'tensorflow.purejs', 'false') === 'true') {
			throw new \Exception('Movinet does not support WASM mode');
		} else {
			$timeout = count($paths) * self::VIDEO_TIMEOUT + self::MODEL_DOWNLOAD_TIMEOUT;
		}
		$classifierProcess = $this->classifyFiles(self::MODEL_NAME, $paths, $timeout);

		foreach ($classifierProcess as $i => $results) {
			// assign tags
			$this->tagManager->assignTags($videos[$i]->getFileId(), $results);
			// Update processed status
			$videos[$i]->setProcessedMovinet(true);
			try {
				$this->videoMapper->update($videos[$i]);
			} catch (Exception $e) {
				$this->logger->warning($e->getMessage(), ['exception' => $e]);
			}
		}
	}
}
