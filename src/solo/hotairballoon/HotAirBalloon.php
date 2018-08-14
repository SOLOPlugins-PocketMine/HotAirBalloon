<?php

declare(strict_types=1);

namespace solo\hotairballoon;

use pocketmine\Player;
use pocketmine\Server;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\Entity;
use pocketmine\entity\Skin;
use pocketmine\event\Listener;
use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\mcpe\protocol\InteractPacket;
use pocketmine\network\mcpe\protocol\InventoryTransactionPacket;
use pocketmine\network\mcpe\protocol\PlayerInputPacket;
use pocketmine\plugin\PluginBase;

class HotAirBalloon extends PluginBase{

	public static $prefix = "§l§b[HotAirBalloon] §r§7";

	public static $resources;

	protected function onLoad(){
		self::$resources = new Resources($this);

		Entity::registerEntity(AirVehicle::class, false, ["airvehicle"]);
	}

	protected function onEnable(){
		S::command(
			"vehicle registerskin",
			"Register your skin to resources for visualize vehicle.",
			"/vehicle registerskin <name>",
			"op",
			true,
			function(Command $self, CommandSender $sender, array $args){
				$name = trim(implode($args));
				if(empty($name)){
					return $sender->sendMessage(HotAirBalloon::$prefix . $self->getUsage() . " - " . $self->getDescription());
				}

				$skin = $sender->getSkin();
				HotAirBalloon::$resources->setSkin($name, $skin);

				$sender->sendMessage(HotAirBalloon::$prefix . "You've registerd your skin.");
			});

		S::command(
			"vehicle create",
			"Create a vehicle.",
			"/vehicle create",
			"op",
			true,
			function(Command $self, CommandSender $sender, array $args){
				$entity = Entity::createEntity("AirVehicle", $sender->getLevel(), Entity::createBaseNBT($sender->asVector3()));
				$entity->spawnToAll();

				$sender->sendMessage(HotAirBalloon::$prefix . "Successfully created a vehicle.");
			});

		new VehicleHandler($this);
	}

	protected function onDisable(){
		self::$resources->save();
	}
}

class Resources{

	private $path;
	private $skins = null;

	public function __construct(HotAirBalloon $plugin){
		$path = $plugin->getDataFolder();
		@mkdir($path);
		$plugin->saveResource("skins.json");

		$this->path = $path;
		$this->skins = $this->loadFromFile($this->path . "skins.json");
		foreach($this->skins as $name => $skin){
			$this->skins[$name] = new Skin(
				$skin["skinId"],
				base64_decode($skin["skinData"]),
				base64_decode($skin["capeData"]),
				$skin["geometryName"],
				$skin["geometryData"]
			);
		}
	}

	protected function loadFromFile(string $file) : array{
		if(!file_exists($file)) return [];
		return json_decode(file_get_contents($file), true) ?? [];
	}

	protected function saveToFile(string $file, array $data){
		file_put_contents($file, json_encode($data));
	}

	public function getSkin(string $name) : ?Skin{
		return $this->skins[$name] ?? null;
	}

	public function setSkin(string $name, Skin $skin){
		$skin->debloatGeometryData();
		$this->skins[$name] = $skin;
	}

	public function save(){
		if($this->skins === null) return;

		foreach($this->skins as $name => $skin){
			$this->skins[$name] = [
				"skinId" => $skin->getSkinId(),
				"skinData" => base64_encode($skin->getSkinData()),
				"capeData" => base64_encode($skin->getCapeData()),
				"geometryName" => $skin->getGeometryName(),
				"geometryData" => $skin->getGeometryData()
			];
		}
		$this->saveToFile($this->path . "skins.json", $this->skins);
	}
}

class VehicleHandler implements Listener{

	private $server;

	public function __construct(HotAirBalloon $owner){
		$this->server = $owner->getServer();
		$this->server->getPluginManager()->registerEvents($this, $owner);
	}

	public function onDataPacketReceive(DataPacketReceiveEvent $event){
		$packet = $event->getPacket();
		switch($packet->pid()){
			case InteractPacket::NETWORK_ID:
				if($packet->action === InteractPacket::ACTION_LEAVE_VEHICLE){
					$target = $event->getPlayer()->getLevel()->getEntity($packet->target);
					if($target instanceof Vehicle and $target->getRider() === $event->getPlayer()){
						$target->dismount();
						$event->setCancelled();
					}
				}
				break;

			case InventoryTransactionPacket::NETWORK_ID:
				if($packet->transactionType === InventoryTransactionPacket::TYPE_USE_ITEM_ON_ENTITY){
					$target = $event->getPlayer()->getLevel()->getEntity($packet->trData->entityRuntimeId);
					if($target instanceof Vehicle and $packet->trData->actionType === InventoryTransactionPacket::USE_ITEM_ON_ENTITY_ACTION_INTERACT){
						$target->ride($event->getPlayer());
						$event->setCancelled();
					}
				}
				break;

			case PlayerInputPacket::NETWORK_ID:
				if($packet->motionX == 0 and $packet->motionY == 0) return; // ignore non-input

				if(isset(Vehicle::$ridingEntities[$event->getPlayer()->getName()])){
					$riding = Vehicle::$ridingEntities[$event->getPlayer()->getName()];
					$riding->input($packet->motionX, $packet->motionY);
					$event->setCancelled();
				}
				break;
		}
	}

	public function onTeleport(EntityTeleportEvent $event){
		$player = $event->getEntity();
		if($player instanceof Player){
			if(isset(Vehicle::$ridingEntities[$player->getName()])){
				$riding = Vehicle::$ridingEntities[$player->getName()];
				$riding->dismount();
			}
		}
	}

	public function onPlayerQuit(PlayerQuitEvent $event){
		if(isset(Vehicle::$ridingEntities[$event->getPlayer()->getName()])){
			$riding = Vehicle::$ridingEntities[$event->getPlayer()->getName()];
			$riding->dismount();
		}
	}
}

class S{

	public static function command(string $name, string $desc, string $usage, string $permission, bool $playerOnly, callable $callback){
		$data = new \stdClass();
		$data->name = $name;
		$data->desc = $desc;
		$data->usage = $usage;
		$data->permission = $permission;
		$data->playerOnly = $playerOnly;
		$data->callback = $callback;
		Server::getInstance()->getCommandMap()->register("HotAirBalloon", new class($data) extends Command{

			public function __construct($data){
				parent::__construct($data->name, $data->desc, $data->usage);

				$this->permission = $data->permission;
				$this->playerOnly = $data->playerOnly;
				$this->callback = $data->callback;
			}

			public function execute(CommandSender $sender, string $label, array $args) : bool{
				if($this->permission !== "all" and !$sender->hasPermission($this->permission)){
					$sender->sendMessage(HotAirBalloon::$prefix . "You don't have permission to use this command.");
					return true;
				}
				if($this->playerOnly and !$sender instanceof Player){
					$sender->sendMessage(HotAirBalloon::$prefix . "You can't run this command in console.");
					return true;
				}
				$callback = $this->callback;
				$callback($this, $sender, $args);

				return true;
			}
		});
	}
}