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
        
        // Remove the category column, which is just there to show the enriched transactions
        // properly in the importer
        $data_sheet->getColumns()->removeByKey('transaction_category__category');
        
        // Remove duplicate transactions (= rows, where the id column has a value) and
        // separate transactions, that are only being cleared.
        $clearedSheet = DataSheetFactory::createFromObject($data_sheet->getMetaObject());
        foreach ($data_sheet->getUidColumn()->getValues(false) as $nr => $uid) {
            if ($uid) {
                if ($data_sheet->getCellValue('status', $nr) === 'P') {
                    $clearedSheet->addRow([
                        $data_sheet->getUidColumnName() => $uid,
                        'status' => 'C'
                    ]);
                }
                $data_sheet->removeRow($nr);
            }
        }
        
        if ($data_sheet->isEmpty() === false) {
            // Mark all newly imported rows a cleared
            if (! $statusCol = $data_sheet->getColumns()->get('status')) {
                $statusCol = $data_sheet->getColumns()->addFromExpression('status');
            }
            $statusCol->setValueOnAllRows('C');
            
            // Save them
            $affected_rows = $data_sheet->dataCreate(true, $transaction);
            $this->setUndoDataSheet($data_sheet);
            $message = $this->getWorkbench()->getCoreApp()->getTranslator()->translate('ACTION.CREATEDATA.RESULT', ['%number%' => $affected_rows], $affected_rows);
        } else {
            $message = 'No new transactions found in imported data';
        }
        
        if ($clearedSheet->isEmpty() === false) {
            $affected_rows += $clearedSheet->dataUpdate(false, $transaction);
            $message .= '; ' . $affected_rows . ' cleared';
        }
        
        $result = ResultFactory::createDataResult($task, $data_sheet, $message);
        if ($affected_rows > 0) {
            $result->setDataModified(true);
        }
        
        return $result;
    }
}