<?php
namespace OCA\Recognize\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Class FaceDetection
 *
 * @package OCA\Recognize\Db
 * @method int getFileId()
 * @method setFileId(int $fileId)
 * @method int getX()
 * @method int getY()
 * @method int getHeight()
 * @method int getWidth()
 * @method setX(int $x)
 * @method setY(int $y)
 * @method setHeight(int $height)
 * @method setWidth(int $width)
 */
class FaceDetection extends Entity {
	protected $fileId;
    protected $userId;
	protected $x;
	protected $y;
	protected $height;
	protected $width;
	protected $vector;

	public static $columns = ['id', 'user_id', 'file_id', 'x', 'y', 'height', 'width', 'vector'];
	public static $fields = ['id', 'userId', 'fileId', 'x', 'y', 'height', 'width', 'vector'];

	public function __construct() {
		// add types in constructor
		$this->addType('id', 'integer');
		$this->addType('fileId', 'integer');
		$this->addType('userId', 'string');
		$this->addType('x', 'float');
		$this->addType('y', 'float');
		$this->addType('height', 'float');
		$this->addType('width', 'float');
		$this->addType('vector', 'json');
	}

	public function toArray(): array {
		$array = [];
		foreach (self::$fields as $field) {
			$array[$field] = $this->{$field};
		}
		return $array;
	}
}