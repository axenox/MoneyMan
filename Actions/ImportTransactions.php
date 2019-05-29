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

class ImportTransactions extends ImportTransactionsPreview
{
    private $defaultCategories = null;
    
    private $payeeIds = null;
    
    private $rules = null;
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Actions\CreateData::perform()
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        $data_sheet = $this->getInputDataEnriched($task);
        
        $data_sheet->getColumns()->removeByKey('transaction_category__category');
        
        $affected_rows = $data_sheet->dataCreate(true, $transaction);
        $this->setUndoDataSheet($data_sheet);
        
        $message = $this->getWorkbench()->getCoreApp()->getTranslator()->translate('ACTION.CREATEDATA.RESULT', ['%number%' => $affected_rows], $affected_rows);
        $result = ResultFactory::createDataResult($task, $data_sheet, $message);
        if ($affected_rows > 0) {
            $result->setDataModified(true);
        }
        
        return $result;
    }
}