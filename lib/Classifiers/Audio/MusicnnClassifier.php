<?php
/*
 * Copyright (c) 2021. The Nextcloud Recognize contributors.
 *
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */

namespace OCA\Recognize\Classifiers\Audio;

use OCA\Recognize\Service\Logger;
use OCA\Recognize\Service\TagManager;
use OCP\Files\File;
use OCP\IConfig;
use OCP\Files\InvalidPathException;
use OCP\Files\NotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\Process;

class MusicnnClassifier {
	public const FILE_TIMEOUT = 40; // seconds
	public const FILE_PUREJS_TIMEOUT = 300; // seconds
	public const MODEL_DOWNLOAD_TIMEOUT = 180; // seconds

	/**
	 * @var LoggerInterface
	 */
	private $logger;
	/**
	 * @var TagManager
	 */
	private $tagManager;
	/**
	 * @var IConfig
	 */
	private $config;

	public function __construct(Logger $logger, IConfig $config, TagManager $tagManager) {
		$this->logger = $logger;
		$this->config = $config;
		$this->tagManager = $tagManager;
	}

	/**
	 * @param File[] $files
	 * @return void
	 * @throws \OCP\Files\NotFoundException
	 */
	public function classify(array $files): void {
		$paths = array_map(static function ($file) {
			return $file->getStorage()->getLocalFile($file->getInternalPath());
		}, $files);
		$this->logger->debug('Classifying '.var_export($paths, true));

		$command = [
			$this->config->getAppValue('recognize', 'node_binary'),
			dirname(__DIR__, 3) . '/src/classifier_musicnn.js',
			'-'
		];

		$this->logger->debug('Running '.var_export($command, true));
		$proc = new Process($command, __DIR__);
		if ($this->config->getAppValue('recognize', 'tensorflow.gpu', 'false') === 'true') {
			$proc->setEnv(['RECOGNIZE_GPU' => 'true']);
		}
		if ($this->config->getAppValue('recognize', 'tensorflow.purejs', 'false') === 'true') {
			$proc->setEnv(['RECOGNIZE_PUREJS' => 'true']);
			$proc->setTimeout(count($paths) * self::FILE_PUREJS_TIMEOUT + self::MODEL_DOWNLOAD_TIMEOUT);
		} else {
			$proc->setTimeout(count($paths) * self::FILE_TIMEOUT + self::MODEL_DOWNLOAD_TIMEOUT);
		}
		$proc->setInput(implode("\n", $paths));
		try {
			$proc->start();

            // Set cores
            $cores = $this->config->getAppValue('recognize', 'tensorflow.cores', '0');
            if ($cores !== '0') {
                @exec('taskset -cp ' . implode(',', range(0, (int)$cores, 1)) . ' ' . $proc->getPid());
            }

			$i = 0;
			$errOut = '';
			foreach ($proc as $type => $data) {
				if ($type !== $proc::OUT) {
					$errOut .= $data;
					$this->logger->debug('Classifier process output: '.$data);
					continue;
				}
				$lines = explode("\n", $data);
				foreach ($lines as $result) {
					if (trim($result) === '') {
						continue;
					}
					$this->logger->debug('Result for ' . $files[$i]->getName() . ' = ' . $result);
					try {
						// decode json
						$tags = json_decode($result, true, 512, JSON_OBJECT_AS_ARRAY | JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_IGNORE);


						// assign tags
						$this->tagManager->assignTags($files[$i]->getId(), $tags);
					} catch (InvalidPathException $e) {
						$this->logger->warning('File with invalid path encountered');
					} catch (NotFoundException $e) {
						$this->logger->warning('File to tag was not found');
					} catch (\JsonException $e) {
						$this->logger->warning('JSON exception');
						$this->logger->warning($e->getMessage());
						$this->logger->warning($result);
					}
					$i++;
				}
			}
			if ($i !== count($files)) {
				$this->logger->warning('Classifier process output: '.$errOut);
				throw new \RuntimeException('Classifier process error');
			}
		} catch (ProcessTimedOutException $e) {
			$this->logger->warning($proc->getErrorOutput());
			throw new \RuntimeException('Classifier process timeout');
		} catch (RuntimeException $e) {
			$this->logger->warning($proc->getErrorOutput());
			throw new \RuntimeException('Classifier process could not be started');
		}
	}
}
