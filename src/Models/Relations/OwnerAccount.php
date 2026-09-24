<?php

namespace Modules\Billing\Models\Relations;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Modules\Billing\Models\Customer;

/**
 * An owner's billing account, keyed by a string `owner_id`: owners' keys
 * differ in type (integer users, ULID workspaces), so one string column holds
 * them all. Bound values are sent as strings, since an integer compared with
 * a string column makes MySQL convert the column and skip its index.
 * `whereHas` compares the columns themselves: MySQL and SQLite convert
 * between types on their own, PostgreSQL needs the owner's key cast.
 *
 * @template TDeclaringModel of Model
 *
 * @extends MorphOne<Customer, TDeclaringModel>
 */
class OwnerAccount extends MorphOne
{
    public function getParentKey(): ?string
    {
        $key = parent::getParentKey();

        return $key === null ? null : (string) $key;
    }

    /**
     * @param  array<int, Model>  $models
     * @param  string|null  $key
     * @return array<int, string>
     */
    protected function getKeys(array $models, $key = null): array
    {
        return array_map(strval(...), parent::getKeys($models, $key));
    }

    /**
     * @param  Builder<Customer>  $query
     * @param  Builder<TDeclaringModel>  $parentQuery
     * @param  array<int, string>|string  $columns
     * @return Builder<Customer>
     */
    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, $columns = ['*']): Builder
    {
        if ($query->getModel()->getConnection()->getDriverName() !== 'pgsql') {
            return parent::getRelationExistenceQuery($query, $parentQuery, $columns);
        }

        $grammar = $query->getQuery()->getGrammar();

        return $query->select($columns)
            ->whereRaw('cast('.$grammar->wrap($this->getQualifiedParentKeyName()).' as text) = '.$grammar->wrap($this->getExistenceCompareKey()))
            ->where($query->qualifyColumn($this->getMorphType()), $this->morphClass);
    }

    /**
     * An integer key would be inlined unquoted (`whereIntegerInRaw`).
     *
     * @param  string  $key
     */
    protected function whereInMethod(Model $model, $key): string
    {
        return 'whereIn';
    }
}
