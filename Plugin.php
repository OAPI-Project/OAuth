<?php
/**
 * OAuth Core
 * 一个用于鉴权的核心插件
 * 
 * @author ohmyga
 * @package OAuth
 * @name OAuth Core
 * @version 1.0.0
 */

namespace OAPIPlugin\OAuth;

use OAPI\Plugin\PluginInterface;
use OAPI\DB\DB;
use OAPI\DB\Consts;
use OAPI\Libs\Libs;
use OAPI\HTTP\HTTP;
use OAPI\Redis\OMRedis;
use OAPI\Console\Console;
use OAPI\LogCat\Error;
use OAPI\Plugin\Exception;

class Plugin implements PluginInterface
{
    private static $_db;

    private static $_redis;

    public static $oauth;

    /**
     * 激活插件方法
     * 
     * @return void
     */
    public static function enable()
    {
        $db = DB::get();
        try {
            if (!self::__checkTable($db, "oauth")) {
                if (!file_exists(__DIR__ . "/auth.sql")) throw new Exception("无法找到 OAuth 插件的数据库初始化文件");

                $sqlfile = file_get_contents(__DIR__ . "/auth.sql");
                $sqlfile = str_replace("OAPI_", $db->getPrefix(), $sqlfile);

                $sqlfile = str_replace("%engine%", isset($db->getEngine()["engine"]) ? $db->getEngine()["engine"] : "InnoDB", $sqlfile);
                $sqlfile = str_replace("%charset%", isset($db->getCharset()["charset"]) ? $db->getCharset()["charset"] : "utf8mb4", $sqlfile);

                $sqlfile = explode(";", $sqlfile);
                foreach ($sqlfile as $script) {
                    $script = trim($script);
                    if ($script) {
                        $db->query($script, Consts::WRITE);
                    }
                }

                Console::success("数据库初始化成功，已创建 OAuth 所需的表", "OAuth");
            } else {
                Console::info("初始化成功，发现 OAuth 所需的表，无需重复创建", "OAuth");
            }
        } catch (Exception $e) {
            Console::error("数据库初始化失败，无法创建 OAuth 所需的表，请查阅日志", "OAuth");
            Error::eachxception_handler($e, (__OAPI_DEBUG__ === true) ? true : false);
        }

        \OAPIPlugin\Admin\Menu::setConfig(__CLASS__);
    }

    /**
     * 禁用插件方法
     * 
     * @return void
     */
    public static function disable()
    {
        \OAPIPlugin\Admin\Menu::removeConfig(__CLASS__);
    }

    /**
     * 插件每次初始化调用的方法
     * 
     * @return void
     */
    public static function run()
    {
        self::$_db = DB::get();
        self::$_redis = OMRedis::get()->redis;

        \OAPI\Plugin\Plugin::actionRegisterRouter(__CLASS__);
        \OAPIPlugin\Admin\Plugin::addAdminRouter(__CLASS__);

        new Core(self::$_db, self::$_redis);
        // 设置默认调用次数
        if (empty(Core::getConfig()["defaultCount"])) Core::addConfig("defaultCount", 2333);
        if (empty(Core::getConfig()["guestCount"])) Core::addConfig("guestCount", 800);
    }

    /**
     * 插件菜单配置
     * 
     * @return array
     */
    public static function menuconfig(): array
    {
        $menu = [
            "id"        => "menu",
            "icon"      => "shield-account",
            "name"      => "OAuth",
            "hasChild"  => true,
            "children"  => []
        ];

        $menu["children"] = [
            [
                "name"   => "基本",
                "page"   => "basic"
            ],
            [
                "name"   => "用户管理",
                "page"   => "users"
            ]
        ];

        return $menu;
    }

    /**
     * 页面获取
     * 
     * @return array
     */
    public static function page_handler($page)
    {
        $page = strtolower($page);
        $file = __DIR__ . '/Template/' . $page . ".vue";

        return \OAPIPlugin\Admin\VueTemplate::load($file, $page);
    }

    /**
     * OAuth API
     * 
     * @version 1
     * @path /oauth
     */
    public static function OAuthAPI_Admin_Action($request, $response, $matches)
    {
        if (HTTP::lockMethod(["GET", "POST", "DELETE"]) == false) return;

        $method = HTTP::getParams("method", null);

        if ($method === null) {
            HTTP::sendJSON(false, 400, "Method is empty", []);
            return false;
        } else {
            $method = strtolower($method);
        }

        // 获取配置
        if ($method == "basic") {
            if (HTTP::lockMethod("GET") == false) return false;
            if (\OAPIPlugin\Admin\Plugin::checkAuth(true) === false) return false;

            $_list = Core::getList();
            $_count = [
                "friend" => 0,
                "normal" => 0,
                "banned" => 0,
            ];
            foreach ($_list as $item) {
                if ($item["group"] == "friend") $_count["friend"]++;
                if ($item["group"] == "normal") $_count["normal"]++;
                if ($item["group"] == "banned") $_count["banned"]++;
            }

            $result = [
                "count"      =>  [
                    "all"      => (int) count($_list),
                    "banned"   => $_count["banned"],
                    "friend"   => $_count["friend"],
                    "normal"   => $_count["normal"]
                ],
                "bot"        => [
                    "set"      => !empty(Core::getConfig()["bot_auth"]) ? true : false,
                    "auth_key" => !empty(Core::getConfig()["bot_auth"]) ? Core::getConfig()["bot_auth"] : null,
                ],

            ];

            HTTP::sendJSON(true, 200, "Success", $result);
            return true;
        }

        // 设置 Bot 专用 AuthKey
        if ($method == "set_bot_auth") {
            if (HTTP::lockMethod("GET") == false) return false;
            if (\OAPIPlugin\Admin\Plugin::checkAuth(true) === false) return false;

            $randString = Libs::randString(64, true);

            if (!empty(Core::getConfig()["bot_auth"])) {
                Core::setConfig([
                    "bot_auth" => $randString
                ]);
            } else {
                Core::addConfig("bot_auth", $randString);
            }

            HTTP::sendJSON(true, 200, "Success", [
                "bot_auth_key"  => $randString
            ]);

            return true;
        }

        // 获取用户列表
        if ($method == "list") {
            if (HTTP::lockMethod("GET") == false) return false;

            if (\OAPIPlugin\Admin\Plugin::checkAuth(true) === false) return false;

            $list = Core::getList();

            if (empty($list)) {
                HTTP::sendJSON(true, 200, "Success", []);
                return true;
            }

            $result = [];

            foreach ($list as $item) {
                $result[] = [
                    "id"       => $item["id"],
                    "apikey"   => $item["apikey"],
                    "group"    => $item["group"],
                    "created"  => $item["created"],
                    "config"   => is_array(json_decode($item["value"], true)) ? json_decode($item["value"], true) : []
                ];
            }

            HTTP::sendJSON(true, 200, "Success", $result);
            return true;
        }

        // 新增用户
        if ($method == "add") {
            if (HTTP::lockMethod("POST") == false) return false;
            if (\OAPIPlugin\Admin\Plugin::checkAuth(true) === false) return false;

            $config = HTTP::getParams("config", "");
            $group = HTTP::getParams("group", "");
            $useConfig = (string)HTTP::getParams("use_config", "true");

            if (empty($group)) {
                HTTP::sendJSON(false, 400, "新增用户的权限组不能为空");
                return false;
            } elseif (!in_array(strtolower($group), ["admin", "friend", "normal", "banned"])) {
                HTTP::sendJSON(false, 404, "所选权限组不存在");
                return false;
            }

            if (($useConfig != "false" && $useConfig != "0") && empty($config)) {
                HTTP::sendJSON(false, 400, "请提交用户配置", [], ["tips" => "如果不添加用户配置请附加请求参数 use_config 并将值填为 false"]);
                return false;
            }

            if (!empty($config) && !is_array(json_decode($config, true))) {
                HTTP::sendJSON(false, 400, "新增用户的配置格式错误");
                return false;
            } else {
                $config = json_decode($config, true);
            }

            $result = Core::addUser($group, ($useConfig != "false" && $useConfig != "0") ? $config : []);
            $result = [
                "id"       => $result["id"],
                "apikey"   => $result["apikey"],
                "group"    => $result["group"],
                "created"  => $result["created"],
                "config"   => $result["value"]
            ];

            HTTP::sendJSON(true, 200, "Success", $result);
            return true;
        }

        // 删除指定用户
        if ($method == "delete") {
            if (HTTP::lockMethod("DELETE") == false) return false;
            if (\OAPIPlugin\Admin\Plugin::checkAuth(true) === false) return false;

            $uid = HTTP::getParams("uid", "");
            $apikey = HTTP::getParams("apikey", "");

            if (empty($uid) || empty($apikey)) {
                HTTP::sendJSON(false, 400, "缺少需要删除的 uid 以及 apikey");
                return false;
            }

            $deleteUser = Core::deleteUser($uid, $apikey);

            if ($deleteUser["status"] === false) {
                HTTP::sendJSON(false, $deleteUser["code"], $deleteUser["message"]);
                return false;
            }

            HTTP::sendJSON(true, 200, "Success", [], ["nya" => $deleteUser["message"]]);
            return true;
        }

        // 修改用户设置
        if ($method == "modify_user_settings") {
            if (HTTP::lockMethod("POST") == false) return false;
            if (\OAPIPlugin\Admin\Plugin::checkAuth(true) === false) return false;

            $uid = HTTP::getParams("uid", "");
            if (empty($uid)) {
                HTTP::sendJSON(false, 400, "缺少需要更新设置的 uid");
                return false;
            }

            $do = HTTP::getParams("do", "");
            if (empty($do)) {
                HTTP::sendJSON(false, 400, "接下来是要执行什么操作呢？");
                return false;
            }

            if (!in_array(strtolower($do), ["refresh_apikey", "update_group"])) {
                HTTP::sendJSON(false, 404, "未知的操作");
                return false;
            } else {
                $do = strtolower($do);
            }

            // 刷新 APIKey
            if ($do == "refresh_apikey") {
                $user = self::$_db->fetchRow(self::$_db->select()->from("table.oauth")->where("id = ?", $uid));
                if (empty($user)) {
                    HTTP::sendJSON(false, 404, "找不到 UID{$uid} 的用户信息");
                    return false;
                }

                $new_apikey = Core::createAPIKey($user["id"], $user["created"]);

                $refresh = self::$_db->query(
                    self::$_db->update("table.oauth")->rows([
                        "apikey"  => $new_apikey
                    ])->where("id = ?", $uid)
                );

                if ($refresh) {
                    HTTP::sendJSON(true, 200, "Success", [
                        "id"       => $uid,
                        "apikey"   => $new_apikey,
                        "created"  => $user["created"],
                        "group"    => $user["group"]
                    ]);
                    return true;
                }

                HTTP::sendJSON(false, 500, "无法更新 UID{$uid} 的 APIKey (服务器内部错误)");
                return false;
            }

            // 修改用户组
            if ($do == "update_group") {
                $new_group = HTTP::getParams("new_group", "");

                if (empty($new_group)) {
                    HTTP::sendJSON(false, 400, "需要更新的用户组不能为空");
                    return false;
                }

                if (!in_array(strtolower($new_group), ["admin", "friend", "normal", "banned"])) {
                    HTTP::sendJSON(false, 400, "所提交的新用户组有误");
                    return false;
                } else {
                    $new_group = strtolower($new_group);
                }

                $user = self::$_db->fetchRow(self::$_db->select()->from("table.oauth")->where("id = ?", $uid));
                if (empty($user)) {
                    HTTP::sendJSON(false, 404, "找不到 UID{$uid} 的用户信息");
                    return false;
                }

                $update_group = self::$_db->query(
                    self::$_db->update("table.oauth")->rows([
                        "group"  => $new_group
                    ])->where("id = ?", $uid)
                );

                if ($update_group) {
                    HTTP::sendJSON(true, 200, "Success", [
                        "id"       => $uid,
                        "apikey"   => $user["apikey"],
                        "created"  => $user["created"],
                        "group"    => $new_group
                    ]);
                    return true;
                }

                HTTP::sendJSON(false, 500, "无法更新 UID{$uid} 的用户组 (服务器内部错误)");
                return false;
            }

            HTTP::sendJSON(false, 404, "404 Not Found");
            return false;
        }

        HTTP::sendJSON(false, 404, "404 Not Found", []);
    }

    private static function __checkBotAuth()
    {
    }

    /**
     * 检查调用额度及权限
     * 
     */
    public static function check($apiname, $group = []): array
    {
        $score = Core::checkScore($apiname, $group);

        if ($score["status"] === false) {
            HTTP::sendJSON(
                false,
                !empty($score["code"]) ? (int) $score["code"] : 500,
                !empty($score["message"]) ? $score["message"] : "没错误，怎么想都不可能有错误嘛！",
                !empty($score["data"]) ? $score["data"] : [],
                !empty($score["more"]) ? $score["more"] : [],
            );
            return ["status" => false];
        }

        return [
            "status" => true,
            "data"   => !empty($score["data"]) ? $score["data"] : [],
            "more"   => !empty($score["more"]) ? $score["more"] : []
        ];
    }

    /**
     * 判断数据库表是否存在
     * 
     * @param DB $db
     * @param string $table
     * @return bool
     */
    private static function __checkTable(DB $db, $table): bool
    {
        return empty($db->fetchAll($db->select("table_name")->from("information_schema.TABLES")->where("table_name = ?", $db->getPrefix() . $table))) ? false : true;
    }
}
