<?php

namespace Thomisticus\NestedAttributes\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Psr\Log\InvalidArgumentException;

trait HasNestedAttributes
{
    /**
     * Defined nested attributes
     *
     * @var array
     */
    protected $acceptNestedAttributesFor = [];

    /**
     * Defined "destroy" key name
     *
     * @var string
     */
    protected $destroyNestedKey = '_destroy';

    /**
     * Get accept nested attributes
     *
     * @return array
     */
    public function getAcceptNestedAttributesFor()
    {
        return $this->acceptNestedAttributesFor;
    }

    /**
     * Fill the model with an array of attributes.
     *
     * @param array $attributes
     * @return $this
     *
     * @throws \Illuminate\Database\Eloquent\MassAssignmentException
     */
    public function fill(array $attributes)
    {
        if (!empty($this->nested)) {
            $this->acceptNestedAttributesFor = [];

            foreach ($this->nested as $attr) {
                if (isset($attributes[$attr])) {
                    $this->acceptNestedAttributesFor[$attr] = $attributes[$attr];
                    unset($attributes[$attr]);
                }
            }

            if (!empty($this->guarded) || empty($this->guarded) && empty($this->fillable)) {
                $this->guarded = array_merge($this->guarded, $this->nested);
            }
        }

        return parent::fill($attributes);
    }

    /**
     * Save the model to the database.
     *
     * @param array $options
     * @return bool
     */
    public function save(array $options = [])
    {
        DB::beginTransaction();

        if (!parent::save($options)) {
            return false;
        }

        foreach ($this->getAcceptNestedAttributesFor() as $attribute => $stack) {
            $methodName = lcfirst(join(array_map('ucfirst', explode('_', $attribute))));

            if (!method_exists($this, $methodName)) {
                throw new InvalidArgumentException('The nested attribute relation "' . $methodName . '" does not exists.');
            }

            $relation = $this->$methodName();
            if ($relation instanceof BelongsTo) {
                if (!$this->saveBelongToNestedAttributes($relation, $stack)) {
                    return false;
                }
            } else {
                if ($relation instanceof HasOne || $relation instanceof MorphOne) {
                    if (!$this->saveOneNestedAttributes($this->$methodName(), $stack)) {
                        return false;
                    }
                } else {
                    if ($relation instanceof HasMany || $relation instanceof MorphMany) {
                       $modelRelation = $this->$methodName();
                       $relatedKeyName = $relation->getRelated()->getKeyName();
                       $idsArray = array_map(function ($stack) use ($relatedKeyName) {
                           return isset($stack[$relatedKeyName]) ? $stack[$relatedKeyName] : false;
                       }, $stack);
                       $idsNotDelete = array_filter($idsArray);

                       //Syncing one-to-many relationships
                       if (count($idsNotDelete) > 0) {
                           $relation->whereNotIn($relatedKeyName, $idsNotDelete)->delete();
                       } else {
                           $relation->delete();
                       }
                        foreach ($stack as $params) {
                            if (!$this->saveManyNestedAttributes($this->$methodName(), $params)) {
                                return false;
                            }
                        }
                    } else {
                        if ($relation instanceof BelongsToMany) {
                            $idsNesteds = [];
                            foreach ($stack as $params) {
                                $id = $this->saveBelongsToManyNestedAttributes($this->$methodName(), $params);

                                $pivotAccessor = $this->$methodName()->getPivotAccessor();
                                if (!empty($params[$pivotAccessor])) {
                                    $idsNesteds[$id] = $params[$pivotAccessor];
                                } else {
                                    $idsNesteds[] = $id;
                                }
                            }

                            $this->$methodName()->sync($idsNesteds);
                        } else {
                            throw new InvalidArgumentException('The nested attribute relation is not supported for "' . $methodName . '".');
                        }
                    }
                }
            }
        }

        DB::commit();

        return true;
    }

    /**
     * Save the hasOne nested relation attributes to the database.
     *
     * @param Illuminate\Database\Eloquent\Relations $relation
     * @param array $params
     * @return bool
     */
    protected function saveBelongToNestedAttributes($relation, array $params)
    {
        if ($this->exists && $model = $relation->first()) {
            if ($this->allowDestroyNestedAttributes($params)) {
                return $model->delete();
            }
            return $model->update($params);
        }

        if ($related = $relation->create($params)) {
            $belongs = $relation->getRelationName();
            $this->$belongs()->associate($related);
            parent::save();

            return true;
        }

        return false;
    }

    /**
     * Save the hasOne nested relation attributes to the database.
     *
     * @param HasOne|MorphOne $relation
     * @param array $params
     * @return bool
     */
    protected function saveOneNestedAttributes($relation, array $params)
    {
        if ($this->exists && $model = $relation->first()) {
            if ($this->allowDestroyNestedAttributes($params)) {
                return $model->delete();
            }
            return $model->update($params);
        }

        if ($relation->create($params)) {
            return true;
        }

        return false;
    }

    /**
     * Save the hasMany nested relation attributes to the database.
     *
     * @param HasMany|MorphMany $relation
     * @param array $params
     * @return bool
     */
    protected function saveManyNestedAttributes($relation, array $params)
    {
        $keyName = $relation->getModel()->getKeyName();

        if ($this->exists && isset($params[$keyName])) {
            $model = $relation->findOrFail($params[$keyName]);

            if ($this->allowDestroyNestedAttributes($params)) {
                return $model->delete();
            }

            return $model->update($params);
        }

        if ($relation->create($params)) {
            return true;
        }

        return false;
    }

    /**
     * Save the belongsToMany nested relation attributes to the database.
     *
     * @param BelongsToMany $relation
     * @param array $params
     * @return bool
     */
    protected function saveBelongsToManyNestedAttributes($relation, array $params)
    {
        $model = $relation->getModel();
        $keyName = $model->getKeyName();

        if ($this->exists && isset($params[$keyName]) && $this->allowDestroyNestedAttributes($params)) {
            $model = $model->findOrFail($params[$keyName]);
            return $model->delete();
        }

        $attributes = !empty($params[$keyName]) ? [$keyName => $params[$keyName]] : $params;

        $pivotAccessor = $relation->getPivotAccessor();
        $attributesToRemove = $model->nested ? array_merge($model->nested, [$pivotAccessor]) : [$pivotAccessor];
        $attributes = Arr::except($attributes, $attributesToRemove);

        unset($params[$pivotAccessor]);

        $params = $model::updateOrCreate($attributes, $params);
        return $params->$keyName;
    }

    /**
     * Check can we delete nested data
     *
     * @param array $params
     * @return bool
     */
    protected function allowDestroyNestedAttributes(array $params)
    {
        return isset($params[$this->destroyNestedKey]) && (bool)$params[$this->destroyNestedKey] === true;
    }
}
