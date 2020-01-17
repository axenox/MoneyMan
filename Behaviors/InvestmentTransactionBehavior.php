<?php
namespace axenox\MoneyMan\Behaviors;

use exface\Core\CommonLogic\Model\Behaviors\AbstractBehavior;
use exface\Core\Interfaces\Model\BehaviorInterface;
use exface\Core\Events\DataSheet\OnBeforeCreateDataEvent;
use exface\Core\Events\DataSheet\OnBeforeUpdateDataEvent;
use exface\Core\Events\DataSheet\AbstractDataSheetEvent;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Exceptions\Behaviors\BehaviorRuntimeError;
use exface\Core\DataTypes\ComparatorDataType;
use exface\Core\DataTypes\SortingDirectionsDataType;
use exface\Core\DataTypes\DataSheetDataType;

/**
 * 
 * 
 * @author Andrej Kabachnik
 *
 */
class InvestmentTransactionBehavior extends AbstractBehavior
{    
    public function register() : BehaviorInterface
    {
        $this->getWorkbench()->eventManager()->addListener(OnBeforeCreateDataEvent::getEventName(), [$this, 'handleBeforeCreateData']);
        $this->getWorkbench()->eventManager()->addListener(OnBeforeUpdateDataEvent::getEventName(), [$this, 'handleBeforeUpdateData']);
        
        $this->setRegistered(true);
        return $this;
    }
    
    public function handleBeforeCreateData(AbstractDataSheetEvent $event)
    {
        $txSheet = $event->getDataSheet();
        
        if ($txSheet->getMetaObject()->is($this->getObject()) === false) {
            return;
        }
        
        foreach ($txSheet->getRows() as $rowNr => $row) {
            $shares = $row['shares'];
            if ($shares < 0) {
                $sellSheet = $this->createSellSheet($txSheet, $rowNr);
                if ($sellSheet->isEmpty() === true) {
                    throw new BehaviorRuntimeError($this->getObject(), 'Cannot create sell-transactions');
                }
                if (! $col = $txSheet->getColumns()->getByExpression('investment_transaction_gain[sell_transaction]')){
                    $col = $txSheet->getColumns()->addFromExpression('investment_transaction_gain[sell_transaction]');
                }
                $col->setValue($rowNr, $sellSheet);
            }
        }
        
        return;
    }
    
    public function handleBeforeUpdateData(AbstractDataSheetEvent $event)
    {
        $txSheet = $event->getDataSheet();
        
        if ($txSheet->getMetaObject()->is($this->getObject()) === false) {
            return;
        }
        
        throw new BehaviorRuntimeError($this->getObject(), 'Update-operations on investment transactions not supported yet!');
        
        return;
    }
    
    protected function createSellSheet(DataSheetInterface $transactionsSheet, int $rowNr) : DataSheetInterface
    {
        $toSell = (-1) * $transactionsSheet->getCellValue('shares', $rowNr);
        $sellTxId = $transactionsSheet->getCellValue($transactionsSheet->getMetaObject()->getUidAttribute(), $rowNr);
        $byTxSheet = $this->getBuyTransactions($transactionsSheet->getCellValue('investment', $rowNr));
        $sellSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.MoneyMan.investment_transaction_gain');
        
        foreach ($byTxSheet->getRows() as $buyRow) {
            if ($toSell <= 0) {
                break;
            }
            $sellable = $buyRow['shares_remaining'];
            $sell = $sellable > $toSell ? $toSell : $sellable;
            $sellSheet->addRow([
                'shares' => $sell,
                'buy_transaction' => $buyRow['id']
            ]);
            $toSell = $toSell - $sell;
        }
        
        return $sellSheet;
    }
    
    protected function getBuyTransactions(int $investmentId) : DataSheetInterface
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.MoneyMan.investment_transaction');
        $ds->getColumns()->addMultiple([
            $ds->getMetaObject()->getUidAttributeAlias(),
            'shares_remaining'
        ]);
        $ds->addFilterFromString('investment', $investmentId, ComparatorDataType::EQUALS);
        $ds->addFilterFromString('shares_remaining', 0, ComparatorDataType::GREATER_THAN);
        $ds->getSorters()->addFromString('transaction__date', SortingDirectionsDataType::ASC);
        $ds->dataRead();
        return $ds;
    }
}