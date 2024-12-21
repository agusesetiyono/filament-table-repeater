<?php

namespace Awcodes\TableRepeater\Components;

use Closure;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Contracts\HasForms;
use Illuminate\Database\Eloquent\Model;


class TableRepeater extends Repeater
{
    use Concerns\CanBeStreamlined;
    use Concerns\HasBreakPoints;
    use Concerns\HasEmptyLabel;
    use Concerns\HasExtraActions;
    use Concerns\HasHeader;

    protected bool | Closure | null $showLabels = null;
    protected bool | Closure | null $minimal = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->registerActions([
            fn(TableRepeater $component): array => $component->getExtraActions()
        ]);
    }

    public function getChildComponents(): array
    {
        $components = parent::getChildComponents();

        if ($this->shouldShowLabels()) {
            return $components;
        }

        foreach ($components as $component) {
            if (
                method_exists($component, 'hiddenLabel') &&
                ! $component instanceof Placeholder
            ) {
                $component->hiddenLabel();
            }
        }

        return $components;
    }

    public function showLabels(bool | Closure | null $condition = true): static
    {
        $this->showLabels = $condition;

        return $this;
    }

    public function shouldShowLabels(): bool
    {
        return $this->evaluate($this->showLabels) ?? false;
    }

    public function minimal(bool | Closure | null $condition = true): static
    {
        $this->minimal = $condition;

        return $this;
    }

    public function isMinimal(): bool
    {
        return $this->evaluate($this->minimal) ?? false;
    }

    public function getView(): string
    {
        return 'table-repeater::components.table-repeater';
    }


    public function relationship(string | Closure | null $name = null, ?Closure $modifyQueryUsing = null): static
    {
        $this->relationship = $name ?? $this->getName();
        $this->modifyRelationshipQueryUsing = $modifyQueryUsing;

        // don't use relationship on form filled
        $this->afterStateHydrated(function (Repeater $component) {
            if (! is_array($component->hydratedDefaultState)) {
                return;
            }

            // $component->mergeHydratedDefaultStateWithChildComponentContainerState();
        });

        $this->loadStateFromRelationshipsUsing(static function (Repeater $component) {
            $component->clearCachedExistingRecords();

            $component->fillFromRelationship();
        });

        $this->saveRelationshipsUsing(static function (Repeater $component, HasForms $livewire, ?array $state) {
            if (! is_array($state)) {
                $state = [];
            }

            $relationship = $component->getRelationship();

            $existingRecords = $component->getCachedExistingRecords();

            $recordsToDelete = [];

            foreach ($existingRecords->pluck($relationship->getRelated()->getKeyName()) as $keyToCheckForDeletion) {
                if (array_key_exists("record-{$keyToCheckForDeletion}", $state)) {
                    continue;
                }

                $recordsToDelete[] = $keyToCheckForDeletion;
                $existingRecords->forget("record-{$keyToCheckForDeletion}");
            }

            $relationship
                ->whereKey($recordsToDelete)
                ->get()
                ->each(static fn(Model $record) => $record->delete());

            $childComponentContainers = $component->getChildComponentContainers(
                withHidden: $component->shouldSaveRelationshipsWhenHidden(),
            );

            $itemOrder = 1;
            $orderColumn = $component->getOrderColumn();

            $translatableContentDriver = $livewire->makeFilamentTranslatableContentDriver();

            foreach ($childComponentContainers as $itemKey => $item) {
                $itemData = $item->getState(shouldCallHooksBefore: false);

                if ($orderColumn) {
                    $itemData[$orderColumn] = $itemOrder;

                    $itemOrder++;
                }

                if ($record = ($existingRecords[$itemKey] ?? null)) {
                    $itemData = $component->mutateRelationshipDataBeforeSave($itemData, record: $record);

                    if ($itemData === null) {
                        continue;
                    }

                    $translatableContentDriver ?
                        $translatableContentDriver->updateRecord($record, $itemData) :
                        $record->fill($itemData)->save();

                    continue;
                }

                $relatedModel = $component->getRelatedModel();

                $itemData = $component->mutateRelationshipDataBeforeCreate($itemData);

                if ($itemData === null) {
                    continue;
                }

                if ($translatableContentDriver) {
                    $record = $translatableContentDriver->makeRecord($relatedModel, $itemData);
                } else {
                    $record = new $relatedModel;
                    $record->fill($itemData);
                }

                $record = $relationship->save($record);
                $item->model($record)->saveRelationships();
                $existingRecords->push($record);
            }

            $component->getRecord()->setRelation($component->getRelationshipName(), $existingRecords);
        });

        $this->dehydrated(false);

        $this->disableItemMovement();

        return $this;
    }
}
