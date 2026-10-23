<?php

namespace Kompo\Searchbar\Components;

use Illuminate\Auth\Access\AuthorizationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class ConfirmMultiDeleteModal extends AbstractGroupedActionModal
{
    protected $_Title = 'filter.delete-confirmation';

    protected $groupedAction = 'delete';

    protected $deletable;
    protected $refused;

    public function created()
    {
        parent::created();

        // Refused: nothing is read (not even how many of the checked rows exist).
        $selected = $this->actionAllowed() ? $this->selectedModels() : collect();

        // The model's own delete rule (Kompo's convention, KomponentHandler::deleteRecord): a team with an active
        // registration, a note by someone else... Models without deletable() keep the model security only.
        [$this->deletable, $this->refused] = $selected
            ->partition(fn($model) => !method_exists($model, 'deletable') || $model->deletable())
            ->map->values()->all();
    }

    public function body()
    {
        if ($this->deletable->isEmpty()) {
            return _Rows(
                _Html('filter.nothing-to-delete')->class('mb-4'),
                _FlexCenter(_LinkOutlined('filter.cancel')->closeModal()),
            );
        }

        return _Rows(
            _Html(__('filter.with-values-delete-count', ['count' => $this->deletable->count()])),
            $this->refused->isEmpty() ? null : _Html(__('filter.with-values-not-deletable-count', ['count' => $this->refused->count()]))
                ->class('mt-2 text-sm text-gray-500'),
            _FlexCenter(
                _LinkOutlined('filter.cancel')->closeModal(),
                _Button('filter.delete')->selfPost('deleteEntities')->refresh($this->refreshTarget())->closeModal(),
            )->class('gap-4 mt-4'),
        );
    }

    /**
     * Only the deletable rows among the current results, one model at a time (model events and delete security run).
     * All or nothing: the model's security (per row and per team) can still refuse a row the gate let through, and a
     * half-deleted selection is worse than none (Event::delete() deletes its children first). It stops at the first
     * refusal, so fewer side effects outside the database (files, notifications) run for rows that come back.
     */
    public function deleteEntities()
    {
        // Kompo's gate (authorize()) already ran for a request; this keeps a direct call from host code closed too.
        if (!$this->actionAllowed()) {
            abort(403, __('filter.grouped-action-not-allowed'));
        }

        if ($this->deletable->isEmpty()) {
            return;
        }

        $connection = $this->searchableInstance->getConnection();
        $connection->beginTransaction();

        try {
            $refused = $this->deletable->first(fn($model) => !$this->tryDelete($model));
        } catch (\Throwable $e) {
            $connection->rollBack();

            throw $e;
        }

        if ($refused) {
            $connection->rollBack();

            abort(403, __('filter.nothing-deleted-not-allowed'));
        }

        $connection->commit();
    }

    /** False when the model's security refuses the row (a 403) or a deleting listener cancels it. */
    protected function tryDelete($model): bool
    {
        try {
            // null: already gone (deleted with an earlier row of the selection).
            return $model->delete() !== false;
        } catch (AuthorizationException $e) {
            return false;
        } catch (HttpExceptionInterface $e) {
            if ($e->getStatusCode() !== 403) {
                throw $e;
            }

            return false;
        }
    }
}
