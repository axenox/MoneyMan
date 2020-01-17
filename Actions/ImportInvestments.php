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
use exface\Core\Exceptions\Actions\ActionInputError;
use exface\Core\DataTypes\NumberDataType;
use exface\Core\DataTypes\DateDataType;
use exface\Core\DataTypes\ComparatorDataType;

class ImportInvestments extends ImportTransactions
{
    private $investmentData = null;
    
    private $accountData = null;
    
    private $existingTransactions = null;
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Actions\CreateData::perform()
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        $importData = $this->enrichInvestmentData($this->getInputDataSheet($task));
        
        $tsSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.MoneyMan.transaction');
        $tsSheet->getColumns()->addFromUidAttribute();
        foreach ($importData->getRows() as $rowNr => $row) {            
            $tsSheet->removeRows();
            $tsSheet->addRow([
                'date' => $row['transaction__date'],
                'account' => $this->getAccountId($importData, $rowNr),
                'transfer_transaction__account' => $this->getCashAccountId($importData, $rowNr),
                'amount_booked' => $this->getAmountShareValue($importData, $rowNr),
                'transfer_transaction__amount_booked' => (-1)*$this->getAmountPayed($importData, $rowNr),
                'currency_booked' => $row['currency'],
                'transaction_category' => $this->getTransactionCategories($importData, $rowNr),
                'status' => 'C'
            ]);
            $tsSheet = $this->enrichData($tsSheet);
            $tsSheet = $this->checkDuplicates($tsSheet);
            $tsSheet->dataCreate(false, $transaction);
            
            $importData->setCellValue('transaction', $rowNr, $tsSheet->getUidColumn()->getCellValue(0));
        }
        
        foreach ($importData->getColumns() as $col) {
            if ($col->getAttribute()->getRelationPath()->isEmpty() === false) {
                $importData->getColumns()->remove($col);
            }
        }
        
        $affected_rows = $importData->dataCreate(false, $transaction);
        
        $result = ResultFactory::createDataResult($task, $importData, 'OK!');
        if ($affected_rows > 0) {
            $result->setDataModified(true);
        }
        
        return $result;
    }
    
    /**
     *
     * @param DataSheetInterface $sheet
     * @return DataSheetInterface
     */
    protected function enrichInvestmentData(DataSheetInterface $sheet) : DataSheetInterface
    {
        foreach ($sheet->getRows() as $nr => $row) {
            $sheet->setCellValue('investment', $nr, $this->getInvestmentId($sheet, $nr));
        }
        return $sheet;
    }
    
    protected function getAmountShareValue(DataSheetInterface $importData, int $rowNr)
    {
        $shares = NumberDataType::cast($importData->getCellValue('shares', $rowNr));
        $price = NumberDataType::cast($importData->getCellValue('price', $rowNr));
        
        if ($shares === null || $price === null) {
            throw new ActionInputError($this, 'Missing input values: number of shares, price per share and total ar all required!');
        }
        
        return $shares * $price;
    }
    
    protected function getAmountPayed(DataSheetInterface $importData, int $rowNr) : float
    {
        $total = NumberDataType::cast($importData->getCellValue('transaction__amount_booked', $rowNr));
        
        if ($total === null) {
            throw new ActionInputError($this, 'Missing input for total payed value!');
        }
        
        return $total;
    }
    
    protected function getTransactionCategories(DataSheetInterface $importData, int $rowNr) : DataSheetInterface
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.MoneyMan.transaction_category');
        
        $fee = $this->getAmountPayed($importData, $rowNr) - $this->getAmountShareValue($importData, $rowNr);
        
        $ds->addRow([
            'category' => $this->getFeeCategoryId($importData, $rowNr),
            'amount' => (-1)*$fee,
            'transaction' => $importData->getCellValue('transaction', $rowNr)
        ]);
        
        return $ds;
    }
    
    protected function getInvestmentGainData(DataSheetInterface $importData, int $rowNr) : DataSheetInterface
    {
        $shares = $importData->getCellValue('shares', $rowNr);
        if ($shares > 0) {
            return null;
        }
        
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.MoneyMan.investment_transaction_gain');
        
        $fee = $this->getAmountPayed($importData, $rowNr) - $this->getAmountShareValue($importData, $rowNr);
        
        $ds->addRow([
            'category' => $this->getFeeCategoryId($importData, $rowNr),
            'amount' => (-1)*$fee,
            'transaction' => $importData->getCellValue('transaction', $rowNr)
        ]);
        
        return $ds;
    }
    
    protected function getInvestmentId(DataSheetInterface $importData, int $rowNr) : int
    {
        if ($this->investmentData === null) {
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.MoneyMan.investment');
            $ds->getColumns()->addMultiple(['id', 'wkn']);
            $ds->addFilterInFromString('wkn', $importData->getColumns()->get('investment__wkn')->getValues());
            $ds->dataRead();
            $this->investmentData = $ds;
        }
        
        $wkn = $importData->getCellValue('investment__wkn', $rowNr);
        $id = $this->investmentData->getCellValue('id', $this->investmentData->getColumns()->get('wkn')->findRowByValue($wkn));
        
        if ($id === null) {
            throw new ActionInputError($this, 'Cannot find investment with WKN "' . $wkn . '"!');
        }
        
        return $id;
    }
    
    protected function getFeeCategoryId(DataSheetInterface $importData, int $rowNr) : int
    {
        $accountRow = $this->getAccountData($importData, $rowNr);
        return $accountRow['fee_category'];
    }
    
    protected function getGainCategoryId(DataSheetInterface $importData, int $rowNr) : int
    {
        $accountRow = $this->getAccountData($importData, $rowNr);
        return $accountRow['gain_category'];
    }
    
    protected function getAccountId(DataSheetInterface $importData, int $rowNr) : int
    {
        $accountRow = $this->getAccountData($importData, $rowNr);
        return $accountRow['account'];
    }
    
    protected function getCashAccountId(DataSheetInterface $importData, int $rowNr) : int
    {
        $accountRow = $this->getAccountData($importData, $rowNr);
        return $accountRow['cash_account'];
    }
    
    protected function getAccountData(DataSheetInterface $importData, int $rowNr) : array
    {
        if ($this->accountData === null) {
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.MoneyMan.investment_account');
            $ds->getColumns()->addMultiple(['id', 'account', 'cash_account', 'fee_category', 'gain_category']);
            $ds->addFilterInFromString('account', $importData->getColumns()->get('transaction__account')->getValues());
            $ds->dataRead();
            $this->accountData = $ds;
        }
        
        return $this->accountData->getRow($this->accountData->getColumns()->get('account')->findRowByValue($importData->getCellValue('transaction__account', $rowNr)));
    }
}