CREATE OR REPLACE VIEW investment_transaction_gain AS
SELECT 
	`itsold`.`id` AS `id`,
	`itsold`.`created_on` AS `created_on`,
	`itsold`.`updated_on` AS `updated_on`,
	`itsold`.`buy_transaction_id` AS `buy_transaction_id`,
	`itsold`.`sell_transaction_id` AS `sell_transaction_id`,
	`itsold`.`shares` AS `shares`,
	`itb`.`transaction_id` AS `buy_account_transaction_id`,
	`itb`.`investment_id` AS `investment_id`,
	`itsold`.`shares` AS `buy_shares`,
	`itb`.`price` AS `buy_price`,
	(`itb`.`price` * `itsold`.`shares`) AS `buy_total`,
	`itsold`.`shares` AS `sell_shares`,
	`its`.`price` AS `sell_price`,
	(`its`.`price` * `itsold`.`shares`) AS `sell_total`,
	`its`.`transaction_id` AS `sell_account_transaction_id`,
	((`its`.`price` * `itsold`.`shares`) - (`itb`.`price` * `itsold`.`shares`)) AS `gain`
FROM `investment_transaction_sold` `itsold`
	JOIN `investment_transaction` `itb` ON `itb`.`id` = `itsold`.`buy_transaction_id`
	JOIN `investment_transaction` `its` ON `its`.`id` = `itsold`.`sell_transaction_id`;