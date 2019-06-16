<?php
namespace axenox\MoneyMan\Behaviors;

use exface\Core\CommonLogic\Model\Behaviors\AbstractBehavior;
use exface\Core\Interfaces\Model\BehaviorInterface;
use exface\Core\Events\DataSheet\OnBeforeCreateDataEvent;
use exface\Core\Events\DataSheet\OnBeforeUpdateDataEvent;
use exface\Core\Events\DataSheet\AbstractDataSheetEvent;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Exceptions\RuntimeException;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Events\DataSheet\OnCreateDataEvent;
use exface\Core\Events\DataSheet\OnUpdateDataEvent;

/**
 * 
 * 
 * @author Andrej Kabachnik
 *
 */
class MoneyTransferBehavior extends AbstractBehavior
{    
    private $createdTransferRows = [];
    
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
            
            foreach ($transferSheet->getRows() as $rowIdx => $transferRow) {
                $txSheet->setCellValue('transfer_transaction', $rowIdx, $transferRow['id']);
                if (! $transferRow['transfer_transaction']) {
                    $this->createdTransferRows[] = [
                        'id' => $transferRow['id']
                    ];
                }
            }
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
            $txId = $txSheet->getUidColumn()->getCellValue($tfCol->findRowByValue($row['id']));
            $this->createdTransferRows[$rowIdx]['transfer_transaction'] = $txId;   
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
        $rows = [];
        foreach ($transactionsSheet->getRows() as $rowIdx => $txRow) {
            $isUpdate = $txRow['transfer_transaction'] > 0;
            $isCreate = $isUpdate === false && $txRow['transfer_transaction__account'] > 0;
        
            if ($isUpdate === false && $isCreate === false) {
                continue;
            }
            
            $row = [
                'date' => $txRow['date'],
                'account' => $txRow['transfer_transaction__account'],
                'amount_booked' => $txRow['transfer_transaction__amount_booked'] ? $txRow['transfer_transaction__amount_booked'] : (-1) * $txRow['amount_booked'],
                'currency_booked' => $txRow['transfer_transaction__currency_booked'] ? $txRow['transfer_transaction__currency_booked'] : $txRow['currency_booked'],
                'note' => $txRow['transfer_transaction__note'] ? $txRow['transfer_transaction__note'] : $txRow['note'],
                'payee' => $txRow['payee'],
                'payee_original_name' => $txRow['payee_original_name'],
                'transfer_transaction' => $txRow['id']
            ];
            
            if ($isCreate === true) {
                $row['transfer_autocreated'] = 1;
            } elseif ($isUpdate === true) {
                $row['id'] = $txRow['transfer_transaction'];
            }
            
            $rows[$rowIdx] = $row;
        }   
        
        $transfersSheet = DataSheetFactory::createFromObject($transactionsSheet->getMetaObject());
        $transfersSheet->addRows($rows);
        return $transfersSheet;
    }
}