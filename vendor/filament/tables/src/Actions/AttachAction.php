<?php

namespace Filament\Tables\Actions;

use Closure;
use Filament\Actions\Concerns\CanCustomizeProcess;
use Filament\Forms\Components\Select;
use Filament\Forms\Form;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class AttachAction extends Action
{
    use CanCustomizeProcess;

    protected ?Closure $modifyRecordSelectUsing = null;

    protected ?Closure $modifyRecordSelectOptionsQueryUsing = null;

    protected bool | Closure $canAttachAnother = true;

    protected bool | Closure $isRecordSelectPreloaded = false;

    /**
     * @var array<string> | Closure | null
     */
    protected array | Closure | null $recordSelectSearchColumns = null;

    protected bool | Closure $isSearchForcedCaseInsensitive = false;

    public static function getDefaultName(): ?string
    {
        return 'attach';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('filament-actions::attach.single.label'));

        $this->modalHeading(fn (): string => __('filament-actions::attach.single.modal.heading', ['label' => $this->getModelLabel()]));

        $this->modalSubmitActionLabel(__('filament-actions::attach.single.modal.actions.attach.label'));

        $this->modalWidth('lg');

        $this->extraModalFooterActions(function (): array {
            return $this->canAttachAnother() ? [
                $this->makeModalSubmitAction('attachAnother', ['another' => true])
                    ->label(__('filament-actions::attach.single.modal.actions.attach_another.label')),
            ] : [];
        });

        $this->successNotificationTitle(__('filament-actions::attach.single.notifications.attached.title'));

        $this->color('gray');

        $this->form(fn (): array => [$this->getRecordSelect()]);

        $this->action(function (array $arguments, array $data, Form $form, Table $table): void {
            /** @var BelongsToMany $relationship */
            $relationship = Relation::noConstraints(fn () => $table->getRelationship());

            $relationshipQuery = $relationship->getQuery();

            // By default, `BelongsToMany` relationships use an inner join to scope the results to only
            // those that are attached in the pivot table. We need to change this to a left join so
            // that we can still get results when the relationship is not attached to the record.
            if ($relationship instanceof BelongsToMany) {
                /** @var ?JoinClause $firstRelationshipJoinClause */
                $firstRelationshipJoinClause = $relationshipQuery->getQuery()->joins[0] ?? null;

                if ($firstRelationshipJoinClause) {
                    $firstRelationshipJoinClause->type = 'left';
                }

                $relationshipQuery
                    ->distinct() // Ensure that results are unique when fetching records to attach.
                    ->select($relationshipQuery->getModel()->getTable() . '.*');
            }

            $isMultiple = is_array($data['recordId']);

            $record = $relationshipQuery
                ->{$isMultiple ? 'whereIn' : 'where'}($relationship->getQualifiedRelatedKeyName(), $data['recordId'])
                ->{$isMultiple ? 'get' : 'first'}();

            if ($record instanceof Model) {
                $this->record($record);
            }

            $this->process(function () use ($data, $record, $relationship) {
                $relationship->attach(
                    $record,
                    Arr::only($data, $relationship->getPivotColumns()),
                );
            }, [
                'relationship' => $relationship,
            ]);

            if ($arguments['another'] ?? false) {
                $this->callAfter();
                $this->sendSuccessNotification();

                $this->record(null);

                $form->fill();

                $this->halt();

                return;
            }

            $this->success();
        });
    }

    public function recordSelect(?Closure $callback): static
    {
        $this->modifyRecordSelectUsing = $callback;

        return $this;
    }

    public function recordSelectOptionsQuery(?Closure $callback): static
    {
        $this->modifyRecordSelectOptionsQueryUsing = $callback;

        return $this;
    }

    public function attachAnother(bool | Closure $condition = true): static
    {
        $this->canAttachAnother = $condition;

        return $this;
    }

    /**
     * @deprecated Use `attachAnother()` instead.
     */
    public function disableAttachAnother(bool | Closure $condition = true): static
    {
        $this->attachAnother(fn (AttachAction $action): bool => ! $action->evaluate($condition));

        return $this;
    }

    public function preloadRecordSelect(bool | Closure $condition = true): static
    {
        $this->isRecordSelectPreloaded = $condition;

        return $this;
    }

    public function canAttachAnother(): bool
    {
        return (bool) $this->evaluate($this->canAttachAnother);
    }

    public function isRecordSelectPreloaded(): bool
    {
        return (bool) $this->evaluate($this->isRecordSelectPreloaded);
    }

    /**
     * @param  array<string> | Closure | null  $columns
     */
    public function recordSelectSearchColumns(array | Closure | null $columns): static
    {
        $this->recordSelectSearchColumns = $columns;

        return $this;
    }

    /**
     * @return array<string> | null
     */
    public function getRecordSelectSearchColumns(): ?array
    {
        return $this->evaluate($this->recordSelectSearchColumns);
    }

    public function getRecordSelect(): Select
    {
        $table = $this->getTable();

        $getOptions = function (?string $search = null, ?array $searchColumns = []) use ($table): array {
            /** @var BelongsToMany $relationship */
            $relationship = Relation::noConstraints(fn () => $table->getRelationship());

            $relationshipQuery = $relationship->getQuery();

            // By default, `BelongsToMany` relationships use an inner join to scope the results to only
            // those that are attached in the pivot table. We need to change this to a left join so
            // that we can still get results when the relationship is not attached to the record.
            if ($relationship instanceof BelongsToMany) {
                /** @var ?JoinClause $firstRelationshipJoinClause */
                $firstRelationshipJoinClause = $relationshipQuery->getQuery()->joins[0] ?? null;

                if ($firstRelationshipJoinClause) {
                    $firstRelationshipJoinClause->type = 'left';
                }

                $relationshipQuery
                    ->distinct() // Ensure that results are unique when fetching options.
                    ->select($relationshipQuery->getModel()->getTable() . '.*');
            }

            if ($this->modifyRecordSelectOptionsQueryUsing) {
                $relationshipQuery = $this->evaluate($this->modifyRecordSelectOptionsQueryUsing, [
                    'query' => $relationshipQuery,
                ]) ?? $relationshipQuery;
            }

            $titleAttribute = $this->getRecordTitleAttribute();
            $titleAttribute = filled($titleAttribute) ? $relationshipQuery->qualifyColumn($titleAttribute) : null;

            if (empty($relationshipQuery->getQuery()->orders) && filled($titleAttribute)) {
                $relationshipQuery->orderBy($titleAttribute);
            }

            if (filled($search) && ($searchColumns || filled($titleAttribute))) {
                $search = Str::lower($search);

                $searchColumns ??= [$titleAttribute];
                $isFirst = true;
                $isForcedCaseInsensitive = $this->isSearchForcedCaseInsensitive();

                $relationshipQuery->where(function (Builder $query) use ($isFirst, $isForcedCaseInsensitive, $searchColumns, $search): Builder {
                    foreach ($searchColumns as $searchColumn) {
                        $caseAwareSearchColumn = $isForcedCaseInsensitive ?
                            new Expression("lower({$searchColumn})") :
                            $searchColumn;

                        $whereClause = $isFirst ? 'where' : 'orWhere';

                        $query->{$whereClause}(
                            $caseAwareSearchColumn,
                            'like',
                            "%{$search}%",
                        );

                        $isFirst = false;
                    }

                    return $query;
                });
            }

            $relationshipQuery
                ->when(
                    ! $table->allowsDuplicates(),
                    fn (Builder $query): Builder => $query->whereDoesntHave(
                        $table->getInverseRelationship(),
                        fn (Builder $query): Builder => $query->where(
                            $table->getRelationship()->getParent()->getQualifiedKeyName(),
                            $table->getRelationship()->getParent()->getKey(),
                        ),
                    ),
                );

            if (filled($titleAttribute)) {
                return $relationshipQuery
                    ->pluck($titleAttribute, $relationship->getQualifiedRelatedKeyName())
                    ->all();
            }

            $relatedKeyName = $relationship->getRelatedKeyName();

            return $relationshipQuery
                ->get()
                ->mapWithKeys(fn (Model $record): array => [$record->{$relatedKeyName} => $this->getRecordTitle($record)])
                ->all();
        };

        $select = Select::make('recordId')
            ->label(__('filament-actions::attach.single.modal.fields.record_id.label'))
            ->required()
            ->searchable($this->getRecordSelectSearchColumns() ?? true)
            ->getSearchResultsUsing(static fn (Select $component, string $search): array => $getOptions(search: $search, searchColumns: $component->getSearchColumns()))
            ->getOptionLabelUsing(function ($value) use ($table): string {
                $relationship = Relation::noConstraints(fn () => $table->getRelationship());

                $relationshipQuery = $relationship->getQuery();

                // By default, `BelongsToMany` relationships use an inner join to scope the results to only
                // those that are attached in the pivot table. We need to change this to a left join so
                // that we can still get results when the relationship is not attached to the record.
                if ($relationship instanceof BelongsToMany) {
                    /** @var ?JoinClause $firstRelationshipJoinClause */
                    $firstRelationshipJoinClause = $relationshipQuery->getQuery()->joins[0] ?? null;

                    if ($firstRelationshipJoinClause) {
                        $firstRelationshipJoinClause->type = 'left';
                    }

                    $relationshipQuery
                        ->distinct() // Ensure that results are unique when fetching options.
                        ->select($relationshipQuery->getModel()->getTable() . '.*');
                }

                return $this->getRecordTitle($relationshipQuery->find($value));
            })
            ->options(fn (): array => $this->isRecordSelectPreloaded() ? $getOptions() : [])
            ->hiddenLabel();

        if ($this->modifyRecordSelectUsing) {
            $select = $this->evaluate($this->modifyRecordSelectUsing, [
                'select' => $select,
            ]);
        }

        return $select;
    }

    public function forceSearchCaseInsensitive(bool | Closure $condition = true): static
    {
        $this->isSearchForcedCaseInsensitive = $condition;

        return $this;
    }

    public function isSearchForcedCaseInsensitive(): bool
    {
        return (bool) $this->evaluate($this->isSearchForcedCaseInsensitive);
    }
}
