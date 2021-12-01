--
-- 表的结构 `OAPI_oauth`
--
CREATE TABLE `OAPI_oauth` (
  `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT "ID",
  `apikey` varchar(512) default NULL COMMENT "APIKEY",
  `group` varchar(32) default NULL COMMENT "权限组",
  `created` int(10) unsigned default '0' COMMENT "创建时间",
  `value` text COMMENT "配置内容",
  PRIMARY KEY  (`id`),
  UNIQUE KEY `apikey` (`apikey`)
) ENGINE = %engine% DEFAULT CHARSET = %charset%;