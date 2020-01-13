-- --------------------------------------------------------

--
-- Table `account`
--

CREATE TABLE IF NOT EXISTS `account` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_on` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_on` datetime DEFAULT NULL,
  `account_name` varchar(25) NOT NULL,
  `account_type_id` int(11) NOT NULL,
  `currency_id` int(11) NOT NULL,
  `inactive_flag` tinyint(1) NOT NULL DEFAULT '0',
  `position` int(11) NOT NULL DEFAULT '0',
  `opening_balance` decimal(12,2) DEFAULT NULL,
  `imported_balance` decimal(12,2) DEFAULT NULL,
  `import_result_balance` decimal(12,2) DEFAULT NULL,
  `adarian_balance` decimal(12,2) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table `account_type`
--

CREATE TABLE IF NOT EXISTS `account_type` (
  `name` varchar(25) NOT NULL,
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_on` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_on` datetime DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table `category`
--

CREATE TABLE IF NOT EXISTS `category` (
  `category_name` varchar(255) DEFAULT NULL,
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_on` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_on` datetime DEFAULT NULL,
  `parent_id` int(11) NOT NULL DEFAULT '0',
  `category_type_id` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `parent_id_index` (`parent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table `category_type`
--

CREATE TABLE IF NOT EXISTS `category_type` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_on` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_on` datetime DEFAULT NULL,
  `name` varchar(50) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table `currency`
--

CREATE TABLE IF NOT EXISTS `currency` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_on` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_on` datetime DEFAULT NULL,
  `currency_symbol` varchar(5) NOT NULL,
  `currency_name` varchar(25) NOT NULL,
  `home_currency_flag` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table `currency_exchange_rate`
--

CREATE TABLE IF NOT EXISTS `currency_exchange_rate` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_on` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_on` datetime DEFAULT NULL,
  `from_currency_id` int(11) NOT NULL,
  `to_currency_id` int(11) NOT NULL,
  `date` date NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table `import_rule`
--

CREATE TABLE IF NOT EXISTS `import_rule` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_on` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_on` datetime DEFAULT NULL,
  `name` varchar(50) NOT NULL,
  `field` varchar(50) NOT NULL,
  `account_id` int(11) DEFAULT NULL,
  `regex` varchar(50) NOT NULL,
  `payee_id` int(11) DEFAULT NULL,
  `category_id` int(11) DEFAULT NULL,
  `transfer_account_id` int(11) DEFAULT NULL,
  `importance` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table `payee`
--

CREATE TABLE IF NOT EXISTS `payee` (
  `payee` varchar(45) DEFAULT NULL,
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_on` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_on` datetime DEFAULT NULL,
  `default_category_id` int(11) DEFAULT NULL,
  `usage` int(11) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- --------------------------------------------------------

--
-- Table `transaction`
--

CREATE TABLE IF NOT EXISTS `transaction` (
  `Id` int(11) NOT NULL AUTO_INCREMENT,
  `created_on` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_on` datetime DEFAULT NULL,
  `status` varchar(1) NOT NULL,
  `date` date DEFAULT NULL,
  `account_id` int(11) NOT NULL,
  `amount_booked` decimal(12,2) DEFAULT NULL,
  `currency_booked_id` int(11) NOT NULL,
  `payee_id` int(11) DEFAULT NULL,
  `payee_original_name` varchar(50) DEFAULT NULL,
  `class` varchar(25) DEFAULT NULL,
  `scheduled` varchar(255) DEFAULT NULL,
  `transfer_transaction_id` int(11) DEFAULT NULL,
  `transfer_autocreated` tinyint(1) DEFAULT NULL,
  `amount_payed` decimal(12,2) DEFAULT NULL,
  `currency_payed_id` int(11) DEFAULT NULL,
  `exchange_rate` double DEFAULT NULL,
  `excluded` varchar(255) DEFAULT NULL,
  `note` varchar(4000) DEFAULT NULL,
  `repeat_cron` varchar(20) DEFAULT NULL,
  `repeat_enabled` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`Id`),
  UNIQUE KEY `date_id_index` (`date`,`Id`) USING BTREE,
  UNIQUE KEY `account_date_index` (`account_id`,`date`,`Id`) USING BTREE,
  KEY `payee_id_index` (`payee_id`),
  KEY `status_index` (`status`),
  KEY `account_id_index` (`account_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 ROW_FORMAT=DYNAMIC;

-- --------------------------------------------------------

--
-- Table `transaction_category`
--

CREATE TABLE IF NOT EXISTS `transaction_category` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `created_on` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_on` datetime DEFAULT NULL,
  `transaction_id` int(11) NOT NULL,
  `category_id` int(11) NOT NULL,
  `amount` decimal(12,2) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 ROW_FORMAT=DYNAMIC;
COMMIT;