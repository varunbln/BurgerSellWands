<?php

declare(strict_types=1);

namespace Heisenburger69\SellWands;

use pocketmine\block\Block;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\StringTag;
use pocketmine\plugin\PluginBase;
use pocketmine\command\CommandSender;
use pocketmine\command\Command;
use pocketmine\item\Item;
use onebone\economyapi\EconomyAPI;
use pocketmine\tile\Chest;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat;
use pocketmine\Player;

class Main extends PluginBase implements Listener {

    public $cfg;

    public function onEnable() : void{

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->cfg = $this->getConfig()->getAll();
    }
    public function replaceVars($str, array $vars) : string{
        foreach($vars as $key => $value){
            $str = str_replace("{" . $key . "}", $value, $str);
        }
        return $str;
    }
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        switch($command->getName()){
            case "sellwand":
                if(!$sender->hasPermission("sellwand.command")){
                    $sender->sendMessage(TextFormat::colorize($this->msg["error.permission"]));
                    return true;
                }
                if(isset($args[0])) {
                    $player = $this->getServer()->getPlayer($args[0]);
                    if($player === null) {
                        $sender->sendMessage(TextFormat::RED . "Player not found!");
                        return true;
                    }
                    $item = Item::get(Item::WOODEN_HOE);
                    $item->setNamedTagEntry(new StringTag("sellwand", "ree"));
                    $item->setCustomName(TextFormat::RESET . TextFormat::BOLD . TextFormat::GREEN . "Sell Wand");
                    $item->setLore([TextFormat::RESET . TextFormat::GRAY . "Tap on a Chest to use"]);
                    $player->getInventory()->addItem($item);
                }
                return true;
            default:
                return false;
        }
    }

    public function onInteract(PlayerInteractEvent $event)
    {
        if ($event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK) {
            $player = $event->getPlayer();
            if(!$player->hasPermission("sellwand.use")) {
                return;
            }
            $item = $event->getItem();
            $nbt = $item->getNamedTagEntry("sellwand");
            $block = $event->getBlock();
            if ($nbt !== null) {
                if ($block->getId() === Block::CHEST) {
                    $x = $block->getX();
                    $y = $block->getY();
                    $z = $block->getZ();
                    $level = $block->getLevel();
                    $chest = $level->getTile(new Vector3($x, $y, $z));
                    if ($chest instanceof Chest) {
                        $inv = $chest->getInventory()->getContents();
                        $revenue = 0;
                        foreach ($inv as $item) {
                            if (isset($this->cfg[$item->getID() . ":" . $item->getDamage()])) {
                                $revenue = $revenue + ($item->getCount() * $this->cfg[$item->getID() . ":" . $item->getDamage()]);
                                $chest->getInventory()->remove($item);
                            } elseif (isset($this->cfg[$item->getID()])) {
                                $revenue = $revenue + ($item->getCount() * $this->cfg[$item->getID()]);
                                $chest->getInventory()->remove($item);
                            }
                        }
                        if ($revenue <= 0) {
                            $player->sendMessage(TextFormat::RED . "There are no items to sell in this Chest");
                            $event->setCancelled(true);
                            return true;
                        }
                        EconomyAPI::getInstance()->addMoney($player->getName(), (int)$revenue);
                        $player->sendMessage(TextFormat::colorize($this->replaceVars("&a&lSuccess! &r&7sold the contents of the Chest for §8\${MONEY}", array(
                            "MONEY" => (string)$revenue))));
                        $event->setCancelled(true);
                    }
                }
            }
        }
    }
}