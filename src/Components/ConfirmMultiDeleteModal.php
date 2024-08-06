<?php

namespace Kompo\Searchbar\Components;


class ConfirmMultiDeleteModal extends AbstractGroupedActionModal
{
    protected $_Title = 'filter.delete-confirmation';

    public function body()
    {
        return _FlexCenter(
            _LinkOutlined('filter.cancel')->closeModal(),
            _Button('filter.delete')->selfPost('deleteEntities')->refresh(AbstractResultsTable::ID)->closeModal(),
        )->class('gap-4');
    }

    public function deleteEntities()
    {
        $this->searchableInstance->destroy($this->ids);
    }
}