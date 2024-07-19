<?php

namespace Commnerd\QuerySearchProcessor;

use Illuminate\Database\Eloquent\{Builder, Model};
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

    private function addQueryModifiers(): void
    {
        if(isset($this->toolQueryParams['_orderBy'])) {
            if(isset($this->toolQueryParams['_orderRandom'])) {
                throw new ConflictingParametersException('Cannot use both _orderBy and _orderRandom');
            }
            $direction = strtolower($this->toolQueryParams['_order'] ?? 'asc') == 'desc' ? 'desc' : 'asc';
            $this->instanceBuilder->orderBy($this->toolQueryParams['_orderBy'], $direction);
        }
        if(isset($this->toolQueryParams['_orderRandom'])) {
            $this->instanceBuilder->inRandomOrder();
        }
    }

    private function addWhere(Builder $builder, string $variable, $value, $context = 'and'): void
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
        $this->processQueryParams();
        $this->processSearch();
        return $this->instanceBuilder;
    }

    private function getSearchColumns(): array
    {
        return $this->getFillable();
    }

    private function queryRelationships(array $withClause): array
    {
        $queriesPieces = [];
        foreach($withClause as $with) {
            $explodedWith = explode('.', $with);
            if(sizeof($explodedWith) > 1) {
                $relationKey = array_shift($explodedWith);
                $relatedModel = $this->{$relationKey}()->getRelated();
                $queryPieces[$relationKey] = $this->processGeneralQueriesWithRelationshipChain($relatedModel, implode('.', $explodedWith));
            }
            else {
                $relatedModel = $this->{$with}()->getRelated();
                foreach($this->generalQueryParams as $key => $val) {
                    if($relatedModel->hasAttribute($key)) {
                        $queryPieces[$with] = function ($query) use ($key, $val) {
                            $this->addWhere($query, $key, $val);
                        };
                    }
                }
            }
        }
        if(empty($queryPieces)) {
            return $withClause;
        }
        return $queryPieces;
    }

    private function traverseQuery(): void
    {
        if(isset($this->toolQueryParams['_with'])) {
            $this->instanceBuilder->with($this->queryRelationships(explode(',', $this->toolQueryParams['_with'])));
        }
    }

    private function processGeneralQueriesWithRelationshipChain(Model $model, string $relationChain): \Closure
    {
        return function($query) use ($model, $relationChain) {
            foreach($this->generalQueryParams as $key => $val) {
                if($model->hasAttribute($key)) {
                    $this->addWhere($query, $key, $val);
                }
            }
            if(empty($relationChain)) {
                return;
            }
            $chainArray = explode('.', $relationChain);
            $relationLabel = array_shift($chainArray);
            $query->with([
                $relationLabel => $this->processGeneralQueriesWithRelationshipChain($model, implode('.', $chainArray)),
            ]);
        };
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

    /**
     * Categorize search terms
     *
     * @return void
     */
    private function processQueryParams(): void
    {
        $this->setClassQueryNamespace();
        $this->triageQueryParams();
        $this->addQueryModifiers();
    }

    /**
     * Run the search process
     *
     * @return void
     */
    private function processSearch(): void
    {
        $this->queryNamespacedSearchTerms();
        $this->queryGeneralSearchTerms();
    }

    private function queryGeneralSearchTerms(): void
    {
        if(isset($this->toolQueryParams['_with'])) {
            $this->traverseQuery();
        }
        else {
            foreach($this->generalQueryParams as $key => $val) {
                $this->addWhere($this->instanceBuilder, $key, $val);
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
                $this->addWhere($this->instanceBuilder, $var, $value);
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

        return (string)$val;
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
