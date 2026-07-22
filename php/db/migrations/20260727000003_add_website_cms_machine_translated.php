<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * CP4: a `machine_translated` flag on cms_block for the save-time DeepL sync.
 * 1 = auto-generated from its counterpart language (may be overwritten by a
 * fresh translation); 0 = manually authored, never touched by the sync.
 * Module-prefixed class name (in-process auto-migrator loads every module's
 * migrations into one process).
 */
final class AddWebsiteCmsMachineTranslated extends AbstractMigration
{
    public function change(): void
    {
        $this->table('cms_block')
            ->addColumn('machine_translated', 'boolean', ['default' => false, 'after' => 'value_json'])
            ->update();
    }
}
