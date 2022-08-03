<?php

/*
 *  PLUGIN BY:
 *   _    __                  _                                     _
 *  | |  / /                 | |                                   | |
 *  | | / /                  | |                                   | |
 *  | |/ / _   _  ____   ____| | ______ ____   _____ ______   ____ | | __
 *  | |\ \| | | |/ __ \ / __ \ |/ /  __/ __ \ / __  | _  _ \ / __ \| |/ /
 *  | | \ \ \_| | <__> |  ___/   <| / | <__> | <__| | |\ |\ | <__> |   <
 *  |_|  \_\__  |\___  |\____|_|\_\_|  \____^_\___  |_||_||_|\____^_\|\_\
 *            | |    | |                          | |
 *         ___/ | ___/ |                          | |
 *        |____/ |____/                           |_|
 *
 * A PocketMine-MP plugin that allows players to use tags
 * Copyright (C) 2020-2022 Kygekraqmak
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 */

declare(strict_types=1);

namespace Kygekraqmak\KygekTagsShop;

use Closure;
use cooldogedev\BedrockEconomy\api\BedrockEconomyAPI;
use cooldogedev\BedrockEconomy\libs\cooldogedev\libSQL\context\ClosureContext;
use Kygekraqmak\KygekTagsShop\event\TagBuyEvent;
use Kygekraqmak\KygekTagsShop\event\TagSellEvent;
use pocketmine\player\Player;
use poggit\libasynql\DataConnector;

/**
 * KygekTagsShop API class
 *
 * @package Kygekraqmak\KygekTagsShop
 */
class TagsActions {

    public const API_VERSION = 1.2;

    /** @var TagsShop */
    private $plugin;

    /** @var array */
    private $config;
    /** @var DataConnector */
    private $data;

    /** @var bool */
    private $economyEnabled;

    /** @var string[] */
    private array $temp = [];

    public function __construct(TagsShop $plugin, array $config, DataConnector $data, bool $economyEnabled) {
        $this->plugin = $plugin;
        $this->config = $config;
        $this->data = $data;
        $this->economyEnabled = $economyEnabled;
    }

    /**
     * Get tags in config file
     *
     * Returns an multidimensional associative array (ID => [tag => price]) or null if there are no tags
     * ID always starts from 0 and is ordered as that of in config file
     *
     * @return null|array
     */
    public function getAllTags() : ?array {
        $alltags = [];
        if (empty($this->config["tags"])) return null;

        foreach ($this->config["tags"] as $tag) {
            $tag = explode(":", $tag);
            $alltags[][str_replace("&", "§", $tag[0] . "&r")] = $tag[1];
        }

        return $alltags;
    }


    /**
     * Get price of a tag
     *
     * Returns null if:
     * - EconomyAPI plugin is not installed or enabled, and/or
     * - tag ID doesn't exists
     *
     * @param int $tagid
     * @return null|int
     */
    public function getTagPrice(int $tagid) : ?int {
        if (!$this->economyEnabled or !$this->tagExists($tagid)) return null;

        return (int) array_values($this->getAllTags()[$tagid])[0];
    }


    /**
     * Get tag display
     *
     * Returns null if tag ID doesn't exists
     *
     * @param int $tagid
     * @return null|string
     */
    public function getTagName(int $tagid) : ?string {
        if (!$this->tagExists($tagid)) return null;

        return array_keys($this->getAllTags()[$tagid])[0];
    }


    /**
     * Checks if tag exists in config
     *
     * @param int $tagid
     * @return bool
     */
    public function tagExists(int $tagid) : bool {
        return isset($this->getAllTags()[$tagid]);
    }


    /**
     * Checks if player has tag
     *
     * @param Player $player
     * @return bool
     */
    public function playerHasTag(Player $player) : bool {
        return isset($this->getAllData()[strtolower($player->getName())]);
    }

    /**
     * Gets player's tag ID from database
     *
     * Returns null if player doesn't have tag
     *
     * @param Player $player
     * @param Closure $callback
     * @return null|int
     */
    public function getPlayerTag(Player $player, Closure $callback) : ?int {
        if (!$this->playerHasTag($player)) return null;

        return $this->getData($player, $callback);
    }


    /**
     * Removes tag from player
     *
     * Sends a warning message if player doesn't have a tag
     *
     * @param Player $player
     */
    public function unsetPlayerTag(Player $player) {
        if (!$this->playerHasTag($player)) {
            $player->sendMessage($this->plugin->messages["kygektagsshop.warning.playerhasnotag"]);
            return;
        }

        $this->getPlayerTag($player, function (?int $tagid) use ($player): void{
            if ($this->economyEnabled) {
                $tagprice = $this->getTagPrice($tagid);
                (new TagSellEvent($player, $tagid))->call();
                BedrockEconomyAPI::getInstance()->addToPlayerBalance($player->getName(), $tagprice);
                $this->removeData($player);
                // TODO: Set player display name to original display name after new database has been implemented
                $player->setDisplayName($player->getName());
                $player->sendMessage(str_replace("{price}", "$" . $tagprice, $this->plugin->messages["kygektagsshop.info.economyselltagsuccess"]));
                return;
            }
    
            (new TagSellEvent($player, $tagid))->call();
            $this->removeData($player);
            $player->setDisplayName($player->getName());
            $player->sendMessage($this->plugin->messages["kygektagsshop.info.freeselltagsuccess"]);
        });
    }


    /**
     * Sets a tag to player
     *
     * Sends a warning message if player have a tag or player doesn't have enough money
     *
     * @param Player $player
     * @param int $tagid
     */
    public function setPlayerTag(Player $player, int $tagid) {

        if ($this->playerHasTag($player)) {
            $player->sendMessage($this->plugin->messages["kygektagsshop.warning.playerhastag"]);
            return;
        }

        if ($this->economyEnabled) {
            BedrockEconomyAPI::getInstance()->getPlayerBalance($player->getName(), ClosureContext::create(function(?int $balance) use ($tagid, $player): void {
                $tagprice = $this->getTagPrice($tagid);
                $money = "$" . ($tagprice - $balance);

                if ($balance < $tagprice) {
                    $player->sendMessage(str_replace("{price}", $money, $this->plugin->messages["kygektagsshop.warning.notenoughmoney"]));
                    return;
                }

                (new TagBuyEvent($player, $tagid))->call();
                $this->setData($player, $tagid);
                BedrockEconomyAPI::getInstance()->subtractFromPlayerBalance($player->getName(), $tagprice);
                // TODO: Store original player display name in database after new database has been implemented (See line #178 for purpose)
                $displayName = $player->getDisplayName();
                $tag = $this->getTagName($tagid);
                $player->setDisplayName(str_replace(["{displayname}", "{tag}"], [$displayName, $tag], $this->getDisplayNameFormat()));
                $player->sendMessage(str_replace("{price}", "$" . $tagprice, $this->plugin->messages["kygektagsshop.info.economybuytagsuccess"]));
            }));
            return;
        }

        (new TagBuyEvent($player, $tagid))->call();
        $this->setData($player, $tagid);
        $player->setDisplayName($player->getName() . " " . $this->getTagName($tagid));
        $player->sendMessage($this->plugin->messages["kygektagsshop.info.freebuytagsuccess"]);
    }


    /**
     * Gets the display name format from the KygekTagsShop configuration file
     *
     * @return string
     */
    public function getDisplayNameFormat() : string {
        return ($this->config["display-name-format"] ?? "{displayname} {tag}") ?: "{displayname} {tag}";
    }

    /**
     * Gets the tag ID of a player from KygekTagsShop database
     *
     * @param Player $player
     * @param Closure $callback
     * @return void
     */
    private function getData(Player $player, Closure $callback) : void {
        $this->data->executeSelect(
            'kygektagsshop.get',
            [
                'player' => strtolower($player->getName())
            ],
            function (array $data) use ($player, $callback){
                if(empty($data)){
                    $this->data->executeInsert('kygektagsshop.insert', [
                        'player' => $player,
                        'tagid' => 0
                    ]);
                    $this->data->waitAll();
                    $callback(0);
                };
                $callback($data[0]['tagid']);
            }
        );
    }


    /**
     * Sets tag ID to a player inside KygekTagsShop database
     *
     * @param Player $player
     * @param int $tagid
     */
    private function setData(Player $player, int $tagid) {
        //$this->data->set(strtolower($player->getName()), $tagid);
        $this->data->executeChange('kygektagsshop.update', [
            'player' => strtolower($player->getName()),
            'tagid' => $tagid
        ]);
        $this->data->waitAll();
        //$this->saveData();
        //$this->reloadData();
    }


    /**
     * Removes player tag ID from KygekTagsShop database
     *
     * @param Player $player
     */
    private function removeData(Player $player) {
        $this->data->executeChange('kygektagsshop.remove', [
            'player' => strtolower($player->getName())
        ]);
        $this->data->waitAll();
        //$this->saveData();
        //$this->reloadData();
    }


    /**
     * Gets all KygekTagsShop database contents
     *
     * @param bool $keys
     * @return array
     */
    public function getAllData() : array {
        $this->data->executeSelect('kygektagsshop.getall', [
        ],
        function (array $data){
            if(empty($data)) $this->temp = [];
            foreach ($data as $player){
                $this->temp = [];
                var_dump($player);
            }
        }
        );
        return $this->temp;
    }


    /**
     * Reloads the KygekTagsShop database
     */
    // public function reloadData() {
    //     $this->data->reload();
    // }


    /**
     * Saves the KygekTagsShop database
     */
    // public function saveData() {
    //     $this->data->save();
    // }


    /**
     * Gets the KygekTagsShop database location
     *
     * @return string
     */
    public function getDataLocation() : string {
        return $this->plugin->getDataFolder() . "data.json";
    }

}
