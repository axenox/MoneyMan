<?php
namespace axenox\MoneyMan;

use exface\Core\Interfaces\InstallerInterface;
use exface\Core\CommonLogic\Model\App;
use exface\Core\Exceptions\Model\MetaObjectNotFoundError;
use exface\Core\CommonLogic\AppInstallers\MySqlDatabaseInstaller;

class MoneyManApp extends App
{
    public function getInstaller(InstallerInterface $injected_installer = null)
    {
        $installer = parent::getInstaller($injected_installer);
        
        try {
            $schema_installer = new MySqlDatabaseInstaller($this->getSelector());
            $schema_installer
            ->setDataSourceSelector('0x11e95e7b68dba8099677e4b318306b9a')
            ->setFoldersWithMigrations(['InitDB','Migrations'])
            ->setFoldersWithStaticSql('Views');
            $installer->addInstaller($schema_installer);
        } catch (MetaObjectNotFoundError $e) {
            $this->getWorkbench()->getLogger()->warning('Cannot init SqlSchemInstaller for app ' . $this->getAliasWithNamespace() . ': no model there yet!');
        }
        
        return $installer;
    }
}