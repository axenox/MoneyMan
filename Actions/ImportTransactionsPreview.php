<?php
namespace axenox\MoneyMan\Actions;

use exface\Core\Actions\CreateData;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Factories\ResultFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\Exceptions\Actions\ActionRuntimeError;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\DateDataType;
use exface\Core\DataTypes\NumberDataType;

class ImportTransactionsPreview extends CreateData
{
    private $defaultCategories = null;
    
    private $payeeIds = null;
    
    private $rules = null;
    
    private $existingTransactions = null;
    
    private $existingTransactionsDaysRange = 3;
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Actions\CreateData::perform()
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        $data_sheet = $this->getInputDataEnriched($task);
        $message = $this->getApp()->getTranslator()->translate('ACTION.IMPORTTRANSACTIONSPREVIEW.RESULT', ['%number%' => $data_sheet->countRows()], $data_sheet->countRows());
        return ResultFactory::createDataResult($task, $data_sheet, $message);
    }
    
    /**
     * 
     * @param TaskInterface $task
     * @return DataSheetInterface
     */
    protected function getInputDataEnriched(TaskInterface $task) : DataSheetInterface
    {
        $data_sheet = $this->getInputDataSheet($task);
        $data_sheet = $this->enrichData($data_sheet);
        return $this->checkDuplicates($data_sheet);
    }
    
    /**
     * 
     * @param DataSheetInterface $sheet
     * @return DataSheetInterface
     */
    protected function enrichData(DataSheetInterface $sheet) : DataSheetInterface
    {
        $sheet = $this->applyImportRules($sheet);
        
        foreach ($sheet->getRows() as $nr => $row) {
            if (! $row['payee'] && $row['payee_original_name']) {
                $row['payee'] = $this->getPayeeId($row['payee_original_name']);
                if ($row['payee'] !== null) {
                    $sheet->setCellValue('payee', $nr, $row['payee']);
                }
            }
            
            if (! $row['transfer_to_account'] && ! $row['transaction_category']) {
                if (! $row['transaction_category__category'] && $row['payee']) {
                    $row['transaction_category__category'] = $this->getDefaultCategory($row['payee']);
                }
                
                if ($row['transaction_category__category']) {
                    $catSheet = DataSheetFactory::createFromObject($sheet->getMetaObject()->getRelation('transaction_category')->getRightObject());
                    $catSheet->addRow([
                        'category' => $row['transaction_category__category'],
                        'amount' => $row['amount_booked']
                    ]);
                    $sheet->setCellValue('transaction_category', $nr, $catSheet);
                }
            }
        }
        return $sheet;
    }
    
    /**
     * 
     * @param int $payeeId
     * @return int|NULL
     */
    protected function getDefaultCategory(int $payeeId) : ?int
    {
        if ($this->defaultCategories === null) {
            $this->defaultCategories = [];
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.MoneyMan.payee');
            $ds->getColumns()->addFromExpression('default_category');
            $ds->getColumns()->addFromExpression('id');
            $ds->dataRead();
            foreach ($ds->getRows() as $row) {
                $this->defaultCategories[$row['id']] = $row['default_category'];
            }
        }
        
        return $this->defaultCategories[$payeeId];
    }
    
    /**
     * 
     * @param string $payeeName
     * @return int|NULL
     */
    protected function getPayeeId(string $payeeName) : ?int
    {
        if ($this->payeeIds === null) {
            $this->payeeIds = [];
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.MoneyMan.transaction');
            $ds->getColumns()->addFromExpression('payee');
            $ds->addFilterFromString('payee_original_name', $payeeName);
            $ds->getSorters()->addFromString('created_on', SortingDirectionsDataType::DESC);
            $ds->setRowsLimit(1);
            $ds->dataRead();
            foreach ($ds->getRows() as $row) {
                $this->payeeIds[$payeeName] = $row['payee'];
            }
        }
        
        return $this->payeeIds[$payeeName];
    }
    
    protected function applyImportRules(DataSheetInterface $sheet) : DataSheetInterface
    {
        if ($this->rules === null) {
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.MoneyMan.import_rule');
            $ds->getColumns()->addMultiple(['account', 'field', 'regex', 'importance', 'category', 'payee', 'transfer_to_account']);
            $ds->getSorters()->addFromString('importance', SortingDirectionsDataType::ASC);
            $ds->dataRead();
            $this->rules = $ds;
        }
        
        foreach ($this->rules->getRows() as $rule) {
            $sheet = $this->applyImportRule($sheet, $rule);
        }
        
        return $sheet;
    }
    
    protected function applyImportRule(DataSheetInterface $sheet, array $rule) : DataSheetInterface
    {
        foreach ($sheet->getRows() as $rowNr => $row) {
            if ($rule['account'] !== null && $rule['account'] != $row['account']) {
                continue;
            }
                
            switch ($rule['field']) {
                case 'any':
                    $match = preg_match($rule['regex'], $row['note']);
                    if ($match === 0) {
                        $match = preg_match($rule['regex'], $row['payee_original_name']);
                    }
                    if ($match === false) {
                        throw new ActionRuntimeError($this, 'Failed evaluating regex "' . $rule['regex'] . '"!');
                    }
                    if ($match === 0) {
                        continue 2;
                    } 
                    break;
                default:
                    $match = preg_match($rule['regex'], $row[$rule['field']]);
                    if ($match === false) {
                        throw new ActionRuntimeError($this, 'Failed evaluating regex "' . $rule['regex'] . '"!');
                    }
                    if ($match === 0) {
                        continue 2;
                    }
            }
            
            if (! $row['payee'] && $rule['payee'] !== null) {
                $sheet->setCellValue('payee', $rowNr, $rule['payee']);
            }
            
            if (! $row['transaction_category__category'] && $rule['category'] !== null) {
                $sheet->setCellValue('transaction_category__category', $rowNr, $rule['category']);
            }
            
            if (! $row['transaction_category__category'] && ! $row['transfer_to_account'] && $rule['transfer_to_account'] !== null) {
                $sheet->setCellValue('transfer_to_account', $rowNr, $rule['transfer_to_account']);
            }
        }
        
        return $sheet;
    }
    
    protected function getExistingTransactions(DataSheetInterface $inputSheet) : DataSheetInterface
    {
        if ($this->existingTransactions === null) {
             $minDate = null;
             $maxDate = null;
             foreach ($inputSheet->getRows() as $row) {
                 $rowDate = DateDataType::cast($row['date']);
                 if ($minDate === null || $minDate > $rowDate) {
                     $minDate = $rowDate;
                 }
                 if ($maxDate === null || $maxDate < $rowDate) {
                     $maxDate = $rowDate;
                 }
             }
             $minDate = DateDataType::cast(strtotime($minDate. ' - ' . $this->existingTransactionsDaysRange . ' days'));
             $maxDate = DateDataType::cast(strtotime($maxDate. ' + ' . $this->existingTransactionsDaysRange . ' days'));
             
             $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.MoneyMan.account_transaction');
             $cols = $ds->getColumns();
             $cols->addFromAttribute($ds->getMetaObject()->getUidAttribute());
             $cols->addMultiple(['account', 'transfer_to_account', 'payee', 'amount_booked', 'date']);
             $ds->addFilterFromString('date', $minDate, ComparatorDataType::GREATER_THAN_OR_EQUALS);
             $ds->addFilterFromString('date', $maxDate, ComparatorDataType::LESS_THAN_OR_EQUALS);
             $ds->dataRead();
             $this->existingTransactions = $ds;
        }
        
        return $this->existingTransactions;
    }
    
    protected function checkDuplicates(DataSheetInterface $enrichedData) : DataSheetInterface
    {
        $existingData = $this->getExistingTransactions($enrichedData);
        foreach ($enrichedData->getRows() as $rowNr => $rowNew) {
            foreach ($existingData->getRows() as $rowOld) {
                if (NumberDataType::cast($rowNew['amount_booked']) == $rowOld['amount_booked'] && $rowNew['payee'] === $rowOld['payee']) {
                    $datetime1 = new \DateTime($rowOld['date']);
                    $datetime2 = new \DateTime($rowNew['date']);
                    $interval = $datetime1->diff($datetime2, true);
                    $dateMatch = $interval->days <= $this->existingTransactionsDaysRange;
                    
                    $accountsMatch = $rowOld['account'] == $rowNew['account'] && $rowOld['transfer_to_account'] == $rowNew['transfer_to_account'];
                    $accountsMatchReversed = $rowOld['transfer_to_account'] == $rowNew['account'] && $rowOld['account'] == $rowNew['transfer_to_account'];
                    if ($dateMatch === true && ($accountsMatch || $accountsMatchReversed)) {
                        $enrichedData->setCellValue('id', $rowNr, $rowOld['id']);
                        continue 2;
                    }
                }
            }
        }
        return $enrichedData;
    }
    
    /**
     *
     * @return int
     */
    public function getNumberOfDaysToSearchForDuplicates() : int
    {
        return $this->existingTransactionsDaysRange;
    }
    
    /**
     * Search fro duplicates with the range of +/- X days from the transaction date.
     * 
     * @uxon-property number_of_days_to_search_for_duplicates
     * @uonx-type int
     * @uxon-default 3
     * 
     * @param int $value
     * @return ImportTransactionsPreview
     */
    public function setNumberOfDaysToSearchForDuplicates(int $value) : ImportTransactionsPreview
    {
        $this->existingTransactionsDaysRange = $value;
        return $this;
    }
    
}