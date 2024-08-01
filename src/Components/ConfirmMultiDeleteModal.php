<?php

namespace Kompo\Searchbar\Components;

use Kompo\Auth\Common\Modal;

class ConfirmMultiDeleteModal extends Modal
{
    use SearchKomponentUtils;

    protected $_Title = 'translate.filter.delete-confirmation';
    protected $noHeaderButtons = true;

    protected $ids;

    public function created()
    {
        $this->setSearchProps();

        $this->ids = explode(',', $this->prop('itemIds'));
    }

    public function body()
    {
        return _FlexCenter(
            _LinkOutlined('translate.filter.cancel')->closeModal(),
            _Button('filter.delete')->selfPost('deleteEntities')->refresh(AbstractResultsTable::ID)->closeModal(),
        )->class('gap-4');
    }

    public function deleteEntities()
    {
        $this->searchableInstance->destroy($this->ids);
    }
}