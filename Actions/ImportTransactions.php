<?php
namespace axenox\MoneyMan\Actions;

use exface\Core\Actions\CreateData;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Factories\ResultFactory;

class ImportTransactions extends CreateData
{
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Actions\CreateData::perform()
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        $data_sheet = $this->getInputDataSheet($task);
        $affected_rows = 0;
        
        $affected_rows += $data_sheet->dataCreate(true, $transaction);
        
        $this->setUndoDataSheet($data_sheet);
        
        $message = $this->getWorkbench()->getCoreApp()->getTranslator()->translate('ACTION.CREATEDATA.RESULT', ['%number%' => $affected_rows], $affected_rows);
        $result = ResultFactory::createDataResult($task, $data_sheet, $message);
        if ($affected_rows > 0) {
            $result->setDataModified(true);
        }
        
        return $result;
    }
}