-- RUSaCis disposable MySQL fixture: no production data or game credentials.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE `accounts` (
  `access_level` BIGINT NULL,
  `last_active` BIGINT NULL,
  `last_server` VARCHAR(255) NULL,
  `login` VARCHAR(255) NULL,
  `password` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `characters` (
  `accesslevel` BIGINT NULL,
  `account_name` VARCHAR(255) NULL,
  `char_name` VARCHAR(255) NULL,
  `clanid` BIGINT NULL,
  `classid` BIGINT NULL,
  `deletetime` BIGINT NULL,
  `exp` BIGINT NULL,
  `herountil` BIGINT NULL,
  `karma` BIGINT NULL,
  `lastAccess` BIGINT NULL,
  `level` BIGINT NULL,
  `nobless` BIGINT NULL,
  `obj_Id` BIGINT NULL,
  `online` BIGINT NULL,
  `onlinetime` BIGINT NULL,
  `pkkills` BIGINT NULL,
  `pvpkills` BIGINT NULL,
  `race` BIGINT NULL,
  `sex` BIGINT NULL,
  `title` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `clan_data` (
  `clan_id` BIGINT NULL,
  `clan_level` BIGINT NULL,
  `clan_name` VARCHAR(255) NULL,
  `hasCastle` BIGINT NULL,
  `leader_id` BIGINT NULL,
  `reputation_score` BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `heroes` (
  `active` BIGINT NULL,
  `char_id` BIGINT NULL,
  `class_id` BIGINT NULL,
  `count` BIGINT NULL,
  `played` BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `castle` (
  `id` BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO accounts(login,password,last_active,access_level,last_server) VALUES ('alice','$2a$10$INVALID_FIXTURE_ONLY',1750000000,0,'1'),('bob','$2a$10$INVALID_FIXTURE_ONLY',1750000010,-1,'1');
INSERT INTO characters(obj_Id,account_name,char_name,level,exp,classid,race,sex,title,online,onlinetime,accesslevel,deletetime,pvpkills,pkkills,karma,nobless,herountil,clanid,lastAccess) VALUES (101,'alice','AliceKnight',80,12345,1,0,0,'',0,500,0,0,10,1,0,1,1893456000000,7,1893456000000),(102,'bob','BobMage',75,900,2,1,1,'',1,250,0,0,0,4,0,0,0,0,1893456000000),(103,'alice','AliceDeleted',99,9999,1,0,0,'',0,500,0,1,9,0,0,0,0,0,1893456000000);
INSERT INTO heroes(char_id,class_id,`count`,played,active) VALUES (102,2,1,1,1);
INSERT INTO clan_data(clan_id,clan_name,clan_level,reputation_score,hasCastle,leader_id) VALUES (7,'TestClan',8,100,1,101);
INSERT INTO castle(id) VALUES (1);
