-- Mobius disposable MySQL fixture: no production data or game credentials.
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE TABLE `accounts` (
  `accessLevel` BIGINT NULL,
  `created_time` BIGINT NULL,
  `email` VARCHAR(255) NULL,
  `lastIP` VARCHAR(255) NULL,
  `lastServer` VARCHAR(255) NULL,
  `lastactive` BIGINT NULL,
  `login` VARCHAR(255) NULL,
  `password` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `account_data` (
  `account_name` VARCHAR(255) NULL,
  `value` VARCHAR(255) NULL,
  `var` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `accounts_ipauth` (
  `ip` VARCHAR(255) NULL,
  `login` VARCHAR(255) NULL,
  `type` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `characters` (
  `accesslevel` BIGINT NULL,
  `account_name` VARCHAR(255) NULL,
  `charId` BIGINT NULL,
  `char_name` VARCHAR(255) NULL,
  `clanid` BIGINT NULL,
  `classid` BIGINT NULL,
  `deletetime` BIGINT NULL,
  `exp` BIGINT NULL,
  `karma` BIGINT NULL,
  `lastAccess` BIGINT NULL,
  `level` BIGINT NULL,
  `nobless` BIGINT NULL,
  `online` BIGINT NULL,
  `onlinetime` BIGINT NULL,
  `pkkills` BIGINT NULL,
  `pvpkills` BIGINT NULL,
  `race` BIGINT NULL,
  `reputation` BIGINT NULL,
  `sex` BIGINT NULL,
  `title` VARCHAR(255) NULL,
  `x` BIGINT NULL,
  `y` BIGINT NULL,
  `z` BIGINT NULL
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
  `charId` BIGINT NULL,
  `claimed` BIGINT NULL,
  `class_id` BIGINT NULL,
  `count` BIGINT NULL,
  `played` BIGINT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `castle` (
  `id` BIGINT NULL,
  `name` VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO accounts(login,password,email,created_time,lastactive,accessLevel,lastIP,lastServer) VALUES ('alice','fixture','alice@example.invalid',1760000000,1750000000,0,'127.0.0.1','1'),('bob','fixture','bob@example.invalid',1760000001,1750000010,-1,'127.0.0.1','1');
INSERT INTO characters(charId,account_name,char_name,level,exp,classid,race,sex,title,online,onlinetime,accesslevel,deletetime,pvpkills,pkkills,nobless,clanid,lastAccess,karma,reputation) VALUES (201,'alice','AliceKnight',80,12345,1,0,0,'',0,500,0,0,10,1,1,7,1893456000000,0,4),(202,'bob','BobMage',75,900,2,1,1,'',1,250,0,0,0,4,0,0,1893456000000,0,9),(203,'alice','AliceDeleted',99,9999,1,0,0,'',0,500,0,1,9,0,0,0,1893456000000,0,99);
INSERT INTO heroes(charId,class_id,`count`,played,claimed) VALUES (202,2,1,1,1);
INSERT INTO clan_data(clan_id,clan_name,clan_level,reputation_score,hasCastle,leader_id) VALUES (7,'TestClan',8,100,1,201);
INSERT INTO castle(id,name) VALUES (1,'Gludio Castle');
