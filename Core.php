<?php
/**
 * OAuth Plugin Core
 * 核心模块 (可独立使用)
 * 
 * @Author: ohmyga
 * @Date: 2021-11-03 05:51:59
 * @LastEditTime: 2021-11-24 03:09:08
 */

namespace OAPIPlugin\OAuth;

use OAPI\DB\DB;
use OAPI\Redis\OMRedis;
use OAPI\HTTP\HTTP;
use OAPI\Libs\Libs;

class Core
{

    private static $_db;

    private static $_redis;

    private static $_group = [
        0     =>  "admin",   // 管理员
        1     =>  "friend",  // 朋友
        2     =>  "normal",  // 普通用户
        3     =>  "banned",  // 被封禁用户
    ];

    private static $_ttl = 24 * 60 * 60;

    public function __construct($db = null, $redis = null)
    {
        self::$_db = ($db == null) ? DB::get() : $db;
        self::$_redis = ($redis == null) ? OMRedis::get()->redis : $redis;
        self::init();
    }

    /**
     * 插件配置初始化
     * 
     * @return void
     */
    private static function init()
    {
        if (!self::$_db->fetchRow(self::$_db->select()->from("table.options")->where("name = ?", "plugin:OAuth"))) {
            self::$_db->query(
                self::$_db->insert("table.options")->rows([
                    "name"    =>  "plugin:OAuth",
                    "value"   =>  "{}",
                ])
            );
        }
    }

    /**
     * 获取 OAuth 表中所有用户
     * 
     * @return array
     */
    public static function getList(): array
    {
        $list = self::$_db->fetchAll(
            self::$_db
                ->select("*")
                ->from("table.oauth")
        );

        return empty($list) ? [] : $list;
    }

    /**
     * 获取 OAuth Plugin 的配置
     * 
     * @return array
     */
    public static function getConfig(): array
    {
        self::init();
        $config = self::$_db->fetchRow(self::$_db->select()->from("table.options")->where("name = ?", "plugin:OAuth"));
        return !empty($config["value"]) && is_array(json_decode($config["value"], true)) ? json_decode($config["value"], true) : [];
    }

    /**
     * 设置 / 更新 OAuth Plugin 设置
     * 
     * @param array $config
     * @return array
     */
    public static function setConfig($config): array
    {
        $configAll = self::getConfig();
        $configArr = [];

        foreach ($configAll as $key => $item) {
            $configArr[$key] = (!empty($config[$key])) ? $config[$key] : $item;
        }

        self::$_db->query(
            self::$_db->update("table.options")->rows([
                "value"  => json_encode($configArr)
            ])->where("name = ?", "plugin:OAuth")
        );

        return $configArr;
    }

    /**
     * 增加 OAuth Plugin 的配置
     * 
     * @param string $key         新配置的键
     * @param string $value       新配置的值
     */
    public static function addConfig($key, $value)
    {
        if (!empty(self::getConfig()[$key])) {
            return self::setConfig([$key => $value]);
        }

        $config = self::getConfig();
        $config[$key] = $value;

        self::$_db->query(
            self::$_db->update("table.options")->rows([
                "value"  => json_encode($config)
            ])->where("name = ?", "plugin:OAuth")
        );

        return $config;
    }

    /**
     * 检查 APIKey 使用额度
     * 
     * @param string $name          限额 API ID
     * @param array $group          限制指定权限组能够使用
     */
    public static function checkScore($name, array $group = [])
    {
        $apikey = self::getRequestAPIKey();
        if ($apikey === null) {
            // 普通访客
            $guest_can_use_max = !empty(self::getConfig()["guestCount"]) ? self::getConfig()["guestCount"] : 800;

            $guest_key = "OAuth_Guest_" . preg_replace("/\./is", "_", HTTP::getUserIP()) . "_{$name}API_Used_Count";

            $count = self::__getQuotaArray($guest_key, $guest_can_use_max);

            if ($count["quota"] <= 0) {
                return ["status" => false, "code" => 403, "message" => "当前 IP 今日(非自然日)调用次数已达最大额度", "data" => [
                    "used" => $count["count"],
                    "max"  => $guest_can_use_max,
                    "cd"   => $count["quota_ttl"]
                ]];
            } else {
                // 如果没超额度则增加调用次数
                self::__addQuotaCount($guest_key);
            }

            return ["status" => true, "message" => "Success"];
        }

        // 首先验证 APIKey 是否存在
        $check = self::$_db->fetchRow(self::$_db->select()->from("table.oauth")->where("apikey = ?", $apikey));
        if (empty($check)) return ["status" => false, "message" => "API Key 不存在"];

        if (is_array($group) && !empty($group)) {
            $_group = [];
            foreach ($group as $item) {
                $_group[] = !empty(self::$_group[$item]) ? self::$_group[$item] : "unknown";
            }

            $auth = null;

            // 管理员
            if ($check["group"] == "admin" && in_array("admin", $_group)) {
                $auth = "admin";
            } else if ($check["group"] == "friend" && in_array("friend", $_group)) {
                // 朋友
                $auth = "friend";
            } else if ($check["group"] == "normal" && in_array("normal", $_group)) {
                // 普通用户
                $auth = "normal";
            } else if ($check["group"] == "banned") {
                $auth = "banned";
            } else if (in_array("admin", $_group) || in_array("friend", $_group) || in_array("normal", $_group) || in_array("banned", $_group)) {
                $auth = "ip";
            } else {
                $auth = "unknown";
            }
        } else {
            // 如果没有指定用户组
            // 即按照 APIKey 所对应的用户组进行限制
            $auth = strtolower($check["group"]);
        }

        // 如果为普通用户即进行调用额度验证
        if ($auth == "normal") {
            // 获取当前使用的 API 的最大使用额度
            $can_use_max = !empty(self::getConfig()[$name . "Count"]) ? (int) self::getConfig()[$name . "Count"] : (!empty(self::getConfig()["defaultCount"]) ? self::getConfig()["defaultCount"] : 1000);

            // 用户唯一标识
            $user_key = "OAuth_User_" . $check["id"] . "_{$name}API_Used_Count";

            $count = self::__getQuotaArray($user_key, $can_use_max);

            if ($count["quota"] <= 0) {
                return ["status" => false, "code" => 403, "message" => "当前 APIKey 今日(非自然日)调用次数已达最大额度", "data" => [
                    "used" => $count["count"],
                    "max"  => $can_use_max,
                    "cd"   => $count["quota_ttl"]
                ]];
            } else {
                // 如果没超额度则增加调用次数
                self::__addQuotaCount($user_key);
            }

            // 如果可以正常使用(没超额度)
            return ["status" => true, "messgae" => "Success"];
        } else if ($auth == "banned") {
            // 如果为被封禁用户
            return ["status" => false, "code" => 403, "message" => "已被封禁", "more" => ["tips" => "如有异议，请联系欧欧 _(:з)∠)_"]];
        } else if ($auth == "admin" || $check["group"] == "admin") {
            // 如果为管理员或者朋友
            // 将不限制额度
            OhMyFriend:
            return ["status" => true, "message" => "Success", "more" => ["group" => $auth, "msg" => "您可以不受限制的尽情使用 API"]];
        } else if ($auth == "friend") {
            goto OhMyFriend;
        } else if ($auth == "ip") {
            // 此为权限不足
            return ["status" => false, "code" => 403, "message" => "当前 APIKey 无权使用此 API"];
        }

        // 以下为未知情况
        return ["status" => false, "code" => 500, "message" => "未知的权限组"];
    }

    /**
     * 获取用户剩余额度
     * 
     * @param string $key
     * @param string $max
     */
    private static function __getQuotaArray($key, $max)
    {
        $time = time();

        // TTL
        $line = $time - self::$_ttl;

        // 获取当前用户当日 (自第一次请求起所使用的额度)
        $count = self::$_redis->zCount($key, $line, '+inf');
        $top_value = self::$_redis->zRangeByScore($key, $line, '+inf', ['limit' => [0, 1]]);

        // 计算剩余额度
        $quota = $max - $count;
        // 计算恢复一次额度所需时间
        $quota_ttl = (is_array($top_value) && count($top_value) > 0) ? self::$_redis->zScore($key, $top_value[0]) + self::$_ttl - $time : 0;

        return [
            "quota"      =>  $quota,
            "quota_ttl"  =>  $quota_ttl,
            "top_value"  =>  $top_value,
            "count"      =>  $count,
            "ttl"        =>  $line
        ];
    }

    /**
     * 增加调用次数
     * 
     * @param string $key
     */
    private static function __addQuotaCount($key)
    {
        $time = time();

        // TTL
        $line = $time - self::$_ttl;

        // 清除旧记录
        self::$_redis->zRemRangeByScore($key, '-inf', $line);

        // 记录
        self::$_redis->zAdd($key, $time, uniqid('', true));

        // 更新集合的 TTL
        self::$_redis->expire($key, self::$_ttl);
    }

    /**
     * 获取请求中所包含的 APIKey 值
     * 
     * @return string
     */
    public static function getRequestAPIKey()
    {
        if (!empty(HTTP::getHeader("Authorization", null))) {
            $apikey =  HTTP::getHeader("Authorization", null);
        } else if (!empty(HTTP::getParams("apikey", null))) {
            $apikey =  HTTP::getParams("apikey", null);
        } else {
            return null;
        }

        return $apikey;
    }

    /**
     * 增加 APIKey
     * 
     * @param string $group
     * @param array $config
     */
    public static function addUser($group, array $config = [])
    {
        $group = (in_array(strtolower($group), self::$_group)) ? strtolower($group) : "normal";

        $adduser = self::$_db->query(
            self::$_db->insert("table.oauth")->rows([
                "group"       =>  $group,
                "created"     =>  time(),
            ])
        );

        $getUser = self::$_db->fetchRow(
            self::$_db
                ->select()
                ->from("table.oauth")
                ->where("id = ?", $adduser)
        );

        $apikey = self::createAPIKey($getUser["id"], $getUser["created"]);

        if (is_array($config) && !empty($config)) {
            foreach ($config as $key => $item) {
                self::addUserConfig($getUser["id"], $key, $item);
            }
        }

        self::$_db->query(
            self::$_db->update("table.oauth")->rows([
                "apikey"   => $apikey,
            ])->where("id = ?", $getUser["id"])
        );

        return [
            "id"        => $getUser["id"],
            "apikey"    => $apikey,
            "group"     => $getUser["group"],
            "created"   => $getUser["created"],
            "value"     => self::getUserConfig($getUser["id"])
        ];
    }

    /**
     * 生成 APIKey
     * 
     * @param int $uid     用户 UID
     * @param int $created 创建时间
     * @return string
     */
    public static function createAPIKey($uid, $created)
    {
        $apikey = base64_encode(
            json_encode([
                "uid"      =>  $uid,
                "created"  =>  $created,
            ])
        );
        $apikey = preg_replace("/=/is", "", $apikey);
        $apikey = $apikey . "+" . Libs::randString(16);
        $apikey = base64_encode($apikey);

        return $apikey;
    }

    /**
     * 删除一个用户 (APIKey)
     * 
     * @param string | int $uid    用户 uid
     * @param string $apikey       APIKey
     * @return array
     */
    public static function deleteUser($uid, $apikey): array
    {
        $all = self::getList();
        $_hasUser = false;
        foreach ($all as $item) {
            if ($item["id"] == $uid && $item["apikey"] == $apikey) {
                $_hasUser = true;
                break;
            }
        }

        if ($_hasUser === false) {
            return [
                "status"   => false,
                "code"     => 404,
                "message"  => "找不到 UID 为 {$uid} 的用户"
            ];
        }

        $deleteUser = self::$_db->query(
            self::$_db->delete("table.oauth")->where("id = ?", $uid)->where("apikey = ?", $apikey)
        );

        if ($deleteUser) {
            return [
                "status"   => true,
                "message"  => "已成功删除 UID{$uid} 的所有信息"
            ];
        }

        return [
            "status"   => false,
            "code"     => 500,
            "message"  => "服务器内部错误"
        ];
    }


    /**
     * 为 OAuth 用户增加配置项
     * 
     * @param int $uid
     * @param string $key
     * @param string $value
     */
    public static function addUserConfig($uid, $key, $value)
    {
        $userConfig = self::getUserConfig($uid);

        if (!empty($userConfig[$key])) {
            return self::setUserConfig($uid, [$key => $value]);
        }

        $userConfig[$key] = $value;

        self::$_db->query(
            self::$_db->update("table.oauth")->rows([
                "value"  => json_encode($userConfig)
            ])->where("id = ?", $uid)
        );

        return $userConfig;
    }

    /**
     * 设置 / 更新 OAuth 设置项
     * 
     * @param int $uid
     * @param array $config
     */
    public static function setUserConfig($uid, $config)
    {
        $configAll = self::getUserConfig($uid);
        $configArr = [];

        foreach ($configAll as $key => $item) {
            $configArr[$key] = (!empty($config[$key])) ? $config[$key] : $item;
        }

        self::$_db->query(
            self::$_db->update("table.oauth")->rows([
                "value"  => json_encode($configArr)
            ])->where("id = ?", $uid)
        );

        return $configArr;
    }

    /**
     * 获取 OAuth 用户的配置项
     * 
     * @param int $uid
     */
    public static function getUserConfig($uid)
    {
        $userConfig = self::$_db->fetchRow(self::$_db->select()->from("table.oauth")->where("id = ?", $uid));
        if (empty($userConfig)) return false;

        return !empty($userConfig["value"]) ? json_decode($userConfig["value"], true) : [];
    }
}
