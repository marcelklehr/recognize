<?php

namespace OCA\Recognize\Files;

use OCA\Recognize\Db\Video;
use OCA\Recognize\Db\VideoMapper;
use OCA\Recognize\Service\Logger;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\Files\File;

class VideoToDbFinder extends VideoFinder {
	private Logger $logger;
	private VideoMapper $videoMapper;

	public function __construct(Logger $logger, VideoMapper $videoMapper) {
		parent::__construct($logger);
		$this->logger = $logger;
		$this->videoMapper = $videoMapper;
	}


	/**
	 * @throws \OCP\Files\InvalidPathException
	 * @throws \OCP\Files\NotFoundException
	 * @throws \OCP\DB\Exception
	 * @throws \OCP\AppFramework\Db\MultipleObjectsReturnedException
	 */
	public function foundFile(string $user, File $node) :void {
		try {
			$this->videoMapper->findByFileId($node->getId());
		} catch (DoesNotExistException $e) {
			$video = new Video();
			$video->setUserId($user);
			$video->setFileId($node->getId());
			$video->setProcessedMovinet(false);
			$this->videoMapper->insert($video);
		}
	}
}
