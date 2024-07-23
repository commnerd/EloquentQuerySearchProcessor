<?php

namespace Commnerd\QuerySearchProcessor;

use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Relations\{Relation, BelongsTo};
use Illuminate\Http\Request;
use Illuminate\Support\Str;

trait QuerySearchProcessor {
    private Builder $instanceBuilder;
    private string $classQueryNamespace;
    private array $generalQueryParams = [];
    private array $generalQueryMappings = [];

    private array $joins = [];
    private array $namespacedQueryParams = [];
    private array $toolQueryParams = [];
    private array $tableNameIterationTracker = [];
    private string $baseTable;
    private Request $request;

    public static function processQuery(Request $request): Builder
    {
        $instance = new static;
        if(!$instance instanceof Model) {
            throw new NonModelException('This trait can only be used on Eloquent Models');
        }
        return $instance->entrypoint($request);
    }

    private function addGeneralQueryMapping(Model $model, string $param) {
        if(method_exists($model, 'getSearchColumns') && in_array($param, $model->getSearchColumns())) {
            array_push($this->generalQueryMappings[$param], $model);
        }
    }

    private function addJoin(Model $model, Relation $relationship) {
        $leftTable = $this->getIteratedTableName($model->getTable(), retrievePrev: true);
        $leftKey = $relationship->getForeignKeyName();
        $rightClass = $relationship->getRelated();
        $rightTable = $rightClass->getTable();
        $rightIteratedTableName = $this->getIteratedTableName($rightTable, true);
        $rightKey = $rightClass->getKeyName();
        if($relationship instanceof BelongsTo) {
            $this->instanceBuilder->leftJoin($rightTable.' as '.$rightIteratedTableName,
                $leftTable.'.'.$leftKey,
                "=",
                $rightIteratedTableName.'.'.$rightKey
            );
        }
        else {
            $this->instanceBuilder->rightJoin($rightTable.' as '.$rightIteratedTableName,
                $leftTable.'.'.$leftKey,
                "=",
                $rightIteratedTableName.'.'.$rightKey
            );
        }
    }

    private function addJoins(): void
    {
        $relationshipHierarchies = explode(',', $this->toolQueryParams['_with']);
        foreach($relationshipHierarchies as $hierarchy) {
            $this->joinHierarchy($hierarchy);
        }
    }

    private function addWhere(Builder $builder, Model $model, string $variable, $value, $context = 'and'): void
    {
        if(is_string($value) and str_contains($value, '~')) {
            $qry = [$variable, 'like', str_replace('~', '%', $value)];
        }
        else {
            $qry = [$variable, $this->toScalar($value)];
        }
        if($context === 'and') {
            $builder->where(...$qry);
        }
        else {
            $builder->orWhere(...$qry);
        }
    }

    private function buildGeneralQueryMappings(): void
    {
        $model = new static;

        foreach(array_keys($this->generalQueryParams) as $param) {
            $this->generalQueryMappings[$param] = [];
            $this->addGeneralQueryMapping($model, $param);
        }

        if(isset($this->toolQueryParams['_with'])) {
            foreach(explode(',', $this->toolQueryParams['_with']) as $relationshipChain) {
                $model = new static;
                foreach(explode('.', $relationshipChain) as $relationship) {
                    if(method_exists($model, $relationship)) {
                        $class = $model->{$relationship}()->getRelated();
                        $model = new $class();
                        foreach(array_keys($this->generalQueryParams) as $param) {
                            $this->addGeneralQueryMapping($model, $param);
                        }
                    }
                }
            }
        }
    }

    private function disambiguateFirstOrderFields() {
        $selects = [];
        foreach($this->getSearchColumns() as $column) {
            $selects[] = $this->getTable().'.'.$column.' as '.$this->getTable().'_'.$column;
        }
        $selects[] = $this->getTable().'.*';
        $this->instanceBuilder->select($selects);
    }

    /**
     * Initialize the search building process and kick it off
     *
     * @param Request $request
     * @return Builder
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    private function entrypoint(Request $request): Builder
    {
        $this->request = $request;
        $this->instanceBuilder = $this->newQuery();
        $this->processSearch();
        return $this->instanceBuilder;
    }

    private function getSearchColumns()
    {
        return $this->getFillable();
    }

    private function getTableFromClass(Model $class): string
    {
        $instance = new $class();
        $name = $instance->getTable();
        if(isset($this->joins[$name])) {
            $name .= '_'.$this->joins[$name]++;
        }
        return $name;
    }

    private function handleToolQueryParameters(): void
    {
        $this->buildGeneralQueryMappings();
        if(isset($this->toolQueryParams['_with'])) {
            $this->instanceBuilder->with(explode(',',$this->toolQueryParams['_with']));
            if(sizeof($this->generalQueryParams) > 0) {
                $this->disambiguateFirstOrderFields();
                $this->addJoins();
            }
        }
        if(isset($this->toolQueryParams['_orderBy']) && !is_null($mapping = $this->mapKeyToHost($this->toolQueryParams['_orderBy']))) {
            list($model, $vars) = $mapping;
            $direction = strtolower($this->toolQueryParams['_order'] ?? 'asc') == 'desc' ? 'desc' : 'asc';
            $varArray = explode(',', $vars);
            foreach($varArray as $var) {
                $this->instanceBuilder->orderBy($this->getIteratedTableName($model->getTable(), retrievePrev: true).'.'.$var, $direction);
            }
            if(isset($this->toolQueryParams['_orderRandom'])) {
                throw new ConflictingParametersException('Cannot use both _orderBy and _orderRandom');
            }
        }
        if(isset($this->toolQueryParams['_orderRandom'])) {
            $this->inRandomOrder();
        }
    }

    private function joinHierarchy(string $hierarchy): void
    {
        $relationLadder = explode('.', $hierarchy);
        $instance = $this;
        $this->joins[] = $this->getTable();
        while($label = array_shift($relationLadder)) {
            $relationship = $instance->{$label}();
            $this->addJoin($instance, $relationship);
            $class = $relationship->getRelated();
            $instance = new $class();
        }
    }

    /**
     * Take a key and/or model and map key to model/key
     *
     * @param string $key
     * @param Model|null $model
     * @return array|null
     */
    private function mapKeyToHost(string $key, Model $model = null): array | null
    {
        $class = get_called_class();
        if($model != null) {
            $class = get_class($model);
        }
        $instance = new $class();

        if(in_array($key, $instance->getSearchColumns())) {
            return array($instance, $key);
        }

        if(isset($this->toolQueryParams['_with'])) {
            if(method_exists($instance, $key) && $instance->{$key}() instanceof Relationship) {
                $class = $instance->{$key}()->getRelated();
                return $this->mapKeyToHost($key, new $class());
            }

            return $this->mapKeyToRelated($key, $instance);
        }

        return null;
    }

    private function mapKeyToRelated(string $key, Model $model = null): array | null
    {
        $class = get_called_class();
        if($model != null) {
            $class = get_class($model);
        }
        $instance = new $class();

        $keyParts = explode('_', $key);
        $targetKey = array_shift($keyParts);
        while(sizeof($keyParts) > 0) {
            if(method_exists($instance, $targetKey) && $instance->{$targetKey}() instanceof Relationship) {
                return array($instance, $targetKey);
            }
            $targetKey .= '_'.array_shift($keyParts);
        }
        return null;
    }

    /**
     * Categorize search terms
     *
     * @return void
     */
    private function processQueryParams(): void
    {
        $this->setClassQueryNamespace();
        $this->triageQueryParams();
        $this->handleToolQueryParameters();
    }

    /**
     * Run the search process
     *
     * @return void
     */
    private function processSearch(): void
    {
        $this->processQueryParams();
        $this->queryNamespacedSearchTerms();
        $this->queryGeneralSearchTerms();
    }

    private function queryGeneralSearchTerms(): void
    {
        if(isset($this->toolQueryParams['_with'])) {
            $this->instanceBuilder->where(function ($builder) {
                foreach($this->generalQueryMappings as $var => $modelArray) {
                    foreach($modelArray as $model) {
                        if(isset($this->tableNameIterationTracker[$model->getTable()])) {
                            foreach(range(1, $this->tableNameIterationTracker[$model->getTable()] - 1) as $index) {
                                $this->addWhere($builder, $model, $model->getTable()."_$index" . '.' . $var, $this->generalQueryParams[$var], 'or');
                            }
                        }
                        if(!isset($this->tableNameIterationTracker[$model->getTable()])) {
                            $this->addWhere($builder, $model, $model->getTable() . '.' . $var, $this->generalQueryParams[$var], 'or');
                        }
                    }
                }
            });
        }
        else {
            foreach($this->generalQueryMappings as $var => $modelArray) {
                $model = new static;
                $this->addWhere($this->instanceBuilder, $model, $var, $this->generalQueryParams[$var]);
            }
        }
    }

    private function queryNamespacedSearchTerms(): void
    {
        $class = get_called_class();
        $instance = new $class();
        foreach($this->namespacedQueryParams as $key => $value) {
            if(!is_null($mapping = $this->mapKeyToHost(substr($key, strlen($this->classQueryNamespace.'_')), $instance))) {
                list($model, $var) = $mapping;
                $this->addWhere($this->instanceBuilder, $model, $var, $value);
            }
        }
    }

    /**
     * Set the query namespace for the calling class
     * @return void
     */
    private function setClassQueryNamespace(): void
    {
        $parts = explode('\\', get_called_class());
        $class = Str::snake(array_pop($parts));
        $this->classQueryNamespace = $class;
    }

    /**
     * Translate string values to scalars
     *
     * @param $val
     * @return bool|float|int|mixed
     */
    private function toScalar($val)
    {
        if($val === 'true') return true;
        if($val === 'false') return false;
        if($val === 'undefined') return null;
        if($val === 'null') return null;

        return $val;
    }

    /**
     * Iterate over query params and triage to appropriate slots
     *
     * @return void
     */
    private function triageQueryParams()
    {
        foreach($this->request->all() as $key => $val) {
            if(substr($key, 0, 1) == '_') {
                $this->toolQueryParams[$key] = $val;
            }
            elseif(preg_match("/^".$this->classQueryNamespace."_/", $key)) {
                $this->namespacedQueryParams[$key] = $val;
            }
            else {
                $this->generalQueryParams[$key] = $val;
            }
        }
    }

    private function getIteratedTableName(string $table, bool $incrementAfter = false, $retrievePrev = false): string
    {
        if($table === $this->getTable()) {
            return $table;
        }
        if(!isset($this->tableNameIterationTracker[$table])) {
            $this->tableNameIterationTracker[$table] = 1;
        }
        if($retrievePrev) {
            return "$table"."_".($this->tableNameIterationTracker[$table] - 1);
        }
        if($incrementAfter) {
            return "$table"."_".$this->tableNameIterationTracker[$table]++;
        }
        return "$table"."_".$this->tableNameIterationTracker[$table];
    }
}