<?php
namespace axenox\MoneyMan\Behaviors;

use exface\Core\CommonLogic\Model\Behaviors\AbstractBehavior;
use exface\Core\Interfaces\Model\BehaviorInterface;
use exface\Core\Events\DataSheet\OnBeforeCreateDataEvent;
use exface\Core\Events\DataSheet\OnBeforeUpdateDataEvent;
use exface\Core\Events\DataSheet\AbstractDataSheetEvent;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Events\DataSheet\OnCreateDataEvent;
use exface\Core\Events\DataSheet\OnUpdateDataEvent;
use exface\Core\DataTypes\NumberDataType;
use exface\Core\Exceptions\Behaviors\BehaviorRuntimeError;

/**
 * 
 * 
 * @author Andrej Kabachnik
 *
 */
class MoneyTransferBehavior extends AbstractBehavior
{    
    private $createdTransferRows = [];
    
    private $createdTransfers = null;
    
    private $processingTransferRows = false;
    
    public function register() : BehaviorInterface
    {
        $this->getWorkbench()->eventManager()->addListener(OnBeforeCreateDataEvent::getEventName(), [$this, 'handleBeforeSaveData']);
        $this->getWorkbench()->eventManager()->addListener(OnBeforeUpdateDataEvent::getEventName(), [$this, 'handleBeforeSaveData']);
        
        $this->getWorkbench()->eventManager()->addListener(OnCreateDataEvent::getEventName(), [$this, 'handleAfterSaveData']);
        $this->getWorkbench()->eventManager()->addListener(OnUpdateDataEvent::getEventName(), [$this, 'handleAfterSaveData']);
        
        $this->setRegistered(true);
        return $this;
    }
    
    public function handleBeforeSaveData(AbstractDataSheetEvent $event)
    {
        if ($this->processingTransferRows === true) {
            return;
        }
        
        $txSheet = $event->getDataSheet();
        
        if ($txSheet->getMetaObject()->is($this->getObject()) === false) {
            return;
        }
        
        $transferSheet = $this->createTransferSheet($txSheet);
        if ($transferSheet->isEmpty() === false) {
            try {
                $this->processingTransferRows = true;
                $transferSheet->dataSave($event->getTransaction());
                $this->processingTransferRows = false;
            } catch (\Throwable $e) {
                throw new RuntimeException('Cannot save transfer transactions: ' . $e->getMessage(), null, $e);
            }
            
            foreach ($transferSheet->getRows() as $transferRow) {
                $txSheet->setCellValue('transfer_transaction', $transferRow['_txRowIdx'], $transferRow['id']);
                if (! $transferRow['transfer_transaction']) {
                    $this->createdTransferRows[] = [
                        'id' => $transferRow['id']
                    ];
                }
            }
            
            $this->createdTransfers = $transferSheet;
        }
        
        foreach ($txSheet->getColumns() as $col) {
            if (StringDataType::startsWith($col->getName(), 'transfer_transaction__', false) === true) {
                $txSheet->getColumns()->remove($col);
            }
        }
        
        return;
    }
    
    public function handleAfterSaveData(AbstractDataSheetEvent $event)
    {        
        if ($this->processingTransferRows === true || empty($this->createdTransferRows) === true) {
            return;
        }
        
        $txSheet = $event->getDataSheet();
        
        if ($txSheet->getMetaObject()->is($this->getObject()) === false) {
            return;
        }
        
        $tfCol = $txSheet->getColumns()->get('transfer_transaction');
        foreach ($this->createdTransferRows as $rowIdx => $row) {
            $txRowNr = $tfCol->findRowByValue($row['id']);
            $txRow = $txSheet->getRow($txRowNr);
            $txId = $txRow[$txSheet->getUidColumnName()];
            $this->createdTransferRows[$rowIdx]['transfer_transaction'] = $txId;  
            if ($txRow['transfer_transaction'] != $this->createdTransferRows[$rowIdx]['id'] || $txRow['id'] != $this->createdTransferRows[$rowIdx]['transfer_transaction']) {
                throw new BehaviorRuntimeError($this->getObject(), 'Inconsistent data detected when creating transfer transaction for transaction "' . $txRow['date'] . ', ' . $txRow['amount_booked'] . ', ' . $txRow['note'] . '"!');
            }
        }
        
        $transferUpdateSheet = DataSheetFactory::createFromObject($txSheet->getMetaObject());
        $transferUpdateSheet->addRows($this->createdTransferRows);
        $this->processingTransferRows = true;
        $transferUpdateSheet->dataUpdate(false, $event->getTransaction());
        $this->processingTransferRows = false;
        
        $this->createdTransferRows = [];
        
        return;
    }
    
    protected function createTransferSheet(DataSheetInterface $transactionsSheet) : DataSheetInterface
    {
        $transfersSheet = DataSheetFactory::createFromObject($transactionsSheet->getMetaObject());
        $transfersSheet->getColumns()->addFromExpression('_txRowIdx', '_txRowIdx', true);
        foreach ($transactionsSheet->getRows() as $rowIdx => $txRow) {
            $isUpdate = $txRow['transfer_transaction'] > 0;
            $isCreate = $isUpdate === false && $txRow['transfer_transaction__account'] > 0;
        
            if ($isUpdate === false && $isCreate === false) {
                continue;
            }
            
            $row = [];
            foreach ($transactionsSheet->getColumns() as $col) {
                if (StringDataType::startsWith($col->getName(), 'transfer_transaction__', false) === true) {
                    $tfColName = StringDataType::substringAfter($col->getName(), 'transfer_transaction__');
                    $row[$tfColName] = $txRow[$col->getName()];
                }
            }
            
            $row['date'] = $txRow['date'];
            $row['transfer_transaction'] = $txRow['id'];
            // Use currency from original transaction if no currency defined for the transfer explicitly
            if (($row['currency_booked'] === null || $row['currency_booked'] === '') && $txRow['currency_booked'] !== null) {
                $row['currency_booked'] = $txRow['currency_booked'];
            }
            // Use (inverted) amount of the original transaction if not defined explicitly and currencies match 
            if (($row['amount_booked'] || $row['amount_booked'] === '') && $txRow['amount_booked'] !== null && $row['currency_booked'] === $txRow['currency_booked']) {
                $row['amount_booked'] = (-1) * NumberDataType::cast($txRow['amount_booked']);
            }
            if ($row['note'] === null && $txRow['note'] !== null) {
                $row['note'] = $txRow['note'];
            }
            if ($row['payee'] === null && $txRow['payee'] !== null) {
                $row['payee'] = $txRow['payee'];
            }
            if ($row['payee_original_name'] === null && $txRow['payee_original_name'] !== null) {
                $row['payee_original_name'] = $txRow['payee_original_name'];
            }
            
            if ($isCreate === true) {
                $row['transfer_autocreated'] = 1;
            } elseif ($isUpdate === true) {
                $row['id'] = $txRow['transfer_transaction'];
            }
            
            // Save the row index in a separate virtual column
            $row['_txRowIdx'] = $rowIdx;
            $transfersSheet->addRow($row);
        }   
        
        return $transfersSheet;
    }
}