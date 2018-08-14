<?php

declare(strict_types=1);

namespace solo\hotairballoon;

use pocketmine\Player;
use pocketmine\entity\Entity;
use pocketmine\entity\Skin;
use pocketmine\level\Level;
use pocketmine\nbt\tag\CompoundTag;

class AirVehicle extends Vehicle{

	protected $gravity = 0.0008;

	public $width = 0.6;
	public $height = 1.8;

	public function __construct(Level $level, CompoundTag $nbt){
		parent::__construct($level, $nbt);
	}

	protected function initEntity(CompoundTag $nbt) : void{
		parent::initEntity($nbt);

		$this->setGenericFlag(Entity::DATA_FLAG_AFFECTED_BY_GRAVITY, false);
	}

	public function getSkin() : Skin{
		return HotAirBalloon::$resources->getSkin("AirVehicle");
	}

	public function input(float $motionX, float $motionY){
		if($motionX > 0) $this->yaw -= 5;
		else if($motionX < 0) $this->yaw += 5;

		if($motionY > 0){
			$this->motion = $this->getDirectionVector()->multiply(0.2);
			$this->motion->y = 0.1;
		}else if($motionY < 0){
			$this->motion = $this->getDirectionVector()->multiply(0.2);
			$this->motion->y = -0.1;
		}
	}

	public function entityBaseTick(int $tickDiff = 1) : bool{
		$hasUpdate = parent::entityBaseTick($tickDiff);

		if($this->isAlive() and $this->isRiding()){
			$this->getRider()->resetFallDistance();
		}
		return $hasUpdate;
	}
}
