<?php

declare(strict_types=1);

namespace autoinv;

use pocketmine\block\Block;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\EventPriority;
use pocketmine\event\Listener;
use pocketmine\item\Durable;
use pocketmine\item\Item;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;

class Main extends PluginBase implements Listener {

    private string $inventoryFullMessage;

    protected function onEnable(): void {
        $this->saveDefaultConfig();
        $this->inventoryFullMessage = $this->getConfig()->get("inventory-full-message", "§cYour inventory is full!");

        $this->getServer()->getPluginManager()->registerEvent(
            BlockBreakEvent::class,
            $this->onBlockBreak(...),
            EventPriority::HIGH, // We use HIGH to run after most protection plugins but before it's too late.
            $this
        );
    }

    public function onBlockBreak(BlockBreakEvent $event): void {
        // 1. Initial Checks for compatibility and game rules.
        if ($event->isCancelled()) {
            return;
        }

        $player = $event->getPlayer();
        if ($player->getGamemode() !== GameMode::SURVIVAL) {
            // Only active for survival players. Creative players don't get drops anyway.
            return;
        }
        
        if(!$player->hasPermission("autoinventory.use")) {
            return;
        }

        $itemInHand = $player->getInventory()->getItemInHand();
        $block = $event->getBlock();
        
        // 2. Calculate the drops based on the tool used (respects Fortune/Silk Touch).
        $drops = $block->getDrops($itemInHand);

        if (empty($drops)) {
            // No drops, so no need to do anything.
            return;
        }

        // 3. Check if the player's inventory can hold ALL the drops.
        if (!$this->canInventoryHoldAll($player, $drops)) {
            $player->sendMessage($this->inventoryFullMessage);
            // Let the event continue as normal, dropping items on the ground.
            return;
        }

        // 4. Execute the core logic since all checks passed.
        
        // This is the safest order of operations to prevent duplication:
        // First, prevent the natural drops. This is final and cannot be undone by lag.
        $event->setDrops([]);

        // Second, apply durability damage to the tool, if applicable.
        if ($itemInHand instanceof Durable) {
            $itemInHand->applyDamage(1);
            // Manually save the updated item back to the player's hand.
            $player->getInventory()->setItemInHand($itemInHand);
        }

        // Finally, attempt to add the items to the inventory.
        $player->getInventory()->addItem(...$drops);
    }

    /**
     * Checks if a player's inventory can hold an array of items.
     *
     * @param Player $player
     * @param Item[] $drops
     * @return bool
     */
    private function canInventoryHoldAll(Player $player, array $drops): bool {
        $tempInventory = clone $player->getInventory();
        // We check against a cloned inventory to prevent race conditions
        // and to accurately check if multiple stacks of the same item can fit.
        return $tempInventory->canAddItem(...$drops);
    }
}