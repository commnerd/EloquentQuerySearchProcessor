<?php

namespace Commnerd\QuerySearchProcessor;

use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Database\Eloquent\Relations\{Relation, BelongsTo};
use Illuminate\Http\Request;
use Illuminate\Support\Str;

trait QuerySearchProcessor {
    private Builder $builder;
    private string $classQueryNamespace;
    private array $generalQueryParams = [];
    private array $generalQueryMappings = [];

    private array $joins = [];
    private array $namespacedQueryParams = [];
    private array $toolQueryParams = [];
    private Request $request;

    public static function processQuery(Request $request): Model
    {
        $instance = new static;
        if(!$instance instanceof Model) {
            throw new NonModelException('This trait can only be used on Eloquent Models');
        }
        $instance->entrypoint($request);
        return $instance;
    }

    /**
     * Execute the query as a "select" statement.
     * (runs locally-crafted builder get function)
     *
     * @param  array|string  $columns
     * @return \Illuminate\Database\Eloquent\Collection|static[]
     */
    public function get($columns = ['*'])
    {
        return $this->builder->get($columns);
    }

    /**
     * Paginate the given query.
     *
     * @param  int|null|\Closure  $perPage
     * @param  array|string  $columns
     * @param  string  $pageName
     * @param  int|null  $page
     * @param  \Closure|int|null  $total
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     *
     * @throws \InvalidArgumentException
     */
    public function paginate($perPage = null, $columns = ['*'], $pageName = 'page', $page = null, $total = null)
    {
        return $this->builder->paginate($perPage, $columns, $pageName, $page, $total);
    }

    /**
     * This is a testing tool used to help us hone our queries
     *
     * @return string
     */
    public function toSql(): string
    {
        return $this->builder->toSql();
    }

    private function addGeneralQueryMapping(Model $model, string $param) {
        if(in_array($param, $model->getSearchColumns())) {
            array_push($this->generalQueryMappings[$param], $model);
        }
    }

    private function addJoin(Model $model, Relation $relationship) {
        $rightKey = '';
        $leftTable = '';
        $leftKey = '';
        if($relationship instanceof BelongsTo) {
            $leftTable = $model->getTable();
            $leftKey = $relationship->getForeignKeyName();
            $rightClass = $relationship->getRelated();
            $rightTable = $rightClass->getTable();
            $rightKey = $rightClass->getKeyName();
        }
        $this->builder->leftJoin($rightTable,$leftTable.'.'.$leftKey,"=",$rightTable.'.'.$rightKey);
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
                foreach(explode('.', $relationshipChain) as $relationship) {
                    $class = $model->{$relationship}()->getRelated();
                    $model = new $class();
                    foreach(array_keys($this->generalQueryParams) as $param) {
                        $this->addGeneralQueryMapping($model, $param);
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
        $this->builder->select($selects);
    }

    /**
     * Initialize the search building process and kick it off
     *
     * @param Request $request
     * @return void
     * @throws \Illuminate\Contracts\Container\BindingResolutionException
     */
    private function entrypoint(Request $request)
    {
        $this->request = $request;
        $this->builder = $this->newQuery();
        $this->processSearch();
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
            $this->builder->with(explode(',',$this->toolQueryParams['_with']));
            if(sizeof($this->generalQueryParams) > 0) {
                $this->disambiguateFirstOrderFields();
                $this->addJoins();
            }
        }
        if(isset($this->toolQueryParams['_orderBy']) && !is_null($mapping = $this->mapKeyToHost($this->toolQueryParams['_orderBy']))) {
            list($model, $var) = $mapping;
            $direction = strtolower($this->toolQueryParams['_order'] ?? 'asc') == 'desc' ? 'desc' : 'asc';
            $this->builder->orderBy($model->getTable().'.'.$var, $direction);
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
            $this->builder->where(function ($builder) {
                foreach($this->generalQueryMappings as $var => $modelArray) {
                    foreach($modelArray as $model) {
                        $this->addWhere($builder, $model, $model->getTable() . '.' . $var, $this->generalQueryParams[$var], 'or');
                    }
                }
            });
        }
        else {
            foreach($this->generalQueryMappings as $var => $modelArray) {
                $model = new static;
                $this->addWhere($this->builder, $model, $var, $this->generalQueryParams[$var]);
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
                $this->addWhere($this->builder, $model, $var, $value);
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
        if(is_numeric($val)) {
            if($val == (int)$val) {
                return (int)$val;
            }
            if($val == (float)$val) {
                return (float)$val;
            }
        }

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
}