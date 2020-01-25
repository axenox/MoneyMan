<?php
namespace axenox\MoneyMan\Actions;

use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Factories\ResultFactory;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Factories\DataSheetFactory;

class ImportInvestments extends ImportTransactions
{
    private $investmentData = null;
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Actions\CreateData::perform()
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {        
        $importData = $this->enrichInvestmentData($this->getInputDataSheet($task));
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
    
    protected function getInvestmentId(DataSheetInterface $importData, int $rowNr) : int
    {
        if ($this->investmentData === null) {
            $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.MoneyMan.investment');
            $ds->getColumns()->addMultiple(['id', 'wkn']);
            $ds->getFilters()->addConditionFromValueArray('wkn', $importData->getColumns()->get('investment__wkn')->getValues());
            $ds->dataRead();
            $this->investmentData = $ds;
        }
        
        $wkn = $importData->getCellValue('investment__wkn', $rowNr);
        $id = $this->investmentData->getCellValue('id', $this->investmentData->getColumns()->get('wkn')->findRowByValue($wkn));
        
        if ($id === null) {
            throw new BehaviorRuntimeError($this->getObject(), 'Cannot find investment with WKN "' . $wkn . '"!');
        }
        
        return $id;
    }
}