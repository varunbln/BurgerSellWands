<?php

declare(strict_types=1);

namespace Heisenburger69\BurgerSellWands;

use onebone\economyapi\EconomyAPI;
use pocketmine\block\Block;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\IntTag;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\tile\Chest;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat as C;

class Main extends PluginBase implements Listener
{

    /** @var Config */
    public $cfg;

    public function onEnable(): void
    {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->cfg = $this->getConfig();
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
    {
        switch ($command->getName()) {
            case "sellwand":
                if (!$sender->hasPermission("sellwand.command")) {
                    $sender->sendMessage(C::colorize(C::RED . "You do not have permission to execute this command."));
                    return false;
                }
                if (!isset($args[0])) {
                    $sender->sendMessage(C::RED . "Use /sellwand <player> <uses>");
                    return false;
                }
                $player = $this->getServer()->getPlayer($args[0]);
                if ($player === null) {
                    $sender->sendMessage(C::RED . "Player not found!");
                    return false;
                }
                if (isset($args[1])) {
                    $uses = intval($args[1]);
                } else {
                    $uses = -1;
                }
                $item = $this->constructWand($uses);
                $player->getInventory()->addItem($item);
                return true;
            default:
                return false;
        }
    }
    
    /**
     * @param PlayerInteractEvent $event
     * @priority MONITOR
     */
    public function onInteract(PlayerInteractEvent $event)
    {
        if ($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK) {
            return;
        }

        $player = $event->getPlayer();

        if (!$player->hasPermission("sellwand.use")) {
            $noPerms = $this->cfg->get("sell-wand-no-permission-message");
            if (!is_string($noPerms)) {
                $noPerms = "&cYou do not have permission to use Sell Wands";
            }
            $player->sendMessage(C::colorize($noPerms));
            return;
        }

        if ($event->isCancelled()) {
            $cantUseHere = $this->cfg->get("sell-wand-cant-use-here-message");
            if (!is_string($cantUseHere)) {
                $cantUseHere = "&cYou cannot use Sell Wands here!";
            }
            $player->sendMessage(C::colorize($cantUseHere));
            return;
        }

        $wand = $event->getItem();
        $nbt = $wand->getNamedTagEntry("sellwand");
        $block = $event->getBlock();

        if ($nbt === null) {
            return;
        }

        if ($block->getId() === Block::CHEST) {
            $x = $block->getX();
            $y = $block->getY();
            $z = $block->getZ();
            $level = $block->getLevel();
            $chest = $level->getTile(new Vector3($x, $y, $z));
            if ($chest instanceof Chest) {
                $inv = $chest->getInventory()->getContents();
                $revenue = 0;
                $prices = $this->cfg->get("prices");
                foreach ($inv as $item) {
                    if (isset($prices[$item->getID() . ":" . $item->getDamage()])) {
                        $revenue = $revenue + ($item->getCount() * $prices[$item->getID() . ":" . $item->getDamage()]);
                        $chest->getInventory()->remove($item);
                    } elseif (isset($prices[$item->getID()])) {
                        $revenue = $revenue + ($item->getCount() * $prices[$item->getID()]);
                        $chest->getInventory()->remove($item);
                    }
                }

                if ($revenue <= 0) {
                    $noSellables = $this->cfg->get("sell-wand-no-items-message");
                    if(!is_string($noSellables)) {
                        $noSellables = "&cThere are no items to sell in this Chest";
                    }
                    $player->sendMessage(C::colorize($noSellables));
                    $event->setCancelled(true);
                    return;
                }

                $usedMsg = $this->cfg->get("sell-wand-use-message");
                if (!is_string($usedMsg)) {
                    $usedMsg = "&a&lSuccess! &r&7sold the contents of the Chest for §8\${MONEY}";
                }
                $player->sendMessage(C::colorize(str_replace("{MONEY}", $revenue, $usedMsg)));
                EconomyAPI::getInstance()->addMoney($player->getName(), (int)$revenue);
                $this->subtractUse($wand, $player);
                $event->setCancelled(true);
            }
        }
    }

    /**
     * @param int $uses
     * @return Item
     */
    public function constructWand(int $uses): Item
    {
        $id = $this->cfg->get("sell-wand-item-id");
        if (!is_int($id)) {
            $id = Item::WOODEN_HOE;
        }
        $item = Item::get($id);
        $item->setNamedTagEntry(new IntTag("sellwand", $uses));

        if($uses < 0) {
            $uses = "Unlimited";
        }
        
        $lore = $this->cfg->get("sell-wand-item-lore");
        if (!is_array($lore)) {
            $lore = [
                C::GRAY . "Tap a Chest to sell its contents",
                " ",
                C::YELLOW . "Remaining Uses: " . C::GREEN . $uses
            ];
            $item->setLore($lore);
        } else {
            $coloredLore = [];
            foreach ($lore as $line) {
                $line = str_replace("{USES}", $uses, $line);
                $line = C::RESET . C::colorize($line);
                $coloredLore[] = $line;
            }
            $item->setLore($coloredLore);
        }

        $name = $this->cfg->get("sell-wand-item-name");
        if(!is_string($name)) {
            $name = "&l&bSell Wand";
        }
        $item->setCustomName(C::RESET . C::colorize($name));
        return $item;
    }

    public function subtractUse(Item $item, Player $player)
    {
        $nbt = $item->getNamedTagEntry("sellwand");
        if($nbt === null) {
            return;
        }

        $value = $nbt->getValue();
        $value--;

        if($value === 0) {
            $player->sendMessage(C::RED . "Your Sell Wand broke!");
            $player->getInventory()->setItemInHand(Item::get(Item::AIR));
            return;
        }

        $wand = $this->constructWand($value);
        $player->getInventory()->setItemInHand($wand);
    }
}
