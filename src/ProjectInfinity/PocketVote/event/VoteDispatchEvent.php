<?php

namespace ProjectInfinity\PocketVote\event;

use pocketmine\event\Cancellable;
use pocketmine\event\CancellableTrait;
use pocketmine\event\plugin\PluginEvent;
use ProjectInfinity\PocketVote\PocketVote;

class VoteDispatchEvent extends PluginEvent implements Cancellable {
    use CancellableTrait;

    public static $handlerList = null;

    private $player, $ip, $site;

    public function __construct(PocketVote $plugin, $player, $ip, $site) {
        parent::__construct($plugin);
        $this->player = $player;
        $this->ip = $ip;
        $this->site = $site;
    }

    /**
     * Returns the player that voted.
     *
     * @return mixed
     */
    public function getPlayer() {
        return $this->player;
    }

    /**
     * Get the IP of the player that voted.
     *
     * @return mixed
     */
    public function getIp() {
        return $this->ip;
    }

    /**
     * Get the site the player voted on.
     *
     * @return mixed
     */
    public function getSite() {
        return $this->site;
    }

}