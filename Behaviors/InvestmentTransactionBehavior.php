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
use exface\Core\DataTypes\NumberDataType;

/**
 * 
 * 
 * @author Andrej Kabachnik
 *
 */
class InvestmentTransactionBehavior extends AbstractBehavior
{    
    private $accountData = null;
    
    public function register() : BehaviorInterface
    {
        $this->getWorkbench()->eventManager()->addListener(OnBeforeCreateDataEvent::getEventName(), [$this, 'handleBeforeCreateData']);
        $this->getWorkbench()->eventManager()->addListener(OnBeforeUpdateDataEvent::getEventName(), [$this, 'handleBeforeUpdateData']);
        
        $this->setRegistered(true);
        return $this;
    }
    
    public function handleBeforeCreateData(OnBeforeCreateDataEvent $event)
    {
        $eventData = $event->getDataSheet();
        
        if ($eventData->getMetaObject()->is($this->getObject()) === false) {
            return;
        }
        
        // Check if the data originates from this behavior already and thus does not need
        // further processing - see end of this method, where this special column is set.
        if ($eventData->getCellValue('_bypass_InvestmentTransactionBehavior', 0) === true) {
            return;
        }
        
        $transaction = $event->getTransaction();
        
        $eventData->getSorters()->addFromString('transaction__date', SortingDirectionsDataType::ASC);
        $eventData->sort();
        
        $accountTransferData = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.MoneyMan.transaction');
        $accountTransferData->getColumns()->addFromUidAttribute();
        
        $investmentTxData = $eventData->copy();
        foreach ($investmentTxData->getColumns() as $col) {
            if ($col->getAttribute()->getRelationPath()->isEmpty() === false) {
                $investmentTxData->getColumns()->remove($col);
            }
        }
        
        foreach ($eventData->getRows() as $rowNr => $row) {
            $shares = NumberDataType::cast($row['shares']);
            $price = NumberDataType::cast($row['price']);
            $isSell = ($shares < 0);
            
            $accountTransferData->removeRows();
            $investmentTxData->removeRows();
            
            foreach ($investmentTxData->getColumns() as $col) {
                if ($eventCol = $eventData->getColumns()->get($col->getName())) {
                    $col->setValue(0, $eventCol->getCellValue($rowNr));
                }
            }
            
            // If it is a sell-transaction, we need to add the buy-sell mapping
            if ($isSell === true) {
                $buyData = $this->getBuyTransactions($row['investment']);
                $buySellData = $this->createBuySellSheet($buyData, (-1)*$shares);
                if ($buySellData->isEmpty() === true) {
                    throw new BehaviorRuntimeError($this->getObject(), 'Cannot create sell-transactions');
                }
                if (! $col = $investmentTxData->getColumns()->getByExpression('investment_transaction_sold[sell_transaction]')){
                    $col = $investmentTxData->getColumns()->addFromExpression('investment_transaction_sold[sell_transaction]');
                }
                $col->setValue(0, $buySellData);
                $totalGain = $this->getGainTotal($buyData, (-1)*$shares, $price);
            } else {
                $buySellData = null;
                $totalGain = null;
            }
            
            // Create the transfer-transactions between accounts
            $accountTransferData->removeRows();
            $accountTransferData->addRow([
                'date' => $row['transaction__date'],
                'account' => $this->getAccountId($eventData, $rowNr),
                'transfer_transaction__account' => $this->getCashAccountId($eventData, $rowNr),
                // The amount booked in the investment transaction is the value of the shares bought. This
                // makes sure, there is no value accumulated in the account! This means 
                // - for buy-transactions it is price*qty
                // - for sell-transactions it is price*qty of the corresponding buy-transactions or
                // sell-value - gain (since sell-value is actually negative, it is sell-value + gain
                // really).
                'amount_booked' => $this->getAmountShareValue($eventData, $rowNr) + ($totalGain ?? 0),
                // The amount transferred to/from the cash-account is whatever is really being payed -
                // including fees, etc.
                'transfer_transaction__amount_booked' => (-1)*$this->getAmountPayed($eventData, $rowNr),
                'currency_booked' => $row['currency'],
                'transaction_category' => $this->createCategoriesSheet($eventData, $rowNr, $totalGain),
                'status' => 'C'
            ]);
            $accountTransferData->dataCreate(false, $transaction);
            
            // Add transaction id to investment transaction
            $investmentTxData->setCellValue('transaction', 0, $accountTransferData->getUidColumn()->getCellValue(0));
            
            // Create the investment transaction
            // Since this behavior will be triggered again, add a special column value to bypass
            // the behavior (see beginning of this method for handling this column).
            $investmentTxData->setCellValue('_bypass_InvestmentTransactionBehavior', 0, true);
            $investmentTxData->dataCreate(false, $transaction);
        }
        
        $event->preventCreate();
        
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
    
    protected function getAmountShareValue(DataSheetInterface $importData, int $rowNr)
    {
        $shares = NumberDataType::cast($importData->getCellValue('shares', $rowNr));
        $price = NumberDataType::cast($importData->getCellValue('price', $rowNr));
        
        if ($shares === null || $price === null) {
            throw new BehaviorRuntimeError($this->getObject(), 'Missing input values: number of shares, price per share and total ar all required!');
        }
        
        return $shares * $price;
    }
    
    protected function getAmountPayed(DataSheetInterface $importData, int $rowNr) : float
    {
        $total = NumberDataType::cast($importData->getCellValue('transaction__amount_booked', $rowNr));
        
        if ($total === null) {
            throw new BehaviorRuntimeError($this->getObject(), 'Missing input for total payed value!');
        }
        
        return $total;
    }
    
    protected function createCategoriesSheet(DataSheetInterface $importData, int $rowNr, float $totalGain = null) : DataSheetInterface
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.MoneyMan.transaction_category');
        
        $fee = $this->getAmountPayed($importData, $rowNr) - $this->getAmountShareValue($importData, $rowNr);
        
        $ds->addRow([
            'category' => $this->getFeeCategoryId($importData, $rowNr),
            'amount' => (-1)*$fee,
            'transaction' => $importData->getCellValue('transaction', $rowNr)
        ]);
        
        if ($totalGain !== null) {
            $ds->addRow([
                'category' => $this->getGainCategoryId($importData, $rowNr),
                'amount' => $totalGain,
                'transaction' => $importData->getCellValue('transaction', $rowNr)
            ]);
        }
        
        return $ds;
    }
    
    protected function getGainTotal(DataSheetInterface $buyData, int $sellShares, float $sellPrice) : float
    {
        $gainTotal = 0;
        foreach ($buyData->getRows() as $buyRow) {
            $sellSharesFromRow = max($buyRow['shares'], $sellShares);
            $gainTotal += $sellPrice * $sellSharesFromRow - $buyRow['price'] * $sellSharesFromRow;
        }
        return $gainTotal;
    }
    
    protected function getInvestmentGainData(DataSheetInterface $importData, int $rowNr) : DataSheetInterface
    {
        $shares = $importData->getCellValue('shares', $rowNr);
        if ($shares > 0) {
            return null;
        }
        
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.MoneyMan.investment_transaction_sold');
        
        $fee = $this->getAmountPayed($importData, $rowNr) - $this->getAmountShareValue($importData, $rowNr);
        
        $ds->addRow([
            'category' => $this->getFeeCategoryId($importData, $rowNr),
            'amount' => (-1)*$fee,
            'transaction' => $importData->getCellValue('transaction', $rowNr)
        ]);
        
        return $ds;
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
    
    protected function createBuySellSheet(DataSheetInterface $buyData, int $sellShares) : DataSheetInterface
    {
        $sellSheet = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.MoneyMan.investment_transaction_sold');
        
        foreach ($buyData->getRows() as $buyRow) {
            if ($sellShares <= 0) {
                break;
            }
            $sellable = $buyRow['shares_remaining'];
            $sell = $sellable > $sellShares ? $sellShares : $sellable;
            $sellSheet->addRow([
                'shares' => $sell,
                'buy_transaction' => $buyRow['id']
            ]);
            $sellShares = $sellShares - $sell;
        }
        
        return $sellSheet;
    }
    
    protected function getBuyTransactions(int $investmentId) : DataSheetInterface
    {
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'axenox.MoneyMan.investment_transaction');
        $ds->getColumns()->addMultiple([
            $ds->getMetaObject()->getUidAttributeAlias(),
            'shares_remaining',
            'price',
            'currency'
        ]);
        $ds->addFilterFromString('investment', $investmentId, ComparatorDataType::EQUALS);
        $ds->addFilterFromString('shares_remaining', 0, ComparatorDataType::GREATER_THAN);
        $ds->getSorters()->addFromString('transaction__date', SortingDirectionsDataType::ASC);
        $ds->dataRead();
        return $ds;
    }
}