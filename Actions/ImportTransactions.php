<?php
namespace axenox\MoneyMan\Actions;

use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Factories\ResultFactory;
use exface\Core\Factories\DataSheetFactory;

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
        // IMPORTANT: traverse the rows in reverse order because we remove rows by row
        // number and if we would remove a lower row number all subsequent rows would
        // get reindexed, so the next time we remove an index, it would point to a totally
        // different row!!! Reversing the direction makes sure, removing a row never changes
        // indexes of rows still to be checked.
        $clearedSheet = DataSheetFactory::createFromObject($data_sheet->getMetaObject());
        $uidName = $data_sheet->getUidColumnName();
        $rows = $data_sheet->getRows();
        $rowsCnt = $data_sheet->countRows();
        for ($nr = $rowsCnt-1; $nr >= 0; $nr--) {
            $row = $rows[$nr];
            if ($row[$uidName]) {
                if ($row['status'] === 'P') {
                    $clearedSheet->addRow([
                        $uidName => $row[$uidName],
                        'status' => 'C'
                    ]);
                }
                $data_sheet->removeRow($nr, true);
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