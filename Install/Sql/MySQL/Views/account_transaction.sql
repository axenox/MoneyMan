CREATE OR REPLACE VIEW account_transaction AS
SELECT  
	t1.`Id`,  
	t1.`created_on`,  
	t1.`updated_on`,  
	CASE 
		WHEN `transfer_to_account_id` IS NOT NULL THEN "TRANSFERT_OUT"
		WHEN `amount_booked` > 0 THEN "DEPOSIT" 
		WHEN `amount_booked` <= 0 THEN "WITHDRAWAL"
	END AS `type`,  
	t1.`status`,  
	t1.`date`,  
	t1.`amount_booked`,
	t1.`currency_booked_id`, 
	t1.`account_id`,  
	t1.`payee_id`,  
	t1.`payee_original_name`,  
	t1.`class`,  
	t1.`scheduled`,  
	t1.`transfer_to_account_id`,  
	t1.`currency_payed_id`,  
	t1.`amount_payed`,  
	t1.`exchange_rate`,  
	t1.`excluded`,  
	t1.`note`
	FROM transaction t1
UNION ALL
SELECT  
	tout.`Id`,  
	tout.`created_on`,  
	tout.`updated_on`,  
	"TRANSFER_IN" AS `type`,  
	tout.`status`,  
	tout.`date`, 
	IFNULL(tout.`amount_payed`, tout.`amount_booked`)*(-1) AS `amount_booked`,
	IFNULL(tout.`currency_payed_id`, tout.`currency_booked_id`) AS `currency_booked_id`,  
	tout.`transfer_to_account_id` AS `account_id`,  
	tout.`payee_id`,   
	CONCAT("[", aout.account_name, "]") AS `payee_original_name`,   
	tout.`class`,  
	tout.`scheduled`,  
	tout.`account_id` AS `transfer_to_account_id`,  
	tout.`currency_payed_id`,  
	tout.`amount_payed`,  
	tout.`exchange_rate`,  
	tout.`excluded`, 
	tout.`note`
	FROM `transaction` tout INNER JOIN account aout ON aout.id = tout.transfer_to_account_id
	WHERE tout.transfer_to_account_id > 0