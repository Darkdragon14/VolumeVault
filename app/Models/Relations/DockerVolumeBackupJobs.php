<?php

namespace App\Models\Relations;

use App\Models\BackupJob;
use App\Models\DockerVolume;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * @extends HasMany<BackupJob, DockerVolume>
 */
class DockerVolumeBackupJobs extends HasMany
{
    public function addConstraints(): void
    {
        parent::addConstraints();

        if (static::$constraints) {
            $this->query->where($this->related->qualifyColumn('docker_host_id'), $this->parent->docker_host_id);
        }
    }

    public function addEagerConstraints(array $models): void
    {
        $namesByHost = [];

        foreach ($models as $model) {
            $namesByHost[$model->docker_host_id][] = $model->getAttribute($this->localKey);
        }

        $this->eagerKeysWereEmpty = $namesByHost === [];

        $this->query->where(function (Builder $query) use ($namesByHost): void {
            foreach ($namesByHost as $hostId => $names) {
                $query->orWhere(function (Builder $query) use ($hostId, $names): void {
                    $query->where($this->related->qualifyColumn('docker_host_id'), $hostId)
                        ->whereIn($this->foreignKey, array_unique($names));
                });
            }
        });
    }

    public function matchMany(array $models, Collection $results, mixed $relation): array
    {
        $dictionary = [];

        foreach ($results as $result) {
            $dictionary[$result->docker_host_id][$result->getAttribute($this->getForeignKeyName())][] = $result;
        }

        foreach ($models as $model) {
            $related = $this->related->newCollection($dictionary[$model->docker_host_id][$model->getAttribute($this->localKey)] ?? []);
            $model->setRelation($relation, $related);
            $this->applyInverseRelationToCollection($related, $model);
        }

        return $models;
    }

    public function getRelationExistenceQuery(Builder $query, Builder $parentQuery, mixed $columns = ['*']): Builder
    {
        return parent::getRelationExistenceQuery($query, $parentQuery, $columns)
            ->whereColumn($query->qualifyColumn('docker_host_id'), $parentQuery->qualifyColumn('docker_host_id'));
    }

    protected function setForeignAttributesForCreate(Model $model): void
    {
        $model->setAttribute('docker_host_id', $this->parent->docker_host_id);
        $model->setAttribute('source_type', BackupJob::SOURCE_TYPE_DOCKER_VOLUME);

        parent::setForeignAttributesForCreate($model);
    }

    public function forceCreate(array $attributes = []): Model
    {
        return $this->related->unguarded(fn (): Model => $this->create($attributes));
    }

    public function one(): never
    {
        throw new LogicException('Docker volume backup jobs do not support conversion to a single-key HasOne relation.');
    }

    public function upsert(array $values, mixed $uniqueBy, mixed $update = null): never
    {
        throw new LogicException('Use createMany or saveMany for host-scoped Docker volume backup jobs.');
    }

    public function limit(mixed $value): static
    {
        if (! $this->parent->exists) {
            throw new LogicException('Per-volume eager loading limits require composite-key partitioning and are not supported.');
        }

        return parent::limit($value);
    }
}
