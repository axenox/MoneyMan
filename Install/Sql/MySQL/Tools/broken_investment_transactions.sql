-- Find broken imported transaction

SELECT GROUP_CONCAT(t.id)
FROM transaction t 
WHERE 
	t.`date` < '2016-01-01' 
	AND t.account_id = 28
	AND (
		(SELECT tt.account_id FROM transaction tt WHERE tt.Id = t.transfer_transaction_id) = 28
		OR -2146826246 IN (SELECT tc.category_id FROM transaction_category tc WHERE tc.transaction_id = t.id)
	);
	
	
-- Delete them
DELETE FROM `transaction` WHERE id IN (/* paste transaction-ids here */);